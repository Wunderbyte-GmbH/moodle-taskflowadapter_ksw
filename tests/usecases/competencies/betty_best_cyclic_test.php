<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace taskflowadapter_ksw\usecases\competencies;

use advanced_testcase;
use core_user;
use local_taskflow\local\external_adapter\external_api_base;
use local_taskflow\local\external_adapter\external_api_repository;
use local_taskflow\local\rules\rules;
use tool_mocktesttime\time_mock;
use context_system;
use core_competency\api;
use core_competency\competency;
use core_competency\user_competency;
use local_taskflow\event\rule_created_updated;
use local_taskflow\local\assignment_status\assignment_status_facade;
use mod_booking\singleton_service;
use stdClass;
use mod_booking_generator;
use function PHPUnit\Framework\assertSame;

/**
 * Tests for booking rules.
 *
 * @package taskflowadapter_ksw
 * @category test
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class betty_best_cyclic_test extends advanced_testcase {
        /** @var string|null Stores the external user data. */
    protected ?string $externaldata = null;

    /**
     * Setup the test environment.
     */
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        time_mock::init();
        time_mock::set_mock_time(strtotime('now'));
        $this->resetAfterTest(true);
        $this->preventResetByRollback();
        $this->externaldata = file_get_contents(__DIR__ . '/external_json/betty_best_ksw.json');
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('local_taskflow');
        $plugingenerator->create_custom_profile_fields([
            'supervisor',
            'orgunit',
            'externalid',
            'contractend',
            'exitdate',
            'Org1',
            'Org2',
            'Org3',
            'Org4',
            'Org5',
            'Org6',
            'Org7',
        ]);
        $plugingenerator->set_config_values('ksw');
    }

    /**
     * Tear down the test environment.
     *
     * @return void
     *
     */
    protected function tearDown(): void {
        parent::tearDown();
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('local_taskflow');
        $plugingenerator->teardown();
    }


    /**
     * Before we create the assignment, the user has already booked and completed the option.
     * Time passes before the user continues.
     *
     * @covers \mod_booking\option\fields\competencies
     *
     * @param array $bdata
     * @throws \coding_exception
     *
     * @dataProvider booking_common_settings_provider
     */
    public function test_assign_competency_on_option_completion(array $bdata): void {
        global $DB;

        [$user1, $user2, $booking, $course, $competency, $competency2, $rule] = $this->betty_best_cyclic_base($bdata);
         $sink = $this->redirectEmails();
        /** @var mod_booking_generator $bookingplugingenerator */
        $bookingplugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $lock = $this->createMock(\core\lock\lock::class);
        $cronlock = $this->createMock(\core\lock\lock::class);

        /** @var \local_taskflow_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('local_taskflow');

        // Create booking option 1.
        $record = new stdClass();
        $record->bookingid = $booking->id;
        $record->text = 'football';
        $record->chooseorcreatecourse = 1; // Connected existing course.
        $record->courseid = $course->id;
        $record->description = 'Will start tomorrow';
        $record->optiondateid_0 = "0";
        $record->daystonotify_0 = "0";
        $record->coursestarttime_0 = strtotime('- 1 day', time());
        $record->courseendtime_0 = strtotime('+ 13 days', time());
        $record->teachersforoption = $user1->username;
        $record->teachersforoption = 0;
        $record->competencies = [$competency->get('id'), $competency2->get('id')];
        $option1 = $bookingplugingenerator->create_option($record);

        singleton_service::destroy_instance();

        // Create a booking option answer - book user2.
        $result = $bookingplugingenerator->create_answer(['optionid' => $option1->id, 'userid' => $user2->id]);
        $this->assertSame(MOD_BOOKING_BO_COND_ALREADYBOOKED, $result);
        singleton_service::destroy_instance();

        // Complete booking option for user2.
        $settings = singleton_service::get_instance_of_booking_option_settings($option1->id);
        $option = singleton_service::get_instance_of_booking_option($settings->cmid, $settings->id);

        $this->assertSame(0, $option->user_completed_option());
        $option->toggle_user_completion($user2->id);

        // Run all adhoc tasks now.
        $plugingenerator->runtaskswithintime($cronlock, $lock, time());

        $this->assertSame(1, $option->user_completed_option());

        // Now some time passes.
        time_mock::set_mock_time(strtotime('+ 8 months', time()));
        $plugingenerator->runtaskswithintime($cronlock, $lock, time());

        // There is no assignment until now.
        $assignments = $DB->get_records('local_taskflow_assignment');
        $this->assertCount(0, $assignments);

        // We generate the rule.
        $event = rule_created_updated::create([
            'objectid' => $rule['id'],
            'context'  => context_system::instance(),
            'other'    => [
                'ruledata' => $rule,
            ],
        ]);
        $event->trigger();

        $plugingenerator->runtaskswithintime($cronlock, $lock, time());
        $assignments = $DB->get_records('local_taskflow_assignment');
        $this->assertCount(3, $assignments);

        $assignment = $DB->get_record('local_taskflow_assignment', ['userid' => $user1->id]);
        $this->assertSame(assignment_status_facade::get_status_identifier('assigned'), (int)$assignment->status);

        // This user has completed the assignment before.
        $assignment = $DB->get_record('local_taskflow_assignment', ['userid' => $user2->id]);
        $this->assertSame(assignment_status_facade::get_status_identifier('completed'), (int)$assignment->status);

        $tasks = $DB->get_records('task_adhoc', ['classname' => '\local_taskflow\task\reset_cyclic_assignment']);
        $this->assertCount(1, $tasks);

        $now = time();

        // Now the time passes until the assignment of the user is reopened.
        time_mock::set_mock_time(strtotime('+ 5 months', time()));
        $plugingenerator->runtaskswithintime($cronlock, $lock, time());

        $now = time();

        // This user has an open assignment again.
        $assignment = $DB->get_record('local_taskflow_assignment', ['userid' => $user2->id]);
        $this->assertSame(assignment_status_facade::get_status_identifier('assigned'), (int)$assignment->status);

        // Here check on messages should be included.
        // Now the time passes until the assignment of the user is reopened.
        time_mock::set_mock_time(strtotime('+ 7 months', time()));
        $plugingenerator->runtaskswithintime($cronlock, $lock, time());

        // This user should be in overdue.
        $assignment = $DB->get_record('local_taskflow_assignment', ['userid' => $user2->id]);
        $this->assertSame(assignment_status_facade::get_status_identifier('overdue'), (int)$assignment->status);
    }

    /**
     * Test rulestemplate on option being completed for user.
     * @param array $bdata
     * @return array
     *
     */
    public function betty_best_cyclic_base(array $bdata): array {
        global $DB;
        singleton_service::destroy_instance();
        $lock = $this->createMock(\core\lock\lock::class);
        $cronlock = $this->createMock(\core\lock\lock::class);
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('local_taskflow');
        $this->setAdminUser();
        $apidatamanager = external_api_repository::create($this->externaldata);
        $externaldata = $apidatamanager->get_external_data();
        $this->assertNotEmpty($externaldata, 'External user data should not be empty.');
        $apidatamanager->process_incoming_data();
        // Allow optioncacellation.
        $bdata['cancancelbook'] = 1;

        $course = $this->set_db_course();
        $user3 = $DB->get_record('user', ['email' => 'chris.change@ksw.ch']);
        $user2 = $DB->get_record('user', ['email' => 'betty.best@ksw.ch']);
        $user1 = $DB->get_record('user', ['email' => 'berta.boss@ksw.ch']);

        $scale = $this->getDataGenerator()->create_scale([
            'scale' => 'Not proficient,Proficient',
            'name' => 'Test Competency Scale',
        ]);

        // Create a competency.
        $framework = api::create_framework((object)[
            'shortname' => 'testframework',
            'idnumber' => 'testframework',
            'contextid' => context_system::instance()->id,
            'scaleid' => $scale->id,
            'scaleconfiguration' => json_encode([
                ['scaleid' => $scale->id],
                ['id' => 1, 'scaledefault' => 1, 'proficient' => 0],
                ['id' => 2, 'scaledefault' => 0, 'proficient' => 1],
            ]),
        ]);
        // Create compentencies.
        $record = (object)[
            'shortname' => 'testcompetency',
            'idnumber' => 'testcompetency',
            'competencyframeworkid' => $framework->get('id'),
            'scaleid' => null,
            'description' => 'A test competency',
            'id' => 0,
            'scaleconfiguration' => null,
            'parentid' => 0,
        ];
        $competency = new competency(0, $record);
        $competency->set('sortorder', 0);
        $competency->create();

        $record = (object)[
            'shortname' => 'testcompetency2',
            'idnumber' => 'testcompetency2',
            'competencyframeworkid' => $framework->get('id'),
            'scaleid' => null,
            'description' => 'A test competency2',
            'id' => 0,
            'scaleconfiguration' => null,
            'parentid' => 0,
        ];
        $competency2 = new competency(0, $record);
        $competency2->set('sortorder', 0);
        $competency2->create();
        $messageids = $this->set_messages_db();
        $cohorts = $DB->get_records('cohort');
        $cohort = array_shift($cohorts);
        $rule = $this->get_rule($cohort->id, $competency->get('id'), $competency2->get('id'), $messageids);
        $id = $DB->insert_record('local_taskflow_rules', $rule);
        $rule['id'] = $id;

        $bdata['course'] = $course->id;
        $bdata['bookingmanager'] = $user1->username;

        $booking = $this->getDataGenerator()->create_module('booking', $bdata);
        set_config('usecompetencies', 1, 'booking');

        $this->setAdminUser();

        $this->getDataGenerator()->enrol_user($user1->id, $course->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user($user2->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($user3->id, $course->id, 'editingteacher');

        // User 2 doesnt have competency yet in this case.
        $existing = user_competency::get_records(['userid' => $user2->id]);
        $this->assertEmpty($existing, 'User already has a competency assigned');

        return [$user1, $user2, $booking, $course, $competency, $competency2, $rule];
    }

    /**
     * Setup the test environment.
     * @return object
     */
    protected function set_db_course(): mixed {
        // Create a user.
        $course = $this->getDataGenerator()->create_course([
            'fullname' => 'Test Course',
            'shortname' => 'TC101',
            'category' => 1,
            'enablecompletion' => 1,
        ]);
        return $course;
    }

    /**
     * Setup the test environment.
     * @return object
     */
    protected function set_db_cohort(): mixed {
        // Create a user.
        $cohort = $this->getDataGenerator()->create_cohort([
            'name' => 'Test Cohort',
            'idnumber' => 'cohort123',
            'contextid' => context_system::instance()->id,
        ]);
        return $cohort;
    }

    /**
     * Setup the test environment.
     * @param int $unitid
     * @param int $target1id
     * @param int $target2id
     * @param array $messageids
     * @return array
     */
    public function get_rule(int $unitid, int $target1id, int $target2id, array $messageids): array {
        $rule = [
            "unitid" => $unitid,
            "rulename" => "test_rule",
            "rulejson" => json_encode((object)[
                "rulejson" => [
                    "rule" => [
                        "name" => "test_rule",
                        "description" => "test_rule_description",
                        "type" => "taskflow",
                        "enabled" => true,
                        "duedatetype" => "duration",
                        "cyclicvalidation" => "1",
                        "cyclicduration" => 86400 * 356, // One year.
                        "fixeddate" => 23233232222,
                        "duration" => 86400 * 10,
                        "timemodified" => strtotime('now', time()),
                        "timecreated" => strtotime('now', time()),
                        "usermodified" => 1,
                        "recursive" => 0,
                        "inheritance" => 1,
                        "filter" => [
                            [
                                "filtertype" => "user_profile_field",
                                "userprofilefield" => "supervisor",
                                "operator" => "not_equals",
                                "value" => "124",
                                "key" => "role",
                            ],
                        ],
                        "actions" => [
                            [
                                "targets" => [
                                    [
                                        "targetid" => $target1id,
                                        "targettype" => "competency",
                                        "targetname" => "mycompetency",
                                        "sortorder" => 2,
                                        "actiontype" => "enroll",
                                        "completebeforenext" => false,
                                    ],
                                    [
                                        "targetid" => $target2id,
                                        "targettype" => "competency",
                                        "targetname" => "mycompetency",
                                        "sortorder" => 3,
                                        "actiontype" => "enroll",
                                        "completebeforenext" => false,
                                    ],
                                ],
                                "messages" => $messageids,
                            ],
                        ],
                    ],
                ],
            ]),
            "isactive" => "1",
            "userid" => "0",
        ];
        return $rule;
    }

    /**
     * Setup the test environment.
     */
    protected function set_messages_db(): array {
        global $DB;
        $messageids = [];
        $messages = json_decode(file_get_contents(__DIR__ . '/../../mock/messages/assignedandwarningsandfailed_messages.json'));
        foreach ($messages as $message) {
            $messageids[] = (object)['messageid' => $DB->insert_record('local_taskflow_messages', $message)];
        }
        return $messageids;
    }

    /**
     * Data provider for condition_bookingpolicy_test
     *
     * @return array
     * @throws \UnexpectedValueException
     */
    public static function booking_common_settings_provider(): array {
        $bdata = [
            'name' => 'Rule Booking Test',
            'eventtype' => 'Test rules',
            'enablecompletion' => 1,
            'bookedtext' => ['text' => 'text'],
            'waitingtext' => ['text' => 'text'],
            'notifyemail' => ['text' => 'text'],
            'statuschangetext' => ['text' => 'text'],
            'deletedtext' => ['text' => 'text'],
            'pollurltext' => ['text' => 'text'],
            'pollurlteacherstext' => ['text' => 'text'],
            'notificationtext' => ['text' => 'text'],
            'userleave' => ['text' => 'text'],
            'tags' => '',
            'completion' => 2,
            'showviews' => ['mybooking,myoptions,optionsiamresponsiblefor,showall,showactive,myinstitution'],
        ];
        return ['bdata' => [$bdata]];
    }
}

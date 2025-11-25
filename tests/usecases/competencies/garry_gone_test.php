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
use local_taskflow\local\external_adapter\external_api_base;
use local_taskflow\local\external_adapter\external_api_repository;
use local_taskflow\plugininfo\taskflowadapter;
use tool_mocktesttime\time_mock;
use context_system;
use local_taskflow\event\rule_created_updated;
use local_taskflow\local\assignment_status\assignment_status_facade;

/**
 * Tests for booking rules.
 *
 * @package taskflowadapter_ksw
 * @category test
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class garry_gone_test extends advanced_testcase {
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

    /**
     * Setup the test environment.
     * @param int $unitid
     * @param int $courseid
     * @param array $messageids
     * @return array
     */
    public function get_rule($unitid, $courseid, $messageids): array {
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
                        "fixeddate" => 23233232222,
                        "duration" => 86400 * 420, // 420 days.
                        "timemodified" => 23233232222,
                        "timecreated" => 23233232222,
                        "usermodified" => 1,
                        "recursive" => 0,
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
                                        "targetid" => $courseid,
                                        "targettype" => "moodlecourse",
                                        "targetname" => "mytargetname2",
                                        "sortorder" => 2,
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
     * Garry Gone Use Case Test.
     *
     * @covers \local_taskflow\local\completion_process\completion_operator
     * @covers \local_taskflow\local\completion_process\types\bookingoption
     * @covers \local_taskflow\local\completion_process\types\competency
     * @covers \local_taskflow\local\completion_process\types\moodlecourse
     * @covers \local_taskflow\local\completion_process\types\types_base
     * @covers \local_taskflow\local\history\history
     * @covers \local_taskflow\event\assignment_completed
     * @covers \local_taskflow\observer
     * @covers \local_taskflow\task\send_taskflow_message
     * @covers \local_taskflow\local\assignments\status\assignment_status
     * @covers \local_taskflow\local\assignments\assignments_facade
     * @covers \local_taskflow\local\assignments\types\standard_assignment
     */
    public function test_garry_gone(): void {
        global $DB;

        // First, set the contract end to one year in the future.
        $jsondecoded = json_decode($this->externaldata);
        $endinfo = external_api_base::return_jsonkey_for_functionname(taskflowadapter::TRANSLATOR_USER_CONTRACTEND);
        $startinfo = external_api_base::return_jsonkey_for_functionname(taskflowadapter::TRANSLATOR_USER_CONTRACTEND);
        $jsondecoded[1]->{$startinfo} = 20201010;
        $jsondecoded[1]->{$endinfo} = 20261111;
        $this->externaldata = json_encode($jsondecoded);

        // Now create the persons.
        $apidatamanager = external_api_repository::create($this->externaldata);
        $externaldata = $apidatamanager->get_external_data();
        $this->assertNotEmpty($externaldata, 'External user data should not be empty.');
        $apidatamanager->process_incoming_data();
        $sink = $this->redirectEmails();

        $cohorts = $DB->get_records('cohort');
        $cohort = array_shift($cohorts);

        $course = $this->set_db_course();
        $messageids = $this->set_messages_db();

        $lock = $this->createMock(\core\lock\lock::class);
        $cronlock = $this->createMock(\core\lock\lock::class);
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('local_taskflow');

        $rule = $this->get_rule($cohort->id, $course->id, $messageids);
        $id = $DB->insert_record('local_taskflow_rules', $rule);
        $rule['id'] = $id;
        $event = rule_created_updated::create([
            'objectid' => $rule['id'],
            'context'  => \context_system::instance(),
            'other'    => [
                'ruledata' => $rule,
            ],
        ]);
        $event->trigger();
        time_mock::set_mock_time(strtotime('+ 2 minutes', time()));
        $plugingenerator->runtaskswithintime($cronlock, $lock, time());

        $assignments = $DB->get_records('local_taskflow_assignment');
        $this->assertNotEmpty($assignments);
        $this->assertCount(3, $assignments);

        $now = time();

        foreach ($assignments as $assignment) {
            $this->assertSame((int)$assignment->status, assignment_status_facade::get_status_identifier('assigned'));
            $this->assertSame($now + 86400 * 420, (int)$assignment->duedate); // Its 420 days in the future.
            // We check if the overdue has been prolonged.
        }

        $assignment = array_pop($assignments);
        $sentmessages = $DB->get_records('local_taskflow_sent_messages');
        $messagesink = array_filter($sink->get_messages(), function ($message) {
            return strpos($message->subject, 'Taskflow -') === 0;
        });

        // Let the time pass.

        time_mock::set_mock_time(strtotime('+ 13 months', time()));
        $this->assertNotEmpty($externaldata, 'External user data should not be empty.');

        $apidatamanager->process_incoming_data();
        $plugingenerator->runtaskswithintime($cronlock, $lock, time());

        $user2 = $DB->get_record('user', ['firstname' => 'Berta']);
        $user1 = $DB->get_record('user', ['firstname' => 'Betty']);
        $assignments = $DB->get_records('local_taskflow_assignment', ['userid' => $user1->id]);
        $this->assertCount(1, $assignments);
        $assignment = reset($assignments);
        $sentmessages = $DB->get_records('local_taskflow_sent_messages');
        $messagesink = array_filter($sink->get_messages(), function ($message) {
            return strpos($message->subject, 'Taskflow -') === 0;
        });

        // Make sure the assignment of the user is now paused.
        $this->assertSame(assignment_status_facade::get_status_identifier('paused'), (int)$assignment->status);
        $this->assertSame(0, (int)$assignment->active);

        // When another two months pass, the one user should be overdue, but the paused user shouldn't.
        time_mock::set_mock_time(strtotime('+ 2 months', time()));
        $plugingenerator->runtaskswithintime($cronlock, $lock, time());

        // Should not receive overdue email because hes paused.
        $assignments = $DB->get_records('local_taskflow_assignment');
        $this->assertCount(3, $assignments);

        $assignment = $DB->get_record('local_taskflow_assignment', ['userid' => $user1->id]);
        $this->assertSame(assignment_status_facade::get_status_identifier('paused'), (int)$assignment->status);

        $assignment = $DB->get_record('local_taskflow_assignment', ['userid' => $user2->id]);
        $this->assertSame(assignment_status_facade::get_status_identifier('overdue'), (int)$assignment->status);

        $sentmessages = $DB->get_records('local_taskflow_sent_messages');
        $messagesink = array_filter($sink->get_messages(), function ($message) {
            return strpos($message->subject, 'Taskflow -') === 0;
        });

        \core\event\user_updated::create_from_userid($user1->id)->trigger();
        \core\event\user_updated::create_from_userid($user2->id)->trigger();
        $plugingenerator->runtaskswithintime($cronlock, $lock, time());

        $assignment = $DB->get_record('local_taskflow_assignment', ['userid' => $user1->id]);
        $this->assertSame(assignment_status_facade::get_status_identifier('paused'), (int)$assignment->status);
        $this->assertNull($assignment->duedate); // Paused assignments don't have a future date anymore.

        $assignment = $DB->get_record('local_taskflow_assignment', ['userid' => $user2->id]);
        $this->assertSame(assignment_status_facade::get_status_identifier('overdue'), (int)$assignment->status);
        $this->assertSame($now + 86400 * 420, (int)$assignment->duedate); // Its 420 days in the future.

        // Garry is coming back.
        // Not yet implemented in KSW.
        profile_load_custom_fields($user1);
        $user1->profile_field_contractend = strtotime('+ 1 year', time());
        profile_save_data($user1);
        \core\event\user_updated::create_from_userid($user1->id)->trigger();

        $plugingenerator->runtaskswithintime($cronlock, $lock, time());
        $now = time();

        $assignment = $DB->get_record('local_taskflow_assignment', ['userid' => $user1->id]);
    }
}

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

/**
 * Tests for booking rules.
 *
 * @package taskflowadapter_ksw
 * @category test
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class betty_best_partially_rule_update_test extends advanced_testcase {
    /**
     * Tests set up.
     */
    public function setUp(): void {
        parent::setUp();
        time_mock::init();
        time_mock::set_mock_time(strtotime('now'));
        $this->resetAfterTest();
        singleton_service::destroy_instance();
        rules::reset_instances();
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('local_taskflow');
        $plugingenerator->create_custom_profile_fields([
            'supervisor',
            'units',
        ]);
        $plugingenerator->set_config_values('ksw');
        $this->create_custom_profile_field();
        $this->preventResetByRollback();
    }

    /**
     * Mandatory clean-up after each test.
     */
    public function tearDown(): void {
        global $DB;

        parent::tearDown();
        // Mandatory clean-up.
        singleton_service::destroy_instance();
    }

    /**
     * Setup the test environment.
     */
    private function create_custom_profile_field(): int {
        global $DB;
        $shortname = 'supervisor';
        $name = ucfirst($shortname);
        if ($DB->record_exists('user_info_field', ['shortname' => $shortname])) {
            return 0;
        }

        $field = (object)[
            'shortname' => $shortname,
            'name' => $name,
            'datatype' => 'text',
            'description' => '',
            'descriptionformat' => FORMAT_HTML,
            'categoryid' => 1,
            'sortorder' => 0,
            'required' => 0,
            'locked' => 0,
            'visible' => 1,
            'forceunique' => 0,
            'signup' => 0,
            'defaultdata' => '',
            'defaultdataformat' => FORMAT_HTML,
            'param1' => '',
            'param2' => '',
            'param3' => '',
            'param4' => '',
            'param5' => '',
        ];

        return $DB->insert_record('user_info_field', $field);
    }

    /**
     * Test rulestemplate on option being completed for user.
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

        [$user1, $user2, $booking, $course, $competency, $competency2] = $this->betty_best_base($bdata);
         $sink = $this->redirectEmails();
        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $lock = $this->createMock(\core\lock\lock::class);
        $cronlock = $this->createMock(\core\lock\lock::class);
        // Create booking option 1.
        $record = new stdClass();
        $record->bookingid = $booking->id;
        $record->text = 'football';
        $record->chooseorcreatecourse = 1; // Connected existing course.
        $record->courseid = $course->id;
        $record->description = 'Will start tomorrow';
        $record->optiondateid_0 = "0";
        $record->daystonotify_0 = "0";
        $record->coursestarttime_0 = strtotime('20 June 2050 15:00');
        $record->courseendtime_0 = strtotime('20 July 2050 14:00');
        $record->teachersforoption = $user1->username;
        $record->teachersforoption = 0;
        $record->competencies = [$competency->get('id'), $competency2->get('id')];
        $option1 = $plugingenerator->create_option($record);

        singleton_service::destroy_instance();

        $assignments = $DB->get_records('local_taskflow_assignment');
        foreach ($assignments as $assignment) {
            $this->assertSame((int)$assignment->status, assignment_status_facade::get_status_identifier('assigned'));
        }

        // Create a booking option answer - book user2.
        $result = $plugingenerator->create_answer(['optionid' => $option1->id, 'userid' => $user2->id]);
        $this->assertSame(MOD_BOOKING_BO_COND_ALREADYBOOKED, $result);
        singleton_service::destroy_instance();

        // Complete booking option for user2.
        $settings = singleton_service::get_instance_of_booking_option_settings($option1->id);
        $option = singleton_service::get_instance_of_booking_option($settings->cmid, $settings->id);

        $assignments = $DB->get_records('local_taskflow_assignment');
        foreach ($assignments as $assignment) {
            $this->assertSame((int)$assignment->status, assignment_status_facade::get_status_identifier('enrolled'));
        }

        time_mock::set_mock_time(strtotime('+ 6 minutes', time()));
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('local_taskflow');
        $plugingenerator->runtaskswithintime($cronlock, $lock, time());
        $sentmessages = $DB->get_records('local_taskflow_sent_messages');
        // No assignment should be sent because user enrolled before 6 minutes.
        $this->assertCount(0, $sentmessages);
        $messagesink = array_filter($sink->get_messages(), function ($message) {
            return strpos($message->subject, 'Taskflow -') === 0;
        });
        $this->assertCount(0, $messagesink);

        $this->assertSame(0, $option->user_completed_option());
        $option->toggle_user_completion($user2->id);

        // Run all adhoc tasks now.
        $plugingenerator->runtaskswithintime($cronlock, $lock, time());

        $this->assertSame(1, $option->user_completed_option());
        $assignments = $DB->get_records('local_taskflow_assignment');
        foreach ($assignments as $assignment) {
            $this->assertSame((int)$assignment->status, assignment_status_facade::get_status_identifier('completed'));
        }
        $sentmessages = $DB->get_records('local_taskflow_sent_messages');
        // Should have no msg.
        $this->assertCount(0, $sentmessages);
        $messagesink = array_filter($sink->get_messages(), function ($message) {
            return strpos($message->subject, 'Taskflow -') === 0;
        });
        $this->assertCount(0, $sentmessages);
        $this->assertCount(0, $messagesink);
    }

    /**
     * Test rulestemplate on option being completed for user.
     *
     * @covers \mod_booking\option\fields\competencies
     *
     * @param array $bdata
     * @throws \coding_exception
     *
     * @dataProvider booking_common_settings_provider
     */
    public function test_betty_best_user_update_after_partial_completion(array $bdata): void {
        global $DB, $CFG;

        [$user1, $user2, $booking, $course, $competency, $competency2] = $this->betty_best_base($bdata);
        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $sink = $this->redirectEmails();
        // Create booking option 1.
        $record = new stdClass();
        $record->bookingid = $booking->id;
        $record->text = 'football';
        $record->chooseorcreatecourse = 1; // Connected existing course.
        $record->courseid = $course->id;
        $record->description = 'Will start tomorrow';
        $record->optiondateid_0 = "0";
        $record->daystonotify_0 = "0";
        $record->coursestarttime_0 = strtotime('20 June 2050 15:00');
        $record->courseendtime_0 = strtotime('20 July 2050 14:00');
        $record->teachersforoption = $user1->username;
        $record->teachersforoption = 0;
        $record->competencies = [$competency->get('id')];
        $option1 = $plugingenerator->create_option($record);

        // Create booking option 2.
        $record = new stdClass();
        $record->bookingid = $booking->id;
        $record->text = 'handball';
        $record->chooseorcreatecourse = 1; // Connected existing course.
        $record->courseid = $course->id;
        $record->description = 'Will start tomorrow';
        $record->optiondateid_0 = "0";
        $record->daystonotify_0 = "0";
        $record->coursestarttime_0 = strtotime('20 June 2050 15:00');
        $record->courseendtime_0 = strtotime('20 July 2050 14:00');
        $record->teachersforoption = $user1->username;
        $record->teachersforoption = 0;
        $record->competencies = [$competency2->get('id')];
        $option2 = $plugingenerator->create_option($record);

        singleton_service::destroy_instance();

        time_mock::set_mock_time(strtotime('+ 6 minutes', time()));
        $lock = $this->createMock(\core\lock\lock::class);
        $cronlock = $this->createMock(\core\lock\lock::class);
        $plugingeneratortf = self::getDataGenerator()->get_plugin_generator('local_taskflow');
        $plugingeneratortf->runtaskswithintime($cronlock, $lock, time());
        $sentmessages = $DB->get_records('local_taskflow_sent_messages');
        //$this->assertCount(1, $sentmessages);
        $messagesink = array_filter($sink->get_messages(), function ($message) {
            return strpos($message->subject, 'Taskflow -') === 0;
        });
        $dbmsg = array_values($DB->get_records('local_taskflow_messages'));
        foreach ($dbmsg as $index => $msg) {
            $data = json_decode($msg->message);
            $dbmsg[$index]->subject = $data->heading;
        }
        foreach ($messagesink as $msg) {
            $this->assertTrue(
                $msg->to === $user2->email
            );
            $this->assertSame(
                $dbmsg[3]->subject,
                $msg->subject,
            );
        }
        //$this->assertCount(1, $sentmessages);

        // Create a booking option answer - book user2.
        $assignmenthistory = $DB->get_records('local_taskflow_history', ['userid' => $user2->id]);
        $result = $plugingenerator->create_answer(['optionid' => $option1->id, 'userid' => $user2->id]);
        $assignmenthistory = $DB->get_records('local_taskflow_history', ['userid' => $user2->id]);
        $this->assertSame(MOD_BOOKING_BO_COND_ALREADYBOOKED, $result);
        singleton_service::destroy_instance();

        // Complete booking option for user2.
        $settings = singleton_service::get_instance_of_booking_option_settings($option1->id);
        $option = singleton_service::get_instance_of_booking_option($settings->cmid, $settings->id);

        $assignments = $DB->get_records('local_taskflow_assignment');
        foreach ($assignments as $assignment) {
            $this->assertSame((int)$assignment->status, assignment_status_facade::get_status_identifier('enrolled'));
        }

        $this->assertSame(0, $option->user_completed_option());
        $option->toggle_user_completion($user2->id);

        // Run all adhoc tasks now.
        $plugingeneratortf->runtaskswithintime($cronlock, $lock, time());

        $this->assertSame(1, $option->user_completed_option());
        $assignments = $DB->get_records('local_taskflow_assignment');
        $ruleid = 0;
        foreach ($assignments as $assignment) {
            $ruleid = $assignment->ruleid;
            $this->assertSame((int)$assignment->status, assignment_status_facade::get_status_identifier('partially_completed'));
        }

        $countbefore = count($assignmenthistory = $DB->get_records('local_taskflow_history', ['userid' => $user2->id]));
        $ruledata = $DB->get_record('local_taskflow_rules', ['id' => $ruleid]);
        // After the partial completion, we update the rule.
        $event = rule_created_updated::create([
            'objectid' => $ruleid,
            'context'  => context_system::instance(),
            'other'    => [
                'ruledata' => $ruledata,
            ],
        ]);
        $event->trigger();
        $plugingeneratortf->runtaskswithintime($cronlock, $lock, time());
        // Check taskflow_history dont trigger status update.
        $countafter = count($assignmenthistory = $DB->get_records('local_taskflow_history', ['userid' => $user2->id]));
        $this->assertSame($countbefore, $countafter);
        // Run all adhoc tasks now.
        $plugingeneratortf->runtaskswithintime($cronlock, $lock, time());

        $this->assertSame(1, $option->user_completed_option());
        $assignments = $DB->get_records('local_taskflow_assignment');
        foreach ($assignments as $assignment) {
            $this->assertSame((int)$assignment->status, assignment_status_facade::get_status_identifier('partially_completed'));
        }

        $sentmessages = $DB->get_records('local_taskflow_sent_messages');
        $this->assertCount(1, $sentmessages);
        $messagesink = array_filter($sink->get_messages(), function ($message) {
            return strpos($message->subject, 'Taskflow -') === 0;
        });
        foreach ($messagesink as $msg) {
            $this->assertTrue(
                $msg->to === $user2->email
            );
            $this->assertSame(
                $dbmsg[3]->subject,
                $msg->subject,
            );
        }
    }

    /**
     * Test rulestemplate on option being completed for user.
     * @param array $bdata
     * @return array
     *
     */
    public function betty_best_base(array $bdata): array {
        global $DB;
        singleton_service::destroy_instance();
        $lock = $this->createMock(\core\lock\lock::class);
        $cronlock = $this->createMock(\core\lock\lock::class);
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('local_taskflow');
        $this->setAdminUser();

        set_config('timezone', 'Europe/Kyiv');
        set_config('forcetimezone', 'Europe/Kyiv');

        // Allow optioncacellation.
        $bdata['cancancelbook'] = 1;

        // Setup test data.
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $user1 = $this->getDataGenerator()->create_user();
        $user2 = $this->getDataGenerator()->create_user();
        $user3 = $this->getDataGenerator()->create_user();

        $testingsupervisor = $this->getDataGenerator()->create_user([
            'firstname' => 'Super',
            'lastname' => 'Visor',
            'email' => 'auper@visor.com',
        ]);

        $fieldid = $DB->get_field('user_info_field', 'id', ['shortname' => 'supervisor'], MUST_EXIST);
        $exsistinginfodata = $DB->get_record(
            'user_info_data',
            [
                    'userid' => $user2->id,
                    'fieldid' => $fieldid,
                ]
        );
        if ($exsistinginfodata) {
            $exsistinginfodata->data = $testingsupervisor->id;
            $DB->update_record(
                'user_info_data',
                $exsistinginfodata
            );
        } else {
            $DB->insert_record('user_info_data', (object)[
                'userid' => $user2->id,
                'fieldid' => $fieldid,
                'data' => $testingsupervisor->id,
                'dataformat' => FORMAT_HTML,
            ]);
        }

        $scale = $this->getDataGenerator()->create_scale([
            'scale' => 'Not proficient,Proficient',
            'name' => 'Test Competency Scale',
        ]);

        $cohort = $this->set_db_cohort();
        cohort_add_member($cohort->id, $user2->id);
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
        $rule = $this->get_rule($cohort->id, $competency->get('id'), $competency2->get('id'), $messageids);
        $id = $DB->insert_record('local_taskflow_rules', $rule);
        $rule['id'] = $id;

        $event = rule_created_updated::create([
            'objectid' => $rule['id'],
            'context'  => context_system::instance(),
            'other'    => [
                'ruledata' => $rule,
            ],
        ]);
        $event->trigger();
        $plugingenerator->runtaskswithintime($cronlock, $lock, time());
        $assignment = $DB->get_records('local_taskflow_assignment');
        $plugingenerator->runtaskswithintime($cronlock, $lock, time());

        $bdata['course'] = $course->id;
        $bdata['bookingmanager'] = $user1->username;

        $booking = $this->getDataGenerator()->create_module('booking', $bdata);
        set_config('usecompetencies', 1, 'booking');

        $this->setAdminUser();

        $this->getDataGenerator()->enrol_user($user1->id, $course->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user($user2->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($user3->id, $course->id, 'editingteacher');

        // User 2 already has competency 1.
        $existing = user_competency::get_records(['userid' => $user2->id]);
        $this->assertEmpty($existing, 'User already has a competency assigned');
        $usercompetency = api::get_user_competency($user2->id, $competency->get('id'));
        $existing = user_competency::get_records(['userid' => $user2->id]);
        $this->assertNotEmpty($existing, 'Competency could not be created for user');

        return [$user1, $user2, $booking, $course, $competency, $competency2];
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
     * @return array
     */
    public function get_rule($unitid, $target1id, $target2id, $messageids): array {
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
                        "cyclicvalidation" => "0",
                        "cyclicduration" => 38361600,
                        "fixeddate" => 23233232222,
                        "duration" => 23233232222,
                        "timemodified" => 23233232222,
                        "timecreated" => 23233232222,
                        "usermodified" => 1,
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
        $messages = json_decode(file_get_contents(__DIR__ . '/../../mock/messages/assignedandwarningsandfailed_messages .json'));
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

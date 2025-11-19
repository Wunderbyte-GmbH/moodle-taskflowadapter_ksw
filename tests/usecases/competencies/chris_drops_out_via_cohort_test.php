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
use context_system;
use core_competency\api;
use core_competency\competency;
use core_competency\user_competency;
use local_taskflow\event\rule_created_updated;
use local_taskflow\local\assignment_status\assignment_status_facade;
use local_taskflow\local\external_adapter\external_api_base;
use local_taskflow\local\external_adapter\external_api_repository;
use local_taskflow\local\rules\rules;
use mod_booking\singleton_service;
use stdClass;
use tool_mocktesttime\time_mock;

/**
 * Test unit class of local_taskflow.
 *
 * @package taskflowadapter_ksw
 * @category test
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 */
final class chris_drops_out_via_cohort_test extends advanced_testcase {
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
        $this->externaldata = file_get_contents(__DIR__ . '/external_json/betty_best_two_cohorts_ksw.json');
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
    protected function set_db_second_course(): mixed {
        // Create a user.
        $course = $this->getDataGenerator()->create_course([
            'fullname' => 'Testing second Course',
            'shortname' => 'TC102',
            'category' => 1,
            'enablecompletion' => 1,
        ]);
        return $course;
    }

    /**
     * Setup the test environment.
     * @param int $unitid
     * @param int $courseid
     * @param array $messageids
     * @return array
     */
    public function get_rule($unitid, $targetid, $messageids): array {
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
                        "duration" => 86400 * 30,
                        "timemodified" => time(),
                        "timecreated" => time(),
                        "usermodified" => 1,
                         "actions" => [
                            [
                                "targets" => [
                                    [
                                        "targetid" => $targetid,
                                        "targettype" => "competency",
                                        "targetname" => "mycompetency",
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
     * Setup the test environment.
     * @param int $unitid
     * @param int $targetid
     * @param array $messageids
     * @return array
     */
    public function get_second_rule($unitid, $targetid, $messageids): array {
        $rule = [
            "unitid" => $unitid,
            "rulename" => "test_second_rule",
            "rulejson" => json_encode((object)[
                "rulejson" => [
                    "rule" => [
                        "name" => "test_second_rule",
                        "description" => "test_second_rule_description",
                        "type" => "taskflow",
                        "enabled" => true,
                        "duedatetype" => "duration",
                        "fixeddate" => 23233232222,
                        "duration" => 5184000,
                        "timemodified" => time(),
                        "timecreated" => time(),
                        "usermodified" => 1,
                        "actions" => [
                            [
                                "targets" => [
                                    [
                                        "targetid" => $targetid,
                                        "targettype" => "competency",
                                        "targetname" => "mycompetency",
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
        $secondcohort = array_shift($cohorts);

        $rule = $this->get_rule($cohort->id, $competency->get('id'), $messageids);
        $secondrule = $this->get_rule($secondcohort->id, $competency2->get('id'), $messageids);
        $id = $DB->insert_record('local_taskflow_rules', $rule);
        $rule['id'] = $id;
        $id = $DB->insert_record('local_taskflow_rules', $secondrule);
        $secondrule['id'] = $id;

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

        // User 2 doesnt have competency yet in this case.
        $existing = user_competency::get_records(['userid' => $user2->id]);
        $this->assertEmpty($existing, 'User already has a competency assigned');

        return [$user1, $user2, $user3, $rule, $secondrule, $booking, $course, $competency, $competency2];
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
     * Example test: Ensure external data is loaded.
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
     * @covers \local_taskflow\local\rules\unit_rules
     * @covers \local_taskflow\local\assignments\assignments_facade
     * @covers \local_taskflow\local\assignments\types\standard_assignment
     * @covers \local_taskflow\local\rules\rules
     * @covers \local_taskflow\local\assignments\assignments_facade
     *
     * @param array $bdata
     * @dataProvider booking_common_settings_provider
     */
    public function test_chris_change($bdata): void {
        global $DB;
        $sink = $this->redirectEmails();
        $lock = $this->createMock(\core\lock\lock::class);
        $cronlock = $this->createMock(\core\lock\lock::class);
        [$user1, $user2, $user3, $rule, $secondrule, $booking, $course, $competency, $competency2] = $this->betty_best_base($bdata);

        $sink = $this->redirectEmails();
        $lock = $this->createMock(\core\lock\lock::class);
        $cronlock = $this->createMock(\core\lock\lock::class);

        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

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
        singleton_service::destroy_booking_option_singleton($option1->id);

        // Create a booking option answer - book user2.
        $result = $plugingenerator->create_answer(['optionid' => $option1->id, 'userid' => $user2->id]);
        $this->assertSame(MOD_BOOKING_BO_COND_ALREADYBOOKED, $result);
        singleton_service::destroy_booking_answers($option1->id);

        // Complete booking option for user2.
        $settings = singleton_service::get_instance_of_booking_option_settings($option1->id);
        $option = singleton_service::get_instance_of_booking_option($settings->cmid, $settings->id);
        $plugingeneratortf = self::getDataGenerator()->get_plugin_generator('local_taskflow');
        $plugingeneratortf->runtaskswithintime($cronlock, $lock, time());
        $userchrisid = $DB->get_record('user', ['firstname' => 'Chris'])->id;
        $userbertaid = $DB->get_record('user', ['firstname' => 'Berta'])->id;
        $userchrisemail = $DB->get_record('user', ['firstname' => 'Chris'])->email;
        $userbertaemail = $DB->get_record('user', ['firstname' => 'Berta'])->email;
        $activecohortprechange = $DB->get_records('local_taskflow_unit_members', ['active' => 1, 'userid' => $userchrisid]);
        $activeassignmentsprechange = $DB->get_records('local_taskflow_assignment', ['userid' => $userchrisid, 'active' => 1]);
        // Chris one assignment of rule one.
        $id = $rule['id'];
        $cohorts = $DB->get_records('cohort');
        $cohort = array_shift($cohorts);
        $secondcohort = array_shift($cohorts);
        $this->assertCount(1, $activeassignmentsprechange);
        if (count($activeassignmentsprechange) >= 1) {
            $assign = array_pop($activeassignmentsprechange);
            $this->assertSame((int)$assign->ruleid, $id);
        }

        $dbmsg = array_values($DB->get_records('local_taskflow_messages'));
        foreach ($dbmsg as $index => $msg) {
            $data = json_decode($msg->message);
            $dbmsg[$index]->subject = $data->heading;
        }
        // Assigned message not sent yet.
        $sentmessages = $DB->get_records('local_taskflow_sent_messages');
        $messagesink = array_filter($sink->get_messages(), function ($message) {
            return strpos($message->subject, 'Taskflow -') === 0;
        });
        $this->assertCount(0, $sentmessages);
        $this->assertCount(0, $messagesink);

        // Assigned message sent after reaching time.
        time_mock::set_mock_time(strtotime('+ 6 minutes', time()));
        $plugingeneratortf->runtaskswithintime($cronlock, $lock, time());
        $sentmessages = $DB->get_records('local_taskflow_sent_messages');
        $messagesink = array_filter($sink->get_messages(), function ($message) {
            return strpos($message->subject, 'Taskflow -') === 0;
        });

        time_mock::set_mock_time(strtotime('+ 30 days', time()));
        $plugingeneratortf->runtaskswithintime($cronlock, $lock, time());
        $assignment = $DB->get_record('local_taskflow_assignment', ['userid' => $userchrisid, 'ruleid' => $rule['id']]);
        $this->assertSame((int)$assignment->status, assignment_status_facade::get_status_identifier('overdue'));
        $oldassignmentid = $assignment->id;
        $oldassignedtime = $assignment->assigneddate;
        $sentmessagesrule1 = $DB->get_records(
            'local_taskflow_sent_messages',
            ['userid' => (int)$userchrisid, 'ruleid' => $rule['id']]
        );
        // Assigned, warn1, warn2.
        $this->assertCount(3, $sentmessagesrule1);

        $user = $DB->get_record('user', ['firstname' => 'Chris']);
        profile_load_custom_fields($user);
        $user->profile_field_orgunit = $user->profile['orgunit']  . '\\' . $secondcohort->name;
        $user->profile_field_Org2 = $secondcohort->name;
        profile_save_data($user);
        \core\event\user_updated::create_from_userid($user->id)->trigger();

        $activecohortpostchange = $DB->get_records('local_taskflow_unit_members', ['active' => 1, 'userid' => $userchrisid]);
        $inactiveassignmentspostchange = $DB->get_records('local_taskflow_assignment', ['userid' => $userchrisid, 'active' => 0]);
        $this->assertNotSame($activecohortprechange, $activecohortpostchange);
        // Rule 1 assignment is inactive now for Chris.
        $this->assertCount(1, $inactiveassignmentspostchange);
        if (count($inactiveassignmentspostchange) >= 1) {
            $assignpost = array_pop($inactiveassignmentspostchange);
            $this->assertSame((int)$assignpost->ruleid, $id);
            $this->assertSame((int)$assignpost->status, assignment_status_facade::get_status_identifier('droppedout'));
        }

        // Should not have new assigned message.
        $plugingeneratortf->runtaskswithintime($cronlock, $lock, time());
        $sentmessages = $DB->get_records('local_taskflow_sent_messages');
        $messagesink = array_filter($sink->get_messages(), function ($message) {
            return strpos($message->subject, 'Taskflow -') === 0;
        });

        time_mock::set_mock_time(strtotime('+ 6 minutes', time()));
        $plugingeneratortf->runtaskswithintime($cronlock, $lock, time());
        $sentmessagesrule1 = $DB->get_records(
            'local_taskflow_sent_messages',
            ['userid' => (int)$userchrisid, 'ruleid' => $rule['id']]
        );
        $sentmessagesrule2 = $DB->get_records(
            'local_taskflow_sent_messages',
            ['userid' => (int)$userchrisid, 'ruleid' => $secondrule['id']]
        );
        $messagesink = array_filter($sink->get_messages(), function ($message) {
            return strpos($message->subject, 'Taskflow -') === 0;
        });
        // These are reset on dropout.
        $this->assertCount(0, $sentmessagesrule1);
        // Assigned mail.
        $this->assertCount(1, $sentmessagesrule2);
        $chrismsgsink = array_filter($messagesink, function ($msg) use ($userchrisemail) {
            return $msg->to === $userchrisemail;
        });
        // Assigned x 2, war1 war2.
        $this->assertCount(4, $chrismsgsink);

        $activeassignmentspostchange = $DB->get_records('local_taskflow_assignment', ['userid' => $userchrisid, 'active' => 1]);
        // Rule 2 assigment active for Chris.
        $this->assertCount(1, $activeassignmentspostchange);
        if (count($activeassignmentspostchange) >= 1) {
            $assignpost = array_pop($activeassignmentspostchange);
            $this->assertSame($assignpost->status, '0');
            $this->assertSame((int)$assignpost->ruleid, ($secondrule['id']));
        }

        $user = $DB->get_record('user', ['firstname' => 'Chris']);
        profile_load_custom_fields($user);
        $user->profile_field_orgunit = $cohort->name;
        $user->profile_field_Org1 = $cohort->name;
        $user->profile_field_Org2 = '';
        profile_save_data($user);
        \core\event\user_updated::create_from_userid($user->id)->trigger();
        $plugingeneratortf->runtaskswithintime($cronlock, $lock, time());
        $assignment = $DB->get_record('local_taskflow_assignment', ['id' => $oldassignmentid]);
        $this->assertSame((int)$assignment->status, assignment_status_facade::get_status_identifier('assigned'));
        $this->assertSame((int)$assignment->prolongedcounter, 0);
        $this->assertSame((int)$assignment->overduecounter, 0);
        $this->assertSame((int)$assignment->assigneddate, $oldassignedtime + 86400 * 30 + 60 * 12);
        $this->assertSame((int)$assignment->duedate, $assignment->assigneddate + 86400 * 30);

        time_mock::set_mock_time(strtotime('+ 6 minutes', time()));
        $plugingeneratortf->runtaskswithintime($cronlock, $lock, time());
        $sentmessagesrule1 = $DB->get_records(
            'local_taskflow_sent_messages',
            ['userid' => (int)$userchrisid, 'ruleid' => $rule['id']]
        );
        $historylogs = $DB->get_records('local_taskflow_history', ['assignmentid' => $assignment->id]);
        // 8 Entries Assigned x2 Doppedout Overdue Mail: over1, over2, assigned1, assigned2.
        $this->assertCount(8, $historylogs);
        // 1 assign mail because this table was flused on droput.
        $this->assertCount(1, $sentmessagesrule1);
    }
}

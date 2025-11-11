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
use local_taskflow\event\rule_created_updated;
use local_taskflow\local\assignment_status\assignment_status_facade;
use local_taskflow\local\external_adapter\external_api_base;
use local_taskflow\local\external_adapter\external_api_repository;
use local_taskflow\local\rules\rules;
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
final class chris_change_test extends advanced_testcase {
    /** @var string|null Stores the external user data. */
    protected ?string $externaldata = null;

    /**
     * Setup the test environment.
     */
    protected function setUp(): void {
        parent::setUp();
        time_mock::init();
        time_mock::set_mock_time(strtotime('now'));
        $this->resetAfterTest(true);
        rules::reset_instances();
        \local_taskflow\local\units\unit_relations::reset_instances();
        external_api_base::teardown();
        $this->externaldata = file_get_contents(__DIR__ . '/external_json/chris_change_ksw.json');
        $this->create_custom_profile_field();
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
        $this->preventResetByRollback();
    }

    /**
     * Tear down the test environment.
     *
     * @return void
     *
     */
    protected function tearDown(): void {
        parent::tearDown();
        external_api_base::teardown();
        \local_taskflow\local\units\unit_relations::reset_instances();
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
                        "duration" => 5184000,
                        "timemodified" => 23233232222,
                        "timecreated" => 23233232222,
                        "usermodified" => 1,
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
     * Setup the test environment.
     * @param int $unitid
     * @param int $courseid
     * @param array $messageids
     * @return array
     */
    public function get_second_rule($unitid, $courseid, $messageids): array {
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
                        "timemodified" => 23233232222,
                        "timecreated" => 23233232222,
                        "usermodified" => 1,
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
     */
    public function test_chris_change(): void {
        global $DB;
        $sink = $this->redirectEmails();
        $lock = $this->createMock(\core\lock\lock::class);
        $cronlock = $this->createMock(\core\lock\lock::class);
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('local_taskflow');
        $apidatamanager = external_api_repository::create($this->externaldata);
        $externaldata = $apidatamanager->get_external_data();
        $this->assertNotEmpty($externaldata, 'External user data should not be empty.');
        $apidatamanager->process_incoming_data();

        $cohorts = $DB->get_records('cohort');
        $cohort = array_shift($cohorts);
        $secondcohort = array_shift($cohorts);

        $course = $this->set_db_course();
        $secondcourse = $this->set_db_second_course();
        $messageids = $this->set_messages_db();
        $rule = $this->get_rule($cohort->id, $course->id, $messageids);
        $secondrule = $this->get_second_rule($secondcohort->id, $secondcourse->id, $messageids);
        $id = $DB->insert_record('local_taskflow_rules', $rule);
        $secondruleid = $DB->insert_record('local_taskflow_rules', $secondrule);
        $rule['id'] = $id;
        $event = rule_created_updated::create([
            'objectid' => $rule['id'],
            'context'  => \context_system::instance(),
            'other'    => [
                'ruledata' => $rule,
            ],
        ]);
        $event->trigger();
        $plugingenerator->runtaskswithintime($cronlock, $lock, time());
        $userchrisid = $DB->get_record('user', ['firstname' => 'Chris'])->id;
        $userbertaid = $DB->get_record('user', ['firstname' => 'Berta'])->id;
        $userchrisemail = $DB->get_record('user', ['firstname' => 'Chris'])->email;
        $userbertaemail = $DB->get_record('user', ['firstname' => 'Berta'])->email;
        $activecohortprechange = $DB->get_records('local_taskflow_unit_members', ['active' => 1, 'userid' => $userchrisid]);
        $activeassignmentsprechange = $DB->get_records('local_taskflow_assignment', ['userid' => $userchrisid, 'active' => 1]);
        // Chris one assignment of rule one.
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
        $plugingenerator->runtaskswithintime($cronlock, $lock, time());
        $sentmessages = $DB->get_records('local_taskflow_sent_messages');
        $messagesink = array_filter($sink->get_messages(), function ($message) {
            return strpos($message->subject, 'Taskflow -') === 0;
        });

        time_mock::set_mock_time(strtotime('+ 30 days', time()));
        $temp = (array)$externaldata;
        $temp[1]->Organisation = $temp[1]->Organisation . '\\' . $secondcohort->name;
        $externaldata = (object)$temp;
        $apidatamanager->process_incoming_data();
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
        $plugingenerator->runtaskswithintime($cronlock, $lock, time());
        $sentmessages = $DB->get_records('local_taskflow_sent_messages');
        $messagesink = array_filter($sink->get_messages(), function ($message) {
            return strpos($message->subject, 'Taskflow -') === 0;
        });

        time_mock::set_mock_time(strtotime('+ 6 minutes', time()));
        $plugingenerator->runtaskswithintime($cronlock, $lock, time());
        $sentmessages = $DB->get_records('local_taskflow_sent_messages');
        $messagesink = array_filter($sink->get_messages(), function ($message) {
            return strpos($message->subject, 'Taskflow -') === 0;
        });
        $this->assertCount(1, $sentmessages);
        $user1msg = array_filter($sentmessages, function ($sentmessage) use ($userchrisid) {
            return $sentmessage->userid === $userchrisid;
        });
        $this->assertCount(1, $user1msg);
        $this->assertCount(2, $messagesink);
        $chrismsgsink = array_filter($messagesink, function ($msg) use ($userchrisemail) {
            return $msg->to === $userchrisemail;
        });
        $this->assertCount(2, $chrismsgsink);
        foreach ($chrismsgsink as $msg) {
            $this->assertSame(
                $dbmsg[3]->subject,
                $msg->subject,
            );
        }

        $activeassignmentspostchange = $DB->get_records('local_taskflow_assignment', ['userid' => $userchrisid, 'active' => 1]);
        // Rule 2 assigment active for Chris.
        $this->assertCount(1, $activeassignmentspostchange);
        if (count($activeassignmentspostchange) >= 1) {
            $assignpost = array_pop($activeassignmentspostchange);
            $this->assertSame($assignpost->status, '0');
            $this->assertSame((int)$assignpost->ruleid, ($secondruleid));
        }
    }
}

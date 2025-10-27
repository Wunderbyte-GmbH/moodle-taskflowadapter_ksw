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

namespace taskflowadapter_ksw\usecases;

use advanced_testcase;
use tool_mocktesttime\time_mock;
use local_taskflow\event\rule_created_updated;
use local_taskflow\local\external_adapter\external_api_base;
use local_taskflow\local\external_adapter\external_api_repository;
use local_taskflow\local\rules\unit_rules;

/**
 * Test unit class of local_taskflow.
 *
 * @package local_taskflow
 * @category test
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 */
final class sara_sick_message_reset_test extends advanced_testcase {
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
        \local_taskflow\local\units\unit_relations::reset_instances();
        $this->externaldata = file_get_contents(__DIR__ . '/external_json/sara_sick.json');
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('local_taskflow');

        $plugingenerator->create_custom_profile_fields([
            'supervisor',
            'units',
            'externalid',
            'longleave',
        ]);
        $plugingenerator->set_config_values('tuines');
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
        $messages = json_decode(file_get_contents(__DIR__ . '/../mock/messages/messages.json'));
        foreach ($messages as $message) {
            $messageids[] = (object)['messageid' => $DB->insert_record('local_taskflow_messages', $message)];
        }
        return $messageids;
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
     * @covers \local_taskflow\task\update_assignment
     * @runInSeparateProcess
     */
    public function test_sara_sick_message_reset_sick(): void {
        global $DB;

        $apidatamanager = external_api_repository::create($this->externaldata);
        $externaldata = $apidatamanager->get_external_data();
        $this->assertNotEmpty($externaldata, 'External user data should not be empty.');
        $apidatamanager->process_incoming_data();

        $cohorts = $DB->get_records('cohort');
        $cohort = array_shift($cohorts);

        $course = $this->set_db_course();
        $messageids = $this->set_messages_db();

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
        $this->runAdhocTasks();
        $assignments = $DB->get_records('local_taskflow_assignment');
        $this->assertNotEmpty($assignments);

        $this->runAdhocTasks();
        $sentmessage = $DB->get_records('local_taskflow_sent_messages');
        $this->assertNotEmpty($sentmessage);

        $sara = $DB->get_record('user', ['firstname' => 'Sara']);

        $sarafirstplannedmails = $this->get_saras_mails($sara->id);

        $externaldata->persons[1]->currentlyOnLongLeave = true;
        $apidatamanager->process_incoming_data();

        $assignments = $DB->get_records('local_taskflow_assignment');
        $this->assertNotEmpty($assignments);

        $this->runAdhocTasks();
        $sentmessages = $DB->get_records('local_taskflow_sent_messages');
        foreach ($sentmessages as $sentmessage) {
            $this->assertNotEquals($sara->id, $sentmessage->userid);
        }

        $saraassigments = $DB->get_records('local_taskflow_assignment', ['userid' => $sara->id]);
        foreach ($saraassigments as $saraassigment) {
            $this->assertEquals('0', $saraassigment->active);
        }

        $saramemberships = $DB->get_records('local_taskflow_unit_members', ['userid' => $sara->id]);
        foreach ($saramemberships as $saramembership) {
            $this->assertEquals('0', $saramembership->active);
        }

        unit_rules::reset_instances();
        $externaldata->persons[1]->currentlyOnLongLeave = false;
        $apidatamanager->process_incoming_data();

        $saraassigments = $DB->get_records('local_taskflow_assignment', ['userid' => $sara->id]);
        foreach ($saraassigments as $saraassigment) {
            $this->assertEquals('0', $saraassigment->status);
        }
        $sarasecondplannedmails = $this->get_saras_mails($sara->id);
        foreach ($sarafirstplannedmails as $key => $sarafirstplannedmail) {
            $this->assertNotEmpty($sarafirstplannedmail->id);
            $this->assertNotEmpty($sarasecondplannedmails[$key]->id);
        }
    }

    /**
     * Setup the test environment.
     * @param int $saraid
     * @return array
     */
    public function get_saras_mails($saraid): array {
        global $DB;
        $sarasmails = [];
        $adhoctasks = $DB->get_records('task_adhoc');
        foreach ($adhoctasks as $adhoctask) {
            if (strpos($adhoctask->customdata, $saraid)) {
                $sarasmails[] = $adhoctask;
            }
        }
        return $sarasmails;
    }
}

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
use local_taskflow\event\rule_created_updated;
use local_taskflow\form\rules\rule;
use local_taskflow\local\assignment_status\assignment_status_facade;
use local_taskflow\local\changemanager\changemanager;
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
final class Change_rule_test extends advanced_testcase {
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
    public function get_rule($unitid, $courseid, $messageids, $recursive): array {
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
                        "recursive" => $recursive,
                        "duedatetype" => "duration",
                        "fixeddate" => 23233232222,
                        "duration" => 5184000,
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
        $messages = json_decode(file_get_contents(__DIR__ . '/../mock/messages/warning_messages.json'));
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
     * @covers \local_taskflow\local\assignments\assignments_facade
     * @covers \local_taskflow\local\assignments\types\standard_assignment
     * @covers \local_taskflow\local\rules\rules
     * @covers \local_taskflow\local\assignments\assignments_facade
     *
     */
    public function test_change_rule(): void {
        global $DB;

        $apidatamanager = external_api_repository::create($this->externaldata);
        $externaldata = $apidatamanager->get_external_data();
        $this->assertNotEmpty($externaldata, 'External user data should not be empty.');
        $apidatamanager->process_incoming_data();

        $cohorts = $DB->get_records('cohort');
        $cohort = array_shift($cohorts);

        $course = $this->set_db_course();
        $messageids = $this->set_messages_db();

        $rule = $this->get_rule($cohort->id, $course->id, $messageids, false);

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
        $userchrisid = $DB->get_record('user', ['firstname' => 'Chris'])->id;

        $assignmentsprechange = $DB->get_records('local_taskflow_assignment', ['userid' => $userchrisid]);
        foreach ($assignmentsprechange as $assignment) {
            $this->assertSame((int)$assignment->duedate, (int)$assignment->assigneddate + 5184000);
        }
        $dbrule = $DB->get_record('local_taskflow_rules', ['id' => $id]);
        $ruleobj = json_decode($dbrule->rulejson);
        $ruleobj->rulejson->rule->duration = 2592000;
        $ruleobj->rulejson->rule->timemodified = time();
        $dbrule->rulejson = json_encode($ruleobj);

        $changemanager = new changemanager($id, (array)$dbrule);
        $changedata = $changemanager->get_change_management_data();

        $DB->update_record('local_taskflow_rules', $dbrule);

        $assignmentspostchange = $DB->get_records('local_taskflow_assignment', ['userid' => $userchrisid]);
        foreach ($assignmentspostchange as $assignment) {
            // Assignment should be the same even if rule is changed.
            $this->assertSame((int)$assignment->duedate, (int)$assignment->assigneddate + 5184000);
        }
        $dbrule->changemanagement = $changedata;
        $event = rule_created_updated::create([
            'objectid' => $dbrule->id,
            'context'  => \context_system::instance(),
            'other'    => [
                'ruledata' => $dbrule,
            ],
        ]);
        $event->trigger();
        $this->runAdhocTasks();


        $assignmentspostchange = $DB->get_records('local_taskflow_assignment', ['userid' => $userchrisid]);
        foreach ($assignmentspostchange as $assignment) {
            // Assignment should be the same even if rule is changed and the event has been triggered because it not recursively set.
            $this->assertSame((int)$assignment->duedate, (int)$assignment->assigneddate + 5184000);
        }


        $dbrule = $DB->get_record('local_taskflow_rules', ['id' => $id]);
        $ruleobj = json_decode($dbrule->rulejson);
        $ruleobj->rulejson->rule->recursive = true;
        $dbrule->rulejson = json_encode($ruleobj);

        $changemanager = new changemanager($id, (array)$dbrule);
        $changedata = $changemanager->get_change_management_data();

        $DB->update_record('local_taskflow_rules', $dbrule);
        rules::reset_instances();
        $dbrule->changemanagement = $changedata;
        $event = rule_created_updated::create([
            'objectid' => $dbrule->id,
            'context'  => \context_system::instance(),
            'other'    => [
                'ruledata' => $dbrule,
            ],
        ]);
        $event->trigger();
        $this->runAdhocTasks();

        $newuser = $this->getDataGenerator()->create_user(['firstname' => 'Newuser']);
        $testingsupervisor = $this->getDataGenerator()->create_user([
            'firstname' => 'Super',
            'lastname' => 'Visor',
            'email' => 'auper@visor.com',
        ]);
        $fieldid = $DB->get_field('user_info_field', 'id', ['shortname' => 'supervisor'], MUST_EXIST);
        $exsistinginfodata = $DB->get_record(
            'user_info_data',
            [
                    'userid' => $newuser->id,
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
                'userid' => $newuser->id,
                'fieldid' => $fieldid,
                'data' => $testingsupervisor->id,
                'dataformat' => FORMAT_HTML,
            ]);
        }
        cohort_add_member($cohort->id, $newuser->id);
        $apidatamanager->process_incoming_data();

        $assignmentspostchange = $DB->get_records('local_taskflow_assignment', ['userid' => $userchrisid]);
        foreach ($assignmentspostchange as $assignment) {
            $this->assertSame((int)$assignment->duedate, (int)$assignment->assigneddate + 2592000);
        }
        $assignmentspostchangenewuser = $DB->get_records('local_taskflow_assignment', ['userid' => $newuser->id]);
        foreach ($assignmentspostchangenewuser as $assignment) {
            $this->assertSame((int)$assignment->duedate, (int)$assignment->assigneddate + 2592000);
        }
    }
}

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
use completion_completion;
use local_taskflow\event\rule_created_updated;
use local_taskflow\local\assignment_status\assignment_status_facade;
use local_taskflow\local\external_adapter\external_api_base;
use local_taskflow\local\external_adapter\external_api_repository;
use local_taskflow\task\open_planned_assignment;

/**
 * Test unit class of local_taskflow.
 *
 * @package local_taskflow
 * @category test
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 */
final class paul_planned_mock_assigned_test extends advanced_testcase {
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
        $this->externaldata = file_get_contents(__DIR__ . '/external_json/chris_change.json');
        $this->create_custom_profile_field();
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('local_taskflow');

        $plugingenerator->create_custom_profile_fields([
            'supervisor',
            'units',
            'externalid',
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
                        "duration" => 23233232222,
                        "timemodified" => 23233232222,
                        "timecreated" => 23233232222,
                        "usermodified" => 1,
                        "activationdelay" => 600,
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
     * @covers \local_taskflow\task\open_planned_assignment
     * @covers \local_taskflow\task\update_assignment
     */
    public function test_paul_planned(): void {
        global $DB;

        $DB->delete_records('local_taskflow_assignment');

        $apidatamanager = external_api_repository::create($this->externaldata);
        $externaldata = $apidatamanager->get_external_data();
        $this->assertNotEmpty($externaldata, 'External user data should not be empty.');
        $apidatamanager->process_incoming_data();

        $plugingenerator = self::getDataGenerator()->get_plugin_generator('local_taskflow');
        $lock = $this->createMock(\core\lock\lock::class);
        $cronlock = $this->createMock(\core\lock\lock::class);

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
        $plugingenerator->runtaskswithintime($cronlock, $lock, time());
        // New assignment with planned status.
        // New event should be scheduled.
        $assignemnts = $DB->get_records('local_taskflow_assignment');
        foreach ($assignemnts as $assignemnt) {
            $this->assertEquals(0, $assignemnt->active);
            $this->assertEquals(null, $assignemnt->duedate);
            $this->assertEquals(null, $assignemnt->assigneddate);
            $this->assertEquals('-1', $assignemnt->status);
        }

        // The delayed time is 10 minutes, so after 6 miuntes the assignment should still be not open
        time_mock::set_mock_time(strtotime('+ 6 minutes', time()));
        $plugingenerator->runtaskswithintime($cronlock, $lock, time());

        $assignemnts = $DB->get_records('local_taskflow_assignment');
        foreach ($assignemnts as $assignemnt) {
            $this->assertEquals(0, $assignemnt->active);
            $this->assertEquals(null, $assignemnt->duedate);
            $this->assertEquals(null, $assignemnt->assigneddate);
            $this->assertEquals('-1', $assignemnt->status);
        }

        $assignemnts = $DB->get_records('local_taskflow_assignment');
        foreach ($assignemnts as $assignemnt) {
            $this->assertEquals(0, $assignemnt->active);
            $this->assertEquals(null, $assignemnt->duedate);
            $this->assertEquals(null, $assignemnt->assigneddate);
            $this->assertEquals('-1', $assignemnt->status);
        }

        // The delayed time is 10 minutes, so after another 6 miuntes the assignment should still be open
        time_mock::set_mock_time(strtotime('+ 12 minutes', time()));
        // First open the assignment.
        $plugingenerator->runtaskswithintime($cronlock, $lock, time());
        // Update the assignment status.
        $plugingenerator->runtaskswithintime($cronlock, $lock, time());

        // Manually trigger activation event.
        foreach ($assignemnts as $assignment) {
            $completedassignemnt = $DB->get_record('local_taskflow_assignment', ['id' => $assignment->id]);
            // Assignment should be completed.
            $this->assertEquals(1, $completedassignemnt->active);
            $this->assertNotEquals(null, $completedassignemnt->duedate);
            $this->assertNotEquals(null, $completedassignemnt->assigneddate);
            $this->assertEquals(assignment_status_facade::get_status_identifier('assigned'), $completedassignemnt->status);
        }
    }

    /**
     * Setup the test environment.
     * @param int $courseid
     * @param int $userid
     * @covers \local_taskflow\local\history\types\base
     * @covers \local_taskflow\local\history\types\typesfactory
     */
    protected function course_completed($courseid, $userid): void {
        global $DB;
        $enrol = enrol_get_plugin('manual');
        $instances = enrol_get_instances($courseid, true);
        $manualinstance = null;
        foreach ($instances as $instance) {
            if ($instance->enrol === 'manual') {
                $manualinstance = $instance;
                break;
            }
        }

        if (!$manualinstance) {
            $enrolid = $DB->insert_record('enrol', [
                'enrol' => 'manual',
                'status' => ENROL_INSTANCE_ENABLED,
                'courseid' => $courseid,
            ]);
            $manualinstance = $DB->get_record('enrol', ['id' => $enrolid]);
        }

        $enrol->enrol_user($manualinstance, $userid, 5);

        $completion = new completion_completion([
            'course' => $courseid,
            'userid' => $userid,
        ]);
        $completion->mark_complete();
    }
}

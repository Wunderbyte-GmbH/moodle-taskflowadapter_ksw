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
use cache_helper;
use completion_completion;
use local_taskflow\event\rule_created_updated;
use local_taskflow\local\external_adapter\external_api_base;

/**
 * Test unit class of local_taskflow.
 *
 * @package taskflowadapter_ksw
 * @category test
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 */
final class laurence_longleave_test extends advanced_testcase {
    /** @var string|null Stores the external user data. */
    protected ?string $externaldata = null;

    /**
     * Setup the test environment.
     * @covers \local_taskflow\local\rules\rules
     */
    protected function setUp(): void {
        parent::setUp();
        time_mock::init();
        time_mock::set_mock_time(strtotime('now'));
        $this->resetAfterTest(true);
        \local_taskflow\local\units\unit_relations::reset_instances();
        \local_taskflow\local\rules\rules::reset_instances();
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('local_taskflow');

        $plugingenerator->create_custom_profile_fields([
            'supervisor',
            'units',
            'longleave',
        ]);
        $plugingenerator->set_config_values();
        $this->create_custom_profile_field();
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
    protected function set_config_values(): void {
        global $DB;
        $settingvalues = [
            'supervisor_field' => 'supervisor',
        ];
        foreach ($settingvalues as $key => $value) {
            set_config($key, $value, 'local_taskflow');
        }
        cache_helper::invalidate_by_event('config', ['local_taskflow']);
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
     */
    protected function set_db_user(): mixed {
        global $DB;
        // Create a user.
        $user = $this->getDataGenerator()->create_user([
            'firstname' => 'Betty',
            'lastname' => 'Best',
            'email' => 'betty@example.com',
        ]);

        $testingsupervisor = $this->getDataGenerator()->create_user([
            'firstname' => 'Super',
            'lastname' => 'Visor',
            'email' => 'auper@visor.com',
        ]);

        $fieldid = $DB->get_field('user_info_field', 'id', ['shortname' => 'supervisor'], MUST_EXIST);
        $exsistinginfodata = $DB->get_record(
            'user_info_data',
            [
                    'userid' => $user->id,
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
                'userid' => $user->id,
                'fieldid' => $fieldid,
                'data' => $testingsupervisor->id,
                'dataformat' => FORMAT_HTML,
            ]);
        }
        $fieldid = $DB->get_field('user_info_field', 'id', ['shortname' => 'longleave'], MUST_EXIST);
        $record = $DB->get_record('user_info_data', ['userid' => $user->id, 'fieldid' => $fieldid]);
        if ($record) {
            $record->data = '1';
            $DB->update_record('user_info_data', $record);
        } else {
            $DB->insert_record('user_info_data', (object)[
                'userid' => $user->id,
                'fieldid' => $fieldid,
                'data' => '1',
                'dataformat' => FORMAT_HTML,
            ]);
        }
        return $user;
    }

    /**
     * Setup the test environment.
     */
    protected function set_second_db_user(): mixed {
        global $DB;
        // Create a user.
        $user = $this->getDataGenerator()->create_user([
            'firstname' => 'Boris',
            'lastname' => 'Best',
            'email' => 'boris@example.com',
        ]);

        $testingsupervisor = $this->getDataGenerator()->create_user([
            'firstname' => 'Super',
            'lastname' => 'Visor',
            'email' => 'auper@visor.com',
        ]);

        $fieldid = $DB->get_field('user_info_field', 'id', ['shortname' => 'supervisor'], MUST_EXIST);
        $exsistinginfodata = $DB->get_record(
            'user_info_data',
            [
                    'userid' => $user->id,
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
                'userid' => $user->id,
                'fieldid' => $fieldid,
                'data' => $testingsupervisor->id,
                'dataformat' => FORMAT_HTML,
            ]);
        }
        $fieldid = $DB->get_field('user_info_field', 'id', ['shortname' => 'longleave'], MUST_EXIST);
        $record = $DB->get_record('user_info_data', ['userid' => $user->id, 'fieldid' => $fieldid]);
        if ($record) {
            $record->data = '0';
            $DB->update_record('user_info_data', $record);
        } else {
            $DB->insert_record('user_info_data', (object)[
                'userid' => $user->id,
                'fieldid' => $fieldid,
                'data' => '0',
                'dataformat' => FORMAT_HTML,
            ]);
        }
        return $user;
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
     * @param int $courseid
     * @param int $userid
     * @covers \local_taskflow\local\history\types\base
     * @covers \local_taskflow\local\history\types\typesfactory
     */
    protected function course_completed($courseid, $userid): void {
        $completion = new completion_completion([
            'course' => $courseid,
            'userid' => $userid,
        ]);
        $completion->mark_complete();
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
            'contextid' => \context_system::instance()->id,
        ]);
        return $cohort;
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
                        "cyclicvalidation" => "1",
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
     * @covers \local_taskflow\local\completion_process\scheduling_cyclic_adhoc
     * @covers \local_taskflow\local\completion_process\scheduling_event_messages
     * @covers \local_taskflow\local\history\history
     * @covers \local_taskflow\local\eventhandlers\assignment_completed
     * @covers \local_taskflow\local\eventhandlers\assignment_status_changed
     * @covers \local_taskflow\event\assignment_completed
     * @covers \local_taskflow\observer
     * @covers \local_taskflow\task\send_taskflow_message
     * @covers \local_taskflow\task\reset_cyclic_assignment
     * @covers \local_taskflow\local\assignments\status\assignment_status
     * @covers \local_taskflow\local\messages\message_sending_time
     * @covers \local_taskflow\local\messages\message_recipient
     * @covers \local_taskflow\local\messages\placeholders\placeholders_factory
     * @covers \local_taskflow\local\assignments\assignments_facade
     * @covers \local_taskflow\local\assignmentrule\assignmentrule
     * @covers \local_taskflow\local\messages\types\standard
     * @covers \local_taskflow\local\rules\rules
     * @covers \local_taskflow\local\assignment_process\assignments\assignments_controller
     * @covers \local_taskflow\local\assignment_operators\action_operator
     * @covers \local_taskflow\local\assignment_process\assignment_preprocessor
     * @covers \local_taskflow\local\eventhandlers\unit_member_removed
     * @covers \local_taskflow\local\unassignment_process\unassignments\unassignment_controller
     * @covers \local_taskflow\local\assignments\assignments_facade
     * @covers \local_taskflow\local\assignments\types\standard_assignment
     */
    public function test_betty_best(): void {
        global $DB;
        $user = $this->set_db_user();
        $seconduser = $this->set_second_db_user();
        $course = $this->set_db_course();
        $cohort = $this->set_db_cohort();
        $messageids = $this->set_messages_db();
        cohort_add_member($cohort->id, $user->id);
        cohort_add_member($cohort->id, $seconduser->id);
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
        $this->assertCount(1, $assignments);
        $assignment = array_shift($assignments);
        $this->assertEquals($seconduser->id, $assignment->userid);

        // Both users no long longleave.
        $this->set_users_longleave_state($user->id, '0');
        $event = rule_created_updated::create([
            'objectid' => $rule['id'],
            'context'  => \context_system::instance(),
            'other'    => [
                'ruledata' => $rule,
            ],
        ]);
        $event->trigger();
        $this->runAdhocTasks();

        // Should be assigned after longelave changes.
        $assignments = $DB->get_records('local_taskflow_assignment');
        $this->assertCount(2, $assignments);
    }

    /**
     * Setup the test environment.
     * @param int $userid
     * @param int $state
     */
    protected function set_users_longleave_state($userid, $state): void {
        global $DB;

        $fieldid = $DB->get_field('user_info_field', 'id', ['shortname' => 'longleave'], MUST_EXIST);
        $record = $DB->get_record('user_info_data', ['userid' => $userid, 'fieldid' => $fieldid]);
        if ($record) {
            $record->data = $state;
            $DB->update_record('user_info_data', $record);
        } else {
            $DB->insert_record('user_info_data', (object)[
                'userid' => $userid,
                'fieldid' => $fieldid,
                'data' => $state,
                'dataformat' => FORMAT_HTML,
            ]);
        }
        return;
    }
}

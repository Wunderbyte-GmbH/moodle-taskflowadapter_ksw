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
use context_course;
use local_taskflow\event\rule_created_updated;
use local_taskflow\local\external_adapter\external_api_base;
use local_taskflow\output\singleassignment;
use renderer_base;
use stdClass;
use taskflowadapter_standard\output\editassignment_template_data;
use taskflowadapter_tuines\form\comment_form;
use taskflowadapter_tuines\table\assignments_table;

/**
 * Test unit class of local_taskflow.
 *
 * @package local_taskflow
 * @category test
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 */
final class betty_best_test extends advanced_testcase {
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
        \local_taskflow\local\rules\rules::reset_instances();
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('local_taskflow');

        $plugingenerator->create_custom_profile_fields([
            'supervisor',
            'units',
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
        return $user;
    }

    /**
     * Setup the test environment.
     * @return object
     */
    protected function set_db_course(): mixed {
        global $DB;

        $shortname = 'TC101BETTY';

        if ($DB->record_exists('course', ['shortname' => $shortname])) {
            return $DB->get_record('course', ['shortname' => $shortname]);
        }

        $course = $this->getDataGenerator()->create_course([
            'fullname' => 'Test Course',
            'shortname' => $shortname,
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
     * @covers \local_taskflow\table\assignments_table
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
     * @covers \local_taskflow\local\messages\message_sending_time
     * @covers \local_taskflow\local\messages\message_recipient
     * @covers \local_taskflow\local\messages\placeholders\placeholders_factory
     * @covers \local_taskflow\output\singleassignment
     * @covers \taskflowadapter_standard\output\editassignment_template_data
     * @covers \taskflowadapter_tuines\form\comment_form
     * @covers \taskflowadapter_tuines\output\comment_history
     *
     * @runInSeparateProcess
     */
    public function test_betty_best(): void {
        global $DB;
        $user = $this->set_db_user();
        $course = $this->set_db_course();
        $cohort = $this->set_db_cohort();
        $messageids = $this->set_messages_db();
        cohort_add_member($cohort->id, $user->id);
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
        $assignment = $DB->get_records('local_taskflow_assignment');
        $this->assertNotEmpty($assignment);

        // Complete course.
        $coursecontext = context_course::instance($course->id);
        $this->assertTrue(is_enrolled($coursecontext, $user->id));
        $this->course_completed($course->id, $user->id);

        $taskadhocmessages = $DB->get_records('task_adhoc');
        $this->assertNotEmpty($taskadhocmessages);

        $assignmenthistory = $DB->get_records('local_taskflow_history');
        $this->assertNotEmpty($assignmenthistory);
        $this->runAdhocTasks();

        $sendmessages = $DB->get_records('local_taskflow_messages');
        $this->assertNotEmpty($sendmessages);

        $oldassignment = array_shift($assignment);
        $newassignment = $DB->get_record('local_taskflow_assignment', ['id' => $oldassignment->id]);
        $this->assertNotEmpty($newassignment->status);

        $data = (object)[
            'id' => $course->id,
            'reset_completion' => 1,
        ];
        reset_course_userdata($data);
        $this->runAdhocTasks();
        $newassignments = $DB->get_records('local_taskflow_assignment');
        global $PAGE;
        $PAGE->set_url(new \moodle_url('/local/taskflow/tests/fake.php'));
        $table = new assignments_table('dummy');
        foreach ($newassignments as $newassignment) {
            $this->assertEquals(0, $newassignment->status);
            $sa = new singleassignment(['id' => $newassignment->id]);
            $data = $sa->export_for_template($this->createMock(renderer_base::class));

            $this->assertArrayHasKey('assignmentdata', $data);
            $this->assertFalse($sa->is_my_assignment());

            $values = new stdClass();
            $values->id = $newassignment->id;

            $json = json_encode((object)['id' => $values->id, 'name' => 'Dummy Assignment']);
            $result = $table->action_toggleassigmentactive($values->id, $json);

            $this->assertArrayHasKey('success', $result);
            $this->assertArrayHasKey('message', $result);
            $this->assertSame(1, $result['success']);
            $this->assertNotEmpty($result['message']);
        }

        $this->course_completed($course->id, $user->id);
        $completion = new completion_completion(['course' => $course->id, 'userid' => $user->id]);
        if ($completion->is_complete()) {
            $completion->delete();
        }

        // Manually emit the event your plugin listens for.
        $evt = \core\event\course_completion_updated::create([
            'context'        => context_course::instance($course->id),
            'courseid'       => $course->id,
            'relateduserid'  => $user->id,
            'other' => [
                'previousstate' => COMPLETION_COMPLETE,
                'newstate'      => COMPLETION_INCOMPLETE,
            ],
        ]);
        $evt->trigger();
        $this->runAdhocTasks();

        foreach ($newassignments as $newassignment) {
            // Instantiate template data class.
            $templatedata = new editassignment_template_data((array)$newassignment);
            $result = $templatedata->export_for_template($this->get_renderer());

            // Assertions.
            $this->assertIsArray($result);
            $this->assertArrayHasKey('assignmentdata', $result);
            $this->assertArrayHasKey('id', $result);
            $this->assertEquals($newassignment->id, $result['id']);

            // Ensure labels were mapped.
            $labels = array_column($result['assignmentdata'], 'label');
            $this->assertContains(get_string('fullname'), $labels);
            $this->assertContains(get_string('name'), $labels);
            $this->assertContains(get_string('description'), $labels);

            $form = new comment_form(
                null,
                ['id' => $newassignment->id],
                'post',
                '',
                [],
                true,
                []
            );
            $form->set_data_for_dynamic_submission();

            $mform = $this->get_mform($form);

            $idvalue = $mform->getElementValue('id');
            if (is_array($idvalue)) {
                $idvalue = reset($idvalue);
            }
            $this->assertEquals($newassignment->id, (int)$idvalue);
            $this->assertNotNull($mform->getElement('commenthistory'));
        }

        // Delete users, assignments, membership, histroy, sent messages.
        delete_user($user);

        $newassignmenthistory = $DB->get_records('local_taskflow_history');
        $this->assertTrue(count($assignmenthistory) > count($newassignmenthistory));
    }

    /**
     * Helper to access the protected _form (HTML_QuickForm) instance.
     * @param comment_form $form
     */
    private function get_mform(comment_form $form): \HTML_QuickForm {
        $ref = new \ReflectionClass($form);
        $prop = $ref->getProperty('_form');
        $prop->setAccessible(true);
        /** @var \HTML_QuickForm $mform */
        $mform = $prop->getValue($form);
        return $mform;
    }

    /**
     * Helper to get a renderer.
     */
    private function get_renderer() {
        global $PAGE;
        return $PAGE->get_renderer('core');
    }
}

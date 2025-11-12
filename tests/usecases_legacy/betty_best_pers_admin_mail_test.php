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
use core_competency\competency;
use core_competency\competency_framework;
use core_competency\user_competency;
use local_taskflow\event\rule_created_updated;
use local_taskflow\local\external_adapter\external_api_base;
use local_taskflow\output\singleassignment;
use mod_booking\bo_availability\bo_info;
use mod_booking\booking_option;
use mod_booking\singleton_service;
use renderer_base;
use stdClass;

/**
 * Test unit class of local_taskflow.
 *
 * @package taskflowadapter_ksw
 * @category test
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 */
final class betty_best_pers_admin_mail_test extends advanced_testcase {
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
        // Create a user.
        $course = $this->getDataGenerator()->create_course([
            'fullname' => 'Test Course',
            'shortname' => 'TC1010',
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
     * @return int
     */
    protected function set_db_competency(): int {
        global $DB;

        // STEP 1: Create a scale.
        $scale = new \stdClass();
        $scale->name = 'Test Scale';
        $scale->scale = 'Not competent,Competent';
        $scale->description = '';
        $scale->descriptionformat = FORMAT_HTML;
        $scale->userid = 2;
        $scale->standard = 1;
        $scaleid = $DB->insert_record('scale', $scale);

        $scaleitems = array_map('trim', explode(',', $scale->scale));
        if (count($scaleitems) < 2) {
            throw new \moodle_exception('Scale must have at least 2 items.');
        }

        // STEP 2: Configure the scaleconfiguration **with string keys**.
        $scaleconfiguration = [
            (object)[ 'scaleid' => $scaleid ],
            (object)[ 'scaleid' => $scaleid, 'proficient' => true ],
            (object)[ 'scaleid' => $scaleid, 'scaledefault' => true ],
        ];

        $framework = new competency_framework(0, (object)[
            'shortname' => 'TFW',
            'idnumber' => 'framework1',
            'contextid' => \context_system::instance()->id,
            'scaleid' => $scaleid,
            'scaleconfiguration' => json_encode($scaleconfiguration),
        ]);
        $framework->create();

        $comp = new competency(0, (object)[
            'shortname' => 'Test Competency',
            'idnumber' => 'comp1',
            'competencyframeworkid' => $framework->get('id'),
            'contextid' => \context_system::instance()->id,
        ]);
        $comp->create();
        return $comp->get('id');
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
     * @param array $targetids
     * @param array $messageids
     * @return array
     */
    public function get_rule($unitid, $targetids, $messageids): array {
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
                                        "targetid" => array_shift($targetids),
                                        "targettype" => "moodlecourse",
                                        "targetname" => "mytargetname2",
                                        "sortorder" => 2,
                                        "actiontype" => "enroll",
                                        "completebeforenext" => false,
                                    ],
                                    [
                                        "targetid" => array_shift($targetids),
                                        "targettype" => "competency",
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
     * @param string $userid
     * @return array
     */
    protected function set_messages_db($userid): array {
        global $DB;
        $messageids = [];
        $messages = json_decode(file_get_contents(__DIR__ . '/../mock/messages/personal_messages.json'));
        foreach ($messages as $message) {
            $message->sending_settings = str_replace('CHANGETOUSERID', $userid, $message->sending_settings);
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
     * @covers \local_taskflow\local\actions\types\unenroll
     * @covers \local_taskflow\output\singleassignment
     * @covers \local_taskflow\form\userevidence
     * @covers \local_taskflow\local\competencies\assignment_competency
     * @runInSeparateProcess
     */
    public function test_betty_best(): void {
        global $DB;
        $user = $this->set_db_user();
        $course = $this->set_db_course();
        $competencyid = $this->set_db_competency();
        $cohort = $this->set_db_cohort();
        $messageids = $this->set_messages_db($user->id);
        cohort_add_member($cohort->id, $user->id);
        $rule = $this->get_rule($cohort->id, [$course->id, $competencyid], $messageids);
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
        $this->runAdhocTasks();

        // Complete course.
        $coursecontext = context_course::instance($course->id);
        $this->assertTrue(is_enrolled($coursecontext, $user->id));
        $competencyrecord = (object)[
            'userid' => $user->id,
            'competencyid' => $competencyid,
            'status' => user_competency::STATUS_IDLE,
            'proficiency' => 1,
            'usermodified' => 2,
            'grade' => 1,
            'reviewerid' => 2,
            'timecreated' => time(),
            'timemodified' => time(),
        ];
        $competencyrecord->id = $DB->insert_record('competency_usercomp', $competencyrecord);
        $this->course_completed($course->id, $user->id);

        $taskadhocmessages = $DB->get_records('task_adhoc');
        $this->assertNotEmpty($taskadhocmessages);

        $assignmenthistory = $DB->get_records('local_taskflow_history');
        $this->assertNotEmpty($assignmenthistory);
        $this->runAdhocTasks();

        $record = $DB->get_record('competency_usercomp', [
            'userid' => $user->id,
            'competencyid' => $competencyid,
        ]);

        $enrolments = $DB->get_records('user_enrolments', ['userid' => $user->id]);

        $oldassignment = array_shift($assignment);
        $newassignment = $DB->get_record('local_taskflow_assignment', ['id' => $oldassignment->id]);

        $sa = new singleassignment(['id' => $newassignment->id]);
        $data = $sa->export_for_template($this->createMock(renderer_base::class));

        $this->assertArrayHasKey('assignmentdata', $data);
        $this->assertFalse($sa->is_my_assignment());
        $this->assertFalse($sa->i_am_supervisor());

        $ajaxformdata = [
            'evidenceid' => 0,
            'userid' => $user->id,
            'competencyid' => $competencyid,
            'statusmode' => 'create',
            'assingmentcompetencyid' => 0,
            'name' => 'Test Evidence',
            'description' => [
                'text' => 'This is a test description.',
                'format' => FORMAT_PLAIN,
            ],
            'url' => 'https://example.com/evidence',
            'files' => 0,
        ];

        $userevidence = (object)[
            'userid' => $user->id,
            'name' => 'name',
            'description' => 'name_name',
            'descriptionformat' => 1,
            'url' => "https://testing-competency.example.com",
            'timecreated' => time(),
            'timemodified' => time(),
            'usermodified' => $user->id,
        ];
        $userevidence->id = $DB->insert_record('competency_userevidence', $userevidence);

        $assignmentcompetency = (object)[
            'name' => 'Testing test',
            'assignmentid' => $newassignment->id,
            'userid' => $user->id,
            'evidenceid' => $userevidence->id,
            'competencyid' => $competencyid,
            'competencyevidenceid' => $userevidence->id,
            'files' => 12,
            'description' => [
                    'text' => 'testing',
                    'format' => '1',
            ],
            'status' => 1,
            'statusmode' => 'view',
            'url' => "https://testing-competency.example.com",
            'timecreated' => time(),
            'timemodified' => time(),
        ];
        $assignmentcompetency->id = $DB->insert_record('local_taskflow_assignment_competency', $assignmentcompetency);

        $assignmentcompetency->assingmentcompetencyid = $assignmentcompetency->id;
        $customdata = [
                'fileareaoptions' => [],
                'evidenceid' => $userevidence->id,
                'assingmentcompetencyid' => $assignmentcompetency->id,
                'statusmode' => 1,
                'setstatus' => 'view',
        ];
        $form = new \local_taskflow\form\userevidence(
            null,
            $customdata,
            'post',
            '',
            [],
            true,
            $ajaxformdata,
            true
        );
        $form->set_data_for_dynamic_submission();
        $this->assertEmpty($form->validation([], []));
        $form->process_set_status((object)$customdata);

        $form = $this->getMockBuilder(\local_taskflow\form\userevidence::class)
            ->setConstructorArgs([null, $customdata, 'post', '', [], true, $ajaxformdata, true])
            ->onlyMethods(['is_submitted', 'is_validated', 'get_data'])
            ->getMock();

        // Pretend the form was submitted and validated.
        $form->method('is_submitted')->willReturn(true);
        $form->method('is_validated')->willReturn(true);

        // Return fake form data.
        $form->method('get_data')->willReturn((object)$assignmentcompetency);
        $this->assertNotEmpty($form->process_dynamic_submission());

        $bookingoption = $this->setup_booking_options_and_answers($user, $competencyid);
        $competencyprocess = new \local_taskflow\local\completion_process\types\competency(
            $competencyid,
            $user->id,
            'competency'
        );
        $competencyprocess->is_completed($newassignment);
    }

    /**
     * Example test: Ensure external data is loaded.
     * @param stdClass $user
     * @param string $competencyid
     * @return stdClass
     */
    public function setup_booking_options_and_answers($user, $competencyid): stdClass {
        global $DB;

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $teacher = $this->getDataGenerator()->create_user();
        $bookingmanager = $this->getDataGenerator()->create_user();

        $bdata['course'] = $course->id;
        $bdata['bookingmanager'] = $bookingmanager->username;

        $booking1 = $this->getDataGenerator()->create_module('booking', $bdata);

        $this->setAdminUser();

        $this->getDataGenerator()->enrol_user($user->id, $course->id);
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id);
        $this->getDataGenerator()->enrol_user($bookingmanager->id, $course->id);

        $record = new stdClass();
        $record->bookingid = $booking1->id;
        $record->text = 'Test option1';
        $record->courseid = $course->id;
        $record->maxanswers = 2;
        $record->competencies = ['23', $competencyid];
        $record->optionid = 0;
        $record->enrolmentstatus = 0;
        $record->confirmationtrainerenabled = 0;
        $record->skipbookingrules = 0;
        $record->confirmationsupervisorenabled = 0;
        $record->skipbookingrulesmode = 0;

        /** @var \mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $option1 = $plugingenerator->create_option($record);

        $settings = singleton_service::get_instance_of_booking_option_settings($option1->id);
        // To avoid retrieving the singleton with the wrong settings, we destroy it.
        singleton_service::destroy_booking_singleton_by_cmid($settings->cmid);

        // Book the first user without any problem.
        $boinfo = new bo_info($settings);
        $finished = strtotime('-2 year');
        $this->save_booking_answers_for_user($option1, $user, $finished);
        return $option1;
    }

    /**
     * Example test: Ensure external data is loaded.
     * @param stdClass $option
     * @param stdClass $student
     * @param string $finished
     */
    public function save_booking_answers_for_user($option, $student, $finished): void {
        global $DB;

        $record = [
            'bookingid' => $option->bookingid,
            'userid' => $student->id,
            'optionid' => $option->id,
            'timemodified' => $finished,
            'completed' => 1,
            'waitinglist' => 0,
            'timecreated' => $finished,
            'status' => 0,
        ];

        $DB->insert_record(
            'booking_answers',
            (object) $record
        );
        booking_option::purge_cache_for_option($option->id);
    }
}

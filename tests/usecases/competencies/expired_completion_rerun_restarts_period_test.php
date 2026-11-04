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
use local_taskflow\event\assignment_completed;
use local_taskflow\event\rule_created_updated;
use local_taskflow\local\assignment_process\assignments\assignments_controller;
use local_taskflow\local\assignment_status\assignment_status_facade;
use local_taskflow\local\changemanager\changemanager;
use local_taskflow\local\external_adapter\external_api_repository;
use local_taskflow\local\history\history;
use local_taskflow\local\rules\rules;
use local_taskflow\local\rules\unit_rules;
use mod_booking\singleton_service;
use mod_booking_generator;
use stdClass;
use tool_mocktesttime\time_mock;

/**
 * A user who already holds the competencies when the rule first runs for them gets a completed assignment
 * right away. When those completions expire, the assignment must start a new period at the expiry with a
 * fresh due date, also when the rule is only processed again long after the expiry, and also when the
 * assignment already became overdue because of that. Re-runs of a completed assignment must not fire the
 * completion again nor move the scheduled reopening.
 *
 * @package taskflowadapter_ksw
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class expired_completion_rerun_restarts_period_test extends advanced_testcase {
    /** @var int Duration of the rule: 90 days. */
    private const DURATION = 90 * DAYSECS;

    /** @var int Cyclic duration of the rule: 365 days. */
    private const CYCLICDURATION = 365 * DAYSECS;

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
     */
    protected function tearDown(): void {
        parent::tearDown();
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('local_taskflow');
        $plugingenerator->teardown();
    }

    /**
     * Betty completed the option 250 days ago. The rule runs for her through the regular processing (no
     * migration), so the assignment is completed at once. Re-runs change nothing. When the completion has
     * expired and the rule runs again, the assignment is assigned again with a period that starts at the expiry.
     *
     * @covers \local_taskflow\local\assignment_process\assignments\assignments_controller::construct_and_process_assignment
     * @covers \local_taskflow\local\completion_process\completion_operator::get_valid_until
     * @covers \local_taskflow\local\completion_process\types\competency::get_valid_until
     * @covers \local_taskflow\local\assignments\assignments_facade::reopen_assignment
     *
     * @param array $bdata
     *
     * @dataProvider booking_common_settings_provider
     */
    public function test_expired_completion_restarts_period_on_rerun(array $bdata): void {
        global $DB;

        $completiontime = strtotime('-250 days', time());
        $expiry = $completiontime + self::CYCLICDURATION;
        [$betty, $ruleid, $assignment] = $this->setup_completed_assignment($bdata, $completiontime);
        $creationtime = time();

        $this->assertSame(assignment_status_facade::get_status_identifier('completed'), (int)$assignment->status);
        $this->assertSame($creationtime, (int)$assignment->periodstart);
        $this->assertSame(
            [$expiry],
            $this->reset_task_times($assignment->id),
            'The reopening is scheduled at the expiry of the completion, not at processing time plus cycle.'
        );

        // Three re-runs of the rule while the completion is valid.
        $this->enable_logstore();
        foreach ([1, 2, 3] as $days) {
            time_mock::set_mock_time($creationtime + $days * DAYSECS);
            $this->resave_rule($ruleid);
        }
        $this->assertSame(
            0,
            $DB->count_records('logstore_standard_log', ['eventname' => '\\' . assignment_completed::class]),
            'A re-run of a completed assignment must not fire the completion again.'
        );

        $assignment = $DB->get_record('local_taskflow_assignment', ['id' => $assignment->id], '*', MUST_EXIST);
        $this->assertSame(assignment_status_facade::get_status_identifier('completed'), (int)$assignment->status);
        $this->assertSame($creationtime, (int)$assignment->periodstart);
        $this->assertSame([$expiry], $this->reset_task_times($assignment->id), 'Re-runs must not move the reopening.');

        // A reminder of the old period was sent; the restart must forget it.
        $DB->insert_record('local_taskflow_sent_messages', (object)[
            'userid' => $betty->id,
            'ruleid' => $ruleid,
            'messageid' => 999999,
            'timesent' => time(),
        ]);

        // The reopening never runs: simulate the task parked far in the future by older code.
        $this->park_reset_task($expiry + 500 * DAYSECS);

        // The rule runs again one day after the expiry.
        time_mock::set_mock_time($expiry + DAYSECS);
        $this->resave_rule($ruleid);

        $assignment = $DB->get_record('local_taskflow_assignment', ['id' => $assignment->id], '*', MUST_EXIST);
        $this->assertSame(assignment_status_facade::get_status_identifier('assigned'), (int)$assignment->status);
        $this->assertSame($expiry, (int)$assignment->periodstart, 'The new period starts at the expiry.');
        $this->assertSame($expiry + self::DURATION, (int)$assignment->duedate, 'The due date is expiry plus duration.');
        $this->assertSame(0, (int)$assignment->overduecounter);
        $this->assertSame(0, (int)$assignment->prolongedcounter);
        $this->assertSame([], $this->reset_task_times($assignment->id), 'An open assignment has no pending reopening.');
        $this->assertContains(
            $expiry + self::DURATION,
            $this->check_task_times($assignment->id),
            'The due date check is scheduled at the new due date.'
        );
        $this->assertSame(
            0,
            $DB->count_records('local_taskflow_sent_messages', ['userid' => $betty->id, 'messageid' => 999999]),
            'Reminders of the old period are forgotten so the new period gets its own.'
        );
        $types = array_column(
            $DB->get_records('local_taskflow_history', ['assignmentid' => $assignment->id], 'id ASC', 'id, type, data'),
            'type'
        );
        $this->assertContains(history::TYPE_COMPETENCY_UNCOMPLETED, $types);
        $this->assertSame(
            1,
            $DB->count_records_select(
                'local_taskflow_history',
                'assignmentid = :assignmentid AND ' . $DB->sql_like('data', ':needle'),
                ['assignmentid' => $assignment->id, 'needle' => '%Cyclic period restarted%']
            )
        );

        // A further re-run changes nothing.
        time_mock::set_mock_time($expiry + 2 * DAYSECS);
        $this->resave_rule($ruleid);
        $again = $DB->get_record('local_taskflow_assignment', ['id' => $assignment->id], '*', MUST_EXIST);
        $this->assertSame((int)$assignment->status, (int)$again->status);
        $this->assertSame((int)$assignment->periodstart, (int)$again->periodstart);
        $this->assertSame((int)$assignment->duedate, (int)$again->duedate);
        $this->assertSame([], $this->reset_task_times($assignment->id));
    }

    /**
     * The live case: older code already turned the expired assignment overdue against the old due date.
     * The next run repairs it: new period at the expiry, status assigned because the new due date lies ahead.
     *
     * @covers \local_taskflow\local\assignment_process\assignments\assignments_controller::construct_and_process_assignment
     *
     * @param array $bdata
     *
     * @dataProvider booking_common_settings_provider
     */
    public function test_assignment_turned_overdue_after_expiry_is_repaired(array $bdata): void {
        global $DB;

        $completiontime = strtotime('-250 days', time());
        $expiry = $completiontime + self::CYCLICDURATION;
        [$betty, $ruleid, $assignment] = $this->setup_completed_assignment($bdata, $completiontime);

        // What the old code left behind: a reopening parked far in the future, the assignment overdue,
        // the period still the creation time and the due date long past.
        $this->park_reset_task($expiry + 500 * DAYSECS);
        time_mock::set_mock_time($expiry + 30 * DAYSECS);
        $DB->update_record('local_taskflow_assignment', (object)[
            'id' => $assignment->id,
            'status' => assignment_status_facade::get_status_identifier('overdue'),
            'overduecounter' => 1,
        ]);
        $DB->insert_record('local_taskflow_sent_messages', (object)[
            'userid' => $betty->id,
            'ruleid' => $ruleid,
            'messageid' => 999999,
            'timesent' => time(),
        ]);

        $this->resave_rule($ruleid);

        $assignment = $DB->get_record('local_taskflow_assignment', ['id' => $assignment->id], '*', MUST_EXIST);
        $this->assertSame(assignment_status_facade::get_status_identifier('assigned'), (int)$assignment->status);
        $this->assertSame($expiry, (int)$assignment->periodstart);
        $this->assertSame($expiry + self::DURATION, (int)$assignment->duedate);
        $this->assertSame(0, (int)$assignment->overduecounter);
        $this->assertSame([], $this->reset_task_times($assignment->id));
        $this->assertContains($expiry + self::DURATION, $this->check_task_times($assignment->id));
        $this->assertSame(
            0,
            $DB->count_records('local_taskflow_sent_messages', ['userid' => $betty->id, 'messageid' => 999999])
        );
    }

    /**
     * When the rule runs again only after expiry plus duration, the new period is already over:
     * the assignment is overdue, but with the correct period and due date.
     *
     * @covers \local_taskflow\local\assignment_process\assignments\assignments_controller::construct_and_process_assignment
     *
     * @param array $bdata
     *
     * @dataProvider booking_common_settings_provider
     */
    public function test_rerun_after_new_period_ended_is_overdue_with_correct_dates(array $bdata): void {
        global $DB;

        $completiontime = strtotime('-250 days', time());
        $expiry = $completiontime + self::CYCLICDURATION;
        [$betty, $ruleid, $assignment] = $this->setup_completed_assignment($bdata, $completiontime);

        $this->park_reset_task($expiry + 500 * DAYSECS);
        time_mock::set_mock_time($expiry + self::DURATION + DAYSECS);
        $this->resave_rule($ruleid);

        $assignment = $DB->get_record('local_taskflow_assignment', ['id' => $assignment->id], '*', MUST_EXIST);
        $this->assertSame(assignment_status_facade::get_status_identifier('overdue'), (int)$assignment->status);
        $this->assertSame($expiry, (int)$assignment->periodstart);
        $this->assertSame($expiry + self::DURATION, (int)$assignment->duedate);
        $this->assertSame([], $this->reset_task_times($assignment->id));
    }

    /**
     * Import the users, let Betty complete the option at the given time, then insert the rule without the
     * event and process it for Betty through the controller: the regular path, not the booking migration.
     *
     * @param array $bdata
     * @param int $completiontime
     * @return array [betty, ruleid, assignment record]
     */
    private function setup_completed_assignment(array $bdata, int $completiontime): array {
        global $DB;
        [$betty, $competency1, $competency2] = $this->setup_completed_option($bdata, $completiontime);

        time_mock::set_mock_time(strtotime('now'));
        $ruleid = $this->insert_rule($competency1, $competency2);
        $controller = new assignments_controller();
        $controller->construct_and_process_assignment($betty->id, rules::instance($ruleid));

        $assignment = $DB->get_record(
            'local_taskflow_assignment',
            ['userid' => $betty->id, 'ruleid' => $ruleid],
            '*',
            MUST_EXIST
        );
        return [$betty, $ruleid, $assignment];
    }

    /**
     * Move every reopening task to the given time: what re-runs on older code did to the live assignments.
     *
     * @param int $nextruntime
     * @return void
     */
    private function park_reset_task(int $nextruntime): void {
        global $DB;
        $DB->set_field(
            'task_adhoc',
            'nextruntime',
            $nextruntime,
            ['classname' => '\local_taskflow\task\reset_cyclic_assignment']
        );
    }

    /**
     * Write events to the standard log store so the test can count them afterwards.
     *
     * @return void
     */
    private function enable_logstore(): void {
        set_config('enabled_stores', 'logstore_standard', 'tool_log');
        set_config('buffersize', 0, 'logstore_standard');
        set_config('logguests', 1, 'logstore_standard');
        get_log_manager(true);
    }

    /**
     * The next run times of the reopening tasks of an assignment.
     *
     * @param int $assignmentid
     * @return array
     */
    private function reset_task_times(int $assignmentid): array {
        return $this->task_times('\local_taskflow\task\reset_cyclic_assignment', $assignmentid);
    }

    /**
     * The next run times of the due date check tasks of an assignment.
     *
     * @param int $assignmentid
     * @return array
     */
    private function check_task_times(int $assignmentid): array {
        return $this->task_times('\local_taskflow\task\check_assignment_status', $assignmentid);
    }

    /**
     * The next run times of the adhoc tasks of a class that belong to an assignment.
     *
     * @param string $classname
     * @param int $assignmentid
     * @return array
     */
    private function task_times(string $classname, int $assignmentid): array {
        global $DB;
        $times = [];
        $tasks = $DB->get_records('task_adhoc', ['classname' => $classname]);
        foreach ($tasks as $task) {
            $customdata = json_decode($task->customdata);
            if ((int)($customdata->assignmentid ?? 0) == $assignmentid) {
                $times[] = (int)$task->nextruntime;
            }
        }
        sort($times);
        return $times;
    }

    /**
     * Import the users, create course, competencies, booking option and let Betty complete it at the given time.
     *
     * @param array $bdata
     * @param int $completiontime
     * @return array
     */
    private function setup_completed_option(array $bdata, int $completiontime): array {
        global $DB;

        time_mock::set_mock_time($completiontime);
        $this->setAdminUser();
        singleton_service::destroy_instance();
        $lock = $this->createMock(\core\lock\lock::class);
        $cronlock = $this->createMock(\core\lock\lock::class);
        /** @var \local_taskflow_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('local_taskflow');
        /** @var mod_booking_generator $bookingplugingenerator */
        $bookingplugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        $apidatamanager = external_api_repository::create($this->externaldata);
        $apidatamanager->process_incoming_data();
        $berta = $DB->get_record('user', ['email' => 'berta.boss@ksw.ch'], '*', MUST_EXIST);
        $betty = $DB->get_record('user', ['email' => 'betty.best@ksw.ch'], '*', MUST_EXIST);

        $course = $this->getDataGenerator()->create_course([
            'fullname' => 'Test Course',
            'shortname' => 'TC101',
            'category' => 1,
            'enablecompletion' => 1,
        ]);
        [$competency1, $competency2] = $this->create_competencies();

        $bdata['course'] = $course->id;
        $bdata['bookingmanager'] = $berta->username;
        $bdata['cancancelbook'] = 1;
        $booking = $this->getDataGenerator()->create_module('booking', $bdata);
        set_config('usecompetencies', 1, 'booking');
        $this->getDataGenerator()->enrol_user($berta->id, $course->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user($betty->id, $course->id, 'student');

        $record = new stdClass();
        $record->bookingid = $booking->id;
        $record->text = 'Reanimationskurs';
        $record->chooseorcreatecourse = 1;
        $record->courseid = $course->id;
        $record->description = 'Completed before the rule exists';
        $record->optiondateid_0 = "0";
        $record->daystonotify_0 = "0";
        $record->coursestarttime_0 = strtotime('- 1 day', time());
        $record->courseendtime_0 = strtotime('+ 1 day', time());
        $record->teachersforoption = 0;
        $record->competencies = [$competency1->get('id'), $competency2->get('id')];
        $option = $bookingplugingenerator->create_option($record);
        singleton_service::destroy_instance();

        $bookingplugingenerator->create_answer(['optionid' => $option->id, 'userid' => $betty->id]);
        singleton_service::destroy_instance();
        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);
        $bookingoption = singleton_service::get_instance_of_booking_option($settings->cmid, $settings->id);
        $bookingoption->toggle_user_completion($betty->id);
        $plugingenerator->runtaskswithintime($cronlock, $lock, time());
        singleton_service::destroy_instance();

        return [$betty, $competency1, $competency2];
    }

    /**
     * Insert the rule without triggering the event.
     *
     * @param competency $competency1
     * @param competency $competency2
     * @return int
     */
    private function insert_rule(competency $competency1, competency $competency2): int {
        global $DB;
        $messageids = $this->set_messages_db();
        $cohorts = $DB->get_records('cohort', [], 'id ASC');
        $cohort = array_shift($cohorts);
        $rule = $this->get_rule($cohort->id, $competency1->get('id'), $competency2->get('id'), $messageids);
        return $DB->insert_record('local_taskflow_rules', $rule);
    }

    /**
     * Save the rule again the way the form does it.
     *
     * @param int $ruleid
     * @return void
     */
    private function resave_rule(int $ruleid): void {
        global $DB;
        $dbrule = $DB->get_record('local_taskflow_rules', ['id' => $ruleid], '*', MUST_EXIST);
        $ruleobj = json_decode($dbrule->rulejson);
        $ruleobj->rulejson->rule->timemodified = time();
        $dbrule->rulejson = json_encode($ruleobj);

        $changemanager = new changemanager($ruleid, (array)$dbrule);
        $changedata = $changemanager->get_change_management_data();

        $DB->update_record('local_taskflow_rules', $dbrule);
        rules::reset_instances();
        unit_rules::reset_instances();
        $ruledata = (array)$dbrule;
        $ruledata['changemanagement'] = $changedata;
        $event = rule_created_updated::create([
            'objectid' => $ruledata['id'],
            'context'  => context_system::instance(),
            'other'    => [
                'ruledata' => $ruledata,
            ],
        ]);
        $event->trigger();
        singleton_service::destroy_instance();

        // The event only queues the rule update, the processing happens in the adhoc task.
        $lock = $this->createMock(\core\lock\lock::class);
        $cronlock = $this->createMock(\core\lock\lock::class);
        /** @var \local_taskflow_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('local_taskflow');
        $plugingenerator->runtaskswithintime($cronlock, $lock, time());
        singleton_service::destroy_instance();
    }

    /**
     * Create two competencies used as rule targets.
     *
     * @return array
     */
    private function create_competencies(): array {
        $scale = $this->getDataGenerator()->create_scale([
            'scale' => 'Not proficient,Proficient',
            'name' => 'Test Competency Scale',
        ]);
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
        $competencies = [];
        foreach (['testcompetency', 'testcompetency2'] as $shortname) {
            $competency = new competency(0, (object)[
                'shortname' => $shortname,
                'idnumber' => $shortname,
                'competencyframeworkid' => $framework->get('id'),
                'scaleid' => null,
                'description' => 'A ' . $shortname,
                'id' => 0,
                'scaleconfiguration' => null,
                'parentid' => 0,
            ]);
            $competency->set('sortorder', 0);
            $competency->create();
            $competencies[] = $competency;
        }
        return $competencies;
    }

    /**
     * Build the rule record: 90 days duration, 365 days cyclic duration, recursive on.
     *
     * @param int $unitid
     * @param int $target1id
     * @param int $target2id
     * @param array $messageids
     * @return array
     */
    private function get_rule(int $unitid, int $target1id, int $target2id, array $messageids): array {
        return [
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
                        "cyclicduration" => self::CYCLICDURATION,
                        "fixeddate" => 23233232222,
                        "duration" => self::DURATION,
                        "timemodified" => time(),
                        "timecreated" => time(),
                        "usermodified" => 1,
                        "recursive" => 1,
                        "inheritance" => 1,
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
    }

    /**
     * Insert the mock messages.
     *
     * @return array
     */
    private function set_messages_db(): array {
        global $DB;
        $messageids = [];
        $messages = json_decode(file_get_contents(__DIR__ . '/../../mock/messages/assignedandwarningsandfailed_messages.json'));
        foreach ($messages as $message) {
            $messageids[] = (object)['messageid' => $DB->insert_record('local_taskflow_messages', $message)];
        }
        return $messageids;
    }

    /**
     * Data provider for the booking instance settings.
     *
     * @return array
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

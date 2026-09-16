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
use local_taskflow\event\rule_created_updated;
use local_taskflow\local\assignment_status\assignment_status_facade;
use local_taskflow\local\changemanager\changemanager;
use local_taskflow\local\external_adapter\external_api_repository;
use local_taskflow\local\rules\rules;
use local_taskflow\local\rules\unit_rules;
use mod_booking\singleton_service;
use mod_booking_generator;
use stdClass;
use tool_mocktesttime\time_mock;

/**
 * The due date of an assignment is calculated from the start of its current period (periodstart).
 * Saving a rule again with "recursive" (status changes also affect existing assignments) or with
 * a changed cyclic duration must not change the due date. A cyclic reopening starts a new period.
 *
 * @package taskflowadapter_ksw
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class periodstart_duedate_consistency_test extends advanced_testcase {
    /** @var int Duration of the rule: 90 days. */
    private const DURATION = 90 * DAYSECS;

    /** @var int Cyclic duration of the rule: 365 days. */
    private const CYCLICDURATION = 365 * DAYSECS;

    /** @var int Cyclic duration after the rule update: 200 days. */
    private const CYCLICDURATIONNEW = 200 * DAYSECS;

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
     * Betty completed the option long ago, the cycle has expired when the rule is created.
     * The assignment is reopened at rule creation, so the due date is rule creation plus duration,
     * and it stays the same when the rule is saved again, with or without a changed cyclic duration.
     *
     * @covers \local_taskflow\local\assignment_process\assignments\assignments_controller::set_due_date
     * @covers \local_taskflow\local\assignments\assignments_facade::reopen_assignment
     *
     * @param array $bdata
     *
     * @dataProvider booking_common_settings_provider
     */
    public function test_expired_completion_duedate_survives_rule_updates(array $bdata): void {
        global $DB;

        $lock = $this->createMock(\core\lock\lock::class);
        $cronlock = $this->createMock(\core\lock\lock::class);
        /** @var \local_taskflow_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('local_taskflow');

        // Completed 400 days ago, the cycle of 365 days has expired.
        $completiontime = strtotime('- 400 days', strtotime('now'));
        [$betty, $competency1, $competency2] = $this->setup_completed_option($bdata, $completiontime);

        time_mock::set_mock_time(strtotime('now'));
        $rulecreationtime = time();
        $ruleid = $this->create_rule($betty, $competency1, $competency2, self::CYCLICDURATION);
        $plugingenerator->runtaskswithintime($cronlock, $lock, time());

        $assignment = $DB->get_record('local_taskflow_assignment', ['userid' => $betty->id, 'ruleid' => $ruleid], '*', MUST_EXIST);
        $this->assertSame(assignment_status_facade::get_status_identifier('assigned'), (int)$assignment->status);
        $this->assertSame($rulecreationtime, (int)$assignment->periodstart, 'Reopening starts the period at rule creation.');
        $this->assertSame($rulecreationtime + self::DURATION, (int)$assignment->duedate);
        $this->assertSame($completiontime, (int)$assignment->assigneddate, 'The migrated assigned date is kept.');
        $this->assertSame($completiontime, (int)$assignment->completeddate);
        $expectedduedate = (int)$assignment->duedate;

        // Save the rule again, unchanged.
        time_mock::set_mock_time(strtotime('+ 1 day', time()));
        $this->resave_rule($ruleid, self::CYCLICDURATION);
        $plugingenerator->runtaskswithintime($cronlock, $lock, time());
        $assignment = $DB->get_record('local_taskflow_assignment', ['id' => $assignment->id], '*', MUST_EXIST);
        $this->assertSame($expectedduedate, (int)$assignment->duedate, 'Saving the rule again must not change the due date.');
        $this->assertSame($rulecreationtime, (int)$assignment->periodstart);

        // Save the rule again with a different cyclic duration.
        time_mock::set_mock_time(strtotime('+ 1 day', time()));
        $this->resave_rule($ruleid, self::CYCLICDURATIONNEW);
        $plugingenerator->runtaskswithintime($cronlock, $lock, time());
        $assignment = $DB->get_record('local_taskflow_assignment', ['id' => $assignment->id], '*', MUST_EXIST);
        $this->assertSame($expectedduedate, (int)$assignment->duedate, 'A changed cyclic duration must not change the due date.');
        $this->assertSame(assignment_status_facade::get_status_identifier('assigned'), (int)$assignment->status);
    }

    /**
     * Betty completed the option 100 days ago, the cycle is still running when the rule is created.
     * The assignment is completed and the reopening is scheduled at completion plus cyclic duration.
     * Changing the cyclic duration reschedules the reopening. The reopening starts a new period,
     * so the due date is reopening time plus duration.
     *
     * @covers \local_taskflow\local\completion_process\scheduling_cyclic_adhoc::reschedule_reset
     * @covers \local_taskflow\task\reset_cyclic_assignment
     *
     * @param array $bdata
     *
     * @dataProvider booking_common_settings_provider
     */
    public function test_running_completion_reopening_starts_new_period(array $bdata): void {
        global $DB;

        $lock = $this->createMock(\core\lock\lock::class);
        $cronlock = $this->createMock(\core\lock\lock::class);
        /** @var \local_taskflow_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('local_taskflow');

        $completiontime = strtotime('- 100 days', strtotime('now'));
        [$betty, $competency1, $competency2] = $this->setup_completed_option($bdata, $completiontime);

        time_mock::set_mock_time(strtotime('now'));
        $ruleid = $this->create_rule($betty, $competency1, $competency2, self::CYCLICDURATION);
        $plugingenerator->runtaskswithintime($cronlock, $lock, time());

        $assignment = $DB->get_record('local_taskflow_assignment', ['userid' => $betty->id, 'ruleid' => $ruleid], '*', MUST_EXIST);
        $this->assertSame(assignment_status_facade::get_status_identifier('completed'), (int)$assignment->status);
        $this->assertSame($completiontime, (int)$assignment->completeddate);
        $this->assertSame([$completiontime + self::CYCLICDURATION], $this->reset_task_times($assignment->id));

        // Changing the cyclic duration moves the reopening, nothing else.
        time_mock::set_mock_time(strtotime('+ 1 day', time()));
        $this->resave_rule($ruleid, self::CYCLICDURATIONNEW);
        $plugingenerator->runtaskswithintime($cronlock, $lock, time());
        $assignment = $DB->get_record('local_taskflow_assignment', ['id' => $assignment->id], '*', MUST_EXIST);
        $this->assertSame(assignment_status_facade::get_status_identifier('completed'), (int)$assignment->status);
        $this->assertSame([$completiontime + self::CYCLICDURATIONNEW], $this->reset_task_times($assignment->id));

        // The reopening takes place: a new period starts.
        time_mock::set_mock_time($completiontime + self::CYCLICDURATIONNEW + HOURSECS);
        $reopeningtime = time();
        $plugingenerator->runtaskswithintime($cronlock, $lock, time());
        $assignment = $DB->get_record('local_taskflow_assignment', ['id' => $assignment->id], '*', MUST_EXIST);
        $this->assertSame(assignment_status_facade::get_status_identifier('assigned'), (int)$assignment->status);
        $this->assertSame($reopeningtime, (int)$assignment->periodstart);
        $this->assertSame($reopeningtime + self::DURATION, (int)$assignment->duedate);
        $this->assertSame(0, (int)$assignment->overduecounter);
        $this->assertSame([], $this->reset_task_times($assignment->id));

        // Saving the rule again after the reopening keeps the due date.
        time_mock::set_mock_time(strtotime('+ 1 day', time()));
        $this->resave_rule($ruleid, self::CYCLICDURATIONNEW);
        $plugingenerator->runtaskswithintime($cronlock, $lock, time());
        $assignment = $DB->get_record('local_taskflow_assignment', ['id' => $assignment->id], '*', MUST_EXIST);
        $this->assertSame($reopeningtime + self::DURATION, (int)$assignment->duedate);
        $this->assertSame($reopeningtime, (int)$assignment->periodstart);
    }

    /**
     * Next run times of the cyclic reset tasks of an assignment.
     *
     * @param int $assignmentid
     * @return array
     */
    private function reset_task_times(int $assignmentid): array {
        global $DB;
        $times = [];
        $tasks = $DB->get_records('task_adhoc', ['classname' => '\local_taskflow\task\reset_cyclic_assignment']);
        foreach ($tasks as $task) {
            $customdata = json_decode($task->customdata);
            if ((int)$customdata->assignmentid == $assignmentid) {
                $times[] = (int)$task->nextruntime;
            }
        }
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
     * Insert the rule and trigger the event.
     *
     * @param stdClass $betty
     * @param competency $competency1
     * @param competency $competency2
     * @param int $cyclicduration
     * @return int
     */
    private function create_rule(stdClass $betty, competency $competency1, competency $competency2, int $cyclicduration): int {
        global $DB;
        $messageids = $this->set_messages_db();
        $cohorts = $DB->get_records('cohort', [], 'id ASC');
        $cohort = array_shift($cohorts);
        $rule = $this->get_rule($cohort->id, $competency1->get('id'), $competency2->get('id'), $messageids, $cyclicduration);
        $ruleid = $DB->insert_record('local_taskflow_rules', $rule);
        $rule['id'] = $ruleid;
        $this->trigger_rule_event($rule);
        return $ruleid;
    }

    /**
     * Save the rule again the way the form does it.
     *
     * @param int $ruleid
     * @param int $cyclicduration
     * @return void
     */
    private function resave_rule(int $ruleid, int $cyclicduration): void {
        global $DB;
        $dbrule = $DB->get_record('local_taskflow_rules', ['id' => $ruleid], '*', MUST_EXIST);
        $ruleobj = json_decode($dbrule->rulejson);
        $ruleobj->rulejson->rule->cyclicduration = $cyclicduration;
        $ruleobj->rulejson->rule->timemodified = time();
        $dbrule->rulejson = json_encode($ruleobj);

        $changemanager = new changemanager($ruleid, (array)$dbrule);
        $changedata = $changemanager->get_change_management_data();

        $DB->update_record('local_taskflow_rules', $dbrule);
        rules::reset_instances();
        unit_rules::reset_instances();
        $ruledata = (array)$dbrule;
        $ruledata['changemanagement'] = $changedata;
        $this->trigger_rule_event($ruledata);
    }

    /**
     * Trigger the rule created/updated event.
     *
     * @param array $ruledata
     * @return void
     */
    private function trigger_rule_event(array $ruledata): void {
        $event = rule_created_updated::create([
            'objectid' => $ruledata['id'],
            'context'  => context_system::instance(),
            'other'    => [
                'ruledata' => $ruledata,
            ],
        ]);
        $event->trigger();
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
     * Build the rule record: 90 days duration, cyclic duration as given, recursive on.
     *
     * @param int $unitid
     * @param int $target1id
     * @param int $target2id
     * @param array $messageids
     * @param int $cyclicduration
     * @return array
     */
    private function get_rule(int $unitid, int $target1id, int $target2id, array $messageids, int $cyclicduration): array {
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
                        "cyclicduration" => $cyclicduration,
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

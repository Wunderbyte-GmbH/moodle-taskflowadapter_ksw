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
 * A user completed a booking option on 17.07.2025, long before the rule exists.
 * Then a cyclic rule (365 days) with a duration of 90 days and "recursive"
 * (status changes also affect existing assignments) is created and saved again.
 * This test makes no assertions, it only prints the due date of the assignment.
 *
 * @package taskflowadapter_ksw
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class completed_before_recursive_rule_update_duedate_test extends advanced_testcase {
    /** @var int Duration of the rule: 90 days. */
    private const DURATION = 90 * DAYSECS;

    /** @var int Cyclic duration of the rule: 365 days. */
    private const CYCLICDURATION = 365 * DAYSECS;

    /** @var string The day the user completed the booking option. */
    private const COMPLETIONDATE = '2025-07-17 10:00:00';

    /** @var string|null Stores the external user data. */
    protected ?string $externaldata = null;

    /**
     * Setup the test environment.
     */
    protected function setUp(): void {
        parent::setUp();
        time_mock::init();
        time_mock::set_mock_time(strtotime(self::COMPLETIONDATE));
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
     * Betty completes the booking option on 17.07.2025. Today, more than 365 days later, the rule is created
     * and then saved again. The rule has to be created at the real current time, because the adhoc task
     * queued by the rule event gets its next run time from the real clock.
     * Prints the due date of Betty's assignment after each step.
     *
     * @covers \local_taskflow\local\assignment_process\booking_migration
     * @covers \local_taskflow\local\assignment_process\assignments\assignments_controller
     *
     * @param array $bdata
     *
     * @dataProvider booking_common_settings_provider
     */
    public function test_duedate_after_recursive_rule_update(array $bdata): void {
        global $DB;

        $this->setAdminUser();
        singleton_service::destroy_instance();
        $lock = $this->createMock(\core\lock\lock::class);
        $cronlock = $this->createMock(\core\lock\lock::class);
        /** @var \local_taskflow_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('local_taskflow');
        /** @var mod_booking_generator $bookingplugingenerator */
        $bookingplugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        // Import Berta, Betty and Chris.
        $apidatamanager = external_api_repository::create($this->externaldata);
        $apidatamanager->process_incoming_data();
        $berta = $DB->get_record('user', ['email' => 'berta.boss@ksw.ch'], '*', MUST_EXIST);
        $betty = $DB->get_record('user', ['email' => 'betty.best@ksw.ch'], '*', MUST_EXIST);

        // Course, competencies, booking instance and booking option.
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
        $record->description = 'Completed long before the rule exists';
        $record->optiondateid_0 = "0";
        $record->daystonotify_0 = "0";
        $record->coursestarttime_0 = strtotime('- 1 day', time());
        $record->courseendtime_0 = strtotime('+ 1 day', time());
        $record->teachersforoption = 0;
        $record->competencies = [$competency1->get('id'), $competency2->get('id')];
        $option = $bookingplugingenerator->create_option($record);
        singleton_service::destroy_instance();

        // Betty books and completes the option on 17.07.2025.
        $bookingplugingenerator->create_answer(['optionid' => $option->id, 'userid' => $betty->id]);
        singleton_service::destroy_instance();
        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);
        $bookingoption = singleton_service::get_instance_of_booking_option($settings->cmid, $settings->id);
        $bookingoption->toggle_user_completion($betty->id);
        $plugingenerator->runtaskswithintime($cronlock, $lock, time());
        singleton_service::destroy_instance();

        $this->print_line('Betty completed the option on', $this->human(time()));

        // Today the rule is created: 90 days duration, cyclic 365 days, recursive on.
        time_mock::set_mock_time(strtotime('now'));
        $this->print_line('Rule is created on', $this->human(time()));

        $messageids = $this->set_messages_db();
        $cohorts = $DB->get_records('cohort', [], 'id ASC');
        $cohort = array_shift($cohorts);
        $rule = $this->get_rule($cohort->id, $competency1->get('id'), $competency2->get('id'), $messageids);
        $ruleid = $DB->insert_record('local_taskflow_rules', $rule);
        $rule['id'] = $ruleid;

        $this->trigger_rule_event($rule);
        $plugingenerator->runtaskswithintime($cronlock, $lock, time());
        $plugingenerator->runtaskswithintime($cronlock, $lock, time());

        $this->print_assignment('After rule creation', $betty->id, $ruleid);

        // Now the very same rule is saved again.
        time_mock::set_mock_time(strtotime('+ 15 minutes', time()));
        $this->print_line('Rule is updated on', $this->human(time()));

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
        $this->trigger_rule_event($ruledata);
        $plugingenerator->runtaskswithintime($cronlock, $lock, time());

        $this->print_assignment('After rule update', $betty->id, $ruleid);
    }

    /**
     * Print the due date (and some context) of the assignment of a user for a rule.
     *
     * @param string $step
     * @param int $userid
     * @param int $ruleid
     * @return void
     */
    private function print_assignment(string $step, int $userid, int $ruleid): void {
        global $DB;
        $assignment = $DB->get_record('local_taskflow_assignment', ['userid' => $userid, 'ruleid' => $ruleid]);
        if (!$assignment) {
            $this->print_line($step, 'no assignment found');
            foreach ($DB->get_records('task_adhoc', [], 'id ASC') as $task) {
                $this->print_line(
                    $step . ' - adhoc task',
                    $task->classname . ' next run ' . $this->human((int)$task->nextruntime)
                );
            }
            return;
        }
        $status = assignment_status_facade::get_all()[(int)$assignment->status]['name'] ?? $assignment->status;
        $this->print_line($step . ' - duedate', $this->human((int)$assignment->duedate));
        $this->print_line($step . ' - assigneddate', $this->human((int)$assignment->assigneddate));
        $this->print_line($step . ' - completeddate', $this->human((int)($assignment->completeddate ?? 0)));
        $this->print_line($step . ' - status', (string)$status);
        foreach ($DB->get_records('task_adhoc', [], 'id ASC') as $task) {
            $this->print_line(
                $step . ' - adhoc task',
                $task->classname . ' next run ' . $this->human((int)$task->nextruntime)
            );
        }
    }

    /**
     * Format a timestamp as human readable date.
     *
     * @param int $timestamp
     * @return string
     */
    private function human(int $timestamp): string {
        if (empty($timestamp)) {
            return '-';
        }
        return userdate($timestamp, get_string('strftimedatetime', 'langconfig')) . ' (' . $timestamp . ')';
    }

    /**
     * Print one line to the test output.
     *
     * @param string $label
     * @param string $value
     * @return void
     */
    private function print_line(string $label, string $value): void {
        fwrite(STDOUT, PHP_EOL . str_pad($label . ':', 45) . $value);
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
     * Build the rule record: 90 days duration, cyclic 365 days, recursive on.
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

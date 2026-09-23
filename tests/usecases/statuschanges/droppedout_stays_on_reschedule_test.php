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

namespace taskflowadapter_ksw\usecases\statuschanges;

use advanced_testcase;
use context_system;
use local_taskflow\event\rule_created_updated;
use local_taskflow\local\assignment_status\assignment_status_facade;
use local_taskflow\task\reschedule_rules;
use mod_booking\singleton_service;
use taskflowadapter_standard\form\editassignment;
use tool_mocktesttime\time_mock;

/**
 * Usecase: a manually dropped out assignment must survive the reschedule of a rule.
 *
 * Setup:
 * - A booking option linked to a competency was completed by the user long ago (older than the cyclic duration).
 * - The rule targets only that competency, has cyclic validation, the "recursive" flag
 *   ("Statusänderungen effektieren auch bestehende Zuweisungen") is NOT set, and filters with nowminusdays.
 * - An admin sets the assignment to "dropped out" via the edit assignment form.
 * - The reschedule_rules task (re-evaluating the nowminusdays filter) runs again.
 *
 * Expectation: the status stays "dropped out".
 *
 * @package taskflowadapter_ksw
 * @category test
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class droppedout_stays_on_reschedule_test extends advanced_testcase {
    /** @var int One year, used as cyclic duration. */
    private const CYCLICDURATION = 31536000;

    /** @var int Value of the nowminusdays filter. */
    private const FILTERDAYS = 30;

    /**
     * Tests set up.
     */
    public function setUp(): void {
        parent::setUp();
        time_mock::init();
        time_mock::set_mock_time(strtotime('now'));
        $this->resetAfterTest();

        $plugingenerator = self::getDataGenerator()->get_plugin_generator('local_taskflow');
        $plugingenerator->create_custom_profile_fields([
            'supervisor',
            'units',
            'entrydate',
        ]);
        $plugingenerator->set_config_values('ksw');
        $this->preventResetByRollback();
    }

    /**
     * Mandatory clean-up after each test.
     */
    public function tearDown(): void {
        parent::tearDown();
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('local_taskflow');
        $plugingenerator->teardown();
    }

    /**
     * A manually dropped out assignment keeps its status when the nowminusdays rule is rescheduled.
     *
     * @covers \local_taskflow\task\reschedule_rules
     * @covers \local_taskflow\local\assignment_process\assignment_controller
     * @return void
     */
    public function test_droppedout_status_stays_after_reschedule(): void {
        global $DB;

        $this->setAdminUser();
        $now = time();

        /** @var \local_taskflow_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('local_taskflow');
        $lock = $this->createMock(\core\lock\lock::class);
        $cronlock = $this->createMock(\core\lock\lock::class);

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $bookingmanager = $this->getDataGenerator()->create_user();
        $user = $this->getDataGenerator()->create_user();
        // Second user: does not match the filter yet, but will after one day. Proves the task really ran.
        $lateuser = $this->getDataGenerator()->create_user();

        $entrydatefieldid = $DB->get_field('user_info_field', 'id', ['shortname' => 'entrydate'], MUST_EXIST);
        $this->set_profile_field($user->id, $entrydatefieldid, strtotime('-40 days', $now));
        $this->set_profile_field($lateuser->id, $entrydatefieldid, strtotime('-30 days +12 hours', $now));

        $cohort = $this->getDataGenerator()->create_cohort([
            'name' => 'Test Cohort',
            'idnumber' => 'cohort123',
            'contextid' => context_system::instance()->id,
        ]);
        cohort_add_member($cohort->id, $user->id);
        cohort_add_member($cohort->id, $lateuser->id);

        [$competency] = $plugingenerator->create_competencies($this, 1);

        $this->getDataGenerator()->enrol_user($bookingmanager->id, $course->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');

        // The booking option and its completion lie two years in the past, i.e. further back than the cyclic duration.
        $completiontime = strtotime('-2 years', $now);
        time_mock::set_mock_time($completiontime);

        [$option] = $plugingenerator->create_booking_options(
            $this,
            $course->id,
            $bookingmanager,
            1,
            [],
            [
                'text' => 'Old completed option',
                'competencies' => [$competency->get('id')],
                'coursestarttime_0' => strtotime('-10 days', $completiontime),
                'courseendtime_0' => strtotime('-5 days', $completiontime),
            ]
        );

        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);
        $bookingoption = singleton_service::get_instance_of_booking_option($settings->cmid, $settings->id);
        $bookingoption->user_submit_response($user, 0, 0, 0, true);
        $bookingoption->toggle_user_completion($user->id);
        singleton_service::destroy_booking_answers($option->id);
        $this->runAdhocTasks();

        $answer = $DB->get_record('booking_answers', ['optionid' => $option->id, 'userid' => $user->id], '*', MUST_EXIST);
        $this->assertEquals(1, $answer->completed);
        $this->assertLessThan(
            $now - self::CYCLICDURATION,
            $answer->timemodified,
            'Completion must be older than the cyclic duration'
        );

        // Back to today.
        time_mock::set_mock_time($now);

        $rule = $this->get_rule($cohort->id, $competency->get('id'));
        $rule['id'] = $DB->insert_record('local_taskflow_rules', $rule);
        $event = rule_created_updated::create([
            'objectid' => $rule['id'],
            'context'  => context_system::instance(),
            'other'    => [
                'ruledata' => $rule,
            ],
        ]);
        $event->trigger();
        $this->runAdhocTasks();

        // Only the first user matches the filter. The old completion is expired, so the assignment is open again.
        $assignments = $DB->get_records('local_taskflow_assignment');
        $this->assertCount(1, $assignments);
        $assignment = reset($assignments);
        $this->assertEquals($user->id, $assignment->userid);
        $this->assertEquals(assignment_status_facade::get_status_identifier('assigned'), $assignment->status);

        // The admin sets the assignment to dropped out via the edit assignment form.
        $submitdata = [
            'id' => $assignment->id,
            'status' => assignment_status_facade::get_status_identifier('droppedout'),
            'change_reason' => 10,
            'comment' => 'Dropped out via form submission',
            'duedate' => [
                'day' => (int) date('j', $assignment->duedate),
                'month' => (int) date('n', $assignment->duedate),
                'year' => (int) date('Y', $assignment->duedate),
            ],
            'keepchanges' => false,
        ];
        $submitdata = editassignment::mock_ajax_submit($submitdata);
        $editassignmentform = new editassignment(
            null,
            $submitdata,
            'post',
            '',
            [],
            true,
            $submitdata,
            true
        );
        $editassignmentform->set_data_for_dynamic_submission();
        $editassignmentform->validation($submitdata, []);
        $editassignmentform->process_dynamic_submission();

        $assignment = $DB->get_record('local_taskflow_assignment', ['id' => $assignment->id], '*', MUST_EXIST);
        $this->assertEquals(assignment_status_facade::get_status_identifier('droppedout'), $assignment->status);

        // One day later the nowminusdays rules are rescheduled.
        time_mock::set_mock_time(strtotime('+1 day', $now));
        $task = new reschedule_rules();
        $task->execute();
        time_mock::set_mock_time(strtotime('+1 minute', time()));
        $plugingenerator->runtaskswithintime($cronlock, $lock, time());

        // The second user now passes the filter, so the task did its job...
        $assignments = $DB->get_records('local_taskflow_assignment', [], 'id ASC');
        $this->assertCount(2, $assignments);
        $lateassignment = $DB->get_record(
            'local_taskflow_assignment',
            ['userid' => $lateuser->id, 'ruleid' => $rule['id']],
            '*',
            MUST_EXIST
        );
        $this->assertEquals(assignment_status_facade::get_status_identifier('assigned'), $lateassignment->status);

        // ... but the manually dropped out assignment is untouched.
        $assignment = $DB->get_record('local_taskflow_assignment', ['id' => $assignment->id], '*', MUST_EXIST);
        $this->assertEquals(assignment_status_facade::get_status_identifier('droppedout'), $assignment->status);
    }

    /**
     * Writes a custom profile field value for a user.
     * @param int $userid
     * @param int $fieldid
     * @param mixed $value
     * @return void
     */
    private function set_profile_field(int $userid, int $fieldid, $value): void {
        global $DB;
        $record = (object)[
            'userid' => $userid,
            'fieldid' => $fieldid,
            'data' => $value,
            'dataformat' => FORMAT_HTML,
        ];
        $existingid = $DB->get_field('user_info_data', 'id', ['userid' => $userid, 'fieldid' => $fieldid]);
        if ($existingid) {
            $record->id = $existingid;
            $DB->update_record('user_info_data', $record);
        } else {
            $DB->insert_record('user_info_data', $record);
        }
    }

    /**
     * Rule with a single competency target, cyclic validation, recursive unticked and a nowminusdays filter.
     * @param int $unitid
     * @param int $competencyid
     * @return array
     */
    private function get_rule(int $unitid, int $competencyid): array {
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
                        // The flag "Statusänderungen effektieren auch bestehende Zuweisungen" is NOT ticked.
                        "recursive" => 0,
                        "duedatetype" => "duration",
                        "cyclicvalidation" => "1",
                        "cyclicduration" => self::CYCLICDURATION,
                        "fixeddate" => 23233232222,
                        "duration" => 2592000,
                        "timemodified" => 23233232222,
                        "timecreated" => 23233232222,
                        "usermodified" => 1,
                        "filter" => [
                            [
                                "filtertype" => "user_profile_field",
                                "userprofilefield" => "entrydate",
                                "operator" => "nowminusdays",
                                "value" => (string) self::FILTERDAYS,
                            ],
                        ],
                        "actions" => [
                            [
                                "targets" => [
                                    [
                                        "targetid" => $competencyid,
                                        "targettype" => "competency",
                                        "targetname" => "mycompetency",
                                        "sortorder" => 1,
                                        "actiontype" => "enroll",
                                        "completebeforenext" => false,
                                    ],
                                ],
                                "messages" => [],
                            ],
                        ],
                    ],
                ],
            ]),
            "isactive" => "1",
            "userid" => "0",
        ];
    }
}

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
use local_taskflow\local\external_adapter\external_api_base;
use local_taskflow\local\external_adapter\external_api_repository;
use local_taskflow\plugininfo\taskflowadapter;
use tool_mocktesttime\time_mock;
use context_system;
use local_taskflow\event\rule_created_updated;
use local_taskflow\local\assignment_status\assignment_status_facade;
use local_taskflow\local\assignments\assignments_facade;

/**
 * Suspended users are dropped out and get no new assignments.
 *
 * @package taskflowadapter_ksw
 * @category test
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class suspended_user_dropout_test extends advanced_testcase {
        /** @var string|null Stores the external user data. */
    protected ?string $externaldata = null;

    /**
     * Setup the test environment.
     */
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        time_mock::init();
        time_mock::set_mock_time(strtotime('now'));
        $this->resetAfterTest(true);
        // The user_created/updated handler is gated off in phpunit by default
        // (see local_taskflow core_user_created_updated::handle()); this test needs it.
        set_config('enableeventhandlersinphpunit', 1, 'local_taskflow');
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
     *
     */
    protected function tearDown(): void {
        parent::tearDown();
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('local_taskflow');
        $plugingenerator->teardown();
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
    protected function set_db_cohort(): mixed {
        // Create a user.
        $cohort = $this->getDataGenerator()->create_cohort([
            'name' => 'Test Cohort',
            'idnumber' => 'cohort123',
            'contextid' => context_system::instance()->id,
        ]);
        return $cohort;
    }

    /**
     * Setup the test environment.
     */
    protected function set_messages_db(): array {
        global $DB;
        $messageids = [];
        $messages = json_decode(file_get_contents(__DIR__ . '/../../mock/messages/assignedandwarningsandfailed_messages.json'));
        foreach ($messages as $message) {
            $messageids[] = (object)['messageid' => $DB->insert_record('local_taskflow_messages', $message)];
        }
        return $messageids;
    }

    /**
     * Data provider for condition_bookingpolicy_test
     *
     * @return array
     * @throws \UnexpectedValueException
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
                        "duration" => 86400 * 420, // 420 days.
                        "timemodified" => 23233232222,
                        "timecreated" => 23233232222,
                        "usermodified" => 1,
                        "recursive" => 0,
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
     * Creates a rule on the unit and runs the scheduled tasks.
     *
     * @param int $unitid
     * @param int $courseid
     * @param array $messageids
     * @return int the rule id
     */
    private function create_and_run_rule(int $unitid, int $courseid, array $messageids): int {
        global $DB;
        $lock = $this->createMock(\core\lock\lock::class);
        $cronlock = $this->createMock(\core\lock\lock::class);
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('local_taskflow');

        $rule = $this->get_rule($unitid, $courseid, $messageids);
        $rule['id'] = $DB->insert_record('local_taskflow_rules', $rule);
        rule_created_updated::create([
            'objectid' => $rule['id'],
            'context'  => \context_system::instance(),
            'other'    => [
                'ruledata' => $rule,
            ],
        ])->trigger();
        time_mock::set_mock_time(strtotime('+ 2 minutes', time()));
        $plugingenerator->runtaskswithintime($cronlock, $lock, time());
        return (int)$rule['id'];
    }

    /**
     * A suspended user is dropped out and is not assigned again by a rule with inheritance.
     *
     * @covers \taskflowadapter_ksw\observer
     * @covers \taskflowadapter_ksw\local\leaver_assignments
     * @covers \local_taskflow\local\assignment_process\assignment_preprocessor
     * @covers \local_taskflow\local\eventhandlers\core_user_created_updated
     */
    public function test_suspended_user_is_dropped_out_and_not_assigned_again(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/user/lib.php');

        $apidatamanager = external_api_repository::create($this->externaldata);
        $apidatamanager->process_incoming_data();
        $this->redirectEmails();

        $cohorts = $DB->get_records('cohort');
        $cohort = array_shift($cohorts);
        $course = $this->set_db_course();
        $messageids = $this->set_messages_db();

        $lock = $this->createMock(\core\lock\lock::class);
        $cronlock = $this->createMock(\core\lock\lock::class);
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('local_taskflow');

        $firstruleid = $this->create_and_run_rule((int)$cohort->id, (int)$course->id, $messageids);

        $assigned = assignment_status_facade::get_status_identifier('assigned');
        $paused = assignment_status_facade::get_status_identifier('paused');
        $droppedout = assignment_status_facade::get_status_identifier('droppedout');

        $assignments = $DB->get_records('local_taskflow_assignment');
        $this->assertCount(3, $assignments);
        foreach ($assignments as $assignment) {
            $this->assertSame($assigned, (int)$assignment->status);
        }

        $leaver = $DB->get_record('user', ['firstname' => 'Betty']);
        $colleague = $DB->get_record('user', ['firstname' => 'Berta']);
        $otheruserids = array_diff(array_column($assignments, 'userid'), [$leaver->id, $colleague->id]);
        $pauseduserid = (int)reset($otheruserids);

        // The third user is paused first, e.g. because the contract ended. That must survive the suspension.
        assignments_facade::set_all_assignments_inactive($pauseduserid);

        // Both are suspended the way a web service does it.
        user_update_user((object)['id' => $leaver->id, 'suspended' => 1], false);
        user_update_user((object)['id' => $pauseduserid, 'suspended' => 1], false);
        $plugingenerator->runtaskswithintime($cronlock, $lock, time());

        $assignment = $DB->get_record('local_taskflow_assignment', ['userid' => $leaver->id]);
        $this->assertSame($droppedout, (int)$assignment->status);
        $this->assertSame(0, (int)$assignment->active);
        $this->assertNull($assignment->duedate);

        $assignment = $DB->get_record('local_taskflow_assignment', ['userid' => $pauseduserid]);
        $this->assertSame($paused, (int)$assignment->status);

        $assignment = $DB->get_record('local_taskflow_assignment', ['userid' => $colleague->id]);
        $this->assertSame($assigned, (int)$assignment->status);
        $this->assertSame(1, (int)$assignment->active);

        // A new rule with inheritance on the same unit only reaches the active colleague.
        $secondruleid = $this->create_and_run_rule((int)$cohort->id, (int)$course->id, $messageids);
        $this->assertTrue(
            $DB->record_exists('local_taskflow_assignment', ['userid' => $colleague->id, 'ruleid' => $secondruleid])
        );
        $this->assertFalse(
            $DB->record_exists('local_taskflow_assignment', ['userid' => $leaver->id, 'ruleid' => $secondruleid])
        );
        $this->assertFalse(
            $DB->record_exists('local_taskflow_assignment', ['userid' => $pauseduserid, 'ruleid' => $secondruleid])
        );

        // A further update of the suspended user neither revives nor creates an assignment.
        \core\event\user_updated::create_from_userid($leaver->id)->trigger();
        $plugingenerator->runtaskswithintime($cronlock, $lock, time());

        $assignments = $DB->get_records('local_taskflow_assignment', ['userid' => $leaver->id]);
        $this->assertCount(1, $assignments);
        $assignment = reset($assignments);
        $this->assertSame($firstruleid, (int)$assignment->ruleid);
        $this->assertSame($droppedout, (int)$assignment->status);
    }
}

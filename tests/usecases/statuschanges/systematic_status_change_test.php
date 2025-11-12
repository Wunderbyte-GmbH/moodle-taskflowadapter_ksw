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
use local_taskflow\local\external_adapter\external_api_repository;
use local_taskflow\task\update_rule;
use tool_mocktesttime\time_mock;
use local_taskflow\event\rule_created_updated;
use local_taskflow\local\assignment_status\assignment_status_facade;

/**
 * Test unit class of local_taskflow.
 *
 * @package taskflowadapter_ksw
 * @category test
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 */
final class systematic_status_change_test extends advanced_testcase {
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
        $this->preventResetByRollback();
        $this->externaldata = file_get_contents(
            $CFG->dirroot . '/local/taskflow/taskflowadapter/ksw/tests/usecases/external_json/betty_best_systematic.json'
        );
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
    public function tearDown(): void {
        global $DB;
        parent::tearDown();
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('local_taskflow');
        $plugingenerator->teardown();
    }


    /**
     * Test Nora notrelevant scenario.
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
     * @covers \local_taskflow\local\assignments\assignments_facade
     * @covers \local_taskflow\local\assignments\types\standard_assignment
     * @dataProvider status_change_provider
     * @param array $testdata
     *
     * @return void
     *
     */
    public function test_status_change(array $testdata): void {
        global $DB;

        $apidatamanager = external_api_repository::create($this->externaldata);
        $externaldata = $apidatamanager->get_external_data();
        $this->assertNotEmpty($externaldata, 'External user data should not be empty.');
        $apidatamanager->process_incoming_data();
        $sink = $this->redirectEmails();

        $cohorts = $DB->get_records('cohort');
        $cohort = array_pop($cohorts);

        $course = $this->set_db_course();
        $messageids = $this->set_messages_db();

        $lock = $this->createMock(\core\lock\lock::class);
        $cronlock = $this->createMock(\core\lock\lock::class);
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('local_taskflow');

        $this->setAdminUser();

        $bossuser = $DB->get_record('user', ['email' => $externaldata->{0}->DefaultEmailAddress]);
        $user = $DB->get_record('user', ['email' => $externaldata->{1}->DefaultEmailAddress]);

        $competencies = $plugingenerator->create_competencies($this, 2);
        $options = $plugingenerator->create_booking_options(
            $this,
            $course->id,
            $bossuser,
            1,
            [],
            [
                'competencies' => [$competencies[0]->get('id'), $competencies[1]->get('id')],
            ]
        );

        // Possibility to change rule data before insertion.
        $rule = $this->get_rule($cohort->id, $competencies[0]->get('id'), $messageids);
        foreach ($testdata['input']['ruledata'] ?? [] as $key => $value) {
            $rulejson = json_decode($rule['rulejson']);
            $rulejson->rulejson->rule->$key = $value;
            $rule['rulejson'] = json_encode($rulejson);
        }

        $action = $testdata['input']['useraction'] ?? null;

        // Here, we need to execute some user actions.
        if (
            $action
        ) {
            $plugingenerator->apply_user_action(
                $user,
                $action,
                $options[0],
                $rule
            );
        }

        $id = $DB->insert_record('local_taskflow_rules', $rule);

        // Trigger the rule created event to create assignments.
        $rule['id'] = $id;
        $event = rule_created_updated::create([
            'objectid' => $rule['id'],
            'context'  => \context_system::instance(),
            'other'    => [
                'ruledata' => $rule,
            ],
        ]);
        $event->trigger();
        time_mock::set_mock_time(strtotime('+ 15 minutes', time()));
        $plugingenerator->runtaskswithintime($cronlock, $lock, time());
        $assignments = $DB->get_records('local_taskflow_assignment');
        // There should be 1 assignments.
        $this->assertCount(1, $assignments);

        // Now, we have simulated the data we need.
        // Therefore, we change the status of the existing assignment in the DB to all the statuses there are.
        // Each time we run the cron again and check if the status changes as expected.

        $assignment1 = reset($assignments);
        $assignment1->status = $testdata['input']['status'];

        foreach ($testdata['input']['assignmentdata'] ?? [] as $key => $value) {
            $assignment1->$key = $value;
        }

        $orignalduedate = $assignment1->duedate;

        if ($testdata['input']['status'] === assignment_status_facade::get_status_identifier('completed')) {
            $assignment1->completeddate = time();
        }

        $DB->update_record('local_taskflow_assignment', $assignment1);

        // Execute the rule update task to trigger status re-evaluation.
        // We use this instead of event to avoid triggering a couple of tasks at the same time.
        $task = new update_rule();
        $task->set_custom_data(
            $rule
        );
        $task->execute();

        // phpcs:ignore Squiz.PHP.CommentedOutCode.Found
        /* time_mock::set_mock_time(strtotime('+ 15 minutes', time()));
        $plugingenerator->runtaskswithintime($cronlock, $lock, time());*/

        // Verify the status after rule execution.
        $assignment1 = $DB->get_record('local_taskflow_assignment', ['id' => $assignment1->id]);

        // If we don't want to execute the task, we check the status now.
        if (empty($taskdata['input']['executetask'])) {
            $this->assertSame(
                $testdata['expected']['status'],
                (int)$assignment1->status,
                $testdata['expected']['errormessage'] ?? 'Status did not change as expected.'
            );

            $this->assertSame(
                $orignalduedate,
                $assignment1->duedate,
                'Duedate is off by ' . ($orignalduedate - $assignment1->duedate) / 86400 . ' days.'
            );
            return;
        }

        time_mock::set_mock_time(strtotime($taskdata['input']['executetask'], time()));
        $plugingenerator->runtaskswithintime($cronlock, $lock, time());

        // Very finally the status after re-execution.
        $assignment1 = $DB->get_record('local_taskflow_assignment', ['id' => $assignment1->id]);
        $this->assertSame($testdata['expected']['status'], (int)$assignment1->status);
        $this->assertSame($orignalduedate, $assignment1->duedate);
    }

    /**
     * Setup the test environment.
     *
     *
     * @param mixed $shortname
     *
     * @return int
     *
     */
    private function create_custom_profile_field($shortname): int {
        global $DB;

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
     * @param int $courseid
     * @param int $userid
     */
    protected function course_completed($courseid, $userid): void {
        $completion = new \completion_completion([
            'course' => $courseid,
            'userid' => $userid,
        ]);
        $completion->mark_complete();
    }

    /**
     * Setup the test environment.
     * @param int $unitid
     * @param int $targetid
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
                        "duration" => 5184000,
                        "timemodified" => 23233232222,
                        "timecreated" => 23233232222,
                        "usermodified" => 1,
                        "actions" => [
                            [
                                "targets" => [
                                    [
                                        "targetid" => $courseid,
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
     */
    protected function set_messages_db(): array {
        global $DB, $CFG;
        $messageids = [];
        $messages = json_decode(
            file_get_contents(
                $CFG->dirroot
                . '/local/taskflow/taskflowadapter/ksw/tests/mock/messages/assignedandwarningsandfailed_messages.json'
            )
        );
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
    public static function status_change_provider(): array {
        return [
            'assigned' => [
                [
                    'input' => [
                        'status' => assignment_status_facade::get_status_identifier('assigned'),
                    ],
                    'expected' => [
                        'status' => assignment_status_facade::get_status_identifier('assigned'),
                        'errormessage' => 'Assigned status did not persist correctly.',
                    ],
                ],
            ],
            'overdue' => [
                [
                    'input' => [
                        'status' => assignment_status_facade::get_status_identifier('overdue'),
                    ],
                    'expected' => [
                        'status' => assignment_status_facade::get_status_identifier('overdue'),
                        'errormessage' => 'Assigned status did not persist correctly.',
                    ],
                ],
            ],
            'prolonged' => [
                [
                    'input' => [
                        'status' => assignment_status_facade::get_status_identifier('prolonged'),
                    ],
                    'expected' => [
                        'status' => assignment_status_facade::get_status_identifier('prolonged'),
                        'errormessage' => 'Prolonged status was not handled correctly.',
                    ],
                ],
            ],
            'droppedout' => [
                // phpcs:ignore moodle.Commenting.TodoComment.MissingInfoInline
                // TODO: Make sure logic is sound. Keepchanges might be wrong.
                [
                    'input' => [
                        'status' => assignment_status_facade::get_status_identifier('droppedout'),
                    ],
                    'expected' => [
                        'status' => assignment_status_facade::get_status_identifier('droppedout'),
                        'errormessage' => 'Dropped-out status did not update as expected.',
                    ],
                ],
            ],
            'enrolled' => [
                // In this enrolled test, the enrolled is only in the db.
                // The enrolled state is reject on import and therefore set back on assigned.
                // To test the enrolled state fully, we need to make sure that the user is really enrolled.
                // Also, in ksw, this is not relevant as we don't use enrolled status.
                [
                    'input' => [
                        'status' => assignment_status_facade::get_status_identifier('enrolled'),
                        'useraction' => 'enrolled',
                    ],
                    'expected' => [
                        'status' => assignment_status_facade::get_status_identifier('assigned'),
                        'errormessage' => 'Enrolled status was not maintained correctly.',
                    ],
                ],
            ],
            'notrelevant' => [
                [
                    'input' => [
                        'status' => assignment_status_facade::get_status_identifier('notrelevant'),
                    ],
                    'expected' => [
                        'status' => assignment_status_facade::get_status_identifier('notrelevant'),
                        'errormessage' => 'Not relevant status did not persist correctly.',
                    ],
                ],
            ],
            'completed' => [
                // In this completed test, the completion is only in the db.
                // The completed state is reject on import and therefore set back on assigned.
                // To test the completed state fully, we need to make sure that the target completion test succeeds.
                [
                    'input' => [
                        'status' => assignment_status_facade::get_status_identifier('completed'),
                        'useraction' => 'completed',
                    ],
                    'expected' => [
                        'status' => assignment_status_facade::get_status_identifier('completed'),
                        'errormessage' => 'Completed status did not finalize properly.',
                    ],
                ],
            ],
            // phpcs:ignore Squiz.PHP.CommentedOutCode.Found
            /*
            'partially_completed' => [
                [
                    'input' => [
                        'status' => assignment_status_facade::get_status_identifier('partially_completed'),
                        'useraction' => 'partially_completed',
                    ],
                    'expected' => [
                        'status' => assignment_status_facade::get_status_identifier('assigned'),
                        'errormessage' => 'Partially completed status was not correctly updated.',
                    ],
                ],
            ],*/
            'paused' => [
                [
                    'input' => [
                        'status' => assignment_status_facade::get_status_identifier('paused'),
                        'userdata' => [
                            'onlongleave' => true,
                        ],
                    ],
                    'expected' => [
                        'status' => assignment_status_facade::get_status_identifier('paused'),
                        'errormessage' => 'Paused status did not remain consistent.',
                    ],
                ],
            ],
            'planned' => [
                [
                    'input' => [
                        'status' => assignment_status_facade::get_status_identifier('planned'),
                    ],
                    'expected' => [
                        'status' => assignment_status_facade::get_status_identifier('planned'),
                        'errormessage' => 'Planned status was not preserved correctly.',
                    ],
                ],
            ],
            'reprimand' => [
                [
                    'input' => [
                        'status' => assignment_status_facade::get_status_identifier('reprimand'),
                    ],
                    'expected' => [
                        'status' => assignment_status_facade::get_status_identifier('assigned'),
                        'errormessage' => 'Reprimand status did not reflect the correct state.',
                    ],
                ],
            ],
            'sanction' => [
                [
                    'input' => [
                        'status' => assignment_status_facade::get_status_identifier('sanction'),
                    ],
                    'expected' => [
                        'status' => assignment_status_facade::get_status_identifier('assigned'),
                        'errormessage' => 'Sanction status failed to update correctly.',
                    ],
                ],
            ],
            'assigned, after task execution' => [
                [
                    'input' => [
                        'status' => assignment_status_facade::get_status_identifier('assigned'),
                        'executetask' => 'now',
                    ],
                    'expected' => [
                        'status' => assignment_status_facade::get_status_identifier('assigned'),
                        'errormessage' => 'Assigned status did not persist correctly after task execution.',
                    ],
                ],
            ],
            'overdue, after task execution' => [
                [
                    'input' => [
                        'status' => assignment_status_facade::get_status_identifier('overdue'),
                        'executetask' => '+ 1 year',
                    ],
                    'expected' => [
                        'status' => assignment_status_facade::get_status_identifier('overdue'),
                        'errormessage' => 'Assigned status did not persist correctly after task execution.',
                    ],
                ],
            ],
            'prolonged, after task execution' => [
                [
                    'input' => [
                        'status' => assignment_status_facade::get_status_identifier('prolonged'),
                        'executetask' => 'now',
                    ],
                    'expected' => [
                        'status' => assignment_status_facade::get_status_identifier('prolonged'),
                        'errormessage' => 'Prolonged status was not handled correctly after task execution.',
                    ],
                ],
            ],
            'droppedout, after task execution' => [
                // phpcs:ignore moodle.Commenting.TodoComment.MissingInfoInline
                // TODO: Make sure logic is sound. Keepchanges might be wrong.
                [
                    'input' => [
                        'status' => assignment_status_facade::get_status_identifier('droppedout'),
                        'executetask' => 'now',
                    ],
                    'expected' => [
                        'status' => assignment_status_facade::get_status_identifier('droppedout'),
                        'errormessage' => 'Dropped-out status did not update as expected after task execution.',
                    ],
                ],
            ],
            'enrolled, after task execution' => [
                // In this enrolled test, the enrolled is only in the db.
                // The enrolled state is reject on import and therefore set back on assigned.
                // To test the enrolled state fully, we need to make sure that the user is really enrolled.
                // Also, in ksw, this is not relevant as we don't use enrolled status.
                [
                    'input' => [
                        'status' => assignment_status_facade::get_status_identifier('enrolled'),
                        'executetask' => 'now',
                    ],
                    'expected' => [
                        'status' => assignment_status_facade::get_status_identifier('assigned'),
                        'errormessage' => 'Enrolled status was not maintained correctly after task execution.',
                    ],
                ],
            ],
            'notrelevant, after task execution' => [
                [
                    'input' => [
                        'status' => assignment_status_facade::get_status_identifier('notrelevant'),
                        'executetask' => 'now',
                    ],
                    'expected' => [
                        'status' => assignment_status_facade::get_status_identifier('notrelevant'),
                        'errormessage' => 'Not relevant status did not persist correctly after task execution.',
                    ],
                ],
            ],
            'completed, after task execution' => [
                // In this completed test, the completion is only in the db.
                // The completed state is reject on import and therefore set back on assigned.
                // To test the completed state fully, we need to make sure that the target completion test succeeds.
                [
                    'input' => [
                        'status' => assignment_status_facade::get_status_identifier('completed'),
                        'executetask' => 'now',
                        'useraction' => 'completed',
                    ],
                    'expected' => [
                        'status' => assignment_status_facade::get_status_identifier('completed'),
                        'errormessage' => 'Completed status did not finalize properly after task execution.',
                    ],
                ],
            ],
            // phpcs:ignore Squiz.PHP.CommentedOutCode.Found
            /* 'partially_completed, after task execution' => [
                [
                    'input' => [
                        'status' => assignment_status_facade::get_status_identifier('partially_completed'),
                        'executetask' => 'now',
                    ],
                    'expected' => [
                        'status' => assignment_status_facade::get_status_identifier('assigned'),
                        'errormessage' => 'Partially completed status was not correctly updated after task execution.',
                    ],
                ],
            ], */
            'paused, after task execution' => [
                [
                    'input' => [
                        'status' => assignment_status_facade::get_status_identifier('paused'),
                        'executetask' => 'now',
                    ],
                    'expected' => [
                        'status' => assignment_status_facade::get_status_identifier('paused'),
                        'errormessage' => 'Paused status did not remain consistent after task execution.',
                    ],
                ],
            ],
            'planned, after task execution' => [
                [
                    'input' => [
                        'status' => assignment_status_facade::get_status_identifier('planned'),
                        'executetask' => '+ 11 days',
                    ],
                    'expected' => [
                        'status' => assignment_status_facade::get_status_identifier('planned'),
                        'errormessage' => 'Planned status was not preserved correctly after task execution.',
                    ],
                ],
            ],
            'reprimand, after task execution' => [
                [
                    'input' => [
                        'status' => assignment_status_facade::get_status_identifier('reprimand'),
                        'executetask' => 'now',
                    ],
                    'expected' => [
                        'status' => assignment_status_facade::get_status_identifier('assigned'),
                        'errormessage' => 'Reprimand status did not reflect the correct state after task execution.',
                    ],
                ],
            ],
            'sanction, after task execution' => [
                [
                    'input' => [
                        'status' => assignment_status_facade::get_status_identifier('sanction'),
                        'executetask' => 'now',
                    ],
                    'expected' => [
                        'status' => assignment_status_facade::get_status_identifier('assigned'),
                        'errormessage' => 'Sanction status failed to update correctly after task execution.',
                    ],
                ],
            ],
            'assigned, after task execution, with keepchanges' => [
                [
                    'input' => [
                        'status' => assignment_status_facade::get_status_identifier('assigned'),
                        'executetask' => 'now',
                        'assignmentdata' => [
                            'keepchanges' => 1,
                        ],
                    ],
                    'expected' => [
                        'status' => assignment_status_facade::get_status_identifier('assigned'),
                        'errormessage' => 'Assigned status did not persist correctly after task execution.',
                    ],
                ],
            ],
            'prolonged, after task execution, with keepchanges' => [
                [
                    'input' => [
                        'status' => assignment_status_facade::get_status_identifier('prolonged'),
                        'executetask' => 'now',
                        'assignmentdata' => [
                            'keepchanges' => 1,
                        ],
                    ],
                    'expected' => [
                        'status' => assignment_status_facade::get_status_identifier('prolonged'),
                        'errormessage' => 'Prolonged status was not handled correctly after task execution.',
                    ],
                ],
            ],
            'droppedout, after task execution, with keepchanges' => [
                // TODO: Make sure logic is sound. Keepchanges might be wrong.
                [
                    'input' => [
                        'status' => assignment_status_facade::get_status_identifier('droppedout'),
                        'executetask' => 'now',
                        'assignmentdata' => [
                            'keepchanges' => 1, // Test a delayed activation.
                        ],
                    ],
                    'expected' => [
                        'status' => assignment_status_facade::get_status_identifier('droppedout'),
                        'errormessage' => 'Dropped-out status did not update as expected after task execution.',
                    ],
                ],
            ],
            'enrolled, after task execution, with keepchanges' => [
                // In this enrolled test, the enrolled is only in the db.
                // The enrolled state is reject on import and therefore set back on assigned.
                // To test the enrolled state fully, we need to make sure that the user is really enrolled.
                // Also, in ksw, this is not relevant as we don't use enrolled status.
                [
                    'input' => [
                        'status' => assignment_status_facade::get_status_identifier('enrolled'),
                        'executetask' => 'now',
                        'assignmentdata' => [
                            'keepchanges' => 1,
                        ],
                    ],
                    'expected' => [
                        'status' => assignment_status_facade::get_status_identifier('enrolled'),
                        'errormessage' => 'Enrolled status was not maintained correctly after task execution.',
                    ],
                ],
            ],
            'notrelevant, after task execution, with keepchanges' => [
                [
                    'input' => [
                        'status' => assignment_status_facade::get_status_identifier('notrelevant'),
                        'executetask' => 'now',
                        'assignmentdata' => [
                            'keepchanges' => 1,
                        ],
                    ],
                    'expected' => [
                        'status' => assignment_status_facade::get_status_identifier('notrelevant'),
                        'errormessage' => 'Not relevant status did not persist correctly after task execution.',
                    ],
                ],
            ],
            'completed, after task execution, with keepchanges' => [
                // In this completed test, the completion is only in the db.
                // The completed state is reject on import and therefore set back on assigned.
                // To test the completed state fully, we need to make sure that the target completion test succeeds.
                [
                    'input' => [
                        'status' => assignment_status_facade::get_status_identifier('completed'),
                        'executetask' => 'now',
                        'useraction' => 'completed',
                        'assignmentdata' => [
                            'keepchanges' => 1,
                        ],
                    ],
                    'expected' => [
                        'status' => assignment_status_facade::get_status_identifier('completed'),
                        'errormessage' => 'Completed status did not finalize properly after task execution.',
                    ],
                ],
            ],
            // phpcs:ignore Squiz.PHP.CommentedOutCode.Found
            /*
            'partially_completed, after task execution, with keepchanges' => [
                [
                    'input' => [
                        'status' => assignment_status_facade::get_status_identifier('partially_completed'),
                        'executetask' => 'now',
                        'assignmentdata' => [
                            'keepchanges' => 1,
                        ],
                    ],
                    'expected' => [
                        'status' => assignment_status_facade::get_status_identifier('assigned'),
                        'errormessage' => 'Partially completed status was not correctly updated after task execution.',
                    ],
                ],
            ],
            */
            'paused, after task execution, with keepchanges' => [
                [
                    'input' => [
                        'status' => assignment_status_facade::get_status_identifier('paused'),
                        'executetask' => 'now',
                        'assignmentdata' => [
                            'keepchanges' => 1,
                        ],
                    ],
                    'expected' => [
                        'status' => assignment_status_facade::get_status_identifier('paused'),
                        'errormessage' => 'Paused status did not remain consistent after task execution.',
                    ],
                ],
            ],
            'planned, after task execution, with keepchanges' => [
                [
                    'input' => [
                        'status' => assignment_status_facade::get_status_identifier('planned'),
                        'executetask' => '+ 11 days',
                        'ruledata' => [
                            'activationdelay' => 86400 * 10, // Test a delayed activation.
                        ],
                        'assignmentdata' => [
                            'keepchanges' => 1,
                        ],
                    ],
                    'expected' => [
                        'status' => assignment_status_facade::get_status_identifier('planned'),
                        'errormessage' => 'Planned status was not preserved correctly after task execution.',
                    ],
                ],
            ],
            'reprimand, after task execution, with keepchanges' => [
                [
                    'input' => [
                        'status' => assignment_status_facade::get_status_identifier('reprimand'),
                        'executetask' => 'now',
                        'assignmentdata' => [
                            'keepchanges' => 1, // Test a delayed activation.
                        ],
                    ],
                    'expected' => [
                        'status' => assignment_status_facade::get_status_identifier('reprimand'),
                        'errormessage' => 'Reprimand status did not reflect the correct state after task execution.',
                    ],
                ],
            ],
            'sanction, after task execution, with keepchanges' => [
                [
                    'input' => [
                        'status' => assignment_status_facade::get_status_identifier('sanction'),
                        'executetask' => 'now',
                        'assignmentdata' => [
                            'keepchanges' => 1, // Test a delayed activation.
                        ],
                    ],
                    'expected' => [
                        'status' => assignment_status_facade::get_status_identifier('sanction'),
                        'errormessage' => 'Sanction status failed to update correctly after task execution.',
                    ],
                ],
            ],
        ];
    }
}

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
use local_taskflow\local\assignment_status\assignment_status_facade;
use local_taskflow\local\rules\rules;
use stdClass;
use tool_mocktesttime\time_mock;
use context_system;
use core_competency\api;
use core_competency\competency;
use local_taskflow\event\rule_created_updated;

/**
 * Scenario test: Users dropped out and reactivated.
 *
 * @package taskflowadapter_ksw
 * @category test
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class dropped_out_comeback_test extends advanced_testcase {
    /**
     * User 1 instance.
     *
     * @var stdClass
     */
    private stdClass $user1;
    /**
     * User 2 instance.
     *
     * @var stdClass
     */
    private stdClass $user2;
    /**
     * User 3 instance.
     *
     * @var stdClass
     */
    private stdClass $user3;

    /**
     * Cohort instance.
     *
     * @var stdClass
     */
    private stdClass $cohort;
    /**
     * Competency instance.
     *
     * @var competency
     */
    private competency $competency;
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
            'Org1',
            'supervisor',
            'units',
            'customfieldtofilter',
        ]);
        $plugingenerator->set_config_values('ksw');
        $this->create_custom_profile_field();
        $this->preventResetByRollback();
    }

    /**
     * Mandatory clean-up after each test.
     */
    public function tearDown(): void {
        global $DB;
        parent::tearDown();
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('local_taskflow');
        $plugingenerator->teardown();
    }

    /**
     * Setup the test environment.
     */
    private function create_custom_profile_field(): int {
        global $DB;
        $shortname = 'customfieldtofilter';
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
     * Test filter changes.
     *
     * @return void
     *
     */
    public function test_filter_changes(): void {
        global $DB;
        $this->build_base_scenario();
        $rule = $this->get_rule($this->filter_to_exclude_noone());
        $id = $DB->insert_record('local_taskflow_rules', $rule);
        $rule['id'] = $id;

        $event = rule_created_updated::create([
            'objectid' => $rule['id'],
            'context'  => context_system::instance(),
            'other'    => [
                'ruledata' => $rule,
            ],
        ]);
        $event->trigger();
        $this->runAdhocTasks();
        $assignments = $DB->get_records('local_taskflow_assignment');
        // There is no Filter, all 3 users should have an assignment.
        $this->assertCount(3, $assignments);
        // Now update the rule with a filter that excludes user1 and user2.
        $rule = $this->get_rule($this->filter_to_exlude_user1_and_user2());
        $rule['id'] = $id;
        $DB->update_record('local_taskflow_rules', $rule);
        // We reset the rules instances to make sure we reload the updated rule.
        rules::reset_instances();
        $event = rule_created_updated::create([
            'objectid' => $rule['id'],
            'context'  => context_system::instance(),
            'other'    => [
                'ruledata' => $rule,
            ],
        ]);
        $event->trigger();
        $this->runAdhocTasks();
        $assignments = $DB->get_records('local_taskflow_assignment');
        // User 3 should have active assignment, user1 and user2 should be dropped out.
        foreach ($assignments as $assignment) {
            if ($assignment->userid == $this->user3->id) {
                $this->assertEquals(assignment_status_facade::get_status_identifier('assigned'), $assignment->status);
                $this->assertEquals(1, $assignment->active);
            } else {
                $this->assertEquals(assignment_status_facade::get_status_identifier('droppedout'), $assignment->status);
                $this->assertEquals($assignment->active, $assignment->active);
            }
        }
        // Now update the rule with a filter that excludes only user2.
        $rule = $this->get_rule($this->filter_to_exclude_user2());
        $rule['id'] = $id;
        $DB->update_record('local_taskflow_rules', $rule);
        // We reset the rules instances to make sure we reload the updated rule.
        rules::reset_instances();
        $event = rule_created_updated::create([
            'objectid' => $rule['id'],
            'context'  => context_system::instance(),
            'other'    => [
                'ruledata' => $rule,
            ],
        ]);
        $event->trigger();
        $this->runAdhocTasks();
        $assignments = $DB->get_records('local_taskflow_assignment');
        // User 1 and 3 should have active assignment, user2 should be dropped out.
        foreach ($assignments as $assignment) {
            if ($assignment->userid == $this->user2->id) {
                $this->assertEquals(assignment_status_facade::get_status_identifier('droppedout'), $assignment->status);
                $this->assertEquals($assignment->active, $assignment->active);
            } else {
                $this->assertEquals(assignment_status_facade::get_status_identifier('assigned'), $assignment->status);
                $this->assertEquals(1, $assignment->active);
            }
        }
        $this->tearDown();
    }
    /**
     * Test custom field changes. Logic is correct setup is not.
     *
     * @return void
     *
     */
    public function test_customfield_changes(): void {
        global $DB, $CFG;
        $this->build_base_scenario();
        $rule = $this->get_rule($this->filter_to_exclude_noone());
        $id = $DB->insert_record('local_taskflow_rules', $rule);
        $rule['id'] = $id;

        $event = rule_created_updated::create([
            'objectid' => $rule['id'],
            'context'  => context_system::instance(),
            'other'    => [
                'ruledata' => $rule,
            ],
        ]);
        $event->trigger();
        $this->runAdhocTasks();
        $assignments = $DB->get_records('local_taskflow_assignment');
        // There is no Filter, all 3 users should have an assignment.
        $this->assertCount(3, $assignments);
        // Now change user profile field of user 1 and user2 to exclude them.
        $fieldid = $DB->get_field('user_info_field', 'id', ['shortname' => 'customfieldtofilter'], MUST_EXIST);
        $existing1 = $DB->get_record('user_info_data', ['userid' => $this->user1->id, 'fieldid' => $fieldid]);
        if ($existing1) {
            $DB->update_record('user_info_data', (object)[
                'id' => $existing1->id,
                'userid' => $this->user1->id,
                'fieldid' => $fieldid,
                'data' => 'Z',
                'dataformat' => FORMAT_HTML,
            ]);
        }
        $existing2 = $DB->get_record('user_info_data', ['userid' => $this->user2->id, 'fieldid' => $fieldid]);
        if ($existing2) {
            $DB->update_record('user_info_data', (object)[
                'id' => $existing2->id,
                'userid' => $this->user2->id,
                'fieldid' => $fieldid,
                'data' => 'Z',
                'dataformat' => FORMAT_HTML,
            ]);
        }
        // We trigger the import to process the changes.
        require_once($CFG->dirroot . '/user/lib.php');
        user_update_user((object)[
            'id' => $this->user2->id,
            'firstname' => 'UpdatedFirstName',
            'lastname' => 'UpdatedLastName',
        ]);
        $this->runAdhocTasks();
        $assignments = $DB->get_records('local_taskflow_assignment');
        // User 3 should have active assignment, user1 and user2 should be dropped out.
        foreach ($assignments as $assignment) {
            if ($assignment->userid == $this->user3->id) {
                $this->assertEquals(assignment_status_facade::get_status_identifier('assigned'), $assignment->status);
                $this->assertEquals(1, $assignment->active);
            } else {
                $this->assertEquals(assignment_status_facade::get_status_identifier('droppedout'), $assignment->status);
                $this->assertEquals($assignment->active, $assignment->active);
            }
        // Now change user profile field of user1 to include him again.
            $existing1 = $DB->get_record('user_info_data', ['userid' => $this->user1->id, 'fieldid' => $fieldid]);
            if ($existing1) {
                $DB->update_record('user_info_data', (object)[
                'id' => $existing1->id,
                'userid' => $this->user1->id,
                'fieldid' => $fieldid,
                'data' => 'A',
                'dataformat' => FORMAT_HTML,
                ]);
            }
        // We trigger the import to process the changes.
            require_once($CFG->dirroot . '/user/lib.php');
            user_update_user((object)[
            'id' => $this->user1->id,
            'firstname' => 'UpdatedFirstNameAgain',
            'lastname' => 'UpdatedLastNameAgain',
            ]);
            $this->runAdhocTasks();
            $assignments = $DB->get_records('local_taskflow_assignment');
        // User 1 and 3 should have active assignment, user2 should be dropped out.
            foreach ($assignments as $assignment) {
                if ($assignment->userid == $this->user2->id) {
                    $this->assertEquals(assignment_status_facade::get_status_identifier('droppedout'), $assignment->status);
                    $this->assertEquals($assignment->active, $assignment->active);
                } else {
                    $this->assertEquals(assignment_status_facade::get_status_identifier('assigned'), $assignment->status);
                    $this->assertEquals(1, $assignment->active);
                }
            }
        }
         $this->tearDown();
    }
    /**
     * Build base scenario.
     *
     * @return void
     *
     */
    private function build_base_scenario(): void {
        global $DB;

        $this->setAdminUser();

        set_config('timezone', 'Europe/Kyiv');
        set_config('forcetimezone', 'Europe/Kyiv');

        // Setup test data.
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $this->user1 = $this->getDataGenerator()->create_user();
        $this->user2 = $this->getDataGenerator()->create_user();
        $this->user3 = $this->getDataGenerator()->create_user();

        $fieldid = $DB->get_field('user_info_field', 'id', ['shortname' => 'customfieldtofilter'], MUST_EXIST);

        $users = [
        [$this->user1->id, 'A'],
        [$this->user2->id, 'X'],
        [$this->user3->id, 'Y'],
        ];

        foreach ($users as [$userid, $value]) {
             $existingid = $DB->get_field('user_info_data', 'id', [
            'userid' => $userid,
            'fieldid' => $fieldid,
             ]);

             $record = (object)[
                 'userid' => $userid,
                 'fieldid' => $fieldid,
                 'data' => $value,
                 'dataformat' => FORMAT_HTML,
             ];

             if ($existingid) {
                 $record->id = $existingid;
                 $DB->update_record('user_info_data', $record);
             } else {
                 $DB->insert_record('user_info_data', $record);
             }
        }

        $scale = $this->getDataGenerator()->create_scale([
        'scale' => 'Not proficient,Proficient',
        'name' => 'Test Competency Scale',
        ]);

        $this->cohort = $this->set_db_cohort();
        cohort_add_member($this->cohort->id, $this->user1->id);
        cohort_add_member($this->cohort->id, $this->user2->id);
        cohort_add_member($this->cohort->id, $this->user3->id);

        // Create a competency.
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

        // Create competencies. For Targets.
        $record = (object)[
            'shortname' => 'testcompetency',
            'idnumber' => 'testcompetency',
            'competencyframeworkid' => $framework->get('id'),
            'scaleid' => null,
            'description' => 'A test competency',
            'id' => 0,
            'scaleconfiguration' => null,
            'parentid' => 0,
        ];
        $this->competency = new competency(0, $record);
        $this->competency->set('sortorder', 0);
        $this->competency->create();
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
     * Filter that excludes user2.
     *
     * @return array
     *
     */
    private function filter_to_exclude_user2(): array {
        return [
                "filtertype" => "user_profile_field",
                "userprofilefield" => "customfieldtofilter",
                "operator" => "isin",
                "date" => time(),
                "value" => "A;Y",
                ];
    }

    /**
     * Filter that excludes user1 and user2.
     *
     * @return array
     *
     */
    private function filter_to_exlude_user1_and_user2(): array {
        return [
                "filtertype" => "user_profile_field",
                "userprofilefield" => "customfieldtofilter",
                "operator" => "contains",
                "date" => time(),
                "value" => "Y",
                ];
    }

    /**
     * Filter that excludes no one.
     *
     * @return array
     *
     */
    private function filter_to_exclude_noone(): array {
        return [
                "filtertype" => "user_profile_field",
                "userprofilefield" => "customfieldtofilter",
                "operator" => "isin",
                "date" => time(),
                "value" => "A;X;Y",
                ];
    }

    /**
     * Setup the test environment.
     * @param array $filter
     * @return array
     */
    public function get_rule(array $filter): array {
        $rule = [
            "unitid" => $this->cohort->id,
            "rulename" => "test_rule",
            "rulejson" => json_encode((object)[
                "rulejson" => [
                    "rule" => [
                        "name" => "test_rule",
                        "description" => "test_rule_description",
                        "type" => "taskflow",
                        "enabled" => true,
                        "duedatetype" => "duration",
                        "cyclicvalidation" => "0",
                        "cyclicduration" => 38361600,
                        "fixeddate" => 23233232222,
                        "duration" => 23233232222,
                        "timemodified" => 23233232222,
                        "timecreated" => 23233232222,
                        "usermodified" => 1,
                        "filter" => [$filter],
                        "actions" => [
                            [
                                "targets" => [
                                    [
                                        "targetid" => $this->competency->get('id'),
                                        "targettype" => "competency",
                                        "targetname" => "mycompetency",
                                        "sortorder" => 2,
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
        return $rule;
    }
}

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
use local_taskflow\local\external_adapter\external_api_repository;
use mod_booking\singleton_service;
use tool_mocktesttime\time_mock;

/**
 * Manually filled (protected) cohorts must survive a user update triggered sync.
 *
 * @package taskflowadapter_ksw
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 */
final class protected_cohort_survives_update_test extends advanced_testcase {
    /** @var string|null Stores the external user data. */
    protected ?string $externaldata = null;

    /**
     * Setup the test environment.
     */
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        require_once($CFG->dirroot . '/cohort/lib.php');
        require_once($CFG->dirroot . '/user/profile/lib.php');
        time_mock::init();
        time_mock::set_mock_time(strtotime('now'));
        $this->resetAfterTest(true);
        $this->preventResetByRollback();
        $this->externaldata = file_get_contents(__DIR__ . '/external_json/betty_best_two_cohorts_ksw.json');
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
     * Builds a rule for a unit.
     * @param int $unitid
     * @param int $targetid
     * @return array
     */
    private function get_rule(int $unitid, int $targetid): array {
        return [
            "unitid" => $unitid,
            "rulename" => "manual_cohort_rule",
            "rulejson" => json_encode((object)[
                "rulejson" => [
                    "rule" => [
                        "name" => "manual_cohort_rule",
                        "description" => "manual_cohort_rule_description",
                        "type" => "taskflow",
                        "enabled" => true,
                        "duedatetype" => "duration",
                        "fixeddate" => 23233232222,
                        "duration" => 86400 * 30,
                        "timemodified" => time(),
                        "timecreated" => time(),
                        "usermodified" => 1,
                        "inheritance" => 1,
                        "actions" => [
                            [
                                "targets" => [
                                    [
                                        "targetid" => $targetid,
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
    }

    /**
     * Imports users, creates a manual cohort with a rule and adds chris to it.
     *
     * @return array [user, manualcohort, rule]
     */
    private function setup_manual_cohort(): array {
        global $DB;
        singleton_service::destroy_instance();
        $this->setAdminUser();
        $lock = $this->createMock(\core\lock\lock::class);
        $cronlock = $this->createMock(\core\lock\lock::class);
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('local_taskflow');

        $apidatamanager = external_api_repository::create($this->externaldata);
        $this->assertNotEmpty($apidatamanager->get_external_data());
        $apidatamanager->process_incoming_data();

        $user = $DB->get_record('user', ['email' => 'chris.change@ksw.ch']);
        $orgcohorts = array_keys($DB->get_records('cohort_members', ['userid' => $user->id], '', 'cohortid'));
        $this->assertNotEmpty($orgcohorts, 'Chris should be in the cohort derived from his org path.');

        // Cohort in a course category context - it must be supported as well.
        $category = $this->getDataGenerator()->create_category();
        $manualcohort = $this->getDataGenerator()->create_cohort([
            'name' => 'Manual special cohort',
            'contextid' => \context_coursecat::instance($category->id)->id,
        ]);

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
        $competency = new competency(0, (object)[
            'shortname' => 'testcompetency',
            'idnumber' => 'testcompetency',
            'competencyframeworkid' => $framework->get('id'),
            'scaleid' => null,
            'description' => 'A test competency',
            'id' => 0,
            'scaleconfiguration' => null,
            'parentid' => 0,
        ]);
        $competency->set('sortorder', 0);
        $competency->create();

        $rule = $this->get_rule((int)$manualcohort->id, (int)$competency->get('id'));
        $rule['id'] = $DB->insert_record('local_taskflow_rules', $rule);
        rule_created_updated::create([
            'objectid' => $rule['id'],
            'context'  => context_system::instance(),
            'other'    => ['ruledata' => $rule],
        ])->trigger();

        // Manual cohort membership => unit member => assignment.
        cohort_add_member($manualcohort->id, $user->id);
        $plugingenerator->runtaskswithintime($cronlock, $lock, time());

        $this->assertTrue(cohort_is_member($manualcohort->id, $user->id));
        $this->assertTrue($DB->record_exists('local_taskflow_unit_members', ['userid' => $user->id, 'unitid' => $manualcohort->id]));
        $this->assertCount(
            1,
            $DB->get_records('local_taskflow_assignment', ['userid' => $user->id, 'ruleid' => $rule['id'], 'active' => 1])
        );
        return [$user, $manualcohort, $rule];
    }

    /**
     * Triggers a user update for the given user (e.g. lastname change).
     *
     * @param \stdClass $user
     * @return void
     */
    private function update_user(\stdClass $user): void {
        global $DB;
        $user = $DB->get_record('user', ['id' => $user->id]);
        profile_load_custom_fields($user);
        $user->lastname = 'Changed';
        user_update_user($user, false, true);
    }

    /**
     * A protected cohort keeps the user and his assignment on user update.
     *
     * @covers \taskflowadapter_ksw\adapter::get_protected_cohortids
     * @covers \taskflowadapter_ksw\adapter::process_incoming_data
     */
    public function test_protected_cohort_membership_survives_user_update(): void {
        global $DB;
        [$user, $manualcohort, $rule] = $this->setup_manual_cohort();
        set_config('protectedcohorts', (string)$manualcohort->id, 'taskflowadapter_ksw');

        $this->update_user($user);

        $this->assertTrue(cohort_is_member($manualcohort->id, $user->id), 'Protected cohort membership must survive.');
        $this->assertTrue($DB->record_exists('local_taskflow_unit_members', ['userid' => $user->id, 'unitid' => $manualcohort->id]));
        $this->assertCount(
            1,
            $DB->get_records('local_taskflow_assignment', ['userid' => $user->id, 'ruleid' => $rule['id'], 'active' => 1]),
            'Assignment of the protected unit must stay active.'
        );
    }

    /**
     * Without protection the sync removes the manual cohort (existing behaviour).
     *
     * @covers \taskflowadapter_ksw\adapter::process_incoming_data
     */
    public function test_unprotected_cohort_membership_is_removed_on_user_update(): void {
        global $DB;
        [$user, $manualcohort, $rule] = $this->setup_manual_cohort();
        set_config('protectedcohorts', '', 'taskflowadapter_ksw');

        $this->update_user($user);

        $this->assertFalse(cohort_is_member($manualcohort->id, $user->id));
        $this->assertCount(
            0,
            $DB->get_records('local_taskflow_assignment', ['userid' => $user->id, 'ruleid' => $rule['id'], 'active' => 1])
        );
    }
}

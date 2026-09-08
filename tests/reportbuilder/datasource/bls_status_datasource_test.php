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

declare(strict_types=1);

namespace taskflowadapter_ksw\reportbuilder\datasource;

use core_reportbuilder_generator;
use core_reportbuilder\local\filters\boolean_select;
use core_reportbuilder\local\filters\text;
use core_reportbuilder\local\models\column;
use core_reportbuilder\manager;
use core_reportbuilder\tests\core_reportbuilder_testcase;
use local_taskflow\local\assignment_status\assignment_status_facade;
use local_taskflow_generator;
use stdClass;

/**
 * BLS status datasource tests.
 *
 * @package    taskflowadapter_ksw
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <https://www.wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \taskflowadapter_ksw\reportbuilder\datasource\bls_status_datasource
 * @covers     \taskflowadapter_ksw\reportbuilder\local\entities\bls_status
 * @covers     \taskflowadapter_ksw\reportbuilder\local\entities\orgunit
 */
final class bls_status_datasource_test extends core_reportbuilder_testcase {
    /**
     * Set up: org unit profile fields.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        /** @var local_taskflow_generator $plugingenerator */
        $plugingenerator = $this->getDataGenerator()->get_plugin_generator('local_taskflow');
        $plugingenerator->create_custom_profile_fields(['Org1', 'Org2', 'Org3']);
    }

    /**
     * Create a rule record.
     *
     * @param string $rulename
     * @return int Rule ID
     */
    private function create_rule(string $rulename): int {
        global $DB;

        return $DB->insert_record('local_taskflow_rules', (object) [
            'rulename' => $rulename,
            'rulejson' => '{}',
            'isactive' => 1,
            'unitid' => 1,
        ]);
    }

    /**
     * Create an assignment record directly, bypassing events and status handling.
     *
     * @param int $userid
     * @param int $ruleid
     * @param string $status Status type name (completed, assigned, overdue, ...)
     * @return int Assignment ID
     */
    private function create_assignment(int $userid, int $ruleid, string $status): int {
        global $DB;

        $now = time();
        return $DB->insert_record('local_taskflow_assignment', (object) [
            'userid' => $userid,
            'ruleid' => $ruleid,
            'status' => assignment_status_facade::get_status_identifier($status),
            'targets' => '[]',
            'messages' => '[]',
            'unitid' => 1,
            'active' => 1,
            'assigneddate' => $now,
            'duedate' => $now + DAYSECS,
            'usermodified' => 0,
            'timecreated' => $now,
            'timemodified' => $now,
            'keepchanges' => 0,
            'overduecounter' => 0,
            'prolongedcounter' => 0,
        ]);
    }

    /**
     * Create a user, optionally with an Org3 value.
     *
     * @param string $username
     * @param string $org3
     * @return stdClass
     */
    private function create_user(string $username, string $org3 = ''): stdClass {
        $data = ['username' => $username];
        if ($org3 !== '') {
            $data['profile_field_Org3'] = $org3;
        }
        return $this->getDataGenerator()->create_user($data);
    }

    /**
     * Create the test data.
     *
     * Unit A: a1 valid (two BLS assignments, counted once), a2 expired,
     *         a3 valid and expired (two rules), a4 not relevant.
     * Unit B: b1 dropped out, b2 expired.
     * No org unit: c1 valid.
     * Unit D: d1 only has a non-BLS assignment, so is not part of the report.
     */
    private function create_fixture(): void {
        $bls1 = $this->create_rule('BLS Reanimation');
        $bls2 = $this->create_rule('Refresher BLS');
        $other = $this->create_rule('Hygiene');

        $a1 = $this->create_user('a1', 'Unit A');
        $this->create_assignment((int) $a1->id, $bls1, 'completed');
        $this->create_assignment((int) $a1->id, $bls2, 'completed');

        $a2 = $this->create_user('a2', 'Unit A');
        $this->create_assignment((int) $a2->id, $bls1, 'overdue');

        $a3 = $this->create_user('a3', 'Unit A');
        $this->create_assignment((int) $a3->id, $bls1, 'assigned');
        $this->create_assignment((int) $a3->id, $bls2, 'enrolled');

        $a4 = $this->create_user('a4', 'Unit A');
        $this->create_assignment((int) $a4->id, $bls1, 'notrelevant');

        $b1 = $this->create_user('b1', 'Unit B');
        $this->create_assignment((int) $b1->id, $bls1, 'droppedout');

        $b2 = $this->create_user('b2', 'Unit B');
        $this->create_assignment((int) $b2->id, $bls1, 'enrolled');

        $c1 = $this->create_user('c1');
        $this->create_assignment((int) $c1->id, $bls1, 'completed');

        $d1 = $this->create_user('d1', 'Unit D');
        $this->create_assignment((int) $d1->id, $other, 'completed');
    }

    /**
     * Create a report with the default setup of the datasource.
     *
     * @param string[] $conditions Additional conditions
     * @return int Report ID
     */
    private function create_default_report(array $conditions = []): int {
        /** @var core_reportbuilder_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('core_reportbuilder');

        $report = $generator->create_report([
            'name' => 'BLS',
            'source' => bls_status_datasource::class,
            'default' => 1,
        ]);
        $reportid = (int) $report->get('id');

        foreach ($conditions as $condition) {
            $generator->create_condition(['reportid' => $reportid, 'uniqueidentifier' => $condition]);
        }

        return $reportid;
    }

    /**
     * Return report content as rows of cell values.
     *
     * @param int $reportid
     * @param array $filtervalues
     * @return array[]
     */
    private function get_rows(int $reportid, array $filtervalues = []): array {
        return array_map('array_values', $this->get_custom_report_content($reportid, 30, $filtervalues));
    }

    /**
     * The default report is grouped by Org3 and counts distinct users.
     */
    public function test_default_report(): void {
        $this->create_fixture();
        $reportid = $this->create_default_report();

        // Columns are created aggregated, with headings.
        $columns = column::get_records(['reportid' => $reportid], 'columnorder');
        $this->assertEquals(
            ['orgunit:org3', 'bls_status:expired', 'bls_status:valid', 'bls_status:required', 'bls_status:validshare'],
            array_map(fn(column $column): string => $column->get('uniqueidentifier'), array_values($columns))
        );
        $this->assertEquals(
            [null, 'sum', 'sum', 'sum', 'percent'],
            array_map(fn(column $column): ?string => $column->get('aggregation'), array_values($columns))
        );
        $this->assertEquals(
            ['Organisation', 'Expired certificates', 'Valid certificates', 'Employees required to hold BLS (total)',
                'Share of valid certificates'],
            array_map(fn(column $column): string => $column->get('heading'), array_values($columns))
        );

        // Organisation, expired, valid, required, share of valid.
        $this->assertEquals([
            [get_string('orgunit:none', 'taskflowadapter_ksw'), 0, 1, 1, '100.0%'],
            ['Unit A', 2, 2, 3, '66.7%'],
            ['Unit B', 1, 0, 1, '0.0%'],
        ], $this->get_rows($reportid));
    }

    /**
     * Filter by org unit.
     */
    public function test_org_unit_filter(): void {
        $this->create_fixture();
        $reportid = $this->create_default_report();

        $rows = $this->get_rows($reportid, [
            'orgunit:org3_operator' => text::CONTAINS,
            'orgunit:org3_value' => 'Unit A',
        ]);
        $this->assertEquals([
            ['Unit A', 2, 2, 3, '66.7%'],
        ], $rows);

        $rows = $this->get_rows($reportid, [
            'orgunit:org3_operator' => text::IS_EMPTY,
        ]);
        $this->assertEquals([
            [get_string('orgunit:none', 'taskflowadapter_ksw'), 0, 1, 1, '100.0%'],
        ], $rows);
    }

    /**
     * Condition on the valid flag restricts the users that are counted.
     */
    public function test_valid_condition(): void {
        $this->create_fixture();
        $reportid = $this->create_default_report(['bls_status:valid']);

        $report = manager::get_report_from_id($reportid);
        $report->set_condition_values([
            'bls_status:valid_operator' => boolean_select::CHECKED,
        ]);

        // Only a1, a3 and c1 hold a valid certificate.
        $this->assertEquals([
            [get_string('orgunit:none', 'taskflowadapter_ksw'), 0, 1, 1, '100.0%'],
            ['Unit A', 1, 2, 2, '100.0%'],
        ], $this->get_rows($reportid));
    }

    /**
     * Without aggregation the report lists one row per user with Yes/No flags.
     */
    public function test_user_rows(): void {
        $this->create_fixture();

        /** @var core_reportbuilder_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('core_reportbuilder');
        $report = $generator->create_report([
            'name' => 'BLS users',
            'source' => bls_status_datasource::class,
            'default' => 0,
        ]);
        $reportid = (int) $report->get('id');

        $generator->create_column(['reportid' => $reportid, 'uniqueidentifier' => 'user:username', 'sortenabled' => 1]);
        $columns = ['bls_status:valid', 'bls_status:expired', 'bls_status:required', 'bls_status:validshare', 'orgunit:org3'];
        foreach ($columns as $column) {
            $generator->create_column(['reportid' => $reportid, 'uniqueidentifier' => $column]);
        }

        $yes = get_string('yes');
        $no = get_string('no');
        $none = get_string('orgunit:none', 'taskflowadapter_ksw');

        $this->assertEquals([
            ['a1', $yes, $no, $yes, $yes, 'Unit A'],
            ['a2', $no, $yes, $yes, $no, 'Unit A'],
            ['a3', $yes, $yes, $yes, $yes, 'Unit A'],
            ['a4', $no, $no, $no, '', 'Unit A'],
            ['b1', $no, $no, $no, '', 'Unit B'],
            ['b2', $no, $yes, $yes, $no, 'Unit B'],
            ['c1', $yes, $no, $yes, $yes, $none],
        ], $this->get_rows($reportid));
    }

    /**
     * Stress test the datasource: every column, aggregation and condition.
     */
    public function test_stress_datasource(): void {
        $this->create_fixture();

        $this->datasource_stress_test_columns(bls_status_datasource::class);
        $this->datasource_stress_test_columns_aggregation(bls_status_datasource::class);
        $this->datasource_stress_test_conditions(bls_status_datasource::class, 'user:username');
    }
}

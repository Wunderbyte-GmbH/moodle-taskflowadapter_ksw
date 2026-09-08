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

use core_reportbuilder\datasource;
use core_reportbuilder\local\entities\user;
use core_reportbuilder\local\helpers\report;
use taskflowadapter_ksw\reportbuilder\local\entities\bls_status;
use taskflowadapter_ksw\reportbuilder\local\entities\orgunit;

/**
 * BLS certificate status datasource for Report Builder.
 *
 * One row per user having at least one BLS assignment (an assignment whose
 * rule name contains "BLS"), with the user's BLS status flags (valid, expired,
 * required) and the org unit levels (Org1, Org2, ...) of the user.
 *
 * A report created with the default setup is already aggregated: one row per
 * Org3 value with the number of users holding an expired certificate, a valid
 * certificate, the number of users required to hold one and the share of
 * valid certificates among them. Users without an Org3 value are reported as
 * "left the organisation".
 *
 * @package    taskflowadapter_ksw
 * @copyright  2026 Wunderbyte GmbH <https://www.wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class bls_status_datasource extends datasource {
    /** @var int Org unit level the default report is grouped by (profile field Org3). */
    public const ORG_LEVEL = 3;

    /**
     * Return user-friendly datasource name.
     *
     * @return string
     */
    public static function get_name(): string {
        return get_string('datasource:blsstatus', 'taskflowadapter_ksw');
    }

    /**
     * Initialise the datasource, define entities and joins.
     */
    protected function initialise(): void {
        $userentity = new user();
        $u = $userentity->get_table_alias('user');
        $this->set_main_table('user', $u);
        $this->add_entity($userentity);

        // Per-user summary of the BLS assignments. Added as report join, so
        // only users with at least one BLS assignment are listed.
        $blsentity = (new bls_status())->set_table_alias('user', $u);
        $this->add_entity($blsentity);
        $this->add_join($blsentity->get_bls_join());

        // Org unit levels of the user (profile fields Org1, Org2, ...).
        $orgunitentity = (new orgunit())->set_table_alias('user', $u);
        $this->add_entity($orgunitentity);

        $this->add_all_from_entities();
    }

    /**
     * Return the identifier of the org unit column the default report is grouped by.
     *
     * Org3 if that profile field exists, otherwise the deepest existing org
     * level, null if there are no org profile fields at all.
     *
     * @return string|null
     */
    public static function get_org_column(): ?string {
        $fields = orgunit::get_org_fields();
        if (empty($fields)) {
            return null;
        }

        $name = 'org' . self::ORG_LEVEL;
        if (!isset($fields[$name])) {
            $name = array_key_last($fields);
        }
        return 'orgunit:' . $name;
    }

    /**
     * Default columns with their aggregation and heading.
     *
     * @return array[] Each entry [column identifier, aggregation or null, heading string identifier]
     */
    private function get_default_column_definitions(): array {
        $definitions = [];

        $orgcolumn = self::get_org_column();
        if ($orgcolumn !== null) {
            $definitions[] = [$orgcolumn, null, 'heading:organisation'];
        }
        $definitions[] = ['bls_status:expired', 'sum', 'heading:expired'];
        $definitions[] = ['bls_status:valid', 'sum', 'heading:valid'];
        $definitions[] = ['bls_status:required', 'sum', 'heading:required'];
        $definitions[] = ['bls_status:validshare', 'percent', 'heading:validshare'];

        return $definitions;
    }

    /**
     * Default columns shown when a new report is created from this datasource.
     *
     * @return string[]
     */
    public function get_default_columns(): array {
        return array_column($this->get_default_column_definitions(), 0);
    }

    /**
     * Default column sorting: by org unit.
     *
     * @return int[]
     */
    public function get_default_column_sorting(): array {
        $orgcolumn = self::get_org_column();
        if ($orgcolumn === null) {
            return [];
        }
        return [$orgcolumn => SORT_ASC];
    }

    /**
     * Add the default columns to a new report, already aggregated and with
     * readable headings, so the report is complete upon creation.
     */
    public function add_default_columns(): void {
        $reportid = $this->get_report_persistent()->get('id');
        $sorting = $this->get_default_column_sorting();
        $availablecolumns = $this->get_columns();

        foreach ($this->get_default_column_definitions() as [$identifier, $aggregation, $heading]) {
            if (!array_key_exists($identifier, $availablecolumns)) {
                continue;
            }

            $column = report::add_report_column($reportid, $identifier);

            $values = ['heading' => get_string($heading, 'taskflowadapter_ksw')];
            if ($aggregation !== null) {
                $values['aggregation'] = $aggregation;
            }
            if (array_key_exists($identifier, $sorting)) {
                $values['sortenabled'] = true;
                $values['sortdirection'] = $sorting[$identifier];
            }
            $column->set_many($values)->update();
        }
    }

    /**
     * Default filters shown in the filter bar.
     *
     * @return string[]
     */
    public function get_default_filters(): array {
        $filters = [];

        $orgcolumn = self::get_org_column();
        if ($orgcolumn !== null) {
            $filters[] = $orgcolumn;
        }
        $filters[] = 'bls_status:valid';
        $filters[] = 'bls_status:expired';
        $filters[] = 'user:fullname';

        return $filters;
    }

    /**
     * Default conditions (always-applied admin conditions), without values so
     * that all users with BLS assignments are counted.
     *
     * @return string[]
     */
    public function get_default_conditions(): array {
        return [
            'bls_status:required',
            'user:suspended',
        ];
    }
}

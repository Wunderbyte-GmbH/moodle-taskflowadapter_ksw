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

namespace taskflowadapter_ksw\reportbuilder\local\entities;

use core\lang_string;
use core_reportbuilder\local\entities\base;
use core_reportbuilder\local\filters\boolean_select;
use core_reportbuilder\local\helpers\database;
use core_reportbuilder\local\helpers\format;
use core_reportbuilder\local\report\column;
use core_reportbuilder\local\report\filter;
use local_taskflow\local\assignment_status\assignment_status_facade;
use stdClass;

/**
 * BLS certificate status entity for Report Builder.
 *
 * Summarises all BLS assignments of a user (assignments whose rule name
 * contains {@see self::RULE_NAME_PATTERN}) into three flags, one row per user:
 *
 * - valid: the user has a BLS assignment with status completed or assigned.
 * - expired: the user has a BLS assignment in any other status, except
 *   "not relevant" and "dropped out".
 * - required: the user has a BLS assignment that is neither "not relevant"
 *   nor "dropped out", i.e. the user is required to hold a BLS certificate.
 *
 * Aggregating the flag columns with "Sum" therefore counts distinct users, and
 * the "Percent" aggregation of the validshare column is the share of users
 * holding a valid certificate among the users who are required to.
 *
 * The datasource has to hand over the alias of the {user} table via
 * set_table_alias() and must add {@see get_bls_join()} as a report join, so
 * only users with at least one BLS assignment are listed.
 *
 * @package    taskflowadapter_ksw
 * @copyright  2026 Wunderbyte GmbH <https://www.wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class bls_status extends base {
    /** @var string Substring identifying BLS rules by their rule name. */
    public const RULE_NAME_PATTERN = 'BLS';

    /** @var string Alias of the derived per-user BLS table. */
    private string $blsalias = '';

    /** @var string Cached join SQL of the derived per-user BLS table. */
    private string $blsjoin = '';

    /**
     * Database tables that this entity uses.
     *
     * @return array
     */
    protected function get_default_tables(): array {
        return [
            'user',
        ];
    }

    /**
     * The default title for this entity.
     *
     * @return lang_string
     */
    protected function get_default_entity_title(): lang_string {
        return new lang_string('entity:blsstatus', 'taskflowadapter_ksw');
    }

    /**
     * Initialise the entity.
     *
     * @return base
     */
    public function initialise(): base {
        $columns = $this->get_all_columns();
        foreach ($columns as $column) {
            $this->add_column($column);
        }

        // All the filters defined by the entity can also be used as conditions.
        $filters = $this->get_all_filters();
        foreach ($filters as $filter) {
            $this->add_filter($filter);
            $this->add_condition($filter);
        }

        return $this;
    }

    /**
     * Return the alias of the derived per-user BLS table.
     *
     * @return string
     */
    public function get_bls_alias(): string {
        if ($this->blsalias === '') {
            $this->blsalias = database::generate_alias();
        }
        return $this->blsalias;
    }

    /**
     * Return the join of the derived per-user BLS table.
     *
     * One row per user having at least one BLS assignment, with the flags
     * hasvalid, hasexpired and required (each 0 or 1).
     *
     * @return string
     */
    public function get_bls_join(): string {
        if ($this->blsjoin !== '') {
            return $this->blsjoin;
        }

        $u = $this->get_table_alias('user');
        $bls = $this->get_bls_alias();
        $a = database::generate_alias();
        $r = database::generate_alias();

        $valid = implode(', ', self::get_valid_statuses());
        $excluded = implode(', ', self::get_excluded_statuses());

        // The pattern is a class constant, so it is safe to inline it. Plain
        // LIKE is used because sql_like() insists on bound parameters, which
        // entity joins cannot carry.
        $rulelike = "{$r}.rulename LIKE '%" . self::RULE_NAME_PATTERN . "%'";

        $this->blsjoin = "JOIN (
                    SELECT {$a}.userid,
                           MAX(CASE WHEN {$a}.status IN ({$valid}) THEN 1 ELSE 0 END) AS hasvalid,
                           MAX(CASE WHEN {$a}.status NOT IN ({$excluded}, {$valid}) THEN 1 ELSE 0 END) AS hasexpired,
                           MAX(CASE WHEN {$a}.status NOT IN ({$excluded}) THEN 1 ELSE 0 END) AS required
                      FROM {local_taskflow_assignment} {$a}
                      JOIN {local_taskflow_rules} {$r} ON {$r}.id = {$a}.ruleid
                     WHERE {$rulelike}
                  GROUP BY {$a}.userid
                  ) {$bls} ON {$bls}.userid = {$u}.id";

        return $this->blsjoin;
    }

    /**
     * Statuses counting as a valid BLS certificate: completed and (re-)assigned.
     *
     * @return int[]
     */
    public static function get_valid_statuses(): array {
        return [
            assignment_status_facade::get_status_identifier('completed'),
            assignment_status_facade::get_status_identifier('assigned'),
        ];
    }

    /**
     * Statuses of users who are not required to hold a BLS certificate.
     *
     * @return int[]
     */
    public static function get_excluded_statuses(): array {
        return [
            assignment_status_facade::get_status_identifier('notrelevant'),
            assignment_status_facade::get_status_identifier('droppedout'),
        ];
    }

    /**
     * Returns list of all available columns.
     *
     * @return column[]
     */
    protected function get_all_columns(): array {
        $bls = $this->get_bls_alias();
        $join = $this->get_bls_join();
        $columns = [];

        // Shown as Yes/No per user; when aggregated (Sum) the number of users.
        $flagcallback = static function ($value, stdClass $row, $arguments, ?string $aggregation) {
            if ($aggregation !== null) {
                return $value;
            }
            if ($value === null) {
                return '';
            }
            return format::boolean_as_text((bool) $value);
        };

        $flags = [
            'valid' => ['hasvalid', new lang_string('column:valid', 'taskflowadapter_ksw')],
            'expired' => ['hasexpired', new lang_string('column:expired', 'taskflowadapter_ksw')],
            'required' => ['required', new lang_string('column:required', 'taskflowadapter_ksw')],
        ];
        foreach ($flags as $name => [$field, $title]) {
            $columns[] = (new column(
                $name,
                $title,
                $this->get_entity_name()
            ))
                ->add_joins($this->get_joins())
                ->add_join($join)
                ->set_type(column::TYPE_INTEGER)
                ->add_field("{$bls}.{$field}")
                ->set_is_sortable(true)
                ->add_callback($flagcallback);
        }

        // Valid certificate among users required to hold one; NULL for the
        // others, so the "Percent" aggregation only averages required users.
        $columns[] = (new column(
            'validshare',
            new lang_string('column:validshare', 'taskflowadapter_ksw'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->add_join($join)
            ->set_type(column::TYPE_BOOLEAN)
            ->add_field("CASE WHEN {$bls}.required = 1 THEN {$bls}.hasvalid ELSE NULL END", 'validshare')
            ->set_is_sortable(true)
            ->add_callback(static function ($value, stdClass $row, $arguments, ?string $aggregation) {
                if ($aggregation !== null) {
                    return $value;
                }
                if ($value === null) {
                    return '';
                }
                return format::boolean_as_text((bool) $value);
            });

        return $columns;
    }

    /**
     * Returns list of all available filters.
     *
     * @return filter[]
     */
    protected function get_all_filters(): array {
        $bls = $this->get_bls_alias();
        $join = $this->get_bls_join();
        $filters = [];

        $flags = [
            'valid' => ['hasvalid', new lang_string('column:valid', 'taskflowadapter_ksw')],
            'expired' => ['hasexpired', new lang_string('column:expired', 'taskflowadapter_ksw')],
            'required' => ['required', new lang_string('column:required', 'taskflowadapter_ksw')],
        ];
        foreach ($flags as $name => [$field, $title]) {
            $filters[] = (new filter(
                boolean_select::class,
                $name,
                $title,
                $this->get_entity_name(),
                "{$bls}.{$field}"
            ))
                ->add_joins($this->get_joins())
                ->add_join($join);
        }

        return $filters;
    }
}

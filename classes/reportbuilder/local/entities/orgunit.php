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
use core_reportbuilder\local\filters\text;
use core_reportbuilder\local\helpers\database;
use core_reportbuilder\local\report\column;
use core_reportbuilder\local\report\filter;
use stdClass;

/**
 * Organisational unit entity for Report Builder.
 *
 * The KSW adapter splits the organisation path of a user into the custom user
 * profile fields Org1, Org2, Org3, ... (see adapter::map_value()). This entity
 * exposes one column and one filter per such field.
 *
 * Unlike the profile field columns of the core user entity, these columns only
 * select the field value itself (no user ID), so aggregating a report by them
 * groups the rows per org unit instead of per user. Users without a value
 * (typically users who left the organisation) are reported as one group.
 *
 * The datasource has to hand over the alias of the {user} table via
 * set_table_alias().
 *
 * @package    taskflowadapter_ksw
 * @copyright  2026 Wunderbyte GmbH <https://www.wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class orgunit extends base {
    /** @var stdClass[]|null Org profile fields, keyed by column name (org1, org2, ...). */
    private ?array $orgfields = null;

    /** @var string[] Join aliases of the {user_info_data} table, keyed by column name. */
    private array $dataaliases = [];

    /**
     * Database tables that this entity uses.
     *
     * @return array
     */
    protected function get_default_tables(): array {
        return [
            'user',
            'user_info_data',
        ];
    }

    /**
     * The default title for this entity.
     *
     * @return lang_string
     */
    protected function get_default_entity_title(): lang_string {
        return new lang_string('entity:orgunit', 'taskflowadapter_ksw');
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
     * Return the custom user profile fields holding the org unit levels (Org1, Org2, ...).
     *
     * @return stdClass[] Field records (id, shortname, name), keyed by column name (org1, org2, ...), sorted by level
     */
    public static function get_org_fields(): array {
        global $DB;

        $bylevel = [];
        foreach ($DB->get_records('user_info_field', null, 'shortname', 'id, shortname, name') as $field) {
            if (preg_match('/^org(\d+)$/i', $field->shortname, $matches)) {
                $bylevel[(int) $matches[1]] = $field;
            }
        }
        ksort($bylevel);

        $fields = [];
        foreach ($bylevel as $level => $field) {
            $fields['org' . $level] = $field;
        }
        return $fields;
    }

    /**
     * Return the org fields of this entity instance (cached).
     *
     * @return stdClass[]
     */
    private function get_fields(): array {
        if ($this->orgfields === null) {
            $this->orgfields = self::get_org_fields();
        }
        return $this->orgfields;
    }

    /**
     * Return the join of the {user_info_data} table for the given org field.
     *
     * @param string $name Column name (org1, org2, ...)
     * @param stdClass $field Profile field record
     * @return string
     */
    private function get_data_join(string $name, stdClass $field): string {
        $u = $this->get_table_alias('user');
        if (!isset($this->dataaliases[$name])) {
            $this->dataaliases[$name] = database::generate_alias();
        }
        $od = $this->dataaliases[$name];
        $fieldid = (int) $field->id;

        return "LEFT JOIN {user_info_data} {$od}
                       ON {$od}.userid = {$u}.id
                      AND {$od}.fieldid = {$fieldid}";
    }

    /**
     * Returns list of all available columns, one per org level.
     *
     * @return column[]
     */
    protected function get_all_columns(): array {
        $columns = [];

        foreach ($this->get_fields() as $name => $field) {
            $join = $this->get_data_join($name, $field);
            $od = $this->dataaliases[$name];

            // Missing and blank values are unified to an empty string, so they
            // form one group ("left the organisation") when aggregating.
            $columns[] = (new column(
                $name,
                new lang_string('column:orgunit', 'taskflowadapter_ksw', format_string($field->name)),
                $this->get_entity_name()
            ))
                ->add_joins($this->get_joins())
                ->add_join($join)
                ->set_type(column::TYPE_TEXT)
                ->add_field("COALESCE(NULLIF(TRIM({$od}.data), ''), '')", $name)
                ->set_is_sortable(true)
                ->add_callback(static function ($value): string {
                    if ($value === null || $value === '') {
                        return get_string('orgunit:none', 'taskflowadapter_ksw');
                    }
                    return format_string((string) $value);
                });
        }

        return $columns;
    }

    /**
     * Returns list of all available filters, one per org level.
     *
     * @return filter[]
     */
    protected function get_all_filters(): array {
        $filters = [];

        foreach ($this->get_fields() as $name => $field) {
            $join = $this->get_data_join($name, $field);
            $od = $this->dataaliases[$name];

            $filters[] = (new filter(
                text::class,
                $name,
                new lang_string('column:orgunit', 'taskflowadapter_ksw', format_string($field->name)),
                $this->get_entity_name(),
                "{$od}.data"
            ))
                ->add_joins($this->get_joins())
                ->add_join($join);
        }

        return $filters;
    }
}

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

namespace taskflowadapter_ksw\reportbuilder\local\entities;

use core\lang_string;
use core_component;
use core_contentbank\content as contentbank_content;
use core_reportbuilder\local\entities\base;
use core_reportbuilder\local\filters\date;
use core_reportbuilder\local\filters\number;
use core_reportbuilder\local\filters\select;
use core_reportbuilder\local\filters\text;
use core_reportbuilder\local\helpers\format;
use core_reportbuilder\local\report\column;
use core_reportbuilder\local\report\filter;

/**
 * Content bank content entity for Report Builder.
 *
 * Defines columns and filters from the {contentbank_content} table.
 *
 * @package    taskflowadapter_ksw
 * @copyright  2026 Wunderbyte GmbH <https://www.wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class content extends base {
    /**
     * Database tables that this entity uses.
     *
     * @return array
     */
    protected function get_default_tables(): array {
        return [
            'contentbank_content',
        ];
    }

    /**
     * The default title for this entity.
     *
     * @return lang_string
     */
    protected function get_default_entity_title(): lang_string {
        return new lang_string('entity:content', 'taskflowadapter_ksw');
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
     * Returns list of all available columns.
     *
     * @return column[]
     */
    protected function get_all_columns(): array {
        $cb = $this->get_table_alias('contentbank_content');
        $columns = [];

        // Content ID.
        $columns[] = (new column(
            'id',
            new lang_string('content:id', 'taskflowadapter_ksw'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->set_type(column::TYPE_INTEGER)
            ->add_field("{$cb}.id")
            ->set_is_sortable(true);

        // Name.
        $columns[] = (new column(
            'name',
            new lang_string('content:name', 'taskflowadapter_ksw'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->set_type(column::TYPE_TEXT)
            ->add_field("{$cb}.name")
            ->set_is_sortable(true);

        // Content type, e.g. "contenttype_h5p", rendered with the plugin name.
        $columns[] = (new column(
            'contenttype',
            new lang_string('content:contenttype', 'taskflowadapter_ksw'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->set_type(column::TYPE_TEXT)
            ->add_field("{$cb}.contenttype")
            ->set_is_sortable(true)
            ->add_callback(static function ($value): string {
                return self::get_contenttype_name((string) $value);
            });

        // Visibility.
        $columns[] = (new column(
            'visibility',
            new lang_string('content:visibility', 'taskflowadapter_ksw'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->set_type(column::TYPE_INTEGER)
            ->add_field("{$cb}.visibility")
            ->set_is_sortable(true)
            ->add_callback(static function ($value): string {
                if ($value === null) {
                    return '';
                }
                return self::get_visibility_options()[(int) $value] ?? (string) $value;
            });

        // Context ID.
        $columns[] = (new column(
            'contextid',
            new lang_string('content:contextid', 'taskflowadapter_ksw'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->set_type(column::TYPE_INTEGER)
            ->add_field("{$cb}.contextid")
            ->set_is_sortable(true);

        // Timestamp columns.
        $timestamps = [
            'timecreated' => new lang_string('content:timecreated', 'taskflowadapter_ksw'),
            'timemodified' => new lang_string('content:timemodified', 'taskflowadapter_ksw'),
        ];
        foreach ($timestamps as $field => $title) {
            $columns[] = (new column(
                $field,
                $title,
                $this->get_entity_name()
            ))
                ->add_joins($this->get_joins())
                ->set_type(column::TYPE_TIMESTAMP)
                ->add_field("{$cb}.{$field}")
                ->set_is_sortable(true)
                ->add_callback([format::class, 'userdate']);
        }

        return $columns;
    }

    /**
     * Returns list of all available filters.
     *
     * @return filter[]
     */
    protected function get_all_filters(): array {
        $cb = $this->get_table_alias('contentbank_content');
        $filters = [];

        // Numeric filters.
        $numbers = [
            'id' => new lang_string('content:id', 'taskflowadapter_ksw'),
            'contextid' => new lang_string('content:contextid', 'taskflowadapter_ksw'),
        ];
        foreach ($numbers as $field => $title) {
            $filters[] = (new filter(
                number::class,
                $field,
                $title,
                $this->get_entity_name(),
                "{$cb}.{$field}"
            ))
                ->add_joins($this->get_joins());
        }

        // Name.
        $filters[] = (new filter(
            text::class,
            'name',
            new lang_string('content:name', 'taskflowadapter_ksw'),
            $this->get_entity_name(),
            "{$cb}.name"
        ))
            ->add_joins($this->get_joins());

        // Content type, offering the installed content type plugins.
        $filters[] = (new filter(
            select::class,
            'contenttype',
            new lang_string('content:contenttype', 'taskflowadapter_ksw'),
            $this->get_entity_name(),
            "{$cb}.contenttype"
        ))
            ->add_joins($this->get_joins())
            ->set_options_callback(static function (): array {
                $options = [];
                foreach (array_keys(core_component::get_plugin_list('contenttype')) as $plugin) {
                    $contenttype = 'contenttype_' . $plugin;
                    $options[$contenttype] = self::get_contenttype_name($contenttype);
                }
                return $options;
            });

        // Visibility.
        $filters[] = (new filter(
            select::class,
            'visibility',
            new lang_string('content:visibility', 'taskflowadapter_ksw'),
            $this->get_entity_name(),
            "{$cb}.visibility"
        ))
            ->add_joins($this->get_joins())
            ->set_options_callback(static function (): array {
                return self::get_visibility_options();
            });

        // Date filters.
        $dates = [
            'timecreated' => new lang_string('content:timecreated', 'taskflowadapter_ksw'),
            'timemodified' => new lang_string('content:timemodified', 'taskflowadapter_ksw'),
        ];
        foreach ($dates as $field => $title) {
            $filters[] = (new filter(
                date::class,
                $field,
                $title,
                $this->get_entity_name(),
                "{$cb}.{$field}"
            ))
                ->add_joins($this->get_joins());
        }

        return $filters;
    }

    /**
     * Return the display name of a content type plugin, falling back to the raw value.
     *
     * @param string $contenttype Frankenstyle name, e.g. "contenttype_h5p"
     * @return string
     */
    public static function get_contenttype_name(string $contenttype): string {
        if ($contenttype === '') {
            return '';
        }
        if (get_string_manager()->string_exists('pluginname', $contenttype)) {
            return get_string('pluginname', $contenttype);
        }
        return $contenttype;
    }

    /**
     * Return the visibility options, keyed by the stored value.
     *
     * @return string[]
     */
    public static function get_visibility_options(): array {
        return [
            contentbank_content::VISIBILITY_PUBLIC => get_string('visibilitychoicepublic', 'core_contentbank'),
            contentbank_content::VISIBILITY_UNLISTED => get_string('visibilitychoiceunlisted', 'core_contentbank'),
        ];
    }
}

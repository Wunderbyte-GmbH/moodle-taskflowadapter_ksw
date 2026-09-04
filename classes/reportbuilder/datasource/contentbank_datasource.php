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

use core\lang_string;
use core\reportbuilder\local\entities\context;
use core_files\reportbuilder\local\entities\file;
use core_reportbuilder\datasource;
use core_reportbuilder\local\entities\user;
use taskflowadapter_ksw\reportbuilder\local\entities\content;

/**
 * Content bank datasource for Report Builder.
 *
 * One row per content bank item, joined with the file backing the content,
 * the context the content lives in, the user who last modified it and the
 * user who created it.
 *
 * @package    taskflowadapter_ksw
 * @copyright  2026 Wunderbyte GmbH <https://www.wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class contentbank_datasource extends datasource {
    /** @var string Entity name of the creator user entity. */
    public const CREATOR_ENTITY = 'creator';

    /**
     * Return user-friendly datasource name.
     *
     * @return string
     */
    public static function get_name(): string {
        return get_string('datasource:contentbank', 'taskflowadapter_ksw');
    }

    /**
     * Initialise the datasource, define entities and joins.
     */
    protected function initialise(): void {
        $contententity = new content();
        $cb = $contententity->get_table_alias('contentbank_content');
        $this->set_main_table('contentbank_content', $cb);
        $this->add_entity($contententity);

        // File backing the content. Content bank stores it in the "public"
        // file area of the content's context, with the content ID as item ID.
        // Left join, so content without a file is still listed.
        $fileentity = new file();
        $f = $fileentity->get_table_alias('files');
        $this->add_entity($fileentity
            ->add_join("LEFT JOIN {files} {$f}
                               ON {$f}.contextid = {$cb}.contextid
                              AND {$f}.component = 'contentbank'
                              AND {$f}.filearea = 'public'
                              AND {$f}.itemid = {$cb}.id
                              AND {$f}.filename <> '.'"));

        // Context the content belongs to.
        $contextentity = new context();
        $ctx = $contextentity->get_table_alias('context');
        $this->add_entity($contextentity
            ->add_join("LEFT JOIN {context} {$ctx} ON {$ctx}.id = {$cb}.contextid"));

        // User who last modified the content (the "author" of the report).
        $userentity = new user();
        $u = $userentity->get_table_alias('user');
        $this->add_entity($userentity
            ->add_join("LEFT JOIN {user} {$u} ON {$u}.id = {$cb}.usermodified"));

        // User who created the content.
        $creatorentity = (new user())
            ->set_entity_name(self::CREATOR_ENTITY)
            ->set_entity_title(new lang_string('entity:creator', 'taskflowadapter_ksw'));
        $c = $creatorentity->get_table_alias('user');
        $this->add_entity($creatorentity
            ->add_join("LEFT JOIN {user} {$c} ON {$c}.id = {$cb}.usercreated"));

        $this->add_all_from_entities();
    }

    /**
     * Default columns shown when a new report is created from this datasource.
     *
     * @return string[]
     */
    public function get_default_columns(): array {
        return [
            'content:id',
            'content:name',
            'file:name',
            'user:fullname',
            'content:timecreated',
            'content:timemodified',
        ];
    }

    /**
     * Default column sorting.
     *
     * @return int[]
     */
    public function get_default_column_sorting(): array {
        return [
            'content:name' => SORT_ASC,
        ];
    }

    /**
     * Default filters shown in the filter bar.
     *
     * @return string[]
     */
    public function get_default_filters(): array {
        return [
            'content:name',
            'content:contenttype',
            'user:fullname',
            'content:timemodified',
        ];
    }

    /**
     * Default conditions (always-applied admin conditions).
     *
     * @return string[]
     */
    public function get_default_conditions(): array {
        return [
            'content:contextid',
            'content:visibility',
        ];
    }
}

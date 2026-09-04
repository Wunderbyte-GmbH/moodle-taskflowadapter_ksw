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

use context_course;
use context_system;
use core_contentbank_generator;
use core_reportbuilder_generator;
use core_reportbuilder\local\filters\number;
use core_reportbuilder\local\filters\select;
use core_reportbuilder\local\filters\text;
use core_reportbuilder\manager;
use core_reportbuilder\tests\core_reportbuilder_testcase;
use stdClass;

/**
 * Content bank datasource tests.
 *
 * @package    taskflowadapter_ksw
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <https://www.wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class contentbank_datasource_test extends core_reportbuilder_testcase {
    /**
     * Set up.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Create one H5P content bank item backed by a file.
     *
     * @param string $name
     * @param int $userid
     * @param \context $context
     * @param string $fixture File name in h5p/tests/fixtures
     * @return stdClass The content record
     */
    private function create_content(
        string $name,
        int $userid,
        \context $context,
        string $fixture = 'filltheblanks.h5p'
    ): stdClass {
        global $CFG;

        /** @var core_contentbank_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('core_contentbank');
        $records = $generator->generate_contentbank_data(
            'contenttype_h5p',
            1,
            $userid,
            $context,
            false,
            $CFG->dirroot . '/h5p/tests/fixtures/' . $fixture,
            $name
        );
        $record = reset($records);
        // The generator appends a counter to the name.
        $record->name = $name;
        return $record;
    }

    /**
     * Create a report over the datasource with the given columns, filters and conditions.
     *
     * @param string[] $columns
     * @param string[] $filters
     * @param string[] $conditions
     * @return int Report ID
     */
    private function create_report(array $columns, array $filters = [], array $conditions = []): int {
        /** @var core_reportbuilder_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('core_reportbuilder');

        $report = $generator->create_report([
            'name' => 'Content bank',
            'source' => contentbank_datasource::class,
            'default' => 0,
        ]);
        $reportid = (int) $report->get('id');

        $generator->create_column(['reportid' => $reportid, 'uniqueidentifier' => 'content:name', 'sortenabled' => 1]);
        foreach ($columns as $column) {
            $generator->create_column(['reportid' => $reportid, 'uniqueidentifier' => $column]);
        }
        foreach ($filters as $filter) {
            $generator->create_filter(['reportid' => $reportid, 'uniqueidentifier' => $filter]);
        }
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
     * Test the default report: default columns and sorting by name.
     * @covers \taskflowadapter_ksw\reportbuilder\datasource\contentbank_datasource::get_default_columns
     * @covers \taskflowadapter_ksw\reportbuilder\datasource\contentbank_datasource::get_default_column_sorting
     * @covers \taskflowadapter_ksw\reportbuilder\local\entities\content::get_all_columns
     */
    public function test_datasource_default(): void {
        global $DB;

        $user = $this->getDataGenerator()->create_user(['firstname' => 'Content', 'lastname' => 'Author']);
        $context = context_system::instance();

        $second = $this->create_content('Zebra content', (int) $user->id, $context);
        $first = $this->create_content('Apple content', (int) $user->id, $context);

        // Set distinct, known timestamps on the first item.
        $created = strtotime('2026-01-10 10:00');
        $modified = strtotime('2026-02-01 12:30');
        $DB->set_field('contentbank_content', 'timecreated', $created, ['id' => $first->id]);
        $DB->set_field('contentbank_content', 'timemodified', $modified, ['id' => $first->id]);
        $first = $DB->get_record('contentbank_content', ['id' => $first->id], '*', MUST_EXIST);
        $second = $DB->get_record('contentbank_content', ['id' => $second->id], '*', MUST_EXIST);

        /** @var core_reportbuilder_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('core_reportbuilder');
        $report = $generator->create_report([
            'name' => 'Content bank',
            'source' => contentbank_datasource::class,
            'default' => 1,
        ]);

        $content = $this->get_rows((int) $report->get('id'));

        $this->assertEquals([
            [
                $first->id,
                'Apple content',
                'filltheblanks.h5p',
                fullname($user),
                userdate($created),
                userdate($modified),
            ],
            [
                $second->id,
                'Zebra content',
                'filltheblanks.h5p',
                fullname($user),
                userdate($second->timecreated),
                userdate($second->timemodified),
            ],
        ], $content);
    }

    /**
     * Test the content columns, the creator entity and content without a file.
     * @covers \taskflowadapter_ksw\reportbuilder\datasource\contentbank_datasource
     * @covers \taskflowadapter_ksw\reportbuilder\local\entities\content::get_all_columns
     * @covers \taskflowadapter_ksw\reportbuilder\local\entities\content::initialise
     */
    public function test_content_columns(): void {
        global $DB;

        $creator = $this->getDataGenerator()->create_user(['firstname' => 'Creating', 'lastname' => 'User']);
        $modifier = $this->getDataGenerator()->create_user(['firstname' => 'Modifying', 'lastname' => 'User']);
        $context = context_system::instance();

        $withfile = $this->create_content('With file', (int) $creator->id, $context);
        $DB->set_field('contentbank_content', 'usermodified', $modifier->id, ['id' => $withfile->id]);

        // Content whose file was removed.
        $nofile = $this->create_content('Without file', (int) $creator->id, $context);
        get_file_storage()->delete_area_files($context->id, 'contentbank', 'public', $nofile->id);
        $DB->set_field(
            'contentbank_content',
            'visibility',
            \core_contentbank\content::VISIBILITY_UNLISTED,
            ['id' => $nofile->id]
        );

        $reportid = $this->create_report([
            'content:contenttype',
            'content:visibility',
            'content:contextid',
            'file:name',
            'user:fullname',
            'creator:fullname',
        ]);

        $this->assertEquals([
            [
                'With file',
                get_string('pluginname', 'contenttype_h5p'),
                get_string('visibilitychoicepublic', 'core_contentbank'),
                $context->id,
                'filltheblanks.h5p',
                fullname($modifier),
                fullname($creator),
            ],
            [
                'Without file',
                get_string('pluginname', 'contenttype_h5p'),
                get_string('visibilitychoiceunlisted', 'core_contentbank'),
                $context->id,
                '',
                fullname($creator),
                fullname($creator),
            ],
        ], $this->get_rows($reportid));
    }

    /**
     * Test that the context condition restricts the report to one context.
     * @covers \taskflowadapter_ksw\reportbuilder\datasource\contentbank_datasource::get_default_conditions
     * @covers \taskflowadapter_ksw\reportbuilder\local\entities\content::get_default_tables
     */
    public function test_context_condition(): void {
        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $systemcontext = context_system::instance();
        $coursecontext = context_course::instance($course->id);

        $this->create_content('System content', (int) $user->id, $systemcontext);
        $this->create_content('Course content', (int) $user->id, $coursecontext);

        $reportid = $this->create_report(['content:contextid'], [], ['content:contextid']);

        // Without a condition value both items are listed.
        $this->assertCount(2, $this->get_rows($reportid));

        $instance = manager::get_report_from_id($reportid);
        $instance->set_condition_values([
            'content:contextid_operator' => number::EQUAL_TO,
            'content:contextid_value1' => $coursecontext->id,
        ]);

        $this->assertEquals([
            ['Course content', $coursecontext->id],
        ], $this->get_rows($reportid));
    }

    /**
     * Test the name, content type and visibility filters.
     * @covers \taskflowadapter_ksw\reportbuilder\datasource\contentbank_datasource::get_default_filters
     * @covers \taskflowadapter_ksw\reportbuilder\local\entities\content::get_all_filters
     */
    public function test_filters(): void {
        global $DB;

        $user = $this->getDataGenerator()->create_user();
        $context = context_system::instance();

        $this->create_content('Alpha', (int) $user->id, $context);
        $beta = $this->create_content('Beta', (int) $user->id, $context);
        $DB->set_field(
            'contentbank_content',
            'visibility',
            \core_contentbank\content::VISIBILITY_UNLISTED,
            ['id' => $beta->id]
        );

        $reportid = $this->create_report([], ['content:name', 'content:contenttype', 'content:visibility']);

        $this->assertEquals([['Beta']], $this->get_rows($reportid, [
            'content:name_operator' => text::CONTAINS,
            'content:name_value' => 'Bet',
        ]));

        $this->assertEquals([['Alpha'], ['Beta']], $this->get_rows($reportid, [
            'content:contenttype_operator' => select::EQUAL_TO,
            'content:contenttype_value' => 'contenttype_h5p',
        ]));

        $this->assertEquals([['Alpha']], $this->get_rows($reportid, [
            'content:visibility_operator' => select::EQUAL_TO,
            'content:visibility_value' => \core_contentbank\content::VISIBILITY_PUBLIC,
        ]));
    }

    /**
     * Stress test datasource.
     * @covers \taskflowadapter_ksw\reportbuilder\datasource\contentbank_datasource
     * @covers \taskflowadapter_ksw\reportbuilder\local\entities\content
     */
    public function test_stress_datasource(): void {
        $user = $this->getDataGenerator()->create_user();
        $this->create_content('Stress content', (int) $user->id, context_system::instance());

        $this->datasource_stress_test_columns(contentbank_datasource::class);
        $this->datasource_stress_test_columns_aggregation(contentbank_datasource::class);
        $this->datasource_stress_test_conditions(contentbank_datasource::class, 'content:id');
    }
}

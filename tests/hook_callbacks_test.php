<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace taskflowadapter_ksw;

use advanced_testcase;
use grade_item;
use moodle_page;

/**
 * Tests for the hook callbacks of the KSW taskflow adapter.
 *
 * @package     taskflowadapter_ksw
 * @copyright   2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers      \taskflowadapter_ksw\hook_callbacks
 */
final class hook_callbacks_test extends advanced_testcase {
    /**
     * Creates a course with a module and returns a page set up for it.
     *
     * @param string $modname
     * @param string $pagetype
     * @param float $gradepass
     * @return moodle_page
     */
    private function create_module_page(string $modname, string $pagetype, float $gradepass): moodle_page {
        global $CFG;
        require_once($CFG->libdir . '/gradelib.php');

        $course = $this->getDataGenerator()->create_course();
        $instance = $this->getDataGenerator()->create_module($modname, ['course' => $course->id]);
        $cm = get_coursemodule_from_instance($modname, $instance->id);

        if ($gradepass > 0) {
            $gradeitem = grade_item::fetch([
                'itemtype' => 'mod',
                'itemmodule' => $modname,
                'iteminstance' => $instance->id,
                'courseid' => $course->id,
            ]);
            $gradeitem->gradepass = $gradepass;
            $gradeitem->update();
        }

        $page = new moodle_page();
        $page->set_cm($cm, $course);
        $page->set_pagetype($pagetype);
        return $page;
    }

    /**
     * The pass limits are only returned on hvp view pages with a grade to pass and the setting enabled.
     *
     * @return void
     */
    public function test_get_hvp_pass_limits(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $withpass = $this->create_module_page('hvp', 'mod-hvp-view', 8.0);
        $withoutpass = $this->create_module_page('hvp', 'mod-hvp-view', 0.0);
        $otherpage = $this->create_module_page('hvp', 'mod-hvp-grade', 8.0);

        // Setting disabled by default.
        $this->assertNull(hook_callbacks::get_hvp_pass_limits($withpass));

        set_config('hvpresultmodal', 1, 'taskflowadapter_ksw');
        $this->assertSame([8.0, 10.0], hook_callbacks::get_hvp_pass_limits($withpass));
        $this->assertNull(hook_callbacks::get_hvp_pass_limits($withoutpass));
        $this->assertNull(hook_callbacks::get_hvp_pass_limits($otherpage));

        $this->setGuestUser();
        $this->assertNull(hook_callbacks::get_hvp_pass_limits($withpass));
    }
}

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

use core\hook\output\before_footer_html_generation;
use grade_item;

/**
 * Hook callbacks for the KSW taskflow adapter.
 *
 * @package     taskflowadapter_ksw
 * @copyright   2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class hook_callbacks {
    /**
     * Loads the pass/fail modal on mod_hvp view pages of activities with a grade to pass.
     *
     * @param before_footer_html_generation $hook
     * @return void
     */
    public static function before_footer_html_generation(before_footer_html_generation $hook): void {
        global $PAGE;

        $limits = self::get_hvp_pass_limits($PAGE);
        if ($limits === null) {
            return;
        }
        $PAGE->requires->js_call_amd('taskflowadapter_ksw/hvpresult', 'init', $limits);
    }

    /**
     * Returns [gradepass, grademax] if the pass/fail modal applies to the given page, null otherwise.
     *
     * @param \moodle_page $page
     * @return array|null
     */
    public static function get_hvp_pass_limits(\moodle_page $page): ?array {
        global $CFG;

        if (!get_config('taskflowadapter_ksw', 'hvpresultmodal')) {
            return null;
        }
        // No empty() here: moodle_page exposes cm via __get() only, so empty() is always true.
        $cm = $page->cm;
        if ($page->pagetype !== 'mod-hvp-view' || $cm === null) {
            return null;
        }
        if (!isloggedin() || isguestuser()) {
            return null;
        }

        require_once($CFG->libdir . '/gradelib.php');
        $gradeitem = grade_item::fetch([
            'itemtype' => 'mod',
            'itemmodule' => 'hvp',
            'iteminstance' => $cm->instance,
            'itemnumber' => 0,
            'courseid' => $cm->course,
        ]);
        if (!$gradeitem || $gradeitem->gradepass <= 0 || $gradeitem->grademax <= 0) {
            return null;
        }
        return [(float)$gradeitem->gradepass, (float)$gradeitem->grademax];
    }
}

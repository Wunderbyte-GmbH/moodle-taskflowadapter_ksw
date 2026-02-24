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

/**
 * Library of common module functions and constants.
 *
 * @package     taskflowadapter_ksw
 * @copyright   2025 Wunderbyte GmbH <georg.maisser@wunderbyte.at>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Navbar output quickaccess.
 *
 * @param \renderer_base $renderer
 *
 * @return string
 *
 */
function taskflowadapter_ksw_render_navbar_output(\renderer_base $renderer) {
    global $OUTPUT, $CFG, $USER;
    require_once($CFG->dirroot . '/lib/moodlelib.php');

    if (!isloggedin()) {
        return "";
    }

    $context = context_system::instance();
    $templatedata = [];

    $urluser = new moodle_url('/local/taskflow#user-pane-' . $USER->id . '-');
    $params = ['completed' => 1, 'filter' => 1, 'typefilter' => 1];
    $courselist = new moodle_url('/mod/booking/mybookings.php', $params);

    $items = [
        ['label' => get_string('mylearningprofile', 'taskflowadapter_ksw'), 'link' => $urluser->out(false)],
        ['label' => get_string('mycourses', 'taskflowadapter_ksw'), 'link' => $courselist->out(false)],
    ];

    if (has_capability('local/taskflow:issupervisor', $context)) {
        $urlsupervisor = new moodle_url('/local/taskflow/');
        $items[] = ['label' => get_string('supervisor', 'taskflowadapter_ksw'), 'link' => $urlsupervisor->out(false)];
    }

    if (user_has_role_assignment($USER->id, 18, $context->id)) {
        $course1 = new moodle_url('/course/view.php?id=9');
        $course2 = new moodle_url('/course/view.php?id=8');
        $course3 = new moodle_url('/course/view.php?id=29');
        $items[] = ['label' => get_string('contentdatabase', 'taskflowadapter_ksw'), 'link' => $course1];
        $items[] = ['label' => get_string('trainingcourse', 'taskflowadapter_ksw'), 'link' => $course2];
        $items[] = ['label' => get_string('archive', 'taskflowadapter_ksw'), 'link' => $course3];
    }

    $templatedata['dropdown'] = [
        'id' => 'main-dropdown',
        'items' => $items,
    ];

    return $OUTPUT->render_from_template('taskflowadapter_ksw/naventry', $templatedata);
}

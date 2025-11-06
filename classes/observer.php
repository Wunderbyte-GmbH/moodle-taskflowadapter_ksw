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
 * Observer for given events.
 *
 * @package   taskflowadapter_ksw
 * @author    Georg Maißer
 * @copyright 2023 Your Name
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace taskflowadapter_ksw;

use local_taskflow\event\assignment_completed;
use local_taskflow\local\assignments\assignment;
use tool_certificate\template;

/**
 * Observer class that handles user events.
 */
class observer {
    /**
     * [Description for assignment_completed]
     *
     * @param mixed $event
     */
    public static function assignment_completed($event) {
        global $DB;
        $data = $event->get_data($event);
        $search = '%BLS%';
        $sql = "SELECT *
                FROM {local_taskflow_rules}
                WHERE rulename LIKE :rulename";

        $params = ['rulename' => $search];
        $blsrules = $DB->get_records_sql($sql, $params);
        $assignment = new assignment($data['other']['assignmentid']);
        foreach ($blsrules as $rule) {
            if ($assignment->ruleid === $rule->id) {
                $certificateid = 5;
                $template = template::instance($certificateid);
                $template->issue_certificate();
                $id = $template->issue_certificate(
                    $assignment->userid,
                    0,
                );
                // Get the issue and create the PDF.
                $issue = $DB->get_record('tool_certificate_issues', ['id' => $id]);
                $pdf = $template->create_issue_file($issue, false);
            }
        }
    }
}

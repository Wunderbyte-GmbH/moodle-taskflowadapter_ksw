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
 * Drops out the assignments of users who left the organisation.
 *
 * @package   taskflowadapter_ksw
 * @copyright 2026 Wunderbyte GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace taskflowadapter_ksw\local;

use local_taskflow\local\assignment_status\assignment_status_facade;
use local_taskflow\local\assignments\types\standard_assignment;

/**
 * Drops out the assignments of users who left the organisation.
 */
class leaver_assignments {
    /** @var string[] Status types that are dropped out. Paused, not relevant and completed stay as they are. */
    private const OPENSTATUSTYPES = [
        'planned',
        'assigned',
        'enrolled',
        'prolonged',
        'partially_completed',
        'overdue',
        'reprimand',
        'sanction',
    ];

    /**
     * Returns the assignments of a user which would be dropped out.
     *
     * @param int $userid
     * @return array assignment records
     */
    public static function get_open_assignments(int $userid): array {
        global $DB;
        $statuses = array_map(
            fn($type) => assignment_status_facade::get_status_identifier($type),
            self::OPENSTATUSTYPES
        );
        [$insql, $params] = $DB->get_in_or_equal($statuses, SQL_PARAMS_NAMED, 'status');
        return $DB->get_records_select(
            'local_taskflow_assignment',
            "userid = :userid AND status $insql",
            $params + ['userid' => $userid],
            'id ASC'
        );
    }

    /**
     * Sets the planned and open assignments of a user to dropped out.
     *
     * @param int $userid
     * @return array one entry per changed assignment with id, ruleid, oldstatus and newstatus
     */
    public static function drop_open_assignments(int $userid): array {
        $droppedout = assignment_status_facade::get_status_identifier('droppedout');
        $changed = [];
        foreach (self::get_open_assignments($userid) as $assignment) {
            $oldstatus = (int)$assignment->status;
            assignment_status_facade::change_status($assignment, $droppedout);
            if ((int)$assignment->status !== $droppedout) {
                // The status is excluded for this adapter.
                continue;
            }
            $assignment->timemodified = time();
            standard_assignment::update_or_create_assignment((object)$assignment);
            $changed[] = [
                'id' => (int)$assignment->id,
                'ruleid' => (int)$assignment->ruleid,
                'oldstatus' => $oldstatus,
                'newstatus' => $droppedout,
            ];
        }
        return $changed;
    }
}

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
 * @author    David Ala
 * @copyright 2025 Wunderbyte GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace taskflowadapter_tuines;

use local_taskflow\local\personas\moodle_users\moodle_user_factory;
use local_taskflow\local\personas\unit_members\moodle_unit_member_facade;
use local_taskflow\local\units\organisational_unit_factory;

/**
 * Observer class that handles user events.
 */
class observer {
    /**
     * Triggered when a user profile field is deleted.
     *
     * @param \core\event\user_info_field_deleted $event
     */
    public static function user_info_field_deleted(\core\event\user_info_field_deleted $event) {
        global $DB;

        // Get the ID of the deleted field.
        $fieldid = $event->objectid;

        // Get full record to access the shortname before it’s fully gone.
        if ($record = $event->get_record_snapshot('user_info_field', $fieldid)) {
            $shortname = $record->shortname;
            // Unset the configuration for the taskflowadapter_ksw.
            unset_config('taskflowadapter_ksw', $shortname);
        }
    }
    /**
     * Triggered when userdata was updated or created.
     *
     * @param \core\event\base $event
     */
    public static function user_updated_created(\core\event\base $event) {
        $data = $event->get_data();
        $allaffectedusers = [$data['relateduserid']];
        $userrepo = new moodle_user_factory();
        $unitrepo = new organisational_unit_factory();
        $unitmemberrepo = new moodle_unit_member_facade();
        $adapter = new adapter("", $userrepo, $unitmemberrepo, $unitrepo);
        $adapter->set_users($allaffectedusers);
        $adapter->process_incoming_data();
    }
}

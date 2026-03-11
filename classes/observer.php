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

use core\event\user_updated;
use core_user;
use local_taskflow\event\assignment_completed;
use local_taskflow\local\assignments\assignment;
use mod_booking\booking_answers\booking_answers;
use mod_booking\singleton_service;
use tool_certificate\template;

/**
 * Observer class that handles user events.
 */
class observer {
    /**
     * Handles the user_updated event.
     *
     * @param user_updated $event
     *
     * @return void
     *
     */
    public static function user_updated(user_updated $event) {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/booking/lib.php');
        $data = $event->get_data();
        $user = core_user::get_user($data['relateduserid']);
        if ($user->suspended == 0) {
            return;
        }
        $now = time();
        $sql = "SELECT {booking_answers}.id, {booking_answers}.optionid, {booking_options}.json
        FROM {booking_answers}
        JOIN {booking_options} ON {booking_answers}.optionid = {booking_options}.id
        WHERE userid = :userid
        AND waitinglist = :waitinglist
        AND {booking_options}.coursestarttime > :currenttime";

        $answers = $DB->get_records_sql(
            $sql,
            [
            'userid' => $user->id,
            'waitinglist' => MOD_BOOKING_STATUSPARAM_BOOKED,
            'currenttime' => $now,
            ]
        );

        foreach ($answers as $answer) {
            $optionjson = json_decode($answer->json ?? '');
            // Keep only options where selflearningcourse is 0.
            if (
                isset($optionjson->selflearningcourse) && $optionjson->selflearningcourse != 0
            ) {
                continue;
            }

            $settings = singleton_service::get_instance_of_booking_option_settings($answer->optionid);
            $option = singleton_service::get_instance_of_booking_option($settings->cmid, $answer->optionid);
            $option->user_delete_response($user->id);
        }
    }
}

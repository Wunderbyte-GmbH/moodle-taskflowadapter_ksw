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
 * Shortcodes for taskflowadapter_ksw
 *
 * @package taskflowadapter_ksw
 * @subpackage db
 * @since Moodle 4.1
 * @copyright 2023 Georg Maißer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace taskflowadapter_ksw;

use mod_booking\singleton_service;

/**
 * Deals with local_shortcodes regarding booking.
 */
class shortcodes {
    /**
     * This shortcode shows a list of booking options, which have a booking customfield...
     * ... with the shortname "recommendedin" and the value set to the shortname of the course...
     * ... in which they should appear.
     *
     * @param string $shortcode
     * @param array $args
     * @param string|null $content
     * @param object $env
     * @param Closure $next
     * @return string
     */
    public static function bookingoptiondescription($shortcode, $args, $content, $env, $next) {
        $values = singleton_service::get_temp_values_for_certificates();
        if (empty($values)) {
            return "PLACEHOLDER";
        }
        $bookingoption = singleton_service::get_instance_of_booking_option_settings($values[1]);
        return $bookingoption->description;
    }
}

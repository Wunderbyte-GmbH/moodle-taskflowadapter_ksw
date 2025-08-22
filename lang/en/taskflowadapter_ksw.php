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
 * This file contains language strings for the taskflow adapter.
 *
 * @package     taskflowadapter_ksw
 * @copyright   2025 Wunderbyte GmbH
 * @author      David Ala
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
defined('MOODLE_INTERNAL') || die();

$string['apisettings'] = "KSW API Settings";
$string['apisettings_desc'] = "Configure taskflow key-value pairs.";
$string['bookingoptiondescription'] = "Pretty Description for certificate renering.";
$string['choose'] = "Choose...";
$string['enter_value'] = 'Enter a suitable JSON key for this setting';
$string['function'] = 'Assign function to userprofilefield: ';
$string['internalid'] = 'Internal ID';
$string['jsonkey'] = 'JSON key for userprofilefield: ';
$string['ksw'] = "KSW API";
$string['lessfunctions'] = '<div class="alert alert-danger" role="alert">Nicht alle Funktionen wurden beim letzten Speichern ausgewählt. Dies kann zu Fehlern führen.</div>';
$string['manyfunctions'] = '<div class="alert alert-danger" role="alert">Funktionen wurden mehrfach ausgewählt beim letzten Speichern. Dies kann zu Fehlern führen.</div>';
$string['mappingdescription'] = 'Taskflow key-value pair explanation';
$string['mappingdescription_desc'] = 'This creates the mapping. The upper field indicates which JSON field is linked to the user profile field. The lower field indicates which function this field represents. Not every user profile field must have a function.';
$string['necessaryuserprofilefields'] = "User profile fields required to be filled in for Taskflow";
$string['necessaryuserprofilefieldsdesc'] = "User profile fields that are not allowed to be empty for the user to be considered in a Taskflow update. If the selected fields are empty, user updates will not be processed in Wunderbyte Taskflow. Leave this setting empty if no fields are required.";
$string['pluginname'] = "KSW";
$string['set:function'] = 'Select a function';
$string['subplugintype_taskflowadapter_plural'] = 'Taskflow adapter extensions';

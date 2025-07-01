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

/**
 * Class taskflowadapter_winterthour.
 *
 * @package     taskflowadapter_winterthour
 * @copyright   2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @author      David Ala
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace taskflowadapter_winterthour;

use admin_setting_configselect;
use admin_setting_configtext;
use admin_setting_description;
use local_taskflow\plugininfo\taskflowadapter;

/**
 * Class for the winterthour taskflow adapter.
 */
class taskflowadapter_winterthour extends taskflowadapter {
    /**
     * [Description for load_settings]
     *
     * @param \part_of_admin_tree $adminroot
     * @param mixed $parentnodename
     * @param mixed $hassiteconfig
     *
     * @return [type]
     *
     */
    public function load_settings(\part_of_admin_tree $adminroot, $parentnodename, $hassiteconfig) {
        if (!$hassiteconfig) {
            return;
        }
        $componentname = 'taskflowadapter_winterthour';
        $allusercustomfields = profile_get_custom_fields();
        $usercustomfields = [];
        $settings = $adminroot->locate($parentnodename);
        // @TODO: PLACEHOLDER FOR NOW make it constants.
        $cohortlabelsettings = [
           'translator_target_group_name' => get_string('name', $componentname),
           'translator_target_group_description' => get_string('description', $componentname),
           'translator_target_group_unitid' => get_string('unit', $componentname),
        ];

        $userlabelsettings = [
            "" => "",
            'translator_user_firstname' => get_string('firstname', $componentname),
            'translator_user_lastname' => get_string('lastname', $componentname),
            'translator_user_email' => get_string('email', $componentname),
            'translator_user_units' => get_string('targetgroup', $componentname),
            'translator_user_orgunit' => get_string('unit', $componentname),
            'translator_user_supervisor' => get_string('supervisor', $componentname),
            'translator_user_long_leave' => get_string('longleave', $componentname),
            'translator_user_end' => get_string('contractend', $componentname),
            'translator_user_internalid' => get_string('internalid', $componentname),
        ];

        if (!empty($allusercustomfields)) {
            foreach ($allusercustomfields as $userprofilefield) {
                $usercustomfields["{$userprofilefield->shortname}"] = $userprofilefield->name;
            }
        }
        $validation = 1;
        foreach ($usercustomfields as $key => $label) {
            if (!empty(get_config($componentname, $key . '_translator'))) {
                $validation++;
            }
        }
        if ($validation < count($userlabelsettings)) {
            $settings->add(
                new admin_setting_description(
                    $componentname . '/lessfunctions',
                    '',
                    get_string('lessfunctions', $componentname)
                )
            );
        }
        if ($validation > count($userlabelsettings)) {
            $settings->add(
                new admin_setting_description(
                    $componentname . '/manyfunctions',
                    '',
                    get_string('manyfunctions', $componentname)
                )
            );
        }

        foreach ($usercustomfields as $key => $label) {
            $settings->add(
                new admin_setting_configtext(
                    $componentname . '/' . $key,
                    get_string('jsonkey', $componentname) . $label,
                    get_string('enter_value', $componentname),
                    '',
                    PARAM_TEXT
                )
            );
             $settings->add(
                 new admin_setting_configselect(
                     $componentname . '/' . $key . '_translator',
                     get_string('function', $componentname) . $label,
                     get_string('set:function', $componentname),
                     "",
                     $userlabelsettings,
                 )
             );
        }
        foreach ($cohortlabelsettings as $key => $label) {
            $settings->add(
                new admin_setting_configtext(
                    $componentname . '/' . $key,
                    $label,
                    get_string('enter_value', $componentname),
                    '',
                    PARAM_TEXT
                )
            );
        }
    }
}

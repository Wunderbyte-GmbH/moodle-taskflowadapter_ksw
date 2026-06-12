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
 * Class taskflowadapter_ksw.
 *
 * @package     taskflowadapter_ksw
 * @copyright   2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @author      David Ala
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace taskflowadapter_ksw;

use admin_setting_configmultiselect;
use admin_setting_configselect;
use admin_setting_configtext;
use admin_setting_heading;
use local_taskflow\local\external_adapter\external_api_base;
use local_taskflow\plugininfo\taskflowadapter;
use local_taskflow\taskflow_stringmanager;

/**
 * Class for the KSW taskflow adapter.
 */
class taskflowadapter_ksw extends taskflowadapter {
    /**
     * COMPONENTNAME
     *
     * @var string
     */
    private const COMPONENTNAME = 'taskflowadapter_ksw';
    /**
     * Loads Subpluginsettings into local_taskflow
     *
     * @param \part_of_admin_tree $adminroot
     * @param mixed $parentnodename
     * @param mixed $hassiteconfig
     *
     * @return void
     *
     */
    public function load_settings(\part_of_admin_tree $adminroot, $parentnodename, $hassiteconfig) {
        if (!$hassiteconfig) {
            return;
        }
        $allusercustomfields = profile_get_custom_fields();
        $usercustomfields = [];
        $settings = $adminroot->locate($parentnodename);
        $userlabelsettings = parent::return_user_label_settings();
        $cohortlabelsettings = parent::return_target_label_settings();

        $settings->add(
            new admin_setting_heading(
                self::COMPONENTNAME . '_api_settings',
                get_string('apisettings', self::COMPONENTNAME),
                taskflow_stringmanager::get_string('apisettings_desc')
            )
        );
        if (!empty($allusercustomfields)) {
            foreach ($allusercustomfields as $userprofilefield) {
                $usercustomfields["{$userprofilefield->shortname}"] = $userprofilefield->name;
            }
        }
        // Returns the description for the Admin how the mapping works.

        parent::check_functions_usage($usercustomfields, self::COMPONENTNAME, $settings);
        parent::return_setting_special_treatment_fields($settings, self::COMPONENTNAME);
        foreach ($usercustomfields as $key => $label) {
            $settings->add(
                new admin_setting_configtext(
                    self::COMPONENTNAME . '/' . 'translator_user_' . $key,
                    taskflow_stringmanager::get_string('jsonkey') . $label,
                    taskflow_stringmanager::get_string('enter_value'),
                    '',
                    PARAM_TEXT
                )
            );
             $settings->add(
                 new admin_setting_configselect(
                     self::COMPONENTNAME . '/' . $key,
                     taskflow_stringmanager::get_string('function') . $label,
                     taskflow_stringmanager::get_string('set:function'),
                     "",
                     $userlabelsettings,
                 )
             );
        }
        foreach ($cohortlabelsettings as $key => $label) {
            $settings->add(
                new admin_setting_configtext(
                    self::COMPONENTNAME . '/' . $key,
                    taskflow_stringmanager::get_string('jsonkey') . $label,
                    taskflow_stringmanager::get_string('enter_value'),
                    '',
                    PARAM_TEXT
                )
            );
        }
        if (adapter::is_allowed_to_react_on_user_events()) {
            $settings->add(new admin_setting_configmultiselect(
                self::COMPONENTNAME . "/necessaryuserprofilefields",
                taskflow_stringmanager::get_string('necessaryuserprofilefields'),
                taskflow_stringmanager::get_string('necessaryuserprofilefieldsdesc'),
                [],
                $usercustomfields
            ));
        }
        $settings->add(
            new admin_setting_configtext(
                self::COMPONENTNAME . "/blscertificatekey",
                taskflow_stringmanager::get_string('blscertificatekey') . $label,
                taskflow_stringmanager::get_string('blscertificatekey_desc'),
                '',
                PARAM_TEXT
            )
        );
    }

    /**
     * Get the instance of the class for a specific ID.
     * @param int $userid
     * @return stdClass
     */
    public static function get_supervisor_for_user(int $userid) {
        global $DB;

        $fieldname = external_api_base::return_shortname_for_functionname(parent::TRANSLATOR_USER_SUPERVISOR);
        if (empty($fieldname)) {
            return (object)[];
        }

        $supervisorfieldid = $DB->get_field('user_info_field', 'id', ['shortname' => $fieldname], IGNORE_MISSING);
        if (empty($supervisorfieldid)) {
            return (object)[];
        }

        $supervisorid = $DB->get_field('user_info_data', 'data', [
            'userid' => $userid,
            'fieldid' => $supervisorfieldid,
        ], IGNORE_MISSING);

        if (empty($supervisorid)) {
            return (object)[];
        }
        return $DB->get_record('user', ['id' => (int)$supervisorid], '*', IGNORE_MISSING);
    }
}

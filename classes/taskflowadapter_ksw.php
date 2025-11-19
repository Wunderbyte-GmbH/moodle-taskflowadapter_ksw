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
                get_string('apisettings_desc', self::COMPONENTNAME)
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
                    get_string('jsonkey', self::COMPONENTNAME) . $label,
                    get_string('enter_value', self::COMPONENTNAME),
                    '',
                    PARAM_TEXT
                )
            );
             $settings->add(
                 new admin_setting_configselect(
                     self::COMPONENTNAME . '/' . $key,
                     get_string('function', self::COMPONENTNAME) . $label,
                     get_string('set:function', self::COMPONENTNAME),
                     "",
                     $userlabelsettings,
                 )
             );
        }
        foreach ($cohortlabelsettings as $key => $label) {
            $settings->add(
                new admin_setting_configtext(
                    self::COMPONENTNAME . '/' . $key,
                    get_string('jsonkey', self::COMPONENTNAME) . $label,
                    get_string('enter_value', self::COMPONENTNAME),
                    '',
                    PARAM_TEXT
                )
            );
        }
        if (adapter::is_allowed_to_react_on_user_events()) {
            $settings->add(new admin_setting_configmultiselect(
                self::COMPONENTNAME . "/necessaryuserprofilefields",
                get_string('necessaryuserprofilefields', self::COMPONENTNAME),
                get_string('necessaryuserprofilefieldsdesc', self::COMPONENTNAME),
                [],
                $usercustomfields
            ));
        }
        $settings->add(
            new admin_setting_configtext(
                self::COMPONENTNAME . "/blscertificatekey",
                get_string('blscertificatekey', self::COMPONENTNAME) . $label,
                get_string('blscertificatekey_desc', self::COMPONENTNAME),
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

        $sql = "SELECT su.*
                FROM {user} u
                JOIN {user_info_data} uid ON uid.userid = u.id
                JOIN {user_info_field} uif ON uif.id = uid.fieldid
                JOIN {user} su ON su.id = " . $DB->sql_cast_char2int('uid.data') . "
                WHERE u.id = :userid
                AND uif.shortname = :supervisor";
        $parms = [
            'userid' => $userid,
            'supervisor' => $fieldname,
        ];
        return $DB->get_record_sql($sql, $parms, IGNORE_MISSING);
    }
}

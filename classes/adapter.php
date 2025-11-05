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
 * Unit class to manage users.
 *
 * @package taskflowadapter_ksw
 * @author Georg Maißer
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace taskflowadapter_ksw;

use DateTime;
use local_taskflow\local\assignments\assignments_facade;
use local_taskflow\local\external_adapter\external_api_interface;
use local_taskflow\local\external_adapter\external_api_base;
use local_taskflow\local\personas\moodle_users\moodle_user_factory;
use local_taskflow\local\personas\moodle_users\types\moodle_user;
use local_taskflow\local\personas\unit_members\types\unit_member;
use local_taskflow\local\supervisor\supervisor;
use local_taskflow\local\units\organisational_unit_factory;
use local_taskflow\local\units\unit_relations;
use local_taskflow\plugininfo\taskflowadapter;
use stdClass;

defined('MOODLE_INTERNAL') || die();
require_once($CFG->dirroot . '/cohort/lib.php');
/**
 * Class unit
 *
 * @author Georg Maißer
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class adapter extends external_api_base implements external_api_interface {
    /**
     * Private constructor to prevent direct instantiation.
     */
    public function process_incoming_data() {
        // Left in there for units.
        $updatedentities = [
            'relationupdate' => [],
            'unitmember' => [],
        ];
        if (!empty(get_object_vars($this->externaldata))) {
            external_api_base::$importing = true;
            $this->translate_users();
        }
        $this->create_or_update_units($updatedentities);
        $this->create_or_update_users();
        $this->create_or_update_supervisor();

        $this->save_all_user_infos($this->users);

        // Left in there for units.
        self::trigger_unit_relation_updated_events($updatedentities['relationupdate']);
        self::trigger_unit_member_updated_events($updatedentities['unitmember']);
        external_api_base::$importing = false;
    }

    /**
     * Private constructor to prevent direct instantiation.
     * @param stdClass $user
     * @param array $updatedentities
     * @return int
     */
    private function generate_units_data(stdClass $user, $updatedentities) {
        $organisations = $this->build_organisation_path($user);
        $unit = null;
        $parent = null;
        $unitinstance = null;
        foreach ($organisations as $organisation) {
            $unit = (object) [
                'name' => $organisation,
                'parent' => $parent,
                'parentunitid' => $parentunitid ?? null,
            ];
            $unitinstance = organisational_unit_factory::create_unit($unit);
            // Left in there for units.
            $parentunitid = $unitinstance->get_id();
            if ($unitinstance instanceof unit_relations) {
                $updatedentities['relationupdate'][$unitinstance->get_id()][] = [
                    'child' => $unitinstance->get_childid(),
                    'parent' => $unitinstance->get_parentid(),
                ];
                $parentunitid = $unitinstance->get_childid();
            }
            $parent = $unit->name;
        }
        if ($unitinstance instanceof unit_relations) {
            return $unitinstance->get_childid();
        }
        if (empty($unitinstance)) {
            return 0;
        }
        return $unitinstance->get_id() ?? 0;
    }

     /**
      * Private constructor to prevent direct instantiation.
      * @param stdClass $user
      * @return bool
      */
    private function contract_ended($user) {
        $storedenddate = $this->return_value_for_functionname(
            taskflowadapter::TRANSLATOR_USER_CONTRACTEND,
            $user
        ) ?? '';
        $enddate = DateTime::createFromFormat(
            'Y-m-d',
            $storedenddate
        );

        if (empty($storedenddate)) {
            return false;
        }
        $this->dates_validation($enddate, $storedenddate);

        $now = new DateTime();
        if (
            $enddate &&
            $enddate < $now
        ) {
            return true;
        }
        return false;
    }

    /**
     * This function maps values to unix timestamps.
     * This can be overwritten in taskflowadapters to match more values.
     *
     * @param mixed $value
     * @param string $jsonkey
     * @param array $user
     *
     * @return string
     *
     */
    private function map_value($value, string $jsonkey, array &$user) {
        $functionname = self::return_function_by_jsonkey($jsonkey);
        switch ($functionname) {
            case taskflowadapter::TRANSLATOR_USER_LONG_LEAVE:
                $value = $value ? 1 : 0;
                break;
            case taskflowadapter::TRANSLATOR_USER_CONTRACTEND:
                $value = strtotime($value);
                break;
            case taskflowadapter::TRANSLATOR_USER_ORGUNIT:
                $additonalfields = explode("\\", $value);
                foreach ($additonalfields as $counter => $fieldvalue) {
                    $key = 'Org' . ($counter + 1);
                    $user[$key] = $fieldvalue;
                }
                break;
        }
        return $value;
    }
    /**
     * Sets the supervisor for the user.
     *
     * @return void
     *
     */
    private function create_or_update_supervisor() {
        foreach ($this->users as $user) {
            $supervisorfield = $this->return_shortname_for_functionname(taskflowadapter::TRANSLATOR_USER_SUPERVISOR);
            $supervisorinstance = new supervisor($user->profile[$supervisorfield], $user->id);
            $supervisorid = $user->profile[$supervisorfield];
            $supervisorinstance->set_supervisor_for_user($supervisorid, $supervisorfield, $user, $this->users);
        }
    }

    /**
     * Creates or updates the unitmember repo and adds user to the right cohort.
     *
     * @param stdClass $user
     *
     * @return void
     *
     */
    private function create_update_unitmemberrepo(stdClass $user) {

         $unitmemberinstance =
            $this->unitmemberrepo->update_or_create($user, (int)($user->cohortid ?? 0));
        if ($unitmemberinstance instanceof unit_member) {
            $updatedentities['unitmember'][$unitmemberinstance->get_userid()][] = [
            'unit' => $unitmemberinstance->get_unitid(),
            ];
        }
    }
    /**
     * [Description for create_or_update_users]
     *
     * @return [type]
     *
     */
    private function create_or_update_users() {
        global $DB;
        foreach ($this->users as $user) {
            $newunits = $this->users[$user->email]->newunits ?? [];
            $oldunits = $this->users[$user->email]->oldunits ?? [];

            $this->set_supervisor_internal_id($user);

            // If there is no old unit we set them the same so that the checks are still correct.
            if (
                  is_array($oldunits)
                  && is_array($newunits)
            ) {
                $this->invalidate_units_on_change(
                    $oldunits,
                    $newunits,
                    $user->id
                );
            }
            $onlongleave = $this->return_value_for_functionname(taskflowadapter::TRANSLATOR_USER_LONG_LEAVE, $user) ?? 0;
            if (
                $this->contract_ended($user) ||
                $onlongleave
            ) {
                assignments_facade::set_all_assignments_inactive($user->id);
                if ($this->contract_ended($user)) {
                    $userinterface = new moodle_user_factory();
                    $userinterface->inactivate_moodle_users([$user]);
                }
            } else {
                $this->create_update_unitmemberrepo($user);
            }
        }
    }

    /**
     * Translate the supervisor external id to internal id.
     * @param array $user
     * @return void
     */
    private function set_supervisor_internal_id(&$user) {
        global $DB;
        $externalsupervisoridfield = $this->return_shortname_for_functionname(taskflowadapter::TRANSLATOR_USER_SUPERVISOR_EXTERNAL);
        $externalidfield = $this->return_shortname_for_functionname(taskflowadapter::TRANSLATOR_USER_EXTERNALID);
        if (!empty($user->profile[$externalsupervisoridfield])) {
            $field = $DB->get_record('user_info_field', ['shortname' => $externalidfield], '*', MUST_EXIST);
            $sql = "SELECT u.*
                    FROM {user} u
                    JOIN {user_info_data} d ON d.userid = u.id
                    WHERE d.fieldid = :fieldid AND d.data = :dataval";
            $params = [
                'fieldid'  => $field->id,
                'dataval'  => $user->profile[$externalsupervisoridfield],
            ];
            $supervisor = $DB->get_record_sql($sql, $params);
            if ($supervisor) {
                $internalsupervisoridfield = $this->return_shortname_for_functionname(taskflowadapter::TRANSLATOR_USER_SUPERVISOR);
                $user->profile[$internalsupervisoridfield] = $supervisor->id;
            }
        }
    }

        /**
         * Creates or updates the units and enrolls them into cohorts.
         *
         * @param array $updatedentities
         *
         * @return void
         *
         */
    private function create_or_update_units($updatedentities) {
        foreach ($this->users as $key => $user) {
            $this->users[$key]->oldunits = moodle_user::get_all_units_of_user($user->id);
            $cohortid = $this->generate_units_data($user, $updatedentities);
            if (!empty($cohortid)) {
                $user->cohortid = $cohortid;
                if (get_config('local_taskflow', 'organisational_unit_option') == 'cohort') {
                    cohort_add_member($cohortid, (int) $user->id);
                    $this->users[$key]->newunits[] = $cohortid;
                }
            }
        }
    }
    /**
     * Builds the organisationpath.
     *
     * @param stdClass $user
     *
     * @return array
     *
     */
    private function build_organisation_path(stdClass $user) {
        $userprofilefields = $user->profile;
        $path = array_values(array_filter(
            $userprofilefields,
            function ($value, $key) {
                // Look for Fields with org and number.
                return preg_match('/^Org\d+$/', $key) && !empty($value);
            },
            ARRAY_FILTER_USE_BOTH
        ));
        return $path;
    }
    /**
     * Translates the user and adds it to the users array.
     *
     * @return void
     *
     */
    private function translate_users() {
        foreach ($this->externaldata as $user) {
            $translateduser = $this->translate_incoming_data($user);
            $unitsfield = $this->return_shortname_for_functionname(taskflowadapter::TRANSLATOR_USER_ORGUNIT);
            $unitsfieljsonkey = $this->return_jsonkey_for_functionname(taskflowadapter::TRANSLATOR_USER_ORGUNIT);
            // Maps the organisationfield.
            $this->map_value($translateduser[$unitsfield], $unitsfieljsonkey, $translateduser);
            $user = $this->userrepo->update_or_create($translateduser);
            $this->create_user_with_customfields($user, $translateduser, 'email');
        }
    }

    /**
     * Private constructor to prevent direct instantiation.
     * @param array $olduserunits
     * @param array $newuserunits
     * @param int $userid
     * @return void
     */
    private function invalidate_units_on_change(
        $olduserunits,
        $newuserunits,
        $userid
    ) {
        $invalidunits = array_diff($olduserunits, $newuserunits);
        if (count($invalidunits) >= 1) {
            assignments_facade::set_user_units_assignments_inactive(
                $userid,
                $invalidunits,
            );
        }
    }
    /**
     * Setter function for users array.
     *
     * @param stdClass $user
     *
     * @return void
     *
     */
    public function set_users(stdClass $user) {
        $this->users[$user->email] = $user;
    }

    /**
     * Checks if necessary Customfields are set for user created or updated.
     *
     * @param stdClass $user
     *
     * @return boolean
     *
     */
    public function necessary_customfields_exist(stdClass $user) {
        $customfields = get_config('taskflowadapter_ksw', "necessaryuserprofilefields");
        // Need to check first if it is one customfield that was checked or multiple.
        if (empty($customfields)) {
            return true;
        }
        if (is_string($customfields)) {
            if (empty($user->profile[$customfields])) {
                return false;
            }
        }
        if (is_array($customfields)) {
            foreach ($customfields as $customfield) {
                if (empty($user->profile[$customfield])) {
                    return false;
                }
            }
        }
        return true;
    }
    /**
     * Gives the Adapter the information to react on user created/updated.
     *
     * @return boolean
     *
     */
    public static function is_allowed_to_react_on_user_events() {
        return true;
    }
}

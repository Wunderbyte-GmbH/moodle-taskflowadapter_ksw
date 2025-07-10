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
        if (!empty($this->externaldata)) {
            $this->translate_user();
        }
        $this->create_or_update_users();
        $this->create_or_update_units($updatedentities);
        $this->create_or_update_supervisor();
        $this->save_all_user_infos($this->users);
        // Left in there for units.
        self::trigger_unit_relation_updated_events($updatedentities['relationupdate']);
        self::trigger_unit_member_updated_events($updatedentities['unitmember']);
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
            if ($unitinstance instanceof unit_relations) {
                $updatedentities['relationupdate'][$unitinstance->get_id()][] = [
                'child' => $unitinstance->get_childid(),
                'parent' => $unitinstance->get_parentid(),
                ];
            }
            $parentunitid = $unitinstance->get_id();
            $parent = $unit->name;
        }
         return $unitinstance->get_id() ?? 0;
    }

     /**
      * Private constructor to prevent direct instantiation.
      * @param array $translateduser
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
     * @param stdClass $user
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
        $unitsfield = $this->return_shortname_for_functionname(taskflowadapter::TRANSLATOR_USER_ORGUNIT);
         $unitmemberinstance =
            $this->unitmemberrepo->update_or_create($user, (int)$user->profile[$unitsfield]);
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
            $organisation = $this->build_organisation_path($user);
            $newunit = end($organisation);
            $oldunit = $this->get_oldunit($user->id);
            // If there is no old unit we set them the same so that the checks are still correct.
            if (empty($oldunit)) {
                $oldunit = $newunit;
            }
            if ($oldunit != $newunit) {
                assignments_facade::set_user_units_assignments_inactive($user->id, [$oldunit]);
            }
            $onlongleave = $this->return_value_for_functionname(taskflowadapter::TRANSLATOR_USER_LONG_LEAVE, $user) ?? 0;
            if (
                $this->contract_ended($user) ||
                $onlongleave
            ) {
                assignments_facade::set_all_assignments_inactive($user->id);
            } else {
                $this->create_update_unitmemberrepo($user);
            }
        }
    }
        /**
         * Creates or updates the units and enrolls them into cohorts.
         *
         * @return void
         *
         */
    private function create_or_update_units($updatedentities) {
        foreach ($this->users as $user) {
            $cohortid = self::generate_units_data($user, $updatedentities);
            if (get_config('local_taskflow', 'organisational_unit_option') == 'cohort') {
                cohort_add_member($cohortid, (int) $user->id);
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
        $organisationfield = $this->return_shortname_for_functionname(taskflowadapter::TRANSLATOR_USER_ORGUNIT);
        if (!empty($oranisationfield)) {
            unset($userprofilefields[$organisationfield]);
        }
        $path = array_values(array_filter(
            $userprofilefields,
            function ($value, $key) {
                return str_starts_with($key, 'Org') && !empty($value);
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
    private function translate_user() {
        foreach ($this->externaldata as $user) {
            $translateduser = $this->translate_incoming_data($user);
            $unitsfield = $this->return_shortname_for_functionname(taskflowadapter::TRANSLATOR_USER_ORGUNIT);
            $unitsfieljsonkey = $this->return_jsonkey_for_functionname(taskflowadapter::TRANSLATOR_USER_ORGUNIT);
            // Maps the organisationfield.
            $this->map_value($translateduser[$unitsfield], $unitsfieljsonkey, $translateduser);
            $user = $this->userrepo->update_or_create($translateduser);
            $this->create_user_with_customfields($user, $translateduser, 'email');
            $this->users[] = $user;
        }
    }
    /**
     * Returns the old with SQL join.
     *
     * @param int $userid
     *
     * @return mixed
     *
     */
    private function get_oldunit(int $userid) {
        global $DB;
        $sql = "SELECT c.name
                FROM m_local_taskflow_unit_members u
                JOIN m_cohort c ON u.unitid = c.id
                WHERE u.userid = :userid";
        $params = ['userid' => $userid];
        return $DB->get_record_sql($sql, $params, IGNORE_MISSING);
    }
}

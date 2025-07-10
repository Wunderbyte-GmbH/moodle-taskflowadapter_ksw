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
        $updatedentities = [
            'relationupdate' => [],
            'unitmember' => [],
        ];
        // Save data to users.
        foreach ($this->externaldata as $user) {
            $translateduser = $this->translate_incoming_data($user);
            $unitsfield = $this->return_shortname_for_functionname(taskflowadapter::TRANSLATOR_USER_ORGUNIT);
            // Create units and give the the translated user.
            $translateduser[$unitsfield] = $this->generate_units_data($translateduser, $updatedentities);
            if (empty(self::$usersbyemail[$translateduser['email']])) {
                // If the user does not exist, we create a new one.
                $olduser = $this->userrepo->get_user_by_mail(
                    $translateduser['email']
                );
            } else {
                // If the user exists, we get the old user.
                $olduser = self::$usersbyemail[$translateduser['email']];
            }
            if ($olduser) {
                 // We store the user for the whole process.
                self::$usersbyid[$olduser->id] = $olduser;
                self::$usersbyemail[$olduser->email] = $olduser;

                $oldunit = $this->return_value_for_functionname(
                    taskflowadapter::TRANSLATOR_USER_ORGUNIT,
                    $olduser
                );
            } else {
                $oldunit = 0;
            }
            $oldunit = !empty($oldtargetgroup) ? $oldtargetgroup : 0;
            // Create a new user.
            $user = $this->userrepo->update_or_create($translateduser);
            $this->create_user_with_customfields($user, $translateduser, 'email');
            $newunit = $this->return_value_for_functionname(taskflowadapter::TRANSLATOR_USER_ORGUNIT, $user);
            if ($oldunit != $newunit) {
                assignments_facade::set_user_units_assignments_inactive($user->id, [$oldunit]);
            }
        }
            $onlongleave = $this->return_value_for_functionname(taskflowadapter::TRANSLATOR_USER_LONG_LEAVE, $user) ?? 0;
        if (
                        $this->contract_ended($user) ||
                        $onlongleave
        ) {
            assignments_facade::set_all_assignments_inactive($user->id);
        } else {
            // Set supervisors.
            foreach ($this->users as $user) {
                $supervisorfield = $this->return_shortname_for_functionname(taskflowadapter::TRANSLATOR_USER_SUPERVISOR);
                $supervisorinstance = new supervisor($user->profile[$supervisorfield], $user->id);
                $supervisorid = $user->profile[$supervisorfield];
                $supervisorinstance->set_supervisor_for_user($supervisorid, $supervisorfield, $user, $this->users);

                // Create or update unit member.
                $unitmemberinstance =
                $this->unitmemberrepo->update_or_create($user, (int)$user->profile[$unitsfield]);
                if (get_config('local_taskflow', 'organisational_unit_option') == 'cohort') {
                    cohort_add_member((int)$user->profile[$unitsfield], (int) $user->id);
                }
                if ($unitmemberinstance instanceof unit_member) {
                    $updatedentities['unitmember'][$unitmemberinstance->get_userid()][] = [
                    'unit' => $unitmemberinstance->get_unitid(),
                    ];
                }
                $this->users[] = $user;
            }
            $this->save_all_user_infos($this->users);
            self::trigger_unit_relation_updated_events($updatedentities['relationupdate']);
            self::trigger_unit_member_updated_events($updatedentities['unitmember']);
        }
    }

    /**
     * Private constructor to prevent direct instantiation.
     * @param array $user
     * @param array $updatedentities
     * @return array
     */
    private function generate_units_data(array &$user, $updatedentities) {
        $organisationfieldname = $this->return_shortname_for_functionname(taskflowadapter::TRANSLATOR_USER_ORGUNIT);
        // Rollen sind anders definiert.
        $organisations = explode("\\", $user[$organisationfieldname]);
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
            if ($unitinstance instanceof unit_relations) {
                $updatedentities['relationupdate'][$unitinstance->get_id()][] = [
                    'child' => $unitinstance->get_childid(),
                    'parent' => $unitinstance->get_parentid(),
                ];
            }
            $parentunitid = $unitinstance->get_id();
            $parent = $unit->name;
        }
        return $unitinstance->get_id() ?? null;
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
                $additonalfields = explode('//', $value);
                foreach ($additonalfields as $counter => $fieldvalue) {
                    $key = 'Org' . ($counter + 1);
                    $user[$key] = $fieldvalue;
                }
                break;
        }

        return $value;
    }
}

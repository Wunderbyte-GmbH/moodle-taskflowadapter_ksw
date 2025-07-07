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
            $units = $this->return_shortname_for_functionname(taskflowadapter::TRANSLATOR_USER_ORGUNIT);
            $translateduser[$units] = [$this->generate_units_data($user, $updatedentities)];
            $user = $this->userrepo->update_or_create($translateduser);
            $this->create_user_with_customfields($user, $translateduser);
            $this->users[$user->email] = $user;
        }
        foreach ($this->users as $user) {
            $supervisorfield = $this->return_shortname_for_functionname(taskflowadapter::TRANSLATOR_USER_SUPERVISOR);
            $supervisorinstance = new supervisor($user->profile[$supervisorfield], $user->id);
            $supervisorinstance->set_supervisor_for_user($user->profile[$supervisorfield], $supervisorfield, $user, $this->users);
            foreach ($user->profile[$units] as $unit) {
                $unitmemberinstance =
                    $this->unitmemberrepo->update_or_create($user, $unit['unitid']);
                if ($unitmemberinstance instanceof unit_member) {
                    $updatedentities['unitmember'][$unitmemberinstance->get_userid()][] = [
                        'unit' => $unitmemberinstance->get_unitid(),
                    ];
                }
            }
            $this->users[] = $user;
        }
        $this->save_all_user_infos($this->users);
        self::trigger_unit_relation_updated_events($updatedentities['relationupdate']);
        self::trigger_unit_member_updated_events($updatedentities['unitmember']);
    }

    /**
     * Private constructor to prevent direct instantiation.
     * @param stdClass $user
     * @param array $updatedentities
     * @return array
     */
    private function generate_units_data($user, &$updatedentities) {
        $organisations = explode("\\", $user->Organisation);
        $unit = null;
        $parent = null;
        $unitinstance = null;
        foreach ($organisations as $organisation) {
            $unit = (object) [
                'name' => $organisation,
                'parent' => $parent,
            ];
            $unitinstance = organisational_unit_factory::create_unit($unit);
            if ($unitinstance instanceof unit_relations) {
                $updatedentities['relationupdate'][$unitinstance->get_id()][] = [
                    'child' => $unitinstance->get_childid(),
                    'parent' => $unitinstance->get_parentid(),
                ];
            }
            $parent = $unit->name;
        }

        return [
            'unitid' => $unitinstance->get_id() ?? null,
            'role' => $user->KisimRolle1 ?? null,
            'since' => $user->EntryDate,
            'exit' => $user->ExitDate,
        ];
    }
}

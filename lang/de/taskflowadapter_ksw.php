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

$string['apisettings'] = "KSW API Einstellungen";
$string['apisettings_desc'] = "Konfiguriere Taskflow-Schlüssel-Wert-Paare.";
$string['bookingoptiondescription'] = "Beschreibung der Buchungsoption für das Rendern von Zertifikate.";
$string['choose'] = "Wähle...";
$string['enter_value'] = 'Gib einen passenden JSON- Schlüssel für diese Einstellung ein';
$string['function'] = 'Funktion zuordnen zu Benutzerprofilfeld: ';
$string['internalid'] = 'Interne-ID';
$string['jsonkey'] = 'JSON Schlüssel für Benutzerprofilfeld: ';
$string['ksw'] = "KSW API";
$string['lessfunctions'] = '<div class="alert alert-danger" role="alert">Nicht alle Funktionen wurden beim letzten Speichern ausgewählt. Dies kann zu Fehlern führen.</div>';
$string['manyfunctions'] = '<div class="alert alert-danger" role="alert">Funktionen wurden mehrfach ausgewählt beim letzten Speichern. Dies kann zu Fehlern führen.</div>';
$string['mappingdescription'] = 'Taskflow-Schlüssel-Wert-Paare Erklärung';
$string['mappingdescription_desc'] = 'Hier wird die Verknüpfung erstellt. Das obere Feld gibt an zu welchen JSON- Feld mit dem Benutzerprofilfeld verknüpft werden soll. Das untere Feld gibt an, welche Fuktion dieses Feld representiert. Nicht jedes Benutzerprofilfeld muss eine Funktion haben.';
$string['necessaryuserprofilefields'] = "Benutzerprofilfelder für Taskflow die unbedingt befüllt sien müssen";
$string['necessaryuserprofilefieldsdesc'] = "Benutzerprofilfelder, die befüllt sein müssen, damit der Benutzer*innen für ein Taskflow-Update berücksichtigt wird. Wenn die ausgewählten Felder nicht befüllt sind, werden Änderungen von Benutzer*innen in Wunderbyte Taskflow nicht berücksichtig. Lassen Sie diese Einstellung leer, wenn es keine Felder gibt die befüllt sein müssen.";
$string['pluginname'] = "KSW";
$string['set:function'] = 'Wählen Sie eine Funktion aus';
$string['subplugintype_taskflowadapter_plural'] = 'Taskflow-Adapter-Erweiterungen';

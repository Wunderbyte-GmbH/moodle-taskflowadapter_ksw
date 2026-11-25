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
 * Drops out the planned and open assignments of suspended users.
 *
 * Without --execute the script only lists what it would change.
 *
 * @package   taskflowadapter_ksw
 * @copyright 2026 Wunderbyte GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use local_taskflow\local\assignment_status\assignment_status_facade;
use taskflowadapter_ksw\local\leaver_assignments;

[$options, $unrecognized] = cli_get_params(
    [
        'help' => false,
        'execute' => false,
        'userid' => 0,
    ],
    [
        'h' => 'help',
    ]
);

if ($unrecognized) {
    cli_error(get_string('cliunknowoption', 'admin', implode("\n  ", $unrecognized)));
}

if ($options['help']) {
    cli_writeln("Drops out the planned and open assignments of suspended users.
Paused, not relevant and completed assignments are not changed.

Options:
  --execute     Apply the changes. Without it the script only lists them.
  --userid=ID   Only handle this user.
  -h, --help    Print this help.

Example:
  php public/local/taskflow/taskflowadapter/ksw/cli/drop_assignments_of_suspended_users.php --userid=1526");
    exit(0);
}

$execute = !empty($options['execute']);
$conditions = ['suspended' => 1, 'deleted' => 0];
if (!empty($options['userid'])) {
    $conditions['id'] = (int)$options['userid'];
}

$droppedout = assignment_status_facade::get_status_identifier('droppedout');
$total = 0;
$users = 0;
$suspendedusers = $DB->get_recordset('user', $conditions, 'id ASC', 'id');
foreach ($suspendedusers as $user) {
    if ($execute) {
        $changes = leaver_assignments::drop_open_assignments((int)$user->id);
    } else {
        $changes = [];
        foreach (leaver_assignments::get_open_assignments((int)$user->id) as $assignment) {
            $changes[] = [
                'id' => (int)$assignment->id,
                'ruleid' => (int)$assignment->ruleid,
                'oldstatus' => (int)$assignment->status,
                'newstatus' => $droppedout,
            ];
        }
    }
    if (empty($changes)) {
        continue;
    }
    $users++;
    foreach ($changes as $change) {
        $total++;
        cli_writeln(
            "user {$user->id} assignment {$change['id']} rule {$change['ruleid']}: "
            . "status {$change['oldstatus']} -> {$change['newstatus']}"
        );
    }
}
$suspendedusers->close();

$verb = $execute ? 'Dropped out' : 'Dry run, would drop out';
cli_writeln("{$verb} {$total} assignments of {$users} suspended users.");

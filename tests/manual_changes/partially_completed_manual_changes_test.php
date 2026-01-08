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

namespace taskflowadapter_ksw\manual_changes;

use core_admin\external\set_block_protection;
use local_taskflow\local\external_adapter\external_api_repository;
use mod_booking\booking_bookit;
use taskflowadapter_standard\form\editassignment;

defined('MOODLE_INTERNAL') || die();
require_once(__DIR__ . '/manual_changes_base.php');
use local_taskflow\local\assignment_status\assignment_status_facade;
use taskflowadapter_ksw\form\editassignment_admin;
use tool_mocktesttime\time_mock;
use mod_booking\singleton_service;
use mod_booking_generator;

/**taskflowadapter_ksw\manual_changes\manual_changes_base extends \advanced_testcase
Tests for manual status changes.
 * Tests for manual status changes.
 *
 * @package taskflowadapter_ksw
 * @category test
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @runTestsInSeparateProcesses
 */
final class partially_completed_manual_changes_test extends manual_changes_base {
    /**
     * Data provider for changed status and expections.
     * @return array
     */
    public static function status_and_outputs(): array {
        return [
            'droppedout' => [
                16, // Status.
                16, // Outputform.
                16, // Outputimport.
            ],
            'completed' => [
                15, // Status.
                15, // Outputform.
                15, // Status is overwritten.
            ],
            'sanction' => [
                12, // Status.
                12, // Outputform.
                12, // Status is overwritten.
            ],
            'reprimand' => [
                11, // Status.
                11, // Outputform.
                11, // Status is overwritten.
            ],
            'overdue' => [
                10, // Status.
                10, // Outputform.
                10, // Outputimport.
            ],
            'partially_completed' => [
                7, // Status.
                7, // Outputform.
                7, // Status is not reached.
            ],
            'prolonged' => [
                5, // Status.
                5, // Outputform.
                5, // Outputimport.
            ],
            'paused' => [
                4, // Status.
                4, // Outputform.
                4, // Outputimport.
            ],
            'enrolled' => [
                3, // Status.
                3, // Outputform.
                3, // Status is not allowed.
            ],
            'assigned' => [
                0, // Status.
                0, // Outputform.
                0, // Outputimport.
            ],
        ];
    }

    /**
     * Test manual changes.
     *
     * @param int $status
     * @param int $outputform
     * @param int $outputimport
     * @return void
     * @dataProvider status_and_outputs
     * @covers \local_taskflow\local\rules\rules
     * @runInSeparateProcess
     */
    public function test_manual_change(
        int $status,
        int $outputform,
        int $outputimport
    ): void {
        global $DB;
        [$user1, $user2, $booking, $course, $competency, $competency2, $externaldata] = $this->manual_changes_base_case();
        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $lock = $this->createMock(\core\lock\lock::class);
        $cronlock = $this->createMock(\core\lock\lock::class);
        // Create booking option1.
        $record = $this->create_booking_option(
            $booking->id,
            $course->id,
            $user1->username,
            $competency->get('id'),
            0,
        );
        $option1 = $plugingenerator->create_option($record);

        singleton_service::destroy_instance();

        $this->setUser($user2);
        $result = booking_bookit::bookit('option', $option1->id, $user2->id);
        $result = booking_bookit::bookit('option', $option1->id, $user2->id);
        $option = singleton_service::get_instance_of_booking_option($option1->cmid, $option1->id);
        $option->toggle_user_completion($user2->id);

        $plugingeneratortf = self::getDataGenerator()->get_plugin_generator('local_taskflow');

        $plugingeneratortf->runtaskswithintime($cronlock, $lock, time());

        $assignments = $DB->get_records('local_taskflow_assignment', ['userid' => $user2->id ]);
        $this->assertCount(1, $assignments);
        $assignment = array_shift($assignments);
        $this->assertSame((int)$assignment->status, assignment_status_facade::get_status_identifier('partially_completed'));

        // Form submission.
        $postdata = [
            'id' => $assignment->id,
            'ruleid' => $assignment->ruleid,
            'userid' => $user1->id,
            'status' => $status,
            'change_reason' => 0,
            'duedate' => $assignment->duedate,
            'comment' => 'UNIT TEST COMMENT',
            'keepchanges' => 0,
            'overduecounter' => $assignment->overduecounter,
            'prolongedcounter' => $assignment->prolongedcounter,
        ];

        $form = $this->getMockBuilder(editassignment::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['get_data'])
            ->getMock();

        $form->method('get_data')->willReturn((object)$postdata);

        // Form is being submitted.
        $form->process_dynamic_submission();
        $newassignment = $DB->get_record('local_taskflow_assignment', ['id' => $assignment->id ]);
        $this->assertSame((int)$newassignment->status, $outputform);
        $this->trigger_import();
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('local_taskflow');
        time_mock::set_mock_time(strtotime('+ 6 minutes', time()));
        $plugingenerator->runtaskswithintime($cronlock, $lock, time());

        // Import is being triggered.
        $newimportassignment = $DB->get_record('local_taskflow_assignment', ['id' => $assignment->id ]);
        $this->assertSame((int)$newimportassignment->status, $outputimport);
    }
}

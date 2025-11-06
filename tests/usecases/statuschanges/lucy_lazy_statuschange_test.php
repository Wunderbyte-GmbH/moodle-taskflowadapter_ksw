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

namespace taskflowadapter_ksw\usecases\statuschanges;

use advanced_testcase;
use local_taskflow\local\rules\rules;
use taskflowadapter_tuines\form\editassignment_supervisor;
use tool_mocktesttime\time_mock;
use context_system;
use core_competency\api;
use core_competency\competency;
use core_competency\user_competency;
use local_taskflow\event\rule_created_updated;
use local_taskflow\local\assignment_status\assignment_status_facade;
use mod_booking\singleton_service;
use stdClass;
use mod_booking_generator;

/**
 * Tests for booking rules.
 *
 * @package taskflowadapter_ksw
 * @category test
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class lucy_lazy_statuschange_test extends advanced_testcase {
    /**
     * Tests set up.
     */
    public function setUp(): void {
        parent::setUp();
        time_mock::init();
        time_mock::set_mock_time(strtotime('now'));
        $this->resetAfterTest();
        singleton_service::destroy_instance();
        rules::reset_instances();
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('local_taskflow');
        $plugingenerator->create_custom_profile_fields([
            'supervisor',
            'units',
        ]);
        $plugingenerator->set_config_values();
        $this->create_custom_profile_field();
        $this->preventResetByRollback();
    }

    /**
     * Mandatory clean-up after each test.
     */
    public function tearDown(): void {
        global $DB;

        parent::tearDown();
        // Mandatory clean-up.
        singleton_service::destroy_instance();
    }

    /**
     * Setup the test environment.
     */
    private function create_custom_profile_field(): int {
        global $DB;
        $shortname = 'supervisor';
        $name = ucfirst($shortname);
        if ($DB->record_exists('user_info_field', ['shortname' => $shortname])) {
            return 0;
        }

        $field = (object)[
            'shortname' => $shortname,
            'name' => $name,
            'datatype' => 'text',
            'description' => '',
            'descriptionformat' => FORMAT_HTML,
            'categoryid' => 1,
            'sortorder' => 0,
            'required' => 0,
            'locked' => 0,
            'visible' => 1,
            'forceunique' => 0,
            'signup' => 0,
            'defaultdata' => '',
            'defaultdataformat' => FORMAT_HTML,
            'param1' => '',
            'param2' => '',
            'param3' => '',
            'param4' => '',
            'param5' => '',
        ];

        return $DB->insert_record('user_info_field', $field);
    }

    /**
     * Test rulestemplate on option being completed for user.
     *
     * @covers \mod_booking\option\fields\competencies
     *
     * @param array $bdata
     * @throws \coding_exception
     *
     * @dataProvider booking_common_settings_provider
     */
    public function test_lucy_lazy_automatic_prolongation(array $bdata): void {

        global $DB;

        $this->luzy_lazy_base();

        $assignments = $DB->get_records('local_taskflow_assignment');
        foreach ($assignments as $assignment) {
            $this->assertSame((int)$assignment->status, assignment_status_facade::get_status_identifier('assigned'));
            $duedate = $assignment->duedate;
        }
        $time = time();
        time_mock::set_mock_time(strtotime('+ 25 hours', time()));

        // In this scenario, the assigment will be prolonged automatically.
        set_config('usingprolongedstate', 1, 'taskflowadapter_tuines');

        $time = time();
        $this->runAdhocTasks();
        $assignments = $DB->get_records('local_taskflow_assignment');
        $this->assertCount(1, $assignments);
        foreach ($assignments as $assignment) {
            $this->assertSame((int)$assignment->status, assignment_status_facade::get_status_identifier('prolonged'));
            // We check if the overdue has been prolonged.
            $this->assertSame((int)$assignment->duedate, $duedate + 86400);
        }

        // Now, we should be in overdue.
        $time = time();
        time_mock::set_mock_time(strtotime('+ 25 hours', time()));

        $this->runAdhocTasks();
        $assignments = $DB->get_records('local_taskflow_assignment');
        $this->assertCount(1, $assignments);
        foreach ($assignments as $assignment) {
            $this->assertSame((int)$assignment->status, assignment_status_facade::get_status_identifier('overdue'));
        }
    }

    /**
     * Test rulestemplate on option being completed for user.
     *
     * @covers \mod_booking\option\fields\competencies
     *
     * @param array $bdata
     * @throws \coding_exception
     *
     * @dataProvider booking_common_settings_provider
     */
    public function test_lucy_lazy_automatic_supervisoraccept(array $bdata): void {

        global $DB;

        [$user, $supervisor, $option] = $this->luzy_lazy_base();

        $assignments = $DB->get_records('local_taskflow_assignment');
        foreach ($assignments as $assignment) {
            $this->assertSame((int)$assignment->status, assignment_status_facade::get_status_identifier('assigned'));
            $duedate = $assignment->duedate;
        }
        $time = time();
        time_mock::set_mock_time(strtotime('+ 25 hours', time()));

        // In this scenario, the assigment will be prolonged automatically.
        set_config('usingprolongedstate', 1, 'taskflowadapter_tuines');


        $time = time();
        $this->runAdhocTasks();
        $assignments = $DB->get_records('local_taskflow_assignment');
        $this->assertCount(1, $assignments);
        foreach ($assignments as $assignment) {
            $this->assertSame((int)$assignment->status, assignment_status_facade::get_status_identifier('prolonged'));
            // We check if the overdue has been prolonged.
            $this->assertSame((int)$assignment->duedate, $duedate + 86400);
        }

        $duedate = $duedate + 86400 * 2; // Example: two days later.
        $duedatearray = [
            'day' => (int) date('j', $duedate),
            'month' => (int) date('n', $duedate),
            'year' => (int) date('Y', $duedate),
        ];

        $submitdata = [
            'id' => $assignment->id,
            'actionbutton' => 'extension',
            'comment_approved' => 'xy',
            'change_reason' => 10,
            'duedate' => $duedatearray,
        ];

        // Because of timezones ets, we need to recalculate our new duedate..
        $newduedate = strtotime("{$duedatearray['year']}-{$duedatearray['month']}-{$duedatearray['day']}");

        $submitdata = editassignment_supervisor::mock_ajax_submit($submitdata);
        // Now, the supervisor of Luzy adds his own prolongation.
        $editassignmentform = new editassignment_supervisor(
            null,
            null,
            'post',
            '',
            [],
            true,
            $submitdata,
            true
        );

        // Simulate data submission (bypassing is_submitted()).
        $editassignmentform->set_data_for_dynamic_submission();
        $editassignmentform->validation($submitdata, []);
        // $this->assertTrue($editassignmentform->is_validated());
        // Process the "AJAX" submission.
        $editassignmentform->process_dynamic_submission();

        // Now, we should be in overdue.
        $time = time();
        time_mock::set_mock_time(strtotime('+ 25 hours', time()));

        $this->runAdhocTasks();
        $assignments = $DB->get_records('local_taskflow_assignment');
        $this->assertCount(1, $assignments);
        foreach ($assignments as $assignment) {
            $this->assertSame((int)$assignment->status, assignment_status_facade::get_status_identifier('prolonged'));
            $this->assertSame((int)$assignment->duedate, $newduedate);
            // Prolonged counter is on one.
            $this->assertSame((int)$assignment->overduecounter, 0);
            // Prolonged counter is on one.
            $this->assertSame((int)$assignment->prolongedcounter, 2);
        }
    }

    /**
     * Test rulestemplate on option being completed for user.
     *
     * @covers \mod_booking\option\fields\competencies
     *
     * @param array $bdata
     * @throws \coding_exception
     *
     * @dataProvider booking_common_settings_provider
     */
    public function test_lucy_lazy_automatic_supervisordecline(array $bdata): void {

        global $DB;

        [$user, $supervisor, $option] = $this->luzy_lazy_base();

        $assignments = $DB->get_records('local_taskflow_assignment');
        foreach ($assignments as $assignment) {
            $this->assertSame((int)$assignment->status, assignment_status_facade::get_status_identifier('assigned'));
            $duedate = $assignment->duedate;
        }
        $time = time();
        time_mock::set_mock_time(strtotime('+ 25 hours', time()));

        // In this scenario, the assigment will be prolonged automatically.
        set_config('usingprolongedstate', 1, 'taskflowadapter_tuines');


        $time = time();
        $this->runAdhocTasks();
        $assignments = $DB->get_records('local_taskflow_assignment');
        $this->assertCount(1, $assignments);
        foreach ($assignments as $assignment) {
            $this->assertSame((int)$assignment->status, assignment_status_facade::get_status_identifier('prolonged'));
            // We check if the overdue has been prolonged.
            $this->assertSame((int)$assignment->duedate, $duedate + 86400);
        }
        $initialduedate = $duedate;
        $duedate = $duedate + 86400 * 2; // Example: two days later.
        $duedatearray = [
            'day' => (int) date('j', $duedate),
            'month' => (int) date('n', $duedate),
            'year' => (int) date('Y', $duedate),
        ];

        $submitdata = [
            'id' => $assignment->id,
            'actionbutton' => 'declined',
            'comment_denied' => 'abc',
            'duedate' => $duedatearray,
        ];

        // Because of timezones ets, we need to recalculate our new duedate..
        $newduedate = strtotime("{$duedatearray['year']}-{$duedatearray['month']}-{$duedatearray['day']}");

        $submitdata = editassignment_supervisor::mock_ajax_submit($submitdata);
        // Now, the supervisor of Luzy adds his own prolongation.
        $editassignmentform = new editassignment_supervisor(
            null,
            null,
            'post',
            '',
            [],
            true,
            $submitdata,
            true
        );

        // Simulate data submission (bypassing is_submitted()).
        $editassignmentform->set_data_for_dynamic_submission();
        $editassignmentform->validation($submitdata, []);
        // $this->assertTrue($editassignmentform->is_validated());
        // Process the "AJAX" submission.
        $editassignmentform->process_dynamic_submission();

        // Now, we should be in overdue.
        $time = time();
        time_mock::set_mock_time(strtotime('+ 25 hours', time()));

        $this->runAdhocTasks();
        $assignments = $DB->get_records('local_taskflow_assignment');
        $this->assertCount(1, $assignments);
        foreach ($assignments as $assignment) {
            $this->assertSame((int)$assignment->status, assignment_status_facade::get_status_identifier('overdue'));
            // We should be on the initial duedate plus prolonged.
            $this->assertSame((int)$assignment->duedate, $initialduedate + 86400);
            // Prolonged counter is on one.
            $this->assertSame((int)$assignment->overduecounter, 1);
            // Prolonged counter is on one.
            $this->assertSame((int)$assignment->prolongedcounter, 2);
        }
    }

    /**
     * Test rulestemplate on option being completed for user.
     *
     * @covers \mod_booking\option\fields\competencies
     *
     * @param array $bdata
     * @throws \coding_exception
     *
     * @dataProvider booking_common_settings_provider
     */
    public function test_lucy_lazy_automatic_supervisoraccept_inoverdue(array $bdata): void {

        global $DB;

        [$user, $supervisor, $option] = $this->luzy_lazy_base();

        $assignments = $DB->get_records('local_taskflow_assignment');
        foreach ($assignments as $assignment) {
            $this->assertSame((int)$assignment->status, assignment_status_facade::get_status_identifier('assigned'));
            $duedate = $assignment->duedate;
        }
        $time = time();
        time_mock::set_mock_time(strtotime('+ 25 hours', time()));

        // In this scenario, the assigment will be prolonged automatically.
        set_config('usingprolongedstate', 1, 'taskflowadapter_tuines');

        $time = time();
        $this->runAdhocTasks();
        $assignments = $DB->get_records('local_taskflow_assignment');
        $this->assertCount(1, $assignments);
        foreach ($assignments as $assignment) {
            $this->assertSame((int)$assignment->status, assignment_status_facade::get_status_identifier('prolonged'));
            // We check if the overdue has been prolonged.
            $this->assertSame((int)$assignment->duedate, $duedate + 86400);
        }

        // Now, we should be in overdue.
        $time = time();
        time_mock::set_mock_time(strtotime('+ 25 hours', time()));

        $this->runAdhocTasks();
        $assignments = $DB->get_records('local_taskflow_assignment');
        $this->assertCount(1, $assignments);
        foreach ($assignments as $assignment) {
            $this->assertSame((int)$assignment->status, assignment_status_facade::get_status_identifier('overdue'));
            // We should be on the initial duedate plus prolonged.
            $this->assertSame((int)$assignment->duedate, $duedate + 86400);
            $this->assertSame((int)$assignment->overduecounter, 1);
        }

        // Now, we move on in time.
        $time = time();
        time_mock::set_mock_time(strtotime('+ 25 hours', time()));

        $duedate = time() + 86400; // Example: two days later.
        $duedatearray = [
            'day' => (int) date('j', $duedate),
            'month' => (int) date('n', $duedate),
            'year' => (int) date('Y', $duedate),
        ];

        $submitdata = [
            'id' => $assignment->id,
            'actionbutton' => 'extension',
            'comment_approved' => 'xy',
            'change_reason' => 10,
            'duedate' => $duedatearray,
        ];

        // Because of timezones ets, we need to recalculate our new duedate..
        $newduedate = strtotime("{$duedatearray['year']}-{$duedatearray['month']}-{$duedatearray['day']}");

        $submitdata = editassignment_supervisor::mock_ajax_submit($submitdata);
        // Now, the supervisor of Luzy adds his own prolongation.
        $editassignmentform = new editassignment_supervisor(
            null,
            null,
            'post',
            '',
            [],
            true,
            $submitdata,
            true
        );

        // Simulate data submission (bypassing is_submitted()).
        $editassignmentform->set_data_for_dynamic_submission();
        $editassignmentform->validation($submitdata, []);
        // $this->assertTrue($editassignmentform->is_validated());
        // Process the "AJAX" submission.
        $editassignmentform->process_dynamic_submission();

        $this->runAdhocTasks();
        $assignments = $DB->get_records('local_taskflow_assignment');
        $this->assertCount(1, $assignments);
        foreach ($assignments as $assignment) {
            $this->assertSame((int)$assignment->status, assignment_status_facade::get_status_identifier('prolonged'));
            $this->assertSame((int)$assignment->duedate, $newduedate);
            // Overdue counter is on one.
            $this->assertSame((int)$assignment->overduecounter, 1);

            // Prolonged counter is on one.
            $this->assertSame((int)$assignment->prolongedcounter, 2);
        }
    }

    /**
     * Test rulestemplate on option being completed for user.
     *
     * @covers \mod_booking\option\fields\competencies
     *
     * @param array $bdata
     * @throws \coding_exception
     *
     * @dataProvider booking_common_settings_provider
     */
    public function test_lucy_lazy_automatic_supervisordecline_inoverdue(array $bdata): void {

        global $DB;

        [$user, $supervisor, $option] = $this->luzy_lazy_base();

        $assignments = $DB->get_records('local_taskflow_assignment');
        foreach ($assignments as $assignment) {
            $this->assertSame((int)$assignment->status, assignment_status_facade::get_status_identifier('assigned'));
            $duedate = $assignment->duedate;
        }
        $time = time();
        time_mock::set_mock_time(strtotime('+ 25 hours', time()));

        // In this scenario, the assigment will be prolonged automatically.
        set_config('usingprolongedstate', 1, 'taskflowadapter_tuines');


        $time = time();
        $this->runAdhocTasks();
        $assignments = $DB->get_records('local_taskflow_assignment');
        $this->assertCount(1, $assignments);
        foreach ($assignments as $assignment) {
            $this->assertSame((int)$assignment->status, assignment_status_facade::get_status_identifier('prolonged'));
            // We check if the overdue has been prolonged.
            $this->assertSame((int)$assignment->duedate, $duedate + 86400);
        }

        // Now, we should be in overdue.
        $time = time();
        time_mock::set_mock_time(strtotime('+ 25 hours', time()));

        $this->runAdhocTasks();
        $assignments = $DB->get_records('local_taskflow_assignment');
        $this->assertCount(1, $assignments);
        foreach ($assignments as $assignment) {
            $this->assertSame((int)$assignment->status, assignment_status_facade::get_status_identifier('overdue'));
            // We should be on the initial duedate plus prolonged.
            $this->assertSame((int)$assignment->duedate, $duedate + 86400);
            $this->assertSame((int)$assignment->overduecounter, 1);
        }

        // We move on in time.
        $time = time();
        time_mock::set_mock_time(strtotime('+ 25 hours', time()));


        $initialduedate = $duedate;
        $duedate = time() + 86400; // Example: two days later.
        $duedatearray = [
            'day' => (int) date('j', $duedate),
            'month' => (int) date('n', $duedate),
            'year' => (int) date('Y', $duedate),
        ];

        $submitdata = [
            'id' => $assignment->id,
            'actionbutton' => 'declined',
            'comment_denied' => 'abc',
            'duedate' => $duedatearray,
        ];

        // Because of timezones ets, we need to recalculate our new duedate..
        $newduedate = strtotime("{$duedatearray['year']}-{$duedatearray['month']}-{$duedatearray['day']}");

        $submitdata = editassignment_supervisor::mock_ajax_submit($submitdata);
        // Now, the supervisor of Luzy adds his own prolongation.
        $editassignmentform = new editassignment_supervisor(
            null,
            null,
            'post',
            '',
            [],
            true,
            $submitdata,
            true
        );

        // Simulate data submission (bypassing is_submitted()).
        $editassignmentform->set_data_for_dynamic_submission();
        $editassignmentform->validation($submitdata, []);
        // $this->assertTrue($editassignmentform->is_validated());
        // Process the "AJAX" submission.
        $editassignmentform->process_dynamic_submission();

        $this->runAdhocTasks();
        $assignments = $DB->get_records('local_taskflow_assignment');
        $this->assertCount(1, $assignments);
        foreach ($assignments as $assignment) {
            $this->assertSame((int)$assignment->status, assignment_status_facade::get_status_identifier('overdue'));
            // We should be on the initial duedate plus prolonged.
            $this->assertSame((int)$assignment->duedate, $initialduedate + 86400);
            $this->assertSame((int)$assignment->overduecounter, 2);
            // Prolonged counter is on two.
            $this->assertSame((int)$assignment->prolongedcounter, 2);
        }
    }

    /**
     * This is the base function for this test.
     * Each scenario will build on this.
     * It returns user, supervisor and option created.
     *
     * @return array
     *
     */
    private function luzy_lazy_base() {

        global $DB;
        singleton_service::destroy_instance();

        $this->setAdminUser();

        set_config('timezone', 'Europe/Kyiv');
        set_config('forcetimezone', 'Europe/Kyiv');

        // Allow optioncacellation.
        $bdata['cancancelbook'] = 1;

        // Setup test data.
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $user1 = $this->getDataGenerator()->create_user();
        $user2 = $this->getDataGenerator()->create_user();
        $user3 = $this->getDataGenerator()->create_user();

        $testingsupervisor = $this->getDataGenerator()->create_user([
            'firstname' => 'Super',
            'lastname' => 'Visor',
            'email' => 'auper@visor.com',
        ]);

        $fieldid = $DB->get_field('user_info_field', 'id', ['shortname' => 'supervisor'], MUST_EXIST);
        $exsistinginfodata = $DB->get_record(
            'user_info_data',
            [
                    'userid' => $user2->id,
                    'fieldid' => $fieldid,
                ]
        );
        if ($exsistinginfodata) {
            $exsistinginfodata->data = $testingsupervisor->id;
            $DB->update_record(
                'user_info_data',
                $exsistinginfodata
            );
        } else {
            $DB->insert_record('user_info_data', (object)[
                'userid' => $user2->id,
                'fieldid' => $fieldid,
                'data' => $testingsupervisor->id,
                'dataformat' => FORMAT_HTML,
            ]);
        }

        $scale = $this->getDataGenerator()->create_scale([
            'scale' => 'Not proficient,Proficient',
            'name' => 'Test Competency Scale',
        ]);

        $cohort = $this->set_db_cohort();
        cohort_add_member($cohort->id, $user2->id);
        // Create a competency.
        $framework = api::create_framework((object)[
            'shortname' => 'testframework',
            'idnumber' => 'testframework',
            'contextid' => context_system::instance()->id,
            'scaleid' => $scale->id,
            'scaleconfiguration' => json_encode([
                ['scaleid' => $scale->id],
                ['id' => 1, 'scaledefault' => 1, 'proficient' => 0],
                ['id' => 2, 'scaledefault' => 0, 'proficient' => 1],
            ]),
        ]);
        // Create compentencies.
        $record = (object)[
            'shortname' => 'testcompetency',
            'idnumber' => 'testcompetency',
            'competencyframeworkid' => $framework->get('id'),
            'scaleid' => null,
            'description' => 'A test competency',
            'id' => 0,
            'scaleconfiguration' => null,
            'parentid' => 0,
        ];
        $competency = new competency(0, $record);
        $competency->set('sortorder', 0);
        $competency->create();

        $rule = $this->get_rule($cohort->id, $competency->get('id'));
        $id = $DB->insert_record('local_taskflow_rules', $rule);
        $rule['id'] = $id;

        $event = rule_created_updated::create([
            'objectid' => $rule['id'],
            'context'  => context_system::instance(),
            'other'    => [
                'ruledata' => $rule,
            ],
        ]);
        $event->trigger();
        $this->runAdhocTasks();
        $assignment = $DB->get_records('local_taskflow_assignment');

        $record = (object)[
            'shortname' => 'testcompetency2',
            'idnumber' => 'testcompetency2',
            'competencyframeworkid' => $framework->get('id'),
            'scaleid' => null,
            'description' => 'A test competency2',
            'id' => 0,
            'scaleconfiguration' => null,
            'parentid' => 0,
        ];
        $competency2 = new competency(0, $record);
        $competency2->set('sortorder', 0);
        $competency2->create();

        $bdata['course'] = $course->id;
        $bdata['bookingmanager'] = $user1->username;

        $booking = $this->getDataGenerator()->create_module('booking', $bdata);
        set_config('usecompetencies', 1, 'booking');

        $this->setAdminUser();

        $this->getDataGenerator()->enrol_user($user1->id, $course->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user($user2->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($user3->id, $course->id, 'editingteacher');

        // User 2 already has competency 1.
        $existing = user_competency::get_records(['userid' => $user2->id]);
        $this->assertEmpty($existing, 'User already has a competency assigned');
        $usercompetency = api::get_user_competency($user2->id, $competency->get('id'));
        $existing = user_competency::get_records(['userid' => $user2->id]);
        $this->assertNotEmpty($existing, 'Competency could not be created for user');

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        // Create booking option 1.
        $record = new stdClass();
        $record->bookingid = $booking->id;
        $record->text = 'football';
        $record->chooseorcreatecourse = 1; // Connected existing course.
        $record->courseid = $course->id;
        $record->description = 'Will start tomorrow';
        $record->optiondateid_0 = "0";
        $record->daystonotify_0 = "0";
        $record->coursestarttime_0 = strtotime('20 June 2050 15:00');
        $record->courseendtime_0 = strtotime('20 July 2050 14:00');
        $record->teachersforoption = $user1->username;
        $record->teachersforoption = 0;
        $record->competencies = [$competency->get('id'), $competency2->get('id')];
        $option1 = $plugingenerator->create_option($record);
        singleton_service::destroy_booking_option_singleton($option1->id);

        return [$user2, $testingsupervisor, $option1];
    }




    /**
     * Setup the test environment.
     * @return object
     */
    protected function set_db_cohort(): mixed {
        // Create a user.
        $cohort = $this->getDataGenerator()->create_cohort([
            'name' => 'Test Cohort',
            'idnumber' => 'cohort123',
            'contextid' => context_system::instance()->id,
        ]);
        return $cohort;
    }

    /**
     * Setup the test environment.
     * @param int $unitid
     * @param int $targetid
     * @return array
     */
    public function get_rule($unitid, $targetid): array {
        $rule = [
            "unitid" => $unitid,
            "rulename" => "test_rule",
            "rulejson" => json_encode((object)[
                "rulejson" => [
                    "rule" => [
                        "name" => "test_rule",
                        "description" => "test_rule_description",
                        "type" => "taskflow",
                        "enabled" => true,
                        "duedatetype" => "duration",
                        "cyclicvalidation" => "0",
                        "cyclicduration" => 38361600,
                        "fixeddate" => 23233232222,
                        "duration" => 84600,
                        "extensionperiod" => 86400,
                        "timemodified" => 23233232222,
                        "timecreated" => 23233232222,
                        "usermodified" => 1,
                        "filter" => [
                            [
                                "filtertype" => "user_profile_field",
                                "userprofilefield" => "supervisor",
                                "operator" => "not_equals",
                                "value" => "124",
                                "key" => "role",
                            ],
                        ],
                        "actions" => [
                            [
                                "targets" => [
                                    [
                                        "targetid" => $targetid,
                                        "targettype" => "competency",
                                        "targetname" => "mycompetency",
                                        "sortorder" => 2,
                                        "actiontype" => "enroll",
                                        "completebeforenext" => false,
                                    ],
                                ],
                                "messages" => [],
                            ],
                        ],
                    ],
                ],
            ]),
            "isactive" => "1",
            "userid" => "0",
        ];
        return $rule;
    }

    /**
     * Data provider for condition_bookingpolicy_test
     *
     * @return array
     * @throws \UnexpectedValueException
     */
    public static function booking_common_settings_provider(): array {
        $bdata = [
            'name' => 'Rule Booking Test',
            'eventtype' => 'Test rules',
            'enablecompletion' => 1,
            'bookedtext' => ['text' => 'text'],
            'waitingtext' => ['text' => 'text'],
            'notifyemail' => ['text' => 'text'],
            'statuschangetext' => ['text' => 'text'],
            'deletedtext' => ['text' => 'text'],
            'pollurltext' => ['text' => 'text'],
            'pollurlteacherstext' => ['text' => 'text'],
            'notificationtext' => ['text' => 'text'],
            'userleave' => ['text' => 'text'],
            'tags' => '',
            'completion' => 2,
            'showviews' => ['mybooking,myoptions,optionsiamresponsiblefor,showall,showactive,myinstitution'],
        ];
        return ['bdata' => [$bdata]];
    }
}

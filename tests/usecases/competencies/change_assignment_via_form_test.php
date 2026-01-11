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

namespace taskflowadapter_ksw\usecases\competencies;

use advanced_testcase;
use local_taskflow\local\external_adapter\external_api_base;
use local_taskflow\local\external_adapter\external_api_repository;
use local_taskflow\local\rules\rules;
use taskflowadapter_standard\form\editassignment;
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
final class change_assignment_via_form_test extends advanced_testcase {
    /** @var string|null Stores the external user data. */
    protected ?string $externaldata = null;

    /**
     * Setup the test environment.
     */
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        time_mock::init();
        time_mock::set_mock_time(strtotime('now'));
        $this->resetAfterTest(true);
        $this->preventResetByRollback();
        $this->externaldata = file_get_contents(__DIR__ . '/external_json/betty_best_ksw.json');
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('local_taskflow');
        $plugingenerator->create_custom_profile_fields([
            'supervisor',
            'orgunit',
            'externalid',
            'contractend',
            'exitdate',
            'Org1',
            'Org2',
            'Org3',
            'Org4',
            'Org5',
            'Org6',
            'Org7',
        ]);
        $plugingenerator->set_config_values('ksw');
    }

    /**
     * Tear down the test environment.
     *
     * @return void
     *
     */
    protected function tearDown(): void {
        parent::tearDown();
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('local_taskflow');
        $plugingenerator->teardown();
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
    public function test_changing_assignment_via_form_to_complete(array $bdata): void {
        global $DB;
        singleton_service::destroy_instance();
        $sink = $this->redirectEmails();
        [$user1, $user2, $user3, $rule, $booking, $course, $competency, $competency2] = $this->betty_best_base($bdata);

        $sink = $this->redirectEmails();
        $lock = $this->createMock(\core\lock\lock::class);
        $cronlock = $this->createMock(\core\lock\lock::class);

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

        time_mock::set_mock_time(strtotime('+ 6 minutes', time()));
        $plugingeneratortf = self::getDataGenerator()->get_plugin_generator('local_taskflow');
        $plugingeneratortf->runtaskswithintime($cronlock, $lock, time());
        // Create a booking option answer - book user2.
        $result = $plugingenerator->create_answer(['optionid' => $option1->id, 'userid' => $user2->id]);
        $this->assertSame(MOD_BOOKING_BO_COND_ALREADYBOOKED, $result);
        singleton_service::destroy_booking_answers($option1->id);

        // Complete booking option for user2.
        $settings = singleton_service::get_instance_of_booking_option_settings($option1->id);
        $option = singleton_service::get_instance_of_booking_option($settings->cmid, $settings->id);

        $plugingeneratortf->runtaskswithintime($cronlock, $lock, time());
        $sentmessages = $DB->get_records('local_taskflow_sent_messages', ['userid' => $user2->id ]);
        $messagesink = array_filter($sink->get_messages(), function ($message) use ($user2) {
            return (strpos($message->subject, 'Taskflow -') === 0 && $message->to == $user2->email);
        });
        // Assigned mail.
        $this->assertCount(1, $messagesink);
        $this->assertCount(1, $sentmessages);

        $assignment = $DB->get_record('local_taskflow_assignment', ['userid' => $user2->id]);
        $this->assertSame(assignment_status_facade::get_status_identifier('enrolled'), (int)$assignment->status);

        $duedate = $assignment->duedate;
        $duedatearray = [
            'day' => (int) date('j', $duedate),
            'month' => (int) date('n', $duedate),
            'year' => (int) date('Y', $duedate),
        ];

        $submitdata = [
            'id' => $assignment->id,
            'status' => assignment_status_facade::get_status_identifier('completed'),
            'change_reason' => 10,
            'comment' => 'Completed via form submission',
            'duedate' => $duedatearray,
            'keepchanges' => false,
         ];

        $submitdata = editassignment::mock_ajax_submit($submitdata);

        $editassignmentform = new editassignment(
            null,
            $submitdata,
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
        // phpcs:ignore Squiz.PHP.CommentedOutCode.Found
        /* $this->assertTrue($editassignmentform->is_validated()); */
        // Process the "AJAX" submission.
        $editassignmentform->process_dynamic_submission();

        time_mock::set_mock_time(strtotime('+ 6 minutes', time()));
        $plugingeneratortf->runtaskswithintime($cronlock, $lock, time());

        $assignment = $DB->get_record('local_taskflow_assignment', ['userid' => $user2->id]);
        $this->assertSame(assignment_status_facade::get_status_identifier('completed'), (int)$assignment->status);
        // 7 => assigned, assigned email, enrolled, booking enrolled, manual change via form, completed, completed msg.
        $historylogs = $DB->get_records('local_taskflow_history', ['assignmentid' => $assignment->id]);
        $this->assertSame(7, count($historylogs));
        $sentmessages = $DB->get_records('local_taskflow_sent_messages', ['userid' => $user2->id ]);
        $messagesink = array_filter($sink->get_messages(), function ($message) use ($user2) {
            return (strpos($message->subject, 'Taskflow -') === 0 && $message->to == $user2->email);
        });
        // Assigned and completed mail.
        $this->assertCount(2, $messagesink);
        $assignedmail = array_filter($messagesink, function ($message) {
            return $message->subject == 'Taskflow - Assigned';
        });
        $completedmail = array_filter($messagesink, function ($message) {
            return $message->subject == 'Taskflow - Completed';
        });
        $this->assertCount(1, $assignedmail);
        $this->assertCount(1, $completedmail);
        $this->assertCount(2, $sentmessages);
        $this->tearDown();
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
    public function test_changing_assignment_via_form_back_to_assigned(array $bdata): void {
        global $DB;
        singleton_service::destroy_instance();
        $sink = $this->redirectEmails();
        [$user1, $user2, $user3, $rule, $booking, $course, $competency, $competency2] = $this->betty_best_base($bdata);

        $sink = $this->redirectEmails();
        $lock = $this->createMock(\core\lock\lock::class);
        $cronlock = $this->createMock(\core\lock\lock::class);

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

        time_mock::set_mock_time(strtotime('+ 6 minutes', time()));
        $plugingeneratortf = self::getDataGenerator()->get_plugin_generator('local_taskflow');
        $plugingeneratortf->runtaskswithintime($cronlock, $lock, time());
        // Create a booking option answer - book user2.
        $result = $plugingenerator->create_answer(['optionid' => $option1->id, 'userid' => $user2->id]);
        $this->assertSame(MOD_BOOKING_BO_COND_ALREADYBOOKED, $result);
        singleton_service::destroy_booking_answers($option1->id);

        // Complete booking option for user2.
        $settings = singleton_service::get_instance_of_booking_option_settings($option1->id);
        $option = singleton_service::get_instance_of_booking_option($settings->cmid, $settings->id);

        $plugingeneratortf->runtaskswithintime($cronlock, $lock, time());
        $sentmessages = $DB->get_records('local_taskflow_sent_messages', ['userid' => $user2->id ]);
        $messagesink = array_filter($sink->get_messages(), function ($message) use ($user2) {
            return (strpos($message->subject, 'Taskflow -') === 0 && $message->to == $user2->email);
        });
        // Assigned mail.
        $this->assertCount(1, $messagesink);
        $this->assertCount(1, $sentmessages);

        $option->toggle_user_completion($user2->id);
        $plugingeneratortf->runtaskswithintime($cronlock, $lock, time());

        $assignment = $DB->get_record('local_taskflow_assignment', ['userid' => $user2->id]);
        $this->assertSame(assignment_status_facade::get_status_identifier('completed'), (int)$assignment->status);

        $duedate = $assignment->duedate;
        $duedatearray = [
            'day' => (int) date('j', $duedate),
            'month' => (int) date('n', $duedate),
            'year' => (int) date('Y', $duedate),
        ];

        $submitdata = [
            'id' => $assignment->id,
            'status' => assignment_status_facade::get_status_identifier('assigned'),
            'change_reason' => 10,
            'comment' => 'Completed via form submission',
            'duedate' => $duedatearray,
            'keepchanges' => false,
         ];

        $submitdata = editassignment::mock_ajax_submit($submitdata);

        $editassignmentform = new editassignment(
            null,
            $submitdata,
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
        // phpcs:ignore Squiz.PHP.CommentedOutCode.Found
        /* $this->assertTrue($editassignmentform->is_validated()); */
        // Process the "AJAX" submission.
        $editassignmentform->process_dynamic_submission();

        time_mock::set_mock_time(strtotime('+ 6 minutes', time()));
        $plugingeneratortf->runtaskswithintime($cronlock, $lock, time());

        $assignment = $DB->get_record('local_taskflow_assignment', ['userid' => $user2->id]);
        $this->assertSame(assignment_status_facade::get_status_identifier('assigned'), (int)$assignment->status);
        // 8 => assigned, assigned email, enrolled, booking enrolled, competed, completed msg, manual change, assigned.
        $historylogs = $DB->get_records('local_taskflow_history', ['assignmentid' => $assignment->id]);
        $this->assertSame(8, count($historylogs));
        $sentmessages = $DB->get_records('local_taskflow_sent_messages', ['userid' => $user2->id ]);
        $messagesink = array_filter($sink->get_messages(), function ($message) use ($user2) {
            return (strpos($message->subject, 'Taskflow -') === 0 && $message->to == $user2->email);
        });
        // Assigned and completed mail.
        $this->assertCount(1, $messagesink);
        $assignedmail = array_filter($messagesink, function ($message) {
            return $message->subject == 'Taskflow - Assigned';
        });
        $completedmail = array_filter($messagesink, function ($message) {
            return $message->subject == 'Taskflow - Completed';
        });
        $this->assertCount(1, $assignedmail);
        $this->assertCount(0, $completedmail);
        $this->assertCount(1, $sentmessages);
        $this->tearDown();
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
    public function test_changing_assignment_via_form_extend_duedate(array $bdata): void {
        global $DB;
        singleton_service::destroy_instance();
        $sink = $this->redirectEmails();
        [$user1, $user2, $user3, $rule, $booking, $course, $competency, $competency2] = $this->betty_best_base($bdata);

        $sink = $this->redirectEmails();
        $lock = $this->createMock(\core\lock\lock::class);
        $cronlock = $this->createMock(\core\lock\lock::class);

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

        time_mock::set_mock_time(strtotime('+ 6 minutes', time()));
        $plugingeneratortf = self::getDataGenerator()->get_plugin_generator('local_taskflow');
        $plugingeneratortf->runtaskswithintime($cronlock, $lock, time());
        // Create a booking option answer - book user2.
        $result = $plugingenerator->create_answer(['optionid' => $option1->id, 'userid' => $user2->id]);
        $this->assertSame(MOD_BOOKING_BO_COND_ALREADYBOOKED, $result);
        singleton_service::destroy_booking_answers($option1->id);

        // Complete booking option for user2.
        $settings = singleton_service::get_instance_of_booking_option_settings($option1->id);
        $option = singleton_service::get_instance_of_booking_option($settings->cmid, $settings->id);

        time_mock::set_mock_time(strtotime('+ 31 days', time()));

        $plugingeneratortf->runtaskswithintime($cronlock, $lock, time());
        $sentmessages = $DB->get_records('local_taskflow_sent_messages', ['userid' => $user2->id ]);
        $messagesink = array_filter($sink->get_messages(), function ($message) use ($user2) {
            return (strpos($message->subject, 'Taskflow -') === 0 && $message->to == $user2->email);
        });
        // Assigned mail, warn1, warn2, overdue.
        $this->assertCount(4, $messagesink);
        $this->assertCount(4, $sentmessages);

        $assignment = $DB->get_record('local_taskflow_assignment', ['userid' => $user2->id]);
        $this->assertSame(assignment_status_facade::get_status_identifier('overdue'), (int)$assignment->status);

        $duedate = $assignment->duedate + 86400 * 5; // Extend duedate by 5 day.
        $olddue = $assignment->duedate;
        $duedatearray = [
            'day' => (int) date('j', $duedate),
            'month' => (int) date('n', $duedate),
            'year' => (int) date('Y', $duedate),
        ];

        $newduedate = strtotime("{$duedatearray['year']}-{$duedatearray['month']}-{$duedatearray['day']}");

        $submitdata = [
            'id' => $assignment->id,
            'status' => assignment_status_facade::get_status_identifier('prolonged'),
            'change_reason' => 10,
            'comment' => 'Completed via form submission',
            'duedate' => $duedatearray,
            'keepchanges' => false,
         ];

        $submitdata = editassignment::mock_ajax_submit($submitdata);

        $editassignmentform = new editassignment(
            null,
            $submitdata,
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
        // phpcs:ignore Squiz.PHP.CommentedOutCode.Found
        /* $this->assertTrue($editassignmentform->is_validated()); */
        // Process the "AJAX" submission.
        $editassignmentform->process_dynamic_submission();

        time_mock::set_mock_time(strtotime('+ 6 minutes', time()));
        $plugingeneratortf->runtaskswithintime($cronlock, $lock, time());

        $assignment = $DB->get_record('local_taskflow_assignment', ['userid' => $user2->id]);
        $this->assertSame(assignment_status_facade::get_status_identifier('prolonged'), (int)$assignment->status);
        $this->assertSame($newduedate, (int)$assignment->duedate);
        // 10 => assigned, assigned email, enrolled..
        // Status overdue warn1mail, warn2 mail, overdue mainl,booking enrolled, manual change via form, proloned,.
        $historylogs = $DB->get_records('local_taskflow_history', ['assignmentid' => $assignment->id]);
        $this->assertSame(10, count($historylogs));
        $sentmessages = $DB->get_records('local_taskflow_sent_messages', ['userid' => $user2->id ]);
        $messagesink = array_filter($sink->get_messages(), function ($message) use ($user2) {
            return (strpos($message->subject, 'Taskflow -') === 0 && $message->to == $user2->email);
        });
        // Assigned, warn1 warn2, overdue.
        $this->assertCount(4, $messagesink);
        $assignedmail = array_filter($messagesink, function ($message) {
            return $message->subject == 'Taskflow - Assigned';
        });
        $warn1mail = array_filter($messagesink, function ($message) {
            return $message->subject == 'Taskflow - Warning 1';
        });
        $warn2mail = array_filter($messagesink, function ($message) {
            return $message->subject == 'Taskflow - Warning 2';
        });
        $overduemail = array_filter($messagesink, function ($message) {
            return $message->subject == 'Taskflow - Overdue';
        });
        $completedmail = array_filter($messagesink, function ($message) {
            return $message->subject == 'Taskflow - Completed';
        });
        $this->assertCount(1, $assignedmail);
        $this->assertCount(1, $warn1mail);
        $this->assertCount(1, $warn2mail);
        $this->assertCount(1, $overduemail);
        $this->assertCount(0, $completedmail);
        $this->assertCount(4, $sentmessages);
        $this->tearDown();
    }

    /**
     * Test rulestemplate on option being completed for user.
     * @param array $bdata
     * @return array
     *
     */
    public function betty_best_base(array $bdata): array {
        global $DB;
        singleton_service::destroy_instance();
        $lock = $this->createMock(\core\lock\lock::class);
        $cronlock = $this->createMock(\core\lock\lock::class);
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('local_taskflow');
        $this->setAdminUser();
        $apidatamanager = external_api_repository::create($this->externaldata);
        $externaldata = $apidatamanager->get_external_data();
        $this->assertNotEmpty($externaldata, 'External user data should not be empty.');
        $apidatamanager->process_incoming_data();
        // Allow optioncacellation.
        $bdata['cancancelbook'] = 1;

        $course = $this->set_db_course();
        $user3 = $DB->get_record('user', ['email' => 'chris.change@ksw.ch']);
        $user2 = $DB->get_record('user', ['email' => 'betty.best@ksw.ch']);
        $user1 = $DB->get_record('user', ['email' => 'berta.boss@ksw.ch']);

        $scale = $this->getDataGenerator()->create_scale([
            'scale' => 'Not proficient,Proficient',
            'name' => 'Test Competency Scale',
        ]);

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
        $messageids = $this->set_messages_db();
        $cohorts = $DB->get_records('cohort');
        $cohort = array_shift($cohorts);
        $rule = $this->get_rule($cohort->id, $competency->get('id'), $messageids);
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
        $plugingenerator->runtaskswithintime($cronlock, $lock, time());
        $assignment = $DB->get_records('local_taskflow_assignment');
        $plugingenerator->runtaskswithintime($cronlock, $lock, time());

        $bdata['course'] = $course->id;
        $bdata['bookingmanager'] = $user1->username;

        $booking = $this->getDataGenerator()->create_module('booking', $bdata);
        set_config('usecompetencies', 1, 'booking');

        $this->setAdminUser();

        $this->getDataGenerator()->enrol_user($user1->id, $course->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user($user2->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($user3->id, $course->id, 'editingteacher');

        // User 2 doesnt have competency yet in this case.
        $existing = user_competency::get_records(['userid' => $user2->id]);
        $this->assertEmpty($existing, 'User already has a competency assigned');

        return [$user1, $user2, $user3, $rule, $booking, $course, $competency, $competency2];
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
     * @return object
     */
    protected function set_db_course(): mixed {
        // Create a user.
        $course = $this->getDataGenerator()->create_course([
            'fullname' => 'Test Course',
            'shortname' => 'TC101',
            'category' => 1,
            'enablecompletion' => 1,
        ]);
        return $course;
    }

    /**
     * Setup the test environment.
     */
    protected function set_messages_db(): array {
        global $DB;
        $messageids = [];
        $messages = json_decode(file_get_contents(__DIR__ . '/../../mock/messages/messageswithcompletion.json'));
        foreach ($messages as $message) {
            $messageids[] = (object)['messageid' => $DB->insert_record('local_taskflow_messages', $message)];
        }
        return $messageids;
    }

    /**
     * Setup the test environment.
     * @param int $unitid
     * @param int $targetid
     * @param array $messageids
     * @return array
     */
    public function get_rule($unitid, $targetid, $messageids): array {
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
                        "cyclicduration" => -938361600,
                        "fixeddate" => 23233232222,
                        "duration" => 86400 * 30,
                        "timemodified" => time(),
                        "timecreated" => time(),
                        "usermodified" => 1,
                        "recursive" => 0,
                        "inheritance" => 1,
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
                                "messages" => $messageids,
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

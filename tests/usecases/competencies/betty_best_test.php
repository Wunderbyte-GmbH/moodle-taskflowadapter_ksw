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
use completion_completion;
use completion_criteria_self;
use completion_info;
use core_user;
use local_taskflow\local\external_adapter\external_api_base;
use local_taskflow\local\external_adapter\external_api_repository;
use local_taskflow\local\rules\rules;
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
use function PHPUnit\Framework\assertSame;

/**
 * Tests for booking rules.
 *
 * @package taskflowadapter_ksw
 * @category test
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class betty_best_test extends advanced_testcase {
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
     * @runInSeparateProcess
     */
    public function test_assign_competency_on_option_completion_using_task_for_completion(array $bdata): void {
        global $DB, $CFG;

        [$user1, $user2, $booking, $course, $competency, $competency2] = $this->betty_best_base($bdata);
         $sink = $this->redirectEmails();
        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $lock = $this->createMock(\core\lock\lock::class);
        $cronlock = $this->createMock(\core\lock\lock::class);
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

        singleton_service::destroy_instance();

        $assignments = $DB->get_records('local_taskflow_assignment', ['userid' => $user2->id ]);
        $this->assertCount(1, $assignments);
        $assignment = array_shift($assignments);
        $this->assertSame((int)$assignment->status, assignment_status_facade::get_status_identifier('assigned'));

        // Count should be 1 - assigned.
        $initialcount = count($assignmenthistory = $DB->get_records('local_taskflow_history', ['assignmentid' => $assignment->id]));
        $this->assertSame(1, $initialcount);
        time_mock::set_mock_time(strtotime('+ 6 minutes', time()));
        $plugingeneratortf = self::getDataGenerator()->get_plugin_generator('local_taskflow');
        $plugingeneratortf->runtaskswithintime($cronlock, $lock, time());

        // Assigned + assigned mail.
        $countafter = count($assignmenthistory = $DB->get_records('local_taskflow_history', ['assignmentid' => $assignment->id]));
        $this->assertSame($initialcount + 1, $countafter);

        // Create a booking option answer - book user2.
        $result = $plugingenerator->create_answer(['optionid' => $option1->id, 'userid' => $user2->id]);

        $this->assertSame(MOD_BOOKING_BO_COND_ALREADYBOOKED, $result);
        singleton_service::destroy_instance();

        // Complete booking option for user2.
        $settings = singleton_service::get_instance_of_booking_option_settings($option1->id);
        $option = singleton_service::get_instance_of_booking_option($settings->cmid, $settings->id);

        $assignments = $DB->get_records('local_taskflow_assignment', ['userid' => $user2->id ]);
        $this->assertCount(1, $assignments);
        $assignment = array_shift($assignments);
        // We dont exclude enrolled in this project.
        $this->assertSame((int)$assignment->status, assignment_status_facade::get_status_identifier('enrolled'));

        $plugingenerator = self::getDataGenerator()->get_plugin_generator('local_taskflow');
        $plugingenerator->runtaskswithintime($cronlock, $lock, time());

        // Should be assigned + assigned msg + enrolled + booking course enrol1 + booking course enrol2.
        $countafter = count($assignmenthistory = $DB->get_records('local_taskflow_history', ['assignmentid' => $assignment->id]));
        $this->assertSame($initialcount + 4, $countafter);

        $sentmessages = $DB->get_records('local_taskflow_sent_messages', ['userid' => $user2->id ]);
        // Assignment mail should be sent.
        $this->assertCount(1, $sentmessages);
        $messagesink = array_filter($sink->get_messages(), function ($message) {
            return strpos($message->subject, 'Taskflow -') === 0;
        });
        $messagesinkuser2 = array_filter($messagesink, function ($msg) use ($user2) {
            return $msg->to === $user2->email;
        });
        $this->assertCount(1, $messagesinkuser2);
        $this->assertCount(3, $messagesink);

        $this->assertSame(0, $option->user_completed_option());

        // setUser_completion now uses a task to complete the option.
        $this->setUser($user2);
        $option->toggle_user_completion($user2->id);

        // Run all adhoc tasks now.
        $plugingenerator->runtaskswithintime($cronlock, $lock, time());

        // Should be assigned + assigned msg + enrolled + booking course enrol1
        // + booking course enrol2 + completed status + comp1 completed + comp2 completed.
        $countafter = count($assignmenthistory = $DB->get_records('local_taskflow_history', ['assignmentid' => $assignment->id]));
        $this->assertSame($initialcount + 7, $countafter);

        $plugingenerator->runtaskswithintime($cronlock, $lock, time());

        require_once($CFG->dirroot . '/user/lib.php');
        user_update_user((object)[
            'id' => $user2->id,
            'firstname' => 'UpdatedFirstName',
            'lastname' => 'UpdatedLastName',
        ]);

        // Should be assigned + assigned msg.
        $countafter = count($assignmenthistory = $DB->get_records('local_taskflow_history', ['assignmentid' => $assignment->id]));
        $this->assertSame($initialcount + 7, $countafter);

        $this->assertSame(1, $option->user_completed_option());
        $assignments = $DB->get_records('local_taskflow_assignment', ['userid' => $user2->id ]);
        $this->assertCount(1, $assignments);
        $assignment = array_shift($assignments);
        $this->assertSame((int)$assignment->status, assignment_status_facade::get_status_identifier('completed'));
        $sentmessages = $DB->get_records('local_taskflow_sent_messages', ['userid' => $user2->id ]);
        // Should have no msg.
        $this->assertCount(1, $sentmessages);
        $messagesink = array_filter($sink->get_messages(), function ($message) {
            return strpos($message->subject, 'Taskflow -') === 0;
        });
        $messagesinkuser2 = array_filter($messagesink, function ($msg) use ($user2) {
            return $msg->to === $user2->email;
        });
        $this->assertCount(1, $sentmessages);
        $this->assertCount(1, $messagesinkuser2);
        $this->assertCount(3, $messagesink);

        // Some time passes, we trigger the rule again, status should be unchanged.
        time_mock::set_mock_time(strtotime('+ 6 minutes', time()));

        $rule = (array)$DB->get_record('local_taskflow_rules', []);

        // Run all adhoc tasks now.
        $event = rule_created_updated::create([
            'objectid' => $rule['id'],
            'context'  => context_system::instance(),
            'other'    => [
                'ruledata' => $rule,
            ],
        ]);
        $event->trigger();
        $plugingenerator->runtaskswithintime($cronlock, $lock, time());

        $assignments = $DB->get_records('local_taskflow_assignment', ['userid' => $user2->id ]);
        $this->assertCount(1, $assignments);
        $assignment = array_shift($assignments);
        $this->assertSame((int)$assignment->status, assignment_status_facade::get_status_identifier('completed'));
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
     * @runInSeparateProcess
     */
    public function test_assign_competency_on_option_completion(array $bdata): void {
        global $DB, $CFG;

        [$user1, $user2, $booking, $course, $competency, $competency2] = $this->betty_best_base($bdata);
         $sink = $this->redirectEmails();
        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $lock = $this->createMock(\core\lock\lock::class);
        $cronlock = $this->createMock(\core\lock\lock::class);
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

        singleton_service::destroy_instance();

        $assignments = $DB->get_records('local_taskflow_assignment', ['userid' => $user2->id ]);
        $this->assertCount(1, $assignments);
        $assignment = array_shift($assignments);
        $this->assertSame((int)$assignment->status, assignment_status_facade::get_status_identifier('assigned'));

        // Count should be 1 - assigned.
        $initialcount = count($assignmenthistory = $DB->get_records('local_taskflow_history', ['assignmentid' => $assignment->id]));
        $this->assertSame(1, $initialcount);
        time_mock::set_mock_time(strtotime('+ 6 minutes', time()));
        $plugingeneratortf = self::getDataGenerator()->get_plugin_generator('local_taskflow');
        $plugingeneratortf->runtaskswithintime($cronlock, $lock, time());

        // Assigned + assigned mail.
        $countafter = count($assignmenthistory = $DB->get_records('local_taskflow_history', ['assignmentid' => $assignment->id]));
        $this->assertSame($initialcount + 1, $countafter);

        // Create a booking option answer - book user2.
        $result = $plugingenerator->create_answer(['optionid' => $option1->id, 'userid' => $user2->id]);

        $this->assertSame(MOD_BOOKING_BO_COND_ALREADYBOOKED, $result);
        singleton_service::destroy_instance();

        // Complete booking option for user2.
        $settings = singleton_service::get_instance_of_booking_option_settings($option1->id);
        $option = singleton_service::get_instance_of_booking_option($settings->cmid, $settings->id);

        $assignments = $DB->get_records('local_taskflow_assignment', ['userid' => $user2->id ]);
        $this->assertCount(1, $assignments);
        $assignment = array_shift($assignments);
        // We dont exclude enrolled in this project.
        $this->assertSame((int)$assignment->status, assignment_status_facade::get_status_identifier('enrolled'));

        $plugingenerator = self::getDataGenerator()->get_plugin_generator('local_taskflow');
        $plugingenerator->runtaskswithintime($cronlock, $lock, time());

        // Should be assigned + assigned msg + enrolled + booking course enrol1 + booking course enrol2.
        $countafter = count($assignmenthistory = $DB->get_records('local_taskflow_history', ['assignmentid' => $assignment->id]));
        $this->assertSame($initialcount + 4, $countafter);

        $sentmessages = $DB->get_records('local_taskflow_sent_messages', ['userid' => $user2->id ]);
        // Assignment mail should be sent.
        $this->assertCount(1, $sentmessages);
        $messagesink = array_filter($sink->get_messages(), function ($message) {
            return strpos($message->subject, 'Taskflow -') === 0;
        });
        $messagesinkuser2 = array_filter($messagesink, function ($msg) use ($user2) {
            return $msg->to === $user2->email;
        });
        $this->assertCount(1, $messagesinkuser2);
        $this->assertCount(3, $messagesink);

        $this->assertSame(0, $option->user_completed_option());
        $option->toggle_user_completion($user2->id);

        // Run all adhoc tasks now.
        $plugingenerator->runtaskswithintime($cronlock, $lock, time());

        // Should be assigned + assigned msg + enrolled + booking course enrol1
        // + booking course enrol2 + completed status + comp1 completed + comp2 completed.
        $countafter = count($assignmenthistory = $DB->get_records('local_taskflow_history', ['assignmentid' => $assignment->id]));
        $this->assertSame($initialcount + 7, $countafter);

        $plugingenerator->runtaskswithintime($cronlock, $lock, time());

        require_once($CFG->dirroot . '/user/lib.php');
        user_update_user((object)[
            'id' => $user2->id,
            'firstname' => 'UpdatedFirstName',
            'lastname' => 'UpdatedLastName',
        ]);

        // Should be assigned + assigned msg.
        $countafter = count($assignmenthistory = $DB->get_records('local_taskflow_history', ['assignmentid' => $assignment->id]));
        $this->assertSame($initialcount + 7, $countafter);

        $this->assertSame(1, $option->user_completed_option());
        $assignments = $DB->get_records('local_taskflow_assignment', ['userid' => $user2->id ]);
        $this->assertCount(1, $assignments);
        $assignment = array_shift($assignments);
        $this->assertSame((int)$assignment->status, assignment_status_facade::get_status_identifier('completed'));
        $sentmessages = $DB->get_records('local_taskflow_sent_messages', ['userid' => $user2->id ]);
        // Should have no msg.
        $this->assertCount(1, $sentmessages);
        $messagesink = array_filter($sink->get_messages(), function ($message) {
            return strpos($message->subject, 'Taskflow -') === 0;
        });
        $messagesinkuser2 = array_filter($messagesink, function ($msg) use ($user2) {
            return $msg->to === $user2->email;
        });
        $this->assertCount(1, $sentmessages);
        $this->assertCount(1, $messagesinkuser2);
        $this->assertCount(3, $messagesink);

        // Some time passes, we trigger the rule again, status should be unchanged.
        time_mock::set_mock_time(strtotime('+ 6 minutes', time()));

        $rule = (array)$DB->get_record('local_taskflow_rules', []);

        // Run all adhoc tasks now.
        $event = rule_created_updated::create([
            'objectid' => $rule['id'],
            'context'  => context_system::instance(),
            'other'    => [
                'ruledata' => $rule,
            ],
        ]);
        $event->trigger();
        $plugingenerator->runtaskswithintime($cronlock, $lock, time());

        $assignments = $DB->get_records('local_taskflow_assignment', ['userid' => $user2->id ]);
        $this->assertCount(1, $assignments);
        $assignment = array_shift($assignments);
        $this->assertSame((int)$assignment->status, assignment_status_facade::get_status_identifier('completed'));
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
     * @runInSeparateProcess
     */
    public function test_assign_competency_on_course_completion(array $bdata): void {
        global $DB, $CFG;

        [$user1, $user2, $booking, $course, $competency, $competency2] = $this->betty_best_base($bdata);

        require_once($CFG->dirroot . '/completion/criteria/completion_criteria_self.php');
        require_once($CFG->dirroot . '/completion/completion_completion.php');
        require_once($CFG->dirroot . '/completion/criteria/completion_criteria_self.php');

        $completion = new completion_info($course);
        $criteriadata = new stdClass();
        $criteriadata->id = $course->id;
        $criteriadata->criteria_self = 1;

        $criterion = new completion_criteria_self();
        $criterion->update_config($criteriadata);

         $sink = $this->redirectEmails();
        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $lock = $this->createMock(\core\lock\lock::class);
        $cronlock = $this->createMock(\core\lock\lock::class);
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

        singleton_service::destroy_instance();

        $assignments = $DB->get_records('local_taskflow_assignment', ['userid' => $user2->id ]);
        $this->assertCount(1, $assignments);
        $assignment = array_shift($assignments);
        $this->assertSame((int)$assignment->status, assignment_status_facade::get_status_identifier('assigned'));

        // Count should be 1 - assigned.
        $initialcount = count($assignmenthistory = $DB->get_records('local_taskflow_history', ['assignmentid' => $assignment->id]));
        $this->assertSame(1, $initialcount);
        time_mock::set_mock_time(strtotime('+ 6 minutes', time()));
        $plugingeneratortf = self::getDataGenerator()->get_plugin_generator('local_taskflow');
        $plugingeneratortf->runtaskswithintime($cronlock, $lock, time());

        // Assigned + assigned mail.
        $countafter = count($assignmenthistory = $DB->get_records('local_taskflow_history', ['assignmentid' => $assignment->id]));
        $this->assertSame($initialcount + 1, $countafter);

        // Create a booking option answer - book user2.
        $result = $plugingenerator->create_answer(['optionid' => $option1->id, 'userid' => $user2->id]);

        $this->assertSame(MOD_BOOKING_BO_COND_ALREADYBOOKED, $result);
        singleton_service::destroy_instance();

        // Complete booking option for user2.
        $settings = singleton_service::get_instance_of_booking_option_settings($option1->id);
        $option = singleton_service::get_instance_of_booking_option($settings->cmid, $settings->id);

        $assignments = $DB->get_records('local_taskflow_assignment', ['userid' => $user2->id ]);
        $this->assertCount(1, $assignments);
        $assignment = array_shift($assignments);
        // We dont exclude enrolled in this project.
        $this->assertSame((int)$assignment->status, assignment_status_facade::get_status_identifier('enrolled'));

        $plugingenerator = self::getDataGenerator()->get_plugin_generator('local_taskflow');
        $plugingenerator->runtaskswithintime($cronlock, $lock, time());

        // Should be assigned + assigned msg + enrolled + booking course enrol1 + booking course enrol2.
        $countafter = count($assignmenthistory = $DB->get_records('local_taskflow_history', ['assignmentid' => $assignment->id]));
        $this->assertSame($initialcount + 4, $countafter);

        $sentmessages = $DB->get_records('local_taskflow_sent_messages', ['userid' => $user2->id ]);
        // Assignment mail should be sent.
        $this->assertCount(1, $sentmessages);
        $messagesink = array_filter($sink->get_messages(), function ($message) {
            return strpos($message->subject, 'Taskflow -') === 0;
        });
        $messagesinkuser2 = array_filter($messagesink, function ($msg) use ($user2) {
            return $msg->to === $user2->email;
        });
        $this->assertCount(1, $messagesinkuser2);
        $this->assertCount(3, $messagesink);

        $this->assertSame(0, $option->user_completed_option());

        $this->setUser($user2);
        $ccompletion = new completion_completion(['userid' => $user2->id, 'course' => $course->id]);
        $ccompletion->mark_complete();

        // Run all adhoc tasks now.
        $this->setAdminUser();
        time_mock::set_mock_time(strtotime('+ 1 minute', time()));
        $plugingenerator->runtaskswithintime($cronlock, $lock, time());

        // Should be assigned + assigned msg + enrolled + booking course enrol1
        // + booking course enrol2 + completed status + comp1 completed + comp2 completed.
        $countafter = count($assignmenthistory = $DB->get_records('local_taskflow_history', ['assignmentid' => $assignment->id]));
        $this->assertSame($initialcount + 7, $countafter);

        $plugingenerator->runtaskswithintime($cronlock, $lock, time());

        require_once($CFG->dirroot . '/user/lib.php');
        user_update_user((object)[
            'id' => $user2->id,
            'firstname' => 'UpdatedFirstName',
            'lastname' => 'UpdatedLastName',
        ]);

        // Should be assigned + assigned msg.
        $countafter = count($assignmenthistory = $DB->get_records('local_taskflow_history', ['assignmentid' => $assignment->id]));
        $this->assertSame($initialcount + 7, $countafter);

        $this->assertSame(1, $option->user_completed_option());
        $assignments = $DB->get_records('local_taskflow_assignment', ['userid' => $user2->id ]);
        $this->assertCount(1, $assignments);
        $assignment = array_shift($assignments);
        $this->assertSame((int)$assignment->status, assignment_status_facade::get_status_identifier('completed'));
        $sentmessages = $DB->get_records('local_taskflow_sent_messages', ['userid' => $user2->id ]);
        // Should have no msg.
        $this->assertCount(1, $sentmessages);
        $messagesink = array_filter($sink->get_messages(), function ($message) {
            return strpos($message->subject, 'Taskflow -') === 0;
        });
        $messagesinkuser2 = array_filter($messagesink, function ($msg) use ($user2) {
            return $msg->to === $user2->email;
        });
        $this->assertCount(1, $sentmessages);
        $this->assertCount(1, $messagesinkuser2);
        $this->assertCount(3, $messagesink);

        // Some time passes, we trigger the rule again, status should be unchanged.
        time_mock::set_mock_time(strtotime('+ 6 minutes', time()));

        $rule = (array)$DB->get_record('local_taskflow_rules', []);

        // Run all adhoc tasks now.
        $event = rule_created_updated::create([
            'objectid' => $rule['id'],
            'context'  => context_system::instance(),
            'other'    => [
                'ruledata' => $rule,
            ],
        ]);
        $event->trigger();
        $plugingenerator->runtaskswithintime($cronlock, $lock, time());

        $assignments = $DB->get_records('local_taskflow_assignment', ['userid' => $user2->id ]);
        $this->assertCount(1, $assignments);
        $assignment = array_shift($assignments);
        $this->assertSame((int)$assignment->status, assignment_status_facade::get_status_identifier('completed'));
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
    public function test_assign_competency_on_course_completion_in_hierarchy(array $bdata): void {
        global $DB, $CFG;

        [$user1, $user2, $booking, $course, $competency, $competency2] = $this->betty_best_base($bdata);

        require_once($CFG->dirroot . '/completion/criteria/completion_criteria_self.php');
        require_once($CFG->dirroot . '/completion/completion_completion.php');
        require_once($CFG->dirroot . '/completion/criteria/completion_criteria_self.php');

        $completion = new completion_info($course);
        $criteriadata = new stdClass();
        $criteriadata->id = $course->id;
        $criteriadata->criteria_self = 1;

        $criterion = new completion_criteria_self();
        $criterion->update_config($criteriadata);

         $sink = $this->redirectEmails();
        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $lock = $this->createMock(\core\lock\lock::class);
        $cronlock = $this->createMock(\core\lock\lock::class);
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

        singleton_service::destroy_instance();

        $user3 = $DB->get_record('user', ['email' => 'berta.boss@ksw.ch']);

        $assignments = $DB->get_records('local_taskflow_assignment', ['userid' => $user3->id ]);
        $this->assertCount(1, $assignments);
        $assignment = array_shift($assignments);
        $this->assertSame((int)$assignment->status, assignment_status_facade::get_status_identifier('assigned'));

        // Count should be 1 - assigned.
        $initialcount = count($assignmenthistory = $DB->get_records('local_taskflow_history', ['assignmentid' => $assignment->id]));
        $this->assertSame(1, $initialcount);
        time_mock::set_mock_time(strtotime('+ 6 minutes', time()));
        $plugingeneratortf = self::getDataGenerator()->get_plugin_generator('local_taskflow');
        $plugingeneratortf->runtaskswithintime($cronlock, $lock, time());

        // Assigned + assigned mail.
        $countafter = count($assignmenthistory = $DB->get_records('local_taskflow_history', ['assignmentid' => $assignment->id]));
        $this->assertSame($initialcount + 1, $countafter);

        // Create a booking option answer - book user2.
        $result = $plugingenerator->create_answer(['optionid' => $option1->id, 'userid' => $user3->id]);

        $this->assertSame(MOD_BOOKING_BO_COND_ALREADYBOOKED, $result);
        singleton_service::destroy_instance();

        // Complete booking option for user2.
        $settings = singleton_service::get_instance_of_booking_option_settings($option1->id);
        $option = singleton_service::get_instance_of_booking_option($settings->cmid, $settings->id);

        $assignments = $DB->get_records('local_taskflow_assignment', ['userid' => $user3->id ]);
        $this->assertCount(1, $assignments);
        $assignment = array_shift($assignments);
        // We dont exclude enrolled in this project.
        $this->assertSame((int)$assignment->status, assignment_status_facade::get_status_identifier('enrolled'));

        $plugingenerator = self::getDataGenerator()->get_plugin_generator('local_taskflow');
        $plugingenerator->runtaskswithintime($cronlock, $lock, time());

        // Should be assigned + assigned msg + enrolled + booking course enrol1 + booking course enrol2.
        $countafter = count($assignmenthistory = $DB->get_records('local_taskflow_history', ['assignmentid' => $assignment->id]));
        $this->assertSame($initialcount + 4, $countafter);

        $sentmessages = $DB->get_records('local_taskflow_sent_messages', ['userid' => $user3->id ]);
        // Assignment mail should be sent.
        $this->assertCount(1, $sentmessages);
        $messagesink = array_filter($sink->get_messages(), function ($message) {
            return strpos($message->subject, 'Taskflow -') === 0;
        });
        $messagesinkuser2 = array_filter($messagesink, function ($msg) use ($user3) {
            return $msg->to === $user3->email;
        });
        $this->assertCount(1, $messagesinkuser2);
        $this->assertCount(3, $messagesink);

        $this->assertSame(0, $option->user_completed_option());

        $this->setUser($user3);
        $ccompletion = new completion_completion(['userid' => $user3->id, 'course' => $course->id]);
        $ccompletion->mark_complete();

        // Run all adhoc tasks now.
        $this->setAdminUser();
        time_mock::set_mock_time(strtotime('+ 1 minute', time()));
        $plugingenerator->runtaskswithintime($cronlock, $lock, time());

        // Should be assigned + assigned msg + enrolled + booking course enrol1
        // + booking course enrol2 + completed status + comp1 completed + comp2 completed.
        $countafter = count($assignmenthistory = $DB->get_records('local_taskflow_history', ['assignmentid' => $assignment->id]));
        $this->assertSame($initialcount + 7, $countafter);

        $plugingenerator->runtaskswithintime($cronlock, $lock, time());

        require_once($CFG->dirroot . '/user/lib.php');
        user_update_user((object)[
            'id' => $user3->id,
            'firstname' => 'UpdatedFirstName',
            'lastname' => 'UpdatedLastName',
        ]);

        // Should be assigned + assigned msg.
        $countafter = count($assignmenthistory = $DB->get_records('local_taskflow_history', ['assignmentid' => $assignment->id]));
        $this->assertSame($initialcount + 7, $countafter);

        $this->assertSame(1, $option->user_completed_option());
        $assignments = $DB->get_records('local_taskflow_assignment', ['userid' => $user3->id ]);
        $this->assertCount(1, $assignments);
        $assignment = array_shift($assignments);
        $this->assertSame((int)$assignment->status, assignment_status_facade::get_status_identifier('completed'));
        $sentmessages = $DB->get_records('local_taskflow_sent_messages', ['userid' => $user3->id ]);
        // Should have no msg.
        $this->assertCount(1, $sentmessages);
        $messagesink = array_filter($sink->get_messages(), function ($message) {
            return strpos($message->subject, 'Taskflow -') === 0;
        });
        $messagesinkuser2 = array_filter($messagesink, function ($msg) use ($user3) {
            return $msg->to === $user3->email;
        });
        $this->assertCount(1, $sentmessages);
        $this->assertCount(1, $messagesinkuser2);
        $this->assertCount(3, $messagesink);

        // Some time passes, we trigger the rule again, status should be unchanged.
        time_mock::set_mock_time(strtotime('+ 6 minutes', time()));

        $rule = (array)$DB->get_record('local_taskflow_rules', []);

        // Run all adhoc tasks now.
        $event = rule_created_updated::create([
            'objectid' => $rule['id'],
            'context'  => context_system::instance(),
            'other'    => [
                'ruledata' => $rule,
            ],
        ]);
        $event->trigger();
        $plugingenerator->runtaskswithintime($cronlock, $lock, time());

        $assignments = $DB->get_records('local_taskflow_assignment', ['userid' => $user3->id ]);
        $this->assertCount(1, $assignments);
        $assignment = array_shift($assignments);
        $this->assertSame((int)$assignment->status, assignment_status_facade::get_status_identifier('completed'));
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
    public function test_assign_competency_on_course_completion_in_hierarchy_multi(array $bdata): void {
        global $DB, $CFG;

        [$user1, $user2, $booking, $course, $competency, $competency2] = $this->betty_best_base($bdata);

        require_once($CFG->dirroot . '/completion/criteria/completion_criteria_self.php');
        require_once($CFG->dirroot . '/completion/completion_completion.php');
        require_once($CFG->dirroot . '/completion/criteria/completion_criteria_self.php');

        $completion = new completion_info($course);
        $criteriadata = new stdClass();
        $criteriadata->id = $course->id;
        $criteriadata->criteria_self = 1;

        $criterion = new completion_criteria_self();
        $criterion->update_config($criteriadata);

        $course2 = $this->set_db_course2();

         $sink = $this->redirectEmails();
        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $lock = $this->createMock(\core\lock\lock::class);
        $cronlock = $this->createMock(\core\lock\lock::class);
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
        $record->competencies = [$competency->get('id')];
        $option1 = $plugingenerator->create_option($record);

        $record = new stdClass();
        $record->bookingid = $booking->id;
        $record->text = 'football';
        $record->chooseorcreatecourse = 1; // Connected existing course.
        $record->courseid = $course2->id;
        $record->description = 'Will start tomorrow';
        $record->optiondateid_0 = "0";
        $record->daystonotify_0 = "0";
        $record->coursestarttime_0 = strtotime('20 June 2050 15:00');
        $record->courseendtime_0 = strtotime('20 July 2050 14:00');
        $record->teachersforoption = $user1->username;
        $record->teachersforoption = 0;
        $record->competencies = [$competency2->get('id')];
        $option2 = $plugingenerator->create_option($record);

        singleton_service::destroy_instance();

        $user3 = $DB->get_record('user', ['email' => 'berta.boss@ksw.ch']);

        $assignments = $DB->get_records('local_taskflow_assignment', ['userid' => $user3->id ]);
        $this->assertCount(1, $assignments);
        $assignment = array_shift($assignments);
        $this->assertSame((int)$assignment->status, assignment_status_facade::get_status_identifier('assigned'));

        // Count should be 1 - assigned.
        $initialcount = count($assignmenthistory = $DB->get_records('local_taskflow_history', ['assignmentid' => $assignment->id]));
        $this->assertSame(1, $initialcount);
        time_mock::set_mock_time(strtotime('+ 6 minutes', time()));
        $plugingeneratortf = self::getDataGenerator()->get_plugin_generator('local_taskflow');
        $plugingeneratortf->runtaskswithintime($cronlock, $lock, time());

        // Assigned + assigned mail.
        $countafter = count($assignmenthistory = $DB->get_records('local_taskflow_history', ['assignmentid' => $assignment->id]));
        $this->assertSame($initialcount + 1, $countafter);

        // Create a booking option answer - book user2.
        $result = $plugingenerator->create_answer(['optionid' => $option1->id, 'userid' => $user3->id]);

        $result = $plugingenerator->create_answer(['optionid' => $option2->id, 'userid' => $user3->id]);

        $this->assertSame(MOD_BOOKING_BO_COND_ALREADYBOOKED, $result);
        singleton_service::destroy_instance();

        // Complete booking option for user2.
        $settings = singleton_service::get_instance_of_booking_option_settings($option1->id);
        $option = singleton_service::get_instance_of_booking_option($settings->cmid, $settings->id);

        $assignments = $DB->get_records('local_taskflow_assignment', ['userid' => $user3->id ]);
        $this->assertCount(1, $assignments);
        $assignment = array_shift($assignments);
        // We dont exclude enrolled in this project.
        $this->assertSame((int)$assignment->status, assignment_status_facade::get_status_identifier('enrolled'));

        $plugingenerator = self::getDataGenerator()->get_plugin_generator('local_taskflow');
        $plugingenerator->runtaskswithintime($cronlock, $lock, time());

        // Should be assigned + assigned msg + enrolled + booking course enrol1 + booking course enrol2.
        $countafter = count($assignmenthistory = $DB->get_records('local_taskflow_history', ['assignmentid' => $assignment->id]));
        $this->assertSame($initialcount + 4, $countafter);

        $sentmessages = $DB->get_records('local_taskflow_sent_messages', ['userid' => $user3->id ]);
        // Assignment mail should be sent.
        $this->assertCount(1, $sentmessages);
        $messagesink = array_filter($sink->get_messages(), function ($message) {
            return strpos($message->subject, 'Taskflow -') === 0;
        });
        $messagesinkuser2 = array_filter($messagesink, function ($msg) use ($user3) {
            return $msg->to === $user3->email;
        });
        $this->assertCount(1, $messagesinkuser2);
        $this->assertCount(3, $messagesink);

        $this->assertSame(0, $option->user_completed_option());

        $this->setUser($user3);
        $ccompletion = new completion_completion(['userid' => $user3->id, 'course' => $course->id]);
        $ccompletion->mark_complete();

        // Run all adhoc tasks now.
        $this->setAdminUser();
        time_mock::set_mock_time(strtotime('+ 1 minute', time()));
        $plugingenerator->runtaskswithintime($cronlock, $lock, time());

        // Should be assigned + assigned msg + enrolled + booking course enrol1
        // + booking course enrol2 + completed status + comp1 completed + comp2 completed.
        $countafter = count($assignmenthistory = $DB->get_records('local_taskflow_history', ['assignmentid' => $assignment->id]));
        $this->assertSame($initialcount + 6, $countafter);

        $this->setUser($user3);
        $ccompletion = new completion_completion(['userid' => $user3->id, 'course' => $course2->id]);
        $ccompletion->mark_complete();

        $this->setAdminUser();
        $plugingenerator->runtaskswithintime($cronlock, $lock, time());

        require_once($CFG->dirroot . '/user/lib.php');
        user_update_user((object)[
            'id' => $user3->id,
            'firstname' => 'UpdatedFirstName',
            'lastname' => 'UpdatedLastName',
        ]);

        // Should be assigned + assigned msg.
        $countafter = count($assignmenthistory = $DB->get_records('local_taskflow_history', ['assignmentid' => $assignment->id]));
        $this->assertSame($initialcount + 8, $countafter);

        $this->assertSame(1, $option->user_completed_option());
        $assignments = $DB->get_records('local_taskflow_assignment', ['userid' => $user3->id ]);
        $this->assertCount(1, $assignments);
        $assignment = array_shift($assignments);
        $this->assertSame((int)$assignment->status, assignment_status_facade::get_status_identifier('completed'));
        $sentmessages = $DB->get_records('local_taskflow_sent_messages', ['userid' => $user3->id ]);
        // Should have no msg.
        $this->assertCount(1, $sentmessages);
        $messagesink = array_filter($sink->get_messages(), function ($message) {
            return strpos($message->subject, 'Taskflow -') === 0;
        });
        $messagesinkuser2 = array_filter($messagesink, function ($msg) use ($user3) {
            return $msg->to === $user3->email;
        });
        $this->assertCount(1, $sentmessages);
        $this->assertCount(1, $messagesinkuser2);
        $this->assertCount(3, $messagesink);

        // Some time passes, we trigger the rule again, status should be unchanged.
        time_mock::set_mock_time(strtotime('+ 6 minutes', time()));

        $rule = (array)$DB->get_record('local_taskflow_rules', []);

        // Run all adhoc tasks now.
        $event = rule_created_updated::create([
            'objectid' => $rule['id'],
            'context'  => context_system::instance(),
            'other'    => [
                'ruledata' => $rule,
            ],
        ]);
        $event->trigger();
        $plugingenerator->runtaskswithintime($cronlock, $lock, time());

        $assignments = $DB->get_records('local_taskflow_assignment', ['userid' => $user3->id ]);
        $this->assertCount(1, $assignments);
        $assignment = array_shift($assignments);
        $this->assertSame((int)$assignment->status, assignment_status_facade::get_status_identifier('completed'));
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
    public function test_betty_best_user_update_after_partial_completion(array $bdata): void {
        global $DB, $CFG;

        [$user1, $user2, $booking, $course, $competency, $competency2] = $this->betty_best_base($bdata);
        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        $sink = $this->redirectEmails();
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
        $record->competencies = [$competency->get('id')];
        $option1 = $plugingenerator->create_option($record);

        // Create booking option 2.
        $record = new stdClass();
        $record->bookingid = $booking->id;
        $record->text = 'handball';
        $record->chooseorcreatecourse = 1; // Connected existing course.
        $record->courseid = $course->id;
        $record->description = 'Will start tomorrow';
        $record->optiondateid_0 = "0";
        $record->daystonotify_0 = "0";
        $record->coursestarttime_0 = strtotime('20 June 2050 15:00');
        $record->courseendtime_0 = strtotime('20 July 2050 14:00');
        $record->teachersforoption = $user1->username;
        $record->teachersforoption = 0;
        $record->competencies = [$competency2->get('id')];
        $option2 = $plugingenerator->create_option($record);

        singleton_service::destroy_instance();

        time_mock::set_mock_time(strtotime('+ 6 minutes', time()));
        $lock = $this->createMock(\core\lock\lock::class);
        $cronlock = $this->createMock(\core\lock\lock::class);
        $plugingeneratortf = self::getDataGenerator()->get_plugin_generator('local_taskflow');
        $plugingeneratortf->runtaskswithintime($cronlock, $lock, time());
        $sentmessages = $DB->get_records('local_taskflow_sent_messages', ['userid' => $user2->id ]);
        $this->assertCount(1, $sentmessages);
        $messagesink = array_filter($sink->get_messages(), function ($message) {
            return strpos($message->subject, 'Taskflow -') === 0;
        });
        // 3 users should have received messages.
        $this->assertCount(3, $messagesink);
        $messagesink = array_filter($messagesink, function ($msg) use ($user2) {
            return $msg->to === $user2->email;
        });
        $dbmsg = array_values($DB->get_records('local_taskflow_messages'));
        foreach ($dbmsg as $index => $msg) {
            $data = json_decode($msg->message);
            $dbmsg[$index]->subject = $data->heading;
        }
        foreach ($messagesink as $msg) {
            $this->assertTrue(
                $msg->to === $user2->email
            );
            $this->assertSame(
                $dbmsg[3]->subject,
                $msg->subject,
            );
        }
        $this->assertCount(1, $sentmessages);
        $assignment = $DB->get_record('local_taskflow_assignment', ['userid' => $user2->id]);
        // Should be 2 assigned + mail.
        $initialcount = count($assignmenthistory = $DB->get_records('local_taskflow_history', ['assignmentid' => $assignment->id]));
        $this->assertSame(2, $initialcount);
        $result = $plugingenerator->create_answer(['optionid' => $option1->id, 'userid' => $user2->id]);
        $this->assertSame(MOD_BOOKING_BO_COND_ALREADYBOOKED, $result);
        singleton_service::destroy_instance();

        // Complete booking option for user2.
        $settings = singleton_service::get_instance_of_booking_option_settings($option1->id);
        $option = singleton_service::get_instance_of_booking_option($settings->cmid, $settings->id);

        $assignments = $DB->get_records('local_taskflow_assignment', ['userid' => $user2->id ]);
        $this->assertCount(1, $assignments);
        $assignment = array_shift($assignments);
        // We exclude enrolled in this project.
        $this->assertSame((int)$assignment->status, assignment_status_facade::get_status_identifier('enrolled'));

        // Assigned + assigned msg + enrolled + booking course enrol1.
        $countafter = count($assignmenthistory = $DB->get_records('local_taskflow_history', ['assignmentid' => $assignment->id]));
        $this->assertSame($initialcount + 2, $countafter);
        $this->assertSame(0, $option->user_completed_option());
        $option->toggle_user_completion($user2->id);

        // Run all adhoc tasks now.
        $plugingeneratortf->runtaskswithintime($cronlock, $lock, time());

        $countafter = count($assignmenthistory = $DB->get_records('local_taskflow_history', ['assignmentid' => $assignment->id]));
        // 4 + partially completed and competency completed.
        $this->assertSame($initialcount + 4, $countafter);

        require_once($CFG->dirroot . '/user/lib.php');
        user_update_user((object)[
            'id' => $user2->id,
            'firstname' => 'UpdatedFirstName',
            'lastname' => 'UpdatedLastName',
        ]);

        $countafter = count($assignmenthistory = $DB->get_records('local_taskflow_history', ['assignmentid' => $assignment->id]));
        // 4 + partially completed and competency completed.
        $this->assertSame($initialcount + 4, $countafter);

        $this->assertSame(1, $option->user_completed_option());
        $assignments = $DB->get_records('local_taskflow_assignment', ['userid' => $user2->id ]);
        $this->assertCount(1, $assignments);
        $assignment = array_shift($assignments);
        // We don't exclude partially completed here.
        $this->assertSame((int)$assignment->status, assignment_status_facade::get_status_identifier('partially_completed'));

        $countbefore = count($assignmenthistory = $DB->get_records('local_taskflow_history', ['userid' => $user2->id]));
        // After the partial completion, we update the user profile.
        require_once($CFG->dirroot . '/user/lib.php');
        user_update_user((object)[
            'id' => $user2->id,
            'firstname' => 'UpdatedFirstName',
            'lastname' => 'UpdatedLastName',
        ]);
        // Check taskflow_history dont trigger status update.
        $countafter = count($assignmenthistory = $DB->get_records('local_taskflow_history', ['userid' => $user2->id]));
        $this->assertSame($countbefore, $countafter);
        // Run all adhoc tasks now.
        $plugingeneratortf->runtaskswithintime($cronlock, $lock, time());

        $this->assertSame(1, $option->user_completed_option());
        $assignments = $DB->get_records('local_taskflow_assignment', ['userid' => $user2->id ]);
        $this->assertCount(1, $assignments);
        $assignment = array_shift($assignments);
        // We exclude paritally completed.
        $this->assertSame((int)$assignment->status, assignment_status_facade::get_status_identifier('partially_completed'));

        $sentmessages = $DB->get_records('local_taskflow_sent_messages', ['userid' => $user2->id ]);
        $this->assertCount(1, $sentmessages);
        $messagesink = array_filter($sink->get_messages(), function ($message) {
            return strpos($message->subject, 'Taskflow -') === 0;
        });
        $this->assertCount(3, $messagesink);
        $messagesink = array_filter($messagesink, function ($msg) use ($user2) {
            return $msg->to === $user2->email;
        });
        foreach ($messagesink as $msg) {
            $this->assertTrue(
                $msg->to === $user2->email
            );
            $this->assertSame(
                $dbmsg[3]->subject,
                $msg->subject,
            );
        }

        $countafter = count($assignmenthistory = $DB->get_records('local_taskflow_history', ['assignmentid' => $assignment->id]));
        // 4 + partially completed and competency completed.
        $this->assertSame($initialcount + 4, $countafter);
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
        $rule = $this->get_rule($cohort->id, $competency->get('id'), $competency2->get('id'), $messageids);
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
        time_mock::set_mock_time(strtotime('+ 1 minutes', time()));
        $plugingenerator->runtaskswithintime($cronlock, $lock, time());
        $assignment = $DB->get_records('local_taskflow_assignment');
        $plugingenerator->runtaskswithintime($cronlock, $lock, time());

        $bdata['course'] = $course->id;
        $bdata['bookingmanager'] = $user1->username;

        $booking = $this->getDataGenerator()->create_module('booking', $bdata);
        set_config('usecompetencies', 1, 'booking');
        set_config('automaticbookingoptioncompletion', 1, 'booking');
        set_config('certificateon', 1, 'booking');

        $this->setAdminUser();

        $this->getDataGenerator()->enrol_user($user1->id, $course->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user($user2->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($user3->id, $course->id, 'student');

        // User 2 doesnt have competency yet in this case.
        $existing = user_competency::get_records(['userid' => $user2->id]);
        $this->assertEmpty($existing, 'User already has a competency assigned');

        return [$user1, $user2, $booking, $course, $competency, $competency2];
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
     * @return object
     */
    protected function set_db_course2(): mixed {
        // Create a user.
        $course = $this->getDataGenerator()->create_course([
            'fullname' => 'Test Course 2',
            'shortname' => 'TC102',
            'category' => 1,
            'enablecompletion' => 1,
        ]);
        return $course;
    }

    /**
     * Setup the test environment.
     * @param int $unitid
     * @param int $target1id
     * @param int $target2id
     * @param array $messageids
     * @return array
     */
    public function get_rule(int $unitid, int $target1id, int $target2id, array $messageids): array {
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
                        "duration" => 23233232222,
                        "timemodified" => 23233232222,
                        "timecreated" => 23233232222,
                        "usermodified" => 1,
                        "inheritance" => 1,
                        "recursive" => 0,
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
                                        "targetid" => $target1id,
                                        "targettype" => "competency",
                                        "targetname" => "mycompetency",
                                        "sortorder" => 2,
                                        "actiontype" => "enroll",
                                        "completebeforenext" => false,
                                    ],
                                    [
                                        "targetid" => $target2id,
                                        "targettype" => "competency",
                                        "targetname" => "mycompetency",
                                        "sortorder" => 3,
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
     * Setup the test environment.
     */
    protected function set_messages_db(): array {
        global $DB;
        $messageids = [];
        $messages = json_decode(file_get_contents(__DIR__ . '/../../mock/messages/assignedandwarningsandfailed_messages.json'));
        foreach ($messages as $message) {
            $messageids[] = (object)['messageid' => $DB->insert_record('local_taskflow_messages', $message)];
        }
        return $messageids;
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

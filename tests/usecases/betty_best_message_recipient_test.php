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

namespace taskflowadapter_ksw\usecases;

use advanced_testcase;
use local_taskflow\local\external_adapter\external_api_repository;
use local_taskflow\local\messages\message_recipient;
use tool_mocktesttime\time_mock;

/**
 * Tests for message_recipient recipient resolution using KSW user import.
 *
 * Betty Best (betty.best@ksw.ch) has Berta Boss as supervisor.
 * Berta Boss (berta.boss@ksw.ch) has no supervisor.
 *
 * @package taskflowadapter_ksw
 * @category test
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class betty_best_message_recipient_test extends advanced_testcase {
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
        $this->externaldata = file_get_contents(
            $CFG->dirroot . '/local/taskflow/taskflowadapter/ksw/tests/usecases/external_json/betty_best_message_recipient_ksw.json'
        );
        /** @var \local_taskflow_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('local_taskflow');
        $plugingenerator->create_custom_profile_fields([
            'supervisor',
            'supervisor_external',
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

        // Two passes: first creates users and saves their externalid profile fields to the DB.
        // Second pass can then resolve Manager_Id → supervisor by querying those DB rows.
        $apidatamanager = external_api_repository::create($this->externaldata);
        $apidatamanager->process_incoming_data();
        \local_taskflow\local\external_adapter\external_api_base::destroy_instance();
        $apidatamanager = external_api_repository::create($this->externaldata);
        $apidatamanager->process_incoming_data();
    }

    /**
     * Mandatory clean-up after each test.
     */
    protected function tearDown(): void {
        parent::tearDown();
        /** @var \local_taskflow_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('local_taskflow');
        $plugingenerator->teardown();
    }

    /**
     * Build a messagedata object from the recipient_role_messages.json template.
     * Replaces CHANGETOUSERID with the given user ID.
     *
     * @param int $index 0=assignee, 1=supervisor, 2=specificuser, 3=ccspecificuser
     * @param int|null $replaceuserid ID to substitute for CHANGETOUSERID (for cases 2 and 3)
     * @return object
     */
    private function make_messagedata(int $index, ?int $replaceuserid = null): object {
        $messages = json_decode(file_get_contents(
            __DIR__ . '/../mock/messages/recipient_role_messages.json'
        ));
        $message = $messages[$index];
        if ($replaceuserid !== null) {
            $message->sending_settings = str_replace('CHANGETOUSERID', $replaceuserid, $message->sending_settings);
        }
        return $message;
    }

    /**
     * assignee role: get_recepient returns the assigned user themselves.
     *
     * @covers \local_taskflow\local\messages\message_recipient::get_recepient
     */
    public function test_assignee_recipient_returns_user(): void {
        global $DB;
        $betty = $DB->get_record('user', ['email' => 'betty.best@ksw.ch'], '*', MUST_EXIST);

        $recipient = new message_recipient($betty->id, $this->make_messagedata(0));
        $result = $recipient->get_recepient();

        $this->assertCount(1, $result);
        $this->assertEquals($betty->id, $result[0]->id);
    }

    /**
     * supervisor role: get_recepient returns Berta Boss when Betty Best (who has a supervisor) is the user.
     *
     * @covers \local_taskflow\local\messages\message_recipient::get_recepient
     */
    public function test_supervisor_recipient_with_supervisor_returns_supervisor(): void {
        global $DB;
        $betty = $DB->get_record('user', ['email' => 'betty.best@ksw.ch'], '*', MUST_EXIST);
        $berta = $DB->get_record('user', ['email' => 'berta.boss@ksw.ch'], '*', MUST_EXIST);

        $recipient = new message_recipient($betty->id, $this->make_messagedata(1));
        $result = $recipient->get_recepient();

        $this->assertCount(1, $result);
        $this->assertEquals($berta->id, $result[0]->id);
    }

    /**
     * supervisor role: get_recepient returns empty when the user has no supervisor (Berta Boss has none).
     * No message should be sent in this case.
     *
     * @covers \local_taskflow\local\messages\message_recipient::get_recepient
     */
    public function test_supervisor_recipient_without_supervisor_returns_empty(): void {
        global $DB;
        $berta = $DB->get_record('user', ['email' => 'berta.boss@ksw.ch'], '*', MUST_EXIST);

        $recipient = new message_recipient($berta->id, $this->make_messagedata(1));
        $result = $recipient->get_recepient();

        $this->assertEmpty($result);
    }

    /**
     * specificuser role: get_recepient returns the configured specific user (Berta Boss).
     *
     * @covers \local_taskflow\local\messages\message_recipient::get_recepient
     */
    public function test_specificuser_recipient_with_valid_user_returns_that_user(): void {
        global $DB;
        $betty = $DB->get_record('user', ['email' => 'betty.best@ksw.ch'], '*', MUST_EXIST);
        $berta = $DB->get_record('user', ['email' => 'berta.boss@ksw.ch'], '*', MUST_EXIST);

        $recipient = new message_recipient($betty->id, $this->make_messagedata(2, $berta->id));
        $result = $recipient->get_recepient();

        $this->assertCount(1, $result);
        $this->assertEquals($berta->id, $result[0]->id);
    }

    /**
     * specificuser role: get_recepient returns empty when the userid does not exist.
     * No message should be sent in this case.
     *
     * @covers \local_taskflow\local\messages\message_recipient::get_recepient
     */
    public function test_specificuser_recipient_with_nonexistent_user_returns_empty(): void {
        global $DB;
        $betty = $DB->get_record('user', ['email' => 'betty.best@ksw.ch'], '*', MUST_EXIST);

        $recipient = new message_recipient($betty->id, $this->make_messagedata(2, 999999));
        $result = $recipient->get_recepient();

        $this->assertEmpty($result);
    }

    /**
     * ccspecificuser role via carboncopyrole: get_carbon_copy returns the configured CC user (Berta Boss).
     *
     * @covers \local_taskflow\local\messages\message_recipient::get_carbon_copy
     */
    public function test_carbon_copy_with_valid_ccspecificuser_returns_that_user(): void {
        global $DB;
        $betty = $DB->get_record('user', ['email' => 'betty.best@ksw.ch'], '*', MUST_EXIST);
        $berta = $DB->get_record('user', ['email' => 'berta.boss@ksw.ch'], '*', MUST_EXIST);

        $recipient = new message_recipient($betty->id, $this->make_messagedata(3, $berta->id));
        $result = $recipient->get_carbon_copy();

        $this->assertCount(1, $result);
        $this->assertEquals($berta->id, $result[0]->id);
    }

    /**
     * ccspecificuser role via carboncopyrole: get_carbon_copy returns empty when the ccuserid does not exist.
     * No message should be sent in this case.
     *
     * @covers \local_taskflow\local\messages\message_recipient::get_carbon_copy
     */
    public function test_carbon_copy_with_nonexistent_ccspecificuser_returns_empty(): void {
        global $DB;
        $betty = $DB->get_record('user', ['email' => 'betty.best@ksw.ch'], '*', MUST_EXIST);

        $recipient = new message_recipient($betty->id, $this->make_messagedata(3, 999999));
        $result = $recipient->get_carbon_copy();

        $this->assertEmpty($result);
    }
}

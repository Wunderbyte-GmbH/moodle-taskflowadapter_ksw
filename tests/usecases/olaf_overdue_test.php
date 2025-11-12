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
use local_taskflow\local\assignment_status\assignment_status_facade;
use local_taskflow\local\external_adapter\external_api_repository;
use tool_mocktesttime\time_mock;
use cache_helper;
use completion_completion;
use local_taskflow\event\rule_created_updated;
use local_taskflow\local\assignments\assignment;
use local_taskflow\local\external_adapter\external_api_base;
use local_taskflow_generator;

/**
 * Test unit class of local_taskflow.
 *
 * @package taskflowadapter_ksw
 * @category test
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 */
final class olaf_overdue_test extends advanced_testcase {
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
            $CFG->dirroot . '/local/taskflow/taskflowadapter/ksw/tests/usecases/external_json/olaf_overdue_ksw.json'
        );
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
    public function tearDown(): void {
        global $DB;
        parent::tearDown();
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('local_taskflow');
        $plugingenerator->teardown();
    }

    /**
     * Setup the test environment.
     * @return object
     */
    protected function set_db_course(): mixed {
        global $DB;

        $shortname = 'TC101BETTY';

        if ($DB->record_exists('course', ['shortname' => $shortname])) {
            return $DB->get_record('course', ['shortname' => $shortname]);
        }

        $course = $this->getDataGenerator()->create_course([
            'fullname' => 'Test Course',
            'shortname' => $shortname,
            'category' => 1,
            'enablecompletion' => 1,
        ]);

        return $course;
    }

    /**
     * Setup the test environment.
     * @param int $courseid
     * @param int $userid
     * @covers \local_taskflow\local\history\types\base
     * @covers \local_taskflow\local\history\types\typesfactory
     */
    protected function course_completed($courseid, $userid): void {
        $completion = new completion_completion([
            'course' => $courseid,
            'userid' => $userid,
        ]);
        $completion->mark_complete();
    }

    /**
     * Setup the test environment.
     * @param int $unitid
     * @param int $courseid
     * @param array $messageids
     * @return array
     */
    public function get_rule($unitid, $courseid, $messageids): array {
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
                        "fixeddate" => 1323323222,
                        "duration" => 86400 * 10,
                        "timemodified" => strtotime('now', time()),
                        "timecreated" => strtotime('now', time()),
                        "extensionperiod" => 86400 * 5,
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
                                        "targetid" => $courseid,
                                        "targettype" => "moodlecourse",
                                        "targetname" => "mytargetname2",
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
     * Setup the test environment.
     */
    protected function set_messages_db(): array {
        global $DB;
        $messageids = [];
        $messages = json_decode(file_get_contents(__DIR__ . '/../mock/messages/messages.json'));
        foreach ($messages as $message) {
            $messageids[] = (object)['messageid' => $DB->insert_record('local_taskflow_messages', $message)];
        }
        return $messageids;
    }

    /**
     * Example test: Ensure external data is loaded.
     * @covers \local_taskflow\local\rules\rules
     */
    public function test_olaf_overdue(): void {
        global $DB;

        $apidatamanager = external_api_repository::create($this->externaldata);
        $externaldata = $apidatamanager->get_external_data();
        $this->assertNotEmpty($externaldata, 'External user data should not be empty.');
        $apidatamanager->process_incoming_data();
        $sink = $this->redirectEmails();

        $cohorts = $DB->get_records('cohort');
        $cohort = array_pop($cohorts);

        $course = $this->set_db_course();
        $messageids = $this->set_messages_db();

        $lock = $this->createMock(\core\lock\lock::class);
        $cronlock = $this->createMock(\core\lock\lock::class);
        /** @var local_taskflow_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('local_taskflow');

        $rule = $this->get_rule($cohort->id, $course->id, $messageids);
        $id = $DB->insert_record('local_taskflow_rules', $rule);
        $rule['id'] = $id;
        $event = rule_created_updated::create([
            'objectid' => $rule['id'],
            'context'  => \context_system::instance(),
            'other'    => [
                'ruledata' => $rule,
            ],
        ]);
        $event->trigger();
        time_mock::set_mock_time(strtotime('+ 15 minutes', time()));
        $plugingenerator->runtaskswithintime($cronlock, $lock, time());

        $user = $DB->get_record('user', ['lastname' => 'Overdue']);
        $assignment = $DB->get_record('local_taskflow_assignment', ['userid' => $user->id]);
        $this->assertSame(assignment_status_facade::get_status_identifier('assigned'), (int)$assignment->status);
        time_mock::set_mock_time(strtotime('+ 11 days', time()));
        $plugingenerator->runtaskswithintime($cronlock, $lock, time());

        // In KSW, we have no extension period.
        $assignment = $DB->get_record('local_taskflow_assignment', ['userid' => $user->id]);
        $this->assertSame(assignment_status_facade::get_status_identifier('overdue'), (int)$assignment->status);
    }
}

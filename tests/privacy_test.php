<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Privacy provider tests for local_paatshala.
 *
 * @package    local_paatshala
 * @copyright  2026 local_paatshala contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_paatshala;

use context_course;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use local_paatshala\privacy\provider;

/**
 * Privacy provider tests for local_paatshala.
 *
 * @package    local_paatshala
 * @copyright  2026 local_paatshala contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class privacy_test extends \core_privacy\tests\provider_testcase {

    /**
     * Two courses; the caller makes an entry in each, one is about a student.
     *
     * @return array [caller, student, course1, course2, entry about student id]
     */
    protected function setup_entries(): array {
        $this->resetAfterTest();
        $caller = $this->getDataGenerator()->create_user();
        $student = $this->getDataGenerator()->create_user();
        $course1 = $this->getDataGenerator()->create_course();
        $course2 = $this->getDataGenerator()->create_course();
        $this->setUser($caller);
        $aboutstudent = journal::add(['courseid' => $course1->id, 'cmid' => 7, 'kind' => 'assign_override',
            'target' => ['userid' => $student->id], 'before' => [], 'after' => ['duedate' => 100],
            'undoable' => 1, 'relateduserid' => $student->id]);
        journal::add(['courseid' => $course2->id, 'kind' => 'assign_dates', 'target' => ['cmid' => 8],
            'before' => ['duedate' => 1], 'after' => ['duedate' => 2], 'undoable' => 1]);
        $this->setUser(null);
        return [$caller, $student, $course1, $course2, $aboutstudent];
    }

    /**
     * Contexts and users are found from both userid and relateduserid.
     */
    public function test_contexts_and_users() {
        list($caller, $student, $course1, $course2) = $this->setup_entries();
        $c1 = context_course::instance($course1->id);
        $c2 = context_course::instance($course2->id);

        $ids = provider::get_contexts_for_userid($caller->id)->get_contextids();
        sort($ids);
        $expected = [$c1->id, $c2->id];
        sort($expected);
        $this->assertEquals($expected, $ids);
        $this->assertEquals([$c1->id], provider::get_contexts_for_userid($student->id)->get_contextids());

        $userlist = new userlist($c1, 'local_paatshala');
        provider::get_users_in_context($userlist);
        $userids = $userlist->get_userids();
        sort($userids);
        $expected = [$caller->id, $student->id];
        sort($expected);
        $this->assertEquals($expected, $userids);
    }

    /**
     * Export includes entries by and about the user.
     */
    public function test_export() {
        list($caller, $student, $course1) = $this->setup_entries();
        $c1 = context_course::instance($course1->id);
        $this->export_context_data_for_user($student->id, $c1, 'local_paatshala');
        $data = writer::with_context($c1)->get_data([get_string('pluginname', 'local_paatshala')]);
        $this->assertCount(1, $data->journal);
        $this->assertEquals(100, $data->journal[0]['after']->duedate);
    }

    /**
     * Deleting a student removes entries about them; deleting the caller anonymises.
     */
    public function test_delete_for_user() {
        global $DB;
        list($caller, $student, $course1, $course2, $aboutstudent) = $this->setup_entries();
        $c1 = context_course::instance($course1->id);
        $c2 = context_course::instance($course2->id);

        provider::delete_data_for_user(new approved_contextlist($student, 'local_paatshala', [$c1->id]));
        $this->assertFalse($DB->record_exists(journal::TABLE, ['id' => $aboutstudent]));
        $this->assertEquals(1, $DB->count_records(journal::TABLE, ['courseid' => $course2->id]));

        provider::delete_data_for_user(new approved_contextlist($caller, 'local_paatshala', [$c2->id]));
        $this->assertEquals(0, $DB->count_records(journal::TABLE, ['userid' => $caller->id]));
        $this->assertEquals(1, $DB->count_records(journal::TABLE, ['courseid' => $course2->id, 'userid' => 0]));
    }

    /**
     * Deleting a list of users in a context.
     */
    public function test_delete_for_users() {
        global $DB;
        list($caller, $student, $course1) = $this->setup_entries();
        $c1 = context_course::instance($course1->id);
        provider::delete_data_for_users(new approved_userlist($c1, 'local_paatshala', [$student->id]));
        $this->assertEquals(0, $DB->count_records(journal::TABLE, ['courseid' => $course1->id]));
        $this->assertEquals(1, $DB->count_records(journal::TABLE, ['userid' => $caller->id]));
    }

    /**
     * Deleting everything in a context.
     */
    public function test_delete_all_in_context() {
        global $DB;
        list($caller, $student, $course1, $course2) = $this->setup_entries();
        provider::delete_data_for_all_users_in_context(context_course::instance($course2->id));
        $this->assertEquals(1, $DB->count_records(journal::TABLE, ['courseid' => $course2->id, 'userid' => 0]));
        $this->assertEquals(1, $DB->count_records(journal::TABLE, ['courseid' => $course1->id, 'userid' => $caller->id]));
    }
}

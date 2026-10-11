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
 * Tests for creating and duplicating courses, deleting them and the category recycle bin.
 *
 * @package    local_paatshala
 * @copyright  2026 local_paatshala contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_paatshala;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/helper.php');

use external_api;
use local_paatshala\external\create_course;
use local_paatshala\external\delete_created;
use local_paatshala\external\duplicate_course;
use local_paatshala\external\journal_get;
use local_paatshala\external\recyclebin_list;
use local_paatshala\external\recyclebin_restore;
use local_paatshala\external\undo;

/**
 * Tests for creating and duplicating courses, deleting them and the category recycle bin.
 *
 * @package    local_paatshala
 * @copyright  2026 local_paatshala contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class courses_test extends testcase {

    /** @var string[] Capabilities the web service role gets. */
    const CAPS = ['moodle/course:create', 'moodle/course:delete', 'moodle/course:update',
        'moodle/restore:restorecourse', 'moodle/restore:rolldates', 'moodle/backup:backupcourse',
        'moodle/restore:configure', 'moodle/backup:configure',
        'tool/recyclebin:viewitems', 'tool/recyclebin:restoreitems', 'moodle/category:viewcourselist'];

    /** @var \stdClass */
    protected $category;

    /**
     * A category, category recycle bin on, plugin on for every course and that category.
     */
    protected function setUp(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->category = $this->getDataGenerator()->create_category();
        set_config('categorybinenable', 1, 'tool_recyclebin');
        $this->enable_plugin('*');
        set_config('allowedcategories', (string)$this->category->id, 'local_paatshala');
    }

    /**
     * Create a course and clean the result.
     *
     * @param string $shortname
     * @param int $enddate
     * @return array
     */
    protected function create(string $shortname = 'NEW1', int $enddate = 0): array {
        return external_api::clean_returnvalue(create_course::execute_returns(),
            create_course::execute($this->category->id, 'New course', $shortname, 1893456000, $enddate, 'topics'));
    }

    /**
     * Undo and clean the result.
     *
     * @param int $journalid
     * @return array
     */
    protected function undo(int $journalid): array {
        return external_api::clean_returnvalue(undo::execute_returns(), undo::execute($journalid, null));
    }

    /**
     * New course id from a result.
     *
     * @param array $result
     * @return int
     */
    protected static function courseid(array $result): int {
        return (int)json_decode($result['after'], true)['courseid'];
    }

    /**
     * Create a course: hidden, in the category, with the site's defaults; journaled; warned
     * that it is not allowed when the allowlist does not cover it.
     */
    public function test_create_course() {
        global $DB;
        set_config('numsections', 6, 'moodlecourse');
        $this->login_ws_user(self::CAPS);
        $result = $this->create();
        $course = $DB->get_record('course', ['id' => self::courseid($result)], '*', MUST_EXIST);
        $this->assertEquals(0, $course->visible);
        $this->assertEquals($this->category->id, $course->category);
        $this->assertEquals('topics', $course->format);
        $this->assertEquals(1893456000, $course->startdate);
        $this->assertEquals(7, $DB->count_records('course_sections', ['course' => $course->id]));
        // As course/edit.php: a creator who cannot assign roles there is enrolled.
        $this->assertSame(['creatorenrolled'], array_column($result['warnings'], 'code'));
        $this->assertTrue(is_enrolled(\context_course::instance($course->id), $this->wsuser->id));

        $entry = $this->journal_entry($result['journalid']);
        $this->assertSame('create_course', $entry['kind']);
        $this->assertEquals(1, $entry['createdbyplugin']);
        $this->assertEquals($course->id, $entry['courseid']);
        $this->assertNull($entry['cmid']);
        $this->assertEquals($this->category->id, $entry['target']['categoryid']);
        // The journal_get function gates course creations by their category.
        set_config('allowedcourses', '', 'local_paatshala');
        $this->assertEquals('create_course', journal_get::execute($result['journalid'])['kind']);

        $other = $this->create('NEW2');
        $this->assertContains('notallowed', array_column($other['warnings'], 'code'));
    }

    /**
     * Refusals: capability, category, shortname, dates, format.
     */
    public function test_create_course_refusals() {
        $this->login_ws_user(self::CAPS);
        $this->getDataGenerator()->create_course(['shortname' => 'TAKEN']);
        $this->assert_error('shortnametaken', function() {
            $this->create('TAKEN');
        });
        $this->assert_error('enddatebeforestartdate', function() {
            $this->create('X1', 1893000000);
        });
        $this->assert_error('invalidparameter', function() {
            create_course::execute($this->category->id, 'C', 'X2', 1893456000, 0, 'nosuchformat');
        });
        $other = $this->getDataGenerator()->create_category();
        $this->assert_error('categorynotallowed', function() use ($other) {
            create_course::execute($other->id, 'C', 'X3', 1893456000);
        });
        $this->remove_capability('moodle/course:create');
        $this->assert_error('nopermissions', function() {
            $this->create('X4');
        });
        set_config('enabled', 0, 'local_paatshala');
        $this->assert_error('disabled', function() {
            $this->create('X5');
        });
    }

    /**
     * Undo deletes the course into the category bin; redo restores it with a new id; delete
     * refused with students and with the bin off.
     */
    public function test_course_undo_redo_and_guards() {
        global $DB;
        $this->login_ws_user(self::CAPS);
        $result = $this->create();
        $courseid = self::courseid($result);

        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $courseid, 'student');
        $e = $this->assert_error('studentdata', function() use ($courseid) {
            delete_created::execute(0, $courseid);
        });
        $this->assertEquals(['students' => 1], json_decode($e->debuginfo, true));
        role_unassign_all(['userid' => $student->id, 'contextid' => \context_course::instance($courseid)->id]);

        set_config('categorybinenable', 0, 'tool_recyclebin');
        $this->assert_error('recyclebindisabled', function() use ($result) {
            $this->undo($result['journalid']);
        });
        set_config('categorybinenable', 1, 'tool_recyclebin');

        $undone = $this->undo($result['journalid']);
        $this->assertFalse($DB->record_exists('course', ['id' => $courseid]));
        $binitemid = json_decode($undone['after'], true)['binitemid'];
        $this->assertTrue($DB->record_exists('tool_recyclebin_category', ['id' => $binitemid]));

        $list = recyclebin_list::execute(0, $this->category->id);
        $this->assertEquals('NEW1', $list['items'][0]['shortname']);

        $redone = $this->undo($undone['journalid']);
        $newid = self::courseid($redone);
        $this->assertNotEquals($courseid, $newid);
        $this->assertEquals('NEW1', $DB->get_field('course', 'shortname', ['id' => $newid]));

        delete_created::execute(0, $newid);
        $this->assertFalse($DB->record_exists('course', ['id' => $newid]));
        $this->assert_error('notcreatedbyplugin', function() {
            delete_created::execute(0, $this->getDataGenerator()->create_course()->id);
        });
    }

    /**
     * A course deleted in Moodle can be restored from the category bin; undo deletes it again.
     */
    public function test_category_bin_restore() {
        global $DB;
        $course = $this->getDataGenerator()->create_course(['category' => $this->category->id, 'shortname' => 'OLD']);
        delete_course($course, false);
        $this->login_ws_user(self::CAPS);
        $item = recyclebin_list::execute(0, $this->category->id)['items'][0];
        $restored = external_api::clean_returnvalue(recyclebin_restore::execute_returns(),
            recyclebin_restore::execute($item['id'], 'category'));
        $newid = self::courseid($restored);
        $this->assertEquals('OLD', $DB->get_field('course', 'shortname', ['id' => $newid]));
        $this->assertSame('recyclebin_restore_course', $this->journal_entry($restored['journalid'])['kind']);
        $this->undo($restored['journalid']);
        $this->assertFalse($DB->record_exists('course', ['id' => $newid]));
    }

    /**
     * Duplicate: no user data, hidden, dates moved by the start date difference.
     */
    public function test_duplicate_course() {
        global $DB;
        $source = $this->getDataGenerator()->create_course(['shortname' => 'SRC', 'startdate' => 1893456000,
            'enddate' => 1896048000]);
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $source->id,
            'allowsubmissionsfromdate' => 1893500000, 'duedate' => 1894000000, 'cutoffdate' => 1894500000]);
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $source->id, 'timeopen' => 1893600000,
            'timeclose' => 1893700000]);
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $source->id, 'student');
        $this->login_ws_user(self::CAPS);

        $offset = 86400 * 100;
        $result = external_api::clean_returnvalue(duplicate_course::execute_returns(),
            duplicate_course::execute($source->id, $this->category->id, 'Copy', 'COPY', 1893456000 + $offset));
        $newid = self::courseid($result);
        $course = $DB->get_record('course', ['id' => $newid], '*', MUST_EXIST);
        $this->assertEquals('COPY', $course->shortname);
        $this->assertEquals(0, $course->visible);
        $this->assertEquals(1893456000 + $offset, $course->startdate);
        $this->assertEquals(1896048000 + $offset, $course->enddate);

        $newassign = $DB->get_record('assign', ['course' => $newid], '*', MUST_EXIST);
        $this->assertEquals(1893500000 + $offset, $newassign->allowsubmissionsfromdate);
        $this->assertEquals(1894000000 + $offset, $newassign->duedate);
        $this->assertEquals(1894500000 + $offset, $newassign->cutoffdate);
        $newquiz = $DB->get_record('quiz', ['course' => $newid], '*', MUST_EXIST);
        $this->assertEquals(1893600000 + $offset, $newquiz->timeopen);
        $this->assertEquals(1893700000 + $offset, $newquiz->timeclose);
        // Restore leaves action events (the due date) to an ad hoc task, run by cron.
        $this->assertFalse($DB->record_exists('event', ['modulename' => 'assign', 'instance' => $newassign->id]));
        ob_start();
        $this->runAdhocTasks();
        ob_end_clean();
        $this->setUser($this->wsuser);
        $this->assertEquals(1894000000 + $offset, $DB->get_field('event', 'timestart',
            ['modulename' => 'assign', 'instance' => $newassign->id, 'eventtype' => 'due']));

        // No user data: the student is not in the copy.
        $this->assertSame([], local\studentdata::course($newid));
        $entry = $this->journal_entry($result['journalid']);
        $this->assertSame('duplicate_course', $entry['kind']);
        $this->assertEquals($source->id, $entry['target']['sourcecourseid']);

        // Undo deletes the copy.
        $this->undo($result['journalid']);
        $this->assertFalse($DB->record_exists('course', ['id' => $newid]));
        $this->assertEquals(1894000000, $DB->get_field('assign', 'duedate', ['id' => $assign->id]));
        $this->assertEquals($quiz->id, $DB->get_field('quiz', 'id', ['course' => $source->id]));
    }

    /**
     * Duplicate refusals: capabilities, source not allowed.
     */
    public function test_duplicate_refusals() {
        $source = $this->getDataGenerator()->create_course(['shortname' => 'SRC']);
        $this->login_ws_user(self::CAPS);
        foreach (['moodle/restore:restorecourse', 'moodle/backup:backupcourse', 'moodle/restore:rolldates',
                'moodle/restore:configure', 'moodle/backup:configure'] as $cap) {
            $this->remove_capability($cap);
            $this->assert_error('nopermissions', function() use ($source) {
                duplicate_course::execute($source->id, $this->category->id, 'Copy', 'COPY', 1893456000);
            });
            assign_capability($cap, CAP_ALLOW, $this->wsroleid, \context_system::instance()->id);
            accesslib_clear_all_caches_for_unit_testing();
        }
        set_config('allowedcourses', '', 'local_paatshala');
        $this->assert_error('coursenotallowed', function() use ($source) {
            duplicate_course::execute($source->id, $this->category->id, 'Copy', 'COPY', 1893456000);
        });
    }
}

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
 * Tests for what counts as student data when deleting what the plugin created.
 *
 * @package    local_paatshala
 * @copyright  2026 local_paatshala contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_paatshala;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once(__DIR__ . '/helper.php');
require_once($CFG->dirroot . '/mod/assign/locallib.php');
require_once($CFG->libdir . '/gradelib.php');

use external_api;
use local_paatshala\external\create_activity;
use local_paatshala\external\delete_created;
use local_paatshala\external\undo;
use local_paatshala\local\studentdata;

/**
 * Student data means students' data: rows of staff never block a delete, a student's always do.
 *
 * @package    local_paatshala
 * @copyright  2026 local_paatshala contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class studentdata_test extends testcase {

    /** @var \stdClass */
    protected $course;

    /** @var \stdClass[] Users by role: editingteacher, teacher, student, suspended, former. */
    protected $users = [];

    /**
     * A course with a teacher, a non-editing teacher, a student, a suspended student and a
     * former student (enrolled, then unenrolled); recycle bin and plugin on.
     */
    protected function setUp(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('coursebinenable', 1, 'tool_recyclebin');
        $gen = $this->getDataGenerator();
        $this->course = $gen->create_course(['enablecompletion' => 1]);
        $this->users['editingteacher'] = $gen->create_and_enrol($this->course, 'editingteacher');
        $this->users['teacher'] = $gen->create_and_enrol($this->course, 'teacher');
        $this->users['student'] = $gen->create_and_enrol($this->course, 'student');
        $this->users['suspended'] = $gen->create_and_enrol($this->course, 'student', null, 'manual', 0, 0,
            ENROL_USER_SUSPENDED);
        $this->users['former'] = $gen->create_and_enrol($this->course, 'student');
        $instance = enrol_get_instances($this->course->id, true);
        $manual = array_values(array_filter($instance, function($i) {
            return $i->enrol === 'manual';
        }))[0];
        enrol_get_plugin('manual')->unenrol_user($manual, $this->users['former']->id);
        $this->enable_plugin('*');
        $this->login_ws_user(['moodle/course:manageactivities', 'mod/page:addinstance', 'mod/page:view',
            'tool/recyclebin:viewitems']);
    }

    /**
     * Create a hidden page through the plugin.
     *
     * @return array [cmid, journal id]
     */
    protected function create_page(): array {
        $result = external_api::clean_returnvalue(create_activity::execute_returns(),
            create_activity::execute($this->course->id, 0, 'page', json_encode(['name' => 'Check', 'content' => '<p>x</p>'])));
        $after = json_decode($result['after'], true);
        return [(int)$after['cmid'], (int)$result['journalid']];
    }

    /**
     * The completion row Moodle writes when someone views an activity with view tracking.
     *
     * @param int $cmid
     * @param int $userid
     */
    protected function viewed(int $cmid, int $userid) {
        global $DB;
        $DB->insert_record('course_modules_completion', (object)['coursemoduleid' => $cmid, 'userid' => $userid,
            'completionstate' => COMPLETION_COMPLETE, 'viewed' => 1, 'timemodified' => time()]);
    }

    /**
     * Who counts as a student, by Moodle's completion-report rule.
     */
    public function test_counts_as_student() {
        $context = \context_course::instance($this->course->id);
        $this->assertFalse(studentdata::counts_as_student($this->users['editingteacher']->id, $context));
        $this->assertFalse(studentdata::counts_as_student($this->users['teacher']->id, $context));
        $this->assertFalse(studentdata::counts_as_student(get_admin()->id, $context));
        $this->assertFalse(studentdata::counts_as_student($this->wsuser->id, $context));
        $this->assertTrue(studentdata::counts_as_student($this->users['student']->id, $context));
        $this->assertTrue(studentdata::counts_as_student($this->users['suspended']->id, $context));
        $this->assertTrue(studentdata::counts_as_student($this->users['former']->id, $context));
        $this->assertTrue(studentdata::counts_as_student(0, $context));
        // An admin enrolled as a teacher is still staff (Moodle's "do anything" is not used).
        $this->getDataGenerator()->enrol_user(get_admin()->id, $this->course->id, 'editingteacher');
        $this->assertFalse(studentdata::counts_as_student(get_admin()->id, $context));
    }

    /**
     * A plugin-created page viewed by staff can still be deleted; viewed by a student (active,
     * suspended or since unenrolled) it cannot.
     */
    public function test_delete_after_views() {
        global $DB;
        foreach (['editingteacher', 'teacher', 'admin'] as $who) {
            list($cmid) = $this->create_page();
            $this->viewed($cmid, $who === 'admin' ? get_admin()->id : $this->users[$who]->id);
            $result = external_api::clean_returnvalue(delete_created::execute_returns(), delete_created::execute($cmid));
            $this->assertGreaterThan(0, $result['journalid'], $who);
            $this->assertFalse($DB->record_exists('course_modules', ['id' => $cmid]), $who);
        }
        foreach (['student', 'suspended', 'former'] as $who) {
            list($cmid) = $this->create_page();
            $this->viewed($cmid, $this->users['editingteacher']->id);
            $this->viewed($cmid, $this->users[$who]->id);
            $e = $this->assert_error('studentdata', function() use ($cmid) {
                delete_created::execute($cmid);
            });
            $this->assertSame(['completions' => 1], json_decode($e->debuginfo, true), $who);
            $this->assertTrue($DB->record_exists('course_modules', ['id' => $cmid]), $who);
        }

        // The undo of the creation deletes the same way.
        list($cmid, $journalid) = $this->create_page();
        $this->viewed($cmid, $this->users['teacher']->id);
        $undone = external_api::clean_returnvalue(undo::execute_returns(), undo::execute($journalid, null));
        $this->assertGreaterThan(0, $undone['journalid']);
        $this->assertFalse($DB->record_exists('course_modules', ['id' => $cmid]));
    }

    /**
     * The same rule for every kind of record: a teacher's grade, submission, quiz preview,
     * forum post and lesson attempt don't count; a student's do.
     */
    public function test_rule_for_each_data_type() {
        global $DB;
        $gen = $this->getDataGenerator();
        $teacher = $this->users['editingteacher']->id;
        $student = $this->users['student']->id;
        $assign = $gen->create_module('assign', ['course' => $this->course->id]);
        $quiz = $gen->create_module('quiz', ['course' => $this->course->id]);
        $forum = $gen->create_module('forum', ['course' => $this->course->id]);
        $lesson = $gen->create_module('lesson', ['course' => $this->course->id]);

        $rows = [
            'grades' => function($userid) use ($assign, $DB) {
                $item = \grade_item::fetch(['itemtype' => 'mod', 'itemmodule' => 'assign', 'iteminstance' => $assign->id,
                    'courseid' => $this->course->id]);
                $DB->insert_record('grade_grades', (object)['itemid' => $item->id, 'userid' => $userid,
                    'finalgrade' => 50, 'rawgrade' => 50]);
            },
            'submissions' => function($userid) use ($assign, $DB) {
                $DB->insert_record('assign_submission', (object)['assignment' => $assign->id, 'userid' => $userid,
                    'timecreated' => time(), 'timemodified' => time(), 'status' => ASSIGN_SUBMISSION_STATUS_SUBMITTED,
                    'attemptnumber' => 0, 'latest' => 1, 'groupid' => 0]);
            },
            'attempts' => function($userid) use ($quiz, $DB) {
                static $unique = 0;
                $DB->insert_record('quiz_attempts', (object)['quiz' => $quiz->id, 'userid' => $userid, 'attempt' => 1,
                    'uniqueid' => 900000 + (++$unique), 'layout' => '', 'currentpage' => 0, 'preview' => 1,
                    'state' => 'finished', 'timestart' => time(), 'timefinish' => time(), 'timemodified' => time()]);
            },
            'posts' => function($userid) use ($forum, $gen) {
                $gen->get_plugin_generator('mod_forum')->create_discussion(['course' => $this->course->id,
                    'forum' => $forum->id, 'userid' => $userid]);
            },
            'lessonattempts' => function($userid) use ($lesson, $DB) {
                $DB->insert_record('lesson_attempts', (object)['lessonid' => $lesson->id, 'pageid' => 0,
                    'userid' => $userid, 'answerid' => 0, 'retry' => 0, 'correct' => 1, 'timeseen' => time()]);
            },
        ];
        $modules = ['grades' => $assign, 'submissions' => $assign, 'attempts' => $quiz, 'posts' => $forum,
            'lessonattempts' => $lesson];
        $names = ['lessonattempts' => 'attempts'];
        foreach ($rows as $kind => $add) {
            $module = $modules[$kind];
            $cm = get_coursemodule_from_id('', $module->cmid, 0, false, MUST_EXIST);
            $add($teacher);
            $this->assertArrayNotHasKey($names[$kind] ?? $kind, studentdata::module($cm), $kind . ' of a teacher');
            $add($student);
            $this->assertSame(1, studentdata::module($cm)[$names[$kind] ?? $kind] ?? 0, $kind . ' of a student');
        }

        // A team submission (no user) always counts.
        $other = $gen->create_module('assign', ['course' => $this->course->id]);
        $DB->insert_record('assign_submission', (object)['assignment' => $other->id, 'userid' => 0,
            'timecreated' => time(), 'timemodified' => time(), 'status' => ASSIGN_SUBMISSION_STATUS_SUBMITTED,
            'attemptnumber' => 0, 'latest' => 1, 'groupid' => 5]);
        $cm = get_coursemodule_from_id('assign', $other->cmid, 0, false, MUST_EXIST);
        $this->assertSame(['submissions' => 1], studentdata::module($cm));
    }
}

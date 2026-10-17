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
 * Tests for local_paatshala_assign_save_grade.
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

use external_api;
use local_paatshala\external\assign_save_grade;
use local_paatshala\external\undo;

/**
 * Tests for local_paatshala_assign_save_grade.
 *
 * @package    local_paatshala
 * @copyright  2026 local_paatshala contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class grades_test extends testcase {

    /** @var \stdClass */
    protected $course;

    /** @var \stdClass */
    protected $student;

    /** @var \stdClass Web service user. */
    protected $wsuser;

    /**
     * Course with a student; plugin on.
     */
    protected function setUp(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->course = $this->getDataGenerator()->create_course();
        $this->student = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $this->enable_plugin('*');
    }

    /**
     * An assignment out of 100 with feedback comments and files.
     *
     * @param array $settings
     * @return \stdClass
     */
    protected function assignment(array $settings = []): \stdClass {
        return $this->getDataGenerator()->create_module('assign', $settings + ['course' => $this->course->id,
            'grade' => 100, 'assignfeedback_comments_enabled' => 1, 'assignfeedback_file_enabled' => 1,
            'assignsubmission_onlinetext_enabled' => 1]);
    }

    /**
     * Log in as the web service user with the grading capabilities.
     *
     * @param string[] $extra
     */
    protected function login(array $extra = []) {
        $this->wsuser = $this->login_ws_user(array_merge(['mod/assign:grade'], $extra));
    }

    /**
     * Call the function and clean the result.
     *
     * @param int $cmid
     * @param int $userid
     * @param float $grade
     * @param array $options feedback, workflowstate, applytoall, notify, cancelpending, expected.
     * @return array
     */
    protected function grade(int $cmid, int $userid, float $grade, array $options = []): array {
        return external_api::clean_returnvalue(assign_save_grade::execute_returns(), assign_save_grade::execute($cmid,
            $userid, $grade, $options['feedback'] ?? [], $options['workflowstate'] ?? null, $options['applytoall'] ?? 0,
            $options['notify'] ?? 1, $options['cancelpending'] ?? 0, $options['expected'] ?? null));
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
     * Run the assignment cron and return the feedback notices it sent.
     *
     * @return \stdClass[]
     */
    protected function run_cron(): array {
        $sink = $this->redirectMessages();
        ob_start();
        \assign::cron();
        ob_end_clean();
        $messages = array_filter($sink->get_messages(), function($message) {
            return $message->component === 'mod_assign' && $message->eventtype === 'assign_notification';
        });
        $sink->close();
        // Cron switches the user; carry on as the web service user.
        $this->setUser($this->wsuser);
        return array_values($messages);
    }

    /**
     * The student's flags.
     *
     * @param \stdClass $assign
     * @param int $userid
     * @return \stdClass|false
     */
    protected function flags(\stdClass $assign, int $userid) {
        global $DB;
        return $DB->get_record('assign_user_flags', ['assignment' => $assign->id, 'userid' => $userid]);
    }

    /**
     * The student's latest grade and comment.
     *
     * @param \stdClass $assign
     * @param int $userid
     * @return array [grade record, comment record or false]
     */
    protected function stored(\stdClass $assign, int $userid): array {
        global $DB;
        $grades = $DB->get_records('assign_grades', ['assignment' => $assign->id, 'userid' => $userid],
            'attemptnumber DESC', '*', 0, 1);
        $grade = reset($grades);
        return [$grade, $grade ? $DB->get_record('assignfeedback_comments', ['grade' => $grade->id]) : false];
    }

    /**
     * notify = 0: mailed is left as it was and cron sends nothing.
     */
    public function test_quiet_grade() {
        $assign = $this->assignment();
        $this->login();
        $result = $this->grade($assign->cmid, $this->student->id, 72.5, ['notify' => 0,
            'feedback' => ['text' => '<p>Good</p>', 'format' => FORMAT_HTML]]);

        $this->assertFalse($result['notifies_students']);
        $this->assertTrue($result['undoable']);
        $this->assertSame([], $result['warnings']);
        $this->assertFalse($this->flags($assign, $this->student->id));
        list($grade, $comment) = $this->stored($assign, $this->student->id);
        $this->assertEquals(72.5, $grade->grade);
        $this->assertEquals($this->wsuser->id, $grade->grader);
        $this->assertSame('<p>Good</p>', $comment->commenttext);
        $after = json_decode($result['after'], true);
        $this->assertEquals(72.5, $after['grade']);
        $this->assertNull($after['mailed']);
        $this->assertNull(json_decode($result['before'], true)['grade']);
        $entry = $this->journal_entry($result['journalid']);
        $this->assertSame('assign_grade', $entry['kind']);
        $this->assertSame(0, (int)$entry['notifies']);
        $this->assertEquals($this->student->id, $entry['relateduserid']);
        $this->assertCount(0, $this->run_cron());

        // An earlier "sent" flag stays too.
        $this->grade($assign->cmid, $this->student->id, 80);
        $this->assertCount(1, $this->run_cron());
        $this->assertEquals(1, $this->flags($assign, $this->student->id)->mailed);
        $this->grade($assign->cmid, $this->student->id, 81, ['notify' => 0]);
        $this->assertEquals(1, $this->flags($assign, $this->student->id)->mailed);
        $this->assertCount(0, $this->run_cron());
    }

    /**
     * notify = 1: mailed = 0 and cron sends exactly one notice.
     */
    public function test_notified_grade() {
        $assign = $this->assignment();
        $this->login();
        $result = $this->grade($assign->cmid, $this->student->id, 60);
        $this->assertTrue($result['notifies_students']);
        $this->assertEquals(0, $this->flags($assign, $this->student->id)->mailed);
        $this->assertSame(1, (int)$this->journal_entry($result['journalid'])['notifies']);
        $messages = $this->run_cron();
        $this->assertCount(1, $messages);
        $this->assertEquals($this->student->id, $messages[0]->useridto);
        $this->assertEquals(1, $this->flags($assign, $this->student->id)->mailed);
        $this->assertCount(0, $this->run_cron());
    }

    /**
     * Marking workflow with a state other than released: no notice even with notify = 1.
     */
    public function test_marking_workflow() {
        $assign = $this->assignment(['markingworkflow' => 1]);
        $this->login(['mod/assign:releasegrades']);

        $result = $this->grade($assign->cmid, $this->student->id, 55, ['workflowstate' => 'inmarking']);
        $this->assertFalse($result['notifies_students']);
        $this->assertSame('inmarking', $this->flags($assign, $this->student->id)->workflowstate);
        $this->assertEquals(2, $this->flags($assign, $this->student->id)->mailed);
        $this->assertCount(0, $this->run_cron());

        // Without a state the current one is kept and still suppresses the notice.
        $result = $this->grade($assign->cmid, $this->student->id, 56);
        $this->assertFalse($result['notifies_students']);
        $this->assertSame('inmarking', $this->flags($assign, $this->student->id)->workflowstate);
        $this->assertCount(0, $this->run_cron());

        $result = $this->grade($assign->cmid, $this->student->id, 56, ['workflowstate' => 'released']);
        $this->assertTrue($result['notifies_students']);
        $this->assertCount(1, $this->run_cron());

        // A state the caller may not set, and a state without marking workflow.
        $this->remove_capability('mod/assign:releasegrades');
        $this->assert_error('invalidsettings', function() use ($assign) {
            $this->grade($assign->cmid, $this->student->id, 57, ['workflowstate' => 'released']);
        });
        $plain = $this->assignment();
        $this->assert_error('invalidparameter', function() use ($plain) {
            $this->grade($plain->cmid, $this->student->id, 57, ['workflowstate' => 'inmarking']);
        });
    }

    /**
     * expected mismatch: conflict, nothing written.
     */
    public function test_expected_conflict() {
        global $DB;
        $assign = $this->assignment();
        $this->login();
        $e = $this->assert_error('conflict', function() use ($assign) {
            $this->grade($assign->cmid, $this->student->id, 70, ['expected' => json_encode(['grade' => 50])]);
        });
        $this->assertStringContainsString('"grade":null', $e->getMessage());
        $this->assertFalse($DB->record_exists('assign_grades', ['assignment' => $assign->id]));
        $this->assertFalse($DB->record_exists('assign_submission', ['assignment' => $assign->id]));
        $this->assertSame(0, $DB->count_records(journal::TABLE));

        $first = $this->grade($assign->cmid, $this->student->id, 70, ['notify' => 0,
            'expected' => json_encode(['grade' => null, 'feedback_text' => null])]);
        $this->assert_error('conflict', function() use ($assign) {
            $this->grade($assign->cmid, $this->student->id, 71, ['expected' => json_encode(['grade' => 69])]);
        });
        $this->assertEquals(70, $this->stored($assign, $this->student->id)[0]->grade);
        $this->assertSame(1, $DB->count_records(journal::TABLE));
        $this->assertGreaterThan(0, $first['journalid']);
    }

    /**
     * Comment text and format are stored as given (HTML and plain); without feedback the
     * comment stays; a new format alone is refused.
     */
    public function test_comment_formats() {
        $assign = $this->assignment();
        $this->login();
        $html = '<p>Well done &amp; <strong>thorough</strong></p><script>x</script>';
        $this->grade($assign->cmid, $this->student->id, 90, ['notify' => 0,
            'feedback' => ['text' => $html, 'format' => FORMAT_HTML]]);
        list($grade, $comment) = $this->stored($assign, $this->student->id);
        $this->assertSame($html, $comment->commenttext);
        $this->assertEquals(FORMAT_HTML, $comment->commentformat);

        $plain = "Plain: 1 < 2 & \"quotes\"\n<p> is not a tag here";
        $this->grade($assign->cmid, $this->student->id, 90, ['notify' => 0,
            'feedback' => ['text' => $plain, 'format' => FORMAT_PLAIN]]);
        list($grade, $comment) = $this->stored($assign, $this->student->id);
        $this->assertSame($plain, $comment->commenttext);
        $this->assertEquals(FORMAT_PLAIN, $comment->commentformat);

        // No feedback: the comment is kept.
        $result = $this->grade($assign->cmid, $this->student->id, 91, ['notify' => 0]);
        list($grade, $comment) = $this->stored($assign, $this->student->id);
        $this->assertEquals(91, $grade->grade);
        $this->assertSame($plain, $comment->commenttext);
        $this->assertEquals(FORMAT_PLAIN, $comment->commentformat);
        $this->assertSame($plain, json_decode($result['after'], true)['feedback_text']);

        // Moodle saves a comment only when its text changes.
        $this->assert_error('notsaved', function() use ($assign, $plain) {
            $this->grade($assign->cmid, $this->student->id, 91, ['notify' => 0,
                'feedback' => ['text' => $plain, 'format' => FORMAT_HTML]]);
        });
        $this->assertEquals(FORMAT_PLAIN, $this->stored($assign, $this->student->id)[1]->commentformat);
    }

    /**
     * Undo restores grade, comment and format, without notifying; undo of a first grade
     * leaves -1 and no comment text.
     */
    public function test_undo() {
        $assign = $this->assignment();
        $this->login();
        $first = $this->grade($assign->cmid, $this->student->id, 40, ['notify' => 0,
            'feedback' => ['text' => 'Needs work', 'format' => FORMAT_PLAIN]]);
        $second = $this->grade($assign->cmid, $this->student->id, 65, ['notify' => 0,
            'feedback' => ['text' => '<p>Better</p>', 'format' => FORMAT_HTML]]);

        $undone = $this->undo($second['journalid']);
        $this->assertFalse($undone['notifies_students']);
        list($grade, $comment) = $this->stored($assign, $this->student->id);
        $this->assertEquals(40, $grade->grade);
        $this->assertSame('Needs work', $comment->commenttext);
        $this->assertEquals(FORMAT_PLAIN, $comment->commentformat);
        $this->assertFalse($this->flags($assign, $this->student->id));
        $this->assertCount(0, $this->run_cron());

        // Undo of the first grade: no grade (-1), the comment cleared; the record stays.
        $this->undo($first['journalid']);
        list($grade, $comment) = $this->stored($assign, $this->student->id);
        $this->assertEquals(-1, $grade->grade);
        $this->assertSame('', $comment->commenttext);
        $this->assertCount(0, $this->run_cron());

        // Undo of a notified grade does not notify again, and the cron's mailed = 1 is no conflict.
        $notified = $this->grade($assign->cmid, $this->student->id, 50);
        $this->assertCount(1, $this->run_cron());
        $this->undo($notified['journalid']);
        $this->assertEquals(-1, $this->stored($assign, $this->student->id)[0]->grade);
        $this->assertEquals(1, $this->flags($assign, $this->student->id)->mailed);
        $this->assertCount(0, $this->run_cron());

        // A later grade blocks the undo.
        $later = $this->grade($assign->cmid, $this->student->id, 77, ['notify' => 0]);
        $this->grade($assign->cmid, $this->student->id, 78, ['notify' => 0]);
        $this->assert_error('conflict', function() use ($later) {
            $this->undo($later['journalid']);
        });
    }

    /**
     * Values an undo restores, per student (timemodified and grader aside).
     *
     * @param \stdClass $assign
     * @param int $userid
     * @return array
     */
    protected function values(\stdClass $assign, int $userid): array {
        list($grade, $comment) = $this->stored($assign, $userid);
        $flags = $this->flags($assign, $userid);
        return ['grade' => $grade ? (float)$grade->grade : null, 'attempt' => $grade ? (int)$grade->attemptnumber : null,
            'text' => $comment ? $comment->commenttext : null, 'format' => $comment ? (int)$comment->commentformat : null,
            'workflowstate' => $flags ? $flags->workflowstate : ''];
    }

    /**
     * Grade through core mod_assign_save_grades, keeping grade and comment.
     *
     * @param \stdClass $assign
     * @param int $userid
     * @param int $applytoall
     */
    protected function core_regrade(\stdClass $assign, int $userid, int $applytoall = 0) {
        global $CFG;
        require_once($CFG->dirroot . '/mod/assign/externallib.php');
        list($grade, $comment) = $this->stored($assign, $userid);
        \mod_assign_external::save_grades($assign->id, $applytoall, [['userid' => $userid, 'grade' => (float)$grade->grade,
            'attemptnumber' => -1, 'addattempt' => 0, 'workflowstate' => '',
            'plugindata' => ['assignfeedbackcomments_editor' => ['text' => $comment->commenttext,
                'format' => (int)$comment->commentformat]]]]);
    }

    /**
     * Two grades undone newest first give the exact start state; a grade saved through core
     * in between (same values, new timemodified) is refused.
     */
    public function test_chained_undo() {
        $assign = $this->assignment();
        $this->login();
        $this->grade($assign->cmid, $this->student->id, 5, ['notify' => 0,
            'feedback' => ['text' => 'Start', 'format' => FORMAT_PLAIN]]);
        $start = $this->values($assign, $this->student->id);
        $a = $this->grade($assign->cmid, $this->student->id, 11, ['notify' => 0,
            'feedback' => ['text' => '<p>A</p>', 'format' => FORMAT_HTML]]);
        $this->waitForSecond();
        $b = $this->grade($assign->cmid, $this->student->id, 12, ['notify' => 0,
            'feedback' => ['text' => 'B', 'format' => FORMAT_PLAIN]]);
        $this->waitForSecond();
        $this->undo($b['journalid']);
        $this->assertEquals(11, $this->values($assign, $this->student->id)['grade']);
        $this->waitForSecond();
        $this->undo($a['journalid']);
        $this->assertSame($start, $this->values($assign, $this->student->id));

        // The same chain with a core grade in between: values unchanged, timemodified moved.
        $a = $this->grade($assign->cmid, $this->student->id, 11, ['notify' => 0,
            'feedback' => ['text' => '<p>A</p>', 'format' => FORMAT_HTML]]);
        $this->waitForSecond();
        $b = $this->grade($assign->cmid, $this->student->id, 12, ['notify' => 0,
            'feedback' => ['text' => 'B', 'format' => FORMAT_PLAIN]]);
        $this->waitForSecond();
        $this->undo($b['journalid']);
        $values = $this->values($assign, $this->student->id);
        $this->waitForSecond();
        $this->core_regrade($assign, $this->student->id);
        $this->assertSame($values, $this->values($assign, $this->student->id));
        $this->assert_error('conflict', function() use ($a) {
            $this->undo($a['journalid']);
        });
        $this->assertSame($values, $this->values($assign, $this->student->id));
    }

    /**
     * The chained undo for a team grade, checked per member.
     */
    public function test_chained_team_undo() {
        $other = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $group = $this->getDataGenerator()->create_group(['courseid' => $this->course->id]);
        $this->getDataGenerator()->create_group_member(['groupid' => $group->id, 'userid' => $this->student->id]);
        $this->getDataGenerator()->create_group_member(['groupid' => $group->id, 'userid' => $other->id]);
        $assign = $this->assignment(['teamsubmission' => 1]);
        $this->login();
        $members = [$this->student->id, $other->id];
        // Different starting grades and comments.
        $this->grade($assign->cmid, $this->student->id, 3, ['notify' => 0, 'feedback' => ['text' => 'S1']]);
        $this->grade($assign->cmid, $other->id, 4, ['notify' => 0, 'feedback' => ['text' => 'S2']]);
        $start = array_map(function($userid) use ($assign) {
            return $this->values($assign, $userid);
        }, $members);

        $chain = function() use ($assign) {
            $this->waitForSecond();
            $a = $this->grade($assign->cmid, $this->student->id, 11, ['notify' => 0, 'applytoall' => 1,
                'feedback' => ['text' => 'Team A', 'format' => FORMAT_PLAIN]]);
            $this->waitForSecond();
            $b = $this->grade($assign->cmid, $this->student->id, 12, ['notify' => 0, 'applytoall' => 1,
                'feedback' => ['text' => 'Team B', 'format' => FORMAT_PLAIN]]);
            $this->waitForSecond();
            $this->undo($b['journalid']);
            return $a;
        };
        $a = $chain();
        foreach ($members as $userid) {
            $this->assertEquals(11, $this->values($assign, $userid)['grade']);
        }
        $this->waitForSecond();
        $this->undo($a['journalid']);
        $this->assertSame($start, array_map(function($userid) use ($assign) {
            return $this->values($assign, $userid);
        }, $members));

        // A core grade of one member in between is refused for the whole team.
        $a = $chain();
        $this->waitForSecond();
        $this->core_regrade($assign, $other->id);
        $this->assert_error('conflict', function() use ($a) {
            $this->undo($a['journalid']);
        });
        $this->assertEquals(11, $this->values($assign, $this->student->id)['grade']);
    }

    /**
     * Team assignment with applytoall: every member graded, none notified; undo gives each
     * member their own grade back.
     */
    public function test_team_grade() {
        global $DB;
        $other = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $group = $this->getDataGenerator()->create_group(['courseid' => $this->course->id]);
        $this->getDataGenerator()->create_group_member(['groupid' => $group->id, 'userid' => $this->student->id]);
        $this->getDataGenerator()->create_group_member(['groupid' => $group->id, 'userid' => $other->id]);
        $assign = $this->assignment(['teamsubmission' => 1]);
        $this->login();

        // Members graded one by one first, with different grades and comments.
        $this->grade($assign->cmid, $this->student->id, 30, ['notify' => 0, 'feedback' => ['text' => 'A']]);
        $this->grade($assign->cmid, $other->id, 35, ['notify' => 0, 'feedback' => ['text' => 'B']]);

        // Different comments: a team grade needs one.
        $this->assert_error('teamdiffers', function() use ($assign) {
            $this->grade($assign->cmid, $this->student->id, 80, ['notify' => 0, 'applytoall' => 1]);
        });
        $result = $this->grade($assign->cmid, $this->student->id, 80, ['notify' => 0, 'applytoall' => 1,
            'feedback' => ['text' => 'Team feedback', 'format' => FORMAT_PLAIN]]);
        $this->assertFalse($result['notifies_students']);
        foreach ([$this->student->id, $other->id] as $userid) {
            list($grade, $comment) = $this->stored($assign, $userid);
            $this->assertEquals(80, $grade->grade);
            $this->assertSame('Team feedback', $comment->commenttext);
            $this->assertFalse($this->flags($assign, $userid));
        }
        $before = json_decode($result['before'], true);
        $this->assertEquals(30, $before['members'][$this->student->id]['grade']);
        $this->assertEquals(35, $before['members'][$other->id]['grade']);
        $this->assertCount(0, $this->run_cron());
        $linked = $DB->get_fieldset_select(journal::USERS_TABLE, 'userid', 'journalid = ?', [$result['journalid']]);
        sort($linked);
        $this->assertEquals([$this->student->id, $other->id], array_map('intval', $linked));

        $this->undo($result['journalid']);
        $this->assertEquals(30, $this->stored($assign, $this->student->id)[0]->grade);
        $this->assertSame('A', $this->stored($assign, $this->student->id)[1]->commenttext);
        $this->assertEquals(35, $this->stored($assign, $other->id)[0]->grade);
        $this->assertSame('B', $this->stored($assign, $other->id)[1]->commenttext);
        $this->assertCount(0, $this->run_cron());

        // With notify = 1 every member is notified too.
        $notified = $this->grade($assign->cmid, $other->id, 85, ['applytoall' => 1,
            'feedback' => ['text' => 'Team feedback', 'format' => FORMAT_PLAIN]]);
        $this->assertTrue($notified['notifies_students']);
        $this->assertCount(2, $this->run_cron());

        // An applytoall without a team assignment.
        $single = $this->assignment();
        $this->assert_error('invalidparameter', function() use ($single) {
            $this->grade($single->cmid, $this->student->id, 50, ['applytoall' => 1]);
        });
    }

    /**
     * A team grade for a team that never submitted: save_grade() needs the team's
     * submission, which is created as the grading page does.
     */
    public function test_team_without_submission() {
        $other = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $group = $this->getDataGenerator()->create_group(['courseid' => $this->course->id]);
        $this->getDataGenerator()->create_group_member(['groupid' => $group->id, 'userid' => $this->student->id]);
        $this->getDataGenerator()->create_group_member(['groupid' => $group->id, 'userid' => $other->id]);
        $assign = $this->assignment(['teamsubmission' => 1]);
        $this->login();
        $result = $this->grade($assign->cmid, $other->id, 10, ['notify' => 0, 'applytoall' => 1]);
        $this->assertEquals(10, $this->stored($assign, $this->student->id)[0]->grade);
        $this->assertEquals(10, $this->stored($assign, $other->id)[0]->grade);
        $this->undo($result['journalid']);
        $this->assertEquals(-1, $this->stored($assign, $this->student->id)[0]->grade);
        $this->assertEquals(-1, $this->stored($assign, $other->id)[0]->grade);
    }

    /**
     * A notice already waiting for cron: kept with a warning, or cancelled with cancelpending.
     */
    public function test_pending_notification() {
        $assign = $this->assignment();
        $this->login();
        $this->grade($assign->cmid, $this->student->id, 20);
        $this->assertEquals(0, $this->flags($assign, $this->student->id)->mailed);

        $quiet = $this->grade($assign->cmid, $this->student->id, 25, ['notify' => 0]);
        $this->assertSame('pendingnotification', $quiet['warnings'][0]['code']);
        $this->assertEquals(0, $this->flags($assign, $this->student->id)->mailed);
        $this->assertCount(1, $this->run_cron());

        $this->grade($assign->cmid, $this->student->id, 30);
        $cancelled = $this->grade($assign->cmid, $this->student->id, 31, ['notify' => 0, 'cancelpending' => 1]);
        $this->assertSame('pendingcancelled', $cancelled['warnings'][0]['code']);
        $this->assertEquals(1, $this->flags($assign, $this->student->id)->mailed);
        $this->assertEquals(1, json_decode($cancelled['after'], true)['mailed']);
        $this->assertCount(0, $this->run_cron());

        // Nothing pending: no warning; cancelpending needs notify = 0.
        $this->assertSame([], $this->grade($assign->cmid, $this->student->id, 32, ['notify' => 0])['warnings']);
        $this->assert_error('invalidparameter', function() use ($assign) {
            $this->grade($assign->cmid, $this->student->id, 33, ['cancelpending' => 1]);
        });
    }

    /**
     * Feedback files and other plugins are left alone.
     */
    public function test_feedback_files_kept() {
        $assign = $this->assignment();
        $this->login();
        $this->grade($assign->cmid, $this->student->id, 50, ['notify' => 0]);
        $grade = $this->stored($assign, $this->student->id)[0];
        $context = \context_module::instance($assign->cmid);
        $fs = get_file_storage();
        $fs->create_file_from_string(['contextid' => $context->id, 'component' => 'assignfeedback_file',
            'filearea' => ASSIGNFEEDBACK_FILE_FILEAREA, 'itemid' => $grade->id, 'filepath' => '/',
            'filename' => 'marked.pdf'], 'PDF');

        $this->grade($assign->cmid, $this->student->id, 55, ['notify' => 0,
            'feedback' => ['text' => 'See the marked file', 'format' => FORMAT_PLAIN]]);
        $files = $fs->get_area_files($context->id, 'assignfeedback_file', ASSIGNFEEDBACK_FILE_FILEAREA, $grade->id,
            'id', false);
        $this->assertCount(1, $files);
        $this->assertSame('marked.pdf', reset($files)->get_filename());
        $this->assertEquals($grade->id, $this->stored($assign, $this->student->id)[0]->id);
    }

    /**
     * Grade limits, gate, capability, module type, ungradable users; a quiet write of the
     * stored values changes nothing.
     */
    public function test_refusals_and_no_change() {
        global $DB;
        $assign = $this->assignment();
        $this->login();
        $this->assert_error('invalidsettings', function() use ($assign) {
            $this->grade($assign->cmid, $this->student->id, 100.5);
        });
        $this->assert_error('invalidsettings', function() use ($assign) {
            $this->grade($assign->cmid, $this->student->id, -3);
        });
        $this->assertFalse($DB->record_exists('assign_grades', ['assignment' => $assign->id]));

        $stranger = $this->getDataGenerator()->create_user();
        $this->assert_error('notgradable', function() use ($assign, $stranger) {
            $this->grade($assign->cmid, $stranger->id, 10);
        });
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $this->course->id]);
        $this->assert_error('wrongmodule', function() use ($quiz) {
            $this->grade($quiz->cmid, $this->student->id, 10);
        });

        $first = $this->grade($assign->cmid, $this->student->id, 100, ['notify' => 0,
            'feedback' => ['text' => 'Full marks', 'format' => FORMAT_PLAIN]]);
        $count = $DB->count_records(journal::TABLE);
        $same = $this->grade($assign->cmid, $this->student->id, 100, ['notify' => 0,
            'feedback' => ['text' => 'Full marks', 'format' => FORMAT_PLAIN]]);
        $this->assertSame(0, $same['journalid']);
        $this->assertSame('nochange', $same['warnings'][0]['code']);
        $this->assertSame($count, $DB->count_records(journal::TABLE));
        $this->assertEquals(json_decode($first['after'], true)['timemodified'],
            $this->stored($assign, $this->student->id)[0]->timemodified);

        // Scales: an item number, not points.
        $scale = $this->getDataGenerator()->create_scale(['scale' => 'Weak,Fair,Good']);
        $scaled = $this->assignment(['grade' => -$scale->id]);
        $this->grade($scaled->cmid, $this->student->id, 3, ['notify' => 0]);
        $this->assert_error('invalidsettings', function() use ($scaled) {
            $this->grade($scaled->cmid, $this->student->id, 4, ['notify' => 0]);
        });

        $this->remove_capability('mod/assign:grade');
        $this->assert_error('nopermissions', function() use ($assign) {
            $this->grade($assign->cmid, $this->student->id, 10);
        });
        // Grade entries hold grades and comments: only for graders.
        $this->assert_error('nopermissions', function() use ($first) {
            \local_paatshala\external\journal_get::execute($first['journalid']);
        });
        $listed = \local_paatshala\external\journal_list::execute($this->course->id, 0, '', 0)['entries'];
        $this->assertSame([], array_filter($listed, function($entry) {
            return $entry['kind'] === 'assign_grade';
        }));
        set_config('enabled', 0, 'local_paatshala');
        $this->assert_error('disabled', function() use ($assign) {
            $this->grade($assign->cmid, $this->student->id, 10);
        });
    }
}

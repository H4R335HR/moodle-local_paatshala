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
 * An assignment grade, with or without notifying the student.
 *
 * @package    local_paatshala
 * @copyright  2026 local_paatshala contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_paatshala\local\kind;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/assign/locallib.php');
require_once($CFG->dirroot . '/grade/grading/lib.php');

use local_paatshala\gate;
use local_paatshala\journal;
use local_paatshala\local\state;

/**
 * One student's grade and feedback comment on their latest attempt.
 *
 * Written through assign::save_grade(), the call behind the grading form and
 * mod_assign_save_grade.
 *
 * Unlike mod_assign_save_grade, the data carries sendstudentnotifications, the grading
 * form's "Notify students" switch: assign::apply_grade_to_user() only flags the
 * "feedback available" notice (user flag mailed = 0, sent by assign::cron()) when it is
 * missing or true, and never while marking workflow is on with a state other than
 * released. As the grading form, the data always carries the workflow state when marking
 * workflow is on (the current one when none is given).
 *
 * The comment goes in as the grading form's editor without a draft item id, so
 * file_postupdate_standard_editor() stores the text as given and the comment's embedded
 * files stay. Without new feedback the current comment is sent, as the form does: core
 * reads the editor unconditionally and would clear the comment without it. The other
 * feedback plugins (files, offline worksheet, annotate PDF) see none of their data and
 * report no change, so they are left alone.
 *
 * State, for the student and the attempt save_grade() writes (the latest): grade (null
 * without a grade record, -1 for "no grade"), grader, timemodified, feedback_text and
 * feedback_format (raw, from assignfeedback_comments; null without a comment), the
 * marking workflow state ('' when not marked), mailed (the user flag: 0 a notice waits
 * for cron, 1 sent, 2 nothing to send, null without flags) and attemptnumber. For a team
 * grade applied to the whole team, `members` holds the same for each member.
 *
 * @package    local_paatshala
 * @copyright  2026 local_paatshala contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class assign_grade extends base {

    /** @var string[] State of one student. */
    const USER_FIELDS = ['grade', 'grader', 'timemodified', 'feedback_text', 'feedback_format', 'workflowstate', 'mailed',
        'attemptnumber'];

    /** @var string[] Fields a call sets. */
    const WRITABLE = ['grade', 'feedback_text', 'feedback_format', 'workflowstate'];

    /** @var string[] Values an undo needs as the entry left them (mailed and grader may change by cron or not at all). */
    const UNDO_FIELDS = ['grade', 'feedback_text', 'feedback_format', 'workflowstate', 'attemptnumber'];

    /** @var int[] Comment formats the editor offers (FORMAT_MOODLE, _HTML, _PLAIN, _MARKDOWN; Moodle defines them as strings). */
    const FORMATS = [0, 1, 2, 4];

    /** @var \assign */
    protected $assign;

    /** @var int Student. */
    protected $userid;

    /** @var bool Apply to the whole team (team submissions). */
    protected $applytoall;

    /** @var bool Ask Moodle to notify the student(s). */
    protected $notify;

    /** @var bool Cancel a notice already waiting for cron (with notify off). */
    protected $cancelpending;

    /** @var bool Whether Moodle flags a notice for this write (set by validate()). */
    protected $notifies = false;

    /** @var bool Whether write() saved anything. */
    protected $written = false;

    /**
     * Gate and capability checks.
     *
     * @param int $cmid
     * @param int $userid
     * @param bool $applytoall
     * @param bool $notify
     * @param bool $cancelpending
     */
    public function __construct(int $cmid, int $userid, bool $applytoall = false, bool $notify = true,
            bool $cancelpending = false) {
        global $DB;
        list($this->cm, $this->course, $this->context) = gate::module($cmid);
        gate::require_modname($this->cm, 'assign');
        // As assign::save_grade() and the grading pages.
        require_capability('mod/assign:grade', $this->context);
        $this->assign = new \assign($this->context, $this->cm, $this->course);

        // The grading table lists enrolled users who can submit (assign::list_participants()).
        if (!$DB->record_exists('user', ['id' => $userid, 'deleted' => 0]) ||
                !is_enrolled($this->context, $userid, 'mod/assign:submit')) {
            throw new \moodle_exception('notgradable', 'local_paatshala', '', $userid);
        }
        if ($applytoall && !$this->assign->get_instance()->teamsubmission) {
            throw new \invalid_parameter_exception('applytoall is only for team submissions');
        }
        if ($cancelpending && $notify) {
            throw new \invalid_parameter_exception('cancelpending is only for notify = 0');
        }
        $this->userid = $userid;
        $this->applytoall = $applytoall;
        $this->notify = $notify;
        $this->cancelpending = $cancelpending;
    }

    /**
     * Kind name.
     *
     * @return string
     */
    public static function kind(): string {
        return 'assign_grade';
    }

    /**
     * Handler for a journal target: undo never notifies and cancels nothing.
     *
     * @param array $target
     * @return base
     */
    public static function from_target(array $target): base {
        return new self((int)$target['cmid'], (int)$target['userid'], !empty($target['applytoall']), false, false);
    }

    /**
     * Fields: the state (an undo writes a journal entry's before back).
     *
     * @return string[]
     */
    public function fields(): array {
        return array_merge(self::USER_FIELDS, ['members']);
    }

    /**
     * `expected` holds the student's own fields.
     *
     * @return string[]
     */
    public function expected_fields(): array {
        return self::USER_FIELDS;
    }

    /**
     * The whole state is journaled: undo restores it and after shows the new grader,
     * time and mailed flag.
     *
     * @param array $values
     * @return string[]
     */
    public function journal_keys(array $values): array {
        return $this->applytoall ? $this->fields() : self::USER_FIELDS;
    }

    /**
     * Users the write changes: the team's members, as assign::save_grade() finds them,
     * or the student.
     *
     * @return int[]
     */
    protected function userids(): array {
        if (!$this->applytoall) {
            return [$this->userid];
        }
        $group = $this->assign->get_submission_group($this->userid);
        $members = $this->assign->get_submission_group_members($group ? $group->id : 0, true,
            $this->assign->show_only_active_users());
        return array_values(array_map(function($member) {
            return (int)$member->id;
        }, $members));
    }

    /**
     * State of one student for the attempt save_grade() writes, without creating anything
     * (assign::get_user_grade() would create a submission).
     *
     * @param int $userid
     * @return array
     */
    protected function user_state(int $userid): array {
        global $DB;
        $instance = $this->assign->get_instance();
        $submission = $instance->teamsubmission ? $this->assign->get_group_submission($userid, 0, false, -1)
            : $this->assign->get_user_submission($userid, false, -1);
        $params = ['assignment' => $instance->id, 'userid' => $userid];
        if ($submission) {
            $params['attemptnumber'] = $submission->attemptnumber;
        }
        $grades = $DB->get_records('assign_grades', $params, 'attemptnumber DESC', '*', 0, 1);
        $grade = $grades ? reset($grades) : null;
        $comment = $grade ? $DB->get_record('assignfeedback_comments', ['grade' => $grade->id]) : null;
        $flags = $DB->get_record('assign_user_flags', ['assignment' => $instance->id, 'userid' => $userid]);
        $attempt = $submission ? $submission->attemptnumber : ($grade ? $grade->attemptnumber : null);
        return [
            'grade' => $grade && $grade->grade !== null ? (float)$grade->grade : null,
            'grader' => $grade ? (int)$grade->grader : null,
            'timemodified' => $grade ? (int)$grade->timemodified : null,
            'feedback_text' => $comment ? (string)$comment->commenttext : null,
            'feedback_format' => $comment ? (int)$comment->commentformat : null,
            'workflowstate' => $flags ? (string)$flags->workflowstate : '',
            'mailed' => $flags ? (int)$flags->mailed : null,
            'attemptnumber' => $attempt === null ? null : (int)$attempt,
        ];
    }

    /**
     * Current state.
     *
     * @return array
     */
    public function read(): array {
        $state = $this->user_state($this->userid);
        if ($this->applytoall) {
            $state['members'] = [];
            foreach ($this->userids() as $userid) {
                $state['members'][$userid] = $this->user_state($userid);
            }
        }
        return $state;
    }

    /**
     * The feedback comments plugin, if on for this assignment.
     *
     * @return \assign_feedback_comments|null
     */
    protected function comments() {
        $plugin = $this->assign->get_feedback_plugin_by_type('comments');
        return $plugin && $plugin->is_enabled() && $plugin->is_visible() ? $plugin : null;
    }

    /**
     * Refuse what the grading form would refuse or could not save.
     *
     * @param array $values
     * @param array $current
     */
    public function validate(array $values, array $current) {
        $instance = $this->assign->get_instance();
        if (!$this->undoing) {
            $extra = array_diff(array_keys($values), self::WRITABLE);
            if ($extra) {
                throw new \invalid_parameter_exception('Not writable: ' . implode(', ', $extra));
            }
            $this->validate_grade($values['grade'] ?? -1);
            if (array_key_exists('feedback_text', $values) || array_key_exists('feedback_format', $values)) {
                if (!$this->comments()) {
                    throw new \moodle_exception('commentsoff', 'local_paatshala');
                }
                if (!in_array((int)($values['feedback_format'] ?? FORMAT_HTML), self::FORMATS, true)) {
                    throw new \invalid_parameter_exception('feedback format must be one of ' . implode(', ', self::FORMATS));
                }
            }
            if (array_key_exists('workflowstate', $values)) {
                if (!$instance->markingworkflow) {
                    throw new \invalid_parameter_exception('workflowstate is only for assignments with marking workflow');
                }
                // The grading form's options: not marked and the states the caller may set.
                $states = array_keys($this->assign->get_marking_workflow_states_for_current_user());
                if ($values['workflowstate'] !== '' && !in_array($values['workflowstate'], $states, true)) {
                    throw new \moodle_exception('invalidsettings', 'local_paatshala', '',
                        get_string('workflowstatenotallowed', 'local_paatshala', $values['workflowstate']));
                }
            }
            if ($this->applytoall) {
                $this->require_team_agrees($values, $current);
            }
        }

        // Rubrics and marking guides grade through their own form data.
        if ($instance->grade > 0 || $instance->grade < 0) {
            $manager = get_grading_manager($this->context, 'mod_assign', 'submissions');
            if ($manager->get_active_controller()) {
                throw new \moodle_exception('advancedgrading', 'local_paatshala');
            }
        }
        // A grade locked or overridden in the gradebook: apply_grade_to_user() would save
        // the feedback only.
        foreach ($this->userids() as $userid) {
            if ($this->assign->grading_disabled($userid)) {
                throw new \moodle_exception('gradelocked', 'local_paatshala', '', $userid);
            }
        }

        $state = $values['workflowstate'] ?? $current['workflowstate'];
        $this->notifies = $this->notify && (!$instance->markingworkflow || $state === ASSIGN_MARKING_WORKFLOW_STATE_RELEASED);
    }

    /**
     * A grade the assignment's grade type allows: 0 to the maximum points, a scale item,
     * or -1 for no grade.
     *
     * @param float $grade
     */
    protected function validate_grade($grade) {
        global $DB;
        $max = (float)$this->assign->get_instance()->grade;
        $grade = (float)$grade;
        if ($grade == -1) {
            return;
        }
        $a = (object)['grade' => $grade, 'max' => $max];
        if ($max > 0) {
            if ($grade < 0) {
                throw new \moodle_exception('invalidsettings', 'local_paatshala', '',
                    get_string('gradeinvalid', 'local_paatshala', $a));
            }
            if ($grade > $max) {
                throw new \moodle_exception('invalidsettings', 'local_paatshala', '',
                    get_string('gradeabovemax', 'local_paatshala', $a));
            }
            return;
        }
        if ($max < 0) {
            $scale = $DB->get_field('scale', 'scale', ['id' => -$max]);
            $items = $scale === false ? 0 : count(explode(',', $scale));
            if ($grade == (int)$grade && $grade >= 1 && $grade <= $items) {
                return;
            }
        }
        throw new \moodle_exception('invalidsettings', 'local_paatshala', '', get_string('gradeinvalid', 'local_paatshala', $a));
    }

    /**
     * A team grade sends one comment and one workflow state to every member. Without new
     * ones, the members' current ones go along, so they must agree.
     *
     * @param array $values
     * @param array $current
     */
    protected function require_team_agrees(array $values, array $current) {
        $members = array_values($current['members']);
        foreach ($members as $member) {
            if (!array_key_exists('feedback_text', $values) && $this->comments() &&
                    ($member['feedback_text'] ?? '') !== ($members[0]['feedback_text'] ?? '')) {
                throw new \moodle_exception('teamdiffers', 'local_paatshala', '', 'feedback');
            }
            if (!array_key_exists('workflowstate', $values) && $this->assign->get_instance()->markingworkflow &&
                    $member['workflowstate'] !== $members[0]['workflowstate']) {
                throw new \moodle_exception('teamdiffers', 'local_paatshala', '', 'workflowstate');
            }
        }
    }

    /**
     * Save through assign::save_grade(); then cancel waiting notices if asked, and warn
     * about ones left waiting.
     *
     * @param array $values
     * @param array $current
     * @return array
     */
    public function write(array $values, array $current): array {
        if ($this->undoing && isset($values['members'])) {
            // Each member gets back their own grade and comment.
            foreach ($values['members'] as $userid => $member) {
                $this->save((int)$userid, $member, $current['members'][$userid] ?? $this->user_state((int)$userid), false);
            }
        } else {
            if (!$this->notify && !$this->undoing && $this->unchanged($values, $current)) {
                // A quiet write of what is stored would only move timemodified and grader.
                return [];
            }
            $this->save($this->userid, $values, $current, $this->applytoall);
        }
        $this->written = true;

        $pending = [];
        foreach ($this->userids() as $userid) {
            $before = $userid === $this->userid ? $current : ($current['members'][$userid] ?? null);
            if ($before !== null && $before['mailed'] === 0) {
                $pending[] = $userid;
            }
        }
        if (!$pending || $this->notifies) {
            return [];
        }
        if ($this->cancelpending) {
            foreach ($pending as $userid) {
                $flags = $this->assign->get_user_flags($userid, false);
                $flags->mailed = 1;
                $this->assign->update_user_flags($flags);
            }
            return [['pendingcancelled', get_string('warning_pendingcancelled', 'local_paatshala', count($pending))]];
        }
        return [['pendingnotification', get_string('warning_pendingnotification', 'local_paatshala', count($pending))]];
    }

    /**
     * Whether the values are what is stored.
     *
     * @param array $values
     * @param array $current
     * @return bool
     */
    protected function unchanged(array $values, array $current): bool {
        $members = $this->applytoall ? $current['members'] : [$current];
        foreach ($members as $member) {
            foreach ($values as $field => $value) {
                $stored = $member[$field];
                if ($field === 'grade' && $stored === null) {
                    return false;
                }
                if (!state::same($value, $stored)) {
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * One assign::save_grade() call, with the data the grading form would post.
     *
     * @param int $userid
     * @param array $values Writable fields (missing ones keep the current values).
     * @param array $current The student's current state.
     * @param bool $applytoall
     */
    protected function save(int $userid, array $values, array $current, bool $applytoall) {
        $instance = $this->assign->get_instance();
        $data = new \stdClass();
        $data->attemptnumber = -1;
        $data->addattempt = 0;
        $data->applytoall = $applytoall ? 1 : 0;
        $data->sendstudentnotifications = $this->notify ? 1 : 0;
        $data->grade = array_key_exists('grade', $values) && $values['grade'] !== null ? (float)$values['grade'] : -1;
        if ($this->comments()) {
            $text = array_key_exists('feedback_text', $values) ? $values['feedback_text'] : $current['feedback_text'];
            $format = array_key_exists('feedback_format', $values) ? $values['feedback_format'] : $current['feedback_format'];
            $data->assignfeedbackcomments_editor = ['text' => (string)$text,
                'format' => $format === null ? FORMAT_HTML : (int)$format];
        }
        if ($instance->markingworkflow) {
            $data->workflowstate = array_key_exists('workflowstate', $values) ? (string)$values['workflowstate']
                : $current['workflowstate'];
        }
        if ($applytoall && !$this->assign->get_group_submission($userid, 0, false)) {
            // The team's submission: save_grade() reads the team's latest attempt from it,
            // and the grading page creates it when it opens.
            $this->assign->get_group_submission($userid, 0, true);
        }
        $this->assign->save_grade($userid, $data);
    }

    /**
     * Refuse when a value was not stored as given (the write is rolled back).
     *
     * @param array $values
     * @param array $state
     */
    public function check_written(array $values, array $state) {
        if (!$this->written) {
            return;
        }
        if ($this->undoing && isset($values['members'])) {
            foreach ($values['members'] as $userid => $member) {
                $this->check_user($member, $state['members'][$userid] ?? $this->user_state((int)$userid));
            }
            return;
        }
        $check = array_intersect_key($values, array_flip(self::WRITABLE));
        $this->check_user($check, $state);
        if ($this->applytoall) {
            foreach ($state['members'] as $member) {
                $this->check_user($check, $member);
            }
        }
    }

    /**
     * Compare one student's stored state with the values written.
     *
     * @param array $values
     * @param array $stored
     */
    protected function check_user(array $values, array $stored) {
        $fail = function(string $field, string $reason) {
            throw new \moodle_exception('notsaved', 'local_paatshala', '', (object)['field' => $field, 'reason' => $reason]);
        };
        $different = function($asked, $got) {
            return get_string('notsaved_different', 'local_paatshala',
                (object)['asked' => journal::encode($asked), 'stored' => journal::encode($got)]);
        };
        $grade = array_key_exists('grade', $values) && $values['grade'] !== null ? (float)$values['grade'] : -1.0;
        $got = $stored['grade'] === null ? -1.0 : $stored['grade'];
        if (grade_floatval($grade) !== grade_floatval($got)) {
            $fail('grade', $different($grade, $stored['grade']));
        }
        if ($this->comments() && array_key_exists('feedback_text', $values)) {
            $text = (string)$values['feedback_text'];
            if ($text !== (string)$stored['feedback_text']) {
                $fail('feedback_text', $different($text, $stored['feedback_text']));
            }
            $format = $values['feedback_format'] ?? null;
            if ($format !== null && $stored['feedback_format'] !== null && (int)$format !== $stored['feedback_format']) {
                $fail('feedback_format', get_string('notsaved_commentformat', 'local_paatshala'));
            }
        }
        if ($this->assign->get_instance()->markingworkflow && array_key_exists('workflowstate', $values) &&
                (string)$values['workflowstate'] !== $stored['workflowstate']) {
            $fail('workflowstate', $different($values['workflowstate'], $stored['workflowstate']));
        }
    }

    /**
     * Undo needs every student's grade, comment, format, workflow state and attempt as the
     * entry left them, and the grade's timemodified as the newest journal entry for that
     * student left it: an undo's own save_grade() moves timemodified, so after undoing a
     * later grade an earlier one can still be undone, while a grade saved outside the
     * plugin moves it with no journal entry and is refused.
     *
     * @param array $after
     * @param array $before
     * @param array $current
     */
    public function check_undo(array $after, array $before, array $current) {
        $fields = array_flip(self::UNDO_FIELDS);
        $same = state::matches(array_intersect_key($after, $fields), array_intersect_key($current, $fields)) &&
            $this->newest_timemodified($this->userid) === $current['timemodified'];
        foreach ($after['members'] ?? [] as $userid => $member) {
            $now = $current['members'][$userid] ?? null;
            $same = $same && $now !== null &&
                state::matches(array_intersect_key($member, $fields), array_intersect_key($now, $fields)) &&
                $this->newest_timemodified((int)$userid) === $now['timemodified'];
        }
        if (!$same) {
            $json = journal::encode($current);
            throw new \moodle_exception('conflict', 'local_paatshala', '', $json, $json);
        }
    }

    /**
     * The grade's timemodified as the newest grade entry for this activity and student
     * (an undo included) recorded it, or null without one.
     *
     * @param int $userid
     * @return int|null
     */
    protected function newest_timemodified(int $userid) {
        global $DB;
        $entries = $DB->get_records_sql("SELECT j.id, j.target, j.afterstate
                                           FROM {" . journal::TABLE . "} j
                                           JOIN {" . journal::USERS_TABLE . "} ju ON ju.journalid = j.id
                                          WHERE j.cmid = :cmid AND j.kind = :kind AND ju.userid = :userid
                                       ORDER BY j.id DESC",
            ['cmid' => $this->cm->id, 'kind' => self::kind(), 'userid' => $userid], 0, 1);
        if (!$entries) {
            return null;
        }
        $entry = reset($entries);
        $after = json_decode($entry->afterstate, true);
        $target = json_decode($entry->target, true);
        if (isset($after['members'][$userid])) {
            $value = $after['members'][$userid]['timemodified'];
        } else if ((int)($target['userid'] ?? 0) === $userid) {
            $value = $after['timemodified'] ?? null;
        } else {
            return null;
        }
        return $value === null ? null : (int)$value;
    }

    /**
     * Whether Moodle flagged a notice.
     *
     * @param array $before
     * @param array $after
     * @return bool
     */
    public function notifies(array $before, array $after): bool {
        return $this->notifies;
    }

    /**
     * The student.
     *
     * @return int
     */
    public function relateduserid() {
        return $this->userid;
    }

    /**
     * Every student whose grade the entry holds.
     *
     * @return int[]
     */
    public function relateduserids(): array {
        return array_values(array_unique(array_merge([$this->userid], $this->userids())));
    }

    /**
     * Target: ids, the assignment name and the options of the call.
     *
     * @return array
     */
    public function target(): array {
        return ['cmid' => (int)$this->cm->id, 'instance' => (int)$this->cm->instance, 'name' => $this->cm->name,
            'userid' => $this->userid, 'applytoall' => $this->applytoall ? 1 : 0, 'notify' => $this->notify ? 1 : 0,
            'cancelpending' => $this->cancelpending ? 1 : 0];
    }
}

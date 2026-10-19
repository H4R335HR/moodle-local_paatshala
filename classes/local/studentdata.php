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
 * What counts as student data, for deleting what the plugin created.
 *
 * @package    local_paatshala
 * @copyright  2026 local_paatshala contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_paatshala\local;

/**
 * What counts as student data, for deleting what the plugin created.
 *
 * An activity has student data if any of these exist for a user who counts as a student
 * (counts_as_student()):
 * - a grade_grades row with a final grade on any of its grade items;
 * - a course_modules_completion row with a completion state other than incomplete;
 * - the module's own records, per module type (TABLES below), e.g. assignment
 *   submissions not in status "new", quiz attempts, workshop submissions and their
 *   assessments, forum posts, feedback responses.
 * Rows of staff (a teacher viewing a page, a teacher's quiz preview or test post, an admin)
 * do not count. Module types that can hold no student data of their own are in NONE. Any
 * other module type is refused: the plugin cannot tell whether it holds student data.
 *
 * A course has student data if any user holds a role with the student archetype in the
 * course context.
 *
 * @package    local_paatshala
 * @copyright  2026 local_paatshala contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class studentdata {

    /** @var string[] Module types with no student records of their own. */
    const NONE = ['page', 'url', 'label', 'resource', 'folder', 'book', 'imscp'];

    /**
     * Per module type: name => SQL giving (userid, n) per user owning the records, with
     * :instance. A row with no user (userid 0, e.g. a team submission) always counts.
     *
     * @var array
     */
    const TABLES = [
        'assign' => [
            'submissions' => "SELECT userid, COUNT(1) AS n FROM {assign_submission}
                               WHERE assignment = :instance AND status <> 'new' GROUP BY userid",
        ],
        'quiz' => [
            'attempts' => 'SELECT userid, COUNT(1) AS n FROM {quiz_attempts} WHERE quiz = :instance GROUP BY userid',
        ],
        'workshop' => [
            'submissions' => 'SELECT authorid AS userid, COUNT(1) AS n FROM {workshop_submissions}
                               WHERE workshopid = :instance AND example = 0 GROUP BY authorid',
            'assessments' => 'SELECT a.reviewerid AS userid, COUNT(1) AS n FROM {workshop_assessments} a
                                JOIN {workshop_submissions} s ON s.id = a.submissionid
                               WHERE s.workshopid = :instance AND s.example = 0 GROUP BY a.reviewerid',
        ],
        'forum' => [
            'posts' => 'SELECT p.userid, COUNT(1) AS n FROM {forum_posts} p
                          JOIN {forum_discussions} d ON d.id = p.discussion
                         WHERE d.forum = :instance GROUP BY p.userid',
        ],
        'feedback' => [
            'responses' => 'SELECT userid, COUNT(1) AS n FROM {feedback_completed} WHERE feedback = :instance
                            GROUP BY userid',
            'unsavedresponses' => 'SELECT userid, COUNT(1) AS n FROM {feedback_completedtmp} WHERE feedback = :instance
                                   GROUP BY userid',
        ],
        'choice' => [
            'answers' => 'SELECT userid, COUNT(1) AS n FROM {choice_answers} WHERE choiceid = :instance GROUP BY userid',
        ],
        'data' => [
            'records' => 'SELECT userid, COUNT(1) AS n FROM {data_records} WHERE dataid = :instance GROUP BY userid',
        ],
        'glossary' => [
            'entries' => 'SELECT userid, COUNT(1) AS n FROM {glossary_entries} WHERE glossaryid = :instance
                          GROUP BY userid',
        ],
        'lesson' => [
            'attempts' => 'SELECT userid, COUNT(1) AS n FROM {lesson_attempts} WHERE lessonid = :instance GROUP BY userid',
            'grades' => 'SELECT userid, COUNT(1) AS n FROM {lesson_grades} WHERE lessonid = :instance GROUP BY userid',
        ],
        'scorm' => [
            'tracks' => 'SELECT userid, COUNT(1) AS n FROM {scorm_scoes_track} WHERE scormid = :instance GROUP BY userid',
        ],
    ];

    /**
     * Whether a user's records in a course are student data.
     *
     * - No user (0): yes; the plugin cannot tell whose they are.
     * - Enrolled in the course (active or not): as Moodle's completion reports decide who is a
     *   tracked user (completion_info::is_tracked_user(), lib/completionlib.php:1141):
     *   moodle/course:isincompletionreports in the course, the student archetype's capability.
     *   The enrolment's status is ignored, so a suspended student still counts, and the site
     *   admin's "do anything" is not used, so an admin enrolled as a teacher does not.
     * - Not enrolled: yes (a former student, whose records stay after unenrolment), unless the
     *   user may edit the course's activities without an enrolment (moodle/course:manageactivities:
     *   site admins, managers and teachers assigned at category or site level, the web service
     *   user that deletes), as staff are. A former teacher's leftover rows therefore block too:
     *   the plugin cannot tell them from a former student's.
     *
     * @param int $userid
     * @param \context_course $context
     * @return bool
     */
    public static function counts_as_student(int $userid, \context_course $context): bool {
        if ($userid <= 0) {
            return true;
        }
        if (is_enrolled($context, $userid, '', false)) {
            return has_capability('moodle/course:isincompletionreports', $context, $userid, false);
        }
        return !has_capability('moodle/course:manageactivities', $context, $userid);
    }

    /**
     * Student data held by an activity.
     *
     * @param \stdClass $cm Course module record with modname, instance and course.
     * @return array Name => number of student rows, only for kinds found; 'unknownmodule' => 1
     *     for types the plugin cannot check.
     */
    public static function module(\stdClass $cm): array {
        global $DB;
        $context = \context_course::instance($cm->course);
        $students = [];
        $count = function(array $rows) use ($context, &$students): int {
            $total = 0;
            foreach ($rows as $row) {
                $userid = (int)$row->userid;
                if (!array_key_exists($userid, $students)) {
                    $students[$userid] = self::counts_as_student($userid, $context);
                }
                if ($students[$userid]) {
                    $total += (int)$row->n;
                }
            }
            return $total;
        };
        $found = [];

        $grades = $count($DB->get_records_sql("SELECT gg.userid, COUNT(1) AS n
                                                 FROM {grade_grades} gg
                                                 JOIN {grade_items} gi ON gi.id = gg.itemid
                                                WHERE gi.courseid = :courseid AND gi.itemtype = 'mod'
                                                      AND gi.itemmodule = :modname AND gi.iteminstance = :instance
                                                      AND gg.finalgrade IS NOT NULL
                                             GROUP BY gg.userid",
            ['courseid' => $cm->course, 'modname' => $cm->modname, 'instance' => $cm->instance]));
        if ($grades) {
            $found['grades'] = $grades;
        }
        $completion = $count($DB->get_records_sql('SELECT userid, COUNT(1) AS n
                                                     FROM {course_modules_completion}
                                                    WHERE coursemoduleid = :cmid AND completionstate > 0
                                                 GROUP BY userid', ['cmid' => $cm->id]));
        if ($completion) {
            $found['completions'] = $completion;
        }

        if (in_array($cm->modname, self::NONE, true)) {
            return $found;
        }
        if (empty(self::TABLES[$cm->modname])) {
            $found['unknownmodule'] = 1;
            return $found;
        }
        foreach (self::TABLES[$cm->modname] as $name => $sql) {
            $rows = $count($DB->get_records_sql($sql, ['instance' => $cm->instance]));
            if ($rows) {
                $found[$name] = $rows;
            }
        }
        return $found;
    }

    /**
     * Student data in a course: users with a student-archetype role in the course context.
     *
     * @param int $courseid
     * @return array 'students' => count, if any.
     */
    public static function course(int $courseid): array {
        global $DB;
        $roles = array_keys(get_archetype_roles('student'));
        if (!$roles) {
            return [];
        }
        list($insql, $params) = $DB->get_in_or_equal($roles, SQL_PARAMS_NAMED);
        $params['contextid'] = \context_course::instance($courseid)->id;
        $count = $DB->count_records_sql("SELECT COUNT(DISTINCT userid)
                                           FROM {role_assignments}
                                          WHERE contextid = :contextid AND roleid $insql", $params);
        return $count ? ['students' => $count] : [];
    }
}

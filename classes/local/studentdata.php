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
 * An activity has student data if any of these exist:
 * - a grade_grades row with a final grade on any of its grade items;
 * - a course_modules_completion row with a completion state other than incomplete;
 * - the module's own records, per module type (TABLES below), e.g. assignment
 *   submissions not in status "new", quiz attempts, workshop submissions and their
 *   assessments, forum posts, feedback responses.
 * Module types that can hold no student data of their own are in NONE. Any other module
 * type is refused: the plugin cannot tell whether it holds student data.
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
     * Per module type: name => [SQL counting student records, with :instance].
     *
     * @var array
     */
    const TABLES = [
        'assign' => [
            'submissions' => "SELECT COUNT(1) FROM {assign_submission} WHERE assignment = :instance AND status <> 'new'",
        ],
        'quiz' => [
            'attempts' => 'SELECT COUNT(1) FROM {quiz_attempts} WHERE quiz = :instance',
        ],
        'workshop' => [
            'submissions' => 'SELECT COUNT(1) FROM {workshop_submissions} WHERE workshopid = :instance AND example = 0',
            'assessments' => 'SELECT COUNT(1) FROM {workshop_assessments} a
                                JOIN {workshop_submissions} s ON s.id = a.submissionid
                               WHERE s.workshopid = :instance AND s.example = 0',
        ],
        'forum' => [
            'posts' => 'SELECT COUNT(1) FROM {forum_posts} p
                          JOIN {forum_discussions} d ON d.id = p.discussion
                         WHERE d.forum = :instance',
        ],
        'feedback' => [
            'responses' => 'SELECT COUNT(1) FROM {feedback_completed} WHERE feedback = :instance',
            'unsavedresponses' => 'SELECT COUNT(1) FROM {feedback_completedtmp} WHERE feedback = :instance',
        ],
        'choice' => [
            'answers' => 'SELECT COUNT(1) FROM {choice_answers} WHERE choiceid = :instance',
        ],
        'data' => [
            'records' => 'SELECT COUNT(1) FROM {data_records} WHERE dataid = :instance',
        ],
        'glossary' => [
            'entries' => 'SELECT COUNT(1) FROM {glossary_entries} WHERE glossaryid = :instance',
        ],
        'lesson' => [
            'attempts' => 'SELECT COUNT(1) FROM {lesson_attempts} WHERE lessonid = :instance',
            'grades' => 'SELECT COUNT(1) FROM {lesson_grades} WHERE lessonid = :instance',
        ],
        'scorm' => [
            'tracks' => 'SELECT COUNT(1) FROM {scorm_scoes_track} WHERE scormid = :instance',
        ],
    ];

    /**
     * Student data held by an activity.
     *
     * @param \stdClass $cm Course module record with modname, instance and course.
     * @return array Name => count, only for kinds found; 'unknownmodule' => 1 for types the
     *     plugin cannot check.
     */
    public static function module(\stdClass $cm): array {
        global $DB;
        $found = [];

        $grades = $DB->count_records_sql("SELECT COUNT(1)
                                            FROM {grade_grades} gg
                                            JOIN {grade_items} gi ON gi.id = gg.itemid
                                           WHERE gi.courseid = :courseid AND gi.itemtype = 'mod'
                                                 AND gi.itemmodule = :modname AND gi.iteminstance = :instance
                                                 AND gg.finalgrade IS NOT NULL",
            ['courseid' => $cm->course, 'modname' => $cm->modname, 'instance' => $cm->instance]);
        if ($grades) {
            $found['grades'] = $grades;
        }
        $completion = $DB->count_records_select('course_modules_completion',
            'coursemoduleid = :cmid AND completionstate > 0', ['cmid' => $cm->id]);
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
            $count = $DB->count_records_sql($sql, ['instance' => $cm->instance]);
            if ($count) {
                $found[$name] = $count;
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

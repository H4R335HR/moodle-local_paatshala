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
 * Activity completion settings and the completion data that depends on them.
 *
 * @package    local_paatshala
 * @copyright  2026 local_paatshala contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_paatshala\local;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/completionlib.php');
require_once($CFG->dirroot . '/completion/criteria/completion_criteria.php');

/**
 * Activity completion settings, what the forms offer, completion data and references to it.
 *
 * What each module's settings form offers, the completion data a change would reset, and
 * the access restrictions that point at an activity's completion.
 *
 * Settings, as get_moduleinfo_data() reads them (course/modlib.php:691-695) and
 * update_moduleinfo() takes them: completion (COMPLETION_TRACKING_NONE 0, _MANUAL 1,
 * _AUTOMATIC 2), completionview, completionusegrade (completiongradeitemnumber not null),
 * completiongradeitemnumber, completionexpected, and the module's own rules (RULES), stored
 * in the module's table.
 *
 * @package    local_paatshala
 * @copyright  2026 local_paatshala contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class completion {

    /** @var int[] Completion modes by name. */
    const MODES = ['none' => COMPLETION_TRACKING_NONE, 'manual' => COMPLETION_TRACKING_MANUAL,
        'automatic' => COMPLETION_TRACKING_AUTOMATIC];

    /**
     * The module types the plugin changes, with the rules each settings form adds through
     * add_completion_rules() in Moodle 3.8: mod/assign/mod_form.php:295 (completionsubmit),
     * mod/quiz/mod_form.php:617 (completionpass, completionattemptsexhausted),
     * mod/feedback/mod_form.php:206 (completionsubmit), mod/forum/mod_form.php:438 (the three
     * counts, each with an "enabled" checkbox). The others add none.
     *
     * @var array
     */
    const RULES = [
        'page' => [],
        'url' => [],
        'resource' => [],
        'book' => [],
        'folder' => [],
        'label' => [],
        'assign' => ['completionsubmit'],
        'quiz' => ['completionpass', 'completionattemptsexhausted'],
        'workshop' => [],
        'feedback' => ['completionsubmit'],
        'forum' => ['completiondiscussions', 'completionreplies', 'completionposts'],
    ];

    /** @var string[] Rules that hold a count (0 off) rather than a 0/1 switch. */
    const COUNT_RULES = ['completiondiscussions', 'completionreplies', 'completionposts'];

    /** @var string[] Settings every activity has (course_modules columns). */
    const STANDARD = ['completion', 'completionview', 'completionusegrade', 'completiongradeitemnumber',
        'completionexpected'];

    /**
     * @var string[] Completion data counts, flat, as the state and `expected` hold them: course_modules_completion
     *     rows of students and of others, those complete (any complete state), those viewed; course_completions
     *     rows of students and of others when the activity is a course completion criterion (else 0).
     */
    const COUNTS = ['students', 'studentscomplete', 'studentsviewed', 'others', 'otherscomplete', 'othersviewed',
        'coursestudents', 'courseothers'];

    /** @var int[] Expected completion states of a completion condition by name (availability_completion). */
    const STATES = ['incomplete' => COMPLETION_INCOMPLETE, 'complete' => COMPLETION_COMPLETE,
        'pass' => COMPLETION_COMPLETE_PASS, 'fail' => COMPLETION_COMPLETE_FAIL];

    /**
     * Every rule name some supported module has.
     *
     * @return string[]
     */
    public static function all_rules(): array {
        $rules = [];
        foreach (self::RULES as $list) {
            $rules = array_merge($rules, $list);
        }
        return array_values(array_unique($rules));
    }

    /**
     * Settings fields of a module type: the standard ones and its own rules.
     *
     * @param string $modname
     * @return string[]
     */
    public static function fields(string $modname): array {
        return array_merge(self::STANDARD, self::RULES[$modname] ?? []);
    }

    /**
     * What the module's settings form offers, as moodleform_mod::standard_coursemodule_elements()
     * decides (course/moodleform_mod.php:680-743): "Student must view this activity" when the module
     * supports FEATURE_COMPLETION_TRACKS_VIEWS; "Student must receive a grade" when it supports
     * FEATURE_GRADE_HAS_GRADE, as a checkbox (completionusegrade) for one grade item or a select of
     * items (completiongradeitemnumber) for several (workshop, forum); its own rules; Automatic only
     * when any of these exists.
     *
     * @param string $modname
     * @return array {supported, view, grade, gradefield ('completionusegrade', 'completiongradeitemnumber'
     *     or ''), gradeitems [{itemnumber, name}], rules string[], automatic}
     */
    public static function supports(string $modname): array {
        $view = (bool)plugin_supports('mod', $modname, FEATURE_COMPLETION_TRACKS_VIEWS, false);
        $grade = (bool)plugin_supports('mod', $modname, FEATURE_GRADE_HAS_GRADE, false);
        $items = [];
        $gradefield = '';
        if ($grade) {
            $component = 'mod_' . $modname;
            $mapping = \core_grades\component_gradeitems::get_itemname_mapping_for_component($component);
            foreach ($mapping as $itemnumber => $itemname) {
                $items[] = ['itemnumber' => (int)$itemnumber, 'name' => count($mapping) > 1 ?
                    get_string("grade_{$itemname}_name", $component) : get_string('grade')];
            }
            $gradefield = count($mapping) > 1 ? 'completiongradeitemnumber' : 'completionusegrade';
        }
        $rules = self::RULES[$modname] ?? [];
        return [
            'supported' => array_key_exists($modname, self::RULES),
            'view' => $view,
            'grade' => $grade,
            'gradefield' => $gradefield,
            'gradeitems' => $items,
            'rules' => $rules,
            'automatic' => $view || $grade || (bool)$rules,
        ];
    }

    /**
     * Site and course completion: refuse unless both are on.
     *
     * @param \stdClass $course
     * @throws \moodle_exception completiondisabled, naming which
     */
    public static function require_enabled(\stdClass $course) {
        global $CFG;
        if (empty($CFG->enablecompletion)) {
            throw new \moodle_exception('completiondisabled', 'local_paatshala', '',
                get_string('completiondisabled_site', 'local_paatshala'));
        }
        $enabled = (int)get_course($course->id)->enablecompletion;
        if (!$enabled) {
            throw new \moodle_exception('completiondisabled', 'local_paatshala', '',
                get_string('completiondisabled_course', 'local_paatshala', $course->id));
        }
    }

    /**
     * The completion settings of an activity, read from the database.
     *
     * @param \stdClass|\cm_info $cm Course module with modname and instance.
     * @return array Field => value (completiongradeitemnumber null when not set).
     */
    public static function settings($cm): array {
        global $DB;
        $row = $DB->get_record('course_modules', ['id' => $cm->id],
            'id, completion, completionview, completiongradeitemnumber, completionexpected', MUST_EXIST);
        $settings = [
            'completion' => (int)$row->completion,
            'completionview' => (int)$row->completionview,
            'completionusegrade' => $row->completiongradeitemnumber === null ? 0 : 1,
            'completiongradeitemnumber' => $row->completiongradeitemnumber === null ? null :
                (int)$row->completiongradeitemnumber,
            'completionexpected' => (int)$row->completionexpected,
        ];
        $rules = self::RULES[$cm->modname] ?? [];
        if ($rules) {
            $record = $DB->get_record($cm->modname, ['id' => $cm->instance], implode(', ', $rules), MUST_EXIST);
            foreach ($rules as $rule) {
                $settings[$rule] = (int)$record->$rule;
            }
        }
        return $settings;
    }

    /**
     * The completion data of an activity, split with the student rule of studentdata
     * (counts_as_student()).
     *
     * locked is what the settings page freezes on: completion_info::count_user_data()
     * (lib/completionlib.php:761) counts rows with a state other than incomplete, of any user
     * (course/moodleform_mod.php:325-366). coursecompletion: when the activity is a course
     * completion criterion, completion_info::delete_all_state() (lib/completionlib.php:855-886),
     * which a reset runs, deletes every course_completions row of the course.
     *
     * @param \stdClass|\cm_info $cm Course module with course.
     * @param array $students Cache userid => counts as student, shared across calls.
     * @return array {students: {rows, incomplete, complete, completepass, completefail, viewed},
     *     others: same, locked, coursecompletion: {criterion, students, studentscompleted, others,
     *     otherscompleted}}
     */
    public static function data($cm, array &$students = []): array {
        global $DB;
        $context = \context_course::instance($cm->course);
        $isstudent = function(int $userid) use ($context, &$students): bool {
            if (!array_key_exists($userid, $students)) {
                $students[$userid] = studentdata::counts_as_student($userid, $context);
            }
            return $students[$userid];
        };
        $empty = ['rows' => 0, 'incomplete' => 0, 'complete' => 0, 'completepass' => 0, 'completefail' => 0,
            'viewed' => 0];
        $result = ['students' => $empty, 'others' => $empty, 'locked' => false];
        $keys = [COMPLETION_INCOMPLETE => 'incomplete', COMPLETION_COMPLETE => 'complete',
            COMPLETION_COMPLETE_PASS => 'completepass', COMPLETION_COMPLETE_FAIL => 'completefail'];
        $rows = $DB->get_records('course_modules_completion', ['coursemoduleid' => $cm->id], '',
            'id, userid, completionstate, viewed');
        foreach ($rows as $row) {
            $who = $isstudent((int)$row->userid) ? 'students' : 'others';
            $result[$who]['rows']++;
            $state = $keys[(int)$row->completionstate] ?? 'complete';
            $result[$who][$state]++;
            if ((int)$row->viewed) {
                $result[$who]['viewed']++;
            }
            if ((int)$row->completionstate !== COMPLETION_INCOMPLETE) {
                $result['locked'] = true;
            }
        }
        $course = ['criterion' => self::is_criterion($cm), 'students' => 0, 'studentscompleted' => 0, 'others' => 0,
            'otherscompleted' => 0];
        if ($course['criterion']) {
            $records = $DB->get_records('course_completions', ['course' => $cm->course], '', 'id, userid, timecompleted');
            foreach ($records as $row) {
                $who = $isstudent((int)$row->userid) ? 'students' : 'others';
                $course[$who]++;
                if ($row->timecompleted) {
                    $course[$who . 'completed']++;
                }
            }
        }
        $result['coursecompletion'] = $course;
        return $result;
    }

    /**
     * The data counts, flat (COUNTS).
     *
     * @param array $data From data().
     * @return array
     */
    public static function counts(array $data): array {
        $complete = function(array $who): int {
            return $who['complete'] + $who['completepass'] + $who['completefail'];
        };
        return [
            'students' => $data['students']['rows'],
            'studentscomplete' => $complete($data['students']),
            'studentsviewed' => $data['students']['viewed'],
            'others' => $data['others']['rows'],
            'otherscomplete' => $complete($data['others']),
            'othersviewed' => $data['others']['viewed'],
            'coursestudents' => $data['coursecompletion']['students'],
            'courseothers' => $data['coursecompletion']['others'],
        ];
    }

    /**
     * Whether the activity is a criterion of the course's completion ("Activities completed").
     *
     * @param \stdClass|\cm_info $cm
     * @return bool
     */
    public static function is_criterion($cm): bool {
        global $DB;
        return $DB->record_exists('course_completion_criteria', ['course' => $cm->course,
            'criteriatype' => COMPLETION_CRITERIA_TYPE_ACTIVITY, 'moduleinstance' => $cm->id]);
    }

    /**
     * Every completion condition in the course's access restrictions, by the activity it
     * points at, nested sets included.
     *
     * @param \stdClass $course
     * @return array cmid => list of {type (section, module), sectionid, sectionnum, cmid, modname, name,
     *     path, negated, display, state}
     */
    public static function references(\stdClass $course): array {
        global $DB;
        $references = [];
        $add = function(array $item, string $json) use (&$references) {
            if ($json === '') {
                return;
            }
            foreach (availability_report::conditions($json) as $condition) {
                $fields = json_decode($condition['fields']);
                if ($condition['type'] !== 'completion' || !isset($fields->cm) || !is_number($fields->cm)) {
                    continue;
                }
                $references[(int)$fields->cm][] = $item + [
                    'path' => $condition['path'],
                    'negated' => $condition['negated'],
                    'display' => $condition['display'],
                    'state' => isset($fields->e) ? (int)$fields->e : -1,
                ];
            }
        };
        $sections = $DB->get_records('course_sections', ['course' => $course->id], 'section ASC');
        foreach ($sections as $section) {
            $add(['type' => 'section', 'sectionid' => (int)$section->id, 'sectionnum' => (int)$section->section,
                'cmid' => 0, 'modname' => '', 'name' => get_section_name($course, $section)],
                (string)$section->availability);
        }
        $cms = $DB->get_records_sql("SELECT cm.id, cm.section, cm.instance, cm.availability, m.name AS modname
                                       FROM {course_modules} cm
                                       JOIN {modules} m ON m.id = cm.module
                                      WHERE cm.course = :courseid AND cm.deletioninprogress = 0
                                            AND cm.availability IS NOT NULL
                                   ORDER BY cm.id", ['courseid' => $course->id]);
        foreach ($cms as $cm) {
            $add(['type' => 'module', 'sectionid' => (int)$cm->section,
                'sectionnum' => isset($sections[$cm->section]) ? (int)$sections[$cm->section]->section : -1,
                'cmid' => (int)$cm->id, 'modname' => $cm->modname,
                'name' => (string)$DB->get_field($cm->modname, 'name', ['id' => $cm->instance])],
                (string)$cm->availability);
        }
        return $references;
    }

    /**
     * A mode given by name or number.
     *
     * @param mixed $value
     * @return int
     * @throws \invalid_parameter_exception
     */
    public static function mode($value): int {
        if (is_string($value) && array_key_exists($value, self::MODES)) {
            return self::MODES[$value];
        }
        if ((is_int($value) || (is_string($value) && ctype_digit($value))) && in_array((int)$value, self::MODES, true)) {
            return (int)$value;
        }
        throw new \invalid_parameter_exception('completion must be none, manual or automatic (or 0, 1, 2)');
    }
}

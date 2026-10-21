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
 * Read an activity's completion settings.
 *
 * @package    local_paatshala
 * @copyright  2026 local_paatshala contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_paatshala\external;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/externallib.php');

use external_api;
use external_function_parameters;
use external_multiple_structure;
use external_single_structure;
use external_value;
use local_paatshala\gate;
use local_paatshala\journal;
use local_paatshala\local\completion;

/**
 * local_paatshala_get_completion: an activity's completion settings and data.
 *
 * Its settings, what its settings form offers, the completion data a change would reset,
 * and the restrictions that point at it.
 *
 * @package    local_paatshala
 * @copyright  2026 local_paatshala contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_completion extends external_api {

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module id'),
        ]);
    }

    /**
     * Read it.
     *
     * @param int $cmid
     * @return array
     */
    public static function execute($cmid): array {
        global $CFG;
        $params = self::validate_parameters(self::execute_parameters(), ['cmid' => $cmid]);
        list($cm, $course, $context) = gate::module($params['cmid']);
        // The settings page (course/modedit.php) requires moodle/course:manageactivities; so does
        // the bulk completion page (course/bulkcompletion.php:61, completion/classes/manager.php:239).
        require_capability('moodle/course:manageactivities', $context);
        $cminfo = get_fast_modinfo($course)->get_cm($cm->id);
        $students = [];
        return [
            'siteenabled' => !empty($CFG->enablecompletion),
            'courseenabled' => (bool)get_course($course->id)->enablecompletion,
        ] + self::item($cminfo, completion::references($course)[(int)$cm->id] ?? [], $students);
    }

    /**
     * One activity's completion, as get_completion and get_course_completion return it.
     *
     * @param \cm_info $cm
     * @param array $references From completion::references() for this activity.
     * @param array $students Student cache shared across activities (completion::data()).
     * @return array
     */
    public static function item(\cm_info $cm, array $references, array &$students): array {
        $settings = completion::settings($cm);
        $data = completion::data($cm, $students);
        $rules = [];
        foreach (completion::RULES[$cm->modname] ?? [] as $rule) {
            $rules[] = ['name' => $rule, 'value' => $settings[$rule]];
        }
        return [
            'cmid' => (int)$cm->id,
            'courseid' => (int)$cm->course,
            'modname' => $cm->modname,
            'name' => $cm->name,
            'sectionnum' => (int)$cm->sectionnum,
            'visible' => (int)$cm->visible,
            'completion' => $settings['completion'],
            'completionview' => $settings['completionview'],
            'completionusegrade' => $settings['completionusegrade'],
            'completiongradeitemnumber' => $settings['completiongradeitemnumber'],
            'completionexpected' => $settings['completionexpected'],
            'rules' => $rules,
            'supports' => completion::supports($cm->modname),
            'data' => $data,
            'referencedby' => $references,
            'state' => journal::encode($settings + completion::counts($data)),
        ];
    }

    /**
     * Return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure(array_merge([
            'siteenabled' => new external_value(PARAM_BOOL, 'Site setting enablecompletion'),
            'courseenabled' => new external_value(PARAM_BOOL, 'The course\'s "Enable completion tracking"'),
        ], self::item_fields()));
    }

    /**
     * Fields of one activity.
     *
     * @return array
     */
    public static function item_fields(): array {
        $counts = function(string $who) {
            return new external_single_structure([
                'rows' => new external_value(PARAM_INT, 'Completion rows of ' . $who),
                'incomplete' => new external_value(PARAM_INT, 'Incomplete (e.g. viewed only)'),
                'complete' => new external_value(PARAM_INT, 'Complete'),
                'completepass' => new external_value(PARAM_INT, 'Complete with a pass grade'),
                'completefail' => new external_value(PARAM_INT, 'Complete with a fail grade'),
                'viewed' => new external_value(PARAM_INT, 'Viewed flag set'),
            ]);
        };
        return [
            'cmid' => new external_value(PARAM_INT, 'Course module id'),
            'courseid' => new external_value(PARAM_INT, 'Course id'),
            'modname' => new external_value(PARAM_PLUGIN, 'Module type'),
            'name' => new external_value(PARAM_TEXT, 'Activity name'),
            'sectionnum' => new external_value(PARAM_INT, 'Section number'),
            'visible' => new external_value(PARAM_INT, '1 shown, 0 hidden'),
            'completion' => new external_value(PARAM_INT, '0 none, 1 manual (students tick it), 2 automatic'),
            'completionview' => new external_value(PARAM_INT, 'Automatic: student must view it'),
            'completionusegrade' => new external_value(PARAM_INT, 'Automatic: student must receive a grade'),
            'completiongradeitemnumber' => new external_value(PARAM_INT, 'Grade item number that must be graded, ' .
                'null for none', VALUE_REQUIRED, null, NULL_ALLOWED),
            'completionexpected' => new external_value(PARAM_INT, 'Expect completed on (unix time, 0 none)'),
            'rules' => new external_multiple_structure(new external_single_structure([
                'name' => new external_value(PARAM_ALPHANUMEXT, 'Field name, as the settings form submits it'),
                'value' => new external_value(PARAM_INT, '0 off, 1 on, or a count (forum)'),
            ]), 'The module\'s own automatic completion rules'),
            'supports' => new external_single_structure([
                'supported' => new external_value(PARAM_BOOL, 'local_paatshala_set_completion changes this module type'),
                'view' => new external_value(PARAM_BOOL, 'The form offers "Student must view this activity"'),
                'grade' => new external_value(PARAM_BOOL, 'The form offers "Student must receive a grade"'),
                'gradefield' => new external_value(PARAM_ALPHANUMEXT,
                    'completionusegrade (one grade item), completiongradeitemnumber (several), or empty'),
                'gradeitems' => new external_multiple_structure(new external_single_structure([
                    'itemnumber' => new external_value(PARAM_INT, 'Grade item number'),
                    'name' => new external_value(PARAM_TEXT, 'Name'),
                ])),
                'rules' => new external_multiple_structure(new external_value(PARAM_ALPHANUMEXT, 'Rule field'),
                    'The module\'s own rules the form offers'),
                'automatic' => new external_value(PARAM_BOOL, 'The form offers Automatic'),
            ]),
            'data' => new external_single_structure([
                'students' => $counts('students (as studentdata counts them)'),
                'others' => $counts('others (staff)'),
                'locked' => new external_value(PARAM_BOOL, 'The settings page freezes completion until ' .
                    '"Unlock completion options" (somebody has a state other than incomplete)'),
                'coursecompletion' => new external_single_structure([
                    'criterion' => new external_value(PARAM_BOOL,
                        'The activity is a course completion criterion (a reset deletes the course completions)'),
                    'students' => new external_value(PARAM_INT, 'Course completion records of students'),
                    'studentscompleted' => new external_value(PARAM_INT, 'Of those, course completed'),
                    'others' => new external_value(PARAM_INT, 'Course completion records of others'),
                    'otherscompleted' => new external_value(PARAM_INT, 'Of those, course completed'),
                ]),
            ]),
            'referencedby' => new external_multiple_structure(new external_single_structure([
                'type' => new external_value(PARAM_ALPHA, 'section or module'),
                'sectionid' => new external_value(PARAM_INT, 'Section id (for an activity: its section)'),
                'sectionnum' => new external_value(PARAM_INT, 'Section number'),
                'cmid' => new external_value(PARAM_INT, 'Course module id (0 for a section)'),
                'modname' => new external_value(PARAM_ALPHANUMEXT, 'Module type (empty for a section)'),
                'name' => new external_value(PARAM_TEXT, 'Name'),
                'path' => new external_value(PARAM_RAW, 'Where in its tree'),
                'negated' => new external_value(PARAM_BOOL, 'Applies negated'),
                'display' => new external_value(PARAM_ALPHA, 'When unmet: show or hide'),
                'state' => new external_value(PARAM_INT, 'Required state: 0 incomplete, 1 complete, 2 pass, 3 fail'),
            ]), 'Sections and activities whose access restrictions have a completion condition on this activity'),
            'state' => new external_value(PARAM_RAW, 'JSON: the settings and data counts, as ' .
                'local_paatshala_set_completion\'s expected takes them'),
        ];
    }
}

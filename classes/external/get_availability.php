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
 * Read the access restrictions of a section or an activity.
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
use local_paatshala\local\availability_report;
use local_paatshala\local\kind\module_availability;
use local_paatshala\local\kind\section_availability;

/**
 * local_paatshala_get_availability: the stored tree of one section or activity, as data.
 *
 * Needs what the edit pages check: moodle/course:update for a section
 * (course/editsection.php:42), moodle/course:manageactivities for an activity
 * (course/modlib.php:475).
 *
 * @package    local_paatshala
 * @copyright  2026 local_paatshala contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_availability extends external_api {

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course'),
            'sectionid' => new external_value(PARAM_INT, 'Section id (course_sections.id), or give cmid', VALUE_DEFAULT, 0),
            'cmid' => new external_value(PARAM_INT, 'Course module id, or give sectionid', VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * Read it.
     *
     * @param int $courseid
     * @param int $sectionid
     * @param int $cmid
     * @return array
     */
    public static function execute($courseid, $sectionid = 0, $cmid = 0): array {
        global $DB;
        $params = self::validate_parameters(self::execute_parameters(),
            ['courseid' => $courseid, 'sectionid' => $sectionid, 'cmid' => $cmid]);
        if (($params['sectionid'] > 0) === ($params['cmid'] > 0)) {
            throw new \invalid_parameter_exception('Give exactly one of sectionid and cmid');
        }
        $course = get_course($params['courseid']);
        if ($params['sectionid']) {
            // Gate, course and capability checks.
            new section_availability($params['courseid'], $params['sectionid']);
            $section = $DB->get_record('course_sections', ['id' => $params['sectionid']], '*', MUST_EXIST);
            return availability_report::section($course, $section);
        }
        new module_availability($params['courseid'], $params['cmid']);
        return self::module($course, $params['cmid']);
    }

    /**
     * Report for an activity.
     *
     * @param \stdClass $course
     * @param int $cmid
     * @return array
     */
    public static function module(\stdClass $course, int $cmid): array {
        global $DB;
        $cm = get_coursemodule_from_id('', $cmid, $course->id, true, MUST_EXIST);
        $cm->availability = $DB->get_field('course_modules', 'availability', ['id' => $cmid]);
        return availability_report::module($course, $cm, (int)$cm->sectionnum);
    }

    /**
     * Return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return self::item_structure();
    }

    /**
     * Structure of one item.
     *
     * @return external_single_structure
     */
    public static function item_structure(): external_single_structure {
        return new external_single_structure([
            'type' => new external_value(PARAM_ALPHA, 'section or module'),
            'courseid' => new external_value(PARAM_INT, 'Course'),
            'sectionid' => new external_value(PARAM_INT, 'Section id (for an activity: its section)'),
            'sectionnum' => new external_value(PARAM_INT, 'Section number'),
            'cmid' => new external_value(PARAM_INT, 'Course module id (0 for a section)'),
            'modname' => new external_value(PARAM_ALPHANUMEXT, 'Module type (empty for a section)'),
            'name' => new external_value(PARAM_TEXT, 'Name'),
            'visible' => new external_value(PARAM_INT, 'Its own visible flag (separate from restrictions)'),
            'activities' => new external_value(PARAM_INT, 'Section: number of activities in it', VALUE_OPTIONAL),
            'availability' => new external_value(PARAM_RAW, 'The tree as stored (JSON), empty for none'),
            'valid' => new external_value(PARAM_BOOL, 'Moodle can read the stored tree'),
            'parseerror' => new external_value(PARAM_RAW, 'Why not, when valid is false'),
            'groups' => new external_multiple_structure(new external_single_structure([
                'id' => new external_value(PARAM_INT, 'Group id'),
                'name' => new external_value(PARAM_TEXT, 'Group name (empty if it does not exist)'),
                'exists' => new external_value(PARAM_BOOL, 'The group exists'),
                'incourse' => new external_value(PARAM_BOOL, 'The group belongs to this course'),
                'members' => new external_value(PARAM_INT, 'Number of members'),
            ]), 'Every group the tree names'),
            'conditions' => new external_multiple_structure(new external_single_structure([
                'type' => new external_value(PARAM_ALPHANUMEXT, 'Condition type (date, group, completion, grade, ...)'),
                'path' => new external_value(PARAM_RAW, 'Where in the tree, e.g. c[1].c[0]'),
                'negated' => new external_value(PARAM_BOOL, 'Applies negated (inside an odd number of "must not" sets)'),
                'display' => new external_value(PARAM_ALPHA, 'When unmet: show (with the restriction message) or hide'),
                'fields' => new external_value(PARAM_RAW, 'JSON: the condition\'s own fields'),
                'cm' => new external_single_structure([
                    'cmid' => new external_value(PARAM_INT, 'Course module id it names'),
                    'name' => new external_value(PARAM_TEXT, 'Activity name (empty if it does not exist)'),
                    'modname' => new external_value(PARAM_ALPHANUMEXT, 'Module type'),
                    'completion' => new external_value(PARAM_INT, 'Its completion: 0 none, 1 manual, 2 automatic'),
                    'completiongradeitemnumber' => new external_value(PARAM_INT,
                        'Its completion grade item number, -1 when completion does not require a grade'),
                    'exists' => new external_value(PARAM_BOOL, 'The activity exists in the course (not being deleted)'),
                ], 'Completion conditions: the activity', VALUE_OPTIONAL),
                'gradeitem' => new external_single_structure([
                    'id' => new external_value(PARAM_INT, 'Grade item id it names'),
                    'name' => new external_value(PARAM_TEXT, 'Grade item name (empty if it does not exist)'),
                    'itemtype' => new external_value(PARAM_ALPHA, 'mod, manual, category or course'),
                    'cmid' => new external_value(PARAM_INT, 'The activity, for an activity\'s grade item (else 0)'),
                    'grademax' => new external_value(PARAM_FLOAT, 'Maximum grade'),
                    'gradepass' => new external_value(PARAM_FLOAT, 'Grade to pass (0 none)'),
                    'exists' => new external_value(PARAM_BOOL, 'The grade item exists in the course'),
                ], 'Grade conditions: the grade item (min and max in fields are percentages)', VALUE_OPTIONAL),
            ]), 'Every condition, flattened'),
            'enableavailability' => new external_value(PARAM_BOOL, 'Site setting enableavailability'),
            'groupenabled' => new external_value(PARAM_BOOL, 'The availability_group plugin is enabled'),
            'dateenabled' => new external_value(PARAM_BOOL, 'The availability_date plugin is enabled'),
            'completionenabled' => new external_value(PARAM_BOOL, 'The availability_completion plugin is enabled'),
            'gradeenabled' => new external_value(PARAM_BOOL, 'The availability_grade plugin is enabled'),
            'parts' => new external_multiple_structure(new external_single_structure([
                'part' => new external_value(PARAM_ALPHA, 'group, from, until, completion or grade'),
                'present' => new external_value(PARAM_BOOL, 'The tree has this part'),
                'paths' => new external_multiple_structure(new external_value(PARAM_RAW, 'Path, e.g. c[1]'),
                    'Its top-level children: one for group, from and until; for completion and grade every ' .
                    'top-level condition of the type (each with its own display in conditions), or one "all of" set'),
                'changeable' => new external_value(PARAM_BOOL, 'local_paatshala_set_access can set or remove it now'),
                'reason' => new external_value(PARAM_RAW, 'Why not, when changeable is false'),
            ]), 'The parts local_paatshala_set_access manages'),
        ]);
    }
}

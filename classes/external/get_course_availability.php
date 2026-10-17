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
 * Read the access restrictions of every section and activity of a course.
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
use local_paatshala\local\availability_report;

/**
 * local_paatshala_get_course_availability: every restricted section and activity.
 *
 * Read from the tables, not evaluated for any user, so one tree Moodle cannot read is
 * reported (valid false) instead of failing the call. Sections need moodle/course:update
 * (course/editsection.php:42); activities are listed where the caller has
 * moodle/course:manageactivities (course/modlib.php:475).
 *
 * @package    local_paatshala
 * @copyright  2026 local_paatshala contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_course_availability extends external_api {

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course'),
        ]);
    }

    /**
     * List them.
     *
     * @param int $courseid
     * @return array
     */
    public static function execute($courseid): array {
        global $DB;
        $params = self::validate_parameters(self::execute_parameters(), ['courseid' => $courseid]);
        $context = gate::course($params['courseid']);
        require_capability('moodle/course:update', $context);
        $course = get_course($params['courseid']);

        $items = [];
        $sections = $DB->get_records('course_sections', ['course' => $course->id], 'section ASC');
        foreach ($sections as $section) {
            if ($section->availability !== null && $section->availability !== '') {
                $items[] = availability_report::section($course, $section);
            }
        }
        $cms = $DB->get_records_sql("SELECT cm.*, m.name AS modname
                                       FROM {course_modules} cm
                                       JOIN {modules} m ON m.id = cm.module
                                      WHERE cm.course = :courseid AND cm.deletioninprogress = 0
                                            AND cm.availability IS NOT NULL
                                   ORDER BY cm.id", ['courseid' => $course->id]);
        foreach ($cms as $cm) {
            if ($cm->availability === '' ||
                    !has_capability('moodle/course:manageactivities', \context_module::instance($cm->id))) {
                continue;
            }
            $cm->name = (string)$DB->get_field($cm->modname, 'name', ['id' => $cm->instance]);
            $items[] = availability_report::module($course, $cm, (int)$sections[$cm->section]->section);
        }
        return ['items' => $items];
    }

    /**
     * Return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'items' => new external_multiple_structure(get_availability::item_structure()),
        ]);
    }
}

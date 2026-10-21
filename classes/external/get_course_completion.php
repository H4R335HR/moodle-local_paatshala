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
 * Read the completion settings of every activity in a course.
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
use local_paatshala\local\completion;

/**
 * local_paatshala_get_course_completion: get_completion for every activity of a course.
 *
 * In course page order, in one call.
 *
 * @package    local_paatshala
 * @copyright  2026 local_paatshala contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_course_completion extends external_api {

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
        global $CFG;
        $params = self::validate_parameters(self::execute_parameters(), ['courseid' => $courseid]);
        $context = gate::course($params['courseid']);
        // As the bulk completion page (course/bulkcompletion.php:61).
        require_capability('moodle/course:manageactivities', $context);
        $course = get_course($params['courseid']);
        $references = completion::references($course);
        $students = [];
        $items = [];
        foreach (get_fast_modinfo($course)->get_cms() as $cm) {
            // Activities being deleted are left out, as the course page and the restriction forms do.
            if ($cm->deletioninprogress || !has_capability('moodle/course:manageactivities', $cm->context)) {
                continue;
            }
            $items[] = get_completion::item($cm, $references[(int)$cm->id] ?? [], $students);
        }
        return [
            'courseid' => (int)$course->id,
            'siteenabled' => !empty($CFG->enablecompletion),
            'courseenabled' => (bool)$course->enablecompletion,
            'items' => $items,
        ];
    }

    /**
     * Return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'courseid' => new external_value(PARAM_INT, 'Course'),
            'siteenabled' => new external_value(PARAM_BOOL, 'Site setting enablecompletion'),
            'courseenabled' => new external_value(PARAM_BOOL, 'The course\'s "Enable completion tracking"'),
            'items' => new external_multiple_structure(new external_single_structure(get_completion::item_fields())),
        ]);
    }
}

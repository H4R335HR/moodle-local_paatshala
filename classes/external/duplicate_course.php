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
 * local_paatshala_duplicate_course.
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
use external_single_structure;
use external_value;
use local_paatshala\local\executor;
use local_paatshala\local\kind\duplicate_course as handler;

/**
 * local_paatshala_duplicate_course: copy a course without user data, dates moved to a new start.
 *
 * @package    local_paatshala
 * @copyright  2026 local_paatshala contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class duplicate_course extends external_api {

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'source_courseid' => new external_value(PARAM_INT, 'Course to copy (must be in allowedcourses)'),
            'categoryid' => new external_value(PARAM_INT, 'Category for the copy (must be in allowedcategories)'),
            'fullname' => new external_value(PARAM_TEXT, 'Full name'),
            'shortname' => new external_value(PARAM_TEXT, 'Short name (unique)'),
            'startdate' => new external_value(PARAM_INT, 'Start date of the copy; every date moves by the difference'),
        ]);
    }

    /**
     * Duplicate the course; the copy's id is in after.
     *
     * @param int $sourcecourseid
     * @param int $categoryid
     * @param string $fullname
     * @param string $shortname
     * @param int $startdate
     * @return array
     */
    public static function execute($sourcecourseid, $categoryid, $fullname, $shortname, $startdate): array {
        $params = self::validate_parameters(self::execute_parameters(), ['source_courseid' => $sourcecourseid,
            'categoryid' => $categoryid, 'fullname' => $fullname, 'shortname' => $shortname, 'startdate' => $startdate]);
        $handler = (new handler($params['categoryid']))->from_source($params['source_courseid'], $params['fullname'],
            $params['shortname'], $params['startdate']);
        return executor::change($handler, ['exists' => 1], null);
    }

    /**
     * Return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return executor::returns();
    }
}

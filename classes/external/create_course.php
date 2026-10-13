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
 * local_paatshala_create_course.
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
use local_paatshala\local\kind\create_course as handler;

/**
 * local_paatshala_create_course: create a hidden course in an allowed category.
 *
 * @package    local_paatshala
 * @copyright  2026 local_paatshala contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class create_course extends external_api {

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'categoryid' => new external_value(PARAM_INT, 'Category id (must be in allowedcategories)'),
            'fullname' => new external_value(PARAM_TEXT, 'Full name'),
            'shortname' => new external_value(PARAM_TEXT, 'Short name (unique)'),
            'startdate' => new external_value(PARAM_INT, 'Start date, unix timestamp'),
            'enddate' => new external_value(PARAM_INT, 'End date, unix timestamp; 0 for none', VALUE_DEFAULT, 0),
            'format' => new external_value(PARAM_PLUGIN, 'Course format', VALUE_DEFAULT, 'topics'),
        ]);
    }

    /**
     * Create the course; its id is in after.
     *
     * @param int $categoryid
     * @param string $fullname
     * @param string $shortname
     * @param int $startdate
     * @param int $enddate
     * @param string $format
     * @return array
     */
    public static function execute($categoryid, $fullname, $shortname, $startdate, $enddate = 0,
            $format = 'topics'): array {
        $params = self::validate_parameters(self::execute_parameters(), ['categoryid' => $categoryid,
            'fullname' => $fullname, 'shortname' => $shortname, 'startdate' => $startdate, 'enddate' => $enddate,
            'format' => $format]);
        $handler = (new handler($params['categoryid']))->with($params['fullname'], $params['shortname'],
            $params['startdate'], $params['enddate'], $params['format']);
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

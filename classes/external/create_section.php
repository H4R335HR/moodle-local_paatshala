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
 * local_paatshala_create_section.
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
use local_paatshala\local\kind\create_section as handler;

/**
 * local_paatshala_create_section: course_create_section(), as course/changenumsections.php.
 *
 * @package    local_paatshala
 * @copyright  2026 local_paatshala contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class create_section extends external_api {

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course id'),
            'position' => new external_value(PARAM_INT,
                'Section number the new section gets (later sections move down); 0 to add it at the end',
                VALUE_DEFAULT, 0),
            'name' => new external_value(PARAM_TEXT, 'Section name; empty for the format\'s default name',
                VALUE_DEFAULT, ''),
        ]);
    }

    /**
     * Create the section.
     *
     * @param int $courseid
     * @param int $position
     * @param string $name
     * @return array
     */
    public static function execute($courseid, $position = 0, $name = ''): array {
        $params = self::validate_parameters(self::execute_parameters(),
            ['courseid' => $courseid, 'position' => $position, 'name' => $name]);
        $handler = new handler($params['courseid']);
        return executor::change($handler, ['exists' => 1, 'sectionnum' => $params['position'],
            'name' => trim($params['name'])], null);
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

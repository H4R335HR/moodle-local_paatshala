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
 * local_paatshala_move_section.
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
use local_paatshala\local\kind\move_section as handler;

/**
 * local_paatshala_move_section: move_section_to(), as the course page's drag and drop.
 *
 * @package    local_paatshala
 * @copyright  2026 local_paatshala contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class move_section extends external_api {

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course id'),
            'sectionnum' => new external_value(PARAM_INT, 'Number of the section to move'),
            'targetnum' => new external_value(PARAM_INT, 'Number it should have after the move'),
            'expected' => executor::expected_param(),
        ]);
    }

    /**
     * Move the section.
     *
     * @param int $courseid
     * @param int $sectionnum
     * @param int $targetnum
     * @param string|null $expected
     * @return array
     */
    public static function execute($courseid, $sectionnum, $targetnum, $expected = null): array {
        $params = self::validate_parameters(self::execute_parameters(), ['courseid' => $courseid,
            'sectionnum' => $sectionnum, 'targetnum' => $targetnum, 'expected' => $expected]);
        $handler = handler::at($params['courseid'], $params['sectionnum']);
        return executor::change($handler, ['sectionnum' => $params['targetnum']], $params['expected']);
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

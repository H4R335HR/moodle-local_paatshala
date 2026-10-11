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
 * local_paatshala_move_module.
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
use local_paatshala\local\kind\move_module as handler;

/**
 * local_paatshala_move_module: moveto_module(), as the course page's drag and drop.
 *
 * @package    local_paatshala
 * @copyright  2026 local_paatshala contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class move_module extends external_api {

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module id of the activity to move'),
            'sectionnum' => new external_value(PARAM_INT, 'Section number to move it to'),
            'beforecmid' => new external_value(PARAM_INT,
                'Course module id to place it before, in that section; 0 for the end', VALUE_DEFAULT, 0),
            'expected' => executor::expected_param(),
        ]);
    }

    /**
     * Move the activity.
     *
     * @param int $cmid
     * @param int $sectionnum
     * @param int $beforecmid
     * @param string|null $expected
     * @return array
     */
    public static function execute($cmid, $sectionnum, $beforecmid = 0, $expected = null): array {
        $params = self::validate_parameters(self::execute_parameters(), ['cmid' => $cmid,
            'sectionnum' => $sectionnum, 'beforecmid' => $beforecmid, 'expected' => $expected]);
        $handler = new handler($params['cmid']);
        return executor::change($handler, ['sectionnum' => $params['sectionnum'],
            'beforecmid' => $params['beforecmid']], $params['expected']);
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

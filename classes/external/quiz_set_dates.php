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
 * local_paatshala_quiz_set_dates.
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
use local_paatshala\local\kind\quiz_dates as handler;

/**
 * local_paatshala_quiz_set_dates: quiz open and close through the settings form path.
 *
 * @package    local_paatshala
 * @copyright  2026 local_paatshala contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class quiz_set_dates extends external_api {

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        $date = function(string $desc) {
            return new external_value(PARAM_INT, $desc . ': unix timestamp, 0 to disable; omit to keep', VALUE_DEFAULT, null);
        };
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module id of the quiz'),
            'timeopen' => $date('Open the quiz'),
            'timeclose' => $date('Close the quiz'),
            'expected' => executor::expected_param(),
        ]);
    }

    /**
     * Change the dates.
     *
     * @param int $cmid
     * @param int|null $timeopen
     * @param int|null $timeclose
     * @param string|null $expected
     * @return array
     */
    public static function execute($cmid, $timeopen = null, $timeclose = null, $expected = null): array {
        $params = self::validate_parameters(self::execute_parameters(),
            ['cmid' => $cmid, 'timeopen' => $timeopen, 'timeclose' => $timeclose, 'expected' => $expected]);
        $handler = new handler($params['cmid']);
        $values = [];
        foreach (handler::FIELDS as $field) {
            if ($params[$field] !== null) {
                $values[$field] = (int)$params[$field];
            }
        }
        return executor::change($handler, $values, $params['expected']);
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

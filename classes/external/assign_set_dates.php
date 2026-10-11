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
 * local_paatshala_assign_set_dates.
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
use local_paatshala\local\executor;
use local_paatshala\local\kind\assign_dates;

/**
 * local_paatshala_assign_set_dates: change assignment dates through the settings form path.
 *
 * @package    local_paatshala
 * @copyright  2026 local_paatshala contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class assign_set_dates extends external_api {

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
            'cmid' => new external_value(PARAM_INT, 'Course module id of the assignment'),
            'allowsubmissionsfromdate' => $date('Allow submissions from'),
            'duedate' => $date('Due date'),
            'cutoffdate' => $date('Cut-off date'),
            'gradingduedate' => $date('Remind me to grade by'),
            'expected' => executor::expected_param(),
        ]);
    }

    /**
     * Change the dates.
     *
     * @param int $cmid
     * @param int|null $allowsubmissionsfromdate
     * @param int|null $duedate
     * @param int|null $cutoffdate
     * @param int|null $gradingduedate
     * @param string|null $expected
     * @return array
     */
    public static function execute($cmid, $allowsubmissionsfromdate = null, $duedate = null, $cutoffdate = null,
            $gradingduedate = null, $expected = null): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'cmid' => $cmid,
            'allowsubmissionsfromdate' => $allowsubmissionsfromdate,
            'duedate' => $duedate,
            'cutoffdate' => $cutoffdate,
            'gradingduedate' => $gradingduedate,
            'expected' => $expected,
        ]);
        $handler = new assign_dates($params['cmid']);
        $values = [];
        foreach (assign_dates::FIELDS as $field) {
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

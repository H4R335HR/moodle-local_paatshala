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
 * local_paatshala_assign_save_override.
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
use local_paatshala\local\kind\assign_override;

/**
 * local_paatshala_assign_save_override: add or change a user or group override.
 *
 * @package    local_paatshala
 * @copyright  2026 local_paatshala contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class assign_save_override extends external_api {

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        $date = function(string $desc) {
            return new external_value(PARAM_INT, $desc . ': unix timestamp, 0 to disable; omit to keep. ' .
                'A value equal to the assignment\'s is stored as "not overridden", as on the override page.',
                VALUE_DEFAULT, null);
        };
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module id of the assignment'),
            'userid' => new external_value(PARAM_INT, 'User to override (or give groupid)', VALUE_DEFAULT, 0),
            'groupid' => new external_value(PARAM_INT, 'Group to override (or give userid)', VALUE_DEFAULT, 0),
            'allowsubmissionsfromdate' => $date('Allow submissions from'),
            'duedate' => $date('Due date'),
            'cutoffdate' => $date('Cut-off date'),
            'sortorder' => new external_value(PARAM_INT, 'Group overrides only: priority, 1 highest; omit to keep ' .
                '(a new group override goes last)', VALUE_DEFAULT, null),
            'expected' => executor::expected_param(),
        ]);
    }

    /**
     * Save the override.
     *
     * @param int $cmid
     * @param int $userid
     * @param int $groupid
     * @param int|null $allowsubmissionsfromdate
     * @param int|null $duedate
     * @param int|null $cutoffdate
     * @param int|null $sortorder
     * @param string|null $expected
     * @return array
     */
    public static function execute($cmid, $userid = 0, $groupid = 0, $allowsubmissionsfromdate = null, $duedate = null,
            $cutoffdate = null, $sortorder = null, $expected = null): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'cmid' => $cmid, 'userid' => $userid, 'groupid' => $groupid,
            'allowsubmissionsfromdate' => $allowsubmissionsfromdate, 'duedate' => $duedate, 'cutoffdate' => $cutoffdate,
            'sortorder' => $sortorder, 'expected' => $expected,
        ]);
        $handler = new assign_override($params['cmid'], $params['userid'], $params['groupid']);
        $values = ['exists' => 1];
        foreach (array_merge(assign_override::DATES, ['sortorder']) as $field) {
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

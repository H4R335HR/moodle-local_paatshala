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
 * local_paatshala_undo.
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

/**
 * local_paatshala_undo: apply the inverse of a journal entry.
 *
 * @package    local_paatshala
 * @copyright  2026 local_paatshala contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class undo extends external_api {

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'journalid' => new external_value(PARAM_INT, 'Journal entry to undo (an undo entry: redo)'),
            'expected' => executor::expected_param(),
            'resetcompletion' => new external_value(PARAM_BOOL, 'module_completion: allow the undo to reset ' .
                'students\' completion data of the activity (refused with errorcode completiondata otherwise)',
                VALUE_DEFAULT, false),
            'force' => new external_value(PARAM_BOOL, 'module_completion: allow the undo to turn completion off on ' .
                'an activity that access restrictions point at (refused with errorcode referenced otherwise)',
                VALUE_DEFAULT, false),
        ]);
    }

    /**
     * Undo.
     *
     * @param int $journalid
     * @param string|null $expected
     * @param bool $resetcompletion
     * @param bool $force
     * @return array
     */
    public static function execute($journalid, $expected = null, $resetcompletion = false, $force = false): array {
        $params = self::validate_parameters(self::execute_parameters(),
            ['journalid' => $journalid, 'expected' => $expected, 'resetcompletion' => $resetcompletion,
                'force' => $force]);
        return executor::undo($params['journalid'], $params['expected'],
            ['resetcompletion' => $params['resetcompletion'], 'force' => $params['force']]);
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

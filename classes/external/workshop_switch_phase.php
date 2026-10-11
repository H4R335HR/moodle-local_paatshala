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
 * local_paatshala_workshop_switch_phase.
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
use local_paatshala\local\kind\workshop_phase;

/**
 * local_paatshala_workshop_switch_phase: workshop::switch_phase(), as switchphase.php.
 *
 * @package    local_paatshala
 * @copyright  2026 local_paatshala contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class workshop_switch_phase extends external_api {

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module id of the workshop'),
            'phase' => new external_value(PARAM_INT,
                'New phase: 10 setup, 20 submission, 30 assessment, 40 grading evaluation, 50 closed'),
            'expected' => executor::expected_param(),
        ]);
    }

    /**
     * Switch the phase.
     *
     * @param int $cmid
     * @param int $phase
     * @param string|null $expected
     * @return array
     */
    public static function execute($cmid, $phase, $expected = null): array {
        $params = self::validate_parameters(self::execute_parameters(),
            ['cmid' => $cmid, 'phase' => $phase, 'expected' => $expected]);
        $handler = new workshop_phase($params['cmid']);
        return executor::change($handler, ['phase' => $params['phase']], $params['expected']);
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

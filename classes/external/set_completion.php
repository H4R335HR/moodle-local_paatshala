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
 * Change an activity's completion settings.
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
use local_paatshala\local\kind\module_completion;

/**
 * local_paatshala_set_completion: change an activity's completion settings.
 *
 * Through the settings form path. One activity per call. Journaled (module_completion),
 * undoable.
 *
 * @package    local_paatshala
 * @copyright  2026 local_paatshala contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class set_completion extends external_api {

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module id'),
            'fields' => new external_value(PARAM_RAW, 'JSON object: completion (none, manual, automatic or 0, 1, 2), ' .
                'completionview, completionusegrade or completiongradeitemnumber, completionexpected, and the ' .
                'module\'s own rules (local_paatshala_get_completion supports.rules); fields not given keep their values'),
            'resetcompletion' => new external_value(PARAM_BOOL, 'Allow resetting students\' completion data of the ' .
                'activity, which any change but the expected date does (refused with errorcode completiondata ' .
                'otherwise); undo does not bring it back', VALUE_DEFAULT, false),
            'force' => new external_value(PARAM_BOOL, 'Allow turning completion off on an activity that access ' .
                'restrictions point at (refused with errorcode referenced otherwise)', VALUE_DEFAULT, false),
            'expected' => new external_value(PARAM_RAW, 'JSON object with some of the settings and data counts, as ' .
                'local_paatshala_get_completion\'s state: refuse with errorcode conflict unless they match',
                VALUE_DEFAULT, null),
        ]);
    }

    /**
     * Change them.
     *
     * @param int $cmid
     * @param string $fields
     * @param bool $resetcompletion
     * @param bool $force
     * @param string|null $expected
     * @return array
     */
    public static function execute($cmid, $fields, $resetcompletion = false, $force = false, $expected = null): array {
        $params = self::validate_parameters(self::execute_parameters(), ['cmid' => $cmid, 'fields' => $fields,
            'resetcompletion' => $resetcompletion, 'force' => $force, 'expected' => $expected]);
        $given = json_decode($params['fields'], true);
        if (!is_array($given) || ($given && array_keys($given) === range(0, count($given) - 1))) {
            throw new \invalid_parameter_exception('fields must be a JSON object');
        }
        $handler = new module_completion($params['cmid']);
        $handler->set_options(['resetcompletion' => $params['resetcompletion'], 'force' => $params['force']]);
        return executor::change($handler, $handler->request($given), $params['expected']);
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

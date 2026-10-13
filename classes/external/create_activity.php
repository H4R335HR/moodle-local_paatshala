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
 * local_paatshala_create_activity.
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
use local_paatshala\local\kind\create_activity as handler;

/**
 * local_paatshala_create_activity: create an assignment, page, URL or label.
 *
 * @package    local_paatshala
 * @copyright  2026 local_paatshala contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class create_activity extends external_api {

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course id'),
            'sectionnum' => new external_value(PARAM_INT, 'Section number'),
            'modname' => new external_value(PARAM_PLUGIN, 'assign, page, url or label'),
            'fields' => new external_value(PARAM_RAW, 'JSON object of settings from the whitelist for the type ' .
                '(name, intro, visible; see the README for each type). Hidden unless visible is 1.', VALUE_DEFAULT, '{}'),
        ]);
    }

    /**
     * Create the activity; its cmid is in after.
     *
     * @param int $courseid
     * @param int $sectionnum
     * @param string $modname
     * @param string $fields
     * @return array
     */
    public static function execute($courseid, $sectionnum, $modname, $fields = '{}'): array {
        $params = self::validate_parameters(self::execute_parameters(), ['courseid' => $courseid,
            'sectionnum' => $sectionnum, 'modname' => $modname, 'fields' => $fields]);
        $decoded = json_decode($params['fields'] === '' ? '{}' : $params['fields'], true);
        if (!is_array($decoded)) {
            throw new \invalid_parameter_exception('fields must be a JSON object');
        }
        $handler = (new handler($params['courseid']))->with($params['modname'], $params['sectionnum'], $decoded);
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

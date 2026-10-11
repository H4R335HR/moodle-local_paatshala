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
 * local_paatshala_delete_created.
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
use local_paatshala\gate;
use local_paatshala\local\executor;

/**
 * local_paatshala_delete_created: delete an activity or course the plugin created.
 *
 * @package    local_paatshala
 * @copyright  2026 local_paatshala contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class delete_created extends external_api {

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Activity to delete (or give courseid)', VALUE_DEFAULT, 0),
            'courseid' => new external_value(PARAM_INT, 'Course to delete (or give cmid)', VALUE_DEFAULT, 0),
            'expected' => executor::expected_param(),
        ]);
    }

    /**
     * Undo the journal entry that created the object.
     *
     * @param int $cmid
     * @param int $courseid
     * @param string|null $expected
     * @return array
     */
    public static function execute($cmid = 0, $courseid = 0, $expected = null): array {
        global $DB;
        $params = self::validate_parameters(self::execute_parameters(),
            ['cmid' => $cmid, 'courseid' => $courseid, 'expected' => $expected]);
        gate::require_enabled();
        if (($params['cmid'] > 0) === ($params['courseid'] > 0)) {
            throw new \invalid_parameter_exception('Give exactly one of cmid and courseid');
        }
        if ($params['cmid']) {
            $kinds = executor::MODULE_CREATIONS;
            $where = 'cmid = :id';
            $id = $params['cmid'];
        } else {
            $kinds = executor::COURSE_CREATIONS;
            $where = 'courseid = :id AND cmid IS NULL';
            $id = $params['courseid'];
        }
        list($insql, $inparams) = $DB->get_in_or_equal($kinds, SQL_PARAMS_NAMED);
        $entries = $DB->get_records_select('local_paatshala_journal',
            "$where AND createdbyplugin = 1 AND undoneby IS NULL AND kind $insql",
            ['id' => $id] + $inparams, 'id DESC', 'id', 0, 1);
        if (!$entries) {
            throw new \moodle_exception('notcreatedbyplugin', 'local_paatshala');
        }
        return executor::undo((int)reset($entries)->id, $params['expected']);
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

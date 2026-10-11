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
 * local_paatshala_copy_activity.
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
use local_paatshala\local\kind\copy_activity as handler;

/**
 * local_paatshala_copy_activity: copy one activity, without user data, into a course.
 *
 * @package    local_paatshala
 * @copyright  2026 local_paatshala contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class copy_activity extends external_api {

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'source_cmid' => new external_value(PARAM_INT, 'Course module id of the activity to copy'),
            'target_courseid' => new external_value(PARAM_INT, 'Course to copy it into (may be the same course)'),
            'sectionnum' => new external_value(PARAM_INT, 'Section number in the target course'),
            'beforecmid' => new external_value(PARAM_INT, 'Activity to place the copy before; 0 for the end',
                VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * Copy the activity. The copy is hidden; its cmid is in after.
     *
     * @param int $sourcecmid
     * @param int $targetcourseid
     * @param int $sectionnum
     * @param int $beforecmid
     * @return array
     */
    public static function execute($sourcecmid, $targetcourseid, $sectionnum, $beforecmid = 0): array {
        $params = self::validate_parameters(self::execute_parameters(), ['source_cmid' => $sourcecmid,
            'target_courseid' => $targetcourseid, 'sectionnum' => $sectionnum, 'beforecmid' => $beforecmid]);
        $handler = (new handler($params['target_courseid']))->from_source($params['source_cmid'],
            $params['sectionnum'], $params['beforecmid']);
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

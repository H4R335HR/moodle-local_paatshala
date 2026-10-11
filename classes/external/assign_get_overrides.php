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
 * local_paatshala_assign_get_overrides.
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
use local_paatshala\gate;

/**
 * local_paatshala_assign_get_overrides: the user and group overrides of an assignment.
 *
 * @package    local_paatshala
 * @copyright  2026 local_paatshala contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class assign_get_overrides extends external_api {

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module id of the assignment'),
        ]);
    }

    /**
     * List the overrides the caller may see, as mod/assign/overrides.php.
     *
     * @param int $cmid
     * @return array
     */
    public static function execute($cmid): array {
        global $DB;
        $params = self::validate_parameters(self::execute_parameters(), ['cmid' => $cmid]);
        list($cm, $course, $context) = gate::module($params['cmid']);
        gate::require_modname($cm, 'assign');
        // As mod/assign/overrides.php.
        require_capability('mod/assign:manageoverrides', $context);

        $records = $DB->get_records('assign_overrides', ['assignid' => $cm->instance], 'sortorder ASC, id ASC');
        $overrides = [];
        foreach ($records as $record) {
            $visible = $record->groupid ? groups_group_visible($record->groupid, $course, $cm)
                : groups_user_groups_visible($course, $record->userid, $cm);
            if (!$visible) {
                continue;
            }
            $override = ['id' => (int)$record->id];
            if ($record->groupid) {
                $override['groupid'] = (int)$record->groupid;
                $override['groupname'] = (string)groups_get_group_name($record->groupid);
                $override['sortorder'] = (int)$record->sortorder;
            } else {
                $override['userid'] = (int)$record->userid;
            }
            foreach (['allowsubmissionsfromdate', 'duedate', 'cutoffdate'] as $field) {
                $override[$field] = $record->$field === null ? null : (int)$record->$field;
            }
            $overrides[] = $override;
        }
        $assign = $DB->get_record('assign', ['id' => $cm->instance], 'allowsubmissionsfromdate, duedate, cutoffdate');
        return [
            'assignment' => [
                'allowsubmissionsfromdate' => (int)$assign->allowsubmissionsfromdate,
                'duedate' => (int)$assign->duedate,
                'cutoffdate' => (int)$assign->cutoffdate,
            ],
            'overrides' => $overrides,
        ];
    }

    /**
     * Return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        $date = function(string $desc) {
            return new external_value(PARAM_INT, $desc . ': unix timestamp, 0 disabled, null not overridden',
                VALUE_REQUIRED, null, NULL_ALLOWED);
        };
        return new external_single_structure([
            'assignment' => new external_single_structure([
                'allowsubmissionsfromdate' => new external_value(PARAM_INT, 'Assignment allow submissions from'),
                'duedate' => new external_value(PARAM_INT, 'Assignment due date'),
                'cutoffdate' => new external_value(PARAM_INT, 'Assignment cut-off date'),
            ]),
            'overrides' => new external_multiple_structure(new external_single_structure([
                'id' => new external_value(PARAM_INT, 'Override id'),
                'userid' => new external_value(PARAM_INT, 'User, for a user override', VALUE_OPTIONAL),
                'groupid' => new external_value(PARAM_INT, 'Group, for a group override', VALUE_OPTIONAL),
                'groupname' => new external_value(PARAM_TEXT, 'Group name', VALUE_OPTIONAL),
                'sortorder' => new external_value(PARAM_INT, 'Group override priority, 1 highest', VALUE_OPTIONAL),
                'allowsubmissionsfromdate' => $date('Allow submissions from'),
                'duedate' => $date('Due date'),
                'cutoffdate' => $date('Cut-off date'),
            ])),
        ]);
    }
}

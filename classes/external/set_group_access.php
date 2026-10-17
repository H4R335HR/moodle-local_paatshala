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
 * Restrict a section or an activity to groups.
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
use local_paatshala\local\availability_report;
use local_paatshala\local\executor;
use local_paatshala\local\kind\module_availability;
use local_paatshala\local\kind\section_availability;

/**
 * local_paatshala_set_group_access: set or remove a group restriction.
 *
 * Restrict access > Group on a section or an activity, keeping its other restrictions.
 *
 * @package    local_paatshala
 * @copyright  2026 local_paatshala contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class set_group_access extends external_api {

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course'),
            'sectionid' => new external_value(PARAM_INT, 'Section id (course_sections.id), or give cmid', VALUE_DEFAULT, 0),
            'cmid' => new external_value(PARAM_INT, 'Course module id, or give sectionid', VALUE_DEFAULT, 0),
            'groupids' => new external_multiple_structure(new external_value(PARAM_INT, 'Group id'),
                'set: members of any of these groups have access', VALUE_DEFAULT, []),
            'whenunmet' => new external_value(PARAM_ALPHA, 'set: hide (hidden entirely from others) or show ' .
                '(shown greyed out with the restriction message)', VALUE_DEFAULT, 'hide'),
            'action' => new external_value(PARAM_ALPHA, 'set or remove', VALUE_DEFAULT, 'set'),
            'expected' => new external_value(PARAM_RAW, 'JSON object {"availability": "<the stored JSON, or empty>"}: ' .
                'refuse with errorcode conflict unless the stored tree is that one (compared as decoded structures)',
                VALUE_DEFAULT, null),
        ]);
    }

    /**
     * Set or remove it.
     *
     * @param int $courseid
     * @param int $sectionid
     * @param int $cmid
     * @param int[] $groupids
     * @param string $whenunmet
     * @param string $action
     * @param string|null $expected
     * @return array
     */
    public static function execute($courseid, $sectionid = 0, $cmid = 0, $groupids = [], $whenunmet = 'hide',
            $action = 'set', $expected = null): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid, 'sectionid' => $sectionid, 'cmid' => $cmid, 'groupids' => $groupids,
            'whenunmet' => $whenunmet, 'action' => $action, 'expected' => $expected,
        ]);
        if (($params['sectionid'] > 0) === ($params['cmid'] > 0)) {
            throw new \invalid_parameter_exception('Give exactly one of sectionid and cmid');
        }
        $handler = $params['sectionid'] ? new section_availability($params['courseid'], $params['sectionid'])
            : new module_availability($params['courseid'], $params['cmid']);
        availability_report::require_enabled();
        $handler->request($params['action'], $params['groupids'], $params['whenunmet']);
        // The tree to write; checked again against a fresh read inside the transaction.
        $values = ['availability' => $handler->edited($handler->read())];
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

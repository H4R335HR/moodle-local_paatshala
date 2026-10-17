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
 * Restrict a section or an activity to groups and dates.
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
 * local_paatshala_set_access: set or remove group and date restrictions.
 *
 * Restrict access > Group and Date on a section or an activity, keeping its other
 * restrictions. Every part given is changed in one write and one journal entry.
 *
 * @package    local_paatshala
 * @copyright  2026 local_paatshala contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class set_access extends external_api {

    /** @var string[] The parts, as parameters. */
    const PARTS = ['group', 'from', 'until'];

    /**
     * Parameters.
     *
     * A part not given is left as it is. Its fields are optional in the structure so that a
     * missing part validates to [] (a structure default is validated against the structure,
     * lib/externallib.php); execute() requires action for a part that is given.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        $whenunmet = 'set: hide (hidden entirely from those it excludes) or show (shown greyed out with the ' .
            'restriction message); default hide';
        $date = function(string $what) use ($whenunmet) {
            return new external_single_structure([
                'action' => new external_value(PARAM_ALPHA, 'set or remove', VALUE_OPTIONAL),
                'time' => new external_value(PARAM_INT, 'set: unix time ' . $what, VALUE_OPTIONAL),
                'whenunmet' => new external_value(PARAM_ALPHA, $whenunmet, VALUE_OPTIONAL),
            ], 'Restrict access > Date ' . $what . '; omit to leave it', VALUE_DEFAULT, []);
        };
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course'),
            'sectionid' => new external_value(PARAM_INT, 'Section id (course_sections.id), or give cmid', VALUE_DEFAULT, 0),
            'cmid' => new external_value(PARAM_INT, 'Course module id, or give sectionid', VALUE_DEFAULT, 0),
            'group' => new external_single_structure([
                'action' => new external_value(PARAM_ALPHA, 'set or remove', VALUE_OPTIONAL),
                'groupids' => new external_multiple_structure(new external_value(PARAM_INT, 'Group id'),
                    'set: members of any of these groups have access', VALUE_OPTIONAL),
                'whenunmet' => new external_value(PARAM_ALPHA, $whenunmet, VALUE_OPTIONAL),
            ], 'Restrict access > Group; omit to leave it', VALUE_DEFAULT, []),
            'from' => $date('from which there is access (date condition >=)'),
            'until' => $date('until which there is access, not included (date condition <)'),
            'expected' => new external_value(PARAM_RAW, 'JSON object {"availability": "<the stored JSON, or empty>"}: ' .
                'refuse with errorcode conflict unless the stored tree is that one (compared as decoded structures)',
                VALUE_DEFAULT, null),
        ]);
    }

    /**
     * Set or remove them.
     *
     * @param int $courseid
     * @param int $sectionid
     * @param int $cmid
     * @param array $group
     * @param array $from
     * @param array $until
     * @param string|null $expected
     * @return array
     */
    public static function execute($courseid, $sectionid = 0, $cmid = 0, $group = [], $from = [], $until = [],
            $expected = null): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid, 'sectionid' => $sectionid, 'cmid' => $cmid, 'group' => $group,
            'from' => $from, 'until' => $until, 'expected' => $expected,
        ]);
        if (($params['sectionid'] > 0) === ($params['cmid'] > 0)) {
            throw new \invalid_parameter_exception('Give exactly one of sectionid and cmid');
        }
        $parts = [];
        foreach (self::PARTS as $part) {
            if ($params[$part]) {
                if (!isset($params[$part]['action'])) {
                    throw new \invalid_parameter_exception($part . ': action is required');
                }
                $parts[$part] = $params[$part];
            }
        }
        $handler = $params['sectionid'] ? new section_availability($params['courseid'], $params['sectionid'])
            : new module_availability($params['courseid'], $params['cmid']);
        $handler->request($parts);
        availability_report::require_enabled($handler->types());
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

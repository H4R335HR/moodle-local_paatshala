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
 * local_paatshala_get_module_settings.
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

/**
 * local_paatshala_get_module_settings: the settings the plugin's write functions take.
 *
 * Same field names as the write inputs, so a result can be passed as `expected`.
 *
 * @package    local_paatshala
 * @copyright  2026 local_paatshala contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_module_settings extends external_api {

    /** @var array Module name => fields read from the module's table. */
    const MODULE_FIELDS = [
        'assign' => ['allowsubmissionsfromdate', 'duedate', 'cutoffdate', 'gradingduedate', 'grade'],
        'workshop' => ['phase', 'submissionstart', 'submissionend', 'assessmentstart', 'assessmentend',
            'phaseswitchassessment'],
        'quiz' => ['timeopen', 'timeclose'],
    ];

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module id'),
        ]);
    }

    /**
     * Read the settings.
     *
     * @param int $cmid
     * @return array
     */
    public static function execute($cmid): array {
        global $DB;
        $params = self::validate_parameters(self::execute_parameters(), ['cmid' => $cmid]);
        list($cm, $course, $context) = gate::module($params['cmid']);
        // The settings page (course/modedit.php) requires moodle/course:manageactivities.
        require_capability('moodle/course:manageactivities', $context);

        $modinfo = get_fast_modinfo($course);
        $cminfo = $modinfo->get_cm($cm->id);
        $sequence = $modinfo->sections[$cminfo->sectionnum] ?? [];
        $position = array_search($cm->id, $sequence);

        $result = [
            'cmid' => (int)$cm->id,
            'courseid' => (int)$course->id,
            'modname' => $cm->modname,
            'name' => $cminfo->name,
            'visible' => (int)$cm->visible,
            'sectionnum' => (int)$cminfo->sectionnum,
            'position' => $position === false ? -1 : (int)$position,
        ];
        if (isset(self::MODULE_FIELDS[$cm->modname])) {
            $fields = self::MODULE_FIELDS[$cm->modname];
            $record = $DB->get_record($cm->modname, ['id' => $cm->instance], implode(',', $fields), MUST_EXIST);
            foreach ($fields as $field) {
                $result[$field] = (int)$record->$field;
            }
        }
        return $result;
    }

    /**
     * Return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        $optional = function(string $desc) {
            return new external_value(PARAM_INT, $desc, VALUE_OPTIONAL);
        };
        return new external_single_structure([
            'cmid' => new external_value(PARAM_INT, 'Course module id'),
            'courseid' => new external_value(PARAM_INT, 'Course id'),
            'modname' => new external_value(PARAM_PLUGIN, 'Module type'),
            'name' => new external_value(PARAM_TEXT, 'Activity name'),
            'visible' => new external_value(PARAM_INT, '1 shown, 0 hidden'),
            'sectionnum' => new external_value(PARAM_INT, 'Section number'),
            'position' => new external_value(PARAM_INT, '0-based position in the section'),
            'allowsubmissionsfromdate' => $optional('assign: allow submissions from (0 disabled)'),
            'duedate' => $optional('assign: due date (0 disabled)'),
            'cutoffdate' => $optional('assign: cut-off date (0 disabled)'),
            'gradingduedate' => $optional('assign: remind me to grade by (0 disabled)'),
            'grade' => $optional('assign: maximum grade, or -scaleid for a scale, 0 for none'),
            'phase' => $optional('workshop: 10 setup, 20 submission, 30 assessment, 40 evaluation, 50 closed'),
            'submissionstart' => $optional('workshop: submissions open (0 disabled)'),
            'submissionend' => $optional('workshop: submissions deadline (0 disabled)'),
            'assessmentstart' => $optional('workshop: assessment open (0 disabled)'),
            'assessmentend' => $optional('workshop: assessment deadline (0 disabled)'),
            'phaseswitchassessment' => $optional('workshop: switch to assessment after the submissions deadline'),
            'timeopen' => $optional('quiz: open (0 disabled)'),
            'timeclose' => $optional('quiz: close (0 disabled)'),
        ]);
    }
}

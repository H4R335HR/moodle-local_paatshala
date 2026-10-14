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
 * Grade an assignment, with or without notifying the student.
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
use local_paatshala\local\kind\assign_grade;

/**
 * local_paatshala_assign_save_grade: grade with or without notifying.
 *
 * assign::save_grade() with the grading form's "Notify students" switch.
 *
 * @package    local_paatshala
 * @copyright  2026 local_paatshala contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class assign_save_grade extends external_api {

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module id of the assignment'),
            'userid' => new external_value(PARAM_INT, 'Student'),
            'grade' => new external_value(PARAM_FLOAT, 'Grade for the latest attempt; -1 for no grade'),
            'feedback' => new external_single_structure([
                'text' => new external_value(PARAM_RAW, 'Feedback comment, stored as given', VALUE_OPTIONAL),
                'format' => new external_value(PARAM_INT, 'Its format: 0 Moodle, 1 HTML, 2 plain, 4 Markdown',
                    VALUE_DEFAULT, FORMAT_HTML),
            ], 'Feedback comment; without text the current comment is kept', VALUE_DEFAULT, []),
            'workflowstate' => new external_value(PARAM_ALPHA, 'Marking workflow state (marking workflow only): ' .
                'empty for not marked, inmarking, readyforreview, inreview, readyforrelease, released; omit to keep',
                VALUE_DEFAULT, null),
            'applytoall' => new external_value(PARAM_INT, 'Team submissions: 1 to give the whole team this grade',
                VALUE_DEFAULT, 0),
            'notify' => new external_value(PARAM_INT, '1 to let Moodle notify the student (as mod_assign_save_grade ' .
                'does), 0 not to (the grading form\'s "Notify students" off)', VALUE_DEFAULT, 1),
            'cancelpending' => new external_value(PARAM_INT, 'With notify 0: 1 also cancels a notice still waiting ' .
                'for cron from an earlier grade', VALUE_DEFAULT, 0),
            'expected' => executor::expected_param(),
        ]);
    }

    /**
     * Save the grade.
     *
     * @param int $cmid
     * @param int $userid
     * @param float $grade
     * @param array $feedback
     * @param string|null $workflowstate
     * @param int $applytoall
     * @param int $notify
     * @param int $cancelpending
     * @param string|null $expected
     * @return array
     */
    public static function execute($cmid, $userid, $grade, $feedback = [], $workflowstate = null, $applytoall = 0,
            $notify = 1, $cancelpending = 0, $expected = null): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'cmid' => $cmid, 'userid' => $userid, 'grade' => $grade, 'feedback' => $feedback,
            'workflowstate' => $workflowstate, 'applytoall' => $applytoall, 'notify' => $notify,
            'cancelpending' => $cancelpending, 'expected' => $expected,
        ]);
        foreach (['applytoall', 'notify', 'cancelpending'] as $flag) {
            if (!in_array($params[$flag], [0, 1], true)) {
                throw new \invalid_parameter_exception($flag . ' must be 0 or 1');
            }
        }
        $handler = new assign_grade($params['cmid'], $params['userid'], (bool)$params['applytoall'],
            (bool)$params['notify'], (bool)$params['cancelpending']);
        $values = ['grade' => (float)$params['grade']];
        if (isset($params['feedback']['text'])) {
            $values['feedback_text'] = $params['feedback']['text'];
            $values['feedback_format'] = (int)$params['feedback']['format'];
        }
        if ($params['workflowstate'] !== null) {
            $values['workflowstate'] = $params['workflowstate'];
        }
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

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
 * Workshop dates.
 *
 * @package    local_paatshala
 * @copyright  2026 local_paatshala contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_paatshala\local\kind;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/workshop/locallib.php');

/**
 * Workshop dates through the settings form path.
 *
 * Submission and assessment start and end, and the automatic switch to the assessment
 * phase after the submissions deadline.
 *
 * @package    local_paatshala
 * @copyright  2026 local_paatshala contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class workshop_dates extends module_settings {

    /** @var string[] Date fields. */
    const DATES = ['submissionstart', 'submissionend', 'assessmentstart', 'assessmentend'];

    /**
     * Module type.
     *
     * @return string
     */
    protected static function modname(): string {
        return 'workshop';
    }

    /**
     * Kind name.
     *
     * @return string
     */
    public static function kind(): string {
        return 'workshop_dates';
    }

    /**
     * Fields.
     *
     * @return string[]
     */
    public function fields(): array {
        return array_merge(self::DATES, ['phaseswitchassessment']);
    }

    /**
     * Current values.
     *
     * @return array
     */
    public function read(): array {
        global $DB;
        $record = $DB->get_record('workshop', ['id' => $this->cm->instance], implode(',', $this->fields()), MUST_EXIST);
        $state = [];
        foreach ($this->fields() as $field) {
            $state[$field] = (int)$record->$field;
        }
        return $state;
    }

    /**
     * Timestamps and 0/1; the order rules are workshop_mod_form::validation()'s.
     *
     * @param array $values
     * @param array $current
     */
    public function validate(array $values, array $current) {
        self::require_timestamps($values, self::DATES);
        if (isset($values['phaseswitchassessment']) && !in_array((int)$values['phaseswitchassessment'], [0, 1], true)) {
            throw new \invalid_parameter_exception('phaseswitchassessment must be 0 or 1');
        }
    }

    /**
     * Warn when the automatic switch is on with a deadline already passed in the
     * submission phase: the next cron run or workshop page view switches the phase.
     *
     * @param array $values
     * @param array $current
     * @return array
     */
    public function write(array $values, array $current): array {
        global $DB;
        parent::write($values, $current);
        $record = $DB->get_record('workshop', ['id' => $this->cm->instance], 'phase, phaseswitchassessment, submissionend');
        if ($record->phase == \workshop::PHASE_SUBMISSION && $record->phaseswitchassessment &&
                $record->submissionend > 0 && $record->submissionend < time()) {
            return [['autoswitch', get_string('warning_autoswitch', 'local_paatshala')]];
        }
        return [];
    }
}

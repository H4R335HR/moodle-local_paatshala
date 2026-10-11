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
 * Workshop phase.
 *
 * @package    local_paatshala
 * @copyright  2026 local_paatshala contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_paatshala\local\kind;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/workshop/locallib.php');

use local_paatshala\gate;

/**
 * Workshop phase, switched with workshop::switch_phase() as mod/workshop/switchphase.php does.
 *
 * switchphase.php does nothing but require mod/workshop:switchphase, call switch_phase()
 * and redirect. switch_phase() sets the phase and fires \mod_workshop\event\phase_switched;
 * switching to the closed phase first pushes the submission and assessment grades to
 * the gradebook (workshop_update_grades()). Workshop has no message providers, so no
 * switch notifies anyone.
 *
 * @package    local_paatshala
 * @copyright  2026 local_paatshala contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class workshop_phase extends base {

    /** @var int[] Phases workshop::available_phases_list() knows. */
    const PHASES = [
        \workshop::PHASE_SETUP,
        \workshop::PHASE_SUBMISSION,
        \workshop::PHASE_ASSESSMENT,
        \workshop::PHASE_EVALUATION,
        \workshop::PHASE_CLOSED,
    ];

    /**
     * Gate and capability checks for a workshop.
     *
     * @param int $cmid
     */
    public function __construct(int $cmid) {
        list($this->cm, $this->course, $this->context) = gate::module($cmid);
        gate::require_modname($this->cm, 'workshop');
        // As mod/workshop/switchphase.php.
        require_capability('mod/workshop:switchphase', $this->context);
    }

    /**
     * Kind name.
     *
     * @return string
     */
    public static function kind(): string {
        return 'workshop_phase';
    }

    /**
     * Handler for a journal target.
     *
     * @param array $target
     * @return base
     */
    public static function from_target(array $target): base {
        return new self((int)$target['cmid']);
    }

    /**
     * Fields.
     *
     * @return string[]
     */
    public function fields(): array {
        return ['phase'];
    }

    /**
     * Current phase.
     *
     * @return array
     */
    public function read(): array {
        global $DB;
        return ['phase' => (int)$DB->get_field('workshop', 'phase', ['id' => $this->cm->instance], MUST_EXIST)];
    }

    /**
     * Only the five workshop phases.
     *
     * @param array $values
     * @param array $current
     */
    public function validate(array $values, array $current) {
        if (!in_array((int)$values['phase'], self::PHASES, true)) {
            throw new \invalid_parameter_exception('phase must be one of ' . implode(', ', self::PHASES));
        }
    }

    /**
     * Switch the phase.
     *
     * @param array $values
     * @param array $current
     * @return array
     */
    public function write(array $values, array $current): array {
        global $DB;
        $phase = (int)$values['phase'];
        $record = $DB->get_record('workshop', ['id' => $this->cm->instance], '*', MUST_EXIST);
        $workshop = new \workshop($record, $this->cm, $this->course);
        if (!$workshop->switch_phase($phase)) {
            throw new \moodle_exception('errorswitchingphase', 'workshop');
        }

        $warnings = [];
        if ($phase == \workshop::PHASE_CLOSED) {
            $warnings[] = ['gradespushed', get_string('warning_gradespushed', 'local_paatshala')];
        }
        if ($phase == \workshop::PHASE_SUBMISSION && $record->phaseswitchassessment
                && $record->submissionend > 0 && $record->submissionend < time()) {
            $warnings[] = ['autoswitch', get_string('warning_autoswitch', 'local_paatshala')];
        }
        return $warnings;
    }

    /**
     * Target.
     *
     * @return array
     */
    public function target(): array {
        return ['cmid' => (int)$this->cm->id, 'modname' => 'workshop', 'instance' => (int)$this->cm->instance,
            'name' => $this->cm->name];
    }
}

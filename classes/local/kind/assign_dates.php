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
 * Assignment dates.
 *
 * @package    local_paatshala
 * @copyright  2026 local_paatshala contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_paatshala\local\kind;

use local_paatshala\gate;
use local_paatshala\local\modform;

/**
 * Assignment dates: allow submissions from, due, cut-off, remind me to grade by.
 *
 * Written through the assignment settings form path (modform: get_moduleinfo_data(),
 * mod_assign_mod_form validation(), update_moduleinfo()), so the due and grading-due
 * calendar events, completion expected event and caches update as on the settings page.
 *
 * @package    local_paatshala
 * @copyright  2026 local_paatshala contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class assign_dates extends base {

    /** @var string[] The date fields. */
    const FIELDS = ['allowsubmissionsfromdate', 'duedate', 'cutoffdate', 'gradingduedate'];

    /**
     * Gate and capability checks for an assignment.
     *
     * @param int $cmid
     */
    public function __construct(int $cmid) {
        list($this->cm, $this->course, $this->context) = gate::module($cmid);
        gate::require_modname($this->cm, 'assign');
        // Settings page: course/modedit.php -> get_moduleinfo_data() -> can_update_moduleinfo()
        // (course/modlib.php) requires moodle/course:manageactivities in the module context.
        require_capability('moodle/course:manageactivities', $this->context);
    }

    /**
     * Kind name.
     *
     * @return string
     */
    public static function kind(): string {
        return 'assign_dates';
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
        return self::FIELDS;
    }

    /**
     * Current dates.
     *
     * @return array
     */
    public function read(): array {
        global $DB;
        $record = $DB->get_record('assign', ['id' => $this->cm->instance], implode(',', self::FIELDS), MUST_EXIST);
        $state = [];
        foreach (self::FIELDS as $field) {
            $state[$field] = (int)$record->$field;
        }
        return $state;
    }

    /**
     * Dates are unix timestamps, 0 for disabled. The rules between them are the settings
     * form's (mod_assign_mod_form::validation()), run by modform::save().
     *
     * @param array $values
     * @param array $current
     */
    public function validate(array $values, array $current) {
        foreach ($values as $field => $value) {
            if ($value < 0) {
                throw new \invalid_parameter_exception($field . ' must be a unix timestamp, or 0 for disabled');
            }
        }
    }

    /**
     * Save through the settings form path.
     *
     * @param array $values
     * @param array $current
     * @return array
     */
    public function write(array $values, array $current): array {
        modform::save($this->cm, $this->course, $values);
        return [];
    }

    /**
     * Target.
     *
     * @return array
     */
    public function target(): array {
        return ['cmid' => (int)$this->cm->id, 'modname' => 'assign', 'instance' => (int)$this->cm->instance,
            'name' => $this->cm->name];
    }
}

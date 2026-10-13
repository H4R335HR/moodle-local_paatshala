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
 * Activity settings changed through the settings form path.
 *
 * @package    local_paatshala
 * @copyright  2026 local_paatshala contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_paatshala\local\kind;

use local_paatshala\gate;
use local_paatshala\journal;
use local_paatshala\local\modform;
use local_paatshala\local\state;

/**
 * Activity settings changed through the settings form path (modform::save()).
 *
 * A subclass names the module type, the fields it allows, how to read them and how they
 * map to the settings form's fields. Capability as the settings page:
 * course/modedit.php -> can_update_moduleinfo() requires moodle/course:manageactivities.
 *
 * @package    local_paatshala
 * @copyright  2026 local_paatshala contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class module_settings extends base {

    /**
     * Module type.
     *
     * @return string
     */
    abstract protected static function modname(): string;

    /**
     * Gate and capability checks.
     *
     * @param int $cmid
     */
    public function __construct(int $cmid) {
        list($this->cm, $this->course, $this->context) = gate::module($cmid);
        gate::require_modname($this->cm, static::modname());
        require_capability('moodle/course:manageactivities', $this->context);
    }

    /**
     * Handler for a journal target.
     *
     * @param array $target
     * @return base
     */
    public static function from_target(array $target): base {
        return new static((int)$target['cmid']);
    }

    /**
     * The settings form fields for some values (by default the same names).
     *
     * @param array $values
     * @return array
     */
    protected function form_changes(array $values): array {
        return $values;
    }

    /**
     * Save through the settings form path.
     *
     * @param array $values
     * @param array $current
     * @return array
     */
    public function write(array $values, array $current): array {
        modform::save($this->cm, $this->course, $this->form_changes($values));
        return [];
    }

    /**
     * Refuse when a value was not stored as asked: the settings form path can drop a
     * value without an error (an assignment plugin's settings while it is off, say).
     *
     * @param array $values
     * @param array $state
     * @throws \moodle_exception notsaved
     */
    public function check_written(array $values, array $state) {
        foreach ($values as $field => $value) {
            $stored = $state[$field] ?? null;
            $reason = $this->not_saved($field, $values, $state);
            if ($reason === null && !state::same($value, $stored)) {
                $reason = get_string('notsaved_different', 'local_paatshala',
                    (object)['asked' => journal::encode($value), 'stored' => journal::encode($stored)]);
            }
            if ($reason !== null) {
                throw new \moodle_exception('notsaved', 'local_paatshala', '',
                    (object)['field' => $field, 'reason' => $reason]);
            }
        }
    }

    /**
     * Why Moodle did not store a value, if the kind knows; null to compare values only.
     *
     * @param string $field
     * @param array $values
     * @param array $state
     * @return string|null
     */
    protected function not_saved(string $field, array $values, array $state) {
        return null;
    }

    /**
     * Target.
     *
     * @return array
     */
    public function target(): array {
        return ['cmid' => (int)$this->cm->id, 'modname' => static::modname(), 'instance' => (int)$this->cm->instance,
            'name' => $this->cm->name];
    }

    /**
     * Refuse values that are not timestamps.
     *
     * @param array $values
     * @param string[] $fields
     */
    protected static function require_timestamps(array $values, array $fields) {
        foreach ($fields as $field) {
            if (isset($values[$field]) && (!is_numeric($values[$field]) || $values[$field] < 0)) {
                throw new \invalid_parameter_exception($field . ' must be a unix timestamp, or 0 for disabled');
            }
        }
    }
}

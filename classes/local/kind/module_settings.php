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
use local_paatshala\local\modform;

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

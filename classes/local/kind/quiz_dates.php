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
 * Quiz open and close dates.
 *
 * @package    local_paatshala
 * @copyright  2026 local_paatshala contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_paatshala\local\kind;

/**
 * Quiz open and close dates, through the settings form path.
 *
 * Saved like the settings page, so quiz_update_instance() updates the calendar events and
 * recalculates open attempts' time checks (quiz_update_open_attempts()).
 *
 * @package    local_paatshala
 * @copyright  2026 local_paatshala contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class quiz_dates extends module_settings {

    /** @var string[] Fields. */
    const FIELDS = ['timeopen', 'timeclose'];

    /**
     * Module type.
     *
     * @return string
     */
    protected static function modname(): string {
        return 'quiz';
    }

    /**
     * Kind name.
     *
     * @return string
     */
    public static function kind(): string {
        return 'quiz_dates';
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
        $record = $DB->get_record('quiz', ['id' => $this->cm->instance], 'timeopen, timeclose', MUST_EXIST);
        return ['timeopen' => (int)$record->timeopen, 'timeclose' => (int)$record->timeclose];
    }

    /**
     * Timestamps; the order rule is mod_quiz_mod_form::validation()'s.
     *
     * @param array $values
     * @param array $current
     */
    public function validate(array $values, array $current) {
        self::require_timestamps($values, self::FIELDS);
    }
}

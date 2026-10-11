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
 * Base class for a kind of change.
 *
 * @package    local_paatshala
 * @copyright  2026 local_paatshala contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_paatshala\local\kind;

/**
 * A kind of change: how to check access to its target, read its state and write it.
 *
 * An instance is bound to one target. The constructor runs the plugin gate and the
 * capability checks of the UI equivalent, so a handler that exists may read and write.
 * State is an array in the shape of the write function's input fields; undo writes the
 * journal entry's `before` back through write().
 *
 * @package    local_paatshala
 * @copyright  2026 local_paatshala contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class base {

    /** @var \stdClass Course record. */
    protected $course;

    /** @var \stdClass|null Course module record, for module changes. */
    protected $cm = null;

    /** @var \context Context of the target. */
    protected $context;

    /**
     * Journal kind name.
     *
     * @return string
     */
    abstract public static function kind(): string;

    /**
     * Handler for a journal entry's target (used by undo).
     *
     * @param array $target Decoded journal target.
     * @return base
     */
    abstract public static function from_target(array $target): base;

    /**
     * Fields the write function takes (and `expected` may hold).
     *
     * @return string[]
     */
    abstract public function fields(): array;

    /**
     * Current state, every field.
     *
     * @return array
     */
    abstract public function read(): array;

    /**
     * Refuse values the UI would refuse.
     *
     * @param array $values New values (a subset of fields()).
     * @param array $current Current state.
     * @throws \moodle_exception
     */
    abstract public function validate(array $values, array $current);

    /**
     * Write new values through Moodle's own code.
     *
     * @param array $values New values (a subset of fields()).
     * @param array $current State before the write.
     * @return array Warnings: list of [code, message].
     */
    abstract public function write(array $values, array $current): array;

    /**
     * What the journal records as target: ids and names.
     *
     * @return array
     */
    abstract public function target(): array;

    /**
     * Whether this change can be undone.
     *
     * @param array $before
     * @param array $after
     * @return bool
     */
    public function undoable(array $before, array $after): bool {
        return true;
    }

    /**
     * Whether Moodle notified students about this change.
     *
     * @param array $before
     * @param array $after
     * @return bool
     */
    public function notifies(array $before, array $after): bool {
        return false;
    }

    /**
     * Student the change is about, for the privacy API.
     *
     * @return int|null
     */
    public function relateduserid() {
        return null;
    }

    /**
     * Course id.
     *
     * @return int
     */
    public function courseid(): int {
        return (int)$this->course->id;
    }

    /**
     * Course module id, if the change is to an activity.
     *
     * @return int|null
     */
    public function cmid() {
        return $this->cm ? (int)$this->cm->id : null;
    }
}

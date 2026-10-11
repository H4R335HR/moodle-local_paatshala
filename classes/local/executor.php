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
 * Runs changes and undo: compare-and-set, write, journal.
 *
 * @package    local_paatshala
 * @copyright  2026 local_paatshala contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_paatshala\local;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/externallib.php');

use external_multiple_structure;
use external_single_structure;
use external_value;
use local_paatshala\gate;
use local_paatshala\journal;
use local_paatshala\local\kind\base;

/**
 * Runs changes and undo: compare-and-set, write, journal, in one delegated transaction.
 *
 * @package    local_paatshala
 * @copyright  2026 local_paatshala contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class executor {

    /** @var string[] Kind name => handler class. */
    const KINDS = [
        'assign_dates' => kind\assign_dates::class,
        'workshop_phase' => kind\workshop_phase::class,
        'move_module' => kind\move_module::class,
        'move_section' => kind\move_section::class,
        'create_section' => kind\create_section::class,
        'assign_override' => kind\assign_override::class,
        'copy_activity' => kind\copy_activity::class,
        'create_activity' => kind\create_activity::class,
        'recyclebin_restore_activity' => kind\restore_activity::class,
        'create_course' => kind\create_course::class,
        'duplicate_course' => kind\duplicate_course::class,
        'recyclebin_restore_course' => kind\restore_course::class,
    ];

    /** @var string[] Kinds that create an activity (delete_created by cmid). */
    const MODULE_CREATIONS = ['copy_activity', 'create_activity', 'recyclebin_restore_activity'];

    /** @var string[] Kinds that create a course (delete_created by courseid). */
    const COURSE_CREATIONS = ['create_course', 'duplicate_course', 'recyclebin_restore_course'];

    /**
     * Apply a change.
     *
     * @param base $handler Handler bound to the target (gate and capabilities already checked).
     * @param array $values New values, keys from $handler->fields().
     * @param string|null $expected JSON object the caller expects the current state to match.
     * @return array Write result (see returns()).
     */
    public static function change(base $handler, array $values, $expected): array {
        if (!$values) {
            throw new \invalid_parameter_exception('Nothing to change: give at least one of ' .
                implode(', ', $handler->fields()));
        }
        $wanted = state::decode_expected($expected, $handler->expected_fields());
        return self::run($handler, $values, $wanted, null);
    }

    /**
     * Undo a journal entry: write its `before` back, if the target still has its `after`.
     *
     * @param int $journalid
     * @param string|null $expected JSON object the caller expects the current state to match.
     * @return array Write result (see returns()).
     */
    public static function undo(int $journalid, $expected): array {
        gate::require_enabled();
        $entry = journal::get($journalid);
        $handlerclass = self::KINDS[$entry->kind] ?? null;
        if ($handlerclass === null) {
            throw new \moodle_exception('notundoable', 'local_paatshala', '', $journalid);
        }
        /** @var base $handler */
        $handler = $handlerclass::from_target(json_decode($entry->target, true));
        if ($handler->courseid() != $entry->courseid) {
            throw new \moodle_exception('notundoable', 'local_paatshala', '', $journalid);
        }
        if (!$entry->undoable) {
            throw new \moodle_exception('notundoable', 'local_paatshala', '', $journalid);
        }
        if ($entry->undoneby) {
            throw new \moodle_exception('alreadyundone', 'local_paatshala', '',
                (object)['id' => $journalid, 'undoneby' => $entry->undoneby]);
        }
        $wanted = state::decode_expected($expected, $handler->expected_fields());
        $before = json_decode($entry->beforestate, true);
        $after = json_decode($entry->afterstate, true);
        return self::run($handler, $before, $wanted, $entry, $after);
    }

    /**
     * Check, write and journal.
     *
     * @param base $handler
     * @param array $values
     * @param array|null $wanted Caller's expected state.
     * @param \stdClass|null $undoof Journal entry being undone.
     * @param array|null $mustbe The undone entry's after; $handler->check_undo() decides whether the
     *     target still has it.
     * @return array
     */
    protected static function run(base $handler, array $values, $wanted, $undoof, $mustbe = null): array {
        global $DB;

        $unknown = array_diff(array_keys($values), $handler->fields());
        if ($unknown) {
            throw new \invalid_parameter_exception('Unknown fields: ' . implode(', ', $unknown));
        }

        $transaction = $DB->start_delegated_transaction();
        try {
            $current = $handler->read();
            if ($mustbe !== null) {
                $handler->check_undo($mustbe, $values, $current);
            }
            state::require_matches($wanted, $current);
            $handler->validate($values, $current);

            $keys = $handler->journal_keys($values);
            $before = state::subset($current, $keys);
            $warnings = $handler->write($values, $current);
            $after = state::subset($handler->read(), $keys);

            $undoable = $handler->undoable($before, $after);
            $notifies = $handler->notifies($before, $after);
            $journalid = journal::add([
                'courseid' => $handler->courseid(),
                'cmid' => $handler->cmid(),
                'kind' => $handler::kind(),
                'target' => $handler->target(),
                'before' => $before,
                'after' => $after,
                'undoable' => $undoable,
                'createdbyplugin' => $handler->createdbyplugin($before, $after),
                'notifies' => $notifies,
                'undoof' => $undoof ? $undoof->id : null,
                'relateduserid' => $handler->relateduserid(),
            ]);
            if ($undoof) {
                journal::mark_undone((int)$undoof->id, $journalid);
            }
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            if (!$transaction->is_disposed()) {
                $transaction->rollback($e);
            }
            throw $e;
        }

        return self::result($journalid, $before, $after, $undoable, $notifies, $warnings);
    }

    /**
     * Build a write result.
     *
     * @param int $journalid
     * @param array $before
     * @param array $after
     * @param bool $undoable
     * @param bool $notifies
     * @param array $warnings List of [code, message].
     * @return array
     */
    public static function result(int $journalid, array $before, array $after, bool $undoable, bool $notifies,
            array $warnings): array {
        $list = [];
        foreach ($warnings as $warning) {
            $list[] = ['code' => $warning[0], 'message' => $warning[1]];
        }
        return [
            'journalid' => $journalid,
            'before' => journal::encode((object)$before),
            'after' => journal::encode((object)$after),
            'undoable' => $undoable,
            'notifies_students' => $notifies,
            'warnings' => $list,
        ];
    }

    /**
     * Return structure of every write function.
     *
     * @return external_single_structure
     */
    public static function returns(): external_single_structure {
        return new external_single_structure([
            'journalid' => new external_value(PARAM_INT, 'Journal entry of this change'),
            'before' => new external_value(PARAM_RAW, 'JSON: the values replaced, in the shape of the input'),
            'after' => new external_value(PARAM_RAW, 'JSON: the values now stored, read back after the write'),
            'undoable' => new external_value(PARAM_BOOL, 'Whether local_paatshala_undo can undo it'),
            'notifies_students' => new external_value(PARAM_BOOL, 'Whether Moodle notified students'),
            'warnings' => new external_multiple_structure(new external_single_structure([
                'code' => new external_value(PARAM_ALPHANUMEXT, 'Warning code'),
                'message' => new external_value(PARAM_TEXT, 'Warning'),
            ])),
        ]);
    }

    /**
     * The `expected` parameter description.
     *
     * @return external_value
     */
    public static function expected_param(): external_value {
        return new external_value(PARAM_RAW,
            'JSON object with some of the input fields: refuse with errorcode conflict unless the current ' .
            'values match', VALUE_DEFAULT, null);
    }
}

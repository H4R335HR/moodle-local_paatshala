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
 * Journal of changes made through local_paatshala.
 *
 * @package    local_paatshala
 * @copyright  2026 local_paatshala contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_paatshala;

/**
 * Journal of changes: the primary record for undo.
 *
 * Holds ids, dates, settings values, positions and names, and for assign_grade the
 * student's grade and raw feedback comment (so undo can restore them); never submission
 * content or activity descriptions. Entries about students are linked to each of them in
 * USERS_TABLE for the privacy API.
 *
 * @package    local_paatshala
 * @copyright  2026 local_paatshala contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class journal {

    /** @var string Table name. */
    const TABLE = 'local_paatshala_journal';

    /** @var string Students each entry holds data about. */
    const USERS_TABLE = 'local_paatshala_journal_user';

    /**
     * Encode a value as the JSON stored in the journal and returned to callers.
     *
     * @param mixed $value
     * @return string
     */
    public static function encode($value): string {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Add an entry.
     *
     * @param array $data Keys: courseid, cmid, kind, target, before, after, undoable,
     *     createdbyplugin, notifies, undoof, relateduserid, relateduserids (every
     *     student the entry is about). target/before/after are arrays and are stored as
     *     JSON.
     * @return int The new journal id.
     */
    public static function add(array $data): int {
        global $DB, $USER;
        $record = (object)[
            'timecreated' => time(),
            'userid' => (int)$USER->id,
            'courseid' => (int)$data['courseid'],
            'cmid' => isset($data['cmid']) ? (int)$data['cmid'] : null,
            'kind' => $data['kind'],
            'target' => self::encode($data['target'] ?? []),
            'beforestate' => self::encode($data['before'] ?? []),
            'afterstate' => self::encode($data['after'] ?? []),
            'undoable' => empty($data['undoable']) ? 0 : 1,
            'createdbyplugin' => empty($data['createdbyplugin']) ? 0 : 1,
            'undoof' => isset($data['undoof']) ? (int)$data['undoof'] : null,
            'undoneby' => null,
            'notifies' => empty($data['notifies']) ? 0 : 1,
            'relateduserid' => isset($data['relateduserid']) ? (int)$data['relateduserid'] : null,
        ];
        $id = (int)$DB->insert_record(self::TABLE, $record);
        $userids = $data['relateduserids'] ?? [];
        if ($record->relateduserid) {
            $userids[] = $record->relateduserid;
        }
        foreach (array_unique(array_map('intval', $userids)) as $userid) {
            $DB->insert_record(self::USERS_TABLE, (object)['journalid' => $id, 'userid' => $userid]);
        }
        return $id;
    }

    /**
     * Whether the current user may see an entry's data. Grade entries hold students'
     * grades and feedback: they need mod/assign:grade, as the grading table.
     *
     * @param \stdClass $record
     * @return bool
     */
    public static function visible(\stdClass $record): bool {
        if ($record->kind !== 'assign_grade') {
            return true;
        }
        $context = $record->cmid ? \context_module::instance($record->cmid, IGNORE_MISSING) : false;
        if (!$context) {
            $context = \context_course::instance($record->courseid, IGNORE_MISSING);
        }
        return $context && has_capability('mod/assign:grade', $context);
    }

    /**
     * Get an entry.
     *
     * @param int $id
     * @return \stdClass
     * @throws \moodle_exception journalnotfound
     */
    public static function get(int $id): \stdClass {
        global $DB;
        $record = $DB->get_record(self::TABLE, ['id' => $id]);
        if (!$record) {
            throw new \moodle_exception('journalnotfound', 'local_paatshala', '', $id);
        }
        return $record;
    }

    /**
     * Record that an entry was undone by another.
     *
     * @param int $id Entry undone.
     * @param int $undoneby Entry that undid it.
     */
    public static function mark_undone(int $id, int $undoneby) {
        global $DB;
        $DB->set_field(self::TABLE, 'undoneby', $undoneby, ['id' => $id]);
    }

    /**
     * Entries for a course, newest first.
     *
     * @param int $courseid
     * @param int $since Only entries created at or after this time.
     * @param string $kind Only entries of this kind ('' for all).
     * @param int $limit Maximum number of entries (0 for all).
     * @return \stdClass[]
     */
    public static function entries(int $courseid, int $since = 0, string $kind = '', int $limit = 0): array {
        global $DB;
        $where = 'courseid = :courseid AND timecreated >= :since';
        $params = ['courseid' => $courseid, 'since' => $since];
        if ($kind !== '') {
            $where .= ' AND kind = :kind';
            $params['kind'] = $kind;
        }
        return array_values($DB->get_records_select(self::TABLE, $where, $params, 'id DESC', '*', 0, $limit));
    }

    /**
     * Entry as returned by the external functions.
     *
     * @param \stdClass $record
     * @return array
     */
    public static function export(\stdClass $record): array {
        $entry = [
            'id' => (int)$record->id,
            'timecreated' => (int)$record->timecreated,
            'userid' => (int)$record->userid,
            'courseid' => (int)$record->courseid,
            'kind' => $record->kind,
            'target' => (string)$record->target,
            'before' => (string)$record->beforestate,
            'after' => (string)$record->afterstate,
            'undoable' => (bool)$record->undoable,
            'createdbyplugin' => (bool)$record->createdbyplugin,
            'notifies' => (bool)$record->notifies,
        ];
        // Optional fields are left out when null.
        foreach (['cmid', 'undoof', 'undoneby'] as $field) {
            if ($record->$field !== null) {
                $entry[$field] = (int)$record->$field;
            }
        }
        return $entry;
    }
}

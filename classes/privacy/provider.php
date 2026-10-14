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
 * Privacy provider for local_paatshala.
 *
 * @package    local_paatshala
 * @copyright  2026 local_paatshala contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_paatshala\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\transform;
use core_privacy\local\request\writer;

/**
 * Privacy provider for the journal.
 *
 * The journal stores the caller's user id and, for overrides and grades, the
 * student(s) the change is about: a grade entry holds the grade and raw feedback comment
 * of the student, or of every member for a team grade. Each such student is linked to
 * the entry in USERS_TABLE.
 *
 * Deleting a user's data removes journal entries about that student (and with them
 * their undo) and anonymises entries the user made (userid set to 0, undo kept).
 *
 * @package    local_paatshala
 * @copyright  2026 local_paatshala contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
        \core_privacy\local\metadata\provider,
        \core_privacy\local\request\plugin\provider,
        \core_privacy\local\request\core_userlist_provider {

    /** @var string Journal table. */
    const TABLE = 'local_paatshala_journal';

    /** @var string Students an entry is about. */
    const USERS_TABLE = 'local_paatshala_journal_user';

    /**
     * Describe the data stored.
     *
     * @param collection $collection
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table(self::TABLE, [
            'timecreated' => 'privacy:metadata:journal:timecreated',
            'userid' => 'privacy:metadata:journal:userid',
            'courseid' => 'privacy:metadata:journal:courseid',
            'kind' => 'privacy:metadata:journal:kind',
            'target' => 'privacy:metadata:journal:target',
            'beforestate' => 'privacy:metadata:journal:beforestate',
            'afterstate' => 'privacy:metadata:journal:afterstate',
            'relateduserid' => 'privacy:metadata:journal:relateduserid',
        ], 'privacy:metadata:journal');
        $collection->add_database_table(self::USERS_TABLE, [
            'journalid' => 'privacy:metadata:journaluser:journalid',
            'userid' => 'privacy:metadata:journaluser:userid',
        ], 'privacy:metadata:journaluser');
        return $collection;
    }

    /**
     * Course contexts with journal entries made by or about the user.
     *
     * @param int $userid
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $sql = "SELECT ctx.id
                  FROM {context} ctx
                  JOIN {" . self::TABLE . "} j ON j.courseid = ctx.instanceid AND ctx.contextlevel = :contextlevel
                 WHERE j.userid = :userid
                    OR EXISTS (SELECT 1 FROM {" . self::USERS_TABLE . "} ju WHERE ju.journalid = j.id AND ju.userid = :about)";
        $contextlist = new contextlist();
        $contextlist->add_from_sql($sql, [
            'contextlevel' => CONTEXT_COURSE,
            'userid' => $userid,
            'about' => $userid,
        ]);
        return $contextlist;
    }

    /**
     * Users with journal entries in a course context.
     *
     * @param userlist $userlist
     */
    public static function get_users_in_context(userlist $userlist) {
        $context = $userlist->get_context();
        if (!$context instanceof \context_course) {
            return;
        }
        $params = ['courseid' => $context->instanceid];
        $userlist->add_from_sql('userid',
            "SELECT userid FROM {" . self::TABLE . "} WHERE courseid = :courseid AND userid > 0", $params);
        $userlist->add_from_sql('userid',
            "SELECT ju.userid FROM {" . self::USERS_TABLE . "} ju
               JOIN {" . self::TABLE . "} j ON j.id = ju.journalid
              WHERE j.courseid = :courseid", $params);
    }

    /**
     * Export journal entries made by or about the user.
     *
     * @param approved_contextlist $contextlist
     */
    public static function export_user_data(approved_contextlist $contextlist) {
        global $DB;
        $userid = (int)$contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof \context_course) {
                continue;
            }
            $about = self::about($userid, (int)$context->instanceid);
            $records = $DB->get_records_select(self::TABLE, 'courseid = :courseid AND userid = :userid',
                ['courseid' => $context->instanceid, 'userid' => $userid], 'id ASC');
            if ($about) {
                list($insql, $inparams) = $DB->get_in_or_equal($about, SQL_PARAMS_NAMED);
                $records += $DB->get_records_select(self::TABLE, "id $insql", $inparams, 'id ASC');
                ksort($records);
            }
            $entries = [];
            foreach ($records as $record) {
                $entries[] = [
                    'id' => $record->id,
                    'timecreated' => transform::datetime($record->timecreated),
                    'madebyyou' => transform::yesno($record->userid == $userid),
                    'aboutyou' => transform::yesno(in_array((int)$record->id, $about, true)),
                    'kind' => $record->kind,
                    'cmid' => $record->cmid,
                    'target' => json_decode($record->target),
                    'before' => json_decode($record->beforestate),
                    'after' => json_decode($record->afterstate),
                ];
            }
            if ($entries) {
                writer::with_context($context)->export_data(
                    [get_string('pluginname', 'local_paatshala')], (object)['journal' => $entries]);
            }
        }
    }

    /**
     * Delete data for all users in a course context.
     *
     * @param \context $context
     */
    public static function delete_data_for_all_users_in_context(\context $context) {
        global $DB;
        if (!$context instanceof \context_course) {
            return;
        }
        $about = $DB->get_fieldset_sql("SELECT DISTINCT ju.journalid FROM {" . self::USERS_TABLE . "} ju
                                          JOIN {" . self::TABLE . "} j ON j.id = ju.journalid
                                         WHERE j.courseid = :courseid", ['courseid' => $context->instanceid]);
        self::delete_entries(array_map('intval', $about));
        $DB->set_field(self::TABLE, 'userid', 0, ['courseid' => $context->instanceid]);
    }

    /**
     * Delete one user's data in the approved contexts.
     *
     * @param approved_contextlist $contextlist
     */
    public static function delete_data_for_user(approved_contextlist $contextlist) {
        $userid = (int)$contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if ($context instanceof \context_course) {
                self::delete_users_in_course((int)$context->instanceid, [$userid]);
            }
        }
    }

    /**
     * Delete several users' data in one context.
     *
     * @param approved_userlist $userlist
     */
    public static function delete_data_for_users(approved_userlist $userlist) {
        $context = $userlist->get_context();
        if ($context instanceof \context_course) {
            self::delete_users_in_course((int)$context->instanceid, $userlist->get_userids());
        }
    }

    /**
     * Remove entries about these users and anonymise entries they made.
     *
     * @param int $courseid
     * @param int[] $userids
     */
    protected static function delete_users_in_course(int $courseid, array $userids) {
        global $DB;
        if (!$userids) {
            return;
        }
        $about = [];
        foreach ($userids as $userid) {
            $about = array_merge($about, self::about((int)$userid, $courseid));
        }
        self::delete_entries(array_values(array_unique($about)));
        list($insql, $inparams) = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        $params = ['courseid' => $courseid] + $inparams;
        $DB->set_field_select(self::TABLE, 'userid', 0, "courseid = :courseid AND userid $insql", $params);
    }

    /**
     * Ids of a course's entries about a student.
     *
     * @param int $userid
     * @param int $courseid
     * @return int[]
     */
    protected static function about(int $userid, int $courseid): array {
        global $DB;
        $ids = $DB->get_fieldset_sql("SELECT ju.journalid FROM {" . self::USERS_TABLE . "} ju
                                        JOIN {" . self::TABLE . "} j ON j.id = ju.journalid
                                       WHERE ju.userid = :userid AND j.courseid = :courseid",
            ['userid' => $userid, 'courseid' => $courseid]);
        return array_map('intval', $ids);
    }

    /**
     * Delete entries (with their undo) and their student links. A team grade entry goes
     * as a whole: it holds every member's grade.
     *
     * @param int[] $ids
     */
    protected static function delete_entries(array $ids) {
        global $DB;
        if (!$ids) {
            return;
        }
        list($insql, $inparams) = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED);
        $DB->delete_records_select(self::USERS_TABLE, "journalid $insql", $inparams);
        $DB->delete_records_select(self::TABLE, "id $insql", $inparams);
    }
}

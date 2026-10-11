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
 * The journal stores the caller's user id and, for overrides, the student the change
 * is about.
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
                 WHERE j.userid = :userid OR j.relateduserid = :relateduserid";
        $contextlist = new contextlist();
        $contextlist->add_from_sql($sql, [
            'contextlevel' => CONTEXT_COURSE,
            'userid' => $userid,
            'relateduserid' => $userid,
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
        $userlist->add_from_sql('relateduserid',
            "SELECT relateduserid FROM {" . self::TABLE . "} WHERE courseid = :courseid AND relateduserid IS NOT NULL",
            $params);
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
            $records = $DB->get_records_select(self::TABLE,
                'courseid = :courseid AND (userid = :userid OR relateduserid = :relateduserid)',
                ['courseid' => $context->instanceid, 'userid' => $userid, 'relateduserid' => $userid], 'id ASC');
            $entries = [];
            foreach ($records as $record) {
                $entries[] = [
                    'id' => $record->id,
                    'timecreated' => transform::datetime($record->timecreated),
                    'madebyyou' => transform::yesno($record->userid == $userid),
                    'aboutyou' => transform::yesno($record->relateduserid == $userid),
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
        $DB->delete_records_select(self::TABLE, 'courseid = :courseid AND relateduserid IS NOT NULL',
            ['courseid' => $context->instanceid]);
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
        list($insql, $inparams) = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        $params = ['courseid' => $courseid] + $inparams;
        $DB->delete_records_select(self::TABLE, "courseid = :courseid AND relateduserid $insql", $params);
        $DB->set_field_select(self::TABLE, 'userid', 0, "courseid = :courseid AND userid $insql", $params);
    }
}

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
 * Assignment user and group overrides.
 *
 * @package    local_paatshala
 * @copyright  2026 local_paatshala contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_paatshala\local\kind;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/assign/lib.php');
require_once($CFG->dirroot . '/mod/assign/locallib.php');
require_once($CFG->dirroot . '/mod/assign/override_form.php');

use local_paatshala\gate;
use local_paatshala\local\modform;

/**
 * An assignment override for one user or one group.
 *
 * State: exists or not, its dates and, for groups, its priority (sortorder, 1 = highest).
 *
 * Moodle 3.8 has no API to save an override: mod/assign/overrideedit.php writes the
 * assign_overrides row inline. write() mirrors that page line for line (dates equal to
 * the assignment's stored as null, insert or update, group sortorder and
 * reorder_group_overrides(), the user/group_override_created/updated events,
 * assign_update_events()). Deleting uses assign::delete_override() and
 * reorder_group_overrides(), as mod/assign/overridedelete.php does. Group priority moves
 * use move_group_override(), the up/down action of mod/assign/overrides.php.
 *
 * State dates are unix timestamps, 0 for disabled, null for "not overridden" (the
 * assignment's own date applies).
 *
 * @package    local_paatshala
 * @copyright  2026 local_paatshala contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class assign_override extends base {

    /** @var string[] Override date fields. */
    const DATES = ['allowsubmissionsfromdate', 'duedate', 'cutoffdate'];

    /** @var int User id, or 0 for a group override. */
    protected $userid;

    /** @var int Group id, or 0 for a user override. */
    protected $groupid;

    /** @var \assign */
    protected $assign;

    /**
     * Gate and capability checks for an override of one user or one group.
     *
     * @param int $cmid
     * @param int $userid
     * @param int $groupid
     */
    public function __construct(int $cmid, int $userid, int $groupid) {
        if (($userid > 0) === ($groupid > 0)) {
            throw new \invalid_parameter_exception('Give exactly one of userid and groupid');
        }
        list($this->cm, $this->course, $this->context) = gate::module($cmid);
        gate::require_modname($this->cm, 'assign');
        // As mod/assign/overrideedit.php, overridedelete.php and overrides.php.
        require_capability('mod/assign:manageoverrides', $this->context);
        $this->userid = $userid;
        $this->groupid = $groupid;
        // The pages work with cm_info (get_course_and_cm_from_cmid()); the override form needs it.
        $this->cm = get_fast_modinfo($this->course)->get_cm($cmid);
        $this->assign = new \assign($this->context, $this->cm, $this->course);

        // The pages refuse an existing override of a group or user the caller cannot see.
        if ($this->record()) {
            $visible = $groupid ? groups_group_visible($groupid, $this->course, $this->cm)
                : groups_user_groups_visible($this->course, $userid, $this->cm);
            if (!$visible) {
                throw new \moodle_exception('invalidoverrideid', 'assign');
            }
        }
    }

    /**
     * Handler for an existing override, by id.
     *
     * @param int $overrideid
     * @return assign_override
     */
    public static function by_id(int $overrideid): assign_override {
        global $DB;
        gate::require_enabled();
        $override = $DB->get_record('assign_overrides', ['id' => $overrideid]);
        if (!$override) {
            throw new \moodle_exception('invalidoverrideid', 'assign');
        }
        $cm = get_coursemodule_from_instance('assign', $override->assignid, 0, false, MUST_EXIST);
        return new self((int)$cm->id, (int)$override->userid, (int)$override->groupid);
    }

    /**
     * Kind name.
     *
     * @return string
     */
    public static function kind(): string {
        return 'assign_override';
    }

    /**
     * Handler for a journal target.
     *
     * @param array $target
     * @return base
     */
    public static function from_target(array $target): base {
        return new self((int)$target['cmid'], (int)($target['userid'] ?? 0), (int)($target['groupid'] ?? 0));
    }

    /**
     * Fields.
     *
     * @return string[]
     */
    public function fields(): array {
        return ['exists', 'allowsubmissionsfromdate', 'duedate', 'cutoffdate', 'sortorder'];
    }

    /**
     * Expected may also hold the override id.
     *
     * @return string[]
     */
    public function expected_fields(): array {
        return array_merge($this->fields(), ['id']);
    }

    /**
     * Every write journals the whole override, so undo can restore or recreate it.
     *
     * @param array $values
     * @return string[]
     */
    public function journal_keys(array $values): array {
        return $this->fields();
    }

    /**
     * The override record, if any.
     *
     * @return \stdClass|false
     */
    protected function record() {
        global $DB;
        $conditions = ['assignid' => $this->cm->instance];
        if ($this->groupid) {
            $conditions['groupid'] = $this->groupid;
            $conditions['userid'] = null;
        } else {
            $conditions['userid'] = $this->userid;
            $conditions['groupid'] = null;
        }
        return $DB->get_record('assign_overrides', $conditions);
    }

    /**
     * Current state.
     *
     * @return array
     */
    public function read(): array {
        $record = $this->record();
        $state = ['exists' => $record ? 1 : 0];
        foreach (self::DATES as $field) {
            $state[$field] = ($record && $record->$field !== null) ? (int)$record->$field : null;
        }
        $state['sortorder'] = ($record && $record->sortorder !== null) ? (int)$record->sortorder : null;
        $state['id'] = $record ? (int)$record->id : null;
        return $state;
    }

    /**
     * The assignment instance the override pages compare against.
     *
     * overrideedit.php calls $assign->get_instance($userid), where $userid is only passed in
     * relative dates mode; otherwise it is the caller's own instance.
     *
     * @return \stdClass
     */
    protected function instance(): \stdClass {
        $userid = ($this->userid && !empty($this->course->relativedatesmode)) ? $this->userid : null;
        return $this->assign->get_instance($userid);
    }

    /**
     * The dates the override form would submit: given values, else the override's, else
     * the assignment's (null in the values means "not overridden").
     *
     * @param array $values
     * @param array $current
     * @return array
     */
    protected function effective_dates(array $values, array $current): array {
        $instance = $this->instance();
        $dates = [];
        foreach (self::DATES as $field) {
            if (array_key_exists($field, $values)) {
                $value = $values[$field];
            } else {
                $value = $current[$field];
            }
            $dates[$field] = ($value === null) ? (int)$instance->$field : (int)$value;
        }
        return $dates;
    }

    /**
     * Run the override form's own validation and the page's user and group lists.
     *
     * @param array $values
     * @param array $current
     */
    public function validate(array $values, array $current) {
        if (empty($values['exists'])) {
            if (!$current['exists']) {
                throw new \moodle_exception('invalidoverrideid', 'assign');
            }
            return;
        }
        foreach (self::DATES as $field) {
            if (isset($values[$field]) && $values[$field] < 0) {
                throw new \invalid_parameter_exception($field . ' must be a unix timestamp, or 0 for disabled');
            }
        }
        if (isset($values['sortorder']) && !$this->groupid) {
            throw new \invalid_parameter_exception('sortorder only applies to group overrides');
        }
        if (!$current['exists']) {
            $this->require_eligible();
        }

        $record = $this->record();
        $selecteduserid = ($this->userid && !$record && !empty($this->course->relativedatesmode)) ? $this->userid : null;
        $url = new \moodle_url('/mod/assign/overrideedit.php', ['cmid' => $this->cm->id]);
        $form = new \assign_override_form($url, $this->cm, $this->assign, $this->context, (bool)$this->groupid,
            $record ?: null, $selecteduserid);
        $data = $this->effective_dates($values, $current);
        $data[$this->groupid ? 'groupid' : 'userid'] = $this->groupid ?: $this->userid;
        $errors = $form->validation($data, []);
        if ($errors) {
            $messages = [];
            foreach ($errors as $field => $message) {
                $messages[] = $field . ': ' . $message;
            }
            throw new \moodle_exception('invalidsettings', 'local_paatshala', '', implode('; ', $messages));
        }
    }

    /**
     * Refuse users and groups the override page would not offer.
     *
     * Mirrors assign_override_form::definition(): with no group mode or
     * moodle/site:accessallgroups, every enrolled user and every course group; otherwise
     * members of the groups the caller may access. Users are then filtered by the
     * activity's availability restrictions.
     */
    protected function require_eligible() {
        global $DB;
        $groupmode = groups_get_activity_groupmode($this->cm);
        $accessallgroups = ($groupmode == NOGROUPS) || has_capability('moodle/site:accessallgroups', $this->context);

        if ($this->groupid) {
            $groups = $accessallgroups ? groups_get_all_groups($this->course->id) :
                groups_get_activity_allowed_groups($this->cm);
            if (!isset($groups[$this->groupid])) {
                throw new \moodle_exception('overridenotallowed', 'local_paatshala', '', 'group ' . $this->groupid);
            }
            return;
        }

        $users = [];
        if ($accessallgroups) {
            $users = get_enrolled_users($this->context, '', 0, 'u.id');
        } else if ($groups = groups_get_activity_allowed_groups($this->cm)) {
            $enrolledjoin = get_enrolled_join($this->context, 'u.id');
            list($ingroupsql, $ingroupparams) = $DB->get_in_or_equal(array_keys($groups), SQL_PARAMS_NAMED);
            $sql = "SELECT DISTINCT u.id
                      FROM {user} u
                      JOIN {groups_members} gm ON gm.userid = u.id
                           {$enrolledjoin->joins}
                     WHERE gm.groupid $ingroupsql
                           AND {$enrolledjoin->wheres}";
            $users = $DB->get_records_sql($sql, $enrolledjoin->params + $ingroupparams);
        }
        $info = new \core_availability\info_module($this->cm);
        $users = $info->filter_user_list($users);
        if (!isset($users[$this->userid])) {
            throw new \moodle_exception('overridenotallowed', 'local_paatshala', '', 'user ' . $this->userid);
        }
    }

    /**
     * Save or delete, as the override pages do.
     *
     * @param array $values
     * @param array $current
     * @return array
     */
    public function write(array $values, array $current): array {
        global $DB;
        $assignid = (int)$this->cm->instance;

        if (empty($values['exists'])) {
            // As mod/assign/overridedelete.php.
            $this->assign->delete_override($current['id']);
            reorder_group_overrides($assignid);
            return [];
        }

        // As mod/assign/overrideedit.php from "Process the data".
        $instance = $this->instance();
        $fromform = (object)$this->effective_dates($values, $current);
        if ($this->groupid) {
            $fromform->groupid = $this->groupid;
        } else {
            $fromform->userid = $this->userid;
        }
        $fromform->assignid = $assignid;

        // Replace unchanged values with null.
        foreach (self::DATES as $key) {
            if ($fromform->{$key} == $instance->{$key}) {
                $fromform->{$key} = null;
            }
        }

        $params = ['context' => $this->context, 'other' => ['assignid' => $assignid]];
        if ($current['exists']) {
            $fromform->id = $current['id'];
            $DB->update_record('assign_overrides', $fromform);

            $params['objectid'] = $fromform->id;
            if (!$this->groupid) {
                $params['relateduserid'] = $fromform->userid;
                $event = \mod_assign\event\user_override_updated::create($params);
            } else {
                $params['other']['groupid'] = $fromform->groupid;
                $event = \mod_assign\event\group_override_updated::create($params);
            }
            $event->trigger();
        } else {
            $fromform->id = $DB->insert_record('assign_overrides', $fromform);
            if ($this->groupid) {
                $fromform->sortorder = 1;

                $overridecountgroup = $DB->count_records('assign_overrides',
                    array('userid' => null, 'assignid' => $assignid));
                $overridecountall = $DB->count_records('assign_overrides', array('assignid' => $assignid));
                if ((!$overridecountgroup) && ($overridecountall)) {
                    $fromform->sortorder = 1;
                } else {
                    $fromform->sortorder = $overridecountgroup;
                }

                $DB->update_record('assign_overrides', $fromform);
                reorder_group_overrides($assignid);
            }

            $params['objectid'] = $fromform->id;
            if (!$this->groupid) {
                $params['relateduserid'] = $fromform->userid;
                $event = \mod_assign\event\user_override_created::create($params);
            } else {
                $params['other']['groupid'] = $fromform->groupid;
                $event = \mod_assign\event\group_override_created::create($params);
            }
            $event->trigger();
        }

        assign_update_events($this->assign, $fromform);

        if ($this->groupid && isset($values['sortorder'])) {
            $this->move_to((int)$fromform->id, (int)$values['sortorder']);
        }
        return [];
    }

    /**
     * Move a group override to a priority with move_group_override() steps, as the up and
     * down arrows of mod/assign/overrides.php do.
     *
     * @param int $overrideid
     * @param int $sortorder
     */
    protected function move_to(int $overrideid, int $sortorder) {
        global $DB;
        $assignid = (int)$this->cm->instance;
        $count = $DB->count_records('assign_overrides', ['userid' => null, 'assignid' => $assignid]);
        if ($sortorder < 1 || $sortorder > $count) {
            throw new \invalid_parameter_exception('sortorder must be 1 to ' . $count);
        }
        $current = (int)$DB->get_field('assign_overrides', 'sortorder', ['id' => $overrideid]);
        while ($current != $sortorder) {
            move_group_override($overrideid, $current > $sortorder ? 'up' : 'down', $assignid);
            $current = (int)$DB->get_field('assign_overrides', 'sortorder', ['id' => $overrideid]);
        }
        // Refresh the calendar event priorities of the group overrides, as reorder does.
        reorder_group_overrides($assignid);
    }

    /**
     * Undo needs the override as the entry left it and, for a group override, its old
     * priority still to exist (other group overrides may have been deleted since).
     *
     * @param array $after
     * @param array $before
     * @param array $current
     */
    public function check_undo(array $after, array $before, array $current) {
        global $DB;
        \local_paatshala\local\state::require_matches($after, $current);
        if ($this->groupid && !empty($before['exists']) && $before['sortorder'] !== null) {
            $count = $DB->count_records('assign_overrides', ['userid' => null, 'assignid' => $this->cm->instance]);
            if (!$current['exists']) {
                $count++;
            }
            if ($before['sortorder'] > $count) {
                $state = $current + ['groupoverrides' => $count - ($current['exists'] ? 0 : 1)];
                $json = \local_paatshala\journal::encode($state);
                throw new \moodle_exception('conflict', 'local_paatshala', '', $json, $json);
            }
        }
    }

    /**
     * A new override is flagged createdbyplugin.
     *
     * @param array $before
     * @param array $after
     * @return bool
     */
    public function createdbyplugin(array $before, array $after): bool {
        return empty($before['exists']) && !empty($after['exists']);
    }

    /**
     * The student the override is about.
     *
     * @return int|null
     */
    public function relateduserid() {
        return $this->userid ?: null;
    }

    /**
     * Target.
     *
     * @return array
     */
    public function target(): array {
        $target = ['cmid' => (int)$this->cm->id, 'assignid' => (int)$this->cm->instance, 'name' => $this->cm->name];
        if ($this->groupid) {
            $target['groupid'] = $this->groupid;
            $target['groupname'] = groups_get_group_name($this->groupid);
        } else {
            $target['userid'] = $this->userid;
        }
        return $target;
    }
}

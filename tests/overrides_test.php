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
 * Tests for the assignment override functions and their undo.
 *
 * @package    local_paatshala
 * @copyright  2026 local_paatshala contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_paatshala;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once(__DIR__ . '/helper.php');
require_once($CFG->dirroot . '/mod/assign/locallib.php');

use external_api;
use local_paatshala\external\assign_delete_override;
use local_paatshala\external\assign_get_overrides;
use local_paatshala\external\assign_save_override;
use local_paatshala\external\undo;

/**
 * Tests for the assignment override functions and their undo.
 *
 * @package    local_paatshala
 * @copyright  2026 local_paatshala contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class overrides_test extends testcase {

    /** @var \stdClass */
    protected $course;

    /** @var \stdClass */
    protected $assign;

    /** @var \stdClass[] Students s1, s2 and an unenrolled user. */
    protected $users = [];

    /** @var \stdClass[] Groups g1, g2, g3. */
    protected $groups = [];

    /**
     * Course with an assignment, two students, three groups.
     */
    protected function setUp(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $this->course = $generator->create_course();
        $this->assign = $generator->create_module('assign', ['course' => $this->course->id,
            'allowsubmissionsfromdate' => 1893000000, 'duedate' => 1894000000, 'cutoffdate' => 1895000000]);
        foreach (['s1', 's2'] as $name) {
            $this->users[$name] = $generator->create_user();
            $generator->enrol_user($this->users[$name]->id, $this->course->id, 'student');
        }
        $this->users['outsider'] = $generator->create_user();
        foreach (['g1', 'g2', 'g3'] as $name) {
            $this->groups[$name] = $generator->create_group(['courseid' => $this->course->id, 'name' => $name]);
        }
        $this->enable_plugin('*');
        $this->login_ws_user(['mod/assign:manageoverrides']);
    }

    /**
     * Save an override and clean the result.
     *
     * @param array $args userid or groupid, dates, sortorder, expected.
     * @return array
     */
    protected function save(array $args): array {
        $args += ['userid' => 0, 'groupid' => 0, 'allowsubmissionsfromdate' => null, 'duedate' => null,
            'cutoffdate' => null, 'sortorder' => null, 'expected' => null];
        return external_api::clean_returnvalue(assign_save_override::execute_returns(),
            assign_save_override::execute($this->assign->cmid, $args['userid'], $args['groupid'],
                $args['allowsubmissionsfromdate'], $args['duedate'], $args['cutoffdate'], $args['sortorder'],
                $args['expected']));
    }

    /**
     * Save a group override for a named group.
     *
     * @param string $group
     * @param array $args
     * @return array
     */
    protected function save_group(string $group, array $args): array {
        return $this->save(['groupid' => $this->groups[$group]->id] + $args);
    }

    /**
     * Delete an override and clean the result.
     *
     * @param int $id
     * @param string|null $expected
     * @return array
     */
    protected function delete(int $id, $expected = null): array {
        return external_api::clean_returnvalue(assign_delete_override::execute_returns(),
            assign_delete_override::execute($id, $expected));
    }

    /**
     * Undo and clean the result.
     *
     * @param int $journalid
     * @return array
     */
    protected function undo(int $journalid): array {
        return external_api::clean_returnvalue(undo::execute_returns(), undo::execute($journalid, null));
    }

    /**
     * Group names in priority order.
     *
     * @return string[]
     */
    protected function group_order(): array {
        global $DB;
        $names = [];
        $records = $DB->get_records('assign_overrides', ['assignid' => $this->assign->id, 'userid' => null], 'sortorder');
        foreach ($records as $record) {
            $names[$record->sortorder] = groups_get_group_name($record->groupid);
        }
        return $names;
    }

    /**
     * Override record of a user.
     *
     * @param string $name
     * @return \stdClass|false
     */
    protected function user_override(string $name) {
        global $DB;
        return $DB->get_record('assign_overrides', ['assignid' => $this->assign->id,
            'userid' => $this->users[$name]->id]);
    }

    /**
     * User override: created like the page (unchanged dates stored as null), calendar
     * event, events fired, journal with the student as related user.
     */
    public function test_user_override() {
        global $DB;
        $sink = $this->redirectEvents();
        $result = $this->save(['userid' => $this->users['s1']->id, 'duedate' => 1894500000,
            'cutoffdate' => 1895000000]);
        $events = $sink->get_events();
        $sink->close();

        $record = $this->user_override('s1');
        $this->assertEquals(1894500000, $record->duedate);
        // Same as the assignment: stored as "not overridden", as on the override page.
        $this->assertNull($record->cutoffdate);
        $this->assertNull($record->allowsubmissionsfromdate);
        $this->assertNull($record->sortorder);
        $this->assertCount(1, array_filter($events, function($e) {
            return $e instanceof \mod_assign\event\user_override_created;
        }));
        $event = $DB->get_record('event', ['modulename' => 'assign', 'instance' => $this->assign->id,
            'userid' => $this->users['s1']->id]);
        $this->assertEquals(1894500000, $event->timestart);

        $this->assertEquals(['exists' => 0, 'allowsubmissionsfromdate' => null, 'duedate' => null, 'cutoffdate' => null,
            'sortorder' => null], json_decode($result['before'], true));
        $this->assertEquals(['exists' => 1, 'allowsubmissionsfromdate' => null, 'duedate' => 1894500000,
            'cutoffdate' => null, 'sortorder' => null], json_decode($result['after'], true));
        $this->assertFalse($result['notifies_students']);

        $entry = $this->journal_entry($result['journalid']);
        $this->assertSame('assign_override', $entry['kind']);
        $this->assertEquals($this->users['s1']->id, $entry['relateduserid']);
        $this->assertEquals($this->users['s1']->id, $entry['target']['userid']);
        $this->assertEquals(1, $entry['createdbyplugin']);

        // Change it: omitted dates keep the override's values.
        $result = $this->save(['userid' => $this->users['s1']->id, 'cutoffdate' => 1896000000]);
        $record = $this->user_override('s1');
        $this->assertEquals(1894500000, $record->duedate);
        $this->assertEquals(1896000000, $record->cutoffdate);
        $this->assertEquals(0, $this->journal_entry($result['journalid'])['createdbyplugin']);

        // Undo the change, then the creation: the override and its calendar event are gone.
        $this->undo($result['journalid']);
        $this->assertNull($this->user_override('s1')->cutoffdate);
        $first = $DB->get_records(journal::TABLE, ['kind' => 'assign_override'], 'id ASC', 'id', 0, 1);
        $this->undo((int)reset($first)->id);
        $this->assertFalse($this->user_override('s1'));
        $this->assertFalse($DB->record_exists('event', ['modulename' => 'assign', 'instance' => $this->assign->id,
            'userid' => $this->users['s1']->id]));
    }

    /**
     * The override form's validation and the page's user and group lists refuse.
     */
    public function test_validation() {
        $s1 = $this->users['s1']->id;
        $e = $this->assert_error('invalidsettings', function() use ($s1) {
            $this->save(['userid' => $s1, 'duedate' => 1894500000, 'cutoffdate' => 1894400000]);
        });
        $this->assertStringContainsString('cutoffdate', $e->getMessage());
        $this->assert_error('invalidsettings', function() use ($s1) {
            $this->save(['userid' => $s1, 'allowsubmissionsfromdate' => 1894500000, 'duedate' => 1894400000]);
        });
        // Nothing differs from the assignment.
        $this->assert_error('invalidsettings', function() use ($s1) {
            $this->save(['userid' => $s1, 'duedate' => 1894000000]);
        });
        $this->assert_error('overridenotallowed', function() {
            $this->save(['userid' => $this->users['outsider']->id, 'duedate' => 1894500000]);
        });
        $othercourse = $this->getDataGenerator()->create_course();
        $othergroup = $this->getDataGenerator()->create_group(['courseid' => $othercourse->id]);
        $this->assert_error('overridenotallowed', function() use ($othergroup) {
            $this->save(['groupid' => $othergroup->id, 'duedate' => 1894500000]);
        });
        $this->assert_error('invalidparameter', function() use ($s1) {
            $this->save(['userid' => $s1, 'groupid' => $this->groups['g1']->id, 'duedate' => 1894500000]);
        });
        $this->assert_error('invalidparameter', function() use ($s1) {
            $this->save(['userid' => $s1, 'duedate' => 1894500000, 'sortorder' => 1]);
        });
        $this->assertFalse($this->user_override('s1'));
    }

    /**
     * Group overrides: new ones go last, sortorder moves them, delete reorders, undo of a
     * delete puts it back at the same priority.
     */
    public function test_group_sortorder_delete_and_undo() {
        global $DB;
        $this->save_group('g1', ['duedate' => 1894100000]);
        $this->save_group('g2', ['duedate' => 1894200000]);
        $g3 = $this->save_group('g3', ['duedate' => 1894300000]);
        $this->assertEquals([1 => 'g1', 2 => 'g2', 3 => 'g3'], $this->group_order());
        $this->assertEquals(3, json_decode($g3['after'], true)['sortorder']);

        // Move g3 to the top.
        $moved = $this->save_group('g3', ['sortorder' => 1]);
        $this->assertEquals([1 => 'g3', 2 => 'g1', 3 => 'g2'], $this->group_order());
        $this->assertEquals(['sortorder' => 3], array_intersect_key(json_decode($moved['before'], true), ['sortorder' => 1]));
        $priority = $DB->get_field('event', 'priority', ['modulename' => 'assign', 'instance' => $this->assign->id,
            'groupid' => $this->groups['g3']->id]);
        $this->assertEquals(1, $priority);

        // Undo and redo the move.
        $unmoved = $this->undo($moved['journalid']);
        $this->assertEquals([1 => 'g1', 2 => 'g2', 3 => 'g3'], $this->group_order());
        $moved = $this->undo($unmoved['journalid']);
        $this->assertEquals([1 => 'g3', 2 => 'g1', 3 => 'g2'], $this->group_order());

        // Delete g1 (priority 2): the others close up.
        $g1 = $DB->get_record('assign_overrides', ['assignid' => $this->assign->id, 'groupid' => $this->groups['g1']->id]);
        $deleted = $this->delete($g1->id);
        $this->assertEquals([1 => 'g3', 2 => 'g2'], $this->group_order());
        $this->assertEquals(['exists' => 0, 'allowsubmissionsfromdate' => null, 'duedate' => null, 'cutoffdate' => null,
            'sortorder' => null], json_decode($deleted['after'], true));

        // Undo recreates it with the same dates at the same priority.
        $undone = $this->undo($deleted['journalid']);
        $this->assertEquals([1 => 'g3', 2 => 'g1', 3 => 'g2'], $this->group_order());
        $this->assertEquals(1894100000, $DB->get_field('assign_overrides', 'duedate',
            ['assignid' => $this->assign->id, 'groupid' => $this->groups['g1']->id]));
        $this->assertEquals(2, $DB->get_field('event', 'priority', ['modulename' => 'assign',
            'instance' => $this->assign->id, 'groupid' => $this->groups['g1']->id]));

        // Redo deletes it again.
        $this->undo($undone['journalid']);
        $this->assertEquals([1 => 'g3', 2 => 'g2'], $this->group_order());

        // The move cannot be undone now: there is no third priority any more.
        $e = $this->assert_error('conflict', function() use ($moved) {
            $this->undo($moved['journalid']);
        });
        $this->assertEquals(2, json_decode($e->debuginfo, true)['groupoverrides']);
        $this->assertEquals([1 => 'g3', 2 => 'g2'], $this->group_order());

        $this->assert_error('invalidparameter', function() {
            $this->save_group('g2', ['sortorder' => 5]);
        });
    }

    /**
     * get_overrides lists both kinds with null for "not overridden".
     */
    public function test_get_overrides() {
        $this->save(['userid' => $this->users['s1']->id, 'duedate' => 1894500000]);
        $this->save_group('g2', ['cutoffdate' => 0]);
        $result = external_api::clean_returnvalue(assign_get_overrides::execute_returns(),
            assign_get_overrides::execute($this->assign->cmid));
        $this->assertEquals(1894000000, $result['assignment']['duedate']);
        $this->assertCount(2, $result['overrides']);
        $byuser = array_values(array_filter($result['overrides'], function($o) {
            return isset($o['userid']);
        }))[0];
        $this->assertEquals($this->users['s1']->id, $byuser['userid']);
        $this->assertEquals(1894500000, $byuser['duedate']);
        $this->assertNull($byuser['cutoffdate']);
        $bygroup = array_values(array_filter($result['overrides'], function($o) {
            return isset($o['groupid']);
        }))[0];
        $this->assertEquals('g2', $bygroup['groupname']);
        $this->assertEquals(0, $bygroup['cutoffdate']);
        $this->assertEquals(1, $bygroup['sortorder']);
    }

    /**
     * Gate: capabilities, plugin disabled, allowlist.
     */
    public function test_gate() {
        $s1 = $this->users['s1']->id;
        $this->remove_capability('mod/assign:manageoverrides');
        $this->assert_error('nopermissions', function() use ($s1) {
            $this->save(['userid' => $s1, 'duedate' => 1894500000]);
        });
        $this->assert_error('nopermissions', function() {
            assign_get_overrides::execute($this->assign->cmid);
        });
        assign_capability('mod/assign:manageoverrides', CAP_ALLOW, $this->wsroleid, \context_system::instance()->id);
        $this->remove_capability('local/paatshala:use');
        $this->assert_error('nopermissions', function() use ($s1) {
            $this->save(['userid' => $s1, 'duedate' => 1894500000]);
        });
        assign_capability('local/paatshala:use', CAP_ALLOW, $this->wsroleid, \context_system::instance()->id);
        accesslib_clear_all_caches_for_unit_testing();
        set_config('allowedcourses', '', 'local_paatshala');
        $this->assert_error('coursenotallowed', function() use ($s1) {
            $this->save(['userid' => $s1, 'duedate' => 1894500000]);
        });
        set_config('allowedcourses', '*', 'local_paatshala');
        set_config('enabled', 0, 'local_paatshala');
        $this->assert_error('disabled', function() use ($s1) {
            $this->save(['userid' => $s1, 'duedate' => 1894500000]);
        });
        set_config('enabled', 1, 'local_paatshala');
        $created = $this->save(['userid' => $s1, 'duedate' => 1894500000]);
        $this->remove_capability('mod/assign:manageoverrides');
        $this->assert_error('nopermissions', function() use ($created) {
            $this->undo($created['journalid']);
        });
        $this->assert_error('nopermissions', function() {
            $this->delete($this->user_override('s1')->id);
        });
    }

    /**
     * Expected, undo after a later change, not undoable, deleting a missing override.
     */
    public function test_conflicts() {
        global $DB;
        $s1 = $this->users['s1']->id;
        $this->assert_error('conflict', function() use ($s1) {
            $this->save(['userid' => $s1, 'duedate' => 1894500000, 'expected' => json_encode(['exists' => 1])]);
        });
        $created = $this->save(['userid' => $s1, 'duedate' => 1894500000, 'expected' => json_encode(['exists' => 0])]);
        $id = $this->user_override('s1')->id;
        $this->assert_error('conflict', function() use ($id) {
            $this->delete($id, json_encode(['duedate' => 1894600000]));
        });

        $this->save(['userid' => $s1, 'duedate' => 1894600000]);
        $this->assert_error('conflict', function() use ($created) {
            $this->undo($created['journalid']);
        });
        $this->assertEquals(1894600000, $this->user_override('s1')->duedate);

        $deleted = $this->delete($id, json_encode(['id' => $id, 'duedate' => 1894600000]));
        $DB->set_field(journal::TABLE, 'undoable', 0, ['id' => $deleted['journalid']]);
        $this->assert_error('notundoable', function() use ($deleted) {
            $this->undo($deleted['journalid']);
        });
        $this->assert_error('invalidoverrideid', function() use ($id) {
            $this->delete($id);
        });
    }
}

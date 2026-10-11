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
 * Tests for local_paatshala_assign_set_dates and its undo.
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
use local_paatshala\external\assign_set_dates;
use local_paatshala\external\undo;

/**
 * Tests for local_paatshala_assign_set_dates and its undo.
 *
 * @package    local_paatshala
 * @copyright  2026 local_paatshala contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class assign_set_dates_test extends testcase {

    /** @var \stdClass */
    protected $course;

    /** @var \stdClass */
    protected $assign;

    /**
     * Course with an assignment, plugin enabled for all courses, ws user logged in.
     */
    protected function setUp(): void {
        $this->resetAfterTest();
        $this->course = $this->getDataGenerator()->create_course();
        $this->assign = $this->getDataGenerator()->create_module('assign', [
            'course' => $this->course->id,
            'allowsubmissionsfromdate' => 1893000000,
            'duedate' => 1894000000,
            'cutoffdate' => 1895000000,
            'gradingduedate' => 1896000000,
        ]);
        $this->enable_plugin('*');
        $this->login_ws_user(['moodle/course:manageactivities']);
    }

    /**
     * Call the function and clean the result.
     *
     * @param array $args
     * @return array
     */
    protected function set_dates(array $args): array {
        $args += ['cmid' => $this->assign->cmid, 'allowsubmissionsfromdate' => null, 'duedate' => null,
            'cutoffdate' => null, 'gradingduedate' => null, 'expected' => null];
        $result = assign_set_dates::execute($args['cmid'], $args['allowsubmissionsfromdate'], $args['duedate'],
            $args['cutoffdate'], $args['gradingduedate'], $args['expected']);
        return external_api::clean_returnvalue(assign_set_dates::execute_returns(), $result);
    }

    /**
     * Undo and clean the result.
     *
     * @param int $journalid
     * @param string|null $expected
     * @return array
     */
    protected function undo(int $journalid, $expected = null): array {
        return external_api::clean_returnvalue(undo::execute_returns(), undo::execute($journalid, $expected));
    }

    /**
     * Stored dates.
     *
     * @return array
     */
    protected function dates(): array {
        global $DB;
        return (array)$DB->get_record('assign', ['id' => $this->assign->id],
            'allowsubmissionsfromdate, duedate, cutoffdate, gradingduedate');
    }

    /**
     * Calendar event time by event type.
     *
     * @return array
     */
    protected function events(): array {
        global $DB;
        return $DB->get_records_menu('event', ['modulename' => 'assign', 'instance' => $this->assign->id],
            '', 'eventtype, timestart');
    }

    /**
     * Success: dates change, calendar events move, journal and result hold before/after.
     */
    public function test_success() {
        global $DB;
        $result = $this->set_dates(['duedate' => 1894500000, 'cutoffdate' => 1895500000]);

        $this->assertEquals(['duedate' => 1894000000, 'cutoffdate' => 1895000000], json_decode($result['before'], true));
        $this->assertEquals(['duedate' => 1894500000, 'cutoffdate' => 1895500000], json_decode($result['after'], true));
        $this->assertTrue($result['undoable']);
        $this->assertFalse($result['notifies_students']);
        $this->assertSame([], $result['warnings']);

        $this->assertEquals(1894500000, $this->dates()['duedate']);
        $this->assertEquals(1895500000, $this->dates()['cutoffdate']);
        $this->assertEquals(1893000000, $this->dates()['allowsubmissionsfromdate']);
        $events = $this->events();
        $this->assertEquals(1894500000, $events[ASSIGN_EVENT_TYPE_DUE]);
        $this->assertEquals(1896000000, $events[ASSIGN_EVENT_TYPE_GRADINGDUE]);

        $entry = $this->journal_entry($result['journalid']);
        $this->assertSame('assign_dates', $entry['kind']);
        $this->assertEquals($this->course->id, $entry['courseid']);
        $this->assertEquals($this->assign->cmid, $entry['cmid']);
        $this->assertEquals($this->wsuser->id, $entry['userid']);
        $this->assertEquals(['cmid' => $this->assign->cmid, 'modname' => 'assign', 'instance' => $this->assign->id,
            'name' => $this->assign->name], $entry['target']);
        $this->assertEquals(json_decode($result['before'], true), $entry['beforestate']);
        $this->assertEquals(json_decode($result['after'], true), $entry['afterstate']);
        $this->assertEquals(1, $entry['undoable']);
        $this->assertEquals(0, $entry['createdbyplugin']);
        $this->assertEquals(0, $entry['notifies']);
        $this->assertNull($entry['undoof']);
        $this->assertNull($entry['undoneby']);
        $this->assertEquals(1, $DB->count_records(journal::TABLE));
    }

    /**
     * Grading due date moves its calendar event; 0 disables a date and removes its event.
     */
    public function test_grading_due_and_disable() {
        $this->set_dates(['gradingduedate' => 1897000000]);
        $this->assertEquals(1897000000, $this->events()[ASSIGN_EVENT_TYPE_GRADINGDUE]);
        $this->set_dates(['gradingduedate' => 0]);
        $this->assertEquals(0, $this->dates()['gradingduedate']);
        $this->assertArrayNotHasKey(ASSIGN_EVENT_TYPE_GRADINGDUE, $this->events());
    }

    /**
     * The settings form's date rules refuse, and nothing is written or journaled.
     */
    public function test_form_validation() {
        global $DB;
        $cases = [
            'cut-off before due' => ['cutoffdate' => 1893500000],
            'due before allow from' => ['duedate' => 1892000000],
            'grading due before due' => ['gradingduedate' => 1893500000],
            'cut-off before allow from' => ['duedate' => 0, 'cutoffdate' => 1892000000],
        ];
        foreach ($cases as $name => $args) {
            $this->assert_error('invalidsettings', function() use ($args) {
                $this->set_dates($args);
            });
            $this->assertEquals(1894000000, $this->dates()['duedate'], $name);
        }
        $this->assertEquals(0, $DB->count_records(journal::TABLE));
        $this->expectException(\invalid_parameter_exception::class);
        $this->set_dates(['duedate' => -5]);
    }

    /**
     * Nothing to change is refused.
     */
    public function test_nothing_to_change() {
        $this->expectException(\invalid_parameter_exception::class);
        $this->set_dates([]);
    }

    /**
     * Each capability missing.
     */
    public function test_capabilities() {
        $this->remove_capability('moodle/course:manageactivities');
        $this->assert_error('nopermissions', function() {
            $this->set_dates(['duedate' => 1894500000]);
        });
        assign_capability('moodle/course:manageactivities', CAP_ALLOW, $this->wsroleid, \context_system::instance()->id);
        $this->remove_capability('local/paatshala:use');
        $this->assert_error('nopermissions', function() {
            $this->set_dates(['duedate' => 1894500000]);
        });
        $this->assertEquals(1894000000, $this->dates()['duedate']);
    }

    /**
     * Plugin disabled.
     */
    public function test_disabled() {
        set_config('enabled', 0, 'local_paatshala');
        $this->assert_error('disabled', function() {
            $this->set_dates(['duedate' => 1894500000]);
        });
    }

    /**
     * Course not allowed; listed id and "*" allowed.
     */
    public function test_allowed_courses() {
        set_config('allowedcourses', '', 'local_paatshala');
        $this->assert_error('coursenotallowed', function() {
            $this->set_dates(['duedate' => 1894500000]);
        });
        set_config('allowedcourses', ($this->course->id + 1) . ',' . ($this->course->id + 2), 'local_paatshala');
        $this->assert_error('coursenotallowed', function() {
            $this->set_dates(['duedate' => 1894500000]);
        });
        set_config('allowedcourses', ($this->course->id + 1) . ', ' . $this->course->id, 'local_paatshala');
        $this->set_dates(['duedate' => 1894500000]);
        set_config('allowedcourses', '*', 'local_paatshala');
        $this->set_dates(['duedate' => 1894600000]);
        $this->assertEquals(1894600000, $this->dates()['duedate']);
    }

    /**
     * Not an assignment.
     */
    public function test_wrong_module() {
        $page = $this->getDataGenerator()->create_module('page', ['course' => $this->course->id]);
        $this->assert_error('wrongmodule', function() use ($page) {
            $this->set_dates(['cmid' => $page->cmid, 'duedate' => 1894500000]);
        });
    }

    /**
     * Expected: match proceeds, mismatch is a conflict carrying the current state, bad JSON is refused.
     */
    public function test_expected() {
        $this->set_dates(['duedate' => 1894500000, 'expected' => json_encode(['duedate' => 1894000000])]);

        $e = $this->assert_error('conflict', function() {
            $this->set_dates(['duedate' => 1894700000, 'expected' => json_encode(['duedate' => 1894000000])]);
        });
        $current = json_decode($e->debuginfo, true);
        $this->assertEquals(1894500000, $current['duedate']);
        $this->assertEquals(1895000000, $current['cutoffdate']);
        $this->assertStringContainsString('"duedate":1894500000', $e->getMessage());
        $this->assertEquals(1894500000, $this->dates()['duedate']);

        $this->expectException(\invalid_parameter_exception::class);
        $this->set_dates(['duedate' => 1894700000, 'expected' => json_encode(['name' => 'x'])]);
    }

    /**
     * Undo returns to the exact before-state, and redo returns to the after-state.
     */
    public function test_undo_and_redo() {
        $original = $this->dates();
        $originalevents = $this->events();
        $result = $this->set_dates(['duedate' => 1894500000, 'gradingduedate' => 1897000000]);

        $undone = $this->undo($result['journalid']);
        $this->assertEquals($original, $this->dates());
        $this->assertEquals($originalevents, $this->events());
        $this->assertEquals($result['after'], $undone['before']);
        $this->assertEquals($result['before'], $undone['after']);

        $undoentry = $this->journal_entry($undone['journalid']);
        $this->assertEquals($result['journalid'], $undoentry['undoof']);
        $this->assertEquals($undone['journalid'], $this->journal_entry($result['journalid'])['undoneby']);

        // Already undone.
        $this->assert_error('alreadyundone', function() use ($result) {
            $this->undo($result['journalid']);
        });

        // Redo: undo the undo.
        $redone = $this->undo($undone['journalid']);
        $this->assertEquals(1894500000, $this->dates()['duedate']);
        $this->assertEquals(1897000000, $this->dates()['gradingduedate']);
        $this->assertEquals($undone['journalid'], $this->journal_entry($redone['journalid'])['undoof']);
    }

    /**
     * Undo refused after a later change to the same fields, and on a mismatching expected.
     */
    public function test_undo_refused_after_later_change() {
        $result = $this->set_dates(['duedate' => 1894500000]);
        $this->set_dates(['duedate' => 1894600000]);
        $this->assert_error('conflict', function() use ($result) {
            $this->undo($result['journalid']);
        });
        $this->assertEquals(1894600000, $this->dates()['duedate']);

        $latest = $this->set_dates(['cutoffdate' => 1895500000]);
        $this->assert_error('conflict', function() use ($latest) {
            $this->undo($latest['journalid'], json_encode(['cutoffdate' => 1895000000]));
        });
        $this->undo($latest['journalid'], json_encode(['cutoffdate' => 1895500000]));
        $this->assertEquals(1895000000, $this->dates()['cutoffdate']);
    }

    /**
     * Undo refused when the entry is not undoable.
     */
    public function test_undo_refused_when_not_undoable() {
        global $DB;
        $result = $this->set_dates(['duedate' => 1894500000]);
        $DB->set_field(journal::TABLE, 'undoable', 0, ['id' => $result['journalid']]);
        $this->assert_error('notundoable', function() use ($result) {
            $this->undo($result['journalid']);
        });
    }

    /**
     * Undo runs the same gate and capability checks.
     */
    public function test_undo_checks_gate_and_capabilities() {
        $result = $this->set_dates(['duedate' => 1894500000]);
        $this->remove_capability('moodle/course:manageactivities');
        $this->assert_error('nopermissions', function() use ($result) {
            $this->undo($result['journalid']);
        });
        set_config('allowedcourses', '', 'local_paatshala');
        $this->assert_error('coursenotallowed', function() use ($result) {
            $this->undo($result['journalid']);
        });
        set_config('enabled', 0, 'local_paatshala');
        $this->assert_error('disabled', function() use ($result) {
            $this->undo($result['journalid']);
        });
    }
}

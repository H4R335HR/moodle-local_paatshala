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
 * Tests for local_paatshala_workshop_switch_phase and its undo.
 *
 * @package    local_paatshala
 * @copyright  2026 local_paatshala contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_paatshala;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once(__DIR__ . '/helper.php');
require_once($CFG->dirroot . '/mod/workshop/locallib.php');

use external_api;
use local_paatshala\external\undo;
use local_paatshala\external\workshop_switch_phase;

/**
 * Tests for local_paatshala_workshop_switch_phase and its undo.
 *
 * @package    local_paatshala
 * @copyright  2026 local_paatshala contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class workshop_switch_phase_test extends testcase {

    /** @var \stdClass */
    protected $course;

    /** @var \stdClass */
    protected $workshop;

    /**
     * Course with a workshop in the submission phase.
     */
    protected function setUp(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->course = $this->getDataGenerator()->create_course();
        $this->workshop = $this->getDataGenerator()->create_module('workshop', ['course' => $this->course->id]);
        $this->set_phase_directly(\workshop::PHASE_SUBMISSION);
        $this->enable_plugin('*');
        $this->login_ws_user(['mod/workshop:switchphase']);
    }

    /**
     * Set the phase without the plugin (test setup).
     *
     * @param int $phase
     */
    protected function set_phase_directly(int $phase) {
        global $DB;
        $DB->set_field('workshop', 'phase', $phase, ['id' => $this->workshop->id]);
    }

    /**
     * Stored phase.
     *
     * @return int
     */
    protected function phase(): int {
        global $DB;
        return (int)$DB->get_field('workshop', 'phase', ['id' => $this->workshop->id]);
    }

    /**
     * Switch and clean the result.
     *
     * @param int $phase
     * @param string|null $expected
     * @return array
     */
    protected function switch_phase(int $phase, $expected = null): array {
        return external_api::clean_returnvalue(workshop_switch_phase::execute_returns(),
            workshop_switch_phase::execute($this->workshop->cmid, $phase, $expected));
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
     * Success: phase changes, phase_switched fires, journal holds before/after.
     */
    public function test_success() {
        $sink = $this->redirectEvents();
        $result = $this->switch_phase(\workshop::PHASE_ASSESSMENT);
        $events = array_filter($sink->get_events(), function($event) {
            return $event instanceof \mod_workshop\event\phase_switched;
        });
        $sink->close();

        $this->assertEquals(\workshop::PHASE_ASSESSMENT, $this->phase());
        $this->assertCount(1, $events);
        $this->assertEquals(['phase' => 20], json_decode($result['before'], true));
        $this->assertEquals(['phase' => 30], json_decode($result['after'], true));
        $this->assertTrue($result['undoable']);
        $this->assertFalse($result['notifies_students']);
        $this->assertSame([], $result['warnings']);

        $entry = $this->journal_entry($result['journalid']);
        $this->assertSame('workshop_phase', $entry['kind']);
        $this->assertEquals($this->workshop->cmid, $entry['cmid']);
        $this->assertEquals(['phase' => 20], $entry['beforestate']);
        $this->assertEquals(['phase' => 30], $entry['afterstate']);
        $this->assertEquals('workshop', $entry['target']['modname']);
    }

    /**
     * Unknown phase refused.
     */
    public function test_invalid_phase() {
        $this->expectException(\invalid_parameter_exception::class);
        $this->switch_phase(35);
    }

    /**
     * Closing warns that grades were pushed to the gradebook.
     */
    public function test_closing_warns_grades_pushed() {
        $result = $this->switch_phase(\workshop::PHASE_CLOSED);
        $this->assertSame('gradespushed', $result['warnings'][0]['code']);
        $this->assertTrue($result['undoable']);
    }

    /**
     * Back to submission with the automatic switch on and the deadline passed warns.
     */
    public function test_autoswitch_warning() {
        global $DB;
        $DB->update_record('workshop', (object)['id' => $this->workshop->id, 'phaseswitchassessment' => 1,
            'submissionend' => time() - 3600, 'phase' => \workshop::PHASE_ASSESSMENT]);
        $result = $this->switch_phase(\workshop::PHASE_SUBMISSION);
        $this->assertSame('autoswitch', $result['warnings'][0]['code']);

        // Deadline in the future: no warning.
        $DB->update_record('workshop', (object)['id' => $this->workshop->id, 'submissionend' => time() + 3600,
            'phase' => \workshop::PHASE_ASSESSMENT]);
        $this->assertSame([], $this->switch_phase(\workshop::PHASE_SUBMISSION)['warnings']);
    }

    /**
     * Each capability missing; plugin disabled; course allowlist; wrong module.
     */
    public function test_gate() {
        $this->remove_capability('mod/workshop:switchphase');
        $this->assert_error('nopermissions', function() {
            $this->switch_phase(\workshop::PHASE_ASSESSMENT);
        });
        assign_capability('mod/workshop:switchphase', CAP_ALLOW, $this->wsroleid, \context_system::instance()->id);
        $this->remove_capability('local/paatshala:use');
        $this->assert_error('nopermissions', function() {
            $this->switch_phase(\workshop::PHASE_ASSESSMENT);
        });
        assign_capability('local/paatshala:use', CAP_ALLOW, $this->wsroleid, \context_system::instance()->id);
        accesslib_clear_all_caches_for_unit_testing();

        set_config('allowedcourses', (string)($this->course->id + 1), 'local_paatshala');
        $this->assert_error('coursenotallowed', function() {
            $this->switch_phase(\workshop::PHASE_ASSESSMENT);
        });
        set_config('allowedcourses', (string)$this->course->id, 'local_paatshala');
        set_config('enabled', 0, 'local_paatshala');
        $this->assert_error('disabled', function() {
            $this->switch_phase(\workshop::PHASE_ASSESSMENT);
        });
        set_config('enabled', 1, 'local_paatshala');
        $this->switch_phase(\workshop::PHASE_ASSESSMENT);
        $this->assertEquals(\workshop::PHASE_ASSESSMENT, $this->phase());

        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $this->course->id]);
        $this->assert_error('wrongmodule', function() use ($assign) {
            workshop_switch_phase::execute($assign->cmid, 30);
        });
    }

    /**
     * Expected mismatch is a conflict.
     */
    public function test_expected() {
        $e = $this->assert_error('conflict', function() {
            $this->switch_phase(\workshop::PHASE_ASSESSMENT, json_encode(['phase' => 10]));
        });
        $this->assertEquals(['phase' => 20], json_decode($e->debuginfo, true));
        $this->assertEquals(\workshop::PHASE_SUBMISSION, $this->phase());
        $this->switch_phase(\workshop::PHASE_ASSESSMENT, json_encode(['phase' => 20]));
        $this->assertEquals(\workshop::PHASE_ASSESSMENT, $this->phase());
    }

    /**
     * Undo round trip, undo refused after a later change and when not undoable, redo.
     */
    public function test_undo() {
        global $DB;
        $result = $this->switch_phase(\workshop::PHASE_ASSESSMENT);
        $undone = $this->undo($result['journalid']);
        $this->assertEquals(\workshop::PHASE_SUBMISSION, $this->phase());
        $this->assertEquals($result['journalid'], $this->journal_entry($undone['journalid'])['undoof']);

        $redone = $this->undo($undone['journalid']);
        $this->assertEquals(\workshop::PHASE_ASSESSMENT, $this->phase());
        $this->assertEquals($redone['journalid'], $this->journal_entry($undone['journalid'])['undoneby']);

        // A later switch: the redo entry no longer matches.
        $this->switch_phase(\workshop::PHASE_EVALUATION);
        $this->assert_error('conflict', function() use ($redone) {
            $this->undo($redone['journalid']);
        });

        $latest = $this->switch_phase(\workshop::PHASE_CLOSED);
        $DB->set_field(journal::TABLE, 'undoable', 0, ['id' => $latest['journalid']]);
        $this->assert_error('notundoable', function() use ($latest) {
            $this->undo($latest['journalid']);
        });
        $this->assertEquals(\workshop::PHASE_CLOSED, $this->phase());
    }
}

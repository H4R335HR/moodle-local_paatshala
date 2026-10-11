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
 * Tests for local_paatshala_move_module and its undo.
 *
 * @package    local_paatshala
 * @copyright  2026 local_paatshala contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_paatshala;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/helper.php');

use external_api;
use local_paatshala\external\move_module;
use local_paatshala\external\undo;

/**
 * Tests for local_paatshala_move_module and its undo.
 *
 * @package    local_paatshala
 * @copyright  2026 local_paatshala contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class move_module_test extends testcase {

    /** @var \stdClass */
    protected $course;

    /** @var \stdClass[] Pages a, b, c in section 1; d in section 2. */
    protected $pages = [];

    /**
     * Course with pages a, b, c in section 1 and d in section 2.
     */
    protected function setUp(): void {
        $this->resetAfterTest();
        $this->course = $this->getDataGenerator()->create_course(['numsections' => 3]);
        foreach (['a' => 1, 'b' => 1, 'c' => 1, 'd' => 2] as $name => $section) {
            $this->pages[$name] = $this->getDataGenerator()->create_module('page',
                ['course' => $this->course->id, 'section' => $section, 'name' => $name]);
        }
        $this->enable_plugin('*');
        $this->login_ws_user(['moodle/course:manageactivities', 'moodle/course:activityvisibility']);
    }

    /**
     * Move and clean the result.
     *
     * @param string $name Page to move.
     * @param int $sectionnum
     * @param string|null $before Page to place it before.
     * @param string|null $expected
     * @return array
     */
    protected function move(string $name, int $sectionnum, $before = null, $expected = null): array {
        $beforecmid = $before ? $this->pages[$before]->cmid : 0;
        return external_api::clean_returnvalue(move_module::execute_returns(),
            move_module::execute($this->pages[$name]->cmid, $sectionnum, $beforecmid, $expected));
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
     * Page names per section, in order.
     *
     * @return array
     */
    protected function layout(): array {
        global $DB;
        $names = [];
        foreach ($this->pages as $name => $page) {
            $names[$page->cmid] = $name;
        }
        $layout = [];
        foreach ($DB->get_records('course_sections', ['course' => $this->course->id], 'section') as $section) {
            $layout[$section->section] = [];
            foreach (array_filter(explode(',', $section->sequence)) as $cmid) {
                $layout[$section->section][] = $names[$cmid];
            }
        }
        return $layout;
    }

    /**
     * Success: position changes; journal and result hold section, neighbour and visibility.
     */
    public function test_success() {
        $result = $this->move('a', 2, 'd');
        $this->assertEquals([0 => [], 1 => ['b', 'c'], 2 => ['a', 'd'], 3 => []], $this->layout());
        $this->assertEquals(['sectionnum' => 1, 'beforecmid' => $this->pages['b']->cmid, 'visible' => 1,
            'visibleoncoursepage' => 1], json_decode($result['before'], true));
        $this->assertEquals(['sectionnum' => 2, 'beforecmid' => $this->pages['d']->cmid, 'visible' => 1,
            'visibleoncoursepage' => 1], json_decode($result['after'], true));
        $this->assertTrue($result['undoable']);
        $this->assertFalse($result['notifies_students']);

        $entry = $this->journal_entry($result['journalid']);
        $this->assertSame('move_module', $entry['kind']);
        $this->assertEquals($this->pages['a']->cmid, $entry['cmid']);
        $this->assertEquals('a', $entry['target']['name']);

        // Within a section, to the end.
        $this->move('b', 1);
        $this->assertEquals(['c', 'b'], $this->layout()[1]);
    }

    /**
     * Bad section or neighbour refused.
     */
    public function test_validation() {
        $this->assert_error('invalidparameter', function() {
            $this->move('a', 9);
        });
        $this->assert_error('invalidparameter', function() {
            $this->move('a', 2, 'b');
        });
        $this->assert_error('invalidparameter', function() {
            $this->move('a', 1, 'a');
        });
        $this->assertEquals(['a', 'b', 'c'], $this->layout()[1]);
    }

    /**
     * Moving into a hidden section hides the activity; undo restores the visibility.
     */
    public function test_hidden_section_and_undo() {
        global $DB;
        set_section_visible($this->course->id, 3, 0);
        $result = $this->move('a', 3);
        $this->assertSame('hidden', $result['warnings'][0]['code']);
        $this->assertEquals(0, $DB->get_field('course_modules', 'visible', ['id' => $this->pages['a']->cmid]));
        $this->assertEquals(0, json_decode($result['after'], true)['visible']);

        $this->undo($result['journalid']);
        $this->assertEquals(['a', 'b', 'c'], $this->layout()[1]);
        $this->assertEquals(1, $DB->get_field('course_modules', 'visible', ['id' => $this->pages['a']->cmid]));
    }

    /**
     * A stealth activity (visible, not on the course page) keeps that state through undo.
     */
    public function test_stealth_restored_on_undo() {
        global $DB;
        set_coursemodule_visible($this->pages['a']->cmid, 1, 0);
        set_section_visible($this->course->id, 3, 0);
        $result = $this->move('a', 3);
        $this->undo($result['journalid']);
        $cm = $DB->get_record('course_modules', ['id' => $this->pages['a']->cmid]);
        $this->assertEquals(1, $cm->visible);
        $this->assertEquals(0, $cm->visibleoncoursepage);
    }

    /**
     * Gate: capabilities, disabled, allowlist.
     */
    public function test_gate() {
        $this->remove_capability('moodle/course:manageactivities');
        $this->assert_error('nopermissions', function() {
            $this->move('a', 2);
        });
        assign_capability('moodle/course:manageactivities', CAP_ALLOW, $this->wsroleid, \context_system::instance()->id);
        $this->remove_capability('local/paatshala:use');
        $this->assert_error('nopermissions', function() {
            $this->move('a', 2);
        });
        assign_capability('local/paatshala:use', CAP_ALLOW, $this->wsroleid, \context_system::instance()->id);
        accesslib_clear_all_caches_for_unit_testing();
        set_config('allowedcourses', '', 'local_paatshala');
        $this->assert_error('coursenotallowed', function() {
            $this->move('a', 2);
        });
        set_config('allowedcourses', (string)$this->course->id, 'local_paatshala');
        set_config('enabled', 0, 'local_paatshala');
        $this->assert_error('disabled', function() {
            $this->move('a', 2);
        });
        set_config('enabled', 1, 'local_paatshala');
        $this->move('a', 2);
        $this->assertEquals(['b', 'c'], $this->layout()[1]);
    }

    /**
     * Expected mismatch is a conflict.
     */
    public function test_expected() {
        $this->assert_error('conflict', function() {
            $this->move('a', 2, null, json_encode(['sectionnum' => 2]));
        });
        $this->move('a', 2, null, json_encode(['sectionnum' => 1, 'beforecmid' => $this->pages['b']->cmid]));
        $this->assertEquals(['d', 'a'], $this->layout()[2]);
    }

    /**
     * Undo round trip and redo.
     */
    public function test_undo_and_redo() {
        $original = $this->layout();
        $result = $this->move('b', 2);
        $undone = $this->undo($result['journalid']);
        $this->assertEquals($original, $this->layout());
        $this->undo($undone['journalid']);
        $this->assertEquals(['d', 'b'], $this->layout()[2]);
    }

    /**
     * Undo refused when the old neighbour moved or is gone, or the activity moved again,
     * or the entry is not undoable.
     */
    public function test_undo_refused() {
        global $DB;
        // Old neighbour moved.
        $result = $this->move('a', 2);
        $this->move('b', 3);
        $this->assert_error('conflict', function() use ($result) {
            $this->undo($result['journalid']);
        });

        // Old neighbour gone.
        $result = $this->move('c', 2);
        course_delete_module($this->pages['b']->cmid);
        $this->move('d', 2);
        $this->assert_error('conflict', function() use ($result) {
            $this->undo($result['journalid']);
        });

        // Moved again since.
        $result = $this->move('d', 3);
        $this->move('d', 1);
        $this->assert_error('conflict', function() use ($result) {
            $this->undo($result['journalid']);
        });

        $result = $this->move('d', 3);
        $DB->set_field(journal::TABLE, 'undoable', 0, ['id' => $result['journalid']]);
        $this->assert_error('notundoable', function() use ($result) {
            $this->undo($result['journalid']);
        });
    }
}

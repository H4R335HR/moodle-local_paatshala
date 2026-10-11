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
 * Tests for local_paatshala_move_section, local_paatshala_create_section and their undo.
 *
 * @package    local_paatshala
 * @copyright  2026 local_paatshala contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_paatshala;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/helper.php');

use external_api;
use local_paatshala\external\create_section;
use local_paatshala\external\move_section;
use local_paatshala\external\undo;

/**
 * Tests for local_paatshala_move_section, local_paatshala_create_section and their undo.
 *
 * @package    local_paatshala
 * @copyright  2026 local_paatshala contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class sections_test extends testcase {

    /** @var \stdClass */
    protected $course;

    /**
     * Topics course with sections 1-3 named S1-S3, a page in section 2.
     */
    protected function setUp(): void {
        global $DB;
        $this->resetAfterTest();
        $this->course = $this->getDataGenerator()->create_course(['numsections' => 3, 'format' => 'topics']);
        foreach ([1, 2, 3] as $num) {
            $DB->set_field('course_sections', 'name', 'S' . $num, ['course' => $this->course->id, 'section' => $num]);
        }
        $this->getDataGenerator()->create_module('page', ['course' => $this->course->id, 'section' => 2]);
        rebuild_course_cache($this->course->id, true);
        $this->enable_plugin('*');
        $this->login_ws_user(['moodle/course:movesections', 'moodle/course:update']);
    }

    /**
     * Section names in order of number.
     *
     * @return array
     */
    protected function names(): array {
        global $DB;
        return array_values($DB->get_records_menu('course_sections', ['course' => $this->course->id], 'section',
            'section, name'));
    }

    /**
     * Move a section and clean the result.
     *
     * @param int $sectionnum
     * @param int $targetnum
     * @param string|null $expected
     * @return array
     */
    protected function move(int $sectionnum, int $targetnum, $expected = null): array {
        return external_api::clean_returnvalue(move_section::execute_returns(),
            move_section::execute($this->course->id, $sectionnum, $targetnum, $expected));
    }

    /**
     * Create a section and clean the result.
     *
     * @param int $position
     * @param string $name
     * @return array
     */
    protected function create(int $position = 0, string $name = ''): array {
        return external_api::clean_returnvalue(create_section::execute_returns(),
            create_section::execute($this->course->id, $position, $name));
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
     * Move a section; journal; undo; redo; the highlighted section follows.
     */
    public function test_move_section() {
        course_set_marker($this->course->id, 1);
        $result = $this->move(1, 3);
        $this->assertEquals([null, 'S2', 'S3', 'S1'], $this->names());
        $this->assertEquals(['sectionnum' => 1], json_decode($result['before'], true));
        $this->assertEquals(['sectionnum' => 3], json_decode($result['after'], true));
        $this->assertEquals(3, get_course($this->course->id)->marker);
        $entry = $this->journal_entry($result['journalid']);
        $this->assertSame('move_section', $entry['kind']);
        $this->assertEquals('S1', $entry['target']['name']);
        $this->assertNull($entry['cmid']);

        $undone = $this->undo($result['journalid']);
        $this->assertEquals([null, 'S1', 'S2', 'S3'], $this->names());
        $this->assertEquals(1, get_course($this->course->id)->marker);
        $this->undo($undone['journalid']);
        $this->assertEquals([null, 'S2', 'S3', 'S1'], $this->names());
    }

    /**
     * Section 0 and out-of-range targets refused; missing section refused.
     */
    public function test_move_section_validation() {
        foreach ([[0, 1], [1, 0], [1, 4], [5, 1]] as $case) {
            $this->assert_error('invalidparameter', function() use ($case) {
                $this->move($case[0], $case[1]);
            });
        }
        $this->assertEquals([null, 'S1', 'S2', 'S3'], $this->names());
    }

    /**
     * Expected, conflicting undo and the not-undoable flag.
     */
    public function test_move_section_conflicts() {
        global $DB;
        $this->assert_error('conflict', function() {
            $this->move(1, 2, json_encode(['name' => 'S2']));
        });
        $result = $this->move(1, 2, json_encode(['name' => 'S1', 'sectionnum' => 1]));
        $this->move(2, 3);
        $this->assert_error('conflict', function() use ($result) {
            $this->undo($result['journalid']);
        });
        $latest = $this->move(3, 1);
        $DB->set_field(journal::TABLE, 'undoable', 0, ['id' => $latest['journalid']]);
        $this->assert_error('notundoable', function() use ($latest) {
            $this->undo($latest['journalid']);
        });
    }

    /**
     * Gate for move_section and create_section.
     */
    public function test_gate() {
        $this->remove_capability('moodle/course:movesections');
        $this->assert_error('nopermissions', function() {
            $this->move(1, 2);
        });
        // Adding at the end only needs moodle/course:update; inserting needs movesections too.
        $this->create();
        $this->assert_error('nopermissions', function() {
            $this->create(1);
        });
        $this->remove_capability('moodle/course:update');
        $this->assert_error('nopermissions', function() {
            $this->create();
        });
        assign_capability('moodle/course:update', CAP_ALLOW, $this->wsroleid, \context_system::instance()->id);
        assign_capability('moodle/course:movesections', CAP_ALLOW, $this->wsroleid, \context_system::instance()->id);
        $this->remove_capability('local/paatshala:use');
        $this->assert_error('nopermissions', function() {
            $this->create();
        });
        assign_capability('local/paatshala:use', CAP_ALLOW, $this->wsroleid, \context_system::instance()->id);
        accesslib_clear_all_caches_for_unit_testing();
        set_config('allowedcourses', (string)($this->course->id + 1), 'local_paatshala');
        $this->assert_error('coursenotallowed', function() {
            $this->create();
        });
        set_config('allowedcourses', '*', 'local_paatshala');
        set_config('enabled', 0, 'local_paatshala');
        $this->assert_error('disabled', function() {
            $this->move(1, 2);
        });
    }

    /**
     * Create at the end and at a position; journal; undo deletes; redo recreates.
     */
    public function test_create_section() {
        global $DB;
        $result = $this->create(0, 'New');
        $this->assertEquals([null, 'S1', 'S2', 'S3', 'New'], $this->names());
        $this->assertEquals(['exists' => 0, 'sectionnum' => null, 'name' => null], json_decode($result['before'], true));
        $this->assertEquals(['exists' => 1, 'sectionnum' => 4, 'name' => 'New'], json_decode($result['after'], true));
        $entry = $this->journal_entry($result['journalid']);
        $this->assertSame('create_section', $entry['kind']);
        $this->assertEquals(1, $entry['createdbyplugin']);
        $sectionid = $entry['target']['sectionid'];
        $this->assertTrue($DB->record_exists('course_sections', ['id' => $sectionid, 'section' => 4]));

        $inserted = $this->create(2);
        $this->assertEquals([null, 'S1', null, 'S2', 'S3', 'New'], $this->names());
        $this->assertEquals(2, json_decode($inserted['after'], true)['sectionnum']);

        $undone = $this->undo($inserted['journalid']);
        $this->assertEquals([null, 'S1', 'S2', 'S3', 'New'], $this->names());
        $this->assertEquals(0, $this->journal_entry($undone['journalid'])['createdbyplugin']);

        $redone = $this->undo($undone['journalid']);
        $this->assertEquals([null, 'S1', null, 'S2', 'S3', 'New'], $this->names());
        $this->assertNotEquals($this->journal_entry($inserted['journalid'])['target']['sectionid'],
            $this->journal_entry($redone['journalid'])['target']['sectionid']);
    }

    /**
     * Undo of a creation refused once the section has an activity, a summary or another name.
     */
    public function test_create_section_undo_refused_when_not_empty() {
        global $DB;
        $withpage = $this->create(0, 'A');
        $sectionnum = json_decode($withpage['after'], true)['sectionnum'];
        $this->getDataGenerator()->create_module('page', ['course' => $this->course->id, 'section' => $sectionnum]);
        $e = $this->assert_error('conflict', function() use ($withpage) {
            $this->undo($withpage['journalid']);
        });
        $this->assertEquals(1, json_decode($e->debuginfo, true)['activities']);

        $withsummary = $this->create(0, 'B');
        $sectionid = $this->journal_entry($withsummary['journalid'])['target']['sectionid'];
        $DB->set_field('course_sections', 'summary', 'Text', ['id' => $sectionid]);
        $this->assert_error('conflict', function() use ($withsummary) {
            $this->undo($withsummary['journalid']);
        });

        $renamed = $this->create(0, 'C');
        $sectionid = $this->journal_entry($renamed['journalid'])['target']['sectionid'];
        $DB->set_field('course_sections', 'name', 'Changed', ['id' => $sectionid]);
        $this->assert_error('conflict', function() use ($renamed) {
            $this->undo($renamed['journalid']);
        });
        $this->assertEquals(7, $DB->count_records('course_sections', ['course' => $this->course->id]));
    }

    /**
     * Creation validation: position range and the maximum number of sections.
     */
    public function test_create_section_validation() {
        $this->assert_error('invalidparameter', function() {
            $this->create(9);
        });
        set_config('maxsections', 4, 'moodlecourse');
        $this->create();
        $this->assert_error('maxsectionslimit', function() {
            $this->create();
        });
    }
}

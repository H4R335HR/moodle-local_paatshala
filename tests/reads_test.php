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
 * Tests for the journal and module settings read functions.
 *
 * @package    local_paatshala
 * @copyright  2026 local_paatshala contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_paatshala;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/helper.php');

use external_api;
use local_paatshala\external\assign_set_dates;
use local_paatshala\external\get_module_settings;
use local_paatshala\external\journal_get;
use local_paatshala\external\journal_list;
use local_paatshala\external\undo;

/**
 * Tests for the journal and module settings read functions.
 *
 * @package    local_paatshala
 * @copyright  2026 local_paatshala contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class reads_test extends testcase {

    /**
     * get_module_settings for assign, workshop, quiz and another module.
     */
    public function test_get_module_settings() {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course(['numsections' => 3]);
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id, 'section' => 2]);
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $course->id, 'section' => 2,
            'duedate' => 1894000000, 'cutoffdate' => 0, 'grade' => 50, 'visible' => 0, 'name' => 'A1',
            'assignsubmission_onlinetext_enabled' => 0, 'assignsubmission_file_enabled' => 1,
            'assignsubmission_file_maxfiles' => 2, 'assignsubmission_file_maxsizebytes' => 1048576,
            'assignsubmission_file_filetypes' => '.pdf', 'assignfeedback_comments_enabled' => 1,
            'assignfeedback_comments_commentinline' => 1, 'attemptreopenmethod' => 'manual', 'maxattempts' => 3]);
        $workshop = $this->getDataGenerator()->create_module('workshop', ['course' => $course->id,
            'submissionend' => 1894000000, 'phaseswitchassessment' => 1]);
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id, 'timeopen' => 1893000000]);
        $this->enable_plugin('*');
        $this->login_ws_user(['moodle/course:manageactivities']);

        $get = function($cmid) {
            return external_api::clean_returnvalue(get_module_settings::execute_returns(),
                get_module_settings::execute($cmid));
        };

        $result = $get($assign->cmid);
        $settings = array_diff_key($result, get_module_settings::ASSIGN_SETTINGS);
        $this->assertEquals(['cmid' => $assign->cmid, 'courseid' => $course->id, 'modname' => 'assign', 'name' => 'A1',
            'visible' => 0, 'sectionnum' => 2, 'position' => 1, 'allowsubmissionsfromdate' => $assign->allowsubmissionsfromdate,
            'duedate' => 1894000000, 'cutoffdate' => 0, 'gradingduedate' => $assign->gradingduedate, 'grade' => 50,
            'atrisk' => []], $settings);

        // Assignment settings under update_assign_settings' field names: the whole whitelist, as it reads them.
        $handler = new \local_paatshala\local\kind\assign_settings((int)$assign->cmid);
        $this->assertEqualsCanonicalizing(array_diff($handler->fields(), ['name']),
            array_keys(get_module_settings::ASSIGN_SETTINGS));
        $this->assertEquals(array_diff_key($handler->read(), ['name' => 1]),
            array_intersect_key($result, get_module_settings::ASSIGN_SETTINGS));
        $this->assertSame(0, $result['assignsubmission_onlinetext_enabled']);
        $this->assertSame(0, $result['assignsubmission_onlinetext_wordlimit']);
        $this->assertSame(1, $result['assignsubmission_file_enabled']);
        $this->assertSame(2, $result['assignsubmission_file_maxfiles']);
        $this->assertSame(1048576, $result['assignsubmission_file_maxsizebytes']);
        $this->assertSame('.pdf', $result['assignsubmission_file_filetypes']);
        $this->assertSame(1, $result['assignfeedback_comments_enabled']);
        $this->assertSame(1, $result['assignfeedback_comments_commentinline']);
        $this->assertSame('manual', $result['attemptreopenmethod']);
        $this->assertSame(3, $result['maxattempts']);
        $this->assertSame(0, $result['requiresubmissionstatement']);

        $result = $get($workshop->cmid);
        $this->assertEquals(10, $result['phase']);
        $this->assertEquals(1894000000, $result['submissionend']);
        $this->assertEquals(1, $result['phaseswitchassessment']);
        $this->assertArrayNotHasKey('duedate', $result);
        $this->assertArrayNotHasKey('maxattempts', $result);

        $result = $get($quiz->cmid);
        $this->assertEquals(1893000000, $result['timeopen']);
        $this->assertEquals(0, $result['timeclose']);

        $result = $get($page->cmid);
        $this->assertEquals(0, $result['position']);
        $this->assertArrayNotHasKey('timeopen', $result);

        $this->remove_capability('moodle/course:manageactivities');
        $this->assert_error('nopermissions', function() use ($get, $assign) {
            $get($assign->cmid);
        });
    }

    /**
     * journal_list and journal_get: filters, order, gate.
     */
    public function test_journal_list_and_get() {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $other = $this->getDataGenerator()->create_course();
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $course->id, 'duedate' => 1894000000]);
        $this->enable_plugin('*');
        $this->login_ws_user(['moodle/course:manageactivities']);

        $first = assign_set_dates::execute($assign->cmid, null, 1894500000);
        $second = undo::execute($first['journalid']);

        $list = external_api::clean_returnvalue(journal_list::execute_returns(), journal_list::execute($course->id));
        $this->assertCount(2, $list['entries']);
        $this->assertEquals($second['journalid'], $list['entries'][0]['id']);
        $this->assertEquals($first['journalid'], $list['entries'][0]['undoof']);
        $this->assertEquals($second['journalid'], $list['entries'][1]['undoneby']);
        $this->assertArrayNotHasKey('undoof', $list['entries'][1]);
        $this->assertEquals('{"duedate":1894000000}', $list['entries'][1]['before']);

        $list = journal_list::execute($course->id, 0, '', 1);
        $this->assertCount(1, $list['entries']);
        $this->assertCount(0, journal_list::execute($course->id, 0, 'workshop_phase')['entries']);
        $this->assertCount(2, journal_list::execute($course->id, 0, 'assign_dates')['entries']);
        $this->assertCount(0, journal_list::execute($course->id, time() + 10)['entries']);
        $this->assertCount(0, journal_list::execute($other->id)['entries']);

        $entry = external_api::clean_returnvalue(journal_get::execute_returns(), journal_get::execute($first['journalid']));
        $this->assertEquals('assign_dates', $entry['kind']);
        $this->assertEquals($assign->cmid, $entry['cmid']);
        $this->assertTrue($entry['undoable']);

        set_config('allowedcourses', (string)$other->id, 'local_paatshala');
        $this->assert_error('coursenotallowed', function() use ($first) {
            journal_get::execute($first['journalid']);
        });
        $this->assert_error('coursenotallowed', function() use ($course) {
            journal_list::execute($course->id);
        });
        set_config('allowedcourses', '*', 'local_paatshala');
        $this->assert_error('journalnotfound', function() {
            journal_get::execute(999999);
        });
        set_config('enabled', 0, 'local_paatshala');
        $this->assert_error('disabled', function() use ($first) {
            journal_get::execute($first['journalid']);
        });
    }
}

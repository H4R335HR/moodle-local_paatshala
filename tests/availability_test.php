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
 * Tests for group access restrictions on sections and activities.
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
use local_paatshala\external\get_availability;
use local_paatshala\external\get_course_availability;
use local_paatshala\external\info;
use local_paatshala\external\set_group_access;
use local_paatshala\external\undo;

/**
 * Tests for group access restrictions on sections and activities.
 *
 * @package    local_paatshala
 * @copyright  2026 local_paatshala contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class availability_test extends testcase {

    /** @var \stdClass */
    protected $course;

    /** @var \stdClass June batch group. */
    protected $june;

    /** @var \stdClass September batch group. */
    protected $september;

    /** @var \stdClass[] Users by role in the story: june, september, both, none, editing, teacher. */
    protected $users = [];

    /** @var \stdClass Section 1 record. */
    protected $section;

    /** @var \stdClass Assignment in section 1. */
    protected $assign;

    /** @var \stdClass Page in section 1. */
    protected $page;

    /** @var \stdClass Page in section 2. */
    protected $other;

    /** @var \stdClass Web service user. */
    protected $wsuser;

    /**
     * A course shared by two batches, with a project section holding an assignment and a page.
     */
    protected function setUp(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('enableavailability', 1);
        set_config('enablecompletion', 1);
        $gen = $this->getDataGenerator();
        $this->course = $gen->create_course(['numsections' => 3, 'enablecompletion' => 1]);
        $this->june = $gen->create_group(['courseid' => $this->course->id, 'name' => 'JUN 25 batch']);
        $this->september = $gen->create_group(['courseid' => $this->course->id, 'name' => 'SEP 25 batch']);
        foreach (['june', 'september', 'both', 'none'] as $name) {
            $this->users[$name] = $gen->create_and_enrol($this->course, 'student');
        }
        $this->users['editing'] = $gen->create_and_enrol($this->course, 'editingteacher');
        $this->users['teacher'] = $gen->create_and_enrol($this->course, 'teacher');
        $gen->create_group_member(['groupid' => $this->june->id, 'userid' => $this->users['june']->id]);
        $gen->create_group_member(['groupid' => $this->september->id, 'userid' => $this->users['september']->id]);
        $gen->create_group_member(['groupid' => $this->june->id, 'userid' => $this->users['both']->id]);
        $gen->create_group_member(['groupid' => $this->september->id, 'userid' => $this->users['both']->id]);

        $this->assign = $gen->create_module('assign', ['course' => $this->course->id, 'section' => 1,
            'duedate' => time() + WEEKSECS, 'assignsubmission_onlinetext_enabled' => 1]);
        $this->page = $gen->create_module('page', ['course' => $this->course->id, 'section' => 1]);
        $this->other = $gen->create_module('page', ['course' => $this->course->id, 'section' => 2]);
        $this->section = $DB->get_record('course_sections', ['course' => $this->course->id, 'section' => 1]);
        $this->enable_plugin('*');
        $this->wsuser = $this->login_ws_user(['moodle/course:update', 'moodle/course:manageactivities',
            'moodle/course:ignoreavailabilityrestrictions']);
    }

    /**
     * Call set_group_access and clean the result.
     *
     * @param array $args sectionid or cmid, groupids, whenunmet, action, expected
     * @return array
     */
    protected function set(array $args): array {
        return external_api::clean_returnvalue(set_group_access::execute_returns(), set_group_access::execute(
            $this->course->id, $args['sectionid'] ?? 0, $args['cmid'] ?? 0, $args['groupids'] ?? [],
            $args['whenunmet'] ?? 'hide', $args['action'] ?? 'set', $args['expected'] ?? null));
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
     * Stored availability of a section or cm.
     *
     * @param string $table
     * @param int $id
     * @return string|null
     */
    protected function stored(string $table, int $id) {
        global $DB;
        return $DB->get_field($table, 'availability', ['id' => $id]);
    }

    /**
     * What a user sees: section 1 and the activities in it.
     *
     * @param string $who
     * @return array [section uservisible, section availableinfo, assign uservisible, page uservisible,
     *     page on course page]
     */
    protected function seen(string $who): array {
        get_fast_modinfo(0, 0, true);
        $modinfo = get_fast_modinfo($this->course, $this->users[$who]->id);
        $section = $modinfo->get_section_info(1);
        $page = $modinfo->get_cm($this->page->cmid);
        return [$section->uservisible, (string)$section->availableinfo, $modinfo->get_cm($this->assign->cmid)->uservisible,
            $page->uservisible, $page->is_visible_on_course_page()];
    }

    /**
     * Whether the user can open the activity by its link (require_login).
     *
     * @param string $who
     * @param int $cmid
     * @return bool
     */
    protected function can_open(string $who, int $cmid): bool {
        global $PAGE;
        get_fast_modinfo(0, 0, true);
        $PAGE = new \moodle_page();
        $this->setUser($this->users[$who]);
        try {
            $cm = get_fast_modinfo($this->course)->get_cm($cmid);
            require_login($this->course, false, $cm, false, true);
            return true;
        } catch (\require_login_exception $e) {
            return false;
        } finally {
            $this->setUser($this->wsuser);
            get_fast_modinfo(0, 0, true);
        }
    }

    /**
     * Give the web service role a capability again.
     *
     * @param string $capability
     */
    protected function assign_capability_back(string $capability) {
        assign_capability($capability, CAP_ALLOW, $this->wsroleid, \context_system::instance()->id, true);
        accesslib_clear_all_caches_for_unit_testing();
    }

    /**
     * Section restricted to the June group, hidden entirely from others.
     */
    public function test_section_hidden_from_other_groups() {
        $result = $this->set(['sectionid' => $this->section->id, 'groupids' => [$this->june->id]]);
        $this->assertSame('{"op":"&","c":[{"type":"group","id":' . $this->june->id . '}],"showc":[false]}',
            $this->stored('course_sections', $this->section->id));
        $this->assertSame(['availability' => ''], json_decode($result['before'], true));
        $this->assertFalse($result['notifies_students']);

        $this->assertSame([true, '', true, true, true], $this->seen('june'));
        $this->assertTrue($this->can_open('june', $this->page->cmid));
        // September: no heading, no "Not available unless" message, activities not reachable.
        $this->assertSame([false, '', false, false, false], $this->seen('september'));
        $this->assertFalse($this->can_open('september', $this->page->cmid));
        $this->assertFalse($this->can_open('september', $this->assign->cmid));
        $this->assertTrue($this->can_open('september', $this->other->cmid));
        $this->assertSame([true, '', true, true, true], $this->seen('both'));
        $this->assertFalse($this->seen('none')[0]);
        $this->assertFalse($this->can_open('none', $this->page->cmid));
        // Staff: the editing teacher through moodle/site:accessallgroups and
        // moodle/course:ignoreavailabilityrestrictions, the non-editing teacher (not in any
        // group, no accessallgroups) through ignoreavailabilityrestrictions.
        $this->assertTrue($this->seen('editing')[0]);
        $this->assertTrue($this->can_open('editing', $this->page->cmid));
        $this->assertTrue($this->seen('teacher')[0]);
        $this->assertTrue($this->can_open('teacher', $this->assign->cmid));
    }

    /**
     * whenunmet = show: others see the heading with the restriction, but cannot open the
     * activities.
     */
    public function test_section_shown_with_message() {
        $this->set(['sectionid' => $this->section->id, 'groupids' => [$this->june->id], 'whenunmet' => 'show']);
        list($visible, $info, $assign, $page) = $this->seen('september');
        $this->assertFalse($visible);
        $this->assertNotSame('', $info);
        $this->assertFalse($assign);
        $this->assertFalse($page);
        $this->assertFalse($this->can_open('september', $this->page->cmid));
        $this->assertTrue($this->seen('june')[0]);
    }

    /**
     * An activity restricted to two groups: members of either have access.
     */
    public function test_activity_restricted_to_groups() {
        $result = $this->set(['cmid' => $this->page->cmid, 'groupids' => [$this->june->id, $this->september->id]]);
        $this->assertSame('{"op":"&","c":[{"op":"|","c":[{"type":"group","id":' . $this->june->id . '},' .
            '{"type":"group","id":' . $this->september->id . '}]}],"showc":[false]}',
            $this->stored('course_modules', $this->page->cmid));
        $this->assertSame('module_availability', $this->journal_entry($result['journalid'])['kind']);
        foreach (['june', 'september', 'both', 'editing', 'teacher'] as $who) {
            $this->assertTrue($this->can_open($who, $this->page->cmid), $who);
        }
        $this->assertFalse($this->can_open('none', $this->page->cmid));
        $this->assertFalse($this->seen('none')[4]);
        $this->assertTrue($this->can_open('none', $this->assign->cmid));

        // Just June, shown with the message to others.
        $this->set(['cmid' => $this->page->cmid, 'groupids' => [$this->june->id], 'whenunmet' => 'show']);
        $this->assertFalse($this->can_open('september', $this->page->cmid));
        get_fast_modinfo(0, 0, true);
        $cm = get_fast_modinfo($this->course, $this->users['september']->id)->get_cm($this->page->cmid);
        $this->assertNotSame('', (string)$cm->availableinfo);
        $this->assertTrue($cm->is_visible_on_course_page());
    }

    /**
     * Other restrictions, submissions and grades are left alone by set, remove and undo.
     */
    public function test_other_data_unchanged() {
        global $DB;
        $date = '{"type":"date","d":">=","t":1500000000}';
        $completion = '{"type":"completion","cm":' . $this->other->cmid . ',"e":1}';
        $stored = '{"op":"&","c":[' . $date . ',' . $completion . '],"showc":[true,false]}';
        $DB->set_field('course_modules', 'availability', $stored, ['id' => $this->assign->cmid]);
        rebuild_course_cache($this->course->id, true);

        // A submission and a grade.
        $this->setAdminUser();
        $assign = new \assign(\context_module::instance($this->assign->cmid), null, null);
        $submission = $assign->get_user_submission($this->users['june']->id, true);
        $submission->status = ASSIGN_SUBMISSION_STATUS_SUBMITTED;
        $DB->update_record('assign_submission', $submission);
        $assign->save_grade($this->users['june']->id, (object)['grade' => 70, 'attemptnumber' => -1, 'addattempt' => 0,
            'sendstudentnotifications' => 0]);
        $this->setUser($this->wsuser);
        $rows = function() use ($DB) {
            $cm = (array)$DB->get_record('course_modules', ['id' => $this->assign->cmid]);
            unset($cm['availability']);
            return [
                'cm' => $cm,
                // The settings form path moves timemodified, as the settings page does.
                'assign' => array_map(function($row) {
                    unset($row->timemodified);
                    return $row;
                }, $DB->get_records('assign', ['id' => $this->assign->id])),
                'submissions' => $DB->get_records('assign_submission', ['assignment' => $this->assign->id]),
                'grades' => $DB->get_records('assign_grades', ['assignment' => $this->assign->id]),
                'grade_grades' => $DB->get_records_sql('SELECT gg.* FROM {grade_grades} gg JOIN {grade_items} gi ' .
                    'ON gi.id = gg.itemid WHERE gi.courseid = ?', [$this->course->id]),
                'sections' => $DB->get_records('course_sections', ['course' => $this->course->id], 'id',
                    'id, section, name, summary, sequence, visible, availability'),
            ];
        };
        $before = $rows();
        // The assignment's grade item and the course total.
        $this->assertCount(2, $before['grade_grades']);

        $set = $this->set(['cmid' => $this->assign->cmid, 'groupids' => [$this->june->id]]);
        $tree = json_decode($this->stored('course_modules', $this->assign->cmid));
        $this->assertEquals(json_decode($date), $tree->c[0]);
        $this->assertEquals(json_decode($completion), $tree->c[1]);
        $this->assertSame([true, false, false], $tree->showc);
        $this->assertEquals($before, $rows());

        $removed = $this->set(['cmid' => $this->assign->cmid, 'action' => 'remove']);
        $this->assertEquals(json_decode($stored), json_decode($this->stored('course_modules', $this->assign->cmid)));
        $this->assertEquals($before, $rows());

        $this->undo($removed['journalid']);
        $this->undo($set['journalid']);
        $this->assertSame($stored, $this->stored('course_modules', $this->assign->cmid));
        $this->assertEquals($before, $rows());
    }

    /**
     * Journal row, undo to the exact before string, undo refused after a later change, redo.
     */
    public function test_journal_undo_redo() {
        global $DB;
        // A stored string with its own key order and spacing.
        $stored = '{"showc": [true], "op": "&", "c": [{"t": 1500000000, "type": "date", "d": ">="}]}';
        $DB->set_field('course_sections', 'availability', $stored, ['id' => $this->section->id]);
        rebuild_course_cache($this->course->id, true);

        $result = $this->set(['sectionid' => $this->section->id, 'groupids' => [$this->june->id],
            'expected' => json_encode(['availability' => '{"op":"&","c":[{"type":"date","d":">=","t":1500000000}],' .
                '"showc":[true]}'])]);
        $entry = $this->journal_entry($result['journalid']);
        $this->assertSame('section_availability', $entry['kind']);
        $this->assertEquals(['courseid' => $this->course->id, 'sectionid' => $this->section->id, 'sectionnum' => 1,
            'name' => get_section_name($this->course, 1)], $entry['target']);
        $this->assertSame(['availability' => $stored], $entry['beforestate']);
        $this->assertSame(1, (int)$entry['undoable']);
        $this->assertSame(0, (int)$entry['notifies']);
        $this->assertNull($entry['relateduserid']);

        $undone = $this->undo($result['journalid']);
        $this->assertSame($stored, $this->stored('course_sections', $this->section->id));
        $redone = $this->undo($undone['journalid']);
        $this->assertSame(json_decode($result['after'], true)['availability'],
            $this->stored('course_sections', $this->section->id));

        // A later change blocks undoing the redo's predecessor.
        $this->set(['sectionid' => $this->section->id, 'groupids' => [$this->september->id]]);
        $this->assert_error('conflict', function() use ($redone) {
            $this->undo($redone['journalid']);
        });

        // Setting what is there: nothing written, nothing journaled.
        $count = $DB->count_records(journal::TABLE);
        $same = $this->set(['sectionid' => $this->section->id, 'groupids' => [$this->september->id]]);
        $this->assertSame(0, $same['journalid']);
        $this->assertSame('nochange', $same['warnings'][0]['code']);
        $this->assertSame($count, $DB->count_records(journal::TABLE));

        // Remove to nothing stores NULL.
        $DB->set_field('course_sections', 'availability', null, ['id' => $this->section->id]);
        rebuild_course_cache($this->course->id, true);
        $this->set(['sectionid' => $this->section->id, 'groupids' => [$this->june->id]]);
        $this->set(['sectionid' => $this->section->id, 'action' => 'remove']);
        $this->assertNull($this->stored('course_sections', $this->section->id));
        $this->set(['cmid' => $this->page->cmid, 'groupids' => [$this->june->id]]);
        $this->set(['cmid' => $this->page->cmid, 'action' => 'remove']);
        $this->assertNull($this->stored('course_modules', $this->page->cmid));
    }

    /**
     * Gate, capabilities, expected, groups, site settings, refusals.
     */
    public function test_refusals() {
        global $DB;
        $section = ['sectionid' => $this->section->id, 'groupids' => [$this->june->id]];
        $this->assert_error('conflict', function() use ($section) {
            $this->set($section + ['expected' => json_encode(['availability' => '{"op":"&","c":[],"showc":[]}'])]);
        });
        $this->assertNull($this->stored('course_sections', $this->section->id));
        $this->set($section + ['expected' => json_encode(['availability' => ''])]);

        $othercourse = $this->getDataGenerator()->create_course();
        $foreign = $this->getDataGenerator()->create_group(['courseid' => $othercourse->id]);
        $this->assert_error('invalidgroup', function() use ($foreign) {
            $this->set(['cmid' => $this->page->cmid, 'groupids' => [$foreign->id]]);
        });
        $empty = $this->getDataGenerator()->create_group(['courseid' => $this->course->id]);
        $warned = $this->set(['cmid' => $this->page->cmid, 'groupids' => [$empty->id]]);
        $this->assertSame('emptygroup', $warned['warnings'][0]['code']);
        $this->assert_error('invalidparameter', function() {
            $this->set(['cmid' => $this->page->cmid, 'sectionid' => $this->section->id, 'groupids' => [1]]);
        });
        $this->assert_error('invalidparameter', function() use ($othercourse) {
            $this->set(['cmid' => $this->getDataGenerator()->create_module('page',
                ['course' => $othercourse->id])->cmid, 'groupids' => [$this->june->id]]);
        });
        $this->assert_error('invalidparameter', function() {
            $this->set(['cmid' => $this->page->cmid, 'groupids' => [$this->june->id], 'whenunmet' => 'grey']);
        });

        // Group conditions the plugin does not manage.
        $DB->set_field('course_modules', 'availability', '{"op":"&","c":[{"op":"!&","c":[{"type":"group","id":' .
            $this->june->id . '}]}],"showc":[true]}', ['id' => $this->other->cmid]);
        $e = $this->assert_error('invalidavailability', function() {
            $this->set(['cmid' => $this->other->cmid, 'groupids' => [$this->june->id]]);
        });
        $this->assertStringContainsString('c[0].c[0]', $e->getMessage());

        // A stored tree Moodle cannot read is refused, not repaired.
        $DB->set_field('course_modules', 'availability', '{"op":"|","c":[]}', ['id' => $this->other->cmid]);
        $e = $this->assert_error('invalidavailability', function() {
            $this->set(['cmid' => $this->other->cmid, 'action' => 'remove']);
        });
        $this->assertStringContainsString('missing ->show', $e->getMessage());

        // Site settings.
        set_config('enableavailability', 0);
        $this->assert_error('availabilitydisabled', function() use ($section) {
            $this->set($section);
        });
        set_config('enableavailability', 1);
        $enabled = \core_plugin_manager::instance()->get_enabled_plugins('availability');
        set_config('disabled', 1, 'availability_group');
        \core_plugin_manager::reset_caches();
        $e = $this->assert_error('availabilitydisabled', function() use ($section) {
            $this->set($section);
        });
        $this->assertStringContainsString('availability_group', $e->getMessage());
        unset_config('disabled', 'availability_group');
        \core_plugin_manager::reset_caches();

        // Capabilities, allowlist, plugin off.
        $this->remove_capability('moodle/course:manageactivities');
        $this->assert_error('nopermissions', function() {
            $this->set(['cmid' => $this->page->cmid, 'groupids' => [$this->june->id]]);
        });
        $this->set($section + ['groupids' => [$this->september->id]]);
        $this->remove_capability('moodle/course:update');
        $this->assert_error('nopermissions', function() use ($section) {
            $this->set($section);
        });
        $this->assert_error('nopermissions', function() {
            get_availability::execute($this->course->id, $this->section->id);
        });
        $this->assign_capability_back('moodle/course:update');
        $this->remove_capability('moodle/course:ignoreavailabilityrestrictions');
        $this->assert_error('nopermissions', function() use ($section) {
            $this->set($section);
        });
        $this->remove_capability('local/paatshala:use');
        $this->assert_error('nopermissions', function() use ($section) {
            $this->set($section);
        });
        set_config('allowedcourses', (string)$othercourse->id, 'local_paatshala');
        $this->assert_error('coursenotallowed', function() use ($section) {
            $this->set($section);
        });
        set_config('enabled', 0, 'local_paatshala');
        $this->assert_error('disabled', function() use ($section) {
            $this->set($section);
        });
        $this->assertNotEmpty($enabled);
    }

    /**
     * Section 0 can be restricted, as its edit page offers restrictions.
     */
    public function test_section_zero() {
        global $DB;
        $zero = $DB->get_record('course_sections', ['course' => $this->course->id, 'section' => 0]);
        $result = $this->set(['sectionid' => $zero->id, 'groupids' => [$this->june->id]]);
        $this->assertGreaterThan(0, $result['journalid']);
        get_fast_modinfo(0, 0, true);
        $this->assertFalse(get_fast_modinfo($this->course, $this->users['september']->id)->get_section_info(0)->uservisible);
        $this->undo($result['journalid']);
        $this->assertNull($this->stored('course_sections', $zero->id));
    }

    /**
     * get_availability and get_course_availability, including a tree Moodle cannot read.
     */
    public function test_reads() {
        global $DB;
        $this->set(['sectionid' => $this->section->id, 'groupids' => [$this->june->id, $this->september->id],
            'whenunmet' => 'show']);
        $result = external_api::clean_returnvalue(get_availability::execute_returns(),
            get_availability::execute($this->course->id, $this->section->id));
        $this->assertSame('section', $result['type']);
        $this->assertSame(1, $result['sectionnum']);
        $this->assertSame(2, $result['activities']);
        $this->assertTrue($result['valid']);
        $this->assertSame('', $result['parseerror']);
        $this->assertTrue($result['enableavailability']);
        $this->assertTrue($result['groupenabled']);
        $this->assertCount(2, $result['conditions']);
        $this->assertSame(['type' => 'group', 'path' => 'c[0].c[1]', 'negated' => false, 'display' => 'show',
            'fields' => '{"id":' . $this->september->id . '}'], $result['conditions'][1]);
        $this->assertSame(['id' => (int)$this->june->id, 'name' => 'JUN 25 batch', 'exists' => true, 'incourse' => true,
            'members' => 2], $result['groups'][0]);

        // A negated condition, a malformed tree, and an activity without restrictions.
        $DB->set_field('course_modules', 'availability', '{"op":"!|","c":[{"type":"date","d":">=","t":1}],' .
            '"showc":[false]}', ['id' => $this->assign->cmid]);
        $DB->set_field('course_modules', 'availability', '{"op":"|","c":[]}', ['id' => $this->other->cmid]);
        $assign = external_api::clean_returnvalue(get_availability::execute_returns(),
            get_availability::execute($this->course->id, 0, $this->assign->cmid));
        $this->assertSame('module', $assign['type']);
        $this->assertSame('assign', $assign['modname']);
        $this->assertTrue($assign['conditions'][0]['negated']);
        $this->assertSame('hide', $assign['conditions'][0]['display']);
        $this->assertArrayNotHasKey('activities', $assign);

        $all = external_api::clean_returnvalue(get_course_availability::execute_returns(),
            get_course_availability::execute($this->course->id))['items'];
        $this->assertCount(3, $all);
        $this->assertSame([(int)$this->section->id, (int)$this->assign->cmid, (int)$this->other->cmid],
            [$all[0]['sectionid'], $all[1]['cmid'], $all[2]['cmid']]);
        $this->assertFalse($all[2]['valid']);
        $this->assertStringContainsString('missing ->show', $all[2]['parseerror']);
        $this->assertSame(2, $all[2]['sectionnum']);

        $info = external_api::clean_returnvalue(info::execute_returns(), info::execute());
        $this->assertTrue($info['enableavailability']);
        $this->assertTrue($info['availabilitygroup']);
        $this->assertContains('local_paatshala_set_group_access', $info['functions']);
    }
}

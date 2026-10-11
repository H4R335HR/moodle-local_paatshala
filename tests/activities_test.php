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
 * Tests for copying and creating activities, deleting them and the course recycle bin.
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
use local_paatshala\external\copy_activity;
use local_paatshala\external\create_activity;
use local_paatshala\external\delete_created;
use local_paatshala\external\recyclebin_list;
use local_paatshala\external\recyclebin_restore;
use local_paatshala\external\undo;

/**
 * Tests for copying and creating activities, deleting them and the course recycle bin.
 *
 * @package    local_paatshala
 * @copyright  2026 local_paatshala contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class activities_test extends testcase {

    /** @var string[] Capabilities the web service role gets. */
    const CAPS = ['moodle/course:manageactivities', 'moodle/course:update', 'moodle/backup:backuptargetimport',
        'moodle/restore:restoretargetimport', 'tool/recyclebin:viewitems', 'tool/recyclebin:restoreitems',
        'mod/assign:addinstance', 'mod/page:addinstance', 'mod/url:addinstance', 'mod/label:addinstance',
        'moodle/grade:managegradingforms', 'mod/url:view', 'mod/label:view'];

    /** @var \stdClass Source course. */
    protected $source;

    /** @var \stdClass Target course. */
    protected $target;

    /**
     * Two courses, recycle bin on, plugin on, ws user.
     */
    protected function setUp(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->source = $this->getDataGenerator()->create_course(['numsections' => 2]);
        $this->target = $this->getDataGenerator()->create_course(['numsections' => 3]);
        set_config('coursebinenable', 1, 'tool_recyclebin');
        $this->enable_plugin('*');
    }

    /**
     * Copy and clean the result.
     *
     * @param int $cmid
     * @param int $sectionnum
     * @param int $beforecmid
     * @return array
     */
    protected function copy(int $cmid, int $sectionnum = 1, int $beforecmid = 0): array {
        return external_api::clean_returnvalue(copy_activity::execute_returns(),
            copy_activity::execute($cmid, $this->target->id, $sectionnum, $beforecmid));
    }

    /**
     * Create and clean the result.
     *
     * @param string $modname
     * @param array $fields
     * @param int $sectionnum
     * @return array
     */
    protected function create(string $modname, array $fields, int $sectionnum = 1): array {
        return external_api::clean_returnvalue(create_activity::execute_returns(),
            create_activity::execute($this->target->id, $sectionnum, $modname, json_encode($fields)));
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
     * New cmid from a result.
     *
     * @param array $result
     * @return int
     */
    protected static function cmid(array $result): int {
        return (int)json_decode($result['after'], true)['cmid'];
    }

    /**
     * An assignment with a rubric in the source course.
     *
     * @return \stdClass
     */
    protected function assign_with_rubric(): \stdClass {
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $this->source->id, 'section' => 1,
            'name' => 'Report', 'duedate' => 1894000000]);
        $this->getDataGenerator()->get_plugin_generator('gradingform_rubric')->create_instance(
            \context_module::instance($assign->cmid), 'mod_assign', 'submissions', 'Rubric', 'Marking',
            ['Quality' => ['Poor' => 0, 'Good' => 5, 'Excellent' => 10], 'Style' => ['Weak' => 0, 'Strong' => 4]]);
        return $assign;
    }

    /**
     * Copy an assignment with a rubric to another course: hidden, placed, rubric kept, journaled.
     */
    public function test_copy_assignment_with_rubric() {
        global $DB;
        $assign = $this->assign_with_rubric();
        $existing = $this->getDataGenerator()->create_module('page', ['course' => $this->target->id, 'section' => 2]);
        $this->login_ws_user(self::CAPS);

        $result = $this->copy($assign->cmid, 2, $existing->cmid);
        $newcmid = self::cmid($result);
        $cm = get_coursemodule_from_id('assign', $newcmid, $this->target->id, false, MUST_EXIST);
        $this->assertEquals(0, $cm->visible);
        $this->assertEquals('Report', $cm->name);
        $this->assertEquals(1894000000, $DB->get_field('assign', 'duedate', ['id' => $cm->instance]));
        $sequence = $DB->get_field('course_sections', 'sequence', ['course' => $this->target->id, 'section' => 2]);
        $this->assertEquals($newcmid . ',' . $existing->cmid, $sequence);

        $area = $DB->get_record('grading_areas', ['contextid' => \context_module::instance($newcmid)->id,
            'component' => 'mod_assign', 'areaname' => 'submissions'], '*', MUST_EXIST);
        $this->assertEquals('rubric', $area->activemethod);
        $definition = $DB->get_record('grading_definitions', ['areaid' => $area->id], '*', MUST_EXIST);
        $this->assertEquals(2, $DB->count_records('gradingform_rubric_criteria', ['definitionid' => $definition->id]));
        $this->assertEquals(5, $DB->count_records_sql('SELECT COUNT(1) FROM {gradingform_rubric_levels} l
            JOIN {gradingform_rubric_criteria} c ON c.id = l.criterionid WHERE c.definitionid = ?', [$definition->id]));

        $this->assertEquals(['exists' => 0, 'cmid' => null, 'binitemid' => null], json_decode($result['before'], true));
        $entry = $this->journal_entry($result['journalid']);
        $this->assertSame('copy_activity', $entry['kind']);
        $this->assertEquals(1, $entry['createdbyplugin']);
        $this->assertEquals($newcmid, $entry['cmid']);
        $this->assertEquals($this->target->id, $entry['courseid']);
        $this->assertEquals($assign->cmid, $entry['target']['sourcecmid']);
        // The source is untouched.
        $this->assertEquals(1, $DB->get_field('course_modules', 'visible', ['id' => $assign->cmid]));
    }

    /**
     * Without moodle/course:update, restore gives the caller a role in the target course: warned.
     */
    public function test_copy_restorer_role_warning() {
        global $DB;
        $assign = $this->assign_with_rubric();
        $this->login_ws_user(self::CAPS);
        $this->remove_capability('moodle/course:update');
        $result = $this->copy($assign->cmid);
        $this->assertSame('restorerrole', $result['warnings'][0]['code']);
        $this->assertTrue($DB->record_exists('role_assignments', ['userid' => $this->wsuser->id,
            'contextid' => \context_course::instance($this->target->id)->id]));
    }

    /**
     * Copy a workshop with an accumulative assessment form: the form's aspects come along.
     */
    public function test_copy_workshop_with_assessment_form() {
        global $DB;
        $workshop = $this->getDataGenerator()->create_module('workshop', ['course' => $this->source->id,
            'strategy' => 'accumulative']);
        foreach ([1, 2, 3] as $sort) {
            $DB->insert_record('workshopform_accumulative', (object)['workshopid' => $workshop->id, 'sort' => $sort,
                'description' => 'Aspect ' . $sort, 'descriptionformat' => FORMAT_HTML, 'grade' => 10, 'weight' => 1]);
        }
        $this->login_ws_user(self::CAPS);

        $newcmid = self::cmid($this->copy($workshop->cmid));
        $instance = $DB->get_field('course_modules', 'instance', ['id' => $newcmid]);
        $this->assertEquals('accumulative', $DB->get_field('workshop', 'strategy', ['id' => $instance]));
        $this->assertEquals(3, $DB->count_records('workshopform_accumulative', ['workshopid' => $instance]));
        $this->assertEquals(0, $DB->get_field('course_modules', 'visible', ['id' => $newcmid]));
    }

    /**
     * Capabilities and allowlist for a copy: source and target.
     */
    public function test_copy_gate() {
        $assign = $this->assign_with_rubric();
        $this->login_ws_user(self::CAPS);
        foreach (['moodle/backup:backuptargetimport', 'moodle/restore:restoretargetimport',
                'moodle/course:manageactivities', 'local/paatshala:use'] as $capability) {
            $this->remove_capability($capability);
            $this->assert_error('nopermissions', function() use ($assign) {
                $this->copy($assign->cmid);
            });
            assign_capability($capability, CAP_ALLOW, $this->wsroleid, \context_system::instance()->id);
            accesslib_clear_all_caches_for_unit_testing();
        }
        set_config('allowedcourses', (string)$this->target->id, 'local_paatshala');
        $this->assert_error('coursenotallowed', function() use ($assign) {
            $this->copy($assign->cmid);
        });
        set_config('allowedcourses', (string)$this->source->id, 'local_paatshala');
        $this->assert_error('coursenotallowed', function() use ($assign) {
            $this->copy($assign->cmid);
        });
        set_config('allowedcourses', '*', 'local_paatshala');
        $this->assert_error('invalidparameter', function() use ($assign) {
            $this->copy($assign->cmid, 9);
        });
        set_config('enabled', 0, 'local_paatshala');
        $this->assert_error('disabled', function() use ($assign) {
            $this->copy($assign->cmid);
        });
    }

    /**
     * Undo of a copy deletes it into the recycle bin; redo restores it (new cmid); undo again.
     */
    public function test_copy_undo_redo() {
        global $DB;
        $assign = $this->assign_with_rubric();
        $this->login_ws_user(self::CAPS);
        $result = $this->copy($assign->cmid);
        $newcmid = self::cmid($result);

        $undone = $this->undo($result['journalid']);
        $this->assertFalse($DB->record_exists('course_modules', ['id' => $newcmid]));
        $after = json_decode($undone['after'], true);
        $this->assertEquals(0, $after['exists']);
        $this->assertTrue($DB->record_exists('tool_recyclebin_course', ['id' => $after['binitemid'],
            'courseid' => $this->target->id]));

        $redone = $this->undo($undone['journalid']);
        $restoredcmid = self::cmid($redone);
        $this->assertNotEquals($newcmid, $restoredcmid);
        $this->assertEquals('Report', $DB->get_field('assign', 'name',
            ['id' => $DB->get_field('course_modules', 'instance', ['id' => $restoredcmid])]));
        $this->assertFalse($DB->record_exists('tool_recyclebin_course', ['id' => $after['binitemid']]));
        $this->assertEquals(1, $this->journal_entry($redone['journalid'])['createdbyplugin']);

        // The restored copy can be deleted again, through delete_created.
        $deleted = delete_created::execute($restoredcmid);
        $this->assertFalse($DB->record_exists('course_modules', ['id' => $restoredcmid]));
        $this->assertEquals($redone['journalid'], $this->journal_entry($deleted['journalid'])['undoof']);
    }

    /**
     * Deletion refused with student data, with the bin off, and for what the plugin did not create.
     */
    public function test_delete_guards() {
        global $DB;
        $assign = $this->assign_with_rubric();
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $this->target->id, 'student');
        $this->login_ws_user(self::CAPS);
        $result = $this->copy($assign->cmid);
        $newcmid = self::cmid($result);
        $instance = $DB->get_field('course_modules', 'instance', ['id' => $newcmid]);

        // A submission that is not "new".
        $DB->insert_record('assign_submission', (object)['assignment' => $instance, 'userid' => $student->id,
            'timecreated' => time(), 'timemodified' => time(), 'status' => ASSIGN_SUBMISSION_STATUS_SUBMITTED,
            'attemptnumber' => 0, 'latest' => 1, 'groupid' => 0]);
        $e = $this->assert_error('studentdata', function() use ($newcmid) {
            delete_created::execute($newcmid);
        });
        $this->assertEquals(1, json_decode($e->debuginfo, true)['submissions']);
        $DB->delete_records('assign_submission', ['assignment' => $instance]);

        // A final grade.
        $item = \grade_item::fetch(['itemtype' => 'mod', 'itemmodule' => 'assign', 'iteminstance' => $instance,
            'courseid' => $this->target->id]);
        $DB->insert_record('grade_grades', (object)['itemid' => $item->id, 'userid' => $student->id,
            'finalgrade' => 50, 'rawgrade' => 50]);
        $e = $this->assert_error('studentdata', function() use ($newcmid) {
            delete_created::execute($newcmid);
        });
        $this->assertEquals(1, json_decode($e->debuginfo, true)['grades']);
        $DB->delete_records('grade_grades', ['itemid' => $item->id]);

        set_config('coursebinenable', 0, 'tool_recyclebin');
        $this->assert_error('recyclebindisabled', function() use ($result) {
            $this->undo($result['journalid']);
        });
        set_config('coursebinenable', 1, 'tool_recyclebin');

        $this->assert_error('notcreatedbyplugin', function() use ($assign) {
            delete_created::execute($assign->cmid);
        });
        $this->remove_capability('moodle/course:manageactivities');
        $this->assert_error('nopermissions', function() use ($newcmid) {
            delete_created::execute($newcmid);
        });
        $this->assertTrue($DB->record_exists('course_modules', ['id' => $newcmid]));
    }

    /**
     * Student data counts for each module type the plugin knows run on this database.
     */
    public function test_studentdata_queries() {
        $types = array_merge(local\studentdata::NONE, array_keys(local\studentdata::TABLES));
        foreach (['assign', 'quiz', 'workshop', 'forum', 'feedback', 'choice', 'data', 'glossary', 'lesson', 'page',
                'url', 'label'] as $modname) {
            $this->assertContains($modname, $types);
            $module = $this->getDataGenerator()->create_module($modname, ['course' => $this->target->id]);
            $cm = get_coursemodule_from_id($modname, $module->cmid, 0, false, MUST_EXIST);
            $this->assertSame([], local\studentdata::module($cm), $modname);
        }
        $this->assertSame([], local\studentdata::course($this->target->id));
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $this->target->id, 'student');
        $this->assertSame(['students' => 1], local\studentdata::course($this->target->id));
    }

    /**
     * Create a page, an assignment, a URL and a label; hidden by default; undo deletes.
     */
    public function test_create_activities() {
        global $DB;
        $this->login_ws_user(self::CAPS);

        $page = $this->create('page', ['name' => 'Notes', 'intro' => '<p>About</p>', 'content' => '<p>Body</p>']);
        $cm = get_coursemodule_from_id('page', self::cmid($page), $this->target->id, false, MUST_EXIST);
        $record = $DB->get_record('page', ['id' => $cm->instance]);
        $this->assertEquals('Notes', $record->name);
        $this->assertEquals('<p>Body</p>', $record->content);
        $this->assertEquals('<p>About</p>', $record->intro);
        $this->assertEquals(0, $cm->visible);
        $this->assertEquals('create_activity', $this->journal_entry($page['journalid'])['kind']);

        $assign = $this->create('assign', ['name' => 'Essay', 'duedate' => 1894000000, 'cutoffdate' => 1895000000,
            'gradingduedate' => 1896000000,
            'assignsubmission_onlinetext_enabled' => 1, 'assignsubmission_onlinetext_wordlimit_enabled' => 1,
            'assignsubmission_onlinetext_wordlimit' => 300, 'assignsubmission_file_enabled' => 0, 'visible' => 1], 2);
        $cm = get_coursemodule_from_id('assign', self::cmid($assign), $this->target->id, false, MUST_EXIST);
        $this->assertEquals(1, $cm->visible);
        $this->assertEquals(2, $DB->get_field('course_sections', 'section', ['id' => $cm->section]));
        $assignobj = new \assign(\context_module::instance($cm->id), $cm, $this->target);
        $onlinetext = $assignobj->get_submission_plugin_by_type('onlinetext');
        $this->assertTrue((bool)$onlinetext->is_enabled());
        $this->assertEquals(300, $onlinetext->get_config('wordlimit'));
        $this->assertFalse((bool)$assignobj->get_submission_plugin_by_type('file')->is_enabled());
        $this->assertEquals(1894000000, $assignobj->get_instance()->duedate);
        $this->assertTrue($DB->record_exists('event', ['modulename' => 'assign', 'instance' => $cm->instance,
            'eventtype' => ASSIGN_EVENT_TYPE_DUE]));

        $url = $this->create('url', ['name' => 'Site', 'externalurl' => 'https://example.com/']);
        $this->assertEquals('https://example.com/', $DB->get_field('url', 'externalurl',
            ['id' => $DB->get_field('course_modules', 'instance', ['id' => self::cmid($url)])]));
        $label = $this->create('label', ['intro' => '<p>Read this</p>']);
        $this->assertTrue($DB->record_exists('course_modules', ['id' => self::cmid($label)]));

        $this->undo($page['journalid']);
        $this->assertFalse($DB->record_exists('course_modules', ['id' => self::cmid($page)]));
    }

    /**
     * Creation refusals: type, whitelist, the form's validation, section, capability.
     */
    public function test_create_refusals() {
        $this->login_ws_user(self::CAPS);
        $this->assert_error('invalidparameter', function() {
            $this->create('forum', ['name' => 'F']);
        });
        $this->assert_error('invalidparameter', function() {
            $this->create('assign', ['name' => 'A', 'teamsubmission' => 1]);
        });
        $this->assert_error('invalidsettings', function() {
            $this->create('assign', ['name' => 'A', 'duedate' => 1894000000, 'cutoffdate' => 1893000000]);
        });
        $this->assert_error('invalidsettings', function() {
            $this->create('url', ['name' => 'U', 'externalurl' => '']);
        });
        $this->assert_error('invalidparameter', function() {
            $this->create('page', ['name' => 'P'], 9);
        });
        // As on the course page: can_add_moduleinfo() reports the type as disabled.
        $this->remove_capability('mod/page:addinstance');
        $this->assert_error('moduledisable', function() {
            $this->create('page', ['name' => 'P']);
        });
    }

    /**
     * Recycle bin: list an activity deleted in Moodle, restore it (journaled, createdbyplugin),
     * undo deletes it again; listing needs its capability.
     */
    public function test_recyclebin_list_and_restore() {
        global $DB;
        $page = $this->getDataGenerator()->create_module('page', ['course' => $this->target->id, 'name' => 'Old']);
        course_delete_module($page->cmid);
        $this->login_ws_user(self::CAPS);

        $list = external_api::clean_returnvalue(recyclebin_list::execute_returns(),
            recyclebin_list::execute($this->target->id));
        $this->assertTrue($list['enabled']);
        $this->assertCount(1, $list['items']);
        $item = $list['items'][0];
        $this->assertEquals('Old', $item['name']);
        $this->assertEquals('page', $item['modname']);

        $restored = external_api::clean_returnvalue(recyclebin_restore::execute_returns(),
            recyclebin_restore::execute($item['id'], 'course'));
        $cmid = self::cmid($restored);
        $this->assertEquals('Old', get_coursemodule_from_id('page', $cmid, 0, false, MUST_EXIST)->name);
        $entry = $this->journal_entry($restored['journalid']);
        $this->assertSame('recyclebin_restore_activity', $entry['kind']);
        $this->assertEquals(1, $entry['createdbyplugin']);
        $this->assertEquals(['exists' => 0, 'cmid' => null, 'binitemid' => $item['id']], $entry['beforestate']);

        $this->undo($restored['journalid']);
        $this->assertFalse($DB->record_exists('course_modules', ['id' => $cmid]));
        $this->assertCount(1, recyclebin_list::execute($this->target->id)['items']);

        $this->remove_capability('tool/recyclebin:viewitems');
        $this->assert_error('nopermissions', function() {
            recyclebin_list::execute($this->target->id);
        });
        $this->remove_capability('tool/recyclebin:restoreitems');
        $items = $DB->get_records('tool_recyclebin_course', ['courseid' => $this->target->id]);
        $this->assert_error('nopermissions', function() use ($items) {
            recyclebin_restore::execute(reset($items)->id, 'course');
        });
    }
}

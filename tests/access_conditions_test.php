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
 * Tests for completion and grade access restrictions.
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
require_once($CFG->libdir . '/completionlib.php');

use external_api;
use local_paatshala\external\get_availability;
use local_paatshala\external\info;
use local_paatshala\external\set_access;
use local_paatshala\external\undo;

/**
 * Tests for the completion and grade parts of local_paatshala_set_access.
 *
 * @package    local_paatshala
 * @copyright  2026 local_paatshala contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class access_conditions_test extends testcase {

    /** @var \stdClass */
    protected $course;

    /** @var \stdClass[] Users: done (completed the video), notdone, graded70, graded30, editing, teacher. */
    protected $users = [];

    /** @var \stdClass Batch group. */
    protected $june;

    /** @var \stdClass Video page in section 1, manual completion. */
    protected $video;

    /** @var \stdClass Assignment in section 1, out of 100, completion on a grade. */
    protected $assign;

    /** @var \stdClass Project brief page in section 3. */
    protected $brief;

    /** @var \stdClass Section 2 record (Session 02). */
    protected $session2;

    /** @var \stdClass Web service user. */
    protected $wsuser;

    /**
     * Session 01 (a video page and an assignment), Session 02, and a project brief in section 3.
     */
    protected function setUp(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('enableavailability', 1);
        set_config('enablecompletion', 1);
        $gen = $this->getDataGenerator();
        $this->course = $gen->create_course(['numsections' => 3, 'enablecompletion' => 1]);
        foreach (['done', 'notdone', 'graded70', 'graded30'] as $name) {
            $this->users[$name] = $gen->create_and_enrol($this->course, 'student');
        }
        $this->users['editing'] = $gen->create_and_enrol($this->course, 'editingteacher');
        $this->users['teacher'] = $gen->create_and_enrol($this->course, 'teacher');
        $this->june = $gen->create_group(['courseid' => $this->course->id, 'name' => 'JUN 25 batch']);
        $gen->create_group_member(['groupid' => $this->june->id, 'userid' => $this->users['done']->id]);
        $gen->create_group_member(['groupid' => $this->june->id, 'userid' => $this->users['notdone']->id]);

        $this->video = $gen->create_module('page', ['course' => $this->course->id, 'section' => 1,
            'name' => 'Session 01 video 1', 'completion' => COMPLETION_TRACKING_MANUAL]);
        $this->assign = $gen->create_module('assign', ['course' => $this->course->id, 'section' => 1, 'grade' => 100,
            'name' => 'Session 01 task', 'completion' => COMPLETION_TRACKING_AUTOMATIC, 'completionusegrade' => 1]);
        $this->brief = $gen->create_module('page', ['course' => $this->course->id, 'section' => 3,
            'name' => 'Project brief']);
        $this->session2 = $DB->get_record('course_sections', ['course' => $this->course->id, 'section' => 2]);

        // The video ticked by one student; grades for two.
        $cm = get_fast_modinfo($this->course)->get_cm($this->video->cmid);
        (new \completion_info($this->course))->update_state($cm, COMPLETION_COMPLETE, $this->users['done']->id);
        $instance = new \assign(\context_module::instance($this->assign->cmid), null, null);
        foreach (['graded70' => 70, 'graded30' => 30] as $who => $grade) {
            $instance->save_grade($this->users[$who]->id, (object)['grade' => $grade, 'attemptnumber' => -1,
                'addattempt' => 0, 'sendstudentnotifications' => 0]);
        }
        $this->enable_plugin('*');
        $this->wsuser = $this->login_ws_user(['moodle/course:update', 'moodle/course:manageactivities',
            'moodle/course:ignoreavailabilityrestrictions']);
    }

    /**
     * Call set_access and clean the result.
     *
     * @param array $args sectionid or cmid; completion, grade, group as the parameters; expected
     * @return array
     */
    protected function set(array $args): array {
        return external_api::clean_returnvalue(set_access::execute_returns(), set_access::execute(
            $this->course->id, $args['sectionid'] ?? 0, $args['cmid'] ?? 0, $args['group'] ?? [], $args['from'] ?? [],
            $args['until'] ?? [], $args['expected'] ?? null, $args['completion'] ?? [], $args['grade'] ?? []));
    }

    /**
     * A completion part.
     *
     * @param array|null $conditions cmid => state; null to remove.
     * @param string $whenunmet
     * @return array
     */
    protected function completion($conditions, string $whenunmet = 'hide'): array {
        if ($conditions === null) {
            return ['action' => 'remove'];
        }
        $list = [];
        foreach ($conditions as $cmid => $state) {
            $list[] = ['cmid' => $cmid, 'state' => $state];
        }
        return ['action' => 'set', 'conditions' => $list, 'whenunmet' => $whenunmet];
    }

    /**
     * A grade part.
     *
     * @param array|null $conditions
     * @param string $whenunmet
     * @return array
     */
    protected function grade($conditions, string $whenunmet = 'hide'): array {
        return $conditions === null ? ['action' => 'remove'] :
            ['action' => 'set', 'conditions' => $conditions, 'whenunmet' => $whenunmet];
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
     * Stored availability.
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
     * What a user sees of a section: [uservisible, availableinfo].
     *
     * @param string $who
     * @param int $sectionnum
     * @return array
     */
    protected function section_seen(string $who, int $sectionnum): array {
        get_fast_modinfo(0, 0, true);
        $section = get_fast_modinfo($this->course, $this->users[$who]->id)->get_section_info($sectionnum);
        return [$section->uservisible, (string)$section->availableinfo];
    }

    /**
     * What a user sees of an activity: [uservisible, availableinfo, on the course page].
     *
     * @param string $who
     * @param int $cmid
     * @return array
     */
    protected function cm_seen(string $who, int $cmid): array {
        get_fast_modinfo(0, 0, true);
        $cm = get_fast_modinfo($this->course, $this->users[$who]->id)->get_cm($cmid);
        return [$cm->uservisible, (string)$cm->availableinfo, $cm->is_visible_on_course_page()];
    }

    /**
     * Session 02 opens once the video is marked done; hidden entirely from the others, or shown with
     * the message; teachers always have access.
     */
    public function test_section_after_completion() {
        $video = $this->video->cmid;
        $result = $this->set(['sectionid' => $this->session2->id, 'completion' => $this->completion([$video => 'complete'])]);
        $this->assertSame('{"op":"&","c":[{"type":"completion","cm":' . $video . ',"e":1}],"showc":[false]}',
            $this->stored('course_sections', $this->session2->id));
        $this->assertSame(['availability' => ''], json_decode($result['before'], true));
        $this->assertFalse($result['notifies_students']);
        $this->assertSame([true, ''], $this->section_seen('done', 2));
        $this->assertSame([false, ''], $this->section_seen('notdone', 2));
        $this->assertTrue($this->section_seen('editing', 2)[0]);
        $this->assertTrue($this->section_seen('teacher', 2)[0]);

        $this->set(['sectionid' => $this->session2->id, 'completion' => $this->completion([$video => 'complete'],
            'show')]);
        list($visible, $info) = $this->section_seen('notdone', 2);
        $this->assertFalse($visible);
        $this->assertStringContainsString('AVAILABILITY_CMNAME_' . $video . '/', $info);

        // Ticking it opens the section.
        get_fast_modinfo(0, 0, true);
        $cm = get_fast_modinfo($this->course)->get_cm($video);
        (new \completion_info(get_course($this->course->id)))->update_state($cm, COMPLETION_COMPLETE,
            $this->users['notdone']->id);
        $this->assertSame([true, ''], $this->section_seen('notdone', 2));

        // Must not be complete: the other way round.
        $this->set(['sectionid' => $this->session2->id, 'completion' => $this->completion([$video => 'incomplete'])]);
        $this->assertFalse($this->section_seen('done', 2)[0]);
        $this->assertTrue($this->section_seen('graded70', 2)[0]);
    }

    /**
     * An activity restricted on completion: hidden entirely, or shown with the message.
     */
    public function test_activity_after_completion() {
        $brief = $this->brief->cmid;
        $this->set(['cmid' => $brief, 'completion' => $this->completion([$this->video->cmid => 'complete'])]);
        $this->assertTrue($this->cm_seen('done', $brief)[0]);
        $this->assertSame([false, '', false], $this->cm_seen('notdone', $brief));
        $this->assertTrue($this->cm_seen('teacher', $brief)[0]);
        $this->set(['cmid' => $brief, 'completion' => $this->completion([$this->video->cmid => 'complete'], 'show')]);
        list($visible, $info, $onpage) = $this->cm_seen('notdone', $brief);
        $this->assertFalse($visible);
        $this->assertStringContainsString('AVAILABILITY_CMNAME_' . $this->video->cmid . '/', $info);
        $this->assertTrue($onpage);
    }

    /**
     * The project brief opens with a grade of at least 50% in the assignment.
     */
    public function test_activity_after_grade() {
        global $DB;
        $brief = $this->brief->cmid;
        $this->set(['cmid' => $brief, 'grade' => $this->grade([['cmid' => $this->assign->cmid, 'min' => 50]])]);
        $itemid = $DB->get_field('grade_items', 'id', ['itemtype' => 'mod', 'itemmodule' => 'assign',
            'iteminstance' => $this->assign->id]);
        $this->assertSame('{"op":"&","c":[{"type":"grade","id":' . $itemid . ',"min":50}],"showc":[false]}',
            $this->stored('course_modules', $brief));
        $this->assertTrue($this->cm_seen('graded70', $brief)[0]);
        $this->assertSame([false, '', false], $this->cm_seen('graded30', $brief));
        $this->assertFalse($this->cm_seen('notdone', $brief)[0]);
        $this->assertTrue($this->cm_seen('editing', $brief)[0]);
        $this->assertTrue($this->cm_seen('teacher', $brief)[0]);

        // Shown with the message; a range by grade item id; "any grade".
        $this->set(['cmid' => $brief, 'grade' => $this->grade([['gradeitemid' => (int)$itemid, 'min' => 20,
            'max' => 50.5]], 'show')]);
        $this->assertTrue($this->cm_seen('graded30', $brief)[0]);
        list($visible, $info, $onpage) = $this->cm_seen('graded70', $brief);
        $this->assertFalse($visible);
        $this->assertNotSame('', $info);
        $this->assertTrue($onpage);
        $this->set(['cmid' => $brief, 'grade' => $this->grade([['cmid' => $this->assign->cmid]])]);
        $this->assertTrue($this->cm_seen('graded30', $brief)[0]);
        $this->assertTrue($this->cm_seen('graded70', $brief)[0]);
        $this->assertFalse($this->cm_seen('done', $brief)[0]);
    }

    /**
     * Completion and group in one tree: both must hold.
     */
    public function test_completion_and_group() {
        $result = $this->set(['sectionid' => $this->session2->id, 'group' => ['action' => 'set',
            'groupids' => [$this->june->id]], 'completion' => $this->completion([$this->video->cmid => 'complete'])]);
        $tree = json_decode($this->stored('course_sections', $this->session2->id));
        $this->assertSame('group', $tree->c[0]->type);
        $this->assertSame('completion', $tree->c[1]->type);
        $this->assertTrue($this->section_seen('done', 2)[0]);
        // In the group, not done.
        $this->assertFalse($this->section_seen('notdone', 2)[0]);
        // Not in the group (no completion either).
        $this->assertFalse($this->section_seen('graded70', 2)[0]);
        $this->assertSame(1, count(journal::entries($this->course->id, 0, 'section_availability')));
        $this->assertSame((int)$result['journalid'], (int)journal::entries($this->course->id)[0]->id);
    }

    /**
     * Several parts in one write and one journal entry; undo to the exact before; redo; refused after
     * a later change; nochange.
     */
    public function test_journal_undo_redo() {
        global $DB;
        $stored = '{"showc": [true], "op": "&", "c": [{"t": 1500000000, "type": "date", "d": ">="}]}';
        $DB->set_field('course_modules', 'availability', $stored, ['id' => $this->brief->cmid]);
        rebuild_course_cache($this->course->id, true);
        $args = ['cmid' => $this->brief->cmid, 'completion' => $this->completion([$this->video->cmid => 'complete',
            $this->assign->cmid => 'pass']), 'grade' => $this->grade([['cmid' => $this->assign->cmid, 'min' => 40]],
            'show'), 'expected' => json_encode(['availability' => $stored])];
        $result = $this->set($args);
        $entry = $this->journal_entry($result['journalid']);
        $this->assertSame('module_availability', $entry['kind']);
        $this->assertSame(['availability' => $stored], $entry['beforestate']);
        $tree = json_decode($this->stored('course_modules', $this->brief->cmid));
        $this->assertCount(3, $tree->c);
        $this->assertSame('&', $tree->c[1]->op);
        $this->assertSame([true, false, true], $tree->showc);

        $undone = $this->undo($result['journalid']);
        $this->assertSame($stored, $this->stored('course_modules', $this->brief->cmid));
        $redone = $this->undo($undone['journalid']);
        $this->assertSame(json_decode($result['after'], true)['availability'],
            $this->stored('course_modules', $this->brief->cmid));
        // Setting the same again: nothing written.
        unset($args['expected']);
        $same = $this->set($args);
        $this->assertSame(0, $same['journalid']);
        $this->assertSame('nochange', $same['warnings'][0]['code']);
        // A later change blocks undoing the first.
        $this->set(['cmid' => $this->brief->cmid, 'grade' => $this->grade(null)]);
        $this->assert_error('conflict', function() use ($redone) {
            $this->undo($redone['journalid']);
        });
        // Remove everything managed: the date stays.
        $this->set(['cmid' => $this->brief->cmid, 'completion' => $this->completion(null), 'from' => ['action' => 'remove']]);
        $this->assertNull($this->stored('course_modules', $this->brief->cmid));
    }

    /**
     * The checks of the restriction forms, site and course settings, and the direct deadlocks.
     */
    public function test_checks() {
        global $DB;
        $gen = $this->getDataGenerator();
        $nocompletion = $gen->create_module('page', ['course' => $this->course->id, 'section' => 3]);
        $other = $gen->create_course(['enablecompletion' => 1]);
        $foreign = $gen->create_module('page', ['course' => $other->id, 'completion' => COMPLETION_TRACKING_MANUAL]);
        $foreignassign = $gen->create_module('assign', ['course' => $other->id]);
        $foreignitem = (int)$DB->get_field('grade_items', 'id', ['itemtype' => 'mod', 'itemmodule' => 'assign',
            'iteminstance' => $foreignassign->id]);
        $section = $this->session2->id;
        $brief = $this->brief->cmid;

        $e = $this->assert_error('nocompletion', function() use ($section, $nocompletion) {
            $this->set(['sectionid' => $section, 'completion' => $this->completion([$nocompletion->cmid => 'complete'])]);
        });
        $this->assertStringContainsString('local_paatshala_set_completion', $e->getMessage());
        $refused = [
            // Self-reference: its own completion, its own grade.
            ['cmid' => $this->video->cmid, 'completion' => $this->completion([$this->video->cmid => 'complete'])],
            ['cmid' => $this->assign->cmid, 'grade' => $this->grade([['cmid' => $this->assign->cmid]])],
            // Another course's activity and grade item.
            ['sectionid' => $section, 'completion' => $this->completion([$foreign->cmid => 'complete'])],
            ['sectionid' => $section, 'grade' => $this->grade([['gradeitemid' => $foreignitem]])],
            ['sectionid' => $section, 'grade' => $this->grade([['cmid' => $foreignassign->cmid]])],
            // Pass and fail need a completion grade condition on the target.
            ['sectionid' => $section, 'completion' => $this->completion([$this->video->cmid => 'pass'])],
            // A page has no grade item.
            ['sectionid' => $section, 'grade' => $this->grade([['cmid' => $this->video->cmid]])],
            // Bad ranges.
            ['sectionid' => $section, 'grade' => $this->grade([['cmid' => $this->assign->cmid, 'min' => 80, 'max' => 20]])],
        ];
        foreach ($refused as $index => $args) {
            $this->assert_error('invalidsettings', function() use ($args) {
                $this->set($args);
            });
        }
        $this->assertNull($this->stored('course_sections', $section));
        try {
            $this->set(['sectionid' => $section, 'completion' => ['action' => 'set', 'conditions' => [
                ['cmid' => $this->video->cmid, 'state' => 'done']]]]);
            $this->fail('Expected invalid_parameter_exception');
        } catch (\invalid_parameter_exception $e) {
            $this->assertStringContainsString('state must be', $e->getMessage());
        }
        try {
            $this->set(['sectionid' => $section, 'grade' => $this->grade([['cmid' => $this->assign->cmid,
                'gradeitemid' => 5]])]);
            $this->fail('Expected invalid_parameter_exception');
        } catch (\invalid_parameter_exception $e) {
            $this->assertStringContainsString('exactly one of cmid and gradeitemid', $e->getMessage());
        }
        $this->assertNull($this->stored('course_sections', $section));

        // Pass is fine on an activity whose completion requires a grade.
        $this->set(['sectionid' => $section, 'completion' => $this->completion([$this->assign->cmid => 'pass'])]);

        // Condition plugins and completion off.
        set_config('disabled', 1, 'availability_completion');
        \core_plugin_manager::reset_caches();
        $e = $this->assert_error('availabilitydisabled', function() use ($brief) {
            $this->set(['cmid' => $brief, 'completion' => $this->completion([$this->video->cmid => 'complete'])]);
        });
        $this->assertStringContainsString('availability_completion', $e->getMessage());
        unset_config('disabled', 'availability_completion');
        set_config('disabled', 1, 'availability_grade');
        \core_plugin_manager::reset_caches();
        $e = $this->assert_error('availabilitydisabled', function() use ($brief) {
            $this->set(['cmid' => $brief, 'grade' => $this->grade([['cmid' => $this->assign->cmid]])]);
        });
        $this->assertStringContainsString('availability_grade', $e->getMessage());
        unset_config('disabled', 'availability_grade');
        \core_plugin_manager::reset_caches();
        $DB->set_field('course', 'enablecompletion', 0, ['id' => $this->course->id]);
        $this->assert_error('completiondisabled', function() use ($brief) {
            $this->set(['cmid' => $brief, 'completion' => $this->completion([$this->video->cmid => 'complete'])]);
        });
        // Grade conditions do not need completion.
        $this->set(['cmid' => $brief, 'grade' => $this->grade([['cmid' => $this->assign->cmid]])]);
        $DB->set_field('course', 'enablecompletion', 1, ['id' => $this->course->id]);

        // Deadlocks: a section on an activity inside it.
        $section1 = $DB->get_field('course_sections', 'id', ['course' => $this->course->id, 'section' => 1]);
        $e = $this->assert_error('deadlock', function() use ($section1) {
            $this->set(['sectionid' => $section1, 'completion' => $this->completion([$this->video->cmid => 'complete'],
                'show')]);
        });
        $this->assertStringContainsString('inside this section', $e->getMessage());
        // Must not be complete is no deadlock.
        $this->set(['sectionid' => $section1, 'completion' => $this->completion([$this->video->cmid => 'incomplete'])]);
        $this->set(['sectionid' => $section1, 'completion' => $this->completion(null)]);
        // A grade in an activity inside it: a warning (a teacher can still grade it).
        $result = $this->set(['sectionid' => $section1, 'grade' => $this->grade([['cmid' => $this->assign->cmid]])]);
        $this->assertSame('gradedeadlock', $result['warnings'][0]['code']);
        $this->set(['sectionid' => $section1, 'grade' => $this->grade(null)]);
        // A direct cycle between two activities.
        $DB->set_field('course_modules', 'completion', COMPLETION_TRACKING_MANUAL, ['id' => $brief]);
        rebuild_course_cache($this->course->id, true);
        $this->set(['cmid' => $brief, 'grade' => $this->grade(null), 'completion' => $this->completion([
            $this->video->cmid => 'complete'])]);
        $e = $this->assert_error('deadlock', function() use ($brief) {
            $this->set(['cmid' => $this->video->cmid, 'completion' => $this->completion([$brief => 'complete'])]);
        });
        $this->assertStringContainsString('Project brief', $e->getMessage());
        // A cycle through a section: section 1 on the brief, which needs the video in section 1.
        $this->assert_error('deadlock', function() use ($section1, $brief) {
            $this->set(['sectionid' => $section1, 'completion' => $this->completion([$brief => 'complete'])]);
        });

        // Expected mismatch, gate, capability.
        $this->assert_error('conflict', function() use ($section) {
            $this->set(['sectionid' => $section, 'completion' => $this->completion([$this->video->cmid => 'complete']),
                'expected' => json_encode(['availability' => ''])]);
        });
        set_config('enabled', 0, 'local_paatshala');
        $this->assert_error('disabled', function() use ($section) {
            $this->set(['sectionid' => $section, 'completion' => $this->completion(null)]);
        });
        set_config('enabled', 1, 'local_paatshala');
        set_config('allowedcourses', (string)$other->id, 'local_paatshala');
        $this->assert_error('coursenotallowed', function() use ($section) {
            $this->set(['sectionid' => $section, 'completion' => $this->completion(null)]);
        });
        set_config('allowedcourses', '*', 'local_paatshala');
        $this->remove_capability('moodle/course:update');
        try {
            $this->set(['sectionid' => $section, 'completion' => $this->completion(null)]);
            $this->fail('Expected required_capability_exception');
        } catch (\required_capability_exception $e) {
            $this->assertInstanceOf(\required_capability_exception::class, $e);
        }
    }

    /**
     * Reads: names and settings of what completion and grade conditions point at, and which parts are
     * changeable.
     */
    public function test_reads() {
        global $DB;
        $video = $this->video->cmid;
        $this->set(['cmid' => $this->brief->cmid, 'completion' => $this->completion([$video => 'complete']),
            'grade' => $this->grade([['cmid' => $this->assign->cmid, 'min' => 50]])]);
        $item = $DB->get_record('grade_items', ['itemtype' => 'mod', 'itemmodule' => 'assign',
            'iteminstance' => $this->assign->id]);
        $read = external_api::clean_returnvalue(get_availability::execute_returns(),
            get_availability::execute($this->course->id, 0, $this->brief->cmid));
        $this->assertTrue($read['completionenabled']);
        $this->assertTrue($read['gradeenabled']);
        list($completion, $grade) = $read['conditions'];
        $this->assertSame(['cmid' => (int)$video, 'name' => 'Session 01 video 1', 'modname' => 'page', 'completion' => 1,
            'completiongradeitemnumber' => -1, 'exists' => true], $completion['cm']);
        $this->assertSame(['id' => (int)$item->id, 'name' => 'Session 01 task', 'itemtype' => 'mod',
            'cmid' => (int)$this->assign->cmid, 'grademax' => 100.0, 'gradepass' => 0.0, 'exists' => true],
            $grade['gradeitem']);
        $this->assertArrayNotHasKey('gradeitem', $completion);
        $parts = array_column($read['parts'], null, 'part');
        $this->assertSame(['group', 'from', 'until', 'completion', 'grade'], array_keys($parts));
        $this->assertTrue($parts['completion']['present']);
        $this->assertTrue($parts['grade']['present']);
        $this->assertFalse($parts['group']['present']);
        foreach ($parts as $part) {
            $this->assertTrue($part['changeable'], $part['part']);
        }

        // A completion condition in an "any of": completion not changeable, the others are.
        $DB->set_field('course_modules', 'availability', '{"op":"&","c":[{"op":"|","c":[{"type":"completion","cm":' .
            $video . ',"e":1},{"type":"grade","id":99999}]}],"showc":[true]}', ['id' => $this->brief->cmid]);
        rebuild_course_cache($this->course->id, true);
        $read = external_api::clean_returnvalue(get_availability::execute_returns(),
            get_availability::execute($this->course->id, 0, $this->brief->cmid));
        $parts = array_column($read['parts'], null, 'part');
        $this->assertFalse($parts['completion']['changeable']);
        $this->assertStringContainsString('c[0].c[0] is a completion restriction', $parts['completion']['reason']);
        $this->assertFalse($parts['grade']['changeable']);
        $this->assertTrue($parts['group']['changeable']);
        $this->assertFalse($read['conditions'][1]['gradeitem']['exists']);

        // Course completion off: the completion part says so.
        $DB->set_field('course', 'enablecompletion', 0, ['id' => $this->course->id]);
        $read = external_api::clean_returnvalue(get_availability::execute_returns(),
            get_availability::execute($this->course->id, $this->session2->id, 0));
        $parts = array_column($read['parts'], null, 'part');
        $this->assertFalse($parts['completion']['changeable']);
        $this->assertStringContainsString('completion tracking is off', $parts['completion']['reason']);
        $this->assertTrue($parts['grade']['changeable']);

        // Info.
        $info = external_api::clean_returnvalue(info::execute_returns(), info::execute());
        $this->assertTrue($info['availabilitycompletion']);
        $this->assertTrue($info['availabilitygrade']);
        $this->assertTrue($info['enablecompletion']);
        foreach (['local_paatshala_get_completion', 'local_paatshala_get_course_completion',
                'local_paatshala_set_completion'] as $function) {
            $this->assertContains($function, $info['functions']);
        }
    }
}

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
 * Tests for activity completion settings.
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
require_once($CFG->libdir . '/gradelib.php');

use external_api;
use local_paatshala\external\get_completion;
use local_paatshala\external\get_course_completion;
use local_paatshala\external\set_completion;
use local_paatshala\external\undo;

/**
 * Tests for activity completion settings.
 *
 * local_paatshala_get_completion, _get_course_completion, _set_completion and undo of
 * module_completion.
 *
 * @package    local_paatshala
 * @copyright  2026 local_paatshala contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class completion_test extends testcase {

    /** @var \stdClass */
    protected $course;

    /** @var \stdClass[] Users: student, student2, editing, teacher. */
    protected $users = [];

    /** @var \stdClass Video page. */
    protected $page;

    /** @var \stdClass Web service user. */
    protected $wsuser;

    /**
     * A course with completion on, two students, two teachers and a page.
     */
    protected function setUp(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('enablecompletion', 1);
        set_config('enableavailability', 1);
        $gen = $this->getDataGenerator();
        $this->course = $gen->create_course(['numsections' => 3, 'enablecompletion' => 1]);
        foreach (['student', 'student2'] as $name) {
            $this->users[$name] = $gen->create_and_enrol($this->course, 'student');
        }
        $this->users['editing'] = $gen->create_and_enrol($this->course, 'editingteacher');
        $this->users['teacher'] = $gen->create_and_enrol($this->course, 'teacher');
        $this->page = $gen->create_module('page', ['course' => $this->course->id, 'section' => 1,
            'name' => 'Session 01 video 1']);
        $this->enable_plugin('*');
        $this->wsuser = $this->login_ws_user(['moodle/course:manageactivities', 'moodle/course:update',
            'moodle/course:ignoreavailabilityrestrictions']);
    }

    /**
     * Call set_completion and clean the result.
     *
     * @param int $cmid
     * @param array $fields
     * @param bool $reset
     * @param bool $force
     * @param array|null $expected
     * @return array
     */
    protected function set(int $cmid, array $fields, bool $reset = false, bool $force = false, $expected = null): array {
        return external_api::clean_returnvalue(set_completion::execute_returns(), set_completion::execute($cmid,
            json_encode($fields), $reset, $force, $expected === null ? null : json_encode($expected)));
    }

    /**
     * Call get_completion and clean the result.
     *
     * @param int $cmid
     * @return array
     */
    protected function get(int $cmid): array {
        return external_api::clean_returnvalue(get_completion::execute_returns(), get_completion::execute($cmid));
    }

    /**
     * Undo and clean the result.
     *
     * @param int $journalid
     * @param bool $reset
     * @param bool $force
     * @return array
     */
    protected function undo(int $journalid, bool $reset = false, bool $force = false): array {
        return external_api::clean_returnvalue(undo::execute_returns(), undo::execute($journalid, null, $reset, $force));
    }

    /**
     * The course module record.
     *
     * @param int $cmid
     * @return \stdClass
     */
    protected function cm(int $cmid): \stdClass {
        global $DB;
        return $DB->get_record('course_modules', ['id' => $cmid], '*', MUST_EXIST);
    }

    /**
     * Mark an activity complete (or not) for a user, as the manual tick box does.
     *
     * @param int $cmid
     * @param string $who
     * @param int $state
     */
    protected function tick(int $cmid, string $who, int $state = COMPLETION_COMPLETE) {
        get_fast_modinfo(0, 0, true);
        $cm = get_fast_modinfo($this->course)->get_cm($cmid);
        $completion = new \completion_info(get_course($this->course->id));
        $completion->update_state($cm, $state, $this->users[$who]->id);
    }

    /**
     * The user's completion state of an activity.
     *
     * @param int $cmid
     * @param string $who
     * @return int
     */
    protected function state(int $cmid, string $who): int {
        global $DB;
        return (int)$DB->get_field('course_modules_completion', 'completionstate',
            ['coursemoduleid' => $cmid, 'userid' => $this->users[$who]->id]);
    }

    /**
     * None -> manual and back on a page, a URL and an assignment: stored settings, journal, undo, redo.
     */
    public function test_manual_and_back() {
        $gen = $this->getDataGenerator();
        $url = $gen->create_module('url', ['course' => $this->course->id, 'section' => 1]);
        $assign = $gen->create_module('assign', ['course' => $this->course->id, 'section' => 1]);
        foreach ([$this->page->cmid, $url->cmid, $assign->cmid] as $cmid) {
            $this->assertSame(0, $this->get($cmid)['completion']);
            $result = $this->set($cmid, ['completion' => 'manual']);
            $this->assertEquals(COMPLETION_TRACKING_MANUAL, $this->cm($cmid)->completion);
            $this->assertSame(1, $this->get($cmid)['completion']);
            $this->assertFalse($result['notifies_students']);
            $this->assertTrue($result['undoable']);
            $this->assertSame([], $result['warnings']);
            $before = json_decode($result['before'], true);
            $after = json_decode($result['after'], true);
            $this->assertSame(0, $before['completion']);
            $this->assertSame(1, $after['completion']);
            $this->assertSame(0, $after['students']);
            $this->assertArrayNotHasKey('reset', $after);

            $entry = $this->journal_entry($result['journalid']);
            $this->assertSame('module_completion', $entry['kind']);
            $this->assertEquals($cmid, $entry['cmid']);
            $this->assertSame($cmid, $entry['target']['cmid']);
            $this->assertSame($before, $entry['beforestate']);

            // Undo, redo, and back to none by a set.
            $undone = $this->undo($result['journalid']);
            $this->assertEquals(COMPLETION_TRACKING_NONE, $this->cm($cmid)->completion);
            $this->undo($undone['journalid']);
            $this->assertEquals(COMPLETION_TRACKING_MANUAL, $this->cm($cmid)->completion);
            $this->set($cmid, ['completion' => 0]);
            $this->assertEquals(COMPLETION_TRACKING_NONE, $this->cm($cmid)->completion);
        }

        // A student can tick a manual activity.
        $this->set($this->page->cmid, ['completion' => 'manual']);
        $this->tick($this->page->cmid, 'student');
        $this->assertSame(COMPLETION_COMPLETE, $this->state($this->page->cmid, 'student'));
    }

    /**
     * Automatic with "must view" on a page: a view completes it.
     */
    public function test_automatic_view() {
        $result = $this->set($this->page->cmid, ['completion' => 'automatic', 'completionview' => 1]);
        $cm = $this->cm($this->page->cmid);
        $this->assertEquals(COMPLETION_TRACKING_AUTOMATIC, $cm->completion);
        $this->assertEquals(1, $cm->completionview);
        $this->assertSame(1, json_decode($result['after'], true)['completionview']);

        get_fast_modinfo(0, 0, true);
        $cminfo = get_fast_modinfo($this->course)->get_cm($this->page->cmid);
        $completion = new \completion_info(get_course($this->course->id));
        $completion->set_module_viewed($cminfo, $this->users['student']->id);
        $this->assertSame(COMPLETION_COMPLETE, $this->state($this->page->cmid, 'student'));
        $this->assertSame(0, $this->state($this->page->cmid, 'student2'));

        // Back to manual clears the hidden "must view", so a view no longer completes it.
        $this->set($this->page->cmid, ['completion' => 'manual'], true);
        $this->assertEquals(0, $this->cm($this->page->cmid)->completionview);
    }

    /**
     * An assignment completed on submission.
     */
    public function test_assign_completionsubmit() {
        global $DB;
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $this->course->id, 'section' => 1,
            'assignsubmission_onlinetext_enabled' => 1]);
        $this->set($assign->cmid, ['completion' => 'automatic', 'completionsubmit' => 1]);
        $this->assertEquals(1, $DB->get_field('assign', 'completionsubmit', ['id' => $assign->id]));
        $read = $this->get($assign->cmid);
        $this->assertSame([['name' => 'completionsubmit', 'value' => 1]], $read['rules']);
        $this->assertSame(['completionsubmit'], $read['supports']['rules']);
        $this->assertSame('completionusegrade', $read['supports']['gradefield']);

        // A submission completes it.
        $this->setUser($this->users['student']);
        $context = \context_module::instance($assign->cmid);
        $instance = new \assign($context, null, null);
        $submission = $instance->get_user_submission($this->users['student']->id, true);
        $submission->status = ASSIGN_SUBMISSION_STATUS_SUBMITTED;
        $DB->update_record('assign_submission', $submission);
        $this->setUser($this->wsuser);
        $cminfo = get_fast_modinfo($this->course)->get_cm($assign->cmid);
        (new \completion_info(get_course($this->course->id)))->update_state($cminfo, COMPLETION_COMPLETE,
            $this->users['student']->id);
        $this->assertSame(COMPLETION_COMPLETE, $this->state($assign->cmid, 'student'));

        // With a grade too.
        $this->set($assign->cmid, ['completionusegrade' => 1], true);
        $cm = $this->cm($assign->cmid);
        $this->assertSame('0', (string)$cm->completiongradeitemnumber);
        $this->assertSame(1, $this->get($assign->cmid)['completionusegrade']);
        $this->set($assign->cmid, ['completionusegrade' => 0], true);
        $this->assertNull($this->cm($assign->cmid)->completiongradeitemnumber);
    }

    /**
     * Quiz "Require passing grade": refused by the quiz form without a grade to pass, accepted with one.
     */
    public function test_quiz_completionpass() {
        global $DB;
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $this->course->id, 'section' => 1,
            'grade' => 10]);
        $fields = ['completion' => 'automatic', 'completionusegrade' => 1, 'completionpass' => 1];
        $e = $this->assert_error('invalidsettings', function() use ($quiz, $fields) {
            $this->set($quiz->cmid, $fields);
        });
        $this->assertStringContainsString('completionpassgroup', $e->getMessage());
        // Our own refusals of what the form disables.
        $this->assert_error('invalidsettings', function() use ($quiz) {
            $this->set($quiz->cmid, ['completion' => 'automatic', 'completionpass' => 1]);
        });
        $this->assert_error('invalidsettings', function() use ($quiz) {
            $this->set($quiz->cmid, ['completion' => 'automatic', 'completionusegrade' => 1,
                'completionattemptsexhausted' => 1]);
        });

        $item = \grade_item::fetch(['itemtype' => 'mod', 'itemmodule' => 'quiz', 'iteminstance' => $quiz->id,
            'courseid' => $this->course->id]);
        $item->gradepass = 6;
        $item->update();
        $this->set($quiz->cmid, $fields + ['completionattemptsexhausted' => 1]);
        $record = $DB->get_record('quiz', ['id' => $quiz->id]);
        $this->assertEquals(1, $record->completionpass);
        $this->assertEquals(1, $record->completionattemptsexhausted);
        $this->assertEquals(6, \grade_item::fetch(['id' => $item->id])->gradepass);
        $this->assertSame('0', (string)$this->cm($quiz->cmid)->completiongradeitemnumber);
    }

    /**
     * Forum counts and a workshop's grade item select.
     */
    public function test_forum_and_workshop() {
        global $DB;
        $gen = $this->getDataGenerator();
        $forum = $gen->create_module('forum', ['course' => $this->course->id, 'section' => 1]);
        $this->set($forum->cmid, ['completion' => 'automatic', 'completionposts' => 2]);
        $record = $DB->get_record('forum', ['id' => $forum->id]);
        $this->assertEquals(2, $record->completionposts);
        $this->assertEquals(0, $record->completiondiscussions);
        $this->assertEquals(0, $record->completionreplies);
        $this->set($forum->cmid, ['completionposts' => 0, 'completionreplies' => 3], true);
        $record = $DB->get_record('forum', ['id' => $forum->id]);
        $this->assertEquals(0, $record->completionposts);
        $this->assertEquals(3, $record->completionreplies);

        $workshop = $gen->create_module('workshop', ['course' => $this->course->id, 'section' => 1]);
        $read = $this->get($workshop->cmid);
        $this->assertSame('completiongradeitemnumber', $read['supports']['gradefield']);
        $this->assertSame([0, 1], array_column($read['supports']['gradeitems'], 'itemnumber'));
        $this->assertSame([], $read['supports']['rules']);
        // The workshop form's own validation: the assessment grade is off (gradinggrade 0).
        $e = $this->assert_error('invalidsettings', function() use ($workshop) {
            $this->set($workshop->cmid, ['completion' => 'automatic', 'completiongradeitemnumber' => 1]);
        });
        $this->assertStringContainsString('completiongradeitemnumber', $e->getMessage());
        $this->set($workshop->cmid, ['completion' => 'automatic', 'completiongradeitemnumber' => 0]);
        $this->assertSame('0', (string)$this->cm($workshop->cmid)->completiongradeitemnumber);
        $this->assertSame(0, $this->get($workshop->cmid)['completiongradeitemnumber']);
        $this->assertSame(1, $this->get($workshop->cmid)['completionusegrade']);
        $this->assert_error('invalidsettings', function() use ($workshop) {
            $this->set($workshop->cmid, ['completionusegrade' => 1]);
        });
        $this->set($workshop->cmid, ['completiongradeitemnumber' => null, 'completionview' => 1], true);
        $this->assertNull($this->cm($workshop->cmid)->completiongradeitemnumber);
    }

    /**
     * Refusals: site or course completion off, fields, rules the form does not offer, the form's validation.
     */
    public function test_refusals() {
        global $DB;
        $label = $this->getDataGenerator()->create_module('label', ['course' => $this->course->id, 'section' => 1]);
        $choice = $this->getDataGenerator()->create_module('choice', ['course' => $this->course->id, 'section' => 1]);
        $page = $this->page->cmid;

        set_config('enablecompletion', 0);
        $e = $this->assert_error('completiondisabled', function() use ($page) {
            $this->set($page, ['completion' => 'manual']);
        });
        $this->assertStringContainsString('enablecompletion', $e->getMessage());
        set_config('enablecompletion', 1);
        $DB->set_field('course', 'enablecompletion', 0, ['id' => $this->course->id]);
        $e = $this->assert_error('completiondisabled', function() use ($page) {
            $this->set($page, ['completion' => 'manual']);
        });
        $this->assertStringContainsString('course ' . $this->course->id, $e->getMessage());
        $DB->set_field('course', 'enablecompletion', 1, ['id' => $this->course->id]);

        // Not completion settings, or a module type the plugin does not change.
        $e = $this->assert_error('fieldexcluded', function() use ($page) {
            $this->set($page, ['gradepass' => 5]);
        });
        $this->assertStringContainsString('not a completion setting', $e->getMessage());
        $this->assert_error('fieldexcluded', function() use ($page) {
            $this->set($page, ['completionunlocked' => 1]);
        });
        $e = $this->assert_error('fieldexcluded', function() use ($choice) {
            $this->set($choice->cmid, ['completion' => 'manual']);
        });
        $this->assertStringContainsString('choice', $e->getMessage());

        // Rules the form does not offer.
        $refused = [
            [$page, ['completion' => 'automatic', 'completionsubmit' => 1]],
            [$page, ['completion' => 'automatic', 'completionusegrade' => 1]],
            [$label->cmid, ['completion' => 'automatic']],
            [$label->cmid, ['completionview' => 1]],
            // Automatic with no condition (the form's badautocompletion).
            [$page, ['completion' => 'automatic', 'completionview' => 0]],
            // A rule with another mode, an expected date with none.
            [$page, ['completion' => 'manual', 'completionview' => 1]],
            [$page, ['completionexpected' => time() + DAYSECS]],
        ];
        foreach ($refused as $index => list($cmid, $fields)) {
            $this->assert_error('invalidsettings', function() use ($cmid, $fields) {
                $this->set($cmid, $fields);
            });
        }
        // A label can be manual.
        $this->set($label->cmid, ['completion' => 'manual']);
        $this->assertEquals(1, $this->cm($label->cmid)->completion);

        foreach ([['completion' => 'sometimes'], ['completionview' => 2], ['completionexpected' => -1]] as $fields) {
            $this->expectExceptionCount = 0;
            try {
                $this->set($page, $fields + ($fields === ['completionview' => 2] ? ['completion' => 'automatic'] : []));
                $this->fail('Expected invalid_parameter_exception for ' . json_encode($fields));
            } catch (\invalid_parameter_exception $e) {
                $this->assertInstanceOf(\invalid_parameter_exception::class, $e);
            }
        }
        foreach (['[1,2]', '"manual"', '{}'] as $json) {
            try {
                set_completion::execute($page, $json);
                $this->fail('Expected invalid_parameter_exception for ' . $json);
            } catch (\invalid_parameter_exception $e) {
                $this->assertInstanceOf(\invalid_parameter_exception::class, $e);
            }
        }
        // Nothing was written by the refusals.
        $this->assertEquals(0, $this->cm($page)->completion);
    }

    /**
     * The expected date moves the "expected completion" calendar event, without resetting completion data.
     */
    public function test_completionexpected_event() {
        global $DB;
        $this->set($this->page->cmid, ['completion' => 'manual']);
        $this->tick($this->page->cmid, 'student');
        $time = mktime(9, 0, 0, 11, 2, 2030);
        $event = function() use ($DB) {
            return $DB->get_record('event', ['modulename' => 'page', 'instance' => $this->page->id,
                'eventtype' => \core_completion\api::COMPLETION_EVENT_TYPE_DATE_COMPLETION_EXPECTED]);
        };
        $this->assertFalse($event());
        // No completiondata: the expected date does not reset anything.
        $result = $this->set($this->page->cmid, ['completionexpected' => $time]);
        $this->assertSame([], $result['warnings']);
        $this->assertEquals($time, $event()->timestart);
        $this->assertEquals($time, $this->cm($this->page->cmid)->completionexpected);
        $this->assertSame(COMPLETION_COMPLETE, $this->state($this->page->cmid, 'student'));
        $id = $event()->id;
        $this->set($this->page->cmid, ['completionexpected' => $time + WEEKSECS]);
        $this->assertEquals($time + WEEKSECS, $event()->timestart);
        $this->assertEquals($id, $event()->id);
        $this->set($this->page->cmid, ['completionexpected' => 0]);
        $this->assertFalse($event());
        $this->assertSame(COMPLETION_COMPLETE, $this->state($this->page->cmid, 'student'));
    }

    /**
     * A page with files in its description and content, an access restriction and tags: a completion
     * change and its undo change nothing else (the settings form path and its file guard).
     */
    public function test_other_settings_unchanged() {
        global $DB;
        $group = $this->getDataGenerator()->create_group(['courseid' => $this->course->id]);
        $tree = '{"op":"&","c":[{"type":"group","id":' . $group->id . '}],"showc":[false]}';
        $this->setAdminUser();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $this->course->id, 'section' => 2,
            'intro' => '<p><img src="@@PLUGINFILE@@/intro.png" alt="i"></p>', 'introformat' => FORMAT_HTML,
            'content' => '<p><img src="@@PLUGINFILE@@/frame.png" alt="v"><iframe src="https://drive.google.com/x">' .
                '</iframe></p>', 'contentformat' => FORMAT_HTML, 'availability' => $tree,
            'tags' => ['Video', 'Session 01'], 'visible' => 0]);
        $context = \context_module::instance($page->cmid);
        $fs = get_file_storage();
        $fs->create_file_from_string(['contextid' => $context->id, 'component' => 'mod_page', 'filearea' => 'intro',
            'itemid' => 0, 'filepath' => '/', 'filename' => 'intro.png'], 'INTRO');
        $fs->create_file_from_string(['contextid' => $context->id, 'component' => 'mod_page', 'filearea' => 'content',
            'itemid' => 0, 'filepath' => '/', 'filename' => 'frame.png'], 'FRAME');
        $this->setUser($this->wsuser);
        $completionfields = ['completion', 'completionview', 'completiongradeitemnumber', 'completionexpected'];
        $rows = function() use ($DB, $page, $context, $completionfields) {
            $strip = function($records, array $fields = []) {
                return array_values(array_map(function($row) use ($fields) {
                    $row = (array)$row;
                    foreach (array_merge(['timemodified', 'revision'], $fields) as $field) {
                        unset($row[$field]);
                    }
                    return $row;
                }, $records));
            };
            return [
                'course_modules' => $strip($DB->get_records('course_modules', ['id' => $page->cmid]), $completionfields),
                'page' => $strip($DB->get_records('page', ['id' => $page->id])),
                'files' => $strip($DB->get_records('files', ['contextid' => $context->id], 'id')),
                'tags' => $strip($DB->get_records('tag_instance', ['itemtype' => 'course_modules',
                    'itemid' => $page->cmid], 'id')),
                'grade_items' => $strip($DB->get_records('grade_items', ['courseid' => $this->course->id], 'id')),
            ];
        };
        $before = $rows();
        $this->assertCount(2, $before['tags']);
        $set = $this->set($page->cmid, ['completion' => 'automatic', 'completionview' => 1,
            'completionexpected' => time() + WEEKSECS]);
        $this->assertEquals($before, $rows());
        $this->assertSame($tree, $this->cm($page->cmid)->availability);
        $this->assertEquals(0, $this->cm($page->cmid)->visible);
        $this->undo($set['journalid']);
        $this->assertEquals($before, $rows());
        $this->assertEquals(0, $this->cm($page->cmid)->completion);
        $this->assertEquals(0, $this->cm($page->cmid)->completionexpected);
    }

    /**
     * Existing completion data: staff rows reset with a warning; students' rows refused without
     * resetcompletion, reset with it (counts in the result and the journal); undo restores the settings,
     * not the data.
     */
    public function test_existing_data() {
        global $DB;
        $cmid = $this->page->cmid;
        $this->set($cmid, ['completion' => 'manual']);

        // A teacher's tick only: reset without resetcompletion, with a warning.
        $this->tick($cmid, 'teacher');
        $read = $this->get($cmid);
        $this->assertSame(1, $read['data']['others']['complete']);
        $this->assertSame(0, $read['data']['students']['rows']);
        $this->assertTrue($read['data']['locked']);
        $result = $this->set($cmid, ['completion' => 'automatic', 'completionview' => 1]);
        $this->assertSame('completionreset', $result['warnings'][0]['code']);
        $this->assertSame(0, $DB->count_records('course_modules_completion', ['coursemoduleid' => $cmid]));
        $this->assertSame(['students' => 0, 'studentscomplete' => 0, 'studentsviewed' => 0, 'others' => 1,
            'otherscomplete' => 1, 'othersviewed' => 0, 'coursestudents' => 0, 'courseothers' => 0],
            json_decode($result['after'], true)['reset']);

        // A student's view and a student's completion: refused.
        get_fast_modinfo(0, 0, true);
        $cminfo = get_fast_modinfo($this->course)->get_cm($cmid);
        $completion = new \completion_info(get_course($this->course->id));
        $completion->set_module_viewed($cminfo, $this->users['student']->id);
        $read = $this->get($cmid);
        $this->assertSame(['rows' => 1, 'incomplete' => 0, 'complete' => 1, 'completepass' => 0, 'completefail' => 0,
            'viewed' => 1], $read['data']['students']);
        $e = $this->assert_error('completiondata', function() use ($cmid) {
            $this->set($cmid, ['completion' => 'manual']);
        });
        $this->assertStringContainsString('1 student rows (1 complete, 1 viewed)', $e->getMessage());
        $this->assertEquals(COMPLETION_TRACKING_AUTOMATIC, $this->cm($cmid)->completion);
        $this->assertSame(1, $DB->count_records('course_modules_completion', ['coursemoduleid' => $cmid]));

        // With resetcompletion.
        $result = $this->set($cmid, ['completion' => 'manual'], true);
        $this->assertSame('completionreset', $result['warnings'][0]['code']);
        $this->assertStringContainsString('Undo restores the settings, not this data', $result['warnings'][0]['message']);
        $this->assertSame(0, $DB->count_records('course_modules_completion', ['coursemoduleid' => $cmid]));
        $entry = $this->journal_entry($result['journalid']);
        $this->assertSame(1, $entry['afterstate']['reset']['students']);
        $this->assertSame(1, $entry['afterstate']['reset']['studentsviewed']);
        $this->assertSame(1, $entry['beforestate']['students']);
        $this->assertSame(0, $entry['afterstate']['students']);
        $this->assertSame(1, (int)$entry['undoable']);
        // The journal holds counts, never whose rows they were.
        $this->assertSame(0, $DB->count_records(journal::USERS_TABLE, ['journalid' => $result['journalid']]));

        // Undo: the settings come back, the student's completion does not.
        $undone = $this->undo($result['journalid']);
        $cm = $this->cm($cmid);
        $this->assertEquals(COMPLETION_TRACKING_AUTOMATIC, $cm->completion);
        $this->assertEquals(1, $cm->completionview);
        $this->assertSame(0, $DB->count_records('course_modules_completion', ['coursemoduleid' => $cmid]));
        $this->assertSame(0, json_decode($undone['after'], true)['students']);
    }

    /**
     * An undo that would itself reset students' data needs resetcompletion.
     */
    public function test_undo_needs_resetcompletion() {
        global $DB;
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $this->course->id, 'section' => 1,
            'grade' => 100]);
        $cmid = $assign->cmid;
        $this->set($cmid, ['completion' => 'automatic', 'completionview' => 1, 'completionusegrade' => 1]);
        // A grade, but no view: incomplete, no row.
        $this->setAdminUser();
        $instance = new \assign(\context_module::instance($cmid), null, null);
        $instance->save_grade($this->users['student']->id, (object)['grade' => 70, 'attemptnumber' => -1,
            'addattempt' => 0, 'sendstudentnotifications' => 0]);
        $this->setUser($this->wsuser);
        $this->assertSame(0, $this->state($cmid, 'student'));

        // Grade only: no student rows yet, so no resetcompletion needed; the recalculation completes it.
        $result = $this->set($cmid, ['completionview' => 0]);
        $this->assertSame(COMPLETION_COMPLETE, $this->state($cmid, 'student'));
        $this->assertSame(1, json_decode($result['after'], true)['students']);

        $e = $this->assert_error('completiondata', function() use ($result) {
            $this->undo($result['journalid']);
        });
        $this->assertStringContainsString('resetcompletion', $e->getMessage());
        $this->assertEquals(0, $this->cm($cmid)->completionview);
        $undone = $this->undo($result['journalid'], true);
        $this->assertEquals(1, $this->cm($cmid)->completionview);
        $this->assertSame('completionreset', $undone['warnings'][0]['code']);
        $this->assertSame(0, $this->state($cmid, 'student'));
        $this->assertSame(0, $DB->count_records('course_modules_completion', ['coursemoduleid' => $cmid,
            'userid' => $this->users['student']->id]));
    }

    /**
     * An activity that is a course completion criterion: a reset deletes the course's completion records,
     * so students' course completions need resetcompletion too.
     */
    public function test_course_completion_criterion() {
        global $DB;
        $cmid = $this->page->cmid;
        $this->set($cmid, ['completion' => 'manual']);
        $DB->insert_record('course_completion_criteria', (object)['course' => $this->course->id,
            'criteriatype' => COMPLETION_CRITERIA_TYPE_ACTIVITY, 'module' => 'page', 'moduleinstance' => $cmid]);
        $DB->insert_record('course_completions', (object)['userid' => $this->users['student']->id,
            'course' => $this->course->id, 'timeenrolled' => time(), 'timestarted' => time(),
            'timecompleted' => time(), 'reaggregate' => 0]);
        $read = $this->get($cmid);
        $this->assertTrue($read['data']['coursecompletion']['criterion']);
        $this->assertSame(1, $read['data']['coursecompletion']['studentscompleted']);
        $e = $this->assert_error('completiondata', function() use ($cmid) {
            $this->set($cmid, ['completion' => 'automatic', 'completionview' => 1]);
        });
        $this->assertStringContainsString('1 students\' course completion records', $e->getMessage());
        // The expected date alone resets nothing.
        $this->set($cmid, ['completionexpected' => time() + WEEKSECS]);
        $this->assertSame(1, $DB->count_records('course_completions', ['course' => $this->course->id]));

        $result = $this->set($cmid, ['completion' => 'automatic', 'completionview' => 1], true);
        $this->assertSame(0, $DB->count_records('course_completions', ['course' => $this->course->id]));
        $this->assertSame(1, json_decode($result['after'], true)['reset']['coursestudents']);
    }

    /**
     * Restrictions pointing at the activity: listed, turning completion off refused unless forced,
     * a mode change warned about.
     */
    public function test_referencedby() {
        global $DB;
        $cmid = $this->page->cmid;
        $other = $this->getDataGenerator()->create_module('page', ['course' => $this->course->id, 'section' => 2,
            'name' => 'Session 02 brief']);
        $this->set($cmid, ['completion' => 'manual']);
        $section = $DB->get_record('course_sections', ['course' => $this->course->id, 'section' => 2]);
        $condition = '{"type":"completion","cm":' . $cmid . ',"e":1}';
        $DB->set_field('course_sections', 'availability', '{"op":"&","c":[' . $condition . '],"showc":[false]}',
            ['id' => $section->id]);
        $DB->set_field('course_modules', 'availability', '{"op":"&","c":[{"op":"|","c":[' . $condition .
            ',{"type":"date","d":">=","t":1893456000}]}],"showc":[true]}', ['id' => $other->cmid]);
        rebuild_course_cache($this->course->id, true);

        $references = $this->get($cmid)['referencedby'];
        $this->assertCount(2, $references);
        $this->assertSame(['type' => 'section', 'sectionid' => (int)$section->id, 'sectionnum' => 2, 'cmid' => 0,
            'modname' => '', 'name' => get_section_name($this->course, 2), 'path' => 'c[0]', 'negated' => false,
            'display' => 'hide', 'state' => 1], $references[0]);
        $this->assertSame('module', $references[1]['type']);
        $this->assertSame((int)$other->cmid, $references[1]['cmid']);
        $this->assertSame('c[0].c[0]', $references[1]['path']);
        $this->assertSame('show', $references[1]['display']);

        $e = $this->assert_error('referenced', function() use ($cmid) {
            $this->set($cmid, ['completion' => 'none']);
        });
        $this->assertStringContainsString('Session 02 brief', $e->getMessage());
        $this->assertStringContainsString('section 2', $e->getMessage());
        $this->assertEquals(1, $this->cm($cmid)->completion);

        // A mode change: warned.
        $result = $this->set($cmid, ['completion' => 'automatic', 'completionview' => 1]);
        $this->assertSame('referenced', $result['warnings'][0]['code']);
        // Forced off: warned.
        $result = $this->set($cmid, ['completion' => 'none'], false, true);
        $this->assertSame('referenced', $result['warnings'][0]['code']);
        $this->assertStringContainsString('can no longer be met', $result['warnings'][0]['message']);
        $this->assertEquals(0, $this->cm($cmid)->completion);
        // Undo turns it on again (no reference check for turning it on).
        $this->undo($result['journalid']);
        $this->assertEquals(2, $this->cm($cmid)->completion);
    }

    /**
     * Gate, capabilities, expected, journal, undo refused after a later change, nochange.
     */
    public function test_matrix() {
        global $DB;
        $cmid = $this->page->cmid;
        $this->remove_capability('moodle/course:manageactivities');
        foreach ([function() use ($cmid) {
            $this->set($cmid, ['completion' => 'manual']);
        }, function() use ($cmid) {
            $this->get($cmid);
        }, function() {
            get_course_completion::execute($this->course->id);
        }] as $call) {
            try {
                $call();
                $this->fail('Expected required_capability_exception');
            } catch (\required_capability_exception $e) {
                $this->assertStringContainsString('Manage activities', $e->getMessage());
            }
        }
        assign_capability('moodle/course:manageactivities', CAP_ALLOW, $this->wsroleid, \context_system::instance()->id,
            true);
        $this->remove_capability('local/paatshala:use');
        try {
            $this->set($cmid, ['completion' => 'manual']);
            $this->fail('Expected required_capability_exception');
        } catch (\required_capability_exception $e) {
            $this->assertStringContainsString(get_string('paatshala:use', 'local_paatshala'), $e->getMessage());
        }
        assign_capability('local/paatshala:use', CAP_ALLOW, $this->wsroleid, \context_system::instance()->id, true);
        accesslib_clear_all_caches_for_unit_testing();

        set_config('enabled', 0, 'local_paatshala');
        $this->assert_error('disabled', function() use ($cmid) {
            $this->set($cmid, ['completion' => 'manual']);
        });
        set_config('enabled', 1, 'local_paatshala');
        set_config('allowedcourses', (string)($this->course->id + 1), 'local_paatshala');
        $this->assert_error('coursenotallowed', function() use ($cmid) {
            $this->get($cmid);
        });
        set_config('allowedcourses', (string)$this->course->id, 'local_paatshala');
        $this->assertSame(0, $this->get($cmid)['completion']);
        set_config('allowedcourses', '*', 'local_paatshala');

        // The read's state as expected matches; a student ticking in between is a conflict.
        $state = json_decode($this->get($cmid)['state'], true);
        $this->set($cmid, ['completion' => 'manual'], false, false, $state);
        $state = json_decode($this->get($cmid)['state'], true);
        $this->tick($cmid, 'student');
        $e = $this->assert_error('conflict', function() use ($cmid, $state) {
            $this->set($cmid, ['completion' => 'automatic', 'completionview' => 1], true, false, $state);
        });
        $this->assertStringContainsString('"studentscomplete":1', $e->getMessage());
        $this->assert_error('conflict', function() use ($cmid) {
            $this->set($cmid, ['completionexpected' => time() + DAYSECS], false, false, ['completion' => 0]);
        });
        try {
            $this->set($cmid, ['completionexpected' => time() + DAYSECS], false, false, ['reset' => 1]);
            $this->fail('Expected invalid_parameter_exception');
        } catch (\invalid_parameter_exception $e) {
            $this->assertStringContainsString('unknown keys', $e->getMessage());
        }

        // Journal row, undo to the exact before, redo, undo refused after a later change.
        $time = mktime(9, 0, 0, 1, 5, 2031);
        $result = $this->set($cmid, ['completionexpected' => $time]);
        $entry = $this->journal_entry($result['journalid']);
        $this->assertSame(['cmid' => (int)$cmid, 'modname' => 'page', 'name' => 'Session 01 video 1'], $entry['target']);
        $this->assertSame(0, $entry['beforestate']['completionexpected']);
        $this->assertSame($time, $entry['afterstate']['completionexpected']);
        $this->assertSame(1, $entry['afterstate']['students']);
        $this->assertSame(0, (int)$entry['notifies']);
        $this->assertNull($entry['relateduserid']);
        $undone = $this->undo($result['journalid']);
        $this->assertEquals(0, $this->cm($cmid)->completionexpected);
        $this->assertSame((int)$result['journalid'], (int)$this->journal_entry($undone['journalid'])['undoof']);
        $redone = $this->undo($undone['journalid']);
        $this->assertEquals($time, $this->cm($cmid)->completionexpected);
        $this->set($cmid, ['completionexpected' => $time + DAYSECS]);
        $this->assert_error('conflict', function() use ($redone) {
            $this->undo($redone['journalid']);
        });
        $this->assert_error('alreadyundone', function() use ($result) {
            $this->undo($result['journalid']);
        });
        // A student's tick after a change blocks its undo too (data counts are compared).
        $later = $this->set($cmid, ['completionexpected' => $time + 2 * DAYSECS]);
        $this->tick($cmid, 'student2');
        $this->assert_error('conflict', function() use ($later) {
            $this->undo($later['journalid']);
        });

        // Setting what is there: nothing written, nothing journaled.
        $count = $DB->count_records(journal::TABLE);
        $same = $this->set($cmid, ['completion' => 'manual', 'completionexpected' => $time + 2 * DAYSECS]);
        $this->assertSame(0, $same['journalid']);
        $this->assertSame('nochange', $same['warnings'][0]['code']);
        $this->assertSame($count, $DB->count_records(journal::TABLE));
    }

    /**
     * get_course_completion: every activity, in course page order, with what its form offers and its data.
     */
    public function test_get_course_completion() {
        $gen = $this->getDataGenerator();
        $label = $gen->create_module('label', ['course' => $this->course->id, 'section' => 1]);
        $quiz = $gen->create_module('quiz', ['course' => $this->course->id, 'section' => 2]);
        $this->set($this->page->cmid, ['completion' => 'manual']);
        $this->tick($this->page->cmid, 'student');
        $this->tick($this->page->cmid, 'editing');
        $result = external_api::clean_returnvalue(get_course_completion::execute_returns(),
            get_course_completion::execute($this->course->id));
        $this->assertTrue($result['siteenabled']);
        $this->assertTrue($result['courseenabled']);
        $this->assertSame([(int)$this->page->cmid, (int)$label->cmid, (int)$quiz->cmid],
            array_column($result['items'], 'cmid'));
        list($page, $labelitem, $quizitem) = $result['items'];
        $this->assertSame(1, $page['completion']);
        $this->assertSame(1, $page['data']['students']['complete']);
        $this->assertSame(1, $page['data']['others']['complete']);
        $this->assertTrue($page['supports']['view']);
        $this->assertTrue($page['supports']['automatic']);
        $this->assertFalse($labelitem['supports']['view']);
        $this->assertFalse($labelitem['supports']['automatic']);
        $this->assertSame(['completionpass', 'completionattemptsexhausted'], $quizitem['supports']['rules']);
        $this->assertSame('completionusegrade', $quizitem['supports']['gradefield']);
        $this->assertSame(2, $quizitem['sectionnum']);
    }

    /**
     * A quiz that requires a passing grade keeps it through other writes of the settings helper
     * (the quiz form refuses "Require passing grade" without a grade to pass; validation sees the
     * stored one, as a browser posts it).
     */
    public function test_quiz_dates_keep_completionpass() {
        global $DB;
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $this->course->id, 'section' => 1,
            'grade' => 10, 'gradepass' => 6, 'completion' => COMPLETION_TRACKING_AUTOMATIC, 'completionusegrade' => 1,
            'completionpass' => 1]);
        $this->assertEquals(1, $DB->get_field('quiz', 'completionpass', ['id' => $quiz->id]));
        $result = external_api::clean_returnvalue(\local_paatshala\external\quiz_set_dates::execute_returns(),
            \local_paatshala\external\quiz_set_dates::execute($quiz->cmid, 1893542400, null, null));
        $this->assertNotSame(0, $result['journalid']);
        $this->assertEquals(1893542400, $DB->get_field('quiz', 'timeopen', ['id' => $quiz->id]));
        $this->assertEquals(1, $DB->get_field('quiz', 'completionpass', ['id' => $quiz->id]));
        $this->assertEquals(COMPLETION_TRACKING_AUTOMATIC, $this->cm($quiz->cmid)->completion);
    }
}

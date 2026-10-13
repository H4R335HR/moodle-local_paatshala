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
 * Tests for assignment settings, workshop dates and quiz dates.
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
require_once($CFG->dirroot . '/mod/workshop/locallib.php');

use external_api;
use local_paatshala\external\quiz_set_dates;
use local_paatshala\external\undo;
use local_paatshala\external\update_assign_settings;
use local_paatshala\external\workshop_set_dates;

/**
 * Tests for assignment settings, workshop dates and quiz dates.
 *
 * @package    local_paatshala
 * @copyright  2026 local_paatshala contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class settings_test extends testcase {

    /** @var \stdClass */
    protected $course;

    /**
     * Course, plugin on, ws user with the settings page capability.
     */
    protected function setUp(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->course = $this->getDataGenerator()->create_course();
        $this->enable_plugin('*');
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
     * An assignment with online text (no word limit) and files (3 files, 1 MB).
     *
     * @return \stdClass
     */
    protected function assignment(): \stdClass {
        return $this->getDataGenerator()->create_module('assign', ['course' => $this->course->id, 'name' => 'Report',
            'assignsubmission_onlinetext_enabled' => 1, 'assignsubmission_onlinetext_wordlimit' => 0,
            'assignsubmission_onlinetext_wordlimit_enabled' => 0, 'assignsubmission_file_enabled' => 1,
            'assignsubmission_file_maxfiles' => 3, 'assignsubmission_file_maxsizebytes' => 1048576,
            'assignsubmission_file_filetypes' => '.pdf', 'assignsubmission_comments_enabled' => 1,
            'assignfeedback_comments_enabled' => 1, 'blindmarking' => 1]);
    }

    /**
     * Change assignment settings and clean the result.
     *
     * @param int $cmid
     * @param array $fields
     * @param string|null $expected
     * @return array
     */
    protected function update(int $cmid, array $fields, $expected = null): array {
        return external_api::clean_returnvalue(update_assign_settings::execute_returns(),
            update_assign_settings::execute($cmid, json_encode($fields), $expected));
    }

    /**
     * A plugin of an assignment.
     *
     * @param int $cmid
     * @param string $type
     * @return \assign_plugin
     */
    protected function plugin(int $cmid, string $type): \assign_plugin {
        list($course, $cm) = get_course_and_cm_from_cmid($cmid, 'assign');
        $assign = new \assign(\context_module::instance($cmid), $cm, $course);
        return $assign->get_submission_plugin_by_type($type);
    }

    /**
     * Word limit, file limits and name change; other settings stay; undo and redo.
     */
    public function test_update_assign_settings() {
        global $DB;
        $assign = $this->assignment();
        $this->login_ws_user(['moodle/course:manageactivities']);

        $result = $this->update($assign->cmid, ['name' => 'Final report',
            'assignsubmission_onlinetext_wordlimit_enabled' => 1, 'assignsubmission_onlinetext_wordlimit' => 500,
            'assignsubmission_file_maxfiles' => 1]);
        $this->assertEquals('Final report', $DB->get_field('assign', 'name', ['id' => $assign->id]));
        $onlinetext = $this->plugin($assign->cmid, 'onlinetext');
        $this->assertEquals(500, $onlinetext->get_config('wordlimit'));
        $this->assertEquals(1, $onlinetext->get_config('wordlimitenabled'));
        $file = $this->plugin($assign->cmid, 'file');
        $this->assertEquals(1, $file->get_config('maxfilesubmissions'));
        $this->assertEquals(1048576, $file->get_config('maxsubmissionsizebytes'));
        $this->assertEquals('.pdf', $file->get_config('filetypeslist'));
        $this->assertTrue((bool)$file->is_enabled());

        $this->assertEquals(['name' => 'Report', 'assignsubmission_onlinetext_wordlimit_enabled' => 0,
            'assignsubmission_onlinetext_wordlimit' => 0, 'assignsubmission_file_maxfiles' => 3],
            json_decode($result['before'], true));
        $this->assertSame('assign_settings', $this->journal_entry($result['journalid'])['kind']);

        $undone = $this->undo($result['journalid']);
        $this->assertEquals('Report', $DB->get_field('assign', 'name', ['id' => $assign->id]));
        $this->assertEquals(0, $this->plugin($assign->cmid, 'onlinetext')->get_config('wordlimitenabled'));
        $this->assertEquals(3, $this->plugin($assign->cmid, 'file')->get_config('maxfilesubmissions'));
        $this->undo($undone['journalid']);
        $this->assertEquals(500, $this->plugin($assign->cmid, 'onlinetext')->get_config('wordlimit'));
    }

    /**
     * Turning a submission type off keeps its settings; undo turns it back on.
     */
    public function test_disable_and_restore_submission_type() {
        $assign = $this->assignment();
        $this->login_ws_user(['moodle/course:manageactivities']);
        $result = $this->update($assign->cmid, ['assignsubmission_file_enabled' => 0]);
        $this->assertFalse((bool)$this->plugin($assign->cmid, 'file')->is_enabled());
        $this->undo($result['journalid']);
        $file = $this->plugin($assign->cmid, 'file');
        $this->assertTrue((bool)$file->is_enabled());
        $this->assertEquals(3, $file->get_config('maxfilesubmissions'));
    }

    /**
     * A file-only assignment: online text and feedback comments off.
     *
     * @return \stdClass
     */
    protected function file_only_assignment(): \stdClass {
        return $this->getDataGenerator()->create_module('assign', ['course' => $this->course->id, 'name' => 'Upload',
            'assignsubmission_onlinetext_enabled' => 0, 'assignsubmission_file_enabled' => 1,
            'assignsubmission_file_maxfiles' => 3, 'assignsubmission_file_maxsizebytes' => 1048576,
            'assignfeedback_comments_enabled' => 0]);
    }

    /**
     * Journal entries about an activity.
     *
     * @param int $cmid
     * @return int
     */
    protected function journal_count(int $cmid): int {
        global $DB;
        return $DB->count_records(journal::TABLE, ['cmid' => $cmid]);
    }

    /**
     * Settings of a plugin that is off are refused with the reason, and nothing is saved or
     * journaled: Moodle only saves a plugin's settings while it is on.
     */
    public function test_settings_of_plugin_off_refused() {
        global $DB;
        $assign = $this->file_only_assignment();
        $this->login_ws_user(['moodle/course:manageactivities']);
        $modified = $DB->get_field('assign', 'timemodified', ['id' => $assign->id]);

        $e = $this->assert_error('notsaved', function() use ($assign) {
            $this->update($assign->cmid, ['assignsubmission_onlinetext_wordlimit_enabled' => 1,
                'assignsubmission_onlinetext_wordlimit' => 250]);
        });
        $this->assertStringContainsString('Online text is off on this assignment', $e->getMessage());
        $this->assertStringContainsString('include assignsubmission_onlinetext_enabled: 1 in the same call',
            $e->getMessage());
        $this->assertSame(0, $this->journal_count($assign->cmid));
        $this->assertEquals(0, $this->plugin($assign->cmid, 'onlinetext')->get_config('wordlimit'));

        // Feedback comments' inline setting, with comments off; the name in the same call is rolled back.
        $e = $this->assert_error('notsaved', function() use ($assign) {
            $this->update($assign->cmid, ['name' => 'Renamed', 'assignfeedback_comments_commentinline' => 1]);
        });
        $this->assertStringContainsString('include assignfeedback_comments_enabled: 1', $e->getMessage());
        $this->assertEquals('Upload', $DB->get_field('assign', 'name', ['id' => $assign->id]));
        $this->assertEquals($modified, $DB->get_field('assign', 'timemodified', ['id' => $assign->id]));
        $this->assertSame(0, $this->journal_count($assign->cmid));

        // File submission limits, with file submissions turned off in the same call.
        $e = $this->assert_error('notsaved', function() use ($assign) {
            $this->update($assign->cmid, ['assignsubmission_file_enabled' => 0, 'assignsubmission_file_maxfiles' => 5]);
        });
        $this->assertStringContainsString('File submissions is off', $e->getMessage());
        $this->assertTrue((bool)$this->plugin($assign->cmid, 'file')->is_enabled());
        $this->assertEquals(3, $this->plugin($assign->cmid, 'file')->get_config('maxfilesubmissions'));

        // A setting equal to the stored one is refused too: it has no effect while the plugin is off.
        $this->assert_error('notsaved', function() use ($assign) {
            $this->update($assign->cmid, ['assignsubmission_onlinetext_wordlimit' => 0]);
        });
    }

    /**
     * Turning a plugin on with its settings in the same call; undo turns it off and puts its
     * settings back as they were, redo turns it on again.
     */
    public function test_plugin_on_with_settings_and_undo() {
        $assign = $this->file_only_assignment();
        $this->login_ws_user(['moodle/course:manageactivities']);
        $result = $this->update($assign->cmid, ['assignsubmission_onlinetext_enabled' => 1,
            'assignsubmission_onlinetext_wordlimit_enabled' => 1, 'assignsubmission_onlinetext_wordlimit' => 250]);
        $this->assertEquals(['assignsubmission_onlinetext_enabled' => 0, 'assignsubmission_onlinetext_wordlimit_enabled' => 0,
            'assignsubmission_onlinetext_wordlimit' => 0], json_decode($result['before'], true));
        $this->assertEquals(['assignsubmission_onlinetext_enabled' => 1, 'assignsubmission_onlinetext_wordlimit_enabled' => 1,
            'assignsubmission_onlinetext_wordlimit' => 250], json_decode($result['after'], true));

        $undone = $this->undo($result['journalid']);
        $onlinetext = $this->plugin($assign->cmid, 'onlinetext');
        $this->assertFalse((bool)$onlinetext->is_enabled());
        $this->assertEquals(0, $onlinetext->get_config('wordlimit'));
        $this->assertEquals(0, $onlinetext->get_config('wordlimitenabled'));
        $this->assertEquals(json_decode($result['before'], true), json_decode($undone['after'], true));

        $this->undo($undone['journalid']);
        $onlinetext = $this->plugin($assign->cmid, 'onlinetext');
        $this->assertTrue((bool)$onlinetext->is_enabled());
        $this->assertEquals(250, $onlinetext->get_config('wordlimit'));

        // Feedback comments with their inline setting, and file limits on an enabled plugin.
        $this->update($assign->cmid, ['assignfeedback_comments_enabled' => 1, 'assignfeedback_comments_commentinline' => 1,
            'assignsubmission_file_maxfiles' => 5]);
        $assignobj = new \assign(\context_module::instance($assign->cmid), null, null);
        $this->assertEquals(1, $assignobj->get_feedback_plugin_by_type('comments')->get_config('commentinline'));
        $this->assertEquals(5, $this->plugin($assign->cmid, 'file')->get_config('maxfilesubmissions'));
    }

    /**
     * The word limit and its switch: Moodle stores them as a pair, so each needs the other,
     * and both are journaled so undo restores the limit after switching it off.
     */
    public function test_word_limit_pair() {
        $assign = $this->assignment();
        $this->login_ws_user(['moodle/course:manageactivities']);
        $e = $this->assert_error('notsaved', function() use ($assign) {
            $this->update($assign->cmid, ['assignsubmission_onlinetext_wordlimit' => 250]);
        });
        $this->assertStringContainsString('include assignsubmission_onlinetext_wordlimit_enabled: 1', $e->getMessage());
        $e = $this->assert_error('notsaved', function() use ($assign) {
            $this->update($assign->cmid, ['assignsubmission_onlinetext_wordlimit_enabled' => 1]);
        });
        $this->assertStringContainsString('include assignsubmission_onlinetext_wordlimit above 0', $e->getMessage());

        $this->update($assign->cmid, ['assignsubmission_onlinetext_wordlimit_enabled' => 1,
            'assignsubmission_onlinetext_wordlimit' => 250]);
        $off = $this->update($assign->cmid, ['assignsubmission_onlinetext_wordlimit_enabled' => 0]);
        $this->assertEquals(['assignsubmission_onlinetext_wordlimit_enabled' => 1, 'assignsubmission_onlinetext_wordlimit' => 250],
            json_decode($off['before'], true));
        $this->assertEquals(0, $this->plugin($assign->cmid, 'onlinetext')->get_config('wordlimit'));
        $this->undo($off['journalid']);
        $onlinetext = $this->plugin($assign->cmid, 'onlinetext');
        $this->assertEquals(250, $onlinetext->get_config('wordlimit'));
        $this->assertEquals(1, $onlinetext->get_config('wordlimitenabled'));
    }

    /**
     * A plugin disabled for the whole site cannot be turned on in an assignment.
     */
    public function test_plugin_disabled_on_site() {
        $assign = $this->assignment();
        set_config('disabled', 1, 'assignfeedback_file');
        $this->login_ws_user(['moodle/course:manageactivities']);
        $e = $this->assert_error('notsaved', function() use ($assign) {
            $this->update($assign->cmid, ['assignfeedback_file_enabled' => 1]);
        });
        $this->assertStringContainsString('disabled for the whole site', $e->getMessage());
        $this->assertSame(0, $this->journal_count($assign->cmid));
    }

    /**
     * Values that already hold: nothing written, no journal entry, journalid 0 and a warning.
     */
    public function test_no_change_not_journaled() {
        global $DB;
        $assign = $this->assignment();
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $this->course->id,
            'timeopen' => 1893456000, 'timeclose' => 1894000000]);
        $this->login_ws_user(['moodle/course:manageactivities']);
        $modified = $DB->get_field('assign', 'timemodified', ['id' => $assign->id]);
        $this->waitForSecond();

        $result = $this->update($assign->cmid, ['name' => 'Report', 'assignsubmission_file_maxfiles' => 3]);
        $this->assertSame(0, $result['journalid']);
        $this->assertFalse($result['undoable']);
        $this->assertSame('nochange', $result['warnings'][0]['code']);
        $this->assertSame($result['before'], $result['after']);
        $this->assertSame(0, $this->journal_count($assign->cmid));
        $this->assertEquals($modified, $DB->get_field('assign', 'timemodified', ['id' => $assign->id]));

        $result = external_api::clean_returnvalue(quiz_set_dates::execute_returns(),
            quiz_set_dates::execute($quiz->cmid, null, 1894000000));
        $this->assertSame(0, $result['journalid']);
        $this->assertSame(0, $this->journal_count($quiz->cmid));
    }

    /**
     * Excluded fields are refused with their reason; unknown ones too; the form's validation.
     */
    public function test_assign_settings_refusals() {
        $assign = $this->assignment();
        $this->login_ws_user(['moodle/course:manageactivities']);
        $excluded = ['grade' => 100, 'intro' => 'x', 'teamsubmission' => 1, 'markingworkflow' => 1, 'duedate' => 1];
        foreach ($excluded as $field => $value) {
            $e = $this->assert_error('fieldexcluded', function() use ($assign, $field, $value) {
                $this->update($assign->cmid, [$field => $value]);
            });
            $this->assertStringContainsString($field, $e->getMessage());
        }
        $this->assert_error('invalidparameter', function() use ($assign) {
            $this->update($assign->cmid, ['completionsubmit' => 1]);
        });
        // The settings form refuses "until pass" with anonymous submissions.
        $this->assert_error('invalidsettings', function() use ($assign) {
            $this->update($assign->cmid, ['attemptreopenmethod' => 'untilpass']);
        });
        $this->assert_error('invalidparameter', function() use ($assign) {
            $this->update($assign->cmid, ['assignsubmission_file_maxfiles' => 'many']);
        });
    }

    /**
     * Gate, expected and undo refusals for settings.
     */
    public function test_assign_settings_gate_and_conflicts() {
        global $DB;
        $assign = $this->assignment();
        $this->login_ws_user(['moodle/course:manageactivities']);
        $this->assert_error('conflict', function() use ($assign) {
            $this->update($assign->cmid, ['name' => 'X'], json_encode(['name' => 'Other']));
        });
        $result = $this->update($assign->cmid, ['name' => 'X'], json_encode(['name' => 'Report']));
        $this->update($assign->cmid, ['name' => 'Y']);
        $this->assert_error('conflict', function() use ($result) {
            $this->undo($result['journalid']);
        });
        $latest = $this->update($assign->cmid, ['assignsubmission_file_maxfiles' => 2]);
        $DB->set_field(journal::TABLE, 'undoable', 0, ['id' => $latest['journalid']]);
        $this->assert_error('notundoable', function() use ($latest) {
            $this->undo($latest['journalid']);
        });

        $this->remove_capability('moodle/course:manageactivities');
        $this->assert_error('nopermissions', function() use ($assign) {
            $this->update($assign->cmid, ['name' => 'Z']);
        });
        set_config('allowedcourses', '', 'local_paatshala');
        $this->assert_error('coursenotallowed', function() use ($assign) {
            $this->update($assign->cmid, ['name' => 'Z']);
        });
        set_config('enabled', 0, 'local_paatshala');
        $this->assert_error('disabled', function() use ($assign) {
            $this->update($assign->cmid, ['name' => 'Z']);
        });
    }

    /**
     * Workshop dates: events move, the form's order rules, the automatic switch warning, undo.
     */
    public function test_workshop_set_dates() {
        global $DB;
        $workshop = $this->getDataGenerator()->create_module('workshop', ['course' => $this->course->id,
            'submissionstart' => 1893456000, 'submissionend' => 1894000000, 'assessmentstart' => 1894100000,
            'assessmentend' => 1894900000]);
        $this->login_ws_user(['moodle/course:manageactivities']);

        $result = external_api::clean_returnvalue(workshop_set_dates::execute_returns(),
            workshop_set_dates::execute($workshop->cmid, null, 1894050000));
        $this->assertEquals(1894050000, $DB->get_field('workshop', 'submissionend', ['id' => $workshop->id]));
        $this->assertEquals(1894050000, $DB->get_field('event', 'timestart', ['modulename' => 'workshop',
            'instance' => $workshop->id, 'eventtype' => WORKSHOP_EVENT_TYPE_SUBMISSION_CLOSE]));
        $this->assertEquals(['submissionend' => 1894000000], json_decode($result['before'], true));

        $this->assert_error('invalidsettings', function() use ($workshop) {
            workshop_set_dates::execute($workshop->cmid, null, 1893000000);
        });
        $this->assert_error('invalidsettings', function() use ($workshop) {
            workshop_set_dates::execute($workshop->cmid, null, null, null, 1894000000);
        });

        $this->undo($result['journalid']);
        $this->assertEquals(1894000000, $DB->get_field('workshop', 'submissionend', ['id' => $workshop->id]));

        // Automatic switch on with a passed deadline while in the submission phase.
        $DB->set_field('workshop', 'phase', \workshop::PHASE_SUBMISSION, ['id' => $workshop->id]);
        $past = time() - 86400;
        $warned = workshop_set_dates::execute($workshop->cmid, $past - 86400, $past, 0, 0, 1);
        $this->assertSame('autoswitch', $warned['warnings'][0]['code']);
        $this->assertEquals(1, $DB->get_field('workshop', 'phaseswitchassessment', ['id' => $workshop->id]));
        $off = workshop_set_dates::execute($workshop->cmid, null, null, null, null, 0);
        $this->assertEquals(0, $DB->get_field('workshop', 'phaseswitchassessment', ['id' => $workshop->id]));
        $this->assertSame([], $off['warnings']);
    }

    /**
     * Quiz dates: events move, close before open refused, undo and redo, gate.
     */
    public function test_quiz_set_dates() {
        global $DB;
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $this->course->id,
            'timeopen' => 1893456000, 'timeclose' => 1894000000]);
        $this->login_ws_user(['moodle/course:manageactivities']);

        $result = external_api::clean_returnvalue(quiz_set_dates::execute_returns(),
            quiz_set_dates::execute($quiz->cmid, null, 1894500000));
        $this->assertEquals(1894500000, $DB->get_field('quiz', 'timeclose', ['id' => $quiz->id]));
        $this->assertEquals(1894500000, $DB->get_field('event', 'timestart', ['modulename' => 'quiz',
            'instance' => $quiz->id, 'eventtype' => QUIZ_EVENT_TYPE_CLOSE]));

        $this->assert_error('invalidsettings', function() use ($quiz) {
            quiz_set_dates::execute($quiz->cmid, null, 1893000000);
        });
        $this->assert_error('conflict', function() use ($quiz) {
            quiz_set_dates::execute($quiz->cmid, 1893400000, null, json_encode(['timeclose' => 1894000000]));
        });

        $undone = $this->undo($result['journalid']);
        $this->assertEquals(1894000000, $DB->get_field('quiz', 'timeclose', ['id' => $quiz->id]));
        $this->undo($undone['journalid']);
        $this->assertEquals(1894500000, $DB->get_field('quiz', 'timeclose', ['id' => $quiz->id]));

        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $this->course->id]);
        $this->assert_error('wrongmodule', function() use ($assign) {
            quiz_set_dates::execute($assign->cmid, 1893400000);
        });
        $this->remove_capability('moodle/course:manageactivities');
        $this->assert_error('nopermissions', function() use ($quiz) {
            quiz_set_dates::execute($quiz->cmid, 1893400000);
        });
    }
}

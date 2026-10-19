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
 * Tests for the settings helper's guard: no write may change an activity's files.
 *
 * @package    local_paatshala
 * @copyright  2026 local_paatshala contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_paatshala;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once(__DIR__ . '/helper.php');
require_once($CFG->dirroot . '/mod/quiz/locallib.php');

use external_api;
use local_paatshala\external\assign_set_dates;
use local_paatshala\external\quiz_set_dates;
use local_paatshala\external\set_access;
use local_paatshala\external\undo;
use local_paatshala\external\update_assign_settings;

/**
 * Tests for the settings helper's guard (modform::save(), errorcode filesaltered).
 *
 * Moodle's file_save_draft_area_files() deletes an area file whose draft copy it skips: a
 * file above the editor's maxbytes whose uploader lacks moodle/course:ignorefilesizelimits
 * (page content: the site's maxbytes), or a file in a subfolder of an area saved without
 * subdirs (assign intro attachments). Every write through the helper must refuse that.
 *
 * @package    local_paatshala
 * @copyright  2026 local_paatshala contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class guard_test extends testcase {

    /** @var \stdClass */
    protected $course;

    /** @var \stdClass A teacher, who uploads files the site limit applies to. */
    protected $teacher;

    /**
     * Course, plugin on, availability on.
     */
    protected function setUp(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('enableavailability', 1);
        $this->course = $this->getDataGenerator()->create_course();
        $this->teacher = $this->getDataGenerator()->create_and_enrol($this->course, 'editingteacher');
        $this->enable_plugin('*');
    }

    /**
     * Store a file in an activity's area.
     *
     * @param int $cmid
     * @param string $component
     * @param string $filearea
     * @param string $filename
     * @param string $content
     * @param array $extra More file record fields (userid, filepath, itemid).
     * @return \stored_file
     */
    protected function add_file(int $cmid, string $component, string $filearea, string $filename, string $content,
            array $extra = []): \stored_file {
        return get_file_storage()->create_file_from_string($extra + ['contextid' => \context_module::instance($cmid)->id,
            'component' => $component, 'filearea' => $filearea, 'itemid' => 0, 'filepath' => '/',
            'filename' => $filename], $content);
    }

    /**
     * Every file of an activity's context: area/itemid/path/name => [contenthash, filesize].
     *
     * @param int $cmid
     * @return array
     */
    protected function all_files(int $cmid): array {
        global $DB;
        $files = [];
        foreach ($DB->get_records('files', ['contextid' => \context_module::instance($cmid)->id]) as $file) {
            if ($file->filename !== '.') {
                $files[$file->component . '/' . $file->filearea . '/' . $file->itemid . $file->filepath .
                    $file->filename] = [$file->contenthash, (int)$file->filesize];
            }
        }
        ksort($files);
        return $files;
    }

    /**
     * Journal entries of an activity.
     *
     * @param int $cmid
     * @return int
     */
    protected function journal_count(int $cmid): int {
        global $DB;
        return $DB->count_records(journal::TABLE, ['cmid' => $cmid]);
    }

    /**
     * A page whose content holds a 2000-byte file uploaded by the teacher.
     *
     * @return \stdClass
     */
    protected function page_with_big_file(): \stdClass {
        global $DB;
        $page = $this->getDataGenerator()->create_module('page', ['course' => $this->course->id]);
        $DB->set_field('page', 'content', '<p><img src="@@PLUGINFILE@@/big.png"></p>', ['id' => $page->id]);
        $this->add_file($page->cmid, 'mod_page', 'content', 'big.png', str_repeat('x', 2000),
            ['userid' => $this->teacher->id]);
        return $page;
    }

    /**
     * Restrict an activity from a date and clean the result.
     *
     * @param int $cmid
     * @param int $time
     * @return array
     */
    protected function restrict(int $cmid, int $time): array {
        return external_api::clean_returnvalue(set_access::execute_returns(), set_access::execute($this->course->id,
            0, $cmid, [], ['action' => 'set', 'time' => $time, 'whenunmet' => 'show'], []));
    }

    /**
     * set_access on a page whose content file is above the site limit: refused, nothing
     * changed, nothing journaled. The uploader's capability counts, not the caller's: a caller
     * with moodle/course:ignorefilesizelimits is refused too.
     */
    public function test_set_access_refused_when_a_file_would_be_dropped() {
        global $DB;
        $page = $this->page_with_big_file();
        set_config('maxbytes', 1000);
        $this->login_ws_user(['moodle/course:manageactivities', 'moodle/course:ignoreavailabilityrestrictions',
            'moodle/course:ignorefilesizelimits']);
        $files = $this->all_files($page->cmid);

        $e = $this->assert_error('filesaltered', function() use ($page) {
            $this->restrict($page->cmid, 1893456000);
        });
        $this->assertStringContainsString('mod_page/content/big.png removed', $e->getMessage());
        $this->assertStringContainsString('mod_page/content/big.png removed', $e->debuginfo);
        $this->assertNull($DB->get_field('course_modules', 'availability', ['id' => $page->cmid]));
        $this->assertSame($files, $this->all_files($page->cmid));
        $this->assertSame(str_repeat('x', 2000), get_file_storage()->get_file(\context_module::instance($page->cmid)->id,
            'mod_page', 'content', 0, '/', 'big.png')->get_content());
        $this->assertSame(0, $this->journal_count($page->cmid));

        // Under the limit, or uploaded by someone who may ignore it, the same call works.
        set_config('maxbytes', 5000);
        $this->assertGreaterThan(0, $this->restrict($page->cmid, 1893456000)['journalid']);
        $this->assertSame($files, $this->all_files($page->cmid));
    }

    /**
     * A file uploaded by an admin (who ignores size limits) is kept, so nothing is refused.
     */
    public function test_file_of_a_user_who_ignores_limits_is_kept() {
        $page = $this->getDataGenerator()->create_module('page', ['course' => $this->course->id]);
        $this->add_file($page->cmid, 'mod_page', 'content', 'big.png', str_repeat('x', 2000),
            ['userid' => get_admin()->id]);
        set_config('maxbytes', 1000);
        $this->login_ws_user(['moodle/course:manageactivities', 'moodle/course:ignoreavailabilityrestrictions']);
        $files = $this->all_files($page->cmid);
        $this->assertGreaterThan(0, $this->restrict($page->cmid, 1893456000)['journalid']);
        $this->assertSame($files, $this->all_files($page->cmid));
    }

    /**
     * A file with no uploader: Moodle checks the caller instead (get_user_max_upload_file_size()
     * falls back to $USER), so the capability on the web service role decides.
     */
    public function test_file_without_uploader_depends_on_the_caller() {
        $page = $this->getDataGenerator()->create_module('page', ['course' => $this->course->id]);
        $this->add_file($page->cmid, 'mod_page', 'content', 'big.png', str_repeat('x', 2000), ['userid' => null]);
        set_config('maxbytes', 1000);
        $this->login_ws_user(['moodle/course:manageactivities', 'moodle/course:ignoreavailabilityrestrictions']);
        $this->assert_error('filesaltered', function() use ($page) {
            $this->restrict($page->cmid, 1893456000);
        });
        assign_capability('moodle/course:ignorefilesizelimits', CAP_ALLOW, $this->wsroleid,
            \context_system::instance()->id, true);
        accesslib_clear_all_caches_for_unit_testing();
        $this->assertGreaterThan(0, $this->restrict($page->cmid, 1893456000)['journalid']);
    }

    /**
     * Undo of an earlier entry goes through the same guard: refused, entry still undoable.
     */
    public function test_undo_refused_when_a_file_would_be_dropped() {
        global $DB;
        $page = $this->getDataGenerator()->create_module('page', ['course' => $this->course->id]);
        $this->login_ws_user(['moodle/course:manageactivities', 'moodle/course:ignoreavailabilityrestrictions']);
        $result = $this->restrict($page->cmid, 1893456000);
        $availability = $DB->get_field('course_modules', 'availability', ['id' => $page->cmid]);

        $this->add_file($page->cmid, 'mod_page', 'content', 'big.png', str_repeat('x', 2000),
            ['userid' => $this->teacher->id]);
        set_config('maxbytes', 1000);
        $files = $this->all_files($page->cmid);
        $this->assert_error('filesaltered', function() use ($result) {
            undo::execute($result['journalid'], null);
        });
        $this->assertSame($availability, $DB->get_field('course_modules', 'availability', ['id' => $page->cmid]));
        $this->assertSame($files, $this->all_files($page->cmid));
        $this->assertSame(1, $this->journal_count($page->cmid));
        $this->assertNull($this->journal_entry($result['journalid'])['undoneby']);

        set_config('maxbytes', 0);
        $undone = external_api::clean_returnvalue(undo::execute_returns(), undo::execute($result['journalid'], null));
        $this->assertGreaterThan(0, $undone['journalid']);
        $this->assertSame($files, $this->all_files($page->cmid));
    }

    /**
     * Assignment dates and settings on an assignment with an intro attachment in a subfolder,
     * which the settings form's intro attachments area (saved without subdirs) drops.
     */
    public function test_assign_writes_refused_when_an_attachment_would_be_dropped() {
        global $DB;
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $this->course->id,
            'duedate' => 1893456000]);
        $this->add_file($assign->cmid, 'mod_assign', 'introattachment', 'brief.pdf', 'PDFDATA');
        $this->add_file($assign->cmid, 'mod_assign', 'introattachment', 'data.csv', 'CSVDATA',
            ['filepath' => '/extra/']);
        $this->login_ws_user(['moodle/course:manageactivities', 'mod/assign:view']);
        $files = $this->all_files($assign->cmid);

        $e = $this->assert_error('filesaltered', function() use ($assign) {
            assign_set_dates::execute($assign->cmid, null, 1893542400, null, null, null);
        });
        $this->assertStringContainsString('mod_assign/introattachment/extra/data.csv removed', $e->getMessage());
        $this->assert_error('filesaltered', function() use ($assign) {
            update_assign_settings::execute($assign->cmid, json_encode(['name' => 'Renamed']), null);
        });
        $this->assertEquals(1893456000, $DB->get_field('assign', 'duedate', ['id' => $assign->id]));
        $this->assertNotSame('Renamed', $DB->get_field('assign', 'name', ['id' => $assign->id]));
        $this->assertSame($files, $this->all_files($assign->cmid));
        $this->assertSame(0, $this->journal_count($assign->cmid));

        // Without the subfolder file the attachments stay and the change goes through.
        get_file_storage()->get_file(\context_module::instance($assign->cmid)->id, 'mod_assign', 'introattachment', 0,
            '/extra/', 'data.csv')->delete();
        $files = $this->all_files($assign->cmid);
        $result = external_api::clean_returnvalue(assign_set_dates::execute_returns(),
            assign_set_dates::execute($assign->cmid, null, 1893542400, null, null, null));
        $this->assertGreaterThan(0, $result['journalid']);
        $this->assertSame($files, $this->all_files($assign->cmid));
    }

    /**
     * Quiz overall feedback gets new item ids on every save (quiz_after_add_or_update()); its
     * files are copied to them and that is not a change.
     */
    public function test_quiz_feedback_files_move_without_refusal() {
        global $DB;
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $this->course->id, 'grade' => 50,
            'sumgrades' => 2, 'timeopen' => 1893456000]);
        $DB->delete_records('quiz_feedback', ['quizid' => $quiz->id]);
        $ids = [];
        $ids[] = $DB->insert_record('quiz_feedback', (object)['quizid' => $quiz->id,
            'feedbacktext' => '<p>Well done <img src="@@PLUGINFILE@@/star.png"></p>', 'feedbacktextformat' => FORMAT_HTML,
            'mingrade' => 25, 'maxgrade' => 51]);
        $ids[] = $DB->insert_record('quiz_feedback', (object)['quizid' => $quiz->id,
            'feedbacktext' => '<p>Try again <img src="@@PLUGINFILE@@/star.png"></p>', 'feedbacktextformat' => FORMAT_HTML,
            'mingrade' => 0, 'maxgrade' => 25]);
        foreach ($ids as $id) {
            $this->add_file($quiz->cmid, 'mod_quiz', 'feedback', 'star.png', 'STAR', ['itemid' => $id]);
        }
        $this->login_ws_user(['moodle/course:manageactivities']);

        $result = external_api::clean_returnvalue(quiz_set_dates::execute_returns(),
            quiz_set_dates::execute($quiz->cmid, 1893542400, null, null));
        $this->assertGreaterThan(0, $result['journalid']);
        $newids = $DB->get_fieldset_select('quiz_feedback', 'id', 'quizid = ?', [$quiz->id]);
        $this->assertCount(2, $newids);
        $this->assertEmpty(array_intersect($ids, $newids));
        $fs = get_file_storage();
        // Moodle copies the files to the new ids and leaves the old ones where they were.
        foreach (array_merge($ids, $newids) as $id) {
            $this->assertTrue($fs->file_exists(\context_module::instance($quiz->cmid)->id, 'mod_quiz', 'feedback', $id, '/',
                'star.png'));
        }
    }

    /**
     * Another text the call does not change is guarded too: a stored description with a
     * draftfile.php link (file_prepare_draft_area() rewrites it to brokenfile.php) refuses a
     * date change instead of saving the rewritten text.
     */
    public function test_text_rewritten_by_the_form_is_refused() {
        global $CFG, $DB;
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $this->course->id,
            'duedate' => 1893456000]);
        $intro = '<p><img src="' . $CFG->wwwroot . '/draftfile.php/5/user/draft/1/a.png"></p>';
        $DB->set_field('assign', 'intro', $intro, ['id' => $assign->id]);
        $this->login_ws_user(['moodle/course:manageactivities', 'mod/assign:view']);
        $e = $this->assert_error('filesaltered', function() use ($assign) {
            assign_set_dates::execute($assign->cmid, null, 1893542400, null, null, null);
        });
        $this->assertStringContainsString('assign.intro text changed', $e->getMessage());
        $this->assertSame($intro, $DB->get_field('assign', 'intro', ['id' => $assign->id]));
        $this->assertEquals(1893456000, $DB->get_field('assign', 'duedate', ['id' => $assign->id]));
    }
}

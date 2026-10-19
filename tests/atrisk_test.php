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
 * Tests for atrisk in local_paatshala_get_content and local_paatshala_get_module_settings.
 *
 * @package    local_paatshala
 * @copyright  2026 local_paatshala contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_paatshala;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once(__DIR__ . '/helper.php');

use external_api;
use local_paatshala\external\get_content;
use local_paatshala\external\get_module_settings;
use local_paatshala\local\modform;

/**
 * Tests for atrisk: the files a settings-form save would drop, as the guard sees them.
 *
 * Every scenario checks that both read functions list the same files, and that a settings save
 * with no changes is refused with filesaltered exactly when the list is not empty.
 *
 * @package    local_paatshala
 * @copyright  2026 local_paatshala contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class atrisk_test extends testcase {

    /** @var \stdClass */
    protected $course;

    /** @var \stdClass A teacher, whose uploads the site limit applies to. */
    protected $teacher;

    /**
     * Course, plugin on, a site upload limit of 1000 bytes, a ws user.
     */
    protected function setUp(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->course = $this->getDataGenerator()->create_course();
        $this->teacher = $this->getDataGenerator()->create_and_enrol($this->course, 'editingteacher');
        $this->enable_plugin('*');
        set_config('maxbytes', 1000);
    }

    /**
     * Log in as the web service user, optionally allowed to ignore size limits.
     *
     * @param bool $ignorelimits
     */
    protected function login(bool $ignorelimits = false) {
        $capabilities = ['moodle/course:manageactivities', 'mod/feedback:view', 'mod/lesson:view', 'mod/label:view'];
        if ($ignorelimits) {
            $capabilities[] = 'moodle/course:ignorefilesizelimits';
        }
        $this->login_ws_user($capabilities);
    }

    /**
     * Store a file in an activity's area.
     *
     * @param int $cmid
     * @param string $area component/filearea
     * @param string $path
     * @param string $name
     * @param int $size
     * @param int|null $userid Uploader.
     */
    protected function add_file(int $cmid, string $area, string $path, string $name, int $size, $userid) {
        list($component, $filearea) = explode('/', $area);
        get_file_storage()->create_file_from_string(['contextid' => \context_module::instance($cmid)->id,
            'component' => $component, 'filearea' => $filearea, 'itemid' => 0, 'filepath' => $path, 'filename' => $name,
            'userid' => $userid], str_repeat('x', $size));
    }

    /**
     * Assert that both reads list these files at risk, and that a no-op settings save is refused
     * with filesaltered exactly when the list is not empty.
     *
     * @param int $cmid
     * @param array $expected List of {area, path, name, reason}.
     */
    protected function assert_agrees(int $cmid, array $expected) {
        $content = external_api::clean_returnvalue(get_content::execute_returns(), get_content::execute($cmid));
        $settings = external_api::clean_returnvalue(get_module_settings::execute_returns(),
            get_module_settings::execute($cmid));
        $this->assertSame($expected, $content['atrisk']);
        $this->assertSame($expected, $settings['atrisk']);

        list($course, $cm) = get_course_and_cm_from_cmid($cmid);
        $cm = get_coursemodule_from_id('', $cmid, 0, false, MUST_EXIST);
        external_api::validate_context(\context_module::instance($cmid));
        $refused = false;
        try {
            modform::save($cm, $course, []);
        } catch (\moodle_exception $e) {
            $this->assertSame('filesaltered', $e->errorcode, $e->getMessage());
            $refused = true;
            foreach ($expected as $file) {
                $this->assertStringContainsString($file['area'] . $file['path'] . $file['name'] . ' removed',
                    $e->getMessage());
            }
        }
        $this->assertSame($expected !== [], $refused, $expected ? 'Expected filesaltered' : 'Expected the save to pass');
    }

    /**
     * A page content file above the site limit, uploaded by a teacher: at risk ("size"), and the
     * save is refused, also for a caller who may ignore size limits.
     */
    public function test_page_file_over_the_site_limit() {
        $page = $this->getDataGenerator()->create_module('page', ['course' => $this->course->id]);
        $this->add_file($page->cmid, 'mod_page/content', '/', 'big.png', 2000, $this->teacher->id);
        $this->add_file($page->cmid, 'mod_page/content', '/', 'small.png', 500, $this->teacher->id);
        $this->add_file($page->cmid, 'mod_page/intro', '/', 'intro.png', 2000, $this->teacher->id);
        $expected = [['area' => 'mod_page/content', 'path' => '/', 'name' => 'big.png', 'reason' => 'size']];
        $this->login(true);
        $this->assert_agrees($page->cmid, $expected);
    }

    /**
     * The same file uploaded by an admin (who ignores size limits) is not at risk.
     */
    public function test_page_file_of_an_admin_is_not_at_risk() {
        $page = $this->getDataGenerator()->create_module('page', ['course' => $this->course->id]);
        $this->add_file($page->cmid, 'mod_page/content', '/', 'big.png', 2000, get_admin()->id);
        $this->login();
        $this->assert_agrees($page->cmid, []);
    }

    /**
     * A file with no uploader: the caller decides, as in Moodle.
     */
    public function test_page_file_without_uploader_follows_the_caller() {
        $page = $this->getDataGenerator()->create_module('page', ['course' => $this->course->id]);
        $this->add_file($page->cmid, 'mod_page/content', '/sub/', 'big.png', 2000, null);
        $this->login();
        $this->assert_agrees($page->cmid, [['area' => 'mod_page/content', 'path' => '/sub/', 'name' => 'big.png',
            'reason' => 'size']]);
        assign_capability('moodle/course:ignorefilesizelimits', CAP_ALLOW, $this->wsroleid,
            \context_system::instance()->id, true);
        accesslib_clear_all_caches_for_unit_testing();
        $this->assert_agrees($page->cmid, []);
    }

    /**
     * An assignment intro attachment in a subfolder: at risk ("subfolder"); top-level ones are not.
     */
    public function test_assign_attachment_in_a_subfolder() {
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $this->course->id]);
        $this->add_file($assign->cmid, 'mod_assign/introattachment', '/', 'brief.pdf', 2000, $this->teacher->id);
        $this->add_file($assign->cmid, 'mod_assign/introattachment', '/extra/', 'data.csv', 10, $this->teacher->id);
        $this->login();
        $this->assert_agrees($assign->cmid, [['area' => 'mod_assign/introattachment', 'path' => '/extra/',
            'name' => 'data.csv', 'reason' => 'subfolder']]);
    }

    /**
     * Feedback's page after submit and a lesson's media file are saved without subfolders too.
     */
    public function test_feedback_and_lesson_subfolders() {
        $feedback = $this->getDataGenerator()->create_module('feedback', ['course' => $this->course->id]);
        $this->add_file($feedback->cmid, 'mod_feedback/page_after_submit', '/img/', 'thanks.png', 10, $this->teacher->id);
        $lesson = $this->getDataGenerator()->create_module('lesson', ['course' => $this->course->id]);
        $this->add_file($lesson->cmid, 'mod_lesson/mediafile', '/media/', 'clip.mp3', 10, $this->teacher->id);
        $this->login();
        $this->assert_agrees($feedback->cmid, [['area' => 'mod_feedback/page_after_submit', 'path' => '/img/',
            'name' => 'thanks.png', 'reason' => 'subfolder']]);
        $this->assert_agrees($lesson->cmid, [['area' => 'mod_lesson/mediafile', 'path' => '/media/',
            'name' => 'clip.mp3', 'reason' => 'subfolder']]);
    }

    /**
     * Clean modules: nothing at risk, and the save passes. Large files and subfolders where Moodle
     * keeps them (descriptions, workshop instructions, a label) are not at risk.
     */
    public function test_clean_modules() {
        $page = $this->getDataGenerator()->create_module('page', ['course' => $this->course->id]);
        $this->add_file($page->cmid, 'mod_page/content', '/', 'small.png', 500, $this->teacher->id);
        $this->add_file($page->cmid, 'mod_page/content', '/sub/', 'small2.png', 500, $this->teacher->id);
        $workshop = $this->getDataGenerator()->create_module('workshop', ['course' => $this->course->id]);
        $this->add_file($workshop->cmid, 'mod_workshop/instructauthors', '/sub/', 'big.png', 2000, $this->teacher->id);
        $label = $this->getDataGenerator()->create_module('label', ['course' => $this->course->id]);
        $this->add_file($label->cmid, 'mod_label/intro', '/sub/', 'big.png', 2000, $this->teacher->id);
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $this->course->id]);
        $this->login();
        foreach ([$page, $workshop, $label, $assign] as $module) {
            $this->assert_agrees($module->cmid, []);
        }
    }
}

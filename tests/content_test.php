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
 * Tests for activity texts (local_paatshala_get_content, local_paatshala_update_content).
 *
 * @package    local_paatshala
 * @copyright  2026 local_paatshala contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_paatshala;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once(__DIR__ . '/helper.php');
require_once($CFG->dirroot . '/mod/label/lib.php');

use external_api;
use local_paatshala\external\get_content;
use local_paatshala\external\undo;
use local_paatshala\external\update_content;

/**
 * Tests for activity texts.
 *
 * @package    local_paatshala
 * @copyright  2026 local_paatshala contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class content_test extends testcase {

    /** @var \stdClass */
    protected $course;

    /** @var string A page's description, with an embedded image. */
    const INTRO = '<p>Read this first.</p><p><img src="@@PLUGINFILE@@/diagram%20one.png" alt="Diagram"></p>';

    /** @var string A page's content, with an embedded image and a linked PDF. */
    const CONTENT = '<p>Week 1</p><p><img src="@@PLUGINFILE@@/photo.jpg" alt="Photo"> ' .
        '<a href="@@PLUGINFILE@@/notes.pdf">Notes</a></p>';

    /**
     * Course, plugin on.
     */
    protected function setUp(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->course = $this->getDataGenerator()->create_course();
        $this->enable_plugin('*');
    }

    /**
     * Store a file in an activity's text area.
     *
     * @param int $cmid
     * @param string $component
     * @param string $filearea
     * @param string $filename
     * @param string $content
     * @param array $extra More file record fields.
     * @return \stored_file
     */
    protected function add_file(int $cmid, string $component, string $filearea, string $filename, string $content,
            array $extra = []): \stored_file {
        return get_file_storage()->create_file_from_string(['contextid' => \context_module::instance($cmid)->id,
            'component' => $component, 'filearea' => $filearea, 'itemid' => 0, 'filepath' => '/',
            'filename' => $filename] + $extra, $content);
    }

    /**
     * A page with an image in its description and an image and a PDF in its content.
     *
     * @return \stdClass
     */
    protected function page(): \stdClass {
        global $DB;
        $page = $this->getDataGenerator()->create_module('page', ['course' => $this->course->id, 'name' => 'Week 1']);
        $DB->update_record('page', (object)['id' => $page->id, 'intro' => self::INTRO, 'introformat' => FORMAT_HTML,
            'content' => self::CONTENT, 'contentformat' => FORMAT_HTML]);
        $this->add_file($page->cmid, 'mod_page', 'intro', 'diagram one.png', 'PNGDATA');
        $this->add_file($page->cmid, 'mod_page', 'content', 'photo.jpg', 'JPGDATA');
        $this->add_file($page->cmid, 'mod_page', 'content', 'notes.pdf', 'PDFDATA');
        return $page;
    }

    /**
     * Change a text and clean the result.
     *
     * @param int $cmid
     * @param string $field
     * @param string $text
     * @param int $format
     * @param array|null $expected
     * @param bool $dropreferences
     * @return array
     */
    protected function update(int $cmid, string $field, string $text, int $format = FORMAT_HTML, $expected = null,
            bool $dropreferences = false): array {
        return external_api::clean_returnvalue(update_content::execute_returns(), update_content::execute($cmid,
            $field, $text, $format, $expected === null ? null : json_encode($expected), $dropreferences));
    }

    /**
     * Read the texts and clean the result.
     *
     * @param int $cmid
     * @return array Field => field result.
     */
    protected function content(int $cmid): array {
        $result = external_api::clean_returnvalue(get_content::execute_returns(), get_content::execute($cmid));
        $fields = [];
        foreach ($result['fields'] as $field) {
            $fields[$field['field']] = $field;
        }
        return $fields;
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
     * Every file of an activity's context: area/itemid/path/name => [contenthash, filesize].
     *
     * @param int $cmid
     * @return array
     */
    protected function all_files(int $cmid): array {
        global $DB;
        $files = [];
        $context = \context_module::instance($cmid);
        foreach ($DB->get_records('files', ['contextid' => $context->id]) as $file) {
            if ($file->filename !== '.') {
                $files[$file->component . '/' . $file->filearea . '/' . $file->itemid . $file->filepath .
                    $file->filename] = [$file->contenthash, (int)$file->filesize];
            }
        }
        ksort($files);
        return $files;
    }

    /**
     * get_content: every text field with its files, references and missing references.
     */
    public function test_get_content() {
        $page = $this->page();
        $this->login_ws_user(['moodle/course:manageactivities']);
        $result = external_api::clean_returnvalue(get_content::execute_returns(), get_content::execute($page->cmid));
        $this->assertSame((int)$page->cmid, $result['cmid']);
        $this->assertSame((int)$this->course->id, $result['courseid']);
        $this->assertSame('page', $result['modname']);
        $this->assertSame('Week 1', $result['name']);
        $this->assertSame(['intro', 'content'], array_column($result['fields'], 'field'));

        $fields = $this->content($page->cmid);
        $this->assertSame(self::INTRO, $fields['intro']['text']);
        $this->assertSame((int)FORMAT_HTML, $fields['intro']['format']);
        $this->assertSame(['diagram one.png'], $fields['intro']['references']);
        $this->assertSame([], $fields['intro']['missing']);
        $this->assertCount(1, $fields['intro']['files']);
        $this->assertSame(['filepath' => '/', 'filename' => 'diagram one.png', 'contenthash' => sha1('PNGDATA'),
            'filesize' => 7, 'mimetype' => 'image/png'], $fields['intro']['files'][0]);
        $this->assertSame(['notes.pdf', 'photo.jpg'], $fields['content']['references']);
        $this->assertSame(['notes.pdf', 'photo.jpg'], array_column($fields['content']['files'], 'filename'));

        // A reference with no file is listed as missing.
        get_file_storage()->get_file(\context_module::instance($page->cmid)->id, 'mod_page', 'content', 0, '/',
            'notes.pdf')->delete();
        $this->assertSame(['notes.pdf'], $this->content($page->cmid)['content']['missing']);

        // Workshop: description and the three instructions; a label: the description only.
        $workshop = $this->getDataGenerator()->create_module('workshop', ['course' => $this->course->id]);
        $this->assertSame(['intro', 'instructauthors', 'instructreviewers', 'conclusion'],
            array_keys($this->content($workshop->cmid)));
        $label = $this->getDataGenerator()->create_module('label', ['course' => $this->course->id]);
        $this->assertSame(['intro'], array_keys($this->content($label->cmid)));
    }

    /**
     * Description with an embedded image: the text changes, the files stay, undo restores
     * text and format byte for byte.
     */
    public function test_intro_keeps_files_and_undo() {
        global $DB;
        $page = $this->page();
        $this->login_ws_user(['moodle/course:manageactivities']);
        $allfiles = $this->all_files($page->cmid);
        $this->assertCount(3, $allfiles);
        $before = $this->content($page->cmid)['intro'];

        $text = "<p>Read this <strong>before</strong> the lab.</p>\r\n<p><img src=\"@@PLUGINFILE@@/diagram%20one.png\" " .
            "alt=\"Diagram\" width=\"400\"></p>  ";
        $result = $this->update($page->cmid, 'intro', $text, FORMAT_HTML, ['text' => $before['text'],
            'format' => $before['format'], 'files' => $before['files']]);
        $this->assertGreaterThan(0, $result['journalid']);
        $this->assertTrue($result['undoable']);
        $this->assertFalse($result['notifies_students']);
        $this->assertSame([], $result['warnings']);

        $record = $DB->get_record('page', ['id' => $page->id]);
        $this->assertSame($text, $record->intro);
        $this->assertEquals(FORMAT_HTML, $record->introformat);
        $this->assertSame(self::CONTENT, $record->content);
        $this->assertSame($allfiles, $this->all_files($page->cmid));

        $files = [['filepath' => '/', 'filename' => 'diagram one.png', 'contenthash' => sha1('PNGDATA')]];
        $this->assertSame(['field' => 'intro', 'text' => self::INTRO, 'format' => 1, 'files' => $files],
            json_decode($result['before'], true));
        $this->assertSame(['field' => 'intro', 'text' => $text, 'format' => 1, 'files' => $files],
            json_decode($result['after'], true));
        $entry = $this->journal_entry($result['journalid']);
        $this->assertSame('module_content', $entry['kind']);
        $this->assertEquals($page->cmid, $entry['cmid']);
        $this->assertSame(['cmid' => (int)$page->cmid, 'modname' => 'page', 'name' => 'Week 1', 'field' => 'intro'],
            $entry['target']);

        $undone = $this->undo($result['journalid']);
        $this->assertGreaterThan(0, $undone['journalid']);
        $record = $DB->get_record('page', ['id' => $page->id]);
        $this->assertSame(self::INTRO, $record->intro);
        $this->assertEquals(FORMAT_HTML, $record->introformat);
        $this->assertSame($allfiles, $this->all_files($page->cmid));
        $this->assertSame($before['files'], $this->content($page->cmid)['intro']['files']);
        $this->assertEquals($undone['journalid'], $this->journal_entry($result['journalid'])['undoneby']);

        // Redo, then the same text again changes nothing.
        $this->undo($undone['journalid']);
        $this->assertSame($text, $DB->get_field('page', 'intro', ['id' => $page->id]));
        $again = $this->update($page->cmid, 'intro', $text);
        $this->assertSame(0, $again['journalid']);
        $this->assertSame('nochange', $again['warnings'][0]['code']);
    }

    /**
     * An assignment's description: its embedded image and its attachments stay; the other
     * settings too.
     */
    public function test_assign_intro() {
        global $DB;
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $this->course->id,
            'intro' => '<p>Brief <img src="@@PLUGINFILE@@/pic.png"></p>', 'introformat' => FORMAT_HTML,
            'duedate' => 1893456000, 'assignsubmission_onlinetext_enabled' => 1,
            'assignsubmission_onlinetext_wordlimit_enabled' => 1, 'assignsubmission_onlinetext_wordlimit' => 300]);
        $this->add_file($assign->cmid, 'mod_assign', 'intro', 'pic.png', 'PNGDATA');
        $this->add_file($assign->cmid, 'mod_assign', 'introattachment', 'brief.pdf', 'PDFDATA');
        $this->login_ws_user(['moodle/course:manageactivities', 'mod/assign:view']);
        $files = $this->all_files($assign->cmid);
        $this->assertCount(2, $files);
        $old = $DB->get_record('assign', ['id' => $assign->id]);

        $text = '<p>Brief, updated <img src="@@PLUGINFILE@@/pic.png"></p>';
        $result = $this->update($assign->cmid, 'intro', $text);
        $new = $DB->get_record('assign', ['id' => $assign->id]);
        $this->assertSame($text, $new->intro);
        $this->assertSame($old->duedate, $new->duedate);
        $this->assertSame($old->grade, $new->grade);
        $this->assertSame($files, $this->all_files($assign->cmid));
        $this->undo($result['journalid']);
        $this->assertSame($old->intro, $DB->get_field('assign', 'intro', ['id' => $assign->id]));
        $this->assertSame($files, $this->all_files($assign->cmid));
    }

    /**
     * Dropping a reference: refused unless asked; the file stays; undo makes it work again.
     */
    public function test_drop_reference() {
        global $DB;
        $page = $this->page();
        $this->login_ws_user(['moodle/course:manageactivities']);
        $files = $this->all_files($page->cmid);
        $text = '<p>Week 1</p><p><img src="@@PLUGINFILE@@/photo.jpg" alt="Photo"></p>';

        $e = $this->assert_error('dropsreference', function() use ($page, $text) {
            $this->update($page->cmid, 'content', $text);
        });
        $this->assertStringContainsString('notes.pdf', $e->getMessage());
        $this->assertSame(self::CONTENT, $DB->get_field('page', 'content', ['id' => $page->id]));

        $result = $this->update($page->cmid, 'content', $text, FORMAT_HTML, null, true);
        $this->assertSame('droppedreference', $result['warnings'][0]['code']);
        $this->assertStringContainsString('notes.pdf', $result['warnings'][0]['message']);
        $this->assertSame($text, $DB->get_field('page', 'content', ['id' => $page->id]));
        $this->assertSame($files, $this->all_files($page->cmid));
        $this->assertSame([], $this->content($page->cmid)['content']['missing']);

        $this->undo($result['journalid']);
        $content = $this->content($page->cmid)['content'];
        $this->assertSame(self::CONTENT, $content['text']);
        $this->assertSame(['notes.pdf', 'photo.jpg'], $content['references']);
        $this->assertSame([], $content['missing']);
        $this->assertSame($files, $this->all_files($page->cmid));

        // A reference to a file that is not there may be dropped freely.
        get_file_storage()->get_file(\context_module::instance($page->cmid)->id, 'mod_page', 'content', 0, '/',
            'notes.pdf')->delete();
        $this->assertGreaterThan(0, $this->update($page->cmid, 'content', $text)['journalid']);
    }

    /**
     * A new reference to a file that is not in the area, and draftfile.php links, are refused.
     */
    public function test_reference_to_missing_file() {
        global $DB;
        $page = $this->page();
        $this->login_ws_user(['moodle/course:manageactivities']);
        $e = $this->assert_error('invalidsettings', function() use ($page) {
            $this->update($page->cmid, 'intro', self::INTRO . '<p><img src="@@PLUGINFILE@@/sub/missing%20pic.png"></p>');
        });
        $this->assertStringContainsString('sub/missing pic.png', $e->getMessage());
        // The content's files are not the description's.
        $this->assert_error('invalidsettings', function() use ($page) {
            $this->update($page->cmid, 'intro', self::INTRO . '<a href="@@PLUGINFILE@@/notes.pdf">Notes</a>');
        });
        $this->assert_error('invalidparameter', function() use ($page) {
            $this->update($page->cmid, 'intro', '<img src="https://example.com/draftfile.php/5/user/draft/7/a.png">');
        });
        $this->assertSame(self::INTRO, $DB->get_field('page', 'intro', ['id' => $page->id]));

        // A missing reference the text already had may stay.
        $DB->set_field('page', 'intro', self::INTRO . '<img src="@@PLUGINFILE@@/gone.png">', ['id' => $page->id]);
        $result = $this->update($page->cmid, 'intro', '<p>New</p>' . self::INTRO . '<img src="@@PLUGINFILE@@/gone.png">');
        $this->assertGreaterThan(0, $result['journalid']);
    }

    /**
     * Page content: revision increments, the content's files stay, the description too.
     */
    public function test_page_content() {
        global $DB;
        $page = $this->page();
        $this->login_ws_user(['moodle/course:manageactivities']);
        $revision = (int)$DB->get_field('page', 'revision', ['id' => $page->id]);
        $files = $this->all_files($page->cmid);
        $text = self::CONTENT . '<h3>Week 2</h3><p>Second <img src="@@PLUGINFILE@@/photo.jpg"> again</p>';

        $this->update($page->cmid, 'content', $text, FORMAT_HTML);
        $record = $DB->get_record('page', ['id' => $page->id]);
        $this->assertSame($text, $record->content);
        $this->assertEquals(FORMAT_HTML, $record->contentformat);
        $this->assertSame(self::INTRO, $record->intro);
        $this->assertEquals($revision + 1, $record->revision);
        $this->assertSame($files, $this->all_files($page->cmid));
    }

    /**
     * Workshop instructions and conclusion, each with a file; the other texts stay.
     */
    public function test_workshop_fields() {
        global $DB;
        $workshop = $this->getDataGenerator()->create_module('workshop', ['course' => $this->course->id]);
        $fields = ['instructauthors', 'instructreviewers', 'conclusion'];
        foreach ($fields as $field) {
            $DB->set_field('workshop', $field, '<p>' . $field . ' <img src="@@PLUGINFILE@@/' . $field . '.png"></p>',
                ['id' => $workshop->id]);
            $DB->set_field('workshop', $field . 'format', FORMAT_HTML, ['id' => $workshop->id]);
            $this->add_file($workshop->cmid, 'mod_workshop', $field, $field . '.png', 'IMG' . $field);
        }
        $this->login_ws_user(['moodle/course:manageactivities']);
        $files = $this->all_files($workshop->cmid);
        $this->assertCount(3, $files);

        $journalids = [];
        foreach ($fields as $field) {
            $old = $DB->get_record('workshop', ['id' => $workshop->id]);
            $text = '<p>New ' . $field . '</p><p><img src="@@PLUGINFILE@@/' . $field . '.png"></p>';
            $result = $this->update($workshop->cmid, $field, $text, FORMAT_HTML);
            $journalids[] = $result['journalid'];
            $record = $DB->get_record('workshop', ['id' => $workshop->id]);
            $this->assertSame($text, $record->$field);
            foreach (array_diff($fields, [$field]) as $other) {
                $this->assertSame($old->$other, $record->$other);
                $this->assertSame($old->{$other . 'format'}, $record->{$other . 'format'});
            }
            $this->assertSame($old->intro, $record->intro);
            $this->assertSame($old->phase, $record->phase);
            $this->assertSame($files, $this->all_files($workshop->cmid));
        }
        // Undo newest first.
        foreach (array_reverse($journalids) as $journalid) {
            $this->undo($journalid);
        }
        foreach ($fields as $field) {
            $this->assertSame('<p>' . $field . ' <img src="@@PLUGINFILE@@/' . $field . '.png"></p>',
                $DB->get_field('workshop', $field, ['id' => $workshop->id]));
        }
        $this->assertSame($files, $this->all_files($workshop->cmid));
    }

    /**
     * Each format is stored as given and read back; other formats are refused.
     */
    public function test_formats() {
        global $DB;
        $page = $this->page();
        $this->login_ws_user(['moodle/course:manageactivities']);
        $texts = [FORMAT_MOODLE => 'Plain *moodle* text', FORMAT_HTML => '<p>HTML</p>', FORMAT_PLAIN => "Line 1\nLine 2",
            FORMAT_MARKDOWN => "# Title\n\n![Photo](@@PLUGINFILE@@/photo.jpg) [Notes](@@PLUGINFILE@@/notes.pdf)"];
        foreach ($texts as $format => $text) {
            $text .= ' @@PLUGINFILE@@/photo.jpg @@PLUGINFILE@@/notes.pdf';
            $this->update($page->cmid, 'content', $text, $format);
            $record = $DB->get_record('page', ['id' => $page->id]);
            $this->assertSame($text, $record->content);
            $this->assertEquals($format, $record->contentformat);
            $this->assertSame((int)$format, $this->content($page->cmid)['content']['format']);
        }
        // Format only.
        $result = $this->update($page->cmid, 'content', $record->content, FORMAT_HTML);
        $this->assertGreaterThan(0, $result['journalid']);
        $this->assertEquals(FORMAT_HTML, $DB->get_field('page', 'contentformat', ['id' => $page->id]));
        $this->undo($result['journalid']);
        $this->assertEquals(FORMAT_MARKDOWN, $DB->get_field('page', 'contentformat', ['id' => $page->id]));

        foreach ([3, 5, -1] as $format) {
            $this->assert_error('invalidparameter', function() use ($page, $format) {
                $this->update($page->cmid, 'content', 'x', $format);
            });
        }
    }

    /**
     * Conflicts on text, format and files; the message holds a hash, not the text.
     */
    public function test_conflicts() {
        $page = $this->page();
        $this->login_ws_user(['moodle/course:manageactivities']);
        $current = $this->content($page->cmid)['intro'];
        $new = self::INTRO . '<p>More</p>';

        $e = $this->assert_error('conflict', function() use ($page, $new) {
            $this->update($page->cmid, 'intro', $new, FORMAT_HTML, ['text' => '<p>Old</p>']);
        });
        $this->assertStringNotContainsString('Read this first', $e->getMessage());
        $this->assertStringContainsString(sha1(self::INTRO), $e->getMessage());
        $this->assertStringContainsString('"textlength":' . strlen(self::INTRO), $e->getMessage());
        $this->assert_error('conflict', function() use ($page, $new) {
            $this->update($page->cmid, 'intro', $new, FORMAT_HTML, ['format' => FORMAT_MOODLE]);
        });
        $this->assert_error('conflict', function() use ($page, $new) {
            $this->update($page->cmid, 'intro', $new, FORMAT_HTML, ['files' => []]);
        });
        $files = $current['files'];
        $files[0]['contenthash'] = sha1('other');
        $this->assert_error('conflict', function() use ($page, $new, $files) {
            $this->update($page->cmid, 'intro', $new, FORMAT_HTML, ['files' => $files]);
        });
        $this->assert_error('invalidparameter', function() use ($page, $new) {
            $this->update($page->cmid, 'intro', $new, FORMAT_HTML, ['field' => 'intro']);
        });
        $this->assert_error('invalidparameter', function() use ($page, $new) {
            $this->update($page->cmid, 'intro', $new, FORMAT_HTML, ['files' => 'diagram one.png']);
        });
        // The result of get_content works as expected (file sizes and types are ignored).
        $result = $this->update($page->cmid, 'intro', $new, FORMAT_HTML, ['text' => $current['text'],
            'format' => $current['format'], 'files' => $current['files']]);
        $this->assertGreaterThan(0, $result['journalid']);
    }

    /**
     * Undo is refused once the text, the format or the files changed since.
     */
    public function test_undo_conflicts() {
        global $DB;
        $page = $this->page();
        $this->login_ws_user(['moodle/course:manageactivities']);
        $result = $this->update($page->cmid, 'intro', '<p>New</p>' . self::INTRO);

        // A file removed by hand: the old text's reference would not work, so no undo.
        $file = get_file_storage()->get_file(\context_module::instance($page->cmid)->id, 'mod_page', 'intro', 0, '/',
            'diagram one.png');
        $file->delete();
        $this->assert_error('conflict', function() use ($result) {
            $this->undo($result['journalid']);
        });
        $this->add_file($page->cmid, 'mod_page', 'intro', 'diagram one.png', 'OTHERPNG');
        $this->assert_error('conflict', function() use ($result) {
            $this->undo($result['journalid']);
        });
        $file = get_file_storage()->get_file(\context_module::instance($page->cmid)->id, 'mod_page', 'intro', 0, '/',
            'diagram one.png');
        $file->delete();
        $this->add_file($page->cmid, 'mod_page', 'intro', 'diagram one.png', 'PNGDATA');

        $DB->set_field('page', 'introformat', FORMAT_MOODLE, ['id' => $page->id]);
        $this->assert_error('conflict', function() use ($result) {
            $this->undo($result['journalid']);
        });
        $DB->set_field('page', 'introformat', FORMAT_HTML, ['id' => $page->id]);
        $DB->set_field('page', 'intro', '<p>Edited in Paatshala</p>', ['id' => $page->id]);
        $this->assert_error('conflict', function() use ($result) {
            $this->undo($result['journalid']);
        });
        $DB->set_field('page', 'intro', '<p>New</p>' . self::INTRO, ['id' => $page->id]);
        $this->undo($result['journalid']);
        $this->assertSame(self::INTRO, $DB->get_field('page', 'intro', ['id' => $page->id]));
    }

    /**
     * A Moodle path that drops a file while saving (a content file above the site upload
     * limit, uploaded by a user the limit applies to) is refused and rolled back.
     */
    public function test_files_altered_rolls_back() {
        global $DB;
        $page = $this->page();
        $student = $this->getDataGenerator()->create_user();
        $this->add_file($page->cmid, 'mod_page', 'content', 'big.bin', str_repeat('x', 2000),
            ['userid' => $student->id]);
        set_config('maxbytes', 1000);
        $this->login_ws_user(['moodle/course:manageactivities']);
        $files = $this->all_files($page->cmid);

        $e = $this->assert_error('filesaltered', function() use ($page) {
            $this->update($page->cmid, 'intro', '<p>New</p>' . self::INTRO);
        });
        $this->assertStringContainsString('content', $e->getMessage());
        $this->assertSame(self::INTRO, $DB->get_field('page', 'intro', ['id' => $page->id]));
        $this->assertSame($files, $this->all_files($page->cmid));
        $this->assertSame(str_repeat('x', 2000), get_file_storage()->get_file(\context_module::instance($page->cmid)->id,
            'mod_page', 'content', 0, '/', 'big.bin')->get_content());
        $this->assertSame(0, $DB->count_records(journal::TABLE, ['cmid' => $page->cmid]));
    }

    /**
     * A label is named after its text, as on the settings page; undo names it back.
     */
    public function test_label_name() {
        global $DB;
        $label = $this->getDataGenerator()->create_module('label', ['course' => $this->course->id,
            'intro' => '<p>Old notice</p>', 'introformat' => FORMAT_HTML]);
        $this->assertSame('Old notice', $DB->get_field('label', 'name', ['id' => $label->id]));
        $this->login_ws_user(['moodle/course:manageactivities']);

        $text = '<p><strong>Lab moved</strong> to room 4 on Friday, bring the printed worksheet and a pen please</p>';
        $result = $this->update($label->cmid, 'intro', $text);
        $name = $DB->get_field('label', 'name', ['id' => $label->id]);
        $this->assertSame(get_label_name((object)['intro' => $text]), $name);
        $this->assertSame('Lab moved to room 4 on Friday, bring the printed w...', $name);
        $this->assertSame('namechanged', $result['warnings'][0]['code']);
        $this->assertStringContainsString($name, $result['warnings'][0]['message']);
        $this->assertSame($text, $DB->get_field('label', 'intro', ['id' => $label->id]));

        $this->undo($result['journalid']);
        $this->assertSame('Old notice', $DB->get_field('label', 'name', ['id' => $label->id]));
        $this->assertSame('<p>Old notice</p>', $DB->get_field('label', 'intro', ['id' => $label->id]));
    }

    /**
     * Gate errors, the capability, and fields the activity does not have.
     */
    public function test_gate_and_fields() {
        $page = $this->page();
        $label = $this->getDataGenerator()->create_module('label', ['course' => $this->course->id]);
        $this->login_ws_user(['moodle/course:manageactivities']);

        $this->assert_error('invalidparameter', function() use ($label) {
            $this->update($label->cmid, 'content', '<p>x</p>');
        });
        $this->assert_error('invalidparameter', function() use ($page) {
            $this->update($page->cmid, 'conclusion', '<p>x</p>');
        });
        $this->assert_error('invalidparameter', function() use ($page) {
            $this->update($page->cmid, 'name', 'x');
        });

        set_config('allowedcourses', '', 'local_paatshala');
        $this->assert_error('coursenotallowed', function() use ($page) {
            $this->update($page->cmid, 'intro', '<p>x</p>');
        });
        $this->assert_error('coursenotallowed', function() use ($page) {
            get_content::execute($page->cmid);
        });
        set_config('allowedcourses', '*', 'local_paatshala');
        set_config('enabled', 0, 'local_paatshala');
        $this->assert_error('disabled', function() use ($page) {
            $this->update($page->cmid, 'intro', '<p>x</p>');
        });
        set_config('enabled', 1, 'local_paatshala');
        $this->remove_capability('moodle/course:manageactivities');
        $this->assert_error('nopermissions', function() use ($page) {
            $this->update($page->cmid, 'intro', '<p>x</p>');
        });
        $this->assert_error('nopermissions', function() use ($page) {
            get_content::execute($page->cmid);
        });
    }
}

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
 * The text of an activity: description, page content, workshop instructions.
 *
 * @package    local_paatshala
 * @copyright  2026 local_paatshala contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_paatshala\local\kind;

use local_paatshala\gate;
use local_paatshala\journal;
use local_paatshala\local\content;
use local_paatshala\local\modform;

/**
 * One text field of an activity, edited without touching its files.
 *
 * Saved through the settings form path (modform::save()): the field's editor gets the new
 * text and format and keeps the draft area modform::load() prepared from the stored files
 * (file_prepare_draft_area()), as when someone edits only the text on the settings page.
 * file_save_draft_area_files() (lib/filelib.php:1042) then deletes an area file only when
 * it is missing from the draft area or changed, not when the text stops referring to it,
 * and leaves @@PLUGINFILE@@ placeholders as they are (it only rewrites draftfile.php links
 * of its own draft area). So the field's files stay exactly as they were, and an undo that
 * writes the old text back makes every old reference work again. modform::save() refuses
 * any change to a file or another text (filesaltered).
 *
 * State: {field, text (as stored, placeholders kept), format, files: the field's file area
 * as a set of {filepath, filename, contenthash} (content::files_set())}, and for a label its
 * name, which Moodle derives from the text: undo writes the old name back exactly.
 *
 * @package    local_paatshala
 * @copyright  2026 local_paatshala contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class module_content extends base {

    /** @var int[] Formats taken: FORMAT_MOODLE, FORMAT_HTML, FORMAT_PLAIN, FORMAT_MARKDOWN. */
    const FORMATS = [0, 1, 2, 4];

    /** @var string The field this handler edits. */
    protected $field;

    /** @var bool Whether the write may drop references to files of the field (dropreferences). */
    protected $dropreferences = false;

    /** @var string[] References the write in progress drops (set by validate()). */
    protected $dropped = [];

    /**
     * Gate and capability checks.
     *
     * @param int $cmid
     * @param string $field
     */
    public function __construct(int $cmid, string $field) {
        list($this->cm, $this->course, $this->context) = gate::module($cmid);
        // The settings page: course/modedit.php calls can_update_moduleinfo(), which requires
        // moodle/course:manageactivities in the module context (course/modlib.php:470-475).
        require_capability('moodle/course:manageactivities', $this->context);
        $fields = content::field_names($this->cm->modname);
        if (!in_array($field, $fields, true)) {
            throw new \invalid_parameter_exception('A ' . $this->cm->modname . ' has no text field ' . $field .
                '; its fields: ' . ($fields ? implode(', ', $fields) : 'none'));
        }
        $this->field = $field;
    }

    /**
     * Kind name.
     *
     * @return string
     */
    public static function kind(): string {
        return 'module_content';
    }

    /**
     * Handler for a journal target.
     *
     * @param array $target
     * @return base
     */
    public static function from_target(array $target): base {
        return new self((int)$target['cmid'], (string)$target['field']);
    }

    /**
     * Allow (or not) a write that stops referring to files of the field.
     *
     * @param bool $allow
     */
    public function allow_dropping(bool $allow) {
        $this->dropreferences = $allow;
    }

    /**
     * Whether the activity is named after its text (a label).
     *
     * @return bool
     */
    protected function named_by_text(): bool {
        return $this->field === 'intro' && in_array($this->cm->modname, content::NAMED_BY_TEXT, true);
    }

    /**
     * Fields (name only for a label's description).
     *
     * @return string[]
     */
    public function fields(): array {
        return ['field', 'text', 'format', 'files', 'name'];
    }

    /**
     * Fields `expected` may hold.
     *
     * @return string[]
     */
    public function expected_fields(): array {
        return ['text', 'format', 'files', 'name'];
    }

    /**
     * `expected.files` is a list.
     *
     * @return string[]
     */
    public function expected_lists(): array {
        return ['files'];
    }

    /**
     * The journal records the whole state: the file list is what makes undo exact, and a
     * label's name is what it shows on the course page.
     *
     * @param array $values
     * @return string[]
     */
    public function journal_keys(array $values): array {
        return $this->fields();
    }

    /**
     * Current state.
     *
     * @return array
     */
    public function read(): array {
        global $DB;
        $state = content::read_field($this->cm, $this->context, $this->field);
        if ($this->named_by_text()) {
            $state['name'] = (string)$DB->get_field($this->cm->modname, 'name', ['id' => $this->cm->instance], MUST_EXIST);
        }
        return $state;
    }

    /**
     * `expected`: text and name compare byte for byte, format as a number, files as sets.
     *
     * @param array|null $wanted
     * @param array $current
     */
    public function require_expected($wanted, array $current) {
        if ($wanted !== null) {
            $this->require_same($wanted, $current);
        }
    }

    /**
     * Undo needs the entry's after: text, format, the file list (a file removed or replaced
     * by hand since refuses it) and a label's name (renamed by hand since refuses it).
     *
     * @param array $after
     * @param array $before
     * @param array $current
     */
    public function check_undo(array $after, array $before, array $current) {
        $this->require_same($after, $current);
    }

    /**
     * Refuse new references to files that are not there, dropped references unless allowed,
     * draftfile.php links and unknown formats. An undo writes the entry's before back as it
     * was: its references are checked by the file list (check_undo()), not here.
     *
     * @param array $values
     * @param array $current
     */
    public function validate(array $values, array $current) {
        if (array_key_exists('field', $values) && $values['field'] !== $this->field) {
            throw new \invalid_parameter_exception('This change is to ' . $this->field . ', not ' . $values['field']);
        }
        if (!isset($values['text']) || !is_string($values['text'])) {
            throw new \invalid_parameter_exception('text must be a string');
        }
        if (!isset($values['format']) || !is_numeric($values['format'])
                || !in_array((int)$values['format'], self::FORMATS, true)) {
            throw new \invalid_parameter_exception('format must be 0 (moodle), 1 (html), 2 (plain) or 4 (markdown)');
        }
        if (array_key_exists('name', $values) && (!$this->named_by_text() || !is_string($values['name']))) {
            throw new \invalid_parameter_exception('name is written only by undo, for a label');
        }
        if (array_key_exists('files', $values)) {
            // Files are never written: what undo writes back must have the current files.
            $this->require_same(['files' => $values['files']], $current);
        }
        $this->dropped = [];
        if ($this->undoing) {
            return;
        }
        if (stripos($values['text'], 'draftfile.php') !== false) {
            throw new \invalid_parameter_exception('text holds a draftfile.php link; refer to the activity\'s files ' .
                'as @@PLUGINFILE@@/<name>');
        }
        $paths = array_map([content::class, 'path'], $current['files']);
        $old = content::references($current['text']);
        $new = content::references($values['text']);
        $missing = array_values(array_diff($new, $old, $paths));
        if ($missing) {
            throw new \moodle_exception('invalidsettings', 'local_paatshala', '',
                get_string('contentmissingfile', 'local_paatshala', implode(', ', $missing)));
        }
        $dropped = array_values(array_diff(array_intersect($old, $paths), $new));
        if ($dropped && !$this->dropreferences) {
            throw new \moodle_exception('dropsreference', 'local_paatshala', '', implode(', ', $dropped));
        }
        $this->dropped = $dropped;
    }

    /**
     * Save the text and format through the settings form path, keeping the draft area. A
     * label's name follows its text; an undo then writes the entry's name back, as Moodle
     * offers no form field for it.
     *
     * @param array $values
     * @param array $current
     * @return array Warnings.
     */
    public function write(array $values, array $current): array {
        global $DB;
        $name = $DB->get_field($this->cm->modname, 'name', ['id' => $this->cm->instance], MUST_EXIST);

        $spec = content::spec($this->cm->modname, $this->field);
        $text = $values['text'];
        $format = (int)$values['format'];
        $changes = [];
        if ($this->cm->modname === 'page' && $this->field === 'content') {
            // As create_activity: page_update_instance() reads the editor, these only matter without one.
            $changes = ['content' => $text, 'contentformat' => $format];
        }
        modform::save($this->cm, $this->course, $changes, [$spec['editor'] => ['text' => $text, 'format' => $format]]);

        $newname = $DB->get_field($this->cm->modname, 'name', ['id' => $this->cm->instance], MUST_EXIST);
        if (isset($values['name']) && $newname !== $values['name']) {
            // The name Moodle derived from the restored text differs from the one the entry
            // recorded (named by an older Moodle, or by hand): put the recorded one back.
            $DB->set_field($this->cm->modname, 'name', $values['name'], ['id' => $this->cm->instance]);
            rebuild_course_cache((int)$this->course->id, true);
            $newname = $values['name'];
        }

        $warnings = [];
        if ($this->dropped) {
            $warnings[] = ['droppedreference', get_string('warning_droppedreference', 'local_paatshala',
                implode(', ', $this->dropped))];
        }
        if ($newname !== $name) {
            // A label is named after its text (label_update_instance(), mod/label/lib.php:85).
            $warnings[] = ['namechanged', get_string('warning_namechanged', 'local_paatshala',
                (object)['old' => $name, 'new' => $newname])];
        }
        return $warnings;
    }

    /**
     * Refuse (and roll back) unless Moodle stored the text, the format and (undo of a label)
     * the name as given. Files and other texts are checked by modform::save().
     *
     * @param array $values
     * @param array $state
     */
    public function check_written(array $values, array $state) {
        if ($state['text'] !== $values['text'] || $state['format'] !== (int)$values['format']) {
            $a = (object)['askedlength' => strlen($values['text']), 'askedformat' => (int)$values['format'],
                'storedlength' => strlen($state['text']), 'storedformat' => $state['format']];
            throw new \moodle_exception('notsaved', 'local_paatshala', '',
                (object)['field' => $this->field, 'reason' => get_string('notsaved_content', 'local_paatshala', $a)]);
        }
        if (array_key_exists('name', $values) && $state['name'] !== $values['name']) {
            throw new \moodle_exception('notsaved', 'local_paatshala', '', (object)['field' => 'name',
                'reason' => get_string('notsaved_different', 'local_paatshala',
                    (object)['asked' => journal::encode($values['name']), 'stored' => journal::encode($state['name'])])]);
        }
    }

    /**
     * Target.
     *
     * @return array
     */
    public function target(): array {
        return ['cmid' => (int)$this->cm->id, 'modname' => $this->cm->modname, 'name' => $this->cm->name,
            'field' => $this->field];
    }

    /**
     * Refuse with errorcode conflict unless the current state holds the wanted values. The
     * message has the current text's length and SHA-1, not the text.
     *
     * @param array $wanted
     * @param array $current
     * @throws \moodle_exception conflict
     */
    protected function require_same(array $wanted, array $current) {
        foreach ($wanted as $key => $value) {
            switch ($key) {
                case 'text':
                case 'field':
                case 'name':
                    $same = is_string($value) && array_key_exists($key, $current) && $value === $current[$key];
                    break;
                case 'format':
                    $same = is_numeric($value) && (int)$value === $current['format'];
                    break;
                case 'files':
                    $same = content::same_files($value, $current['files']);
                    break;
                default:
                    $same = false;
            }
            if (!$same) {
                $summary = ['field' => $current['field'], 'textlength' => strlen($current['text']),
                    'textsha1' => sha1($current['text']), 'format' => $current['format'],
                    'files' => content::files_set($current['files'])];
                if (array_key_exists('name', $current)) {
                    $summary['name'] = $current['name'];
                }
                $json = journal::encode($summary);
                throw new \moodle_exception('conflict', 'local_paatshala', '', $json, $json);
            }
        }
    }
}

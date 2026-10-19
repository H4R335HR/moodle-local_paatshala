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
 * writes the old text back makes every old reference work again.
 *
 * State: {field, text (as stored, placeholders kept), format, files: the field's file area
 * as [{filepath, filename, contenthash}] sorted by path}. A write is refused (and rolled
 * back) when Moodle stored another text or format (notsaved), changed a file of the
 * activity's text areas (filesaltered: must never happen), or changed another text field
 * of the activity (notsaved).
 *
 * @package    local_paatshala
 * @copyright  2026 local_paatshala contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class module_content extends base {

    /** @var array The description of every activity with FEATURE_MOD_INTRO (course/modlib.php:576-581, 702-705). */
    const INTRO = ['column' => 'intro', 'format' => 'introformat', 'editor' => 'introeditor', 'filearea' => 'intro'];

    /**
     * @var array Further text fields per module type: field => its column, format column, the
     *     settings form's editor (data_preprocessing() of mod_page_mod_form and
     *     mod_workshop_mod_form) and the file area (itemid 0).
     */
    const FIELDS = [
        'page' => [
            'content' => ['column' => 'content', 'format' => 'contentformat', 'editor' => 'page',
                'filearea' => 'content'],
        ],
        'workshop' => [
            'instructauthors' => ['column' => 'instructauthors', 'format' => 'instructauthorsformat',
                'editor' => 'instructauthorseditor', 'filearea' => 'instructauthors'],
            'instructreviewers' => ['column' => 'instructreviewers', 'format' => 'instructreviewersformat',
                'editor' => 'instructreviewerseditor', 'filearea' => 'instructreviewers'],
            'conclusion' => ['column' => 'conclusion', 'format' => 'conclusionformat', 'editor' => 'conclusioneditor',
                'filearea' => 'conclusion'],
        ],
    ];

    /** @var array Other file areas the settings form round-trips, checked as unchanged too. */
    const OTHER_AREAS = ['assign' => ['introattachment']];

    /** @var int[] Formats taken: FORMAT_MOODLE, FORMAT_HTML, FORMAT_PLAIN, FORMAT_MARKDOWN. */
    const FORMATS = [0, 1, 2, 4];

    /** @var string The field this handler edits. */
    protected $field;

    /** @var bool Whether the write may drop references to files of the field (dropreferences). */
    protected $dropreferences = false;

    /** @var string[] References the write in progress drops (set by validate()). */
    protected $dropped = [];

    /** @var array Every text field and file area before the write in progress (set by write()). */
    protected $guard = [];

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
        $fields = self::field_names($this->cm->modname);
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
     * The text fields of a module type.
     *
     * @param string $modname
     * @return string[]
     */
    public static function field_names(string $modname): array {
        $fields = plugin_supports('mod', $modname, FEATURE_MOD_INTRO, true) ? ['intro'] : [];
        return array_merge($fields, array_keys(self::FIELDS[$modname] ?? []));
    }

    /**
     * Column, format column, editor and file area of a field.
     *
     * @param string $modname
     * @param string $field
     * @return array
     */
    public static function spec(string $modname, string $field): array {
        return $field === 'intro' ? self::INTRO : self::FIELDS[$modname][$field];
    }

    /**
     * Files of a text area (itemid 0), directories left out, sorted by path.
     *
     * @param \context $context
     * @param string $modname
     * @param string $filearea
     * @return \stored_file[]
     */
    public static function area_files(\context $context, string $modname, string $filearea): array {
        $files = get_file_storage()->get_area_files($context->id, 'mod_' . $modname, $filearea, 0, 'id', false);
        $files = array_values($files);
        usort($files, function(\stored_file $a, \stored_file $b) {
            return strcmp($a->get_filepath() . $a->get_filename(), $b->get_filepath() . $b->get_filename());
        });
        return $files;
    }

    /**
     * A file as the state holds it.
     *
     * @param \stored_file $file
     * @return array
     */
    public static function file_entry(\stored_file $file): array {
        return ['filepath' => $file->get_filepath(), 'filename' => $file->get_filename(),
            'contenthash' => $file->get_contenthash()];
    }

    /**
     * The path a text uses for a file after @@PLUGINFILE@@/.
     *
     * @param array $entry File entry (file_entry()).
     * @return string
     */
    public static function path(array $entry): string {
        return ltrim($entry['filepath'] . $entry['filename'], '/');
    }

    /**
     * The files a text refers to through @@PLUGINFILE@@/, as paths (URL-decoded), sorted.
     *
     * A reference ends at whitespace, a quote, <, >, ?, #, ) or a backslash (an attribute's
     * end, a query string such as ?time=, a fragment, a Markdown link's end).
     *
     * @param string $text
     * @return string[]
     */
    public static function references(string $text): array {
        preg_match_all('~@@PLUGINFILE@@/([^\s"\'<>?#)\\\\]+)~', $text, $matches);
        $paths = [];
        foreach ($matches[1] as $raw) {
            $path = rawurldecode($raw);
            if (substr($path, -1) !== '/') {
                $paths[$path] = true;
            }
        }
        $paths = array_keys($paths);
        sort($paths, SORT_STRING);
        return $paths;
    }

    /**
     * A field's state.
     *
     * @param \stdClass $cm
     * @param \context $context
     * @param string $field
     * @return array {field, text, format, files}
     */
    public static function read_field(\stdClass $cm, \context $context, string $field): array {
        global $DB;
        $spec = self::spec($cm->modname, $field);
        $record = $DB->get_record($cm->modname, ['id' => $cm->instance], $spec['column'] . ', ' . $spec['format'],
            MUST_EXIST);
        return [
            'field' => $field,
            'text' => (string)$record->{$spec['column']},
            'format' => (int)$record->{$spec['format']},
            'files' => array_map([self::class, 'file_entry'], self::area_files($context, $cm->modname, $spec['filearea'])),
        ];
    }

    /**
     * Fields.
     *
     * @return string[]
     */
    public function fields(): array {
        return ['field', 'text', 'format', 'files'];
    }

    /**
     * Fields `expected` may hold.
     *
     * @return string[]
     */
    public function expected_fields(): array {
        return ['text', 'format', 'files'];
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
     * The journal records the whole state: the file list is what makes undo exact.
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
        return self::read_field($this->cm, $this->context, $this->field);
    }

    /**
     * `expected`: text compares byte for byte, format as a number, files by path and
     * contenthash (entries may carry more keys, e.g. local_paatshala_get_content's).
     *
     * @param array|null $wanted
     * @param array $current
     */
    public function require_expected($wanted, array $current) {
        if ($wanted === null) {
            return;
        }
        if (array_key_exists('files', $wanted)) {
            $wanted['files'] = self::normalise_files($wanted['files']);
        }
        $this->require_same($wanted, $current);
    }

    /**
     * Undo needs the entry's after: text, format and the file list (a file removed or
     * replaced by hand since refuses it).
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
        if (array_key_exists('files', $values)) {
            // Files are never written: what undo writes back must have the current files.
            $this->require_same(['files' => self::normalise_files($values['files'])], $current);
        }
        $this->dropped = [];
        if ($this->undoing) {
            return;
        }
        if (stripos($values['text'], 'draftfile.php') !== false) {
            throw new \invalid_parameter_exception('text holds a draftfile.php link; refer to the activity\'s files ' .
                'as @@PLUGINFILE@@/<name>');
        }
        $paths = array_map([self::class, 'path'], $current['files']);
        $old = self::references($current['text']);
        $new = self::references($values['text']);
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
     * Save the text and format through the settings form path, keeping the draft area.
     *
     * @param array $values
     * @param array $current
     * @return array Warnings.
     */
    public function write(array $values, array $current): array {
        global $DB;
        $this->guard = $this->guard_state();
        $name = $DB->get_field($this->cm->modname, 'name', ['id' => $this->cm->instance], MUST_EXIST);

        $spec = self::spec($this->cm->modname, $this->field);
        $text = $values['text'];
        $format = (int)$values['format'];
        $changes = [];
        if ($this->cm->modname === 'page' && $this->field === 'content') {
            // As create_activity: page_update_instance() reads the editor, these only matter without one.
            $changes = ['content' => $text, 'contentformat' => $format];
        }
        modform::save($this->cm, $this->course, $changes, [$spec['editor'] => ['text' => $text, 'format' => $format]]);

        $warnings = [];
        if ($this->dropped) {
            $warnings[] = ['droppedreference', get_string('warning_droppedreference', 'local_paatshala',
                implode(', ', $this->dropped))];
        }
        $newname = $DB->get_field($this->cm->modname, 'name', ['id' => $this->cm->instance], MUST_EXIST);
        if ($newname !== $name) {
            // A label is named after its text (label_update_instance(), mod/label/lib.php:85).
            $warnings[] = ['namechanged', get_string('warning_namechanged', 'local_paatshala',
                (object)['old' => $name, 'new' => $newname])];
        }
        return $warnings;
    }

    /**
     * Refuse (and roll back) unless Moodle stored the text and format as given and left every
     * file and every other text field of the activity as it was.
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
        $now = $this->guard_state();
        $altered = [];
        foreach ($this->guard['areas'] as $area => $files) {
            if ($now['areas'][$area] !== $files) {
                $altered[] = $area;
            }
        }
        if ($altered) {
            throw new \moodle_exception('filesaltered', 'local_paatshala', '', implode(', ', $altered));
        }
        foreach ($this->guard['texts'] as $field => $text) {
            if ($field !== $this->field && $now['texts'][$field] !== $text) {
                throw new \moodle_exception('notsaved', 'local_paatshala', '', (object)['field' => $this->field,
                    'reason' => get_string('notsaved_otherfield', 'local_paatshala', $field)]);
            }
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
     * Every text field (text and format) and every text file area of the activity.
     *
     * @return array {texts: field => [text, format], areas: filearea => file entries}
     */
    protected function guard_state(): array {
        $state = ['texts' => [], 'areas' => []];
        foreach (self::field_names($this->cm->modname) as $field) {
            $read = self::read_field($this->cm, $this->context, $field);
            $state['texts'][$field] = [$read['text'], $read['format']];
            $state['areas'][self::spec($this->cm->modname, $field)['filearea']] = $read['files'];
        }
        foreach (self::OTHER_AREAS[$this->cm->modname] ?? [] as $area) {
            $state['areas'][$area] = array_map([self::class, 'file_entry'],
                self::area_files($this->context, $this->cm->modname, $area));
        }
        return $state;
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
                    $same = is_string($value) && $value === $current[$key];
                    break;
                case 'format':
                    $same = is_numeric($value) && (int)$value === $current['format'];
                    break;
                case 'files':
                    $same = $value === $current['files'];
                    break;
                default:
                    $same = false;
            }
            if (!$same) {
                $json = journal::encode(['field' => $current['field'], 'textlength' => strlen($current['text']),
                    'textsha1' => sha1($current['text']), 'format' => $current['format'], 'files' => $current['files']]);
                throw new \moodle_exception('conflict', 'local_paatshala', '', $json, $json);
            }
        }
    }

    /**
     * File entries as the state holds them, sorted by path.
     *
     * @param mixed $files
     * @return array
     */
    protected static function normalise_files($files): array {
        if (!is_array($files)) {
            throw new \invalid_parameter_exception('files must be a list of {filepath, filename, contenthash}');
        }
        $entries = [];
        foreach ($files as $file) {
            if (!is_array($file) || !isset($file['filepath'], $file['filename'], $file['contenthash'])
                    || !is_string($file['filepath']) || !is_string($file['filename']) || !is_string($file['contenthash'])) {
                throw new \invalid_parameter_exception('files must be a list of {filepath, filename, contenthash}');
            }
            $entries[] = ['filepath' => $file['filepath'], 'filename' => $file['filename'],
                'contenthash' => $file['contenthash']];
        }
        usort($entries, function(array $a, array $b) {
            return strcmp($a['filepath'] . $a['filename'], $b['filepath'] . $b['filename']);
        });
        return $entries;
    }
}

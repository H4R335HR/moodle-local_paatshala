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
 * Load-merge-save of activity settings.
 *
 * @package    local_paatshala
 * @copyright  2026 local_paatshala contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_paatshala\local;

defined('MOODLE_INTERNAL') || die();

global $CFG;
// What course/modedit.php loads (lines 27-32): get_moduleinfo_data() and update_moduleinfo() use
// grade_item (course/modlib.php:602, 742) without loading gradelib.php themselves, so a page or a
// label (whose lib.php does not load it either) failed with "Class 'grade_item' not found" over REST.
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->libdir . '/filelib.php');
require_once($CFG->libdir . '/gradelib.php');
require_once($CFG->libdir . '/completionlib.php');
require_once($CFG->libdir . '/plagiarismlib.php');
require_once($CFG->dirroot . '/course/modlib.php');

/**
 * Load-merge-save of activity settings through the module's own settings form.
 *
 * update_moduleinfo() replaces every setting, so the data passed to it must be the full
 * data the settings form (course/modedit.php) would submit. This rebuilds it:
 *
 * 1. get_moduleinfo_data(), as modedit.php does: course module fields, the module row,
 *    the intro editor with its files in a draft area (file_prepare_draft_area), advanced
 *    grading methods, outcomes, grade to pass and grade category.
 * 2. The module form (mod_X_mod_form) is built and set_data() is called, which runs the
 *    form's data_preprocessing() (for assign: intro attachments draft area and submission
 *    and feedback plugin preprocessing), then definition_after_data(), which Moodle runs
 *    before every display and submission (workshop sets its grade categories there).
 * 3. The form's element values are exported, as a browser would submit them unchanged.
 *    This brings in everything that only exists as element defaults, e.g. assign
 *    submission and feedback plugin settings (assign_plugin_config), plagiarism and
 *    other plugins' course module elements.
 * 4. The preprocessed data from 1-2 is laid over the export, because it holds the exact
 *    stored values where elements are lossy (date selectors drop the seconds).
 * 5. The change is applied, and unchecked plain checkboxes are removed (a browser does
 *    not post them; see drop_unchecked()).
 * 6. The form's element rules and validation() are run (moodleform_mod and the module's
 *    own); any error refuses the change. Then what moodleform_mod::get_data() does after
 *    validation (unformat_float() of gradepass, data_postprocessing()).
 * 7. update_moduleinfo() saves it, as modedit.php does.
 *
 * Left out on purpose, so update_moduleinfo() and its callbacks leave them untouched
 * (each is only acted on when present):
 * - tags: core_tag_tag::set_item_tags() only runs when 'tags' is set;
 * - competencies and the competency rule: tool_lp_coursemodule_edit_post_actions()
 *   returns early when both are missing;
 * - completionunlocked: completion settings other than the expected date are only
 *   written when it is set;
 * - grade to pass and grade category (gradepass, gradecat and their per-item variants):
 *   edit_module_post_actions() only sets them when present. Present, it also copies the
 *   grade to pass onto outcome grade items (a 3.8 quirk the settings page shares);
 * - outcome_N and advancedgradingmethod_*: only add outcomes or switch grading methods
 *   when present.
 *
 * @package    local_paatshala
 * @copyright  2026 local_paatshala contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class modform {

    /** @var string[] Keys removed from the data before saving (see class comment). */
    const UNTOUCHED = ['tags', 'competencies', 'competency_rule', 'completionunlocked'];

    /** @var string Keys matching this are removed too, unless the change sets them (see class comment). */
    const UNTOUCHED_PATTERN = '/(^|_)gradepass$|(^|_)gradecat$|^outcome_\d+$|^advancedgradingmethod_/';

    /**
     * @var string[] Areas whose item ids Moodle renews on every save, leaving the old files in place:
     *     quiz overall feedback (quiz_after_add_or_update() reinserts the rows and saves each draft
     *     area at the new id). Files at new item ids are not a change there.
     */
    const RENUMBERED = ['mod_quiz/feedback'];

    /** @var string[] Form bookkeeping keys that are not settings. */
    const NOT_SETTINGS = ['sesskey', 'submitbutton', 'submitbutton2', 'cancel', 'buttonar', 'add', 'update',
        'return', 'sr', 'grade_rescalegrades'];

    /**
     * Build the data the module's settings form would submit if nothing were changed.
     *
     * The caller must have validated the module context (external_api::validate_context),
     * which sets $COURSE as require_login() does on modedit.php.
     *
     * @param \stdClass $cm Course module record.
     * @param \stdClass $course
     * @return array [\stdClass $data, \moodleform_mod $mform]
     */
    public static function load(\stdClass $cm, \stdClass $course): array {
        global $CFG;

        // Checks moodle/course:manageactivities in the module context (course/modlib.php).
        list($cm, $context, $module, $data, $cw) = get_moduleinfo_data($cm, $course);

        $formfile = $CFG->dirroot . '/mod/' . $module->name . '/mod_form.php';
        if (!file_exists($formfile)) {
            throw new \moodle_exception('noformdesc');
        }
        require_once($formfile);
        $formclass = 'mod_' . $module->name . '_mod_form';
        // As modedit.php: the update flag drives the form's definition_after_data().
        $data->update = $cm->id;
        $mform = new $formclass($data, $cw->section, $cm, $course);
        $mform->set_data($data);
        // Moodle runs this before every display and submission; some forms set element
        // values here (workshop: grade categories and grades to pass).
        $mform->definition_after_data();

        return [(object)self::form_values($mform), $mform];
    }

    /**
     * Create an activity the way course/modedit.php's add path does.
     *
     * prepare_new_moduleinfo_data() (which runs can_add_moduleinfo(): moodle/course:manageactivities
     * and mod/X:addinstance in the course), the module's settings form with its defaults
     * (admin defaults, submission plugin defaults), the given values, the form's
     * validation(), then add_moduleinfo().
     *
     * @param \stdClass $course
     * @param string $modname
     * @param int $sectionnum Must exist (can_add_moduleinfo() would create it).
     * @param array $changes Setting name => value, in the form's submitted shape.
     * @return \stdClass The data add_moduleinfo() returns (coursemodule is the new cmid).
     */
    public static function add(\stdClass $course, string $modname, int $sectionnum, array $changes): \stdClass {
        global $CFG;
        list($module, $context, $cw, $cm, $data) = prepare_new_moduleinfo_data($course, $modname, $sectionnum);
        $data->add = $modname;
        $data->return = 0;
        $data->sr = null;

        $formfile = $CFG->dirroot . '/mod/' . $module->name . '/mod_form.php';
        if (!file_exists($formfile)) {
            throw new \moodle_exception('noformdesc');
        }
        require_once($formfile);
        $formclass = 'mod_' . $module->name . '_mod_form';
        $mform = new $formclass($data, $cw->section, null, $course);
        $mform->set_data($data);
        $mform->definition_after_data();

        $data = (object)self::form_values($mform);
        // The form's hidden id fields are PARAM_INT: a new activity posts '' and get_data() cleans it to 0.
        foreach (['id', 'instance', 'coursemodule'] as $key) {
            $data->$key = 0;
        }
        foreach ($changes as $key => $value) {
            $data->$key = $value;
        }
        self::drop_unchecked($mform, $data);
        self::validate($mform, $data);
        self::postprocess($mform, $data);
        return add_moduleinfo($data, $course, null);
    }

    /**
     * Change some settings of an activity, keeping all others.
     *
     * @param \stdClass $cm Course module record.
     * @param \stdClass $course
     * @param array $changes Setting name => new value, in the form's submitted shape.
     * @param array $editors Editor element name => ['text' => ..., 'format' => ...]: the editor's
     *     text and format are replaced and its draft area (itemid, prepared from the stored
     *     files by load()) is kept, as when someone edits only the text on the settings page.
     * @throws \moodle_exception invalidsettings when the form's validation refuses it; filesaltered
     *     (the whole save rolled back) when the save changed a file of the activity, or a text
     *     it was not asked to change (see guard()).
     */
    public static function save(\stdClass $cm, \stdClass $course, array $changes, array $editors = []) {
        global $DB;
        $transaction = $DB->start_delegated_transaction();
        try {
            list($data, $mform) = self::load($cm, $course);
            $guard = self::guard($cm, $mform, $data);
            foreach ($changes as $key => $value) {
                $data->$key = $value;
            }
            foreach ($editors as $key => $editor) {
                $loaded = $data->$key ?? null;
                if (!is_array($loaded) || empty($loaded['itemid'])) {
                    throw new \coding_exception('The settings form has no editor ' . $key . ' with a draft area');
                }
                $data->$key = ['text' => $editor['text'], 'format' => $editor['format'], 'itemid' => $loaded['itemid']];
            }
            self::drop_unchecked($mform, $data);
            // The form validates the submitted values first, then moodleform_mod::get_data()
            // post-processes them.
            self::validate($mform, $data);
            self::postprocess($mform, $data);

            $cm = get_coursemodule_from_id('', $cm->id, 0, false, MUST_EXIST);
            update_moduleinfo($cm, $data, $course, null);
            self::require_guard($cm, $guard, array_merge(array_keys($changes), array_keys($editors)));
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            if (!$transaction->is_disposed()) {
                $transaction->rollback($e);
            }
            throw $e;
        }
    }

    /**
     * What a save must leave as it was, taken after load(): the files of every file area the
     * settings form prepared a draft area from, and of the activity's text and attachment areas
     * (content::known_areas(), so an empty one counts too), and the text and format behind
     * every editor of the form.
     *
     * file_save_draft_area_files() deletes an area file whose draft copy it skips: a file above
     * the editor's maxbytes uploaded by a user without moodle/course:ignorefilesizelimits
     * (page content: the site limit), or a file in a subfolder of an area saved without
     * subdirs (assign intro attachments). The settings page does the same, silently.
     *
     * @param \stdClass $cm
     * @param \moodleform_mod $mform Form after load().
     * @param \stdClass $data Data from load().
     * @return array {files: component/filearea => file list, texts: column => [text, format],
     *     editors: editor element => column}
     */
    protected static function guard(\stdClass $cm, \moodleform_mod $mform, \stdClass $data): array {
        global $DB, $USER;
        $context = \context_module::instance($cm->id);
        $areas = array_fill_keys(content::known_areas($cm->modname), true);
        $columns = $DB->get_columns($cm->modname);
        $editors = [];
        $usercontext = \context_user::instance($USER->id);
        $fs = get_file_storage();
        foreach (self::elements($mform) as $element) {
            $name = $element->getName();
            $type = $element->getType();
            $value = self::value((array)$data, (string)$name);
            $itemid = 0;
            if ($type === 'editor') {
                $itemid = is_array($value) ? (int)($value['itemid'] ?? 0) : 0;
                $column = strpos((string)$name, '[') === false ? self::editor_column($cm->modname, (string)$name) : null;
                if ($column !== null && isset($columns[$column])) {
                    $editors[$name] = $column;
                    // The area usually has the column's name (page_after_submit): guard it even when the
                    // draft got no copies, e.g. every file in a subfolder of an area prepared without them.
                    $areas['mod_' . $cm->modname . '/' . $column] = true;
                }
            } else if ($type === 'filemanager') {
                $itemid = is_numeric($value) ? (int)$value : 0;
                if (strpos((string)$name, '[') === false) {
                    // Likewise for a file manager named after its area (lesson's mediafile).
                    $areas['mod_' . $cm->modname . '/' . $name] = true;
                }
            }
            if ($itemid <= 0) {
                continue;
            }
            // Each draft copy records its original (file_prepare_draft_area(), lib/filelib.php:426-437).
            foreach ($fs->get_area_files($usercontext->id, 'user', 'draft', $itemid, 'id', false) as $draft) {
                $source = @unserialize($draft->get_source());
                if (is_object($source) && isset($source->original)) {
                    $original = \file_storage::unpack_reference($source->original);
                    if ((int)$original['contextid'] === (int)$context->id) {
                        $areas[$original['component'] . '/' . $original['filearea']] = true;
                    }
                }
            }
        }
        ksort($areas);
        $files = [];
        foreach (array_keys($areas) as $area) {
            $files[$area] = self::area_list($context, $area);
        }
        return ['files' => $files, 'texts' => self::texts($cm, $editors, $columns), 'editors' => $editors];
    }

    /**
     * Refuse (errorcode filesaltered) a save that changed a guarded file, or a text whose
     * editor or column was not among the changes. The caller's transaction rolls it back.
     *
     * @param \stdClass $cm
     * @param array $guard From guard().
     * @param string[] $changed Form keys the save was asked to change.
     * @throws \moodle_exception filesaltered
     */
    protected static function require_guard(\stdClass $cm, array $guard, array $changed) {
        global $DB;
        $context = \context_module::instance($cm->id);
        $problems = [];
        foreach ($guard['files'] as $area => $before) {
            $after = self::area_list($context, $area);
            $olditemids = [];
            foreach ($before as $key => $hash) {
                $olditemids[explode('|', $key, 2)[0]] = true;
                if (!array_key_exists($key, $after)) {
                    $problems[] = self::file_label($area, $key) . ' removed';
                } else if ($after[$key] !== $hash) {
                    $problems[] = self::file_label($area, $key) . ' replaced';
                }
            }
            foreach (array_diff_key($after, $before) as $key => $hash) {
                $itemid = explode('|', $key, 2)[0];
                if (!in_array($area, self::RENUMBERED, true) || isset($olditemids[$itemid])) {
                    $problems[] = self::file_label($area, $key) . ' added';
                }
            }
        }
        $now = self::texts($cm, $guard['editors'], $DB->get_columns($cm->modname));
        foreach ($guard['texts'] as $column => $before) {
            $editor = array_search($column, $guard['editors'], true);
            if (in_array($editor, $changed, true) || in_array($column, $changed, true)) {
                continue;
            }
            if ($now[$column] !== $before) {
                $problems[] = $cm->modname . '.' . $column . ' text changed';
            }
        }
        if ($problems) {
            $list = implode('; ', $problems);
            throw new \moodle_exception('filesaltered', 'local_paatshala', '', $list, $list);
        }
    }

    /**
     * The module table column an editor of the settings form saves into: introeditor -> intro,
     * a page's page -> content, and <column>editor or <column>_editor (workshop instructions,
     * feedback's page_after_submit_editor).
     *
     * @param string $modname
     * @param string $editor
     * @return string|null
     */
    protected static function editor_column(string $modname, string $editor) {
        if ($editor === 'introeditor') {
            return 'intro';
        }
        if ($modname === 'page' && $editor === 'page') {
            return 'content';
        }
        return preg_match('/^(.+?)_?editor$/', $editor, $matches) ? $matches[1] : null;
    }

    /**
     * The text and format behind each guarded editor.
     *
     * @param \stdClass $cm
     * @param array $editors Editor => column.
     * @param array $columns The module table's columns.
     * @return array Column => [text, format or null].
     */
    protected static function texts(\stdClass $cm, array $editors, array $columns): array {
        global $DB;
        if (!$editors) {
            return [];
        }
        $fields = [];
        foreach ($editors as $column) {
            $fields[$column] = $column;
            if (isset($columns[$column . 'format'])) {
                $fields[$column . 'format'] = $column . 'format';
            }
        }
        $record = $DB->get_record($cm->modname, ['id' => $cm->instance], implode(', ', $fields), MUST_EXIST);
        $texts = [];
        foreach ($editors as $column) {
            $format = $column . 'format';
            $texts[$column] = [(string)$record->$column, isset($record->$format) ? (int)$record->$format : null];
        }
        return $texts;
    }

    /**
     * The files of a module area, every itemid, directories left out.
     *
     * @param \context $context
     * @param string $area component/filearea
     * @return array "itemid|filepath|filename" => contenthash, sorted.
     */
    protected static function area_list(\context $context, string $area): array {
        list($component, $filearea) = explode('/', $area, 2);
        $list = [];
        foreach (get_file_storage()->get_area_files($context->id, $component, $filearea, false, 'id', false) as $file) {
            $list[$file->get_itemid() . '|' . $file->get_filepath() . '|' . $file->get_filename()] = $file->get_contenthash();
        }
        ksort($list, SORT_STRING);
        return $list;
    }

    /**
     * A guarded file as the filesaltered message names it: component/filearea/path, with the
     * item id when it is not 0.
     *
     * @param string $area
     * @param string $key From area_list().
     * @return string
     */
    protected static function file_label(string $area, string $key): string {
        list($itemid, $filepath, $filename) = explode('|', $key, 3);
        return $area . ($itemid === '0' ? '' : ' item ' . $itemid . ' ') . $filepath . $filename;
    }

    /**
     * The value of an element in the data, following the brackets of names such as
     * feedbacktext[0] (quiz overall feedback).
     *
     * @param array $data
     * @param string $name
     * @return mixed Null when absent.
     */
    protected static function value(array $data, string $name) {
        if (array_key_exists($name, $data)) {
            // The preprocessed defaults use the element name as the key (mod/quiz/mod_form.php:462).
            return $data[$name];
        }
        $value = $data;
        foreach (preg_split('/[\[\]]+/', $name, -1, PREG_SPLIT_NO_EMPTY) as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return null;
            }
            $value = $value[$part];
        }
        return $value;
    }

    /**
     * Every element of the form, groups opened.
     *
     * @param \moodleform_mod $mform
     * @return \HTML_QuickForm_element[]
     */
    protected static function elements(\moodleform_mod $mform): array {
        $result = [];
        $elements = self::quickform($mform)->_elements;
        while ($elements) {
            $element = array_shift($elements);
            if ($element->getType() === 'group') {
                $elements = array_merge($elements, $element->getElements());
                continue;
            }
            $result[] = $element;
        }
        return $result;
    }

    /**
     * What moodleform_mod::get_data() does after validation: unformat_float() on the grade
     * to pass, then the form's data_postprocessing().
     *
     * @param \moodleform_mod $mform
     * @param \stdClass $data
     */
    protected static function postprocess(\moodleform_mod $mform, \stdClass $data) {
        if (isset($data->gradepass)) {
            $data->gradepass = unformat_float($data->gradepass);
        }
        // Forms put display defaults into their own completion rule fields and clear them
        // again in data_postprocessing() only with completionunlocked set (forum:
        // mod/forum/mod_form.php:406-430 and 505-520), which a browser posts as 1 whenever
        // nobody has completed the activity (course/moodleform_mod.php:339). It is set for
        // this step only: update_moduleinfo() would rewrite the course module's completion
        // settings and reset every user's completion state (course/modlib.php:529, 640).
        $data->completionunlocked = 1;
        $mform->data_postprocessing($data);
        unset($data->completionunlocked);
    }

    /**
     * Run the settings form's validation() on the data; refuse on any error.
     *
     * @param \moodleform_mod $mform
     * @param \stdClass $data
     * @throws \moodle_exception invalidsettings
     */
    public static function validate(\moodleform_mod $mform, \stdClass $data) {
        $errors = self::rule_errors(self::quickform($mform), (array)$data) + $mform->validation((array)$data, []);
        if ($errors) {
            $messages = [];
            foreach ($errors as $field => $message) {
                $messages[] = $field . ': ' . $message;
            }
            throw new \moodle_exception('invalidsettings', 'local_paatshala', '', implode('; ', $messages));
        }
    }

    /**
     * What the settings form would submit unchanged, with the stored values laid over it
     * and the untouched keys removed (steps 3 and 4 of the class comment).
     *
     * @param \moodleform_mod $mform Form after set_data().
     * @return array
     */
    protected static function form_values(\moodleform_mod $mform): array {
        $quickform = self::quickform($mform);
        $values = $quickform->exportValues();
        foreach (array_keys($values) as $key) {
            if (in_array($key, self::NOT_SETTINGS, true) || strpos($key, '_qf__') === 0
                    || strpos($key, 'mform_isexpanded_') === 0) {
                unset($values[$key]);
            }
        }
        // A browser posts empty text-like fields as ''; the export leaves them out.
        foreach ($quickform->_elements as $element) {
            $name = $element->getName();
            if ($name !== null && $name !== '' && !array_key_exists($name, $values) && strpos($name, '[') === false
                    && in_array($element->getType(), ['text', 'textarea', 'hidden', 'url'], true)) {
                $values[$name] = '';
            }
        }
        // A browser posts an editor's format from its format selector; an editor with no value
        // (e.g. a quiz's empty overall feedback) exports none.
        foreach ($quickform->_elements as $element) {
            if ($element->getType() !== 'editor') {
                continue;
            }
            $path = preg_split('/[\[\]]+/', $element->getName(), -1, PREG_SPLIT_NO_EMPTY);
            $value = &$values;
            foreach ($path as $part) {
                if (!is_array($value) || !array_key_exists($part, $value)) {
                    unset($value);
                    continue 2;
                }
                $value = &$value[$part];
            }
            if (is_array($value) && (!isset($value['format']) || $value['format'] === '')) {
                $value['format'] = (int)editors_get_preferred_format();
            }
            unset($value);
        }
        // The preprocessed data (set_data() stores it as the defaults) wins over the export.
        foreach ($quickform->_defaultValues as $key => $value) {
            $values[$key] = $value;
        }
        // The availability element submits '' for "no restrictions", the table holds null.
        if (array_key_exists('availabilityconditionsjson', $values) && $values['availabilityconditionsjson'] === null) {
            $values['availabilityconditionsjson'] = '';
        }
        foreach (array_keys($values) as $key) {
            if (in_array($key, self::UNTOUCHED, true) || preg_match(self::UNTOUCHED_PATTERN, $key)) {
                unset($values[$key]);
            }
        }
        return $values;
    }

    /**
     * Remove unchecked plain checkboxes, as a browser does not post them.
     *
     * The stored data can hold 0 for them (quiz data_preprocessing() sets review options to
     * 0), and some code tests isset() (quiz_review_option_form_to_db() in 3.8.2+ 20200508),
     * so a 0 left in would switch the option on. Advanced checkboxes post 0 and stay.
     *
     * @param \moodleform_mod $mform
     * @param \stdClass $data
     */
    protected static function drop_unchecked(\moodleform_mod $mform, \stdClass $data) {
        $elements = self::quickform($mform)->_elements;
        while ($elements) {
            $element = array_shift($elements);
            if ($element->getType() === 'group') {
                $elements = array_merge($elements, $element->getElements());
                continue;
            }
            if ($element->getType() !== 'checkbox') {
                continue;
            }
            $name = $element->getName();
            if (property_exists($data, $name) && empty($data->$name)) {
                unset($data->$name);
            }
        }
    }

    /**
     * The form's element rules (addRule(): required, maxlength, numeric...), checked as
     * HTML_QuickForm::validate() does on submission.
     *
     * Rules on sub-elements of groups (names with "[") and rules depending on other
     * elements are not checked.
     *
     * @param \MoodleQuickForm $quickform
     * @param array $data
     * @return array Element name => message.
     */
    protected static function rule_errors(\MoodleQuickForm $quickform, array $data): array {
        global $CFG;
        require_once($CFG->libdir . '/pear/HTML/QuickForm/RuleRegistry.php');
        $registry = \HTML_QuickForm_RuleRegistry::singleton();
        $errors = [];
        foreach ($quickform->_rules as $target => $rules) {
            if (strpos($target, '[') !== false) {
                continue;
            }
            $value = $data[$target] ?? null;
            foreach ($rules as $rule) {
                if (isset($errors[$target]) || isset($rule['dependent'])) {
                    continue 2;
                }
                if (!$quickform->isElementRequired($target) && ($value === null || $value === '' || $value === [])) {
                    continue 2;
                }
                $result = $registry->validate($rule['type'], $value, $rule['format'], is_array($value));
                if (!$result || (!empty($rule['howmany']) && $rule['howmany'] > (int)$result)) {
                    $errors[$target] = $rule['message'] === '' ? get_string('required') : $rule['message'];
                }
            }
        }
        return $errors;
    }

    /**
     * The QuickForm inside a moodleform (moodleform::$_form is protected).
     *
     * @param \moodleform $mform
     * @return \MoodleQuickForm
     */
    protected static function quickform(\moodleform $mform): \MoodleQuickForm {
        $property = new \ReflectionProperty('moodleform', '_form');
        $property->setAccessible(true);
        return $property->getValue($mform);
    }
}

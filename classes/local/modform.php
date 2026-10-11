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
 *    and feedback plugin preprocessing).
 * 3. The form's element values are exported, as a browser would submit them unchanged.
 *    This brings in everything that only exists as element defaults, e.g. assign
 *    submission and feedback plugin settings (assign_plugin_config), plagiarism and
 *    other plugins' course module elements.
 * 4. The preprocessed data from 1-2 is laid over the export, because it holds the exact
 *    stored values where elements are lossy (date selectors drop the seconds).
 * 5. The change is applied, then what moodleform_mod::get_data() does after export
 *    (unformat_float() of gradepass, data_postprocessing()).
 * 6. The form's validation() is run (moodleform_mod and the module's own rules); any
 *    error refuses the change.
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
        $mform = new $formclass($data, $cw->section, $cm, $course);
        $mform->set_data($data);

        $quickform = self::quickform($mform);
        $values = $quickform->exportValues();
        foreach (array_keys($values) as $key) {
            if (in_array($key, self::NOT_SETTINGS, true) || strpos($key, '_qf__') === 0
                    || strpos($key, 'mform_isexpanded_') === 0) {
                unset($values[$key]);
            }
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
        return [(object)$values, $mform];
    }

    /**
     * Change some settings of an activity, keeping all others.
     *
     * @param \stdClass $cm Course module record.
     * @param \stdClass $course
     * @param array $changes Setting name => new value, in the form's submitted shape.
     * @throws \moodle_exception invalidsettings when the form's validation refuses it.
     */
    public static function save(\stdClass $cm, \stdClass $course, array $changes) {
        list($data, $mform) = self::load($cm, $course);
        foreach ($changes as $key => $value) {
            $data->$key = $value;
        }
        // What moodleform_mod::get_data() does after exporting the submitted values.
        if (isset($data->gradepass)) {
            $data->gradepass = unformat_float($data->gradepass);
        }
        $mform->data_postprocessing($data);

        self::validate($mform, $data);

        $cm = get_coursemodule_from_id('', $cm->id, 0, false, MUST_EXIST);
        update_moduleinfo($cm, $data, $course, null);
    }

    /**
     * Run the settings form's validation() on the data; refuse on any error.
     *
     * @param \moodleform_mod $mform
     * @param \stdClass $data
     * @throws \moodle_exception invalidsettings
     */
    public static function validate(\moodleform_mod $mform, \stdClass $data) {
        $errors = $mform->validation((array)$data, []);
        if ($errors) {
            $messages = [];
            foreach ($errors as $field => $message) {
                $messages[] = $field . ': ' . $message;
            }
            throw new \moodle_exception('invalidsettings', 'local_paatshala', '', implode('; ', $messages));
        }
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

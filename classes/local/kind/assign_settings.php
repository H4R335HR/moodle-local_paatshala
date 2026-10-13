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
 * Assignment settings.
 *
 * @package    local_paatshala
 * @copyright  2026 local_paatshala contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_paatshala\local\kind;

use local_paatshala\local\state;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/assign/locallib.php');

/**
 * Assignment settings from a whitelist, through the settings form path.
 *
 * Field names are the settings form's. Submission and feedback plugin settings are read
 * from the plugins' stored config (assign_plugin_config).
 *
 * Moodle saves a plugin's settings only while the plugin is on
 * (assign::update_plugin_instance()), and online text stores the word limit and its switch
 * together (assign_submission_onlinetext::save_settings()). A setting that would not be
 * saved is refused with the reason, and the write rolled back (check_written()).
 *
 * @package    local_paatshala
 * @copyright  2026 local_paatshala contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class assign_settings extends module_settings {

    /** @var array Fields on the assign row: name => type (int or text). */
    const ROW = [
        'name' => 'text',
        'requiresubmissionstatement' => 'int',
        'attemptreopenmethod' => 'text',
        'maxattempts' => 'int',
    ];

    /** @var array Plugin fields: form name => [subtype, plugin, config name or 'enabled', type]. */
    const PLUGIN = [
        'assignsubmission_onlinetext_enabled' => ['assignsubmission', 'onlinetext', 'enabled', 'int'],
        'assignsubmission_onlinetext_wordlimit' => ['assignsubmission', 'onlinetext', 'wordlimit', 'int'],
        'assignsubmission_onlinetext_wordlimit_enabled' => ['assignsubmission', 'onlinetext', 'wordlimitenabled', 'int'],
        'assignsubmission_file_enabled' => ['assignsubmission', 'file', 'enabled', 'int'],
        'assignsubmission_file_maxfiles' => ['assignsubmission', 'file', 'maxfilesubmissions', 'int'],
        'assignsubmission_file_maxsizebytes' => ['assignsubmission', 'file', 'maxsubmissionsizebytes', 'int'],
        'assignsubmission_file_filetypes' => ['assignsubmission', 'file', 'filetypeslist', 'text'],
        'assignfeedback_comments_enabled' => ['assignfeedback', 'comments', 'enabled', 'int'],
        'assignfeedback_comments_commentinline' => ['assignfeedback', 'comments', 'commentinline', 'int'],
        'assignfeedback_file_enabled' => ['assignfeedback', 'file', 'enabled', 'int'],
        'assignfeedback_offline_enabled' => ['assignfeedback', 'offline', 'enabled', 'int'],
        'assignfeedback_editpdf_enabled' => ['assignfeedback', 'editpdf', 'enabled', 'int'],
    ];

    /** @var array Fields refused on purpose: field => reason string identifier. */
    const EXCLUDED = [
        'intro' => 'excluded_description',
        'introeditor' => 'excluded_description',
        'introattachments' => 'excluded_description',
        'grade' => 'excluded_grade',
        'teamsubmission' => 'excluded_team',
        'requireallteammemberssubmit' => 'excluded_team',
        'teamsubmissiongroupingid' => 'excluded_team',
        'preventsubmissionnotingroup' => 'excluded_team',
        'markingworkflow' => 'excluded_workflow',
        'markingallocation' => 'excluded_workflow',
        'allowsubmissionsfromdate' => 'excluded_dates',
        'duedate' => 'excluded_dates',
        'cutoffdate' => 'excluded_dates',
        'gradingduedate' => 'excluded_dates',
    ];

    /** @var string[] Online text word limit fields: save_settings() stores both from either. */
    const WORDLIMIT = ['assignsubmission_onlinetext_wordlimit', 'assignsubmission_onlinetext_wordlimit_enabled'];

    /**
     * Module type.
     *
     * @return string
     */
    protected static function modname(): string {
        return 'assign';
    }

    /**
     * Kind name.
     *
     * @return string
     */
    public static function kind(): string {
        return 'assign_settings';
    }

    /**
     * Fields: the whitelist.
     *
     * @return string[]
     */
    public function fields(): array {
        return array_merge(array_keys(self::ROW), array_keys(self::PLUGIN));
    }

    /**
     * The assign object (fresh, so plugin config is re-read).
     *
     * @return \assign
     */
    protected function assign(): \assign {
        return new \assign($this->context, $this->cm, $this->course);
    }

    /**
     * A submission or feedback plugin, if installed.
     *
     * @param \assign $assign
     * @param string $subtype
     * @param string $type
     * @return \assign_plugin|null
     */
    protected static function plugin(\assign $assign, string $subtype, string $type) {
        $plugin = $subtype === 'assignsubmission' ? $assign->get_submission_plugin_by_type($type)
            : $assign->get_feedback_plugin_by_type($type);
        return $plugin ?: null;
    }

    /**
     * Current values of every whitelisted field.
     *
     * @return array
     */
    public function read(): array {
        global $DB;
        $row = $DB->get_record('assign', ['id' => $this->cm->instance], implode(',', array_keys(self::ROW)), MUST_EXIST);
        $state = [];
        foreach (self::ROW as $field => $type) {
            $state[$field] = $type === 'int' ? (int)$row->$field : (string)$row->$field;
        }
        $assign = $this->assign();
        foreach (self::PLUGIN as $field => list($subtype, $type, $config, $valuetype)) {
            $plugin = self::plugin($assign, $subtype, $type);
            if (!$plugin) {
                $state[$field] = null;
                continue;
            }
            $value = $config === 'enabled' ? (int)$plugin->is_enabled() : $plugin->get_config($config);
            if ($value === false || $value === null) {
                $value = $valuetype === 'int' ? 0 : '';
            }
            $state[$field] = $valuetype === 'int' ? (int)$value : (string)$value;
        }
        return $state;
    }

    /**
     * Refuse excluded fields with their reason, missing plugins and wrong types; the rest
     * is the settings form's validation (modform::save()).
     *
     * @param array $values
     * @param array $current
     */
    public function validate(array $values, array $current) {
        foreach ($values as $field => $value) {
            if (array_key_exists($field, self::PLUGIN) && $current[$field] === null) {
                throw new \invalid_parameter_exception($field . ': that plugin is not installed');
            }
            $type = self::ROW[$field] ?? self::PLUGIN[$field][3];
            if ($type === 'int' && !is_numeric($value)) {
                throw new \invalid_parameter_exception($field . ' must be a number');
            }
            if ($type === 'text' && !is_string($value)) {
                throw new \invalid_parameter_exception($field . ' must be a string');
            }
        }
    }

    /**
     * Refuse fields outside the whitelist, naming the reason for the excluded ones.
     *
     * @param array $fields
     */
    public static function require_whitelisted(array $fields) {
        $allowed = array_merge(array_keys(self::ROW), array_keys(self::PLUGIN));
        foreach (array_keys($fields) as $field) {
            if (isset(self::EXCLUDED[$field])) {
                throw new \moodle_exception('fieldexcluded', 'local_paatshala', '', (object)['field' => $field,
                    'reason' => get_string(self::EXCLUDED[$field], 'local_paatshala')]);
            }
            if (!in_array($field, $allowed, true)) {
                throw new \invalid_parameter_exception('Field ' . $field . ' is not allowed; allowed: ' .
                    implode(', ', $allowed));
            }
        }
    }

    /**
     * Journal both word limit fields when either is written: Moodle sets both from the
     * pair (a limit of 0 turns the limit off; turning it off sets the limit to 0).
     *
     * @param array $values
     * @return string[]
     */
    public function journal_keys(array $values): array {
        $keys = array_keys($values);
        if (array_intersect(self::WORDLIMIT, $keys)) {
            $keys = array_values(array_unique(array_merge($keys, self::WORDLIMIT)));
        }
        return $keys;
    }

    /**
     * Save through the settings form path. An undo also puts back the settings of a plugin
     * that ends up off: Moodle keeps them while the plugin is off but only saves them while
     * it is on, so the settings form path cannot restore them.
     *
     * @param array $values
     * @param array $current
     * @return array
     */
    public function write(array $values, array $current): array {
        $warnings = parent::write($values, $current);
        if (!$this->undoing) {
            return $warnings;
        }
        $assign = $this->assign();
        foreach ($values as $field => $value) {
            if (!isset(self::PLUGIN[$field]) || self::PLUGIN[$field][2] === 'enabled') {
                continue;
            }
            list($subtype, $type, $config, $valuetype) = self::PLUGIN[$field];
            $plugin = self::plugin($assign, $subtype, $type);
            if ($plugin && !$plugin->is_enabled()) {
                $plugin->set_config($config, $valuetype === 'int' ? (int)$value : (string)$value);
            }
        }
        return $warnings;
    }

    /**
     * Why Moodle did not save a plugin field: the plugin is off (refused even when the
     * stored value happens to match, as it has no effect, except in an undo, which
     * restores it), the word limit pair, or the plugin cannot be turned on here.
     *
     * @param string $field
     * @param array $values
     * @param array $state
     * @return string|null
     */
    protected function not_saved(string $field, array $values, array $state) {
        if (!isset(self::PLUGIN[$field])) {
            return null;
        }
        list($subtype, $type, $config) = self::PLUGIN[$field];
        $plugin = self::plugin($this->assign(), $subtype, $type);
        $enabledfield = $subtype . '_' . $type . '_enabled';
        $saved = state::same($values[$field], $state[$field]);
        $a = (object)['plugin' => $plugin->get_name(), 'enabled' => $enabledfield];

        if ($config === 'enabled') {
            if ($saved) {
                return null;
            }
            if (!$plugin->is_visible()) {
                return get_string('notsaved_pluginhidden', 'local_paatshala', $a);
            }
            if (!$plugin->is_configurable()) {
                return get_string('notsaved_notconfigurable', 'local_paatshala', $a);
            }
            if ($type === 'offline' && $plugin->get_config('enabled')) {
                // Offline grading worksheet: is_enabled() is false while advanced grading is active.
                return get_string('notsaved_offline', 'local_paatshala', $a);
            }
            return null;
        }
        if (!$this->undoing && empty($state[$enabledfield])) {
            return get_string('notsaved_pluginoff', 'local_paatshala', $a);
        }
        if ($saved) {
            return null;
        }
        if ($field === self::WORDLIMIT[0] && empty($state[self::WORDLIMIT[1]])) {
            return get_string('notsaved_wordlimitoff', 'local_paatshala', self::WORDLIMIT[1]);
        }
        if ($field === self::WORDLIMIT[1] && empty($state[self::WORDLIMIT[0]])) {
            return get_string('notsaved_wordlimitzero', 'local_paatshala', self::WORDLIMIT[0]);
        }
        return null;
    }

    /**
     * Form values: ints for numbers, as the form's elements post them.
     *
     * @param array $values
     * @return array
     */
    protected function form_changes(array $values): array {
        $changes = [];
        foreach ($values as $field => $value) {
            $type = self::ROW[$field] ?? self::PLUGIN[$field][3];
            $changes[$field] = $type === 'int' ? (int)$value : (string)$value;
        }
        return $changes;
    }
}

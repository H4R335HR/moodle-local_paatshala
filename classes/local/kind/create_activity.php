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
 * An activity created by the plugin from scratch.
 *
 * @package    local_paatshala
 * @copyright  2026 local_paatshala contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_paatshala\local\kind;

use local_paatshala\local\modform;

/**
 * An activity created from scratch.
 *
 * Assign, page, url or label, with a fixed whitelist of fields per type, through the
 * settings form's add path (modform::add()).
 *
 * @package    local_paatshala
 * @copyright  2026 local_paatshala contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class create_activity extends created_module {

    /** @var array Fields allowed per module type (besides name, intro and visible). */
    const FIELDS = [
        'assign' => ['allowsubmissionsfromdate', 'duedate', 'cutoffdate', 'gradingduedate', 'grade',
            'assignsubmission_onlinetext_enabled', 'assignsubmission_onlinetext_wordlimit',
            'assignsubmission_onlinetext_wordlimit_enabled', 'assignsubmission_file_enabled',
            'assignsubmission_file_maxfiles', 'assignsubmission_file_maxsizebytes', 'assignsubmission_file_filetypes',
            'assignfeedback_comments_enabled', 'assignfeedback_file_enabled', 'submissiondrafts',
            'requiresubmissionstatement', 'attemptreopenmethod', 'maxattempts', 'sendnotifications',
            'sendlatenotifications'],
        'page' => ['content'],
        'url' => ['externalurl'],
        'label' => [],
    ];

    /** @var string Module type (creation only). */
    protected $modname;

    /** @var int Section number (creation only). */
    protected $sectionnum;

    /** @var array Fields (creation only). */
    protected $fields;

    /**
     * Kind name.
     *
     * @return string
     */
    public static function kind(): string {
        return 'create_activity';
    }

    /**
     * Set what to create and check the fields against the whitelist.
     *
     * @param string $modname
     * @param int $sectionnum
     * @param array $fields
     * @return create_activity
     */
    public function with(string $modname, int $sectionnum, array $fields): create_activity {
        global $DB;
        if (!isset(self::FIELDS[$modname])) {
            throw new \invalid_parameter_exception('modname must be one of ' . implode(', ', array_keys(self::FIELDS)));
        }
        $allowed = array_merge(['name', 'intro', 'visible'], self::FIELDS[$modname]);
        $unknown = array_diff(array_keys($fields), $allowed);
        if ($unknown) {
            throw new \invalid_parameter_exception('Fields not allowed for ' . $modname . ': ' . implode(', ', $unknown) .
                '; allowed: ' . implode(', ', $allowed));
        }
        foreach ($fields as $field => $value) {
            if (!is_scalar($value) && $value !== null) {
                throw new \invalid_parameter_exception($field . ' must be a scalar');
            }
        }
        if (isset($fields['visible']) && !in_array((int)$fields['visible'], [0, 1], true)) {
            throw new \invalid_parameter_exception('visible must be 0 or 1');
        }
        if (!$DB->record_exists('course_sections', ['course' => $this->course->id, 'section' => $sectionnum])) {
            throw new \invalid_parameter_exception('Section ' . $sectionnum . ' does not exist in this course');
        }
        $this->modname = $modname;
        $this->sectionnum = $sectionnum;
        $this->fields = $fields;
        return $this;
    }

    /**
     * Create through the settings form's add path. Hidden unless visible = 1 is given.
     *
     * @return array
     */
    protected function create(): array {
        if (!$this->modname) {
            throw new \coding_exception('with() first');
        }
        $changes = [];
        foreach ($this->fields as $field => $value) {
            if ($field === 'intro') {
                $changes['introeditor'] = $this->editor((string)$value);
            } else if ($field === 'content') {
                // The form's editor (its required rule checks it); page_add_instance() reads
                // content directly when there is no form object.
                $changes['page'] = $this->editor((string)$value);
                $changes['content'] = (string)$value;
                $changes['contentformat'] = FORMAT_HTML;
            } else {
                $changes[$field] = $value;
            }
        }
        $changes['visible'] = (int)($this->fields['visible'] ?? 0);
        $changes['visibleoncoursepage'] = 1;

        $moduleinfo = modform::add($this->course, $this->modname, $this->sectionnum, $changes);
        $this->cmid = (int)$moduleinfo->coursemodule;
        return [];
    }

    /**
     * An editor element's submitted value for some HTML, with an empty draft area.
     *
     * @param string $text
     * @return array
     */
    protected function editor(string $text): array {
        return ['text' => $text, 'format' => FORMAT_HTML, 'itemid' => file_get_unused_draft_itemid()];
    }
}

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
 * Access restrictions as plain data, and the site checks for them.
 *
 * @package    local_paatshala
 * @copyright  2026 local_paatshala contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_paatshala\local;

/**
 * Access restrictions as plain data (no HTML).
 *
 * Read from the stored JSON without evaluating it for any user, so a tree Moodle cannot
 * read is reported, not fatal.
 *
 * @package    local_paatshala
 * @copyright  2026 local_paatshala contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class availability_report {

    /**
     * Site settings: restrict access on, and the enabled condition plugins.
     *
     * @return array [enableavailability (bool), enabled condition plugin names (sorted)]
     */
    public static function status(): array {
        global $CFG;
        $enabled = \core_plugin_manager::instance()->get_enabled_plugins('availability');
        $names = is_array($enabled) ? array_keys($enabled) : [];
        sort($names);
        return [!empty($CFG->enableavailability), $names];
    }

    /**
     * Refuse unless restrict access and the condition plugins for a request are on.
     *
     * @param string[] $types Condition types the request writes (group, date).
     * @throws \moodle_exception availabilitydisabled, naming the setting or plugin
     */
    public static function require_enabled(array $types) {
        list($availability, $enabled) = self::status();
        if (!$availability) {
            throw new \moodle_exception('availabilitydisabled', 'local_paatshala', '', 'enableavailability');
        }
        foreach ($types as $type) {
            if (!in_array($type, $enabled, true)) {
                throw new \moodle_exception('availabilitydisabled', 'local_paatshala', '', 'availability_' . $type);
            }
        }
    }

    /**
     * Why Moodle cannot read a stored tree, or null when it can ('' is none).
     *
     * Strict, as when a tree is saved (tree::__construct($structure, false),
     * availability/classes/tree.php:137): a condition of a type not enabled on the site is
     * an error here, where Moodle ignores it when showing the course (info.php:114).
     *
     * @param string $json
     * @return string|null
     */
    public static function parse_error(string $json) {
        if ($json === '') {
            return null;
        }
        $decoded = json_decode($json);
        if (!is_object($decoded)) {
            return 'Invalid availability text';
        }
        try {
            new \core_availability\tree($decoded, false);
        } catch (\coding_exception $e) {
            return is_string($e->a) && $e->a !== '' ? $e->a : $e->getMessage();
        }
        return null;
    }

    /**
     * Every condition of a stored tree, flattened as the reports list them (no names looked up).
     *
     * @param string $json '' for none.
     * @return array List of {type, path, negated, display, fields (JSON)}.
     */
    public static function conditions(string $json): array {
        $conditions = [];
        $groupids = [];
        $decoded = $json === '' ? null : json_decode($json);
        if (is_object($decoded)) {
            self::walk($decoded, '', false, null, true, $conditions, $groupids);
        }
        return $conditions;
    }

    /**
     * Report for a section.
     *
     * @param \stdClass $course
     * @param \stdClass $section course_sections record
     * @return array
     */
    public static function section(\stdClass $course, \stdClass $section): array {
        global $DB;
        $item = [
            'type' => 'section',
            'courseid' => (int)$course->id,
            'sectionid' => (int)$section->id,
            'sectionnum' => (int)$section->section,
            'cmid' => 0,
            'modname' => '',
            'name' => get_section_name($course, $section),
            'visible' => (int)$section->visible,
            'activities' => $section->sequence === '' || $section->sequence === null ? 0 :
                count(explode(',', $section->sequence)),
        ];
        return $item + self::tree((int)$course->id, (string)$section->availability);
    }

    /**
     * Report for an activity.
     *
     * @param \stdClass $course
     * @param \stdClass $cm course_modules record with modname and name
     * @param int $sectionnum
     * @return array
     */
    public static function module(\stdClass $course, \stdClass $cm, int $sectionnum): array {
        $item = [
            'type' => 'module',
            'courseid' => (int)$course->id,
            'sectionid' => (int)$cm->section,
            'sectionnum' => $sectionnum,
            'cmid' => (int)$cm->id,
            'modname' => $cm->modname,
            'name' => (string)$cm->name,
            'visible' => (int)$cm->visible,
        ];
        return $item + self::tree((int)$course->id, (string)$cm->availability);
    }

    /**
     * The tree part of a report.
     *
     * @param int $courseid
     * @param string $json
     * @return array
     */
    protected static function tree(int $courseid, string $json): array {
        global $DB;
        list($availability, $enabled) = self::status();
        $error = self::parse_error($json);
        $conditions = [];
        $groupids = [];
        $decoded = $json === '' ? null : json_decode($json);
        if (is_object($decoded)) {
            self::walk($decoded, '', false, null, true, $conditions, $groupids);
        }
        foreach ($conditions as $index => $condition) {
            if ($condition['type'] === 'completion' || $condition['type'] === 'grade') {
                $conditions[$index] += self::details($courseid, $condition);
            }
        }
        $groups = [];
        foreach (array_unique($groupids) as $groupid) {
            $record = $DB->get_record('groups', ['id' => $groupid], 'id, courseid, name');
            $groups[] = [
                'id' => $groupid,
                'name' => $record ? $record->name : '',
                'exists' => (bool)$record,
                'incourse' => $record && (int)$record->courseid === $courseid,
                'members' => $record ? $DB->count_records('groups_members', ['groupid' => $groupid]) : 0,
            ];
        }
        return [
            'availability' => $json,
            'valid' => $error === null,
            'parseerror' => (string)$error,
            'groups' => $groups,
            'conditions' => $conditions,
            'enableavailability' => $availability,
            'groupenabled' => in_array('group', $enabled, true),
            'dateenabled' => in_array('date', $enabled, true),
            'completionenabled' => in_array('completion', $enabled, true),
            'gradeenabled' => in_array('grade', $enabled, true),
            'parts' => self::parts($courseid, $json, $error),
        ];
    }

    /**
     * What a completion or grade condition points at.
     *
     * @param int $courseid
     * @param array $condition From walk().
     * @return array completion: ['cm' => {cmid, name, modname, completion, completiongradeitemnumber,
     *     exists}]; grade: ['gradeitem' => {id, name, itemtype, cmid, grademax, gradepass, exists}].
     */
    protected static function details(int $courseid, array $condition): array {
        global $CFG, $DB;
        $fields = json_decode($condition['fields']);
        $modinfo = get_fast_modinfo($courseid);
        if ($condition['type'] === 'completion') {
            $cmid = isset($fields->cm) && is_number($fields->cm) ? (int)$fields->cm : 0;
            $cm = $modinfo->cms[$cmid] ?? null;
            $exists = $cm !== null && !$cm->deletioninprogress;
            return ['cm' => [
                'cmid' => $cmid,
                'name' => $exists ? $cm->name : '',
                'modname' => $exists ? $cm->modname : '',
                'completion' => $exists ? (int)$cm->completion : 0,
                'completiongradeitemnumber' => $exists && $cm->completiongradeitemnumber !== null ?
                    (int)$cm->completiongradeitemnumber : -1,
                'exists' => $exists,
            ]];
        }
        require_once($CFG->libdir . '/gradelib.php');
        $id = isset($fields->id) && is_int($fields->id) ? $fields->id : 0;
        $record = $id ? $DB->get_record('grade_items', ['id' => $id, 'courseid' => $courseid]) : false;
        $item = $record ? new \grade_item($record, false) : null;
        $cmid = 0;
        if ($item && $item->itemtype === 'mod' && isset($modinfo->instances[$item->itemmodule][$item->iteminstance])) {
            $cmid = (int)$modinfo->instances[$item->itemmodule][$item->iteminstance]->id;
        }
        return ['gradeitem' => [
            'id' => $id,
            'name' => $item ? $item->get_name(true) : '',
            'itemtype' => $item ? (string)$item->itemtype : '',
            'cmid' => $cmid,
            'grademax' => $item ? (float)$item->grademax : 0.0,
            'gradepass' => $item ? (float)$item->gradepass : 0.0,
            'exists' => (bool)$item,
        ]];
    }

    /**
     * Which managed parts local_paatshala_set_access can change now: present (the part has its managed child),
     * changeable, and why not. A part is not changeable when the tree is invalid, when accesstree refuses it
     * (a condition of its type outside its managed child), or when the site or course settings it needs are off.
     *
     * @param int $courseid
     * @param string $json
     * @param string|null $error Parse error of the tree.
     * @return array List of {part, present, changeable, reason}.
     */
    protected static function parts(int $courseid, string $json, $error): array {
        global $CFG;
        list($availability, $enabled) = self::status();
        $tree = $error === null ? accesstree::decode($json) : null;
        $result = [];
        foreach (accesstree::PARTS as $part) {
            $type = in_array($part, ['from', 'until'], true) ? 'date' : $part;
            $present = false;
            $reason = '';
            if ($error !== null) {
                $reason = get_string('storedinvalid', 'local_paatshala', $error);
            } else {
                if ($tree !== null) {
                    try {
                        $present = accesstree::managed_index($tree, $part) !== null;
                    } catch (accesstree_exception $e) {
                        $reason = $e->getMessage();
                    }
                }
                if ($reason === '' && !$availability) {
                    $reason = get_string('availabilitydisabled', 'local_paatshala', 'enableavailability');
                } else if ($reason === '' && !in_array($type, $enabled, true)) {
                    $reason = get_string('availabilitydisabled', 'local_paatshala', 'availability_' . $type);
                } else if ($reason === '' && $part === 'completion' && (empty($CFG->enablecompletion) ||
                        !get_course($courseid)->enablecompletion)) {
                    $reason = get_string('completiondisabled', 'local_paatshala', empty($CFG->enablecompletion) ?
                        get_string('completiondisabled_site', 'local_paatshala') :
                        get_string('completiondisabled_course', 'local_paatshala', $courseid));
                }
            }
            $result[] = ['part' => $part, 'present' => $present, 'changeable' => $reason === '', 'reason' => $reason];
        }
        return $result;
    }

    /**
     * Collect conditions: path, effective negation (an odd number of '!' trees above, as
     * tree::get_logic_flags(), tree.php:579), display when unmet (the top-level showc
     * flag, or the root's show flag; tree::check_available(), tree.php:259-290).
     *
     * @param \stdClass $node
     * @param string $path
     * @param bool $not
     * @param string|null $display
     * @param bool $root
     * @param array $conditions
     * @param int[] $groupids
     */
    protected static function walk(\stdClass $node, string $path, bool $not, $display, bool $root,
            array &$conditions, array &$groupids) {
        if (isset($node->type)) {
            $fields = (array)$node;
            unset($fields['type']);
            $conditions[] = ['type' => (string)$node->type, 'path' => $path, 'negated' => $not,
                'display' => (string)$display, 'fields' => json_encode((object)$fields, JSON_UNESCAPED_SLASHES)];
            if ($node->type === 'group' && isset($node->id) && is_int($node->id)) {
                $groupids[] = $node->id;
            }
            return;
        }
        $op = isset($node->op) && is_string($node->op) ? $node->op : '';
        $childnot = strpos($op, '!') === 0 ? !$not : $not;
        if ($root && isset($node->show) && is_bool($node->show)) {
            $display = $node->show ? 'show' : 'hide';
        }
        foreach (isset($node->c) && is_array($node->c) ? $node->c : [] as $index => $child) {
            if (!is_object($child)) {
                continue;
            }
            $childdisplay = $display;
            if ($root && isset($node->showc) && is_array($node->showc) && array_key_exists($index, $node->showc)) {
                $childdisplay = $node->showc[$index] ? 'show' : 'hide';
            }
            self::walk($child, ($path === '' ? '' : $path . '.') . 'c[' . $index . ']', $childnot, $childdisplay, false,
                $conditions, $groupids);
        }
    }
}

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
 * An activity created by the plugin.
 *
 * @package    local_paatshala
 * @copyright  2026 local_paatshala contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_paatshala\local\kind;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/course/lib.php');

use local_paatshala\gate;
use local_paatshala\journal;
use local_paatshala\local\studentdata;

/**
 * An activity created by the plugin (copied, created, or restored from the recycle bin).
 *
 * State: {exists, cmid, binitemid}. The creating write is the subclass's create(). Undo
 * deletes the activity with course_delete_module(), as course/mod.php does, so the
 * course recycle bin keeps a backup; it is refused unless the course recycle bin is on
 * and the activity holds no student data (studentdata::module()). Redo restores that
 * recycle bin item with tool_recyclebin\course_bin::restore_item(); the activity gets a
 * new course module id.
 *
 * @package    local_paatshala
 * @copyright  2026 local_paatshala contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class created_module extends base {

    /** @var int|null Course module id, once it exists. */
    protected $cmid;

    /** @var int|null Course recycle bin item holding it, once deleted. */
    protected $binitemid;

    /**
     * Gate for the course the activity is (or will be) in.
     *
     * @param int $courseid
     * @param int|null $cmid
     * @param int|null $binitemid
     */
    public function __construct(int $courseid, $cmid = null, $binitemid = null) {
        $this->context = gate::course($courseid);
        $this->course = get_course($courseid);
        $this->cmid = $cmid ? (int)$cmid : null;
        $this->binitemid = $binitemid ? (int)$binitemid : null;
    }

    /**
     * Handler for a journal target.
     *
     * @param array $target
     * @return base
     */
    public static function from_target(array $target): base {
        return new static((int)$target['courseid'], $target['cmid'] ?? null, $target['binitemid'] ?? null);
    }

    /**
     * Create the activity (the function's own write).
     *
     * @return array Warnings.
     */
    abstract protected function create(): array;

    /**
     * Fields.
     *
     * @return string[]
     */
    public function fields(): array {
        return ['exists', 'cmid', 'binitemid'];
    }

    /**
     * Every write journals the whole state.
     *
     * @param array $values
     * @return string[]
     */
    public function journal_keys(array $values): array {
        return $this->fields();
    }

    /**
     * The course module record, if it exists and is not being deleted.
     *
     * @return \stdClass|false
     */
    protected function cm_record() {
        global $DB;
        if (!$this->cmid) {
            return false;
        }
        $cm = get_coursemodule_from_id('', $this->cmid, $this->course->id);
        if (!$cm || $DB->get_field('course_modules', 'deletioninprogress', ['id' => $this->cmid])) {
            return false;
        }
        return $cm;
    }

    /**
     * Current state.
     *
     * @return array
     */
    public function read(): array {
        global $DB;
        $bin = $this->binitemid && $DB->record_exists('tool_recyclebin_course',
            ['id' => $this->binitemid, 'courseid' => $this->course->id]);
        return [
            'exists' => $this->cm_record() ? 1 : 0,
            'cmid' => $this->cmid,
            'binitemid' => $bin ? $this->binitemid : null,
        ];
    }

    /**
     * Nothing to validate beyond what create(), delete() and restore() check.
     *
     * @param array $values
     * @param array $current
     */
    public function validate(array $values, array $current) {
    }

    /**
     * Create, delete or restore from the recycle bin.
     *
     * @param array $values
     * @param array $current
     * @return array
     */
    public function write(array $values, array $current): array {
        if (empty($values['exists'])) {
            $this->delete();
            return [];
        }
        if ($this->binitemid) {
            $this->restore($this->binitemid);
            return [];
        }
        return $this->create();
    }

    /**
     * Delete under the guards: course recycle bin on, no student data, capability as course/mod.php.
     */
    protected function delete() {
        global $DB;
        $cm = $this->cm_record();
        if (!$cm) {
            throw new \moodle_exception('invalidcoursemodule');
        }
        $context = \context_module::instance($cm->id);
        \external_api::validate_context($context);
        // As course/mod.php (delete).
        require_capability('moodle/course:manageactivities', $context);
        if (!\local_paatshala\local\recyclebin::course_bin_enabled()) {
            throw new \moodle_exception('recyclebindisabled', 'local_paatshala', '', 'course');
        }
        $found = studentdata::module($cm);
        if ($found) {
            $json = journal::encode($found);
            throw new \moodle_exception('studentdata', 'local_paatshala', '', $json, $json);
        }

        $before = (int)$DB->get_field_sql('SELECT MAX(id) FROM {tool_recyclebin_course} WHERE courseid = ?',
            [$this->course->id]);
        course_delete_module($cm->id);
        $item = $DB->get_records_select('tool_recyclebin_course', 'courseid = :courseid AND id > :before',
            ['courseid' => $this->course->id, 'before' => $before], 'id DESC', 'id', 0, 1);
        if (!$item) {
            throw new \moodle_exception('recyclebinnoitem', 'local_paatshala');
        }
        $this->binitemid = (int)reset($item)->id;
    }

    /**
     * Restore a course recycle bin item with tool_recyclebin's own restore; find the new cmid.
     *
     * @param int $itemid
     */
    protected function restore(int $itemid) {
        global $DB;
        // As admin/tool/recyclebin/index.php: tool/recyclebin:restoreitems in the course context.
        require_capability('tool/recyclebin:restoreitems', $this->context);
        $bin = new \tool_recyclebin\course_bin($this->course->id);
        $item = $DB->get_record('tool_recyclebin_course', ['id' => $itemid, 'courseid' => $this->course->id]);
        if (!$item) {
            throw new \moodle_exception('recyclebinnoitem', 'local_paatshala');
        }
        $existing = $DB->get_fieldset_select('course_modules', 'id', 'course = ?', [$this->course->id]);
        $bin->restore_item($item);
        $new = array_diff($DB->get_fieldset_select('course_modules', 'id', 'course = ?', [$this->course->id]), $existing);
        if (count($new) != 1) {
            throw new \moodle_exception('recyclebinnoitem', 'local_paatshala');
        }
        $this->cmid = (int)reset($new);
        $this->binitemid = null;
    }

    /**
     * Undo of a creation needs the activity as the entry left it; undo of a deletion (redo)
     * needs its recycle bin item, which may have expired.
     *
     * @param array $after
     * @param array $before
     * @param array $current
     */
    public function check_undo(array $after, array $before, array $current) {
        \local_paatshala\local\state::require_matches($after, $current);
    }

    /**
     * Creations and restores are flagged createdbyplugin.
     *
     * @param array $before
     * @param array $after
     * @return bool
     */
    public function createdbyplugin(array $before, array $after): bool {
        return empty($before['exists']) && !empty($after['exists']);
    }

    /**
     * Course module id, if any.
     *
     * @return int|null
     */
    public function cmid() {
        return $this->cmid;
    }

    /**
     * Target: course, course module and recycle bin item.
     *
     * @return array
     */
    public function target(): array {
        $target = ['courseid' => (int)$this->course->id, 'cmid' => $this->cmid, 'binitemid' => $this->binitemid];
        if ($cm = $this->cm_record()) {
            $target['modname'] = $cm->modname;
            $target['name'] = $cm->name;
        }
        return $target;
    }
}

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
 * A course created by the plugin.
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
 * A course created by the plugin.
 *
 * Created, duplicated, or restored from the category recycle bin.
 *
 * State: {exists, courseid, binitemid}. The gate is the category's: the course is not in
 * local_paatshala/allowedcourses until the admin adds it. Undo deletes the course with
 * delete_course(), as course/delete.php does after can_delete_course(), so the category
 * recycle bin keeps a backup; refused unless the category recycle bin is on and no user
 * holds a student role in the course. Redo restores that recycle bin item with
 * tool_recyclebin\category_bin::restore_item(); the course gets a new id.
 *
 * @package    local_paatshala
 * @copyright  2026 local_paatshala contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class created_course extends base {

    /** @var int Category id. */
    protected $categoryid;

    /** @var int|null Course id, once it exists. */
    protected $courseid;

    /** @var int|null Category recycle bin item holding it, once deleted. */
    protected $binitemid;

    /**
     * Gate for the category.
     *
     * @param int $categoryid
     * @param int|null $courseid
     * @param int|null $binitemid
     */
    public function __construct(int $categoryid, $courseid = null, $binitemid = null) {
        $this->context = gate::category($categoryid);
        $this->categoryid = $categoryid;
        $this->courseid = $courseid ? (int)$courseid : null;
        $this->binitemid = $binitemid ? (int)$binitemid : null;
    }

    /**
     * Handler for a journal target.
     *
     * @param array $target
     * @return base
     */
    public static function from_target(array $target): base {
        return new static((int)$target['categoryid'], $target['courseid'] ?? null, $target['binitemid'] ?? null);
    }

    /**
     * Create the course (the function's own write).
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
        return ['exists', 'courseid', 'binitemid'];
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
     * Current state.
     *
     * @return array
     */
    public function read(): array {
        global $DB;
        $exists = $this->courseid && $DB->record_exists('course', ['id' => $this->courseid]);
        $bin = $this->binitemid && $DB->record_exists('tool_recyclebin_category',
            ['id' => $this->binitemid, 'categoryid' => $this->categoryid]);
        return [
            'exists' => $exists ? 1 : 0,
            'courseid' => $this->courseid,
            'binitemid' => $bin ? $this->binitemid : null,
        ];
    }

    /**
     * Nothing beyond what the writes check.
     *
     * @param array $values
     * @param array $current
     */
    public function validate(array $values, array $current) {
    }

    /**
     * Create, delete or restore from the category recycle bin.
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
     * A warning when the new course is not in local_paatshala/allowedcourses.
     *
     * @param int $courseid
     * @return array
     */
    protected static function allowlist_warning(int $courseid): array {
        if (gate::course_allowed($courseid)) {
            return [];
        }
        return [['notallowed', get_string('warning_newcoursenotallowed', 'local_paatshala', $courseid)]];
    }

    /**
     * Fail unless a shortname is free.
     *
     * @param string $shortname
     */
    protected static function require_shortname_free(string $shortname) {
        global $DB;
        if ($course = $DB->get_record('course', ['shortname' => $shortname], 'id, fullname', IGNORE_MULTIPLE)) {
            throw new \moodle_exception('shortnametaken', '', '', $course->fullname);
        }
    }

    /**
     * Delete under the guards: as course/delete.php, category recycle bin on, no students.
     */
    protected function delete() {
        global $DB;
        $course = $DB->get_record('course', ['id' => $this->courseid]);
        if (!$course) {
            throw new \moodle_exception('invalidcourseid');
        }
        // As course/delete.php.
        if (!can_delete_course($course->id)) {
            throw new \required_capability_exception(\context_course::instance($course->id), 'moodle/course:delete',
                'nopermissions', '');
        }
        if (!\local_paatshala\local\recyclebin::category_bin_enabled()) {
            throw new \moodle_exception('recyclebindisabled', 'local_paatshala', '', 'category');
        }
        $found = studentdata::course($course->id);
        if ($found) {
            $json = journal::encode($found);
            throw new \moodle_exception('studentdata', 'local_paatshala', '', $json, $json);
        }
        $before = (int)$DB->get_field_sql('SELECT MAX(id) FROM {tool_recyclebin_category} WHERE categoryid = ?',
            [$course->category]);
        delete_course($course, false);
        $item = $DB->get_records_select('tool_recyclebin_category', 'categoryid = :categoryid AND id > :before',
            ['categoryid' => $course->category, 'before' => $before], 'id DESC', 'id', 0, 1);
        if (!$item) {
            throw new \moodle_exception('recyclebinnoitem', 'local_paatshala');
        }
        $this->binitemid = (int)reset($item)->id;
    }

    /**
     * Restore a category recycle bin item with tool_recyclebin's own restore.
     *
     * @param int $itemid
     */
    protected function restore(int $itemid) {
        global $DB;
        // As admin/tool/recyclebin/index.php: tool/recyclebin:restoreitems in the category context.
        require_capability('tool/recyclebin:restoreitems', $this->context);
        $item = $DB->get_record('tool_recyclebin_category', ['id' => $itemid, 'categoryid' => $this->categoryid]);
        if (!$item) {
            throw new \moodle_exception('recyclebinnoitem', 'local_paatshala');
        }
        // The restore (category_bin::restore_item()) creates the course with the item's shortname.
        self::require_shortname_free($item->shortname);
        $bin = new \tool_recyclebin\category_bin($this->categoryid);
        $bin->restore_item($item);
        $this->courseid = (int)$DB->get_field('course', 'id', ['shortname' => $item->shortname], MUST_EXIST);
        $this->binitemid = null;
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
     * The course.
     *
     * @return int
     */
    public function courseid(): int {
        return (int)$this->courseid;
    }

    /**
     * No course module.
     *
     * @return null
     */
    public function cmid() {
        return null;
    }

    /**
     * Target: category, course and recycle bin item.
     *
     * @return array
     */
    public function target(): array {
        global $DB;
        $target = ['categoryid' => $this->categoryid, 'courseid' => $this->courseid, 'binitemid' => $this->binitemid];
        if ($this->courseid && ($course = $DB->get_record('course', ['id' => $this->courseid], 'shortname, fullname'))) {
            $target['shortname'] = $course->shortname;
            $target['fullname'] = $course->fullname;
        }
        return $target;
    }
}

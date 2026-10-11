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
 * Position of an activity in the course.
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
use local_paatshala\local\state;

/**
 * Position of an activity: its section and the activity it sits before (0: last).
 *
 * Moved with moveto_module(), as the course page's drag and drop does (course/rest.php,
 * class resource, field move). Moving into a hidden section hides the activity and moving
 * a hidden activity into a visible section restores its visibility from visibleold, as
 * on the course page; visible and visibleoncoursepage are journaled so undo restores them.
 *
 * @package    local_paatshala
 * @copyright  2026 local_paatshala contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class move_module extends base {

    /**
     * Gate and capability checks for an activity.
     *
     * @param int $cmid
     */
    public function __construct(int $cmid) {
        list($this->cm, $this->course, $this->context) = gate::module($cmid);
        // As course/rest.php: moodle/course:manageactivities in the module context.
        require_capability('moodle/course:manageactivities', $this->context);
    }

    /**
     * Kind name.
     *
     * @return string
     */
    public static function kind(): string {
        return 'move_module';
    }

    /**
     * Handler for a journal target.
     *
     * @param array $target
     * @return base
     */
    public static function from_target(array $target): base {
        return new self((int)$target['cmid']);
    }

    /**
     * Fields: the input's sectionnum and beforecmid, plus visibility, which a move can change.
     *
     * @return string[]
     */
    public function fields(): array {
        return ['sectionnum', 'beforecmid', 'visible', 'visibleoncoursepage'];
    }

    /**
     * Every move journals the visibility too.
     *
     * @param array $values
     * @return string[]
     */
    public function journal_keys(array $values): array {
        return $this->fields();
    }

    /**
     * Current position and visibility, from the database (not the cached modinfo).
     *
     * @return array
     */
    public function read(): array {
        global $DB;
        $cm = $DB->get_record('course_modules', ['id' => $this->cm->id], 'id, section, visible, visibleoncoursepage',
            MUST_EXIST);
        $section = $DB->get_record('course_sections', ['id' => $cm->section], 'id, section, sequence', MUST_EXIST);
        $sequence = self::sequence($section->sequence);
        $index = array_search((int)$cm->id, $sequence, true);
        $before = ($index !== false && isset($sequence[$index + 1])) ? $sequence[$index + 1] : 0;
        return [
            'sectionnum' => (int)$section->section,
            'beforecmid' => $before,
            'visible' => (int)$cm->visible,
            'visibleoncoursepage' => (int)$cm->visibleoncoursepage,
        ];
    }

    /**
     * Course module ids in a section sequence.
     *
     * @param string|null $sequence
     * @return int[]
     */
    protected static function sequence($sequence): array {
        $ids = [];
        foreach (explode(',', (string)$sequence) as $id) {
            if (trim($id) !== '') {
                $ids[] = (int)$id;
            }
        }
        return $ids;
    }

    /**
     * The target section must exist (course/rest.php refuses a bad section id) and the
     * activity to sit before must be in it.
     *
     * @param array $values
     * @param array $current
     */
    public function validate(array $values, array $current) {
        $section = $this->target_section((int)$values['sectionnum']);
        if (!$section) {
            throw new \invalid_parameter_exception('Section ' . $values['sectionnum'] . ' does not exist in this course');
        }
        $beforecmid = (int)($values['beforecmid'] ?? 0);
        if ($beforecmid) {
            if ($beforecmid == $this->cm->id) {
                throw new \invalid_parameter_exception('beforecmid is the activity being moved');
            }
            if (!in_array($beforecmid, self::sequence($section->sequence), true)) {
                throw new \invalid_parameter_exception('Activity ' . $beforecmid . ' is not in section ' .
                    $values['sectionnum']);
            }
        }
        foreach (['visible', 'visibleoncoursepage'] as $field) {
            if (isset($values[$field]) && !in_array((int)$values[$field], [0, 1], true)) {
                throw new \invalid_parameter_exception($field . ' must be 0 or 1');
            }
        }
    }

    /**
     * Section record by number.
     *
     * @param int $sectionnum
     * @return \stdClass|false
     */
    protected function target_section(int $sectionnum) {
        global $DB;
        return $DB->get_record('course_sections', ['course' => $this->course->id, 'section' => $sectionnum]);
    }

    /**
     * Move with moveto_module(); restore visibility if the values carry it (undo).
     *
     * @param array $values
     * @param array $current
     * @return array
     */
    public function write(array $values, array $current): array {
        global $DB;
        $section = $this->target_section((int)$values['sectionnum']);
        $cm = $DB->get_record('course_modules', ['id' => $this->cm->id], '*', MUST_EXIST);
        $beforecmid = (int)($values['beforecmid'] ?? 0);
        $beforemod = $beforecmid ? $DB->get_record('course_modules', ['id' => $beforecmid]) : null;
        moveto_module($cm, $section, $beforemod);

        $warnings = [];
        $moved = $this->read();
        if (isset($values['visible']) && ($moved['visible'] != $values['visible']
                || $moved['visibleoncoursepage'] != ($values['visibleoncoursepage'] ?? $moved['visibleoncoursepage']))) {
            // Restoring a visibility the move changed (undo); the course page needs this capability
            // to show or hide an activity (course/mod.php).
            require_capability('moodle/course:activityvisibility', $this->context);
            set_coursemodule_visible($this->cm->id, (int)$values['visible'],
                (int)($values['visibleoncoursepage'] ?? 1));
            \core\event\course_module_updated::create_from_cm(get_coursemodule_from_id('', $this->cm->id))->trigger();
        } else if ($moved['visible'] != $current['visible']) {
            $warnings[] = [$moved['visible'] ? 'shown' : 'hidden',
                get_string($moved['visible'] ? 'warning_moveshown' : 'warning_movehidden', 'local_paatshala')];
        }
        return $warnings;
    }

    /**
     * Undo needs the activity where the entry left it and its old neighbour still in the
     * old section.
     *
     * @param array $after
     * @param array $before
     * @param array $current
     */
    public function check_undo(array $after, array $before, array $current) {
        state::require_matches($after, $current);
        $section = $this->target_section((int)$before['sectionnum']);
        $neighbour = (int)($before['beforecmid'] ?? 0);
        if (!$section || ($neighbour && !in_array($neighbour, self::sequence($section->sequence), true))) {
            $json = \local_paatshala\journal::encode($current);
            throw new \moodle_exception('conflict', 'local_paatshala', '', $json, $json);
        }
    }

    /**
     * Target.
     *
     * @return array
     */
    public function target(): array {
        return ['cmid' => (int)$this->cm->id, 'modname' => $this->cm->modname, 'instance' => (int)$this->cm->instance,
            'name' => $this->cm->name];
    }
}

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
 * Position of a course section.
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

/**
 * Position (section number) of a course section.
 *
 * Moved with move_section_to() as the course page's drag and drop does (course/rest.php,
 * class section, field move).
 *
 * The section is identified by its id, so undo finds it wherever it is.
 *
 * @package    local_paatshala
 * @copyright  2026 local_paatshala contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class move_section extends base {

    /** @var int Section id. */
    protected $sectionid;

    /**
     * Gate and capability checks for a section.
     *
     * @param int $courseid
     * @param int $sectionid
     */
    public function __construct(int $courseid, int $sectionid) {
        $this->context = gate::course($courseid);
        $this->course = get_course($courseid);
        $this->sectionid = $sectionid;
        // As course/rest.php: moodle/course:movesections in the course context.
        require_capability('moodle/course:movesections', $this->context);
    }

    /**
     * Handler for the section currently at a number.
     *
     * @param int $courseid
     * @param int $sectionnum
     * @return move_section
     */
    public static function at(int $courseid, int $sectionnum): move_section {
        global $DB;
        gate::course($courseid);
        $id = $DB->get_field('course_sections', 'id', ['course' => $courseid, 'section' => $sectionnum]);
        if (!$id) {
            throw new \invalid_parameter_exception('Section ' . $sectionnum . ' does not exist in this course');
        }
        return new self($courseid, (int)$id);
    }

    /**
     * Kind name.
     *
     * @return string
     */
    public static function kind(): string {
        return 'move_section';
    }

    /**
     * Handler for a journal target.
     *
     * @param array $target
     * @return base
     */
    public static function from_target(array $target): base {
        return new self((int)$target['courseid'], (int)$target['sectionid']);
    }

    /**
     * Fields.
     *
     * @return string[]
     */
    public function fields(): array {
        return ['sectionnum'];
    }

    /**
     * Expected may also pin which section this is.
     *
     * @return string[]
     */
    public function expected_fields(): array {
        return ['sectionnum', 'sectionid', 'name'];
    }

    /**
     * Current number; null fields if the section is gone.
     *
     * @return array
     */
    public function read(): array {
        global $DB;
        $section = $DB->get_record('course_sections', ['id' => $this->sectionid, 'course' => $this->course->id],
            'id, section, name');
        return [
            'sectionnum' => $section ? (int)$section->section : null,
            'sectionid' => $section ? (int)$section->id : null,
            'name' => $section ? $section->name : null,
        ];
    }

    /**
     * Section 0 never moves; the destination must be an existing section number.
     *
     * @param array $values
     * @param array $current
     */
    public function validate(array $values, array $current) {
        if ($current['sectionnum'] === null) {
            throw new \invalid_parameter_exception('Section ' . $this->sectionid . ' does not exist');
        }
        $target = (int)$values['sectionnum'];
        $last = course_get_format($this->course)->get_last_section_number();
        if ($current['sectionnum'] == 0 || $target < 1 || $target > $last) {
            throw new \invalid_parameter_exception('Sections 1 to ' . $last . ' can be moved to positions 1 to ' . $last);
        }
    }

    /**
     * Move with move_section_to().
     *
     * @param array $values
     * @param array $current
     * @return array
     */
    public function write(array $values, array $current): array {
        $course = get_course($this->course->id);
        if (!move_section_to($course, (int)$current['sectionnum'], (int)$values['sectionnum'])) {
            throw new \moodle_exception('cannotmovesection', 'local_paatshala');
        }
        return [];
    }

    /**
     * Target.
     *
     * @return array
     */
    public function target(): array {
        global $DB;
        return ['courseid' => (int)$this->course->id, 'sectionid' => $this->sectionid,
            'name' => $DB->get_field('course_sections', 'name', ['id' => $this->sectionid])];
    }
}

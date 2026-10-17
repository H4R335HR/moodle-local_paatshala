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
 * Access restrictions of a course section.
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
 * Access restrictions of a course section, saved as course/editsection.php does.
 *
 * Section 0 too: the course page gives every section, the general one included, an edit
 * link (course/format/renderer.php:289-302) and course/editsection_form.php:60-71 adds the
 * restriction field for any section.
 *
 * @package    local_paatshala
 * @copyright  2026 local_paatshala contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class section_availability extends availability {

    /** @var \stdClass The course_sections record. */
    protected $section;

    /**
     * Gate and capability checks.
     *
     * @param int $courseid
     * @param int $sectionid
     */
    public function __construct(int $courseid, int $sectionid) {
        global $DB;
        $this->context = gate::course($courseid);
        $this->course = get_course($courseid);
        $section = $DB->get_record('course_sections', ['id' => $sectionid]);
        if (!$section || (int)$section->course !== $courseid) {
            throw new \invalid_parameter_exception('Section ' . $sectionid . ' is not in course ' . $courseid);
        }
        $this->section = $section;
        // As course/editsection.php:40-42: require_login($course), moodle/course:update.
        require_capability('moodle/course:update', $this->context);
    }

    /**
     * Kind name.
     *
     * @return string
     */
    public static function kind(): string {
        return 'section_availability';
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
     * Stored value.
     *
     * @return string|null
     */
    protected function stored() {
        global $DB;
        return $DB->get_field('course_sections', 'availability', ['id' => $this->section->id], MUST_EXIST);
    }

    /**
     * As course/editsection.php:119-129: '' becomes null, then course_update_section(),
     * which saves, rebuilds the course cache and triggers course_section_updated.
     *
     * @param string $json
     */
    protected function store(string $json) {
        global $DB;
        $section = $DB->get_record('course_sections', ['id' => $this->section->id], '*', MUST_EXIST);
        course_update_section($this->course, $section, ['availability' => $json === '' ? null : $json]);
    }

    /**
     * Target.
     *
     * @return array
     */
    public function target(): array {
        return ['courseid' => (int)$this->course->id, 'sectionid' => (int)$this->section->id,
            'sectionnum' => (int)$this->section->section,
            'name' => get_section_name($this->course, $this->section)];
    }
}

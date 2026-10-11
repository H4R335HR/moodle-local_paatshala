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
 * A course section created by the plugin.
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

/**
 * A course section created by the plugin: exists or not, its number and name.
 *
 * Created with course_create_section() as course/changenumsections.php does, named with
 * course_update_section(). Undo deletes it with course_delete_section() without forcing,
 * so only while it has no activities and no summary (the course format's own rule), as
 * course/editsection.php does with course_can_delete_section()'s checks. Redo creates a
 * new section at the same number with the same name; it gets a new id.
 *
 * @package    local_paatshala
 * @copyright  2026 local_paatshala contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class create_section extends base {

    /** @var int|null Section id, once it exists. */
    protected $sectionid;

    /**
     * Gate and capability checks for the course.
     *
     * @param int $courseid
     * @param int|null $sectionid Null before creation.
     */
    public function __construct(int $courseid, $sectionid = null) {
        $this->context = gate::course($courseid);
        $this->course = get_course($courseid);
        $this->sectionid = $sectionid;
        // As course/changenumsections.php and course/editsection.php.
        require_capability('moodle/course:update', $this->context);
    }

    /**
     * Kind name.
     *
     * @return string
     */
    public static function kind(): string {
        return 'create_section';
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
     * Fields: exists (1 create, 0 delete), sectionnum (0 or omitted: at the end) and name.
     *
     * @return string[]
     */
    public function fields(): array {
        return ['exists', 'sectionnum', 'name'];
    }

    /**
     * Current state.
     *
     * @return array
     */
    public function read(): array {
        $section = $this->section();
        return [
            'exists' => $section ? 1 : 0,
            'sectionnum' => $section ? (int)$section->section : null,
            'name' => $section ? $section->name : null,
        ];
    }

    /**
     * The section record, if it exists.
     *
     * @return \stdClass|false
     */
    protected function section() {
        global $DB;
        if (!$this->sectionid) {
            return false;
        }
        return $DB->get_record('course_sections', ['id' => $this->sectionid, 'course' => $this->course->id]);
    }

    /**
     * Creation: the format uses sections, the course stays within the maximum number of
     * sections and the position is 0 (end) or an existing number or one past the last
     * (course/changenumsections.php).
     *
     * @param array $values
     * @param array $current
     */
    public function validate(array $values, array $current) {
        if (empty($values['exists'])) {
            return;
        }
        if ($current['exists']) {
            throw new \invalid_parameter_exception('The section already exists');
        }
        $format = course_get_format($this->course);
        if (!$format->uses_sections() || array_key_exists('numsections', $format->get_format_options())) {
            throw new \moodle_exception('formatnosections', 'local_paatshala', '', $this->course->format);
        }
        $last = $format->get_last_section_number();
        if ($last + 1 > $format->get_max_sections()) {
            throw new \moodle_exception('maxsectionslimit', 'moodle', '', $format->get_max_sections());
        }
        $position = (int)($values['sectionnum'] ?? 0);
        if ($position < 0 || $position > $last + 1) {
            throw new \invalid_parameter_exception('position must be 0 (at the end) or 1 to ' . ($last + 1));
        }
        if (\core_text::strlen((string)($values['name'] ?? '')) > 255) {
            throw new \invalid_parameter_exception('name is longer than 255 characters');
        }
    }

    /**
     * Create or delete.
     *
     * @param array $values
     * @param array $current
     * @return array
     */
    public function write(array $values, array $current): array {
        if (!empty($values['exists'])) {
            $position = (int)($values['sectionnum'] ?? 0);
            $last = course_get_format($this->course)->get_last_section_number();
            if ($position > 0 && $position <= $last) {
                // Inserting before an existing section moves the others (changenumsections.php).
                require_capability('moodle/course:movesections', $this->context);
            }
            $section = course_create_section($this->course, $position);
            $this->sectionid = (int)$section->id;
            $name = (string)($values['name'] ?? '');
            if ($name !== '') {
                course_update_section($this->course, $section, ['name' => $name]);
            }
            return [];
        }

        $section = $this->section();
        // As course/editsection.php: course_can_delete_section() (format allows it,
        // moodle/course:movesections and moodle/course:update).
        if (!$section || !course_can_delete_section($this->course, $section)) {
            throw new \moodle_exception('cannotdeletesection', 'local_paatshala');
        }
        if (!course_delete_section($this->course, $section, false, false)) {
            throw new \moodle_exception('cannotdeletesection', 'local_paatshala');
        }
        return [];
    }

    /**
     * Undoing a creation needs the section still there, with the same name, and empty
     * (no activities, no summary): course_delete_section() is never forced.
     *
     * @param array $after
     * @param array $before
     * @param array $current
     */
    public function check_undo(array $after, array $before, array $current) {
        $section = $this->section();
        if (!empty($after['exists'])) {
            $ok = $section && \local_paatshala\local\state::same($after['name'], $current['name'])
                && trim((string)$section->sequence) === '' && trim((string)$section->summary) === '';
        } else {
            $ok = !$section;
        }
        if (!$ok) {
            $state = $current;
            if ($section) {
                $state['activities'] = count(array_filter(explode(',', (string)$section->sequence)));
                $state['hassummary'] = trim((string)$section->summary) !== '';
            }
            $json = journal::encode($state);
            throw new \moodle_exception('conflict', 'local_paatshala', '', $json, $json);
        }
    }

    /**
     * A creation is flagged createdbyplugin.
     *
     * @param array $before
     * @param array $after
     * @return bool
     */
    public function createdbyplugin(array $before, array $after): bool {
        return empty($before['exists']) && !empty($after['exists']);
    }

    /**
     * Target: the section id (new on each creation).
     *
     * @return array
     */
    public function target(): array {
        return ['courseid' => (int)$this->course->id, 'sectionid' => $this->sectionid];
    }
}

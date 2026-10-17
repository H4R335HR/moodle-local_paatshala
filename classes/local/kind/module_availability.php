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
 * Access restrictions of an activity.
 *
 * @package    local_paatshala
 * @copyright  2026 local_paatshala contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_paatshala\local\kind;

use local_paatshala\gate;
use local_paatshala\local\modform;

/**
 * Access restrictions of an activity.
 *
 * Saved through the settings form path (modform::save()) with
 * availabilityconditionsjson, as course/modedit.php does.
 *
 * @package    local_paatshala
 * @copyright  2026 local_paatshala contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class module_availability extends availability {

    /**
     * Gate and capability checks.
     *
     * @param int $courseid
     * @param int $cmid
     */
    public function __construct(int $courseid, int $cmid) {
        list($this->cm, $this->course, $this->context) = gate::module($cmid);
        if ((int)$this->cm->course !== $courseid) {
            throw new \invalid_parameter_exception('Course module ' . $cmid . ' is not in course ' . $courseid);
        }
        // The settings page: course/modedit.php calls can_update_moduleinfo(), which requires
        // moodle/course:manageactivities in the module context (course/modlib.php:470-475).
        require_capability('moodle/course:manageactivities', $this->context);
    }

    /**
     * Kind name.
     *
     * @return string
     */
    public static function kind(): string {
        return 'module_availability';
    }

    /**
     * Handler for a journal target.
     *
     * @param array $target
     * @return base
     */
    public static function from_target(array $target): base {
        return new self((int)$target['courseid'], (int)$target['cmid']);
    }

    /**
     * Stored value.
     *
     * @return string|null
     */
    protected function stored() {
        global $DB;
        return $DB->get_field('course_modules', 'availability', ['id' => $this->cm->id], MUST_EXIST);
    }

    /**
     * Through the settings form path; update_moduleinfo() stores '' as null
     * (course/modlib.php:546-551).
     *
     * @param string $json
     */
    protected function store(string $json) {
        modform::save($this->cm, $this->course, ['availabilityconditionsjson' => $json]);
    }

    /**
     * Target.
     *
     * @return array
     */
    public function target(): array {
        return ['courseid' => (int)$this->course->id, 'cmid' => (int)$this->cm->id, 'modname' => $this->cm->modname,
            'name' => $this->cm->name];
    }
}

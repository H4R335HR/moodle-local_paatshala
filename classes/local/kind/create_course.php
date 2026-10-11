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
 * A course created by the plugin from scratch.
 *
 * @package    local_paatshala
 * @copyright  2026 local_paatshala contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_paatshala\local\kind;

/**
 * A course created with create_course(), as course/edit.php does.
 *
 * The data starts from the site's course defaults (moodlecourse settings), as the course
 * settings form does; the course is created hidden.
 *
 * @package    local_paatshala
 * @copyright  2026 local_paatshala contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class create_course extends created_course {

    /** @var \stdClass|null Course data (creation only). */
    protected $data;

    /**
     * Kind name.
     *
     * @return string
     */
    public static function kind(): string {
        return 'create_course';
    }

    /**
     * Set and check the course to create.
     *
     * Checks as course/edit.php and course_edit_form::validation(): moodle/course:create in
     * the category, names given and within length, shortname free, end date after the start
     * date (course_validate_dates()), and an enabled course format.
     *
     * @param string $fullname
     * @param string $shortname
     * @param int $startdate
     * @param int $enddate
     * @param string $format
     * @return create_course
     */
    public function with(string $fullname, string $shortname, int $startdate, int $enddate,
            string $format): create_course {
        require_capability('moodle/course:create', $this->context);
        $fullname = trim($fullname);
        $shortname = trim($shortname);
        if ($fullname === '' || $shortname === '' || \core_text::strlen($fullname) > 254 ||
                \core_text::strlen($shortname) > 255) {
            throw new \invalid_parameter_exception('fullname (1-254 characters) and shortname (1-255) are required');
        }
        if ($startdate <= 0 || $enddate < 0) {
            throw new \invalid_parameter_exception('startdate must be a unix timestamp; enddate 0 or a timestamp');
        }
        self::require_shortname_free($shortname);
        if ($errorcode = course_validate_dates(['startdate' => $startdate, 'enddate' => $enddate])) {
            throw new \moodle_exception($errorcode, 'error');
        }
        if (!in_array($format, get_sorted_course_formats(true), true)) {
            throw new \invalid_parameter_exception('format ' . $format . ' is not an enabled course format');
        }

        $config = get_config('moodlecourse');
        $this->data = (object)[
            'category' => $this->categoryid,
            'fullname' => $fullname,
            'shortname' => $shortname,
            'idnumber' => '',
            'summary' => '',
            'summaryformat' => FORMAT_HTML,
            'format' => $format,
            'startdate' => $startdate,
            'enddate' => $enddate,
            'visible' => 0,
            'numsections' => (int)($config->numsections ?? 4),
            'hiddensections' => (int)($config->hiddensections ?? 0),
            'coursedisplay' => (int)($config->coursedisplay ?? 0),
            'lang' => (string)($config->lang ?? ''),
            'newsitems' => (int)($config->newsitems ?? 5),
            'showgrades' => (int)($config->showgrades ?? 1),
            'showreports' => (int)($config->showreports ?? 0),
            'maxbytes' => (int)($config->maxbytes ?? 0),
            'enablecompletion' => (int)($config->enablecompletion ?? 1),
            'groupmode' => (int)($config->groupmode ?? 0),
            'groupmodeforce' => (int)($config->groupmodeforce ?? 0),
        ];
        return $this;
    }

    /**
     * Create as course/edit.php does, including its enrolment of the creator.
     *
     * @return array
     */
    protected function create(): array {
        global $CFG, $USER;
        if (!$this->data) {
            throw new \coding_exception('with() first');
        }
        $course = create_course($this->data);
        $this->courseid = (int)$course->id;

        $warnings = [];
        $context = \context_course::instance($course->id);
        // As course/edit.php: enrol the creator if they could not otherwise assign roles there.
        if (!empty($CFG->creatornewroleid) && !is_viewing($context, null, 'moodle/role:assign') &&
                !is_enrolled($context, null, 'moodle/role:assign')) {
            enrol_try_internal_enrol($course->id, $USER->id, $CFG->creatornewroleid);
            $warnings[] = ['creatorenrolled', get_string('warning_creatorenrolled', 'local_paatshala')];
        }
        return array_merge($warnings, self::allowlist_warning($this->courseid));
    }
}

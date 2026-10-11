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
 * Plugin gate: the checks every local_paatshala function runs first.
 *
 * @package    local_paatshala
 * @copyright  2026 local_paatshala contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_paatshala;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/externallib.php');

/**
 * Plugin gate: enabled setting, course and category allowlists, and local/paatshala:use.
 *
 * Order in every function: enabled, allowlist, validate_context(), local/paatshala:use,
 * then the function's own capabilities.
 *
 * @package    local_paatshala
 * @copyright  2026 local_paatshala contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class gate {

    /** @var string The plugin capability. */
    const CAPABILITY = 'local/paatshala:use';

    /**
     * Whether the admin setting local_paatshala/enabled is on.
     *
     * @return bool
     */
    public static function is_enabled(): bool {
        return !empty(get_config('local_paatshala', 'enabled'));
    }

    /**
     * Refuse unless the plugin is enabled.
     *
     * @throws \moodle_exception disabled
     */
    public static function require_enabled() {
        if (!self::is_enabled()) {
            throw new \moodle_exception('disabled', 'local_paatshala');
        }
    }

    /**
     * Parse an allowlist setting.
     *
     * @param string|false|null $value Setting value: empty, "*" or comma-separated ids.
     * @return int[]|null Null means every id ("*"); an empty array means none.
     */
    public static function parse_list($value) {
        $value = trim((string)$value);
        if ($value === '*') {
            return null;
        }
        $ids = [];
        foreach (explode(',', $value) as $part) {
            $part = trim($part);
            if ($part !== '' && ctype_digit($part)) {
                $ids[] = (int)$part;
            }
        }
        return array_values(array_unique($ids));
    }

    /**
     * Whether a course is in local_paatshala/allowedcourses. The site course never is.
     *
     * @param int $courseid
     * @return bool
     */
    public static function course_allowed(int $courseid): bool {
        if ($courseid == SITEID) {
            return false;
        }
        $ids = self::parse_list(get_config('local_paatshala', 'allowedcourses'));
        return $ids === null || in_array($courseid, $ids, true);
    }

    /**
     * Whether a course category is in local_paatshala/allowedcategories.
     *
     * @param int $categoryid
     * @return bool
     */
    public static function category_allowed(int $categoryid): bool {
        $ids = self::parse_list(get_config('local_paatshala', 'allowedcategories'));
        return $ids === null || in_array($categoryid, $ids, true);
    }

    /**
     * Refuse unless the course is allowed.
     *
     * @param int $courseid
     * @throws \moodle_exception coursenotallowed
     */
    public static function require_course_allowed(int $courseid) {
        if (!self::course_allowed($courseid)) {
            throw new \moodle_exception('coursenotallowed', 'local_paatshala', '', $courseid);
        }
    }

    /**
     * Gate for a function acting on a course.
     *
     * @param int $courseid
     * @return \context_course
     */
    public static function course(int $courseid): \context_course {
        self::require_enabled();
        self::require_course_allowed($courseid);
        $context = \context_course::instance($courseid);
        \external_api::validate_context($context);
        require_capability(self::CAPABILITY, $context);
        return $context;
    }

    /**
     * Gate for a function acting on a course module.
     *
     * The plugin capability is checked in the course context; the context validated is
     * the module's, as core's mod_assign external functions do.
     *
     * @param int $cmid
     * @return array [\cm_info|\stdClass $cm, \stdClass $course, \context_module $context]
     */
    public static function module(int $cmid): array {
        self::require_enabled();
        $cm = get_coursemodule_from_id('', $cmid, 0, false, MUST_EXIST);
        self::require_course_allowed((int)$cm->course);
        $context = \context_module::instance($cm->id);
        \external_api::validate_context($context);
        require_capability(self::CAPABILITY, $context->get_course_context());
        $course = get_course($cm->course);
        return [$cm, $course, $context];
    }

    /**
     * Refuse unless a course module is of the given module type.
     *
     * @param \stdClass $cm Course module record with modname.
     * @param string $modname
     * @throws \moodle_exception wrongmodule
     */
    public static function require_modname(\stdClass $cm, string $modname) {
        if ($cm->modname !== $modname) {
            throw new \moodle_exception('wrongmodule', 'local_paatshala', '',
                (object)['cmid' => $cm->id, 'modname' => $cm->modname, 'expected' => $modname]);
        }
    }

    /**
     * Gate for a function acting on a course category (course creation).
     *
     * @param int $categoryid
     * @return \context_coursecat
     */
    public static function category(int $categoryid): \context_coursecat {
        self::require_enabled();
        if (!self::category_allowed($categoryid)) {
            throw new \moodle_exception('categorynotallowed', 'local_paatshala', '', $categoryid);
        }
        $context = \context_coursecat::instance($categoryid);
        \external_api::validate_context($context);
        require_capability(self::CAPABILITY, $context);
        return $context;
    }

    /**
     * Gate for site-level read functions. Does not require the plugin to be enabled.
     *
     * @return \context_system
     */
    public static function system(): \context_system {
        $context = \context_system::instance();
        \external_api::validate_context($context);
        require_capability(self::CAPABILITY, $context);
        return $context;
    }
}

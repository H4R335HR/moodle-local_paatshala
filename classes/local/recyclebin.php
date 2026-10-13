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
 * Recycle bin helpers.
 *
 * @package    local_paatshala
 * @copyright  2026 local_paatshala contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_paatshala\local;

/**
 * Recycle bin helpers.
 *
 * @package    local_paatshala
 * @copyright  2026 local_paatshala contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class recyclebin {

    /**
     * Whether tool_recyclebin is installed.
     *
     * @return bool
     */
    public static function installed(): bool {
        return \core_plugin_manager::instance()->get_plugin_info('tool_recyclebin') !== null
            && class_exists('\tool_recyclebin\course_bin');
    }

    /**
     * Whether deleted activities go to the course recycle bin.
     *
     * @return bool
     */
    public static function course_bin_enabled(): bool {
        return self::installed() && \tool_recyclebin\course_bin::is_enabled();
    }

    /**
     * Whether deleted courses go to the category recycle bin.
     *
     * @return bool
     */
    public static function category_bin_enabled(): bool {
        return self::installed() && \tool_recyclebin\category_bin::is_enabled();
    }
}

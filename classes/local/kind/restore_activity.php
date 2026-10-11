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
 * An activity restored from the course recycle bin by the plugin.
 *
 * @package    local_paatshala
 * @copyright  2026 local_paatshala contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_paatshala\local\kind;

/**
 * An activity restored from the course recycle bin with tool_recyclebin's own restore.
 *
 * The restored activity gets a new course module id. Undo deletes it again, under the
 * same guards as any deletion of what the plugin created.
 *
 * @package    local_paatshala
 * @copyright  2026 local_paatshala contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class restore_activity extends created_module {

    /**
     * Kind name.
     *
     * @return string
     */
    public static function kind(): string {
        return 'recyclebin_restore_activity';
    }

    /**
     * Restoring always starts from a recycle bin item (write() restores it).
     *
     * @return array
     */
    protected function create(): array {
        throw new \coding_exception('A recycle bin restore needs the item id');
    }
}

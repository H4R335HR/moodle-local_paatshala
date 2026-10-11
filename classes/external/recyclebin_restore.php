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
 * local_paatshala_recyclebin_restore.
 *
 * @package    local_paatshala
 * @copyright  2026 local_paatshala contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_paatshala\external;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/externallib.php');

use external_api;
use external_function_parameters;
use external_single_structure;
use external_value;
use local_paatshala\gate;
use local_paatshala\local\executor;
use local_paatshala\local\kind\restore_activity;
use local_paatshala\local\kind\restore_course;

/**
 * local_paatshala_recyclebin_restore: restore a recycle bin item with tool_recyclebin.
 *
 * @package    local_paatshala
 * @copyright  2026 local_paatshala contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class recyclebin_restore extends external_api {

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'itemid' => new external_value(PARAM_INT, 'Recycle bin item id (from local_paatshala_recyclebin_list)'),
            'bin' => new external_value(PARAM_ALPHA, 'course (an activity) or category (a course)', VALUE_DEFAULT,
                'course'),
        ]);
    }

    /**
     * Restore the item; the new cmid or course id is in after.
     *
     * @param int $itemid
     * @param string $bin
     * @return array
     */
    public static function execute($itemid, $bin = 'course'): array {
        global $DB;
        $params = self::validate_parameters(self::execute_parameters(), ['itemid' => $itemid, 'bin' => $bin]);
        gate::require_enabled();
        if (!\local_paatshala\local\recyclebin::installed()) {
            throw new \moodle_exception('recyclebindisabled', 'local_paatshala', '', 'tool_recyclebin');
        }
        if ($params['bin'] === 'course') {
            $item = $DB->get_record('tool_recyclebin_course', ['id' => $params['itemid']]);
            if (!$item) {
                throw new \moodle_exception('recyclebinnoitem', 'local_paatshala');
            }
            $handler = new restore_activity((int)$item->courseid, null, (int)$item->id);
        } else if ($params['bin'] === 'category') {
            $item = $DB->get_record('tool_recyclebin_category', ['id' => $params['itemid']]);
            if (!$item) {
                throw new \moodle_exception('recyclebinnoitem', 'local_paatshala');
            }
            $handler = new restore_course((int)$item->categoryid, null, (int)$item->id);
        } else {
            throw new \invalid_parameter_exception('bin must be course or category');
        }
        return executor::change($handler, ['exists' => 1], null);
    }

    /**
     * Return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return executor::returns();
    }
}

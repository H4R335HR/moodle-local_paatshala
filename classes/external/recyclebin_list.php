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
 * local_paatshala_recyclebin_list.
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
use external_multiple_structure;
use external_single_structure;
use external_value;
use local_paatshala\gate;

/**
 * local_paatshala_recyclebin_list: items in a course or category recycle bin.
 *
 * @package    local_paatshala
 * @copyright  2026 local_paatshala contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class recyclebin_list extends external_api {

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course whose bin to list (or give categoryid)', VALUE_DEFAULT, 0),
            'categoryid' => new external_value(PARAM_INT, 'Category whose bin to list (or give courseid)', VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * List the items, as admin/tool/recyclebin/index.php.
     *
     * @param int $courseid
     * @param int $categoryid
     * @return array
     */
    public static function execute($courseid = 0, $categoryid = 0): array {
        global $DB;
        $params = self::validate_parameters(self::execute_parameters(),
            ['courseid' => $courseid, 'categoryid' => $categoryid]);
        if (($params['courseid'] > 0) === ($params['categoryid'] > 0)) {
            throw new \invalid_parameter_exception('Give exactly one of courseid and categoryid');
        }
        if (!\local_paatshala\local\recyclebin::installed()) {
            throw new \moodle_exception('recyclebindisabled', 'local_paatshala', '', 'tool_recyclebin');
        }
        $items = [];
        if ($params['courseid']) {
            $context = gate::course($params['courseid']);
            require_capability('tool/recyclebin:viewitems', $context);
            $expiry = (int)get_config('tool_recyclebin', 'coursebinexpiry');
            $modules = $DB->get_records_menu('modules', null, '', 'id, name');
            foreach ((new \tool_recyclebin\course_bin($params['courseid']))->get_items() as $item) {
                $items[] = [
                    'id' => (int)$item->id,
                    'bin' => 'course',
                    'name' => $item->name,
                    'modname' => $modules[$item->module] ?? '',
                    'timecreated' => (int)$item->timecreated,
                    'expires' => $expiry ? (int)$item->timecreated + $expiry : 0,
                ];
            }
            $enabled = \tool_recyclebin\course_bin::is_enabled();
        } else {
            $context = gate::category($params['categoryid']);
            require_capability('tool/recyclebin:viewitems', $context);
            $expiry = (int)get_config('tool_recyclebin', 'categorybinexpiry');
            foreach ((new \tool_recyclebin\category_bin($params['categoryid']))->get_items() as $item) {
                $items[] = [
                    'id' => (int)$item->id,
                    'bin' => 'category',
                    'name' => $item->fullname,
                    'shortname' => $item->shortname,
                    'timecreated' => (int)$item->timecreated,
                    'expires' => $expiry ? (int)$item->timecreated + $expiry : 0,
                ];
            }
            $enabled = \tool_recyclebin\category_bin::is_enabled();
        }
        return ['enabled' => (bool)$enabled, 'items' => $items];
    }

    /**
     * Return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'enabled' => new external_value(PARAM_BOOL, 'Whether this bin is on'),
            'items' => new external_multiple_structure(new external_single_structure([
                'id' => new external_value(PARAM_INT, 'Item id'),
                'bin' => new external_value(PARAM_ALPHA, 'course or category'),
                'name' => new external_value(PARAM_TEXT, 'Activity name or course full name'),
                'modname' => new external_value(PARAM_PLUGIN, 'Module type (course bin)', VALUE_OPTIONAL),
                'shortname' => new external_value(PARAM_TEXT, 'Course short name (category bin)', VALUE_OPTIONAL),
                'timecreated' => new external_value(PARAM_INT, 'When it was deleted'),
                'expires' => new external_value(PARAM_INT, 'When the bin will purge it (0 never)'),
            ])),
        ]);
    }
}

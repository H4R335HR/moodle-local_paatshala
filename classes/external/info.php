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
 * local_paatshala_info.
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
 * local_paatshala_info: plugin version, settings, functions and recycle bin settings.
 *
 * Read-only. Works while the plugin is disabled so the caller can see that it is.
 *
 * @package    local_paatshala
 * @copyright  2026 local_paatshala contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class info extends external_api {

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([]);
    }

    /**
     * Report the plugin's state.
     *
     * @return array
     */
    public static function execute(): array {
        global $CFG;
        self::validate_parameters(self::execute_parameters(), []);
        gate::system();

        $plugin = \core_plugin_manager::instance()->get_plugin_info('local_paatshala');
        $functions = [];
        include($CFG->dirroot . '/local/paatshala/db/services.php');
        $names = array_keys($functions);
        sort($names);

        $binenabled = \core_plugin_manager::instance()->get_plugin_info('tool_recyclebin') !== null;
        $bin = get_config('tool_recyclebin');
        list($availability, $conditions) = \local_paatshala\local\availability_report::status();
        return [
            'version' => (int)$plugin->versiondisk,
            'release' => (string)$plugin->release,
            'moodleversion' => (string)$CFG->version,
            'moodlerelease' => (string)$CFG->release,
            'enabled' => gate::is_enabled(),
            'allowedcourses' => trim((string)get_config('local_paatshala', 'allowedcourses')),
            'allowedcategories' => trim((string)get_config('local_paatshala', 'allowedcategories')),
            'functions' => $names,
            'enableavailability' => $availability,
            'availabilitygroup' => in_array('group', $conditions, true),
            'availabilitydate' => in_array('date', $conditions, true),
            'availabilityconditions' => $conditions,
            'recyclebin' => [
                'installed' => $binenabled,
                'coursebinenable' => $binenabled && !empty($bin->coursebinenable),
                'coursebinexpiry' => (int)($bin->coursebinexpiry ?? 0),
                'categorybinenable' => $binenabled && !empty($bin->categorybinenable),
                'categorybinexpiry' => (int)($bin->categorybinexpiry ?? 0),
            ],
        ];
    }

    /**
     * Return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'version' => new external_value(PARAM_INT, 'Plugin version (version.php)'),
            'release' => new external_value(PARAM_TEXT, 'Plugin release'),
            'moodleversion' => new external_value(PARAM_RAW, 'Moodle $CFG->version'),
            'moodlerelease' => new external_value(PARAM_TEXT, 'Moodle $CFG->release'),
            'enabled' => new external_value(PARAM_BOOL, 'Setting local_paatshala/enabled'),
            'allowedcourses' => new external_value(PARAM_RAW, 'Setting local_paatshala/allowedcourses: ids, * or empty'),
            'allowedcategories' => new external_value(PARAM_RAW,
                'Setting local_paatshala/allowedcategories: ids, * or empty'),
            'functions' => new external_multiple_structure(
                new external_value(PARAM_ALPHANUMEXT, 'Web service function name'), 'Functions the plugin defines'),
            'enableavailability' => new external_value(PARAM_BOOL, 'Site setting enableavailability (restrict access)'),
            'availabilitygroup' => new external_value(PARAM_BOOL, 'The availability_group condition plugin is enabled'),
            'availabilitydate' => new external_value(PARAM_BOOL, 'The availability_date condition plugin is enabled'),
            'availabilityconditions' => new external_multiple_structure(
                new external_value(PARAM_PLUGIN, 'Condition type (availability_<type>)'),
                'Enabled condition plugins, sorted'),
            'recyclebin' => new external_single_structure([
                'installed' => new external_value(PARAM_BOOL, 'tool_recyclebin is installed'),
                'coursebinenable' => new external_value(PARAM_BOOL, 'tool_recyclebin/coursebinenable'),
                'coursebinexpiry' => new external_value(PARAM_INT, 'tool_recyclebin/coursebinexpiry, seconds (0 = never)'),
                'categorybinenable' => new external_value(PARAM_BOOL, 'tool_recyclebin/categorybinenable'),
                'categorybinexpiry' => new external_value(PARAM_INT,
                    'tool_recyclebin/categorybinexpiry, seconds (0 = never)'),
            ]),
        ]);
    }
}

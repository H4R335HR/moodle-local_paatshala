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
 * Admin settings for local_paatshala.
 *
 * @package    local_paatshala
 * @copyright  2026 local_paatshala contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_paatshala', new lang_string('pluginname', 'local_paatshala'));
    $ADMIN->add('localplugins', $settings);

    $settings->add(new admin_setting_configcheckbox(
        'local_paatshala/enabled',
        new lang_string('enabled', 'local_paatshala'),
        new lang_string('enabled_desc', 'local_paatshala'),
        0
    ));

    // Empty, "*", or comma-separated ids. The regex is the setting's validation.
    $idlist = '/^\s*(\*|\d+(\s*,\s*\d+)*)?\s*$/';
    $settings->add(new admin_setting_configtext(
        'local_paatshala/allowedcourses',
        new lang_string('allowedcourses', 'local_paatshala'),
        new lang_string('allowedcourses_desc', 'local_paatshala'),
        '',
        $idlist
    ));
    $settings->add(new admin_setting_configtext(
        'local_paatshala/allowedcategories',
        new lang_string('allowedcategories', 'local_paatshala'),
        new lang_string('allowedcategories_desc', 'local_paatshala'),
        '',
        $idlist
    ));
}

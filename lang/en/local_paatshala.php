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
 * English strings for local_paatshala.
 *
 * @package    local_paatshala
 * @copyright  2026 local_paatshala contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['allowedcategories'] = 'Allowed categories';
$string['allowedcategories_desc'] = 'Course category ids where courses may be created, comma-separated, or * for every category. Empty means none.';
$string['allowedcourses'] = 'Allowed courses';
$string['allowedcourses_desc'] = 'Course ids the plugin may read and change, comma-separated, or * for every course. Empty means none. The site front page is never allowed.';
$string['categorynotallowed'] = 'Category {$a} is not in local_paatshala/allowedcategories.';
$string['coursenotallowed'] = 'Course {$a} is not in local_paatshala/allowedcourses.';
$string['disabled'] = 'The local_paatshala plugin is disabled (Site administration > Plugins > Local plugins > Paatshala web services).';
$string['enabled'] = 'Enabled';
$string['enabled_desc'] = 'Allow the plugin\'s web service functions to run. Off by default.';
$string['journalnotfound'] = 'Journal entry {$a} does not exist.';
$string['paatshala:use'] = 'Use the Paatshala web service functions';
$string['pluginname'] = 'Paatshala web services';
$string['privacy:metadata:journal'] = 'Journal of changes made through the plugin\'s web service functions, kept so they can be undone.';
$string['privacy:metadata:journal:afterstate'] = 'The values written (ids, dates, settings, positions and names).';
$string['privacy:metadata:journal:beforestate'] = 'The values replaced (ids, dates, settings, positions and names).';
$string['privacy:metadata:journal:courseid'] = 'The course changed.';
$string['privacy:metadata:journal:kind'] = 'The kind of change.';
$string['privacy:metadata:journal:relateduserid'] = 'The student the change is about, for per-student changes such as overrides.';
$string['privacy:metadata:journal:target'] = 'What was changed: ids and names, including student ids for per-student changes.';
$string['privacy:metadata:journal:timecreated'] = 'When the change was made.';
$string['privacy:metadata:journal:userid'] = 'The user who called the web service function.';

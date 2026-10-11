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
$string['alreadyundone'] = 'Journal entry {$a->id} was already undone by entry {$a->undoneby}.';
$string['backupsettinglocked'] = 'The backup setting {$a} is locked to a different value for this user, so the course cannot be copied without user data.';
$string['cannotdeletesection'] = 'The section cannot be deleted: the course format does not allow it, it is not empty, or the capabilities moodle/course:movesections and moodle/course:update are missing.';
$string['cannotmovesection'] = 'Moodle refused to move the section.';
$string['categorynotallowed'] = 'Category {$a} is not in local_paatshala/allowedcategories.';
$string['conflict'] = 'The current state differs from the expected state. Current state: {$a}';
$string['copyfailed'] = 'The copy was restored but its new course module could not be found.';
$string['coursenotallowed'] = 'Course {$a} is not in local_paatshala/allowedcourses.';
$string['disabled'] = 'The local_paatshala plugin is disabled (Site administration > Plugins > Local plugins > Paatshala web services).';
$string['enabled'] = 'Enabled';
$string['enabled_desc'] = 'Allow the plugin\'s web service functions to run. Off by default.';
$string['formatnosections'] = 'Course format {$a} does not support adding sections this way.';
$string['invalidsettings'] = 'The settings form would refuse this: {$a}';
$string['journalnotfound'] = 'Journal entry {$a} does not exist.';
$string['notcreatedbyplugin'] = 'The journal has no entry showing this plugin created it (or that entry was already undone). Only what the plugin created can be deleted.';
$string['notundoable'] = 'Journal entry {$a} cannot be undone.';
$string['overridenotallowed'] = 'The override page would not offer {$a}: not enrolled in the course, not in a group the caller can access, or excluded by the activity\'s restrictions.';
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
$string['recyclebindisabled'] = 'The {$a} recycle bin is off (tool_recyclebin). Deletion is refused so it can be undone; turn the recycle bin on first.';
$string['recyclebinnoitem'] = 'The recycle bin item does not exist (it may have expired).';
$string['rolldateslocked'] = 'The restore start date is locked (moodle/restore:rolldates is needed in the new course), so the dates could not be moved.';
$string['studentdata'] = 'It holds student data, so it is not deleted: {$a}';
$string['warning_autoswitch'] = 'The workshop is set to switch to the assessment phase after the submissions deadline, which has passed: the next cron run or a visit to the workshop page will switch it to the assessment phase again.';
$string['warning_creatorenrolled'] = 'As on the course settings page, the creator was enrolled in the new course with the course creator\'s default role.';
$string['warning_gradespushed'] = 'Closing the workshop pushed its grades to the gradebook. Switching the phase back does not remove them.';
$string['warning_movehidden'] = 'The activity was moved into a hidden section and is now hidden.';
$string['warning_moveshown'] = 'The hidden activity was moved into a visible section and Moodle restored its earlier visibility: it is now visible.';
$string['warning_newcoursenotallowed'] = 'Course {$a} is not in local_paatshala/allowedcourses; add it there to change it with this plugin.';
$string['warning_restorerrole'] = 'Moodle\'s restore gave the calling user the role set in "restorernewroleid" in the target course, because it lacked moodle/course:update there (as Import does). Undo does not remove that role; give the web service role moodle/course:update to avoid it.';
$string['wrongmodule'] = 'Course module {$a->cmid} is a {$a->modname}, not a {$a->expected}.';

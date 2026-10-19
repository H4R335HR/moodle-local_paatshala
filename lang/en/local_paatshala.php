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

$string['advancedgrading'] = 'This assignment grades with an advanced grading method (rubric or marking guide), which needs its own form data; grade it in Moodle.';
$string['allowedcategories'] = 'Allowed categories';
$string['allowedcategories_desc'] = 'Course category ids where courses may be created, comma-separated, or * for every category. Empty means none.';
$string['allowedcourses'] = 'Allowed courses';
$string['allowedcourses_desc'] = 'Course ids the plugin may read and change, comma-separated, or * for every course. Empty means none. The site front page is never allowed.';
$string['alreadyundone'] = 'Journal entry {$a->id} was already undone by entry {$a->undoneby}.';
$string['availabilitydisabled'] = 'Restrict access cannot be used: {$a} is off on this site (Site administration > Advanced features for enableavailability; Site administration > Plugins > Availability restrictions > Manage restrictions for availability_group and availability_date).';
$string['backupsettinglocked'] = 'The backup setting {$a} is locked to a different value for this user, so the course cannot be copied without user data.';
$string['cannotdeletesection'] = 'The section cannot be deleted: the course format does not allow it, it is not empty, or the capabilities moodle/course:movesections and moodle/course:update are missing.';
$string['cannotmovesection'] = 'Moodle refused to move the section.';
$string['categorynotallowed'] = 'Category {$a} is not in local_paatshala/allowedcategories.';
$string['commentsoff'] = 'Feedback comments are off on this assignment, so there is no comment to set.';
$string['conflict'] = 'The current state differs from the expected state. Current state: {$a}';
$string['contentmissingfile'] = 'the text refers to {$a}, which is not in the activity\'s files; upload it in Paatshala first';
$string['copyfailed'] = 'The copy was restored but its new course module could not be found.';
$string['coursenotallowed'] = 'Course {$a} is not in local_paatshala/allowedcourses.';
$string['disabled'] = 'The local_paatshala plugin is disabled (Site administration > Plugins > Local plugins > Paatshala web services).';
$string['dropsreference'] = 'The new text no longer refers to {$a}, which the current text uses. The files are kept in the activity\'s files either way; give dropreferences = 1 to drop the reference.';
$string['enabled'] = 'Enabled';
$string['enabled_desc'] = 'Allow the plugin\'s web service functions to run. Off by default.';
$string['excluded_dates'] = 'dates are changed with local_paatshala_assign_set_dates';
$string['excluded_description'] = 'the description is changed with local_paatshala_update_content (field intro); its attachments are not editable through the plugin';
$string['excluded_grade'] = 'the maximum grade is not editable for now: changing it can rescale existing grades';
$string['excluded_team'] = 'group submission settings are not editable for now: they change how existing submissions are grouped';
$string['excluded_workflow'] = 'marking workflow settings are not editable for now: they change the state of existing grades';
$string['fieldexcluded'] = 'Field {$a->field} is refused: {$a->reason}.';
$string['filesaltered'] = 'Moodle changed the activity\'s files ({$a}) while saving the text, so nothing was changed. A text edit must never change files; please report this.';
$string['formatnosections'] = 'Course format {$a} does not support adding sections this way.';
$string['gradeabovemax'] = 'grade {$a->grade} is above the maximum grade {$a->max}';
$string['gradeinvalid'] = '{$a->grade} is not a grade for this assignment (0 to the maximum, a scale item, or -1 for no grade)';
$string['gradelocked'] = 'The grade of user {$a} is locked or overridden in the gradebook; Moodle would save the feedback only.';
$string['invalidavailability'] = 'The access restrictions cannot be changed here: {$a}';
$string['invalidgroup'] = 'Group {$a} does not belong to this course.';
$string['invalidsettings'] = 'The settings or grading form would refuse this: {$a}';
$string['journalnotfound'] = 'Journal entry {$a} does not exist.';
$string['notcreatedbyplugin'] = 'The journal has no entry showing this plugin created it (or that entry was already undone). Only what the plugin created can be deleted.';
$string['notgradable'] = 'User {$a} is not enrolled in this course with the capability to submit to the assignment.';
$string['notsaved'] = 'Moodle did not save {$a->field}. {$a->reason} Nothing was changed.';
$string['notsaved_commentformat'] = 'Moodle saves a feedback comment only when its text changes, so a new format alone is not stored; change the text in the same call.';
$string['notsaved_content'] = 'Moodle stored a text of {$a->storedlength} bytes in format {$a->storedformat}, not the {$a->askedlength} bytes in format {$a->askedformat} asked for.';
$string['notsaved_different'] = 'Asked for {$a->asked}, Moodle stored {$a->stored}.';
$string['notsaved_notconfigurable'] = '{$a->plugin} cannot be turned on or off in an assignment\'s settings on this site (for Annotate PDF: Ghostscript is not set up).';
$string['notsaved_offline'] = '{$a->plugin} stays off while an advanced grading method (rubric or marking guide) is active on this assignment.';
$string['notsaved_otherfield'] = 'Saving it also changed the activity\'s {$a} text.';
$string['notsaved_pluginhidden'] = '{$a->plugin} is disabled for the whole site (Site administration > Plugins > Activity modules > Assignment), so an assignment cannot turn it on or off.';
$string['notsaved_pluginoff'] = '{$a->plugin} is off on this assignment, and Moodle saves its settings only while it is on; include {$a->enabled}: 1 in the same call.';
$string['notsaved_wordlimitoff'] = 'The word limit is off, and Moodle stores a limit only with it on; include {$a}: 1 in the same call.';
$string['notsaved_wordlimitzero'] = 'Moodle turns the word limit off when the limit is 0; include {$a} above 0 in the same call.';
$string['notundoable'] = 'Journal entry {$a} cannot be undone.';
$string['overridenotallowed'] = 'The override page would not offer {$a}: not enrolled in the course, not in a group the caller can access, or excluded by the activity\'s restrictions.';
$string['paatshala:use'] = 'Use the Paatshala web service functions';
$string['pluginname'] = 'Paatshala web services';
$string['privacy:metadata:journal'] = 'Journal of changes made through the plugin\'s web service functions, kept so they can be undone.';
$string['privacy:metadata:journal:afterstate'] = 'The values written (ids, dates, settings, positions, names and activity texts).';
$string['privacy:metadata:journal:beforestate'] = 'The values replaced (ids, dates, settings, positions, names and activity texts).';
$string['privacy:metadata:journal:courseid'] = 'The course changed.';
$string['privacy:metadata:journal:kind'] = 'The kind of change.';
$string['privacy:metadata:journal:relateduserid'] = 'The student the change is about, for per-student changes such as overrides.';
$string['privacy:metadata:journal:target'] = 'What was changed: ids and names, including student ids for per-student changes.';
$string['privacy:metadata:journal:timecreated'] = 'When the change was made.';
$string['privacy:metadata:journal:userid'] = 'The user who called the web service function.';
$string['privacy:metadata:journaluser'] = 'The students a journal entry holds data about (for a team grade, every member).';
$string['privacy:metadata:journaluser:journalid'] = 'The journal entry.';
$string['privacy:metadata:journaluser:userid'] = 'The student.';
$string['recyclebindisabled'] = 'The {$a} recycle bin is off (tool_recyclebin). Deletion is refused so it can be undone; turn the recycle bin on first.';
$string['recyclebinnoitem'] = 'The recycle bin item does not exist (it may have expired).';
$string['rolldateslocked'] = 'The restore start date is locked (moodle/restore:rolldates is needed in the new course), so the dates could not be moved.';
$string['storedinvalid'] = 'the stored restrictions are not valid ({$a}); fix them in Moodle first';
$string['studentdata'] = 'It holds student data, so it is not deleted: {$a}';
$string['teamdiffers'] = 'The team members have different current {$a}; a team grade gives every member the same, so give it in the call.';
$string['warning_autoswitch'] = 'The workshop is set to switch to the assessment phase after the submissions deadline, which has passed: the next cron run or a visit to the workshop page will switch it to the assessment phase again.';
$string['warning_creatorenrolled'] = 'As on the course settings page, the creator was enrolled in the new course with the course creator\'s default role.';
$string['warning_droppedreference'] = 'The text no longer refers to {$a}; the files are kept in the activity\'s files, and undo makes the references work again.';
$string['warning_emptygroup'] = 'Group {$a} has no members yet: nobody but staff can see the item until members are added.';
$string['warning_gradespushed'] = 'Closing the workshop pushed its grades to the gradebook. Switching the phase back does not remove them.';
$string['warning_movehidden'] = 'The activity was moved into a hidden section and is now hidden.';
$string['warning_moveshown'] = 'The hidden activity was moved into a visible section and Moodle restored its earlier visibility: it is now visible.';
$string['warning_namechanged'] = 'Moodle names a label after its text, as on the settings page: "{$a->old}" is now "{$a->new}".';
$string['warning_newcoursenotallowed'] = 'Course {$a} is not in local_paatshala/allowedcourses; add it there to change it with this plugin.';
$string['warning_nochange'] = 'Nothing changed: the values already held, so nothing was written or journaled.';
$string['warning_pendingcancelled'] = 'A feedback notice from an earlier grade was still waiting for cron for {$a} student(s); it was cancelled (mailed = 1). Undo does not bring it back.';
$string['warning_pendingnotification'] = 'A feedback notice from an earlier grade is still waiting for cron for {$a} student(s): cron will send it, now showing this grade. Give cancelpending = 1 with notify = 0 to cancel it.';
$string['warning_restorerrole'] = 'Moodle\'s restore gave the calling user the role set in "restorernewroleid" in the target course, because it lacked moodle/course:update there (as Import does). Undo does not remove that role; give the web service role moodle/course:update to avoid it.';
$string['warning_untilpassed'] = '"until" ({$a}) has already passed: everyone the restriction applies to loses access at once.';
$string['workflowstatenotallowed'] = 'the grading form does not offer workflow state "{$a}" to this user';
$string['wrongmodule'] = 'Course module {$a->cmid} is a {$a->modname}, not a {$a->expected}.';

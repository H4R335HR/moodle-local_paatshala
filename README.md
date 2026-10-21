# local_paatshala

A Moodle 3.8 local plugin that adds the web service functions the
[paatshala-mcp](https://github.com/H4R335HR/paatshala-mcp) server needs and Moodle 3.8
lacks. Every write has a matching read, returns the state it replaced, can refuse on a
stale snapshot, is journaled, and can be undone.

Release 0.10.0 (2026102100). All phases of the brief (dates, workshop phase, reads,
journal and undo; activity and section order; new sections; assignment overrides; copying
and creating activities and courses, deleting what the plugin created, the recycle bin;
assignment settings, workshop and quiz dates), grading an assignment with or without
notifying the student (0.7.0), group and date access restrictions on sections and
activities (phase 6, 0.8.0), editing activity texts (descriptions, page content,
workshop instructions) without touching their files (0.9.0), and activity completion
settings with completion and grade access restrictions (0.10.0).

## Requirements

- Moodle 3.8, built and checked against 3.8.2+ (Build: 20200508). `requires` is
  2019111800 (3.8.0).
- PHP 7.1 to 7.4; PostgreSQL or MySQL/MariaDB.

## Install

1. Upload the zip through Site administration > Plugins > Install plugins, or clone this
   repository into `local/paatshala` in the Moodle directory:
   `git clone https://github.com/H4R335HR/moodle-local_paatshala.git local/paatshala`
2. Visit Site administration > Notifications (or run `php admin/cli/upgrade.php`).
3. Set the three settings below, add the functions to `ws_automation` and grant the
   capabilities (see "Web service setup").

## Settings

Site administration > Plugins > Local plugins > Paatshala web services:

| Setting | Default | Meaning |
| --- | --- | --- |
| `local_paatshala/enabled` | off | While off, every function except `local_paatshala_info` refuses with `disabled`. |
| `local_paatshala/allowedcourses` | empty | Course ids the plugin may act on, comma-separated, or `*` for all. Empty means none. The front page is never allowed. |
| `local_paatshala/allowedcategories` | empty | Category ids where courses may be created, comma-separated, or `*`. Empty means none. |

### Recommended recycle bin settings

Deleting what the plugin created (phase 4) goes through the recycle bin, which is then
the real undo window. Under Site administration > Plugins > Admin tools > Recycle bin:
turn on `coursebinenable` and `categorybinenable` and set `coursebinexpiry` and
`categorybinexpiry` long enough (for example 8 weeks). `local_paatshala_info` reports
these settings.

## Web service setup

Add the functions to the existing `ws_automation` external service (Site administration >
Server > Web services > External services > ws_automation > Functions), and give the
`ws_automation` role the capabilities listed per function. The capabilities come from the
code path each function wraps, not from core's `db/services.php`.

Every function except `local_paatshala_info` also needs what `validate_context()` needs
to enter the course or activity: the role must be able to see the course
(`moodle/course:view`, or an enrolment), hidden activities
(`moodle/course:viewhiddenactivities`) and the activity itself (`mod/<type>:view`, e.g.
`mod/assign:view`, `mod/workshop:view`). Once an activity or its section has access
restrictions, entering it also needs `moodle/course:ignoreavailabilityrestrictions`
(`require_login()` refuses an activity the user cannot see, lib/moodlelib.php:3026), so
give the role that too.

| Function | Capabilities (context) |
| --- | --- |
| `local_paatshala_info` | `local/paatshala:use` (system) |
| `local_paatshala_get_module_settings` | `local/paatshala:use` (course), `moodle/course:manageactivities` (activity) |
| `local_paatshala_assign_set_dates` | `local/paatshala:use` (course), `moodle/course:manageactivities` (activity) |
| `local_paatshala_workshop_switch_phase` | `local/paatshala:use` (course), `mod/workshop:switchphase` (activity) |
| `local_paatshala_move_module` | `local/paatshala:use` (course), `moodle/course:manageactivities` (activity); `moodle/course:activityvisibility` (activity) when an undo has to restore visibility |
| `local_paatshala_move_section` | `local/paatshala:use` (course), `moodle/course:movesections` (course) |
| `local_paatshala_create_section` | `local/paatshala:use` (course), `moodle/course:update` (course); `moodle/course:movesections` (course) to insert before existing sections and to undo (delete) |
| `local_paatshala_assign_get_overrides` | `local/paatshala:use` (course), `mod/assign:manageoverrides` (activity) |
| `local_paatshala_assign_save_override` | `local/paatshala:use` (course), `mod/assign:manageoverrides` (activity); `moodle/site:accessallgroups` to override users and groups outside the caller's own groups in a group-mode assignment |
| `local_paatshala_assign_delete_override` | `local/paatshala:use` (course), `mod/assign:manageoverrides` (activity) |
| `local_paatshala_get_availability` | `local/paatshala:use` (course); `moodle/course:update` (course) for a section, `moodle/course:manageactivities` (activity) for an activity |
| `local_paatshala_get_course_availability` | `local/paatshala:use`, `moodle/course:update` (course); activities are listed where the role has `moodle/course:manageactivities` |
| `local_paatshala_set_access` | `local/paatshala:use` (course); `moodle/course:update` (course) for a section (course/editsection.php:42), `moodle/course:manageactivities` (activity) for an activity (course/modlib.php:475); `moodle/course:ignoreavailabilityrestrictions` (see Group and date access restrictions) |
| `local_paatshala_assign_save_grade` | `local/paatshala:use` (course), `mod/assign:grade` (activity); `mod/assign:releasegrades` (or `managegrades`) to set the released workflow state, `mod/assign:reviewgrades` for the review states, as the grading form |
| `local_paatshala_copy_activity` | `local/paatshala:use` (source and target course); `moodle/course:manageactivities` (source activity and target course); `moodle/backup:backuptargetimport` (source course); `moodle/restore:restoretargetimport` (target course). Recommended: `moodle/course:update` (target course), see below |
| `local_paatshala_create_activity` | `local/paatshala:use` (course), `moodle/course:manageactivities` (course), `mod/<type>:addinstance` (course); `moodle/grade:managegradingforms` for assignments' grading method defaults |
| `local_paatshala_create_course` | `local/paatshala:use` (category), `moodle/course:create` (category). Recommended: `moodle/role:assign` with course view, see below |
| `local_paatshala_duplicate_course` | `local/paatshala:use` (category and source course), `moodle/course:create`, `moodle/restore:restorecourse`, `moodle/restore:rolldates`, `moodle/restore:configure` (category); `moodle/backup:backupcourse`, `moodle/backup:configure` (source course) |
| `local_paatshala_delete_created` | `local/paatshala:use` (course or category); an activity: `moodle/course:manageactivities` (activity); a course: `moodle/course:delete` (course) |
| `local_paatshala_recyclebin_list` | `local/paatshala:use` (course or category), `tool/recyclebin:viewitems` (course or category) |
| `local_paatshala_recyclebin_restore` | `local/paatshala:use` (course or category), `tool/recyclebin:restoreitems` (course or category) |
| `local_paatshala_update_assign_settings` | `local/paatshala:use` (course), `moodle/course:manageactivities` (activity) |
| `local_paatshala_workshop_set_dates` | `local/paatshala:use` (course), `moodle/course:manageactivities` (activity) |
| `local_paatshala_quiz_set_dates` | `local/paatshala:use` (course), `moodle/course:manageactivities` (activity) |
| `local_paatshala_get_content` | `local/paatshala:use` (course), `moodle/course:manageactivities` (activity) |
| `local_paatshala_update_content` | `local/paatshala:use` (course), `moodle/course:manageactivities` (activity) |
| `local_paatshala_get_completion` | `local/paatshala:use` (course), `moodle/course:manageactivities` (activity) (course/modedit.php through can_update_moduleinfo(), course/modlib.php:470-475; the bulk completion page checks the same, completion/classes/manager.php:239-241) |
| `local_paatshala_get_course_completion` | `local/paatshala:use`, `moodle/course:manageactivities` (course) (course/bulkcompletion.php:61); activities are listed where the role has `moodle/course:manageactivities` (activity) |
| `local_paatshala_set_completion` | `local/paatshala:use` (course), `moodle/course:manageactivities` (activity). Site setting `enablecompletion` and the course's completion tracking must be on |
| `local_paatshala_journal_list` | `local/paatshala:use` (course) |
| `local_paatshala_journal_get` | `local/paatshala:use` (course of the entry) |
| `local_paatshala_undo` | `local/paatshala:use` (course), plus the capabilities and site settings of the function that made the entry |

`local/paatshala:use` has no default archetypes. Allow it on the `ws_automation` role. It
is checked in the course context for course and activity functions, the category context
for course creation (phase 4) and the system context for `local_paatshala_info`.

## How every write works

- **Gate, checked first:** `enabled`, then the course allowlist, then
  `validate_context()`, then `local/paatshala:use`, then the capabilities of the UI
  equivalent. Refusals are `moodle_exception`s with errorcodes `disabled`,
  `coursenotallowed`, `categorynotallowed`, `nopermissions`, `wrongmodule`.
- **Compare-and-set:** every write takes an optional `expected`, a JSON object with some
  of the function's input fields. If the current values differ, the function refuses with
  errorcode `conflict`. The current state (JSON, every field) is in the error message and
  in `debuginfo`. Moodle only sends `debuginfo` over REST when debugging is on, so read it
  from the message. Unknown keys in `expected` are refused as invalid parameters. Values
  are scalars, except `files` for `local_paatshala_update_content` (a list).
- **Result:** `{journalid, before, after, undoable, notifies_students, warnings[]}`.
  `before` and `after` are JSON strings with the fields the call set; `after` is read back
  from the database after the write. Times are unix timestamps; 0 means disabled.
- **Nothing changed:** if the fields already held the values given, the write is rolled
  back (no `timemodified`, calendar or event side effects) and nothing is journaled: the
  result has `journalid` 0, `undoable` false, `before` equal to `after` and a warning
  with code `nochange`. This includes an undo that would change nothing; its entry stays
  not undone.
- **Values Moodle did not save:** the settings functions (assignment settings, workshop
  and quiz dates, activity texts) read every value back and refuse with errorcode
  `notsaved`, naming the field and the reason, if one was not stored as given; the whole
  call is rolled back.
- **Files and texts Moodle would change on the side:** every write through the activity
  settings helper is refused with errorcode `filesaltered` when the save would remove,
  replace or add a file of the activity, or change a text it was not asked to change (see
  "filesaltered" below).
- **Validation:** the plugin refuses what the settings page would refuse (errorcode
  `invalidsettings`, with the form's messages).
- **Transactions:** each change and its journal entry are written in one delegated
  transaction; a refusal leaves nothing behind.

## Functions

### local_paatshala_info

Read-only. Returns the plugin version and release, Moodle version and release,
`enabled`, `allowedcourses`, `allowedcategories`, the list of plugin functions,
`enableavailability`, the enabled condition plugins (`availabilityconditions`, with
`availabilitygroup` and `availabilitydate` as flags), and the recycle bin settings (`tool_recyclebin` `coursebinenable`, `coursebinexpiry`,
`categorybinenable`, `categorybinexpiry`). Answers while the plugin is disabled.

### local_paatshala_get_module_settings(cmid)

Read-only. For every activity: `cmid`, `courseid`, `modname`, `name`, `visible`,
`sectionnum`, `position` (0-based within the section). For assignments also
`allowsubmissionsfromdate`, `duedate`, `cutoffdate`, `gradingduedate`, `grade`; for
workshops `phase`, `submissionstart`, `submissionend`, `assessmentstart`, `assessmentend`,
`phaseswitchassessment`; for quizzes `timeopen`, `timeclose`. Field names match the write
inputs, so a result can be passed as `expected`. Every activity also gets `atrisk`: the
files any settings-form save of it would drop, so a caller can refuse before proposing a
change that would come back `filesaltered` (see "atrisk" under "Activity settings
helper").

For assignments it also returns every setting `local_paatshala_update_assign_settings`
takes (its whitelist, under the same field names), read the same way that function reads
them, so a caller can snapshot them before a change: `requiresubmissionstatement`,
`attemptreopenmethod`, `maxattempts`, the online text, file submission, feedback comments,
feedback file, offline worksheet and annotate PDF fields. `name` is the activity name
above. A submission or feedback type's settings are returned as stored even while the
type is off (a word limit reads 0 while the limit is off). A type that is not installed
is left out. Unlike `mod_assign_get_assignments`, this works in courses the web service
user is not enrolled in; it needs the same capability as the settings page.

### local_paatshala_assign_set_dates(cmid, allowsubmissionsfromdate?, duedate?, cutoffdate?, gradingduedate?, expected?)

- **Changes:** the given dates (omit a field to keep it; 0 disables it). Saved through the
  settings page's own path (see "Activity settings helper"), so the due and
  "remind me to grade by" calendar events move, the completion-expected event, caches,
  the gradebook and the `course_module_updated` event update as on the settings page.
- **Validation:** `mod_assign_mod_form::validation()` itself: due not before "allow
  submissions from", cut-off not before due or "allow submissions from", "remind me to
  grade by" not before due or "allow submissions from".
- **Undo:** writes the previous values back the same way. Refused if any of those fields
  has changed since.
- **Limits:** dates are stored to the second (the settings page rounds to the minute).
  User and group overrides are separate (phase 3).
- **Notifies:** no. Changing dates sends no messages.

### local_paatshala_workshop_switch_phase(cmid, phase, expected?)

- **Changes:** the workshop phase (10 setup, 20 submission, 30 assessment, 40 grading
  evaluation, 50 closed) through `workshop::switch_phase()`, as
  `mod/workshop/switchphase.php` does. Fires `\mod_workshop\event\phase_switched`.
- **Undo:** switches back to the previous phase. Refused if the phase has changed since.
- **Limits:**
  - Switching to closed pushes the workshop's submission and assessment grades to the
    gradebook (`workshop_update_grades()`). Undo switches the phase back but does not take
    those grades out of the gradebook; the result carries warning `gradespushed`.
  - If the workshop is set to switch to the assessment phase after the submissions
    deadline (`phaseswitchassessment`) and that deadline has passed, switching to the
    submission phase does not stick: the workshop cron task or the next visit to the
    workshop page switches it to assessment again and then turns the automatic switch off.
    The result carries warning `autoswitch`.
- **Notifies:** no. Workshop has no message providers.

### local_paatshala_move_module(cmid, sectionnum, beforecmid=0, expected?)

- **Changes:** moves an activity into section `sectionnum`, before activity `beforecmid`
  (0: at the end), with `moveto_module()`, as the course page's drag and drop does
  (`course/rest.php`). The section must exist and `beforecmid` must be in it.
- **Before/after:** `{sectionnum, beforecmid, visible, visibleoncoursepage}`. Visibility
  is included because the move can change it: moving into a hidden section hides the
  activity (warning `hidden`), and moving a hidden activity into a visible section
  restores the visibility it had before its section was hidden (warning `shown`), as on
  the course page.
- **Undo:** moves it back before its old neighbour and restores the visibility. Refused
  with `conflict` if the activity has moved since, or the old neighbour has moved to
  another section or is gone. If it was last in its section, it goes back to the end.
- **Notifies:** no.

### local_paatshala_move_section(courseid, sectionnum, targetnum, expected?)

- **Changes:** moves the section now numbered `sectionnum` so that it gets number
  `targetnum`, with `move_section_to()` (`course/rest.php`). The sections in between
  shift by one; the highlighted section follows as on the course page. Section 0 never
  moves. `expected` may also hold `sectionid` and `name` to pin which section is meant.
- **Undo:** moves the same section (by id) back. Refused if it has moved since.
- **Limits:** in a weekly format the dates shown for sections follow their numbers.
- **Notifies:** no.

### local_paatshala_create_section(courseid, position=0, name='')

- **Changes:** adds a section with `course_create_section()`, as
  `course/changenumsections.php` does: at the end (`position` 0) or with number
  `position`, moving later sections down. Refused past the site's maximum number of
  sections (`moodlecourse/maxsections`) and for formats without sections. An optional
  name is set with `course_update_section()`. The journal entry has `createdbyplugin` = 1.
- **Undo:** deletes the section with `course_delete_section()` without forcing, so only
  while it has no activities and no summary, and still has the name it was created with;
  otherwise `conflict`, with the number of activities in the message. Redo creates a new
  section at the same number with the same name (it gets a new id).
- **Notifies:** no.

### local_paatshala_assign_get_overrides(cmid)

Read-only. The assignment's own dates and every user and group override the caller may
see (as `mod/assign/overrides.php`): `id`, `userid` or `groupid` and `groupname`,
`sortorder` (group priority, 1 highest), `allowsubmissionsfromdate`, `duedate`,
`cutoffdate`. A date is null when the override does not change it.

### local_paatshala_assign_save_override(cmid, userid=0, groupid=0, allowsubmissionsfromdate?, duedate?, cutoffdate?, sortorder?, expected?)

- **Changes:** adds or changes the override of one user or one group (give exactly one).
  Moodle 3.8 has no API for this: the plugin mirrors `mod/assign/overrideedit.php` line
  for line and fires the same `user_override_created/updated` and
  `group_override_created/updated` events. Calendar events are refreshed with
  `assign_update_events()`.
- **Dates:** omitted dates keep the override's value. As on the override page, a date
  equal to the assignment's own is stored as "not overridden" (null); pass the
  assignment's date to stop overriding it. 0 disables a date.
- **Who:** only users and groups the override page would offer: enrolled users (and
  groups) of the course, limited to the caller's groups in a group-mode assignment
  without `moodle/site:accessallgroups`, filtered by the assignment's access restrictions
  (errorcode `overridenotallowed`).
- **Validation:** `assign_override_form::validation()` itself: due not before "allow
  submissions from", cut-off not before due or "allow submissions from", not later than
  an extension, and at least one date different from the assignment's.
- **Group priority:** a new group override goes last, as on the page; `sortorder` moves it
  with `move_group_override()` steps (the page's up and down arrows), then
  `reorder_group_overrides()` renumbers and updates calendar event priorities.
- **Undo:** restores the previous dates and priority, or deletes a new override. Refused
  if the override has changed since, or its old priority no longer exists.
- **Notifies:** no.

### local_paatshala_assign_delete_override(overrideid, expected?)

- **Changes:** deletes the override with `assign::delete_override()` and renumbers group
  priorities with `reorder_group_overrides()`, as `mod/assign/overridedelete.php`.
- **Undo:** recreates it with the same dates and, for a group, the same priority (it gets
  a new id). Refused if an override for that user or group exists again, or the user or
  group is no longer one the page would offer.
- **Notifies:** no.

### local_paatshala_assign_save_grade(cmid, userid, grade, feedback={}, workflowstate?, applytoall=0, notify=1, cancelpending=0, expected?)

- **Changes:** the grade of the student's latest attempt, and optionally the feedback
  comment and marking workflow state, through `assign::save_grade()`: the call behind the
  grading form and `mod_assign_save_grade`. `grade` is -1 for "no grade"; points from 0 to
  the maximum, or a scale item number (1 = the first item). Above the maximum is refused
  with `invalidsettings` (core would silently ignore it).
- **Notifications:** `notify` = 1 behaves as `mod_assign_save_grade`, which in 3.8 always
  flags the "feedback available" notice (user flag `mailed` = 0; `assign::cron()` sends it
  if the grade item is not hidden, the grade changed in the last 24 hours and, with
  marking workflow, the state is released). `notify` = 0 is the grading form's "Notify
  students" switch off: `mailed` is left as it was. With marking workflow on and a state
  other than released Moodle never notifies, whatever `notify` says. `notifies_students`
  says which happened.
- **A notice already waiting** (`mailed` = 0 from an earlier grade, cron not run yet, or a
  notice cron held back): a quiet write leaves it, and cron sends it with the new grade
  (the write also renews the grade's time, so even a notice older than cron's 24 hours
  goes out). The result warns with code `pendingnotification`. `cancelpending` = 1 (with
  `notify` = 0) cancels it (`mailed` = 1, warning `pendingcancelled`); undo does not bring
  it back.
- **Feedback:** `feedback` = `{text, format}` (format 0 Moodle, 1 HTML, 2 plain, 4
  Markdown; default 1). The text is stored as given: it goes in as the grading form's
  editor without a draft area, so nothing is reformatted and files embedded in the
  comment stay. Without `text` the current comment is kept (core would clear it). Moodle
  saves a comment only when its text changes, so a new format with the same text is
  refused with `notsaved`. Refused with `commentsoff` when feedback comments are off.
  Feedback files, the offline worksheet and annotated PDFs are left alone.
- **Workflow state:** only with marking workflow; `''` for not marked or a state the
  grading form offers the caller. Without one, the current state is sent, as the form
  does.
- **Teams:** `applytoall` = 1 (team submissions only) gives every team member the grade,
  comment and state, as the grading form's "Apply grades and feedback to entire group".
  `notify` applies to every member. Without `feedback` or `workflowstate` the members'
  current ones go along, so they must agree (else `teamdiffers`). A team that never
  submitted gets its team submission first, as the grading page does.
- **State and `expected`:** `grade` (null without a grade record), `grader`,
  `timemodified`, `feedback_text`, `feedback_format` (raw, null without a comment),
  `workflowstate`, `mailed` (0 waiting, 1 sent, 2 nothing to send, null without flags),
  `attemptnumber`. For a team grade, before and after also hold `members`, the same for
  each member.
- **Refused:** `notgradable` for a user not enrolled with `mod/assign:submit`;
  `advancedgrading` when a rubric or marking guide is active; `gradelocked` when the grade
  is locked or overridden in the gradebook (Moodle would save the feedback only);
  `notsaved` when a value read back differs from the one sent. A quiet write of the
  stored values changes nothing (`nochange`).
- **Undo:** writes back each student's previous grade, comment and format, and workflow
  state, always without notifying. A grade that did not exist goes back to -1 with an
  empty comment (the grade record stays; both read as "not graded"). Refused if any
  member's grade, comment, format, state or attempt is no longer the entry's `after`, or
  the grade's time is not the one the newest journal entry for that student left (an
  undo included): so grades can be undone newest first in a chain, but a grade saved
  outside the plugin in between (which moves the time without a journal entry) blocks
  it. `mailed` changing (cron sending the notice) does not block it.
- **Journal:** holds the students' grades and raw comments, linked to each student for
  the privacy API (see Privacy). `local_paatshala_journal_get` refuses such an entry and
  `local_paatshala_journal_list` leaves it out unless the caller has `mod/assign:grade`.

### Creating and deleting

Everything the plugin creates is journaled with `createdbyplugin` = 1 and can be deleted
again by undoing that entry or with `local_paatshala_delete_created`. Deletion goes
through Moodle's own functions so the recycle bin keeps a backup, and is refused unless:

- the journal shows the plugin created that object (the newest creating entry for it
  that has not been undone);
- the recycle bin that would receive it is on: the course recycle bin for activities, the
  category recycle bin for courses (errorcode `recyclebindisabled`);
- it holds no student data (errorcode `studentdata`, with counts of students' rows in the
  message):
  - activities: rows belonging to a student (see below) of any of these: a final grade
    (`grade_grades.finalgrade`) on its grade items; a completion state other than
    incomplete; and per type: assignment submissions not in status "new", quiz attempts,
    workshop submissions (by author) and their assessments (by reviewer; not examples),
    forum posts, feedback responses (saved and unsaved), choice answers, database records,
    glossary entries, lesson attempts and grades, SCORM tracks. Page, URL, label, file,
    folder, book and IMS content package have none of their own. Any other type is
    refused (`unknownmodule`): the plugin cannot tell.
  - **Who is a student**, per row's user:
    - enrolled in the course (active or suspended): has `moodle/course:isincompletionreports`
      in the course. That is the rule Moodle's completion reports use for "tracked users"
      (`completion_info::is_tracked_user()`, lib/completionlib.php:1141, the same in the
      20200508 build), and the student archetype's capability. The enrolment's status is
      ignored, so a suspended student still blocks; a site admin's "do anything" is not
      used, so an admin enrolled as a teacher does not.
    - not enrolled: blocks, unless the user has `moodle/course:manageactivities` in the
      course without an enrolment (site admins, managers and teachers assigned at category
      or site level, the web service user). The rows of an unenrolled former student stay
      in Moodle and still block: the plugin cannot tell a former student from a former
      teacher, and keeping both as blocking is the safe side.
    - no user (`userid` 0, e.g. a team submission): blocks.

    So a teacher, a non-editing teacher or an admin opening the activity (which writes a
    "viewed" completion row on a page with completion), a teacher's quiz preview or test
    forum post does not block a delete; any student's does.
  - courses: any user holding a role with the student archetype in the course.

Undoing a deletion (redo) restores the recycle bin item with tool_recyclebin's own
restore while it has not expired; the object gets a new id, which the result's `after`
and the journal target hold. A restore is a creation again, so it can be deleted again
under the same rules.

Courses the plugin creates are not in `local_paatshala/allowedcourses` until the admin
adds them (the result warns `notallowed`); the plugin's course functions refuse them until
then. Creating, deleting and restoring courses is gated by the category instead.

### local_paatshala_copy_activity(source_cmid, target_courseid, sectionnum, beforecmid=0)

- **Changes:** copies one activity without user data into a course (the same or another),
  the way the course page's Duplicate does (`duplicate_module()`: a one-activity backup
  in import mode, restored into the course), but into the chosen course. The copy is
  hidden and placed in section `sectionnum`, before `beforecmid` (0: at the end). It keeps
  its name, settings, grading method and rubric or marking guide, workshop assessment
  form, and question references. Both courses must be in `allowedcourses`.
- **Result:** `after.cmid` is the copy.
- **Undo:** deletes the copy (see "Creating and deleting").
- **Limits:** Moodle's restore gives the caller the role in `restorernewroleid` (editing
  teacher by default) in the target course if the caller lacks `moodle/course:update`
  there, as Import does; the result warns `restorerrole` and undo does not remove that
  role. Give the web service role `moodle/course:update` to avoid it. Group and grouping
  settings that refer to the source course's groups do not carry over to another course.
- **Notifies:** no.

### local_paatshala_create_activity(courseid, sectionnum, modname, fields)

- **Changes:** creates an assignment, page, URL or label through the settings page's add
  path: the module's settings form with the site's defaults, the given fields, the form's
  own checks (its rules and `validation()`), then `add_moduleinfo()`. Hidden unless
  `fields` has `"visible": 1`.
- **Fields** (a JSON object): every type takes `name`, `intro` (HTML) and `visible`; then
  - assign: `allowsubmissionsfromdate`, `duedate`, `cutoffdate`, `gradingduedate`,
    `grade`, `assignsubmission_onlinetext_enabled`, `assignsubmission_onlinetext_wordlimit`,
    `assignsubmission_onlinetext_wordlimit_enabled`, `assignsubmission_file_enabled`,
    `assignsubmission_file_maxfiles`, `assignsubmission_file_maxsizebytes`,
    `assignsubmission_file_filetypes`, `assignfeedback_comments_enabled`,
    `assignfeedback_file_enabled`, `submissiondrafts`, `requiresubmissionstatement`,
    `attemptreopenmethod`, `maxattempts`, `sendnotifications`, `sendlatenotifications`;
  - page: `content` (HTML);
  - url: `externalurl`;
  - label: nothing more (its text is `intro`).
  Anything else is refused. Settings not given take the site's defaults, as on the page.
  Note the defaults include dates relative to today (an assignment's due date is a week
  ahead, "remind me to grade by" two weeks ahead), so give dates that fit together.
- **Result:** `after.cmid`.
- **Undo:** deletes it (see "Creating and deleting").
- **Notifies:** no.

### local_paatshala_create_course(categoryid, fullname, shortname, startdate, enddate=0, format='topics')

- **Changes:** creates a hidden course with `create_course()`, starting from the site's
  course defaults (Site administration > Courses > Course default settings), as the
  course settings page does. Checks as that page: the short name is free, the end date is
  after the start date, the format is enabled. The category must be in
  `allowedcategories`.
- **Limits:** as `course/edit.php`, if the caller cannot assign roles in the new course
  (`moodle/role:assign` and course view), Moodle enrols them with the course creator's
  role (`creatornewroleid`); the result warns `creatorenrolled`.
- **Result:** `after.courseid`.
- **Undo:** deletes the course (see "Creating and deleting").
- **Notifies:** no.

### local_paatshala_duplicate_course(source_courseid, categoryid, fullname, shortname, startdate)

- **Changes:** copies a course without user data, the way
  `core_course_external::duplicate_course()` does (backup without users, role
  assignments, comments, completion, logs or grade history, restored into a new course),
  and sets the restore's "course start date" as the restore page does, so every date the
  restore code offsets moves by (new start date - old start date). The copy is hidden.
- **Dates that move** (restore's `apply_date_offset()` in the 20200508 build): course
  start and end dates; course completion date criteria; activity "expected completion"
  dates; date conditions in access restrictions; non-action calendar events; grade item
  and category lock times; assignment allow
  submissions from, due, cut-off and remind-me-to-grade-by dates (and overrides); quiz
  open and close (and overrides); workshop submission and assessment start and end;
  forum due, cut-off, rating start and end; choice,
  feedback and SCORM open and close; lesson available and deadline (and overrides);
  database available and view from/to, rating dates; glossary rating dates; wiki edit
  begin and end; chat time; badge expiry. Action events (due dates on the calendar) are
  not restored but rebuilt by an ad hoc task on the next cron run.
- **Result:** `after.courseid`.
- **Undo:** deletes the copy (see "Creating and deleting").
- **Limits:** it runs a full backup and restore inside the web service request, which
  grows faster than the course. Measured locally (PHP 7.4, PostgreSQL 12, 4 cores):
  - 200 activities (80 assignments with a 200 KB attachment each, 20 quizzes with 100
    questions, 20 forums, 80 pages), 200 students: about 13 s, 127 MB peak;
  - 600 activities (the same mix, three times over): about 113 s, 383 MB peak.
  Large courses can outlast web server and proxy timeouts (nginx's default proxy read
  timeout is 60 s); the request may then fail on the client while the copy finishes on
  the server. Time Paatshala's largest course on staging before relying on it.
- **Notifies:** no.

### local_paatshala_delete_created(cmid | courseid, expected?)

Deletes an activity or course the plugin created by undoing its creating journal entry
(see "Creating and deleting"). Refused with `notcreatedbyplugin` for anything else.

### local_paatshala_recyclebin_list(courseid | categoryid)

Read-only. Whether that recycle bin is on, and its items: `id`, `bin` (course or
category), `name`, `modname` (activities) or `shortname` (courses), `timecreated` and
`expires` (0 if never), as `admin/tool/recyclebin/index.php` shows them.

### local_paatshala_recyclebin_restore(itemid, bin='course')

- **Changes:** restores a recycle bin item with tool_recyclebin's own restore
  (`course_bin` or `category_bin` `restore_item()`), whoever deleted it. The restored
  activity or course gets a new id (in `after`).
- **Undo:** deletes it again, under the guards in "Creating and deleting".
- **Limits:** tool_recyclebin restores as the site admin and, if Moodle's restore
  precheck fails, prints an error page and exits instead of throwing; the web service then
  returns that page instead of JSON (rare: a missing module type).
- **Notifies:** no.

### local_paatshala_update_assign_settings(cmid, fields, expected?)

- **Changes:** the given assignment settings, through the settings page's own path (see
  "Activity settings helper"), with the form's checks. `fields` is a JSON object using the
  settings form's field names, from this whitelist:
  - `name`;
  - submission types and their limits: `assignsubmission_onlinetext_enabled`,
    `assignsubmission_onlinetext_wordlimit_enabled`, `assignsubmission_onlinetext_wordlimit`,
    `assignsubmission_file_enabled`, `assignsubmission_file_maxfiles`,
    `assignsubmission_file_maxsizebytes`, `assignsubmission_file_filetypes`;
  - `requiresubmissionstatement`;
  - attempts: `attemptreopenmethod` (none, manual, untilpass), `maxattempts` (-1 unlimited);
  - feedback types: `assignfeedback_comments_enabled`, `assignfeedback_comments_commentinline`,
    `assignfeedback_file_enabled`, `assignfeedback_offline_enabled`,
    `assignfeedback_editpdf_enabled`.
  Refused with the reason (errorcode `fieldexcluded`): the description (changed with
  `local_paatshala_update_content`, field `intro`) and its attachments, the maximum grade (can rescale
  grades), group submission settings, marking workflow settings, and the dates (use
  `local_paatshala_assign_set_dates`). Anything else is refused as not allowed.
- **Plugin settings need the plugin on:** Moodle saves a submission or feedback type's
  settings only while that type is on (`assign::update_plugin_instance()`), and silently
  drops them otherwise. So a setting of a type that is off after the call, given a value
  other than its current one, is refused with errorcode `notsaved`, e.g. "Online text is off on this assignment, and Moodle saves its
  settings only while it is on; include assignsubmission_onlinetext_enabled: 1 in the same
  call." This covers the word limit, the file limits and types, and the inline feedback
  comments setting. Turning a type on and setting its limits in one call works. Such a
  setting given at its current value is left alone (alone it returns `nochange`).
- **Word limit pair:** online text stores the limit and its switch together: a limit of 0
  turns the switch off, and the switch off stores the limit as 0. A limit without the
  switch on, or the switch on without a limit above 0, is refused with `notsaved` and
  the reason.
- **Types that cannot be turned on here** are refused with `notsaved` and the reason: a
  type disabled for the whole site, Annotate PDF without Ghostscript, the offline grading
  worksheet while advanced grading is active.
- **Before/after:** the fields given, as stored; both word limit fields when either is
  given. Turning a submission type off keeps its limits.
- **Undo:** writes the previous values back the same way. Refused if any of them changed.
  When the undo turns a type off again, its settings are put back as they were with the
  plugin's own config (`assign_plugin::set_config()`), because Moodle keeps a type's
  settings while it is off but the settings page cannot save them then.
- **Notifies:** no.

### local_paatshala_workshop_set_dates(cmid, submissionstart?, submissionend?, assessmentstart?, assessmentend?, phaseswitchassessment?, expected?)

- **Changes:** the workshop's dates and its automatic switch to the assessment phase after
  the submissions deadline, through the settings page's path: calendar events update.
  Checks are `workshop_mod_form::validation()`'s (end after start, phases not
  overlapping).
- **Undo:** writes the previous values back.
- **Limits:** with the automatic switch on and the submissions deadline already passed
  while the workshop is in the submission phase, the next cron run or workshop page view
  switches it to assessment; the result warns `autoswitch`.
- **Notifies:** no.

### local_paatshala_quiz_set_dates(cmid, timeopen?, timeclose?, expected?)

- **Changes:** the quiz's open and close dates, through the settings page's path:
  `quiz_update_instance()` updates the calendar events and the time checks of open
  attempts (`quiz_update_open_attempts()`), as on the settings page. Check:
  close not before open (`mod_quiz_mod_form::validation()`).
- **Undo:** writes the previous dates back.
- **Limits:** user and group quiz overrides are separate and unchanged. The gradebook
  visibility of the quiz's grade follows its review options, not its dates.
- **Notifies:** no.

### Activity texts

An activity's teacher-written texts, edited without touching the files embedded in them:

| Activity | Field | Stored as | Settings form editor | File area (itemid 0) |
| --- | --- | --- | --- | --- |
| every type with a description | `intro` | `{mod}.intro`, `introformat` | `introeditor` | `mod_<type>/intro` |
| page | `content` | `page.content`, `contentformat` | `page` | `mod_page/content` |
| workshop | `instructauthors`, `instructreviewers`, `conclusion` | same names, `*format` | `<field>editor` | same names |

Not here: uploading, replacing or deleting files; assignment intro attachments;
"display description on course page"; book chapters; section summaries; a label's name
(Moodle derives it from the text, see below).

Why files are safe: the settings helper loads every editor with a draft area prepared
from the stored files (`file_prepare_draft_area()`), exactly as the settings page does,
and only the edited editor's text and format are replaced; its draft area (`itemid`) is
kept. `file_save_draft_area_files()` (lib/filelib.php:1042) deletes an area file only when
it is missing from the draft area or changed, not when the text stops referring to it, and
leaves `@@PLUGINFILE@@/name` placeholders as they are (it only rewrites links to its own
draft area). So a text-only edit leaves the file set as it was, and an undo that writes
the old text back makes every old reference work again.

#### local_paatshala_get_content(cmid)

Read-only. `{cmid, courseid, modname, name, fields: [{field, text, format, files:
[{filepath, filename, contenthash, filesize, mimetype}], references, missing}]}` for every
field the activity has (`intro` first). `text` is exactly as stored: placeholders kept, no
`format_text()`. `format` is 0 (moodle), 1 (html), 2 (plain) or 4 (markdown). `files` is
the field's file area, directories left out, sorted by path. `references` are the paths
the text uses after `@@PLUGINFILE@@/`, URL-decoded (`diagram one.png`, `sub/pic.png`); a
reference ends at whitespace, a quote, `<`, `>`, `?`, `#`, `)` or a backslash. `missing`
are references with no file in the area. `atrisk` lists the files any settings-form save
of the activity would drop (see "atrisk" below).

#### local_paatshala_update_content(cmid, field, text, format, expected?, dropreferences=0)

- **Changes:** one field's text and format, through the settings page's own path (see
  "Activity settings helper"), with the form's checks. The text is stored byte for byte as
  given; refer to the activity's files as `@@PLUGINFILE@@/<name>`. A field the activity
  does not have is refused (`invalidparameter`, naming its fields).
- **Formats:** 0, 1, 2 or 4; anything else is refused (`invalidparameter`).
- **`expected`:** any of `text` (compared byte for byte), `format`, `files` and, for a
  label's description, `name` (byte for byte). The `conflict` message gives the current
  text's length and SHA-1 (`textlength`, `textsha1`) instead of the text, with the
  format, the files (as a set, see below) and a label's name.
- **File lists (`files`):** a list of `{filepath, filename, contenthash}`: `filepath` as
  Moodle stores it (`/` for the top, `/sub/` for a folder), the file name, and the SHA-1
  of the content. Names are part of it, because a text refers to a file by name: a file
  renamed by hand keeps its hash but breaks the reference. Two lists are the same when
  they hold the same entries as a **set**: order does not matter, an entry given twice
  counts once, and further keys on an entry (`filesize`, `mimetype` from `get_content`)
  are ignored. The plugin returns them sorted by `filepath`, `filename`, `contenthash`.
  The rule holds for `expected`, the undo check and the journal; a caller matching a
  snapshot should compare the same way.
- **References:** a new reference to a file that is not in the field's area is refused
  with `invalidsettings` ("the text refers to x.png, which is not in the activity's files;
  upload it in Paatshala first"); a missing reference the current text already has may
  stay. A reference the current text has to a file in the area, absent from the new text,
  is refused with errorcode `dropsreference` naming the files, unless `dropreferences` is
  1; then the result warns `droppedreference`. The file is kept either way. Links to draft
  areas (`draftfile.php`) are refused (`invalidparameter`).
- **Read back:** the text byte for byte and the format (and, for an undo of a label, the
  name): a difference is `notsaved`. Files and the activity's other texts are guarded by
  the settings helper like every other write (`filesaltered`, see "Activity settings
  helper").
- **Nothing changed:** the same text and format: `nochange`, as for the other writes.
- **Before/after:** `{field, text, format, files}`, plus `name` for a label's description
  (so `local_paatshala_journal_list` shows the label's old and new name). Undoable.
- **Undo:** writes the entry's `before` text and format back the same way, with no
  reference checks; refused (`conflict`) unless the text, the format, the file list and a
  label's name are still the entry's `after`. The file list check is what makes undo
  exact: if a file the old text refers to was removed or replaced by hand since, the undo
  is refused, never written. A label renamed or edited by hand since is refused the same
  way. Undo several entries of one field newest first.
- **Side effects, as on the settings page:** the activity's `timemodified`; a page's
  `revision` increments (`page_update_instance()`); the course cache is rebuilt and the
  search index updated (`course_module_updated` event). A label is named after its text
  (`label_update_instance()`: the first 50 characters without tags, "..." after a cut),
  so a label's name changes with its text and the result warns `namechanged` with the
  old and new name. Undo restores the entry's old name exactly, and redo the new one: when
  the name Moodle derives from the restored text differs from the recorded one (a name
  given by an older Moodle, or by hand), the recorded name is written to `label.name`
  and the course cache rebuilt, since the settings page has no name field for labels.
- **Notifies:** no. `update_moduleinfo()` triggers `course_module_updated` only, and no
  message is sent on that path in 3.8: its observers are the recent activity block (which
  then lists "Updated: <activity>" for course users, as after a save on the settings page)
  and glossary's. Event monitoring (`tool_monitor`) rules on that event, if a site has
  any, message their subscribers as for any settings save.

### Activity completion (0.10.0)

The "Activity completion" settings of an activity, as on its settings page, so a teacher
does not have to open each activity's settings to switch on completion ticks.

**What the modes mean for students:**

- **None** (`completion` 0, "Do not indicate activity completion"): no completion state
  is kept; a restriction on its completion cannot be met ("must be complete") or is always
  met ("must not be complete").
- **Manual** (1, "Students can manually mark the activity as completed"): a tick box next
  to the activity on the course page; the student ticks it (and can untick it) when they
  decide they are done.
- **Automatic** (2, "Show activity as complete when conditions are met"): Moodle marks it
  complete when every chosen condition holds: viewed it (`completionview`), received a
  grade (`completionusegrade`, or `completiongradeitemnumber` for workshops and forums),
  and the activity's own rules (assignment: submitted; quiz: passing grade, or all attempts
  used; feedback: submitted; forum: N posts, discussions or replies). The student cannot
  tick it.
- **A view or a tick does not prove a video was watched.** "Viewed" means the activity's
  page was opened (the page module calls `completion_info::set_module_viewed()` when the
  page loads, mod/page/view.php:55, mod/page/lib.php:522); an embedded Drive or YouTube video may never
  have been played. A manual tick is the student's own statement. Restrictions on
  completion (below) mean exactly this and nothing more; the plugin cannot do better than
  Moodle's rule.
- `completionexpected` ("Expect completed on") adds a date to the dashboard and the
  calendar ("expected completion" event), for progress tracking only; it is not a
  deadline and changes nothing for access.

**Module types:** page, url, resource, book, folder, label, assign, quiz, workshop,
feedback and forum. Others are refused with `fieldexcluded`. What each form offers
(`supports` in the reads), from the modules' `*_supports()` and `add_completion_rules()` in
3.8:

| Type | View | Grade | Own rules |
| --- | --- | --- | --- |
| page, url, resource, book, folder | yes | no | none |
| label | no (Manual only) | no | none |
| assign | yes | `completionusegrade` | `completionsubmit` |
| quiz | yes | `completionusegrade` | `completionpass` (needs a grade to pass and `completionusegrade`), `completionattemptsexhausted` (needs `completionpass`) |
| workshop | yes | `completiongradeitemnumber` (0 submission, 1 assessment) | none |
| feedback | yes | no | `completionsubmit` |
| forum | yes | `completiongradeitemnumber` (0 rating, 1 whole forum) | `completiondiscussions`, `completionreplies`, `completionposts` (counts, 0 off) |

#### local_paatshala_get_completion(cmid)

Read-only. `siteenabled` (`enablecompletion`), `courseenabled`, the activity's ids, name,
section and visibility, its settings (`completion`, `completionview`,
`completionusegrade`, `completiongradeitemnumber` (null for none), `completionexpected`,
`rules` [{name, value}]), `supports` (above: `view`, `grade`, `gradefield`, `gradeitems`,
`rules`, `automatic`, `supported`), `data`, `referencedby` and `state`:

- **`data`:** the activity's completion rows split into `students` and `others` (staff),
  with the rule `local_paatshala_delete_created` uses since 0.9.0 (a user counts as a
  student with `moodle/course:isincompletionreports` when enrolled, a former student's rows
  count, rows of users who may manage activities without an enrolment do not); for each:
  `rows`, `incomplete` (a row with a viewed flag only), `complete`, `completepass`,
  `completefail`, `viewed`. `locked` is what the settings page freezes on (somebody has a
  state other than incomplete, `completion_info::count_user_data()`,
  lib/completionlib.php:761). `coursecompletion`: whether the activity is a course
  completion criterion, and the course completion records of students and others
  (with how many completed the course), which a reset deletes (below).
- **`referencedby`:** every section and activity of the course whose access restriction
  has a completion condition on this activity, nested sets included: type, ids, name,
  `path`, `negated`, `display`, `state` (the state it requires).
- **`state`:** the settings and data counts as one JSON object, ready to pass as
  `expected`.

#### local_paatshala_get_course_completion(courseid)

`local_paatshala_get_completion` for every activity of the course (course page order,
activities being deleted left out), in one call: `courseid`, `siteenabled`,
`courseenabled`, `items`.

#### local_paatshala_set_completion(cmid, fields, resetcompletion=0, force=0, expected?)

One activity per call (six pages: six calls). `fields` is a JSON object with any of
`completion` (`none`, `manual`, `automatic`, or 0, 1, 2), `completionview`,
`completionusegrade` or `completiongradeitemnumber` (per type, above),
`completionexpected` (unix time, 0 for none) and the type's own rules; fields not given
keep their values.

- **Refused:**
  - `completiondisabled`: the site's `enablecompletion` or the course's completion tracking
    is off (naming which);
  - `fieldexcluded`: a field that is not a completion setting, or a module type not listed
    above;
  - `invalidsettings`: a rule the type's form does not offer (`completionsubmit` on a page,
    Automatic or "must view" on a label, `completionusegrade` on a workshop), Automatic with
    no condition (the form's "badautocompletion"), a condition given with Manual or None, an
    expected date with None, quiz `completionpass` without `completionusegrade` or
    `completionattemptsexhausted` without `completionpass`, and whatever the module's form
    validation refuses (a quiz's "Require passing grade" without a grade to pass, a
    workshop's assessment grade item while assessment grading is off);
  - `completiondata`, `referenced`: below; `conflict`: `expected` differs (settings and
    data counts, as `state`), so a student ticking the activity between the caller's read
    and the write is a conflict.
- **Mode changes:** moving away from Automatic clears the automatic conditions (the form
  hides them but still posts them, and 3.8 marks a Manual activity complete on view when
  its hidden "must view" is still ticked, lib/completionlib.php:725-750); moving to None
  clears the expected date.
- **Write path:** the settings page's: the activity settings helper (load, merge, the
  form's validation, `update_moduleinfo()`), with `completionunlocked` set as the page
  posts it when nothing locks completion or after "Unlock completion options"
  (course/moodleform_mod.php:324-366). So the module's own form validation and update
  code run (the quiz form's grade-to-pass check, `quiz_process_options()`, forum's
  post-processing of its counts, each module's expected-completion calendar event), and the
  `filesaltered` guard covers the save: content files, intro, availability, tags and every
  other setting stay as stored (tested row by row). Not `core_completion\manager::
  apply_completion()` (the 3.8 bulk edit): it writes `course_modules` and the module's
  table directly, without the module's form validation or update code, and resets
  completion on every save except Manual to Manual (completion/classes/manager.php:302-394).
- **Existing completion data.** A change to anything but the expected date is saved with
  `completionunlocked`, and `update_moduleinfo()` then calls
  `completion_info::reset_all_state()` (course/modlib.php:640-642), as the settings page
  does:
  - every completion row of the activity is deleted (`delete_all_state()`,
    lib/completionlib.php:855-886): manual ticks and viewed flags are gone, whoever they
    belong to;
  - with Automatic, the state of every tracked user and every user who had a row is
    recalculated (lib/completionlib.php:895-930): grade and submission rules come back for
    those who meet them (and fire `course_module_completion_updated`, as any completion
    does: badges and competencies observe it); view rules start again from nothing;
  - if the activity is a course completion criterion, every course completion record of
    the course is deleted too (`course_completions` and this criterion's
    `course_completion_crit_compl`): students who had completed the course lose it until
    the completion cron finds their criteria met again, with a new completion time;
  - the expected date alone resets nothing (course/modlib.php:538-540).

  So: with students' rows (completion rows, or course completion records when it is a
  criterion), the change is refused with `completiondata`, giving the counts (students'
  rows, complete, viewed; others' rows; course completion records). `resetcompletion = 1`
  allows it; the result has warning `completionreset` with the counts, and the journal
  entry's `after.reset` holds them (the flag that a reset happened). Rows of staff only
  (a teacher's preview tick) are reset without `resetcompletion`, with the warning.
- **Restrictions pointing at it** (`referencedby`): turning completion off is refused with
  `referenced`, listing them, unless `force = 1` (then warning `referenced`: "must be
  complete" conditions on it can never be met). Changing its mode gives warning
  `referenced` (its completion was reset, so those restrictions apply again).
  Turning off "must receive a grade" while restrictions require pass or fail gives warning
  `referencedpassfail`.
- **Result:** before and after are every setting plus the data counts; `after.reset` when
  data was reset. `notifies_students` false: neither the settings save nor the reset sends
  a message in 3.8.2. A recalculation that completes an activity can award a badge whose
  criteria are then met (badges/classes/observer.php:37-70), which messages the student,
  as on the settings page.
- **Journal** kind `module_completion`, target `{cmid, modname, name}`. Holds the settings
  and the counts, never user ids or per-user states.
- **Undo** writes the entry's settings back the same way. Refused with `conflict` unless
  the settings and the data counts are still the entry's `after` (a student ticking it
  since blocks the undo). An undo that would itself reset students' data needs
  `resetcompletion = 1` on `local_paatshala_undo` (else `completiondata`), and one that
  turns completion off on a referenced activity needs `force = 1`. **Undo does not bring
  reset completion data back.** 3.8 has no supported way to put the rows back as they were:
  `update_state()` sets a manual state only with a new time and fires
  `course_module_completion_updated` (badges, competency evidence), recalculates automatic
  states instead of setting them, `set_module_viewed()` only works under a view rule and
  marks completion too, and `internal_set_data()` is internal and fires the same event;
  direct table writes are out. So students tick again, or view again, after a reset; say
  so before using `resetcompletion` on a course with students.

### Group and date access restrictions

(0.10.0 adds activity completion and grade parts; see "Completion and grade restrictions"
below. Everything here applies to them too.)

Restrict access > Group and Restrict access > Date on a section or an activity, through
Moodle's availability API (the tree stored in `course_sections.availability` /
`course_modules.availability`). This is not group mode (which only splits participants
inside an activity) and not the section's or activity's own visible flag (the eye icon):
all three are separate settings, and these functions change only the restriction.

- **Site settings:** `enableavailability` (Site administration > Advanced features >
  Enable restricted access) must be on, and the condition plugin for each part a call
  changes: Group (`availability_group`) for `group`, Date (`availability_date`) for `from`
  and `until` (Site administration > Plugins > Availability restrictions > Manage
  restrictions). Otherwise `availabilitydisabled`, naming which. A stored tree with a
  condition of a disabled type cannot be parsed strictly, so any change to it is
  `invalidavailability`.
- **What students see:** `whenunmet` = `hide` hides the item entirely from students the
  part excludes: no heading, no "Not available unless..." message, and the activities
  inside a hidden section cannot be opened by link either. `show` shows it greyed out with
  the restriction message, and they still cannot open it. Each part has its own setting.
  Members of any of the groups have access (a student in two of them too), from `from`
  (included) until `until` (not included), when every part is met.
- **Staff:** the group condition lets in users with `moodle/site:accessallgroups`
  (availability/condition/group/classes/condition.php:58; the editing teacher and manager
  roles), and the whole restriction (dates included) is ignored for users with
  `moodle/course:ignoreavailabilityrestrictions` (lib/modinfolib.php:2008 for activities,
  2839 for sections; editing and non-editing teachers, managers). So both teacher roles
  keep access.
- **Web service role:** `local_paatshala_set_access` requires
  `moodle/course:ignoreavailabilityrestrictions` (beyond the settings pages), so the role
  never restricts an item it could not then read, change back or undo.
- **Section 0** can be restricted, as its edit page offers.

#### local_paatshala_get_availability(courseid, sectionid=0, cmid=0)

Read-only; one of `sectionid` or `cmid`. Returns the type, ids, section number, name, its
own `visible` flag, the number of activities (sections), the stored tree as a raw string
(`''` for none), `valid` and `parseerror`, every group the tree names (name, whether it
exists and belongs to the course, member count), every condition as plain data (type,
path such as `c[1].c[0]`, whether it applies negated, `show`/`hide` when unmet, its own
fields as JSON: a date condition's are `{"d":">=","t":...}` for from, `"<"` for until),
`enableavailability`, `groupenabled`, `dateenabled`, `completionenabled` and `gradeenabled`.
`valid` is the strict parse Moodle uses when a tree is saved: a condition of a type disabled
on the site makes it false, although Moodle ignores such a condition when showing the course.

From 0.10.0 each completion condition also has `cm` (the activity it names: `cmid`, `name`,
`modname`, its `completion` mode, its `completiongradeitemnumber` or -1, `exists`), each
grade condition `gradeitem` (`id`, `name`, `itemtype`, `cmid` for an activity's item,
`grademax`, `gradepass`, `exists`), and the item has `parts`: for each of `group`, `from`,
`until`, `completion` and `grade`, whether the tree has it (`present`), whether
`local_paatshala_set_access` can set or remove it now (`changeable`) and why not
(`reason`: the refusal below with its path, an invalid tree, a site setting or the
course's completion tracking being off).

#### local_paatshala_get_course_availability(courseid)

The same for every section and activity of the course with a stored tree. Read from the
tables, not evaluated for anyone, so a tree Moodle cannot read (course 265 has one, where
`core_course_get_contents` fails with "Invalid availability structure (missing ->show)")
comes back with `valid` false and its `parseerror` instead of failing the call.

#### local_paatshala_set_access(courseid, sectionid=0, cmid=0, group?, from?, until?, expected?, completion?, grade?)

One of `sectionid` or `cmid`, and at least one part; a part left out is left as stored.
All parts given are written at once: one write, one journal entry, one undo.

- **`group`** = `{action: set|remove, groupids: [...], whenunmet: hide|show}`. `set`
  restricts to members of any of `groupids` (all in the course, else `invalidgroup`; a
  group without members gives warning `emptygroup`).
- **`from`** and **`until`** = `{action: set|remove, time: unix time, whenunmet:
  hide|show}`: Date conditions `>=` time (from) and `<` time (until), as the Date
  condition's form makes them. `whenunmet` defaults to `hide`. `until` must be after
  `from`, using the stored value for the one not given (`invalidsettings`). An `until`
  that has already passed is allowed, as in the form, with warning `untilpassed`:
  everyone it applies to loses access at once.
- **The managed restrictions** are top-level children of an "all of" root, one per part,
  each with its own display setting: one group condition or an "any of" set of group
  conditions (one per group); one `>=` date condition; one `<` date condition. Every other
  restriction and its display setting stays as stored:
  - no tree: a new "all of" root with the parts set, in the order group, from, until;
  - "all of" root: each part is replaced in place, or appended;
  - "any of" or "not all of" root: wrapped in a new "all of" root with the old one as its
    first child (same logic, same display) and the parts after it;
  - "none of" root: wrapped the same way only if all its conditions have the same display
    setting; otherwise refused.
  - `remove` drops that part; with nothing left the column becomes NULL, as the activity
    form stores. A wrap made earlier stays.
- **Refused** with `invalidavailability` and the reason (naming the path), to edit in
  Moodle instead, for the parts being changed only: conditions of that part's kind
  anywhere else (inside a "must not" set or a nested set, mixed into an "any of" with
  other conditions, two candidates, an "any group" condition), and, for any change, a
  stored tree Moodle cannot read (never repaired). So a tree with a nested date can still
  have its group restriction changed. The result is checked by building Moodle's own tree
  from it. Profile, grouping, "any group" and other conditions are never written; nor are
  completion and grade conditions outside their managed parts (0.10.0, below).
- **`expected`:** `{"availability": "<stored JSON or ''>"}`, compared as decoded structures
  (key order and whitespace aside); a mismatch is `conflict` with the current value.
- **Writes** through the settings pages' own paths: `course_update_section()` for a section
  (course/editsection.php:129: saves, rebuilds the course cache, triggers
  course_section_updated), the activity settings helper with `availabilityconditionsjson`
  for an activity (as course/modedit.php). A call that would not change the tree is
  `nochange` (journalid 0).
- **Result:** before and after are `{"availability": ...}`; `notifies_students` false.
- **Undo** writes the exact `before` string back the same way; refused with `conflict` if
  the tree is no longer the entry's `after`. Journal kinds `section_availability` and
  `module_availability`; they hold group ids and times only.

#### Completion and grade restrictions (0.10.0)

Restrict access > Activity completion and Restrict access > Grade, as two more parts of
`local_paatshala_set_access`, written with the group and date parts in the same call, the
same journal entry and the same kinds (`section_availability`, `module_availability`).

- **`completion`** = `{action: set|remove, conditions: [{cmid, state}], whenunmet:
  hide|show}`. `state`: `complete` (also met by complete with pass or fail),
  `incomplete`, `pass` (complete with a pass grade), `fail` (complete with a fail grade).
  Every condition must hold. Stored as availability_completion stores it: `{"type":
  "completion", "cm": <cmid>, "e": 1|0|2|3}`.
- **`grade`** = `{action: set|remove, conditions: [{cmid | gradeitemid, min?, max?}],
  whenunmet: hide|show}`. `cmid` stands for the activity's grade item (item type mod,
  item number 0; a page has none); `gradeitemid` takes any grade item of the course, as
  the form offers them (category totals, manual items, the course total). `min` and `max`
  are percentages of the item's range (0 to 100), `min` included and `max` not, as
  availability_grade compares them (classes/condition.php:110-120 and 225-245, the score is
  `(finalgrade - min) * 100 / (max - min)`); neither means "must have a grade"; `min`
  must be below `max`. Stored as `{"type": "grade", "id": <itemid>, "min": .., "max":
  ..}`, the bounds only when given.
- **The managed children**, top-level children of the "all of" root with their own
  display setting: completion is one completion condition or an "all of" set made only of
  completion conditions; grade the same with grade conditions. `set` replaces the part's
  conditions as a whole (same position, display updated) or appends it; a new tree has
  group, from, until, completion, grade in that order. Wrapping, `remove`, NULL for an
  empty tree and `nochange` as for the other parts. A completion or grade condition
  anywhere else (negated, nested, in an "any of", in a set mixing the two types, two
  candidate children, a cm stored as a string) is refused with its path, but only when
  the call changes that part.
- **Checks**, from the two condition plugins' forms (`invalidsettings` unless named):
  - the condition plugins on (`availabilitydisabled`, naming `availability_completion` or
    `availability_grade`); for completion, site and course completion tracking on
    (`completiondisabled`, naming which; availability_completion/classes/frontend.php:80-91
    offers the condition only then);
  - each activity in this course and not being deleted, and not the restricted activity
    itself (frontend.php:60-72);
  - each activity with completion tracking on: `nocompletion` otherwise, naming it (turn it
    on with `local_paatshala_set_completion` first). Moodle evaluates such a condition
    against no completion data: "complete" is never met, "incomplete" always;
  - `pass` and `fail` only for an activity whose completion requires a grade
    (completiongradeitemnumber set), as the form's JavaScript requires
    (yui/src/form/js/form.js:80-86);
  - each grade item in this course, not of an activity being deleted, not the restricted
    activity's own (availability_grade/classes/frontend.php:45-66); the bounds as the form's
    JavaScript checks them (form.js:128-160);
  - 3.8 has no "previous activity with completion" option (cm -1; added in a later
    Moodle): availability_completion's constructor and frontend have no such case.
- **Deadlocks** (direct cases only):
  - refused (`deadlock`): a section restricted on completion (complete, pass or fail) of
    an activity inside it, whatever `whenunmet` (a student cannot open an activity in a
    section that is not available to them, so cannot complete it); an item restricted on
    completion of an activity that itself (or its section) requires completion of the item
    or of an activity inside it, through "all of" sets only;
  - warned (`gradedeadlock`): a section restricted on a grade in an activity inside it (a
    teacher can still grade it without a submission);
  - not covered: longer cycles (A needs B needs C needs A), cycles through "any of" sets or
    grade conditions between activities, conditions on activities that are hidden or
    restricted by other means. "Must not be complete" is never a deadlock.
- **Staff** keep access as before (`moodle/course:ignoreavailabilityrestrictions`).
- **Interaction with completion settings:** a restriction on an activity's completion means
  only what that activity's completion rule means (see "Activity completion": a view or a
  tick does not prove a video was watched). Changing the activity's completion settings
  resets its completion data, so restrictions on it apply again until students complete it
  under the new rule; turning its completion off is refused with `referenced` unless
  forced (`force`), and then "must be complete" conditions on it can never be met.

#### What a hidden restriction does not hide in 3.8

Checked on the 20200508 build (local copy, Moodle 3.8.2+ fda378562d) as a September-only
student, with section 1 restricted to the June group (hidden entirely) and holding an
assignment with a due date, completion and grades, and a forum with a post:

| Where | Activity name or due date shown? |
| --- | --- |
| Calendar (upcoming, month, day), dashboard timeline and upcoming events block | No |
| User report and overview report | No row for the activity, **but its grade counts in the course total** (a grade the student got before the restriction, for example after moving batch) |
| Recent activity (block and full report) | No |
| Course page, navigation (left drawer), Activities block and the assignment and forum index pages | No |
| Global search (simple DB engine) | No |
| Direct links to the activities | No: `require_login()` refuses and the student lands on the course page, without the section |
| Completion: dashboard course progress | **Yes, as a count**: the hidden activity with completion is counted, so after completing the one visible activity of two the student sees "50% complete" and cannot reach 100% |
| Restriction message of an item shown greyed (`show`) with a completion or grade condition (0.10.0) | **Yes**: the message names the activity the completion condition points at, and the grade item a grade condition points at, even when that activity is hidden from the student or restricted itself (availability/classes/info.php:736-748 puts the activity's name, without a link when the student cannot open it; availability_grade/classes/condition.php:171-193 the grade item's name). With `hide` nothing is shown. |

Not checked: emails (forum subscriptions, digests, notifications), mobile app, other
search engines, other reports (outline, completion reports for teachers).

Completion and grade conditions (0.10.0) were not re-checked in a browser on the staging
copy. From the code, every row above depends only on whether the item is available to the
student (`cm_info::uservisible`, `section_info::uservisible`), not on which condition makes
it unavailable, so the rows hold for them as they are; the new row comes from the message
code. Worth a manual look on staging before relying on it.

### local_paatshala_journal_list(courseid, since=0, kind='', limit=100)

Journal entries of a course, newest first: `id`, `timecreated`, `userid`, `courseid`,
`cmid`, `kind`, `target`, `before`, `after` (JSON strings), `undoable`,
`createdbyplugin`, `undoof`, `undoneby`, `notifies`. `limit` 0 returns all.

### local_paatshala_journal_get(id)

One journal entry, same fields.

### local_paatshala_undo(journalid, expected?, resetcompletion=0, force=0)

Applies the inverse of a journal entry through the same code path, gate and capability
checks as the original function, journals it with `undoof` set and sets `undoneby` on the
original.

- `resetcompletion` and `force` are used by `module_completion` entries only, with the
  meaning they have in `local_paatshala_set_completion`: an undo that would itself reset
  students' completion data is refused with `completiondata` without `resetcompletion`,
  and one that turns completion off on an activity restrictions point at is refused with
  `referenced` without `force`. Other kinds ignore them.

- Refused with `conflict` unless the target still has the entry's `after` (and matches
  `expected`, if given).
- Refused with `notundoable` for entries with `undoable` = 0, and `alreadyundone` for
  entries already undone.
- Undoing an undo entry is a redo, under the same rules.

## Activity settings helper

`update_moduleinfo()` replaces every setting of an activity, so writing one field means
rebuilding everything the settings form would submit. `classes/local/modform.php` does it
the way `course/modedit.php` does:

1. `get_moduleinfo_data()`: course module fields, the module row, the intro in a draft
   area (`file_prepare_draft_area()`), advanced grading methods, outcomes, grade to pass
   and grade category.
2. The module's own settings form is built and `set_data()` runs its
   `data_preprocessing()` (for assignments: the intro attachments draft area and the
   submission and feedback plugin preprocessing).
3. The form's element values are taken as a browser would submit them unchanged. This
   brings in settings that only exist as form defaults: assignment submission and
   feedback plugin settings (file limits, word limit, feedback types), plagiarism settings
   and other plugins' activity settings.
4. The stored values from 1–2 replace the form's where both exist, because some
   elements are lossy (date selectors drop the seconds). Before export,
   `definition_after_data()` runs, as Moodle does before every display and submission
   (workshop sets its grade categories there).
5. The change is applied. Data is then shaped as a browser posts it: empty text fields
   as `''`, editors with a format, and unchecked plain checkboxes left out. The last
   matters in this build: `quiz_review_option_form_to_db()` uses `isset()`, so a review
   option left in as 0 would be switched on.
6. The form's element rules (required, maxlength...) and `validation()` run; then what
   `moodleform_mod::get_data()` does after validation (`unformat_float()` of the grade to
   pass, `data_postprocessing()`).
7. `update_moduleinfo()` (or `add_moduleinfo()` for a new activity) saves it.
8. The guard (updates only): the files and texts recorded after step 1 are compared with
   what the save left; any difference the call did not ask for rolls the whole call back
   with errorcode `filesaltered`. See "filesaltered" below.

Tags, competencies, the competency rule, the grade to pass, the grade category, outcomes,
the advanced grading method and the completion lock flag are left out of the saved data.
`update_moduleinfo()` and its callbacks only touch those when they are present, so they
stay exactly as they were. A test saves a richly configured assignment without changes
and checks that `course_modules`, `assign`, `assign_plugin_config`, `grade_items`,
`event`, `files`, `tag_instance`, `competency_modulecomp`, grading areas and definitions
and availability are unchanged apart from `timemodified`. The same no-op test runs on a
quiz (non-default review options, overall feedback bands, access rules, questions) and a
workshop (dates, automatic switch, grades, assessment strategy). The exceptions are
Moodle's own and happen on the settings page too: `assign::update_calendar()` saves
module events with `userid` 0; `quiz_after_add_or_update()` deletes and reinserts the
quiz's overall feedback rows (new ids); `workshop_calendar_update()` reuses its event
rows in database order (event ids can swap between event types).

### filesaltered

`file_save_draft_area_files()` (lib/filelib.php:1097-1129) saves an activity's draft area
back and deletes every stored file whose draft copy it skips. It skips a file:

- above the area's `maxbytes` when the file's **uploader** (`files.userid`) does not have
  `moodle/course:ignorefilesizelimits` in the activity. A file with no uploader is checked
  against the caller instead (`get_user_max_upload_file_size()` falls back to `$USER`).
  In 3.8 core the only activity area saved with a size limit is **page content**
  (`page_get_editor_options()`: the site's `maxbytes`, Site administration > Security >
  Site security settings > Maximum uploaded file size). Course and activity upload limits
  are not used on these save paths. Descriptions of every activity, assignment intro
  attachments, workshop instructions and conclusion, quiz overall feedback, resource and
  folder files are saved with no size limit;
- in a subfolder, when the area is saved without subfolders: **assignment intro
  attachments** (`assign::save_intro_draft_files()`), feedback's page after submit and
  lesson's media file. The settings forms do not let users make such folders; they come
  from restores or other code;
- beyond `maxfiles` (none of the areas above sets one).

**The settings page loses these files the same way.** A teacher who opens the activity's
settings in Paatshala and presses "Save" goes through the same code: Moodle 3.8 deletes
the file without a warning. A file that `filesaltered` names is therefore already at risk;
the refusal keeps the plugin from being the one to delete it, and the files are listed in
advance by `atrisk` (`local_paatshala_get_content`, `local_paatshala_get_module_settings`).

Every plugin write through the settings helper records, after loading the form:

- the files, at every item id, of the activity's text and attachment areas, of the areas
  where a save can drop files (`content::SAVED_AREAS`: page content, assignment intro
  attachments, feedback's page after submit, lesson's media file), of every area the form
  prepared a draft area from (found from the drafts' recorded originals), and of the areas
  named after the form's editors and file managers (so an area whose every file the form
  skipped is still guarded);
- the text and format behind every editor of the form (description, page content,
  workshop instructions, feedback's page after submit).

After `update_moduleinfo()` it compares. A file removed, replaced or added, or a changed
text the call did not ask to change, refuses the call with errorcode `filesaltered`: the
delegated transaction is rolled back, so nothing is changed and nothing is journaled, and
the message and `debuginfo` name each difference (`mod_page/content/big.png removed`,
`assign.intro text changed`). The one exception is quiz overall feedback:
`quiz_after_add_or_update()` reinserts the feedback rows on every save and copies their
files to the new item ids, leaving the old ones in place; files at item ids that did not
exist before are not a change there.

The functions that go through the helper, and so through the guard, including their undo
and redo through `local_paatshala_undo`: `local_paatshala_assign_set_dates`,
`local_paatshala_update_assign_settings`, `local_paatshala_workshop_set_dates`,
`local_paatshala_quiz_set_dates`, `local_paatshala_set_access` for an activity, and
`local_paatshala_update_content`. Not through it: creating an activity
(`add_moduleinfo()`, nothing stored yet to lose), copying (backup and restore), and the
functions that call Moodle APIs directly (workshop phase, moves, sections, section access,
overrides, grades, deletes and restores).

**Resolving it.** The message names the file. For a file above the size limit, raise the
site's maximum uploaded file size above that file's size, at least while the change is
made. This is the recommended fix: the file was accepted under an earlier limit, and the
same drop would otherwise hit a teacher saving the settings page. Or replace the file with
a smaller one, or have it uploaded again by a user who may ignore size limits. Giving the
`ws_automation` role `moodle/course:ignorefilesizelimits` does **not** help for a file
with an uploader, because Moodle checks the uploader, not the caller (tested); it only
helps for files with no uploader.

For a file in a subfolder there is no limit to raise. The 3.8 settings page cannot move
it either: these areas are loaded into the form without subfolders, so the file is not
even shown there, and saving the page deletes it. Moodle still serves it, at
`<site>/pluginfile.php/<module context id>/<component>/<filearea>/0<path><name>` (the
`area`, `path` and `name` in `atrisk`). So, as a teacher of the course:

1. Download each listed file from that address. An assignment serves its attachments only
   while its description is shown (`assign::show_intro()`): before "Allow submissions
   from", tick "Always show description" first.
2. Open the activity's settings and add the file again at the top level:
   - assignment intro attachments: drop it into "Additional files";
   - feedback's page after submit: insert it into the "Page after submission" text with
     the editor's image or link button, replacing the old link;
   - lesson media file: upload it under Appearance > "Linked media".
3. Save. Moodle deletes the subfolder copy and keeps the new one.

`atrisk` is then empty and the plugin's calls go through. Files in subfolders only arise
from restores or other code, as the 3.8 forms do not let users make folders in these areas.

For a changed text, fix the text named (for example a `draftfile.php` link, which Moodle
rewrites to `brokenfile.php`) on the settings page.

### atrisk

`local_paatshala_get_content` and `local_paatshala_get_module_settings` return
`atrisk: [{area, path, name, reason}]`, every file a settings-form save of the activity
would drop, sorted by area, path and name; `[]` when none. Read-only: it changes no write,
and the guard stays the authority.

- `area` is `component/filearea`, e.g. `mod_page/content`; `path` the folder (`/` for the
  top); `name` the file name.
- `reason` `size`: a page content file larger than the site's maximum uploaded file size,
  whose uploader may not ignore size limits. It is checked as Moodle does: the uploader's
  `moodle/course:ignorefilesizelimits` in the activity, or the caller's for a file with no
  uploader.
- `reason` `subfolder`: a file in a subfolder of an area saved without subfolders:
  assignment intro attachments, feedback's page after submit, lesson's media file.

Tests check that the two agree: whenever `atrisk` is not empty, a settings save without
changes is refused with `filesaltered` naming those files, and when it is empty the save
goes through (pages, assignments, feedback, lessons, workshops, labels). A caller should
refuse to propose a write to an activity with files at risk, and point to "Resolving it"
above.

## Journal and uninstalling

Every change is written to `local_paatshala_journal`: who, when, course, activity, kind,
target (ids and names), the values before and after, whether it can be undone, whether
it created the object, undo links and whether students were notified. Grade entries
(`assign_grade`) hold the students' grades and raw feedback comments, and text entries
(`module_content`) the activity text before and after, so undo can restore them.
Completion entries (`module_completion`) hold settings and data counts only. It never
holds submission content.

Uninstalling the plugin drops the table, and with it every undo. Export it first with
`local_paatshala_journal_list(courseid, limit=0)` for each course.

## Privacy

The journal stores the user who called each function and, for per-student changes
(assignment overrides and grades), the student's id (`relateduserid`). A grade entry also
holds the student's grade and feedback comment, and a team grade every member's. Each
student an entry holds data about is linked to it in `local_paatshala_journal_user`. The
privacy provider exports entries made by the user and entries about them. A deletion
request removes entries about that student (and their undo; a team grade entry goes as a
whole) and anonymises entries the user made (user id set to 0; undo kept).

## Live check of activity texts

`scripts/check_content.py` (Python 3.6+, standard library only) runs the 0.9.0 live check
through the `ws_automation` token and prints every raw JSON answer. It reads
`PAATSHALA_BASE_URL` and `PAATSHALA_WRITE_TOKEN` from the environment (as paatshala-mcp's
`.env`; never commit them), acts only on the cmids it is given, and stops before any write
unless each one is a hidden activity of course 237 (`--course` to change it).

```
python3 scripts/check_content.py edit --page <cmid> --label <cmid>
python3 scripts/check_content.py guard --page <cmid of the page with the oversized image>
python3 scripts/check_content.py cleanup --cmid <cmid> --cmid <cmid> ...
```

- `edit`: `get_content`; `update_content` on the page's content and intro (a marked
  paragraph appended) and the label's intro (the marker put first, so the label, named
  after the start of its text, is renamed), each with `expected` from `get_content`; files
  kept and no missing references after each; undo and redo of each entry; the label's
  name after every step; `journal_list` of the run's `module_content` entries. Exits
  non-zero unless the label is renamed by the edit, named back by undo and renamed again
  by redo.
- `guard`: refuses to write unless `get_content`'s `atrisk` lists a file; then
  `update_content` and `set_access` must both answer `filesaltered` (an unexpected success
  is undone at once), and `get_content` must show the same content files, still at risk.
- `cleanup`: `local_paatshala_delete_created` on each cmid, to the course recycle bin. A
  refusal (`studentdata`, or a cmid outside the course or visible) is printed and the next
  cmid is tried; the exit status is non-zero if any was not deleted.

## Live check of activity completion

`scripts/check_completion.py` (Python 3.6+, standard library only) runs the 0.10.0 live
check the same way: `PAATSHALA_BASE_URL` and `PAATSHALA_WRITE_TOKEN` from the environment,
every raw JSON answer printed, course 237 by default (`--course`), and it refuses courses
450 and 452. It creates its own two pages and touches nothing else.

```
python3 scripts/check_completion.py run --section-id 2985 --group <a group of course 237>
python3 scripts/check_completion.py cleanup --cmid <A> --cmid <B>
```

- `run`: refuses unless the section is hidden and unrestricted; creates hidden pages A
  and B (`create_activity`); `get_completion` on both; `set_completion` none -> manual on
  both, with `expected` from `get_completion`'s `state` (a page created with the site's
  default completion, `completiondefault`, is first set to none); `set_access` on B with
  the completion of A and the group, both hidden entirely; `get_availability` on B (both
  conditions, A named); `get_completion` on A (`referencedby` lists B; turning A's
  completion off is refused with `referenced`); `journal_list`; undo of every entry,
  newest first; checks that everything is back. Prints A and B for the cleanup and exits
  non-zero on any problem.
- `cleanup`: as in `check_content.py`, to the course recycle bin.

## Staging (moodle-docker at the 20200508 build)

Paatshala runs "3.8.2+ (Build: 20200508)", a weekly build on `MOODLE_38_STABLE`. CI tests
the head of `MOODLE_38_STABLE`, so check each phase on a copy at the exact build first.

1. Find the commit:
   ```
   git clone --branch MOODLE_38_STABLE https://github.com/moodle/moodle.git moodle38
   cd moodle38
   git log --format='%H %ad %s' --date=short --grep='weekly release 3.8.2+' | head -3
   ```
   The build is `fda378562d235754337305bbce39d5ac041247b4` (2020-05-08); its
   `version.php` says `$release = '3.8.2+ (Build: 20200508)'`.
   `git checkout fda378562d235754337305bbce39d5ac041247b4`.
2. Run it with [moodle-docker](https://github.com/moodlehq/moodle-docker):
   ```
   git clone https://github.com/moodlehq/moodle-docker.git
   cd moodle-docker
   export MOODLE_DOCKER_WWWROOT=/path/to/moodle38
   export MOODLE_DOCKER_DB=pgsql        # or mariadb, to match Paatshala
   export MOODLE_DOCKER_PHP_VERSION=7.4 # match Paatshala's PHP
   cp config.docker-template.php $MOODLE_DOCKER_WWWROOT/config.php
   bin/moodle-docker-compose up -d
   bin/moodle-docker-wait-for-db
   bin/moodle-docker-compose exec webserver php admin/cli/install_database.php \
       --agree-license --fullname="Staging" --shortname="staging" \
       --adminpass="test" --adminemail="admin@example.com"
   ```
3. Install the plugin: clone or unzip it into `moodle38/local/paatshala`, then
   `bin/moodle-docker-compose exec webserver php admin/cli/upgrade.php --non-interactive`.
4. Recreate the web service setup: enable web services and REST, create a
   `ws_automation` service and role like Paatshala's, add the plugin's functions and
   capabilities, create a token, and call the functions over REST.
5. Run the plugin's PHPUnit tests at that build:
   ```
   bin/moodle-docker-compose exec webserver php admin/tool/phpunit/cli/init.php
   bin/moodle-docker-compose exec webserver vendor/bin/phpunit --testsuite local_paatshala_testsuite
   ```

## Upgrading Moodle later

What depends on Moodle 3.8 internals, to re-check before upgrading Paatshala:

- Activity completion (0.10.0): `update_moduleinfo()`'s handling of `completionunlocked`
  and `reset_all_state()`, `set_moduleinfo_defaults()` (completionusegrade and
  completiongradeitemnumber), `moodleform_mod::standard_coursemodule_elements()` and
  `validation()` (what each form offers), the modules' `add_completion_rules()` and
  `data_postprocessing()` (forum, feedback), `quiz_process_options()`,
  `completion_info::delete_all_state()` (course completion records), and the
  availability_completion and availability_grade forms (`completion::RULES`,
  `kind\availability::check_conditions()`). Later Moodles add a "previous activity"
  completion condition (cm -1) and change the completion settings page.

- `classes/local/modform.php` relies on `get_moduleinfo_data()`, `update_moduleinfo()`,
  `moodleform_mod` (`set_data()`, `data_preprocessing()`, `validation()`,
  `data_postprocessing()`), `moodleform::$_form` (read by reflection) and on which keys
  `update_moduleinfo()`, `edit_module_post_actions()` and
  `tool_lp_coursemodule_edit_post_actions()` treat as optional. Run the no-op test
  (`tests/modform_test.php`) on the new version first.
- `workshop::switch_phase()` and the workshop cron task's automatic switch.
- Activity texts: `file_prepare_draft_area()` and `file_save_draft_area_files()` (which
  files they keep), the editors prepared by `get_moduleinfo_data()` and the page and
  workshop forms' `data_preprocessing()`, `page_update_instance()`,
  `workshop_update_instance()` and `label_update_instance()` / `get_label_name()`.
- Phase 4: `duplicate_module()` (copied, with the target course changed),
  `core_course_external::duplicate_course()` (followed), the restore setting
  `course_startdate`, `restore_fix_restorer_access_step`, `create_course()` and
  `course/edit.php`'s creator enrolment, `can_delete_course()`, `delete_course()`,
  `course_delete_module()`, `tool_recyclebin` `course_bin`/`category_bin` (`restore_item()`
  and how items are stored), and the tables `classes/local/studentdata.php` counts.
- `classes/local/kind/assign_override.php` mirrors `mod/assign/overrideedit.php`,
  `overridedelete.php` and `assign_override_form` (constructed for its `validation()`),
  and calls `assign::delete_override()`, `move_group_override()`,
  `reorder_group_overrides()` and `assign_update_events()`. If a later Moodle adds an
  override API, switch to it.
- `moveto_module()` (and its visibility rules), `move_section_to()`,
  `course_create_section()`, `course_update_section()`, `course_can_delete_section()` and
  `course_delete_section()` in `course/lib.php`; the checks copied from `course/rest.php`,
  `course/changenumsections.php` and `course/editsection.php`.
- External functions use the 3.8 `external_api` classes in `lib/externallib.php` (moved
  to `core_external` in 4.2, with aliases).

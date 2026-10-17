# local_paatshala

A Moodle 3.8 local plugin that adds the web service functions the
[paatshala-mcp](https://github.com/H4R335HR/paatshala-mcp) server needs and Moodle 3.8
lacks. Every write has a matching read, returns the state it replaced, can refuse on a
stale snapshot, is journaled, and can be undone.

Release 0.7.1 (2026101401). All phases of the brief (dates, workshop phase, reads,
journal and undo; activity and section order; new sections; assignment overrides; copying
and creating activities and courses, deleting what the plugin created, the recycle bin;
assignment settings, workshop and quiz dates), and grading an assignment with or without
notifying the student (0.7.0).

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
`mod/assign:view`, `mod/workshop:view`).

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
| `local_paatshala_journal_list` | `local/paatshala:use` (course) |
| `local_paatshala_journal_get` | `local/paatshala:use` (course of the entry) |
| `local_paatshala_undo` | `local/paatshala:use` (course), plus the capabilities of the function that made the entry |

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
  from the message. Unknown keys in `expected` are refused as invalid parameters.
- **Result:** `{journalid, before, after, undoable, notifies_students, warnings[]}`.
  `before` and `after` are JSON strings with the fields the call set; `after` is read back
  from the database after the write. Times are unix timestamps; 0 means disabled.
- **Nothing changed:** if the fields already held the values given, the write is rolled
  back (no `timemodified`, calendar or event side effects) and nothing is journaled: the
  result has `journalid` 0, `undoable` false, `before` equal to `after` and a warning
  with code `nochange`. This includes an undo that would change nothing; its entry stays
  not undone.
- **Values Moodle did not save:** the settings functions (assignment settings, workshop
  and quiz dates) read every value back and refuse with errorcode `notsaved`, naming the
  field and the reason, if one was not stored as given; the whole call is rolled back.
- **Validation:** the plugin refuses what the settings page would refuse (errorcode
  `invalidsettings`, with the form's messages).
- **Transactions:** each change and its journal entry are written in one delegated
  transaction; a refusal leaves nothing behind.

## Functions

### local_paatshala_info

Read-only. Returns the plugin version and release, Moodle version and release,
`enabled`, `allowedcourses`, `allowedcategories`, the list of plugin functions, and the
recycle bin settings (`tool_recyclebin` `coursebinenable`, `coursebinexpiry`,
`categorybinenable`, `categorybinexpiry`). Answers while the plugin is disabled.

### local_paatshala_get_module_settings(cmid)

Read-only. For every activity: `cmid`, `courseid`, `modname`, `name`, `visible`,
`sectionnum`, `position` (0-based within the section). For assignments also
`allowsubmissionsfromdate`, `duedate`, `cutoffdate`, `gradingduedate`, `grade`; for
workshops `phase`, `submissionstart`, `submissionend`, `assessmentstart`, `assessmentend`,
`phaseswitchassessment`; for quizzes `timeopen`, `timeclose`. Field names match the write
inputs, so a result can be passed as `expected`.

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
- it holds no student data (errorcode `studentdata`, with counts in the message):
  - activities: any final grade (`grade_grades.finalgrade`) on its grade items; any
    completion state other than incomplete; and per type: assignment submissions not in
    status "new", quiz attempts, workshop submissions and their assessments (not
    examples), forum posts, feedback responses (saved and unsaved), choice answers,
    database records, glossary entries, lesson attempts and grades, SCORM tracks. Page,
    URL, label, file, folder, book and IMS content package have none of their own. Any
    other type is refused (`unknownmodule`): the plugin cannot tell.
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
  Refused with the reason (errorcode `fieldexcluded`): the description and its files
  (embedded files cannot be restored reliably by undo), the maximum grade (can rescale
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

### local_paatshala_journal_list(courseid, since=0, kind='', limit=100)

Journal entries of a course, newest first: `id`, `timecreated`, `userid`, `courseid`,
`cmid`, `kind`, `target`, `before`, `after` (JSON strings), `undoable`,
`createdbyplugin`, `undoof`, `undoneby`, `notifies`. `limit` 0 returns all.

### local_paatshala_journal_get(id)

One journal entry, same fields.

### local_paatshala_undo(journalid, expected?)

Applies the inverse of a journal entry through the same code path, gate and capability
checks as the original function, journals it with `undoof` set and sets `undoneby` on the
original.

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

## Journal and uninstalling

Every change is written to `local_paatshala_journal`: who, when, course, activity, kind,
target (ids and names), the values before and after, whether it can be undone, whether
it created the object, undo links and whether students were notified. Grade entries
(`assign_grade`) hold the students' grades and raw feedback comments, so undo can restore
them. It never holds submission content or activity descriptions.

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

- `classes/local/modform.php` relies on `get_moduleinfo_data()`, `update_moduleinfo()`,
  `moodleform_mod` (`set_data()`, `data_preprocessing()`, `validation()`,
  `data_postprocessing()`), `moodleform::$_form` (read by reflection) and on which keys
  `update_moduleinfo()`, `edit_module_post_actions()` and
  `tool_lp_coursemodule_edit_post_actions()` treat as optional. Run the no-op test
  (`tests/modform_test.php`) on the new version first.
- `workshop::switch_phase()` and the workshop cron task's automatic switch.
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

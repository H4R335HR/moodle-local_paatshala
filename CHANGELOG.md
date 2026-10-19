# Changelog

## 0.9.0 (2026101901) - Student data means students' data

- Version bump only, release unchanged, so a site already on 0.9.0 (2026101900) upgrades to
  this build. It carries the student-data rule for deleting what the plugin created (below,
  last item of 2026101900): rows of staff (a teacher or admin viewing a plugin-created page,
  a teacher's quiz preview or test post) no longer block `local_paatshala_delete_created`
  or the undo of a copy or creation; any student's still do. Also `check_content.py`'s
  cleanup and label fixes. No database, capability or function changes: the upgrade has no
  steps.

## 0.9.0 (2026101900) - Activity texts

- `local_paatshala_get_content`: an activity's text fields as stored (description; page
  content; workshop instructions for submission and assessment and conclusion), each with
  its format, its file area's files, the files it refers to through `@@PLUGINFILE@@` and
  the references with no file.
- `local_paatshala_update_content`: change one text field and its format through the
  settings form path, keeping the field's draft area so its files stay as they are. A new
  reference to a file that is not there is refused; dropping a reference needs
  `dropreferences` (`dropsreference` otherwise; the file is kept). Text and format are read
  back byte for byte (`notsaved`), and any change to the activity's files rolls the call
  back (`filesaltered`). Journaled (`module_content`) with the file list; undo is refused
  unless text, format and files are still the entry's after. File lists are
  `{filepath, filename, contenthash}` compared as sets (any order, duplicates once).
- Labels: the journal's before and after hold the label's name, which Moodle derives from
  its text; undo restores the exact old name and redo the new one, and a label renamed or
  edited by hand since is a conflict. The new name is reported (`namechanged`).
- `expected` may hold a list where a function takes one (`files`).
- Settings helper: `modform::save()` can replace an editor's text and format and keep its
  draft area.
- Settings helper guard, for every write through it (assignment dates and settings,
  workshop and quiz dates, access restrictions of activities, activity texts) and their
  undo and redo: the files of the activity's text and attachment areas and of every area
  the form prepared, and the text behind every editor, are compared after the save. A
  file removed, replaced or added, or a text the call did not ask to change, rolls the
  whole call back with errorcode `filesaltered`, naming the files. Moodle's
  `file_save_draft_area_files()` drops a page content file above the site upload limit
  when its uploader may not ignore size limits, and files in subfolders of areas saved
  without them (assignment intro attachments); the settings page does so silently. Quiz
  overall feedback files copied to new item ids on every save are not a change. The guard
  also covers the areas where a save can drop files and the areas named after the form's
  editors and file managers, so an area whose every file the form skipped (feedback's page
  after submit, a lesson's media file, with their files in a subfolder) is guarded too.
- `atrisk` in `local_paatshala_get_content` and `local_paatshala_get_module_settings`:
  every file a settings-form save of the activity would drop (`size`: a page content file
  above the site's maximum upload size whose uploader may not ignore size limits;
  `subfolder`: a file in a subfolder of an area saved without them). Read-only; tests check
  it agrees with the guard.
- Settings helper: loads `gradelib.php`, `completionlib.php` and `plagiarismlib.php` as
  `course/modedit.php` does. Without them, any write through the helper to an activity whose
  own library does not load gradelib (a page, a label) failed over REST with "Class
  'grade_item' not found" (`update_moduleinfo()`, course/modlib.php:602). This affected
  `local_paatshala_set_access` on such activities since 0.8.0. Found with the new live
  check script on a local site; PHPUnit loads gradelib anyway, so the tests could not show it.
- `scripts/check_content.py`: the live check of activity texts and the file guard. Its
  cleanup carries on after a refused delete and exits non-zero; its edit puts the label's
  marker first, so the label is renamed and undo and redo rename it back and forth.
- Student data means students' data (`local_paatshala_delete_created` and the undo of
  `copy_activity` / `create_activity`): only rows of users who count as students block a
  delete. Enrolled users count when they have `moodle/course:isincompletionreports` (the
  rule of Moodle's completion reports), suspended ones included; users not enrolled count
  unless they have `moodle/course:manageactivities` (admins, category-level staff); rows
  with no user count. Found on Paatshala: a teacher opening a plugin-created page wrote a
  "viewed" completion row, which made it undeletable.
- `local_paatshala_update_assign_settings` still refuses the description, now naming
  `local_paatshala_update_content`.

## 0.8.0 (2026101701) - Phase 6: group and date access restrictions

- `local_paatshala_get_availability`, `local_paatshala_get_course_availability`: access
  restrictions of a section or activity, or of a whole course, as plain data (raw tree,
  whether it parses, conditions, groups, which condition plugins are on); a tree Moodle
  cannot read is reported, not fatal.
- `local_paatshala_set_access`: set or remove the group restriction (Restrict access >
  Group) and the "from" and "until" dates (Restrict access > Date), each hidden entirely
  or shown with the message, keeping every other restriction; all parts in one write and
  one journal entry. "until" must be after "from"; a passed "until" gives warning
  `untilpassed`. Through `course_update_section()` for sections and the settings helper
  for activities. Journaled (`section_availability`, `module_availability`), undoable.
- `local_paatshala_info` reports `enableavailability` and the enabled condition plugins.
- Settings helper: module completion rule fields (forum discussions, replies, posts) are
  post-processed as a browser submission with completion unlocked, so a save no longer
  turns a 0 rule into the form's display default 1; the course module's completion
  settings are still left alone.

## 0.7.1 (2026101401) - Chained grade undo

- `assign_grade` undo compares the grade's timemodified with the newest journal entry for
  that activity and student (an undo included) instead of the undone entry's `after`.
  Undoing two grades newest first was refused (found on Paatshala: #115 after undoing
  #116), because the first undo's own save moved timemodified. A grade saved outside the
  plugin in between is still refused. Values (grade, comment, format, workflow state,
  attempt) still have to be the entry's `after`; team grades are checked per member.

## 0.7.0 (2026101400) - Grading without notifying

- `local_paatshala_assign_save_grade`: the latest attempt's grade, feedback comment and
  marking workflow state through `assign::save_grade()`, with the grading form's "Notify
  students" switch (`notify`). The comment is stored as given; without one the current
  comment is kept. Team grades (`applytoall`). A notice still waiting for cron is reported
  (`pendingnotification`) or cancelled on request (`cancelpending`). Journaled; undo
  restores every student's grade, comment and state without notifying.
- Journal entries are linked to every student they hold data about
  (`local_paatshala_journal_user`, filled from `relateduserid` on upgrade); the privacy
  provider uses the links.
- `invalidsettings` now reads "The settings or grading form would refuse this".

## 0.6.3 (2026101303) - Unchanged settings of a type that is off

- `local_paatshala_update_assign_settings`: a setting of a submission or feedback type
  that is off is refused with `notsaved` only when it is asked to change. Given at its
  current value it is left alone, so echoing the current settings back returns
  `nochange` instead of `notsaved` (found live on a file-only assignment with the word
  limit fields at 0/0).

## 0.6.2 (2026101302) - Assignment settings in get_module_settings

- `local_paatshala_get_module_settings` also returns an assignment's settings under the
  field names `local_paatshala_update_assign_settings` takes (its whitelist, read the same
  way), so a caller can snapshot them; `mod_assign_get_assignments` does not return
  courses the web service user is not enrolled in. Read-only, no new capability.

## 0.6.1 (2026101301) - Settings that Moodle did not save

- A write that leaves every field as it was is rolled back and not journaled: the result
  has `journalid` 0 and a `nochange` warning (all write functions, and undo).
- The settings functions read every value back and refuse with errorcode `notsaved`, and
  roll back, when Moodle did not store a value as given. Found on Paatshala: a word limit
  on an assignment with online text off returned success and journaled a no-op, because
  Moodle saves a submission or feedback type's settings only while the type is on.
- `local_paatshala_update_assign_settings`: names the reason (type off: include its
  `_enabled: 1` in the same call; word limit and its switch; type disabled for the site,
  Annotate PDF without Ghostscript, offline worksheet with advanced grading). Both word
  limit fields are journaled when either is given, so undo restores the limit after the
  switch is turned off; an undo that turns a type off puts its settings back as they were.

## 0.6.0 (2026101300) - Phase 5: settings edits

- `local_paatshala_update_assign_settings`: whitelisted assignment settings (name,
  submission and feedback types and limits, submission statement, attempts) through the
  settings form path; description, maximum grade, group submission, marking workflow and
  dates are refused with the reason.
- `local_paatshala_workshop_set_dates` and `local_paatshala_quiz_set_dates` through the
  settings form path.
- Settings helper: runs the form's `definition_after_data()` before export, validates
  before post-processing (as the form does), leaves unchecked plain checkboxes out (as a
  browser does; in this build a quiz review option left in as 0 would be switched on),
  and gives editors without a value a format. No-op tests for a quiz and a workshop.

## 0.5.0 (2026101104) - Phase 4: creating things, and the recycle bin

- `local_paatshala_copy_activity`: one-activity backup and restore without user data, into
  any allowed course; arrives hidden; keeps rubrics and workshop assessment forms.
- `local_paatshala_create_activity`: assign, page, url, label through the settings form's
  add path, with a field whitelist per type; hidden by default.
- `local_paatshala_create_course` and `local_paatshala_duplicate_course` (dates moved to
  the new start date); hidden.
- `local_paatshala_delete_created`: deletes only what the journal shows the plugin
  created, only without student data, only with the recycle bin on.
- `local_paatshala_recyclebin_list`, `local_paatshala_recyclebin_restore`; restoring is
  journaled and undoable.
- The activity settings helper also checks the form's element rules (required,
  maxlength...) and posts empty text fields as a browser does.

## 0.4.0 (2026101103) - Phase 3: assignment overrides

- `local_paatshala_assign_get_overrides`.
- `local_paatshala_assign_save_override`: mirrors `mod/assign/overrideedit.php` (no API
  in 3.8), validation by `assign_override_form::validation()`, eligible users and groups
  as the page offers them, group priority through `move_group_override()`.
- `local_paatshala_assign_delete_override`: `assign::delete_override()` and
  `reorder_group_overrides()`; undo recreates the override at the same priority.
- Journal `relateduserid` holds the student of a user override (privacy API).

## 0.3.0 (2026101102) - Phase 2: order and sections

- `local_paatshala_move_module` (`moveto_module()`, checks as `course/rest.php`); the
  journal records visibility, which the move can change, and undo restores it. Undo is
  refused if the old neighbour moved or is gone.
- `local_paatshala_move_section` (`move_section_to()`, `moodle/course:movesections`).
- `local_paatshala_create_section` (`course_create_section()`, checks as
  `course/changenumsections.php`); undo deletes the section only while it is empty.
- Executor hooks for kinds: `expected_fields()`, `check_undo()`, `journal_keys()`,
  `createdbyplugin()`.

## 0.2.0 (2026101101) - Phase 1: dates, workshop phase, reads, undo

- Load-merge-save helper for activity settings (`classes/local/modform.php`), with a
  no-op test on a richly configured assignment.
- `local_paatshala_get_module_settings`.
- `local_paatshala_assign_set_dates` (settings form path, form validation, calendar
  events move).
- `local_paatshala_workshop_switch_phase` (`workshop::switch_phase()`), with warnings for
  grades pushed on closing and the automatic switch to assessment.
- `local_paatshala_journal_list`, `local_paatshala_journal_get`, `local_paatshala_undo`
  (undo and redo with compare-and-set).
- Compare-and-set (`expected`) and the common write result on every write.

## 0.1.0 (2026101100) - Phase 0: skeleton

- Plugin skeleton for Moodle 3.8 (requires 2019111800).
- Admin settings `local_paatshala/enabled` (default off), `allowedcourses` and
  `allowedcategories` (comma-separated ids, `*`, or empty for none).
- Capability `local/paatshala:use` (no default archetypes).
- Journal table `local_paatshala_journal` and privacy provider.
- `local_paatshala_info`.
- GitHub Actions with moodle-plugin-ci 3.4.14: MOODLE_38_STABLE on PHP 7.1 +
  PostgreSQL and PHP 7.4 + MariaDB.

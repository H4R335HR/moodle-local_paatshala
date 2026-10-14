# Changelog

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

# Changelog

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

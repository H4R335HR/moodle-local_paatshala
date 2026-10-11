# local_paatshala

A Moodle 3.8 local plugin that adds the web service functions the
[paatshala-mcp](https://github.com/H4R335HR/paatshala-mcp) server needs and Moodle 3.8
lacks. Every write has a matching read, returns the state it replaced, can refuse on a
stale snapshot, is journaled, and can be undone.

Status: phase 2 (dates, workshop phase, reads, journal and undo; activity and section order;
new sections).

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
   elements are lossy (date selectors drop the seconds).
5. The change is applied, then the form's `validation()` runs.
6. `update_moduleinfo()` saves it.

Tags, competencies, the competency rule, the grade to pass, the grade category, outcomes,
the advanced grading method and the completion lock flag are left out of the saved data.
`update_moduleinfo()` and its callbacks only touch those when they are present, so they
stay exactly as they were. A test saves a richly configured assignment without changes
and checks that `course_modules`, `assign`, `assign_plugin_config`, `grade_items`,
`event`, `files`, `tag_instance`, `competency_modulecomp`, grading areas and definitions
and availability are unchanged apart from `timemodified`. One exception is Moodle's own:
`assign::update_calendar()` saves module events with `userid` 0 on every settings save.

## Journal and uninstalling

Every change is written to `local_paatshala_journal`: who, when, course, activity, kind,
target (ids and names), the values before and after, whether it can be undone, whether
it created the object, undo links and whether students were notified. It never holds
submission content, feedback text or activity descriptions.

Uninstalling the plugin drops the table, and with it every undo. Export it first with
`local_paatshala_journal_list(courseid, limit=0)` for each course.

## Privacy

The journal stores the user who called each function and, for per-student changes
(assignment overrides, phase 3), the student's id (`relateduserid`). The privacy
provider exports both. A deletion request removes entries about that student (and their
undo) and anonymises entries the user made (user id set to 0; undo kept).

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
- `moveto_module()` (and its visibility rules), `move_section_to()`,
  `course_create_section()`, `course_update_section()`, `course_can_delete_section()` and
  `course_delete_section()` in `course/lib.php`; the checks copied from `course/rest.php`,
  `course/changenumsections.php` and `course/editsection.php`.
- External functions use the 3.8 `external_api` classes in `lib/externallib.php` (moved
  to `core_external` in 4.2, with aliases).

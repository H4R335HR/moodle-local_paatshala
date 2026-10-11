# local_paatshala

A Moodle 3.8 local plugin that adds the web service functions the
[paatshala-mcp](https://github.com/H4R335HR/paatshala-mcp) server needs and Moodle 3.8
lacks. Every write has a matching read, returns the state it replaced, can refuse on a
stale snapshot, is journaled, and can be undone.

Status: phase 0 (skeleton). Only `local_paatshala_info` exists so far.

## Requirements

- Moodle 3.8 (built and checked against 3.8.2+ (Build: 20200508)).
- PHP 7.1 to 7.4; PostgreSQL or MySQL/MariaDB.

## Install

1. Upload the zip through Site administration > Plugins > Install plugins, or clone this
   repository into `local/paatshala` in the Moodle directory:
   `git clone https://github.com/H4R335HR/moodle-local_paatshala.git local/paatshala`
2. Visit Site administration > Notifications (or run `php admin/cli/upgrade.php`).

## Settings

Site administration > Plugins > Local plugins > Paatshala web services:

| Setting | Default | Meaning |
| --- | --- | --- |
| `local_paatshala/enabled` | off | Nothing but `local_paatshala_info` runs while off. |
| `local_paatshala/allowedcourses` | empty | Course ids the plugin may act on, comma-separated, or `*` for all. Empty means none. The front page is never allowed. |
| `local_paatshala/allowedcategories` | empty | Category ids where courses may be created, comma-separated, or `*`. Empty means none. |

## Web service setup

Add the functions to the existing `ws_automation` external service (Site administration >
Server > Web services > External services > ws_automation > Functions), and give the
`ws_automation` role the capabilities below.

| Function | Capabilities (context) |
| --- | --- |
| `local_paatshala_info` | `local/paatshala:use` (system) |

`local/paatshala:use` has no default archetypes. Allow it on the `ws_automation` role
(system context role definition). It is checked in the course context for course
functions, the category context for course creation and the system context for
`local_paatshala_info`.

## Functions

### local_paatshala_info

Read-only. Returns the plugin version and release, Moodle version, `enabled`,
`allowedcourses`, `allowedcategories`, the list of plugin functions, and the recycle bin
settings (`tool_recyclebin` `coursebinenable`, `coursebinexpiry`, `categorybinenable`,
`categorybinexpiry`). Answers while the plugin is disabled.

## Journal and uninstalling

Every change is written to `local_paatshala_journal`, the record undo works from.
Uninstalling the plugin drops the table, and with it every undo. Export it first with
`local_paatshala_journal_list` (phase 1) per course.

## Privacy

The journal stores the user who called each function and, for per-student changes
(assignment overrides), the student's id. The privacy provider exports both, deletes
entries about a student on request, and anonymises entries a user made (user id set to
0, undo kept).

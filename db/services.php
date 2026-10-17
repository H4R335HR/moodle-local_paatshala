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
 * Web service functions of local_paatshala.
 *
 * No service is defined here: the site admin adds the functions to the existing
 * ws_automation external service.
 *
 * @package    local_paatshala
 * @copyright  2026 local_paatshala contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'local_paatshala_info' => [
        'classname' => 'local_paatshala\external\info',
        'methodname' => 'execute',
        'description' => 'Plugin version, settings, functions and recycle bin settings.',
        'type' => 'read',
        'capabilities' => 'local/paatshala:use',
    ],
    'local_paatshala_get_module_settings' => [
        'classname' => 'local_paatshala\external\get_module_settings',
        'methodname' => 'execute',
        'description' => 'Settings of an activity that the plugin\'s write functions take (dates, phase, position).',
        'type' => 'read',
        'capabilities' => 'local/paatshala:use, moodle/course:manageactivities',
    ],
    'local_paatshala_assign_set_dates' => [
        'classname' => 'local_paatshala\external\assign_set_dates',
        'methodname' => 'execute',
        'description' => 'Change assignment dates through the settings form path. Journaled, undoable.',
        'type' => 'write',
        'capabilities' => 'local/paatshala:use, moodle/course:manageactivities',
    ],
    'local_paatshala_workshop_switch_phase' => [
        'classname' => 'local_paatshala\external\workshop_switch_phase',
        'methodname' => 'execute',
        'description' => 'Switch a workshop phase with workshop::switch_phase(). Journaled, undoable.',
        'type' => 'write',
        'capabilities' => 'local/paatshala:use, mod/workshop:switchphase',
    ],
    'local_paatshala_journal_list' => [
        'classname' => 'local_paatshala\external\journal_list',
        'methodname' => 'execute',
        'description' => 'Journal entries of a course, newest first.',
        'type' => 'read',
        'capabilities' => 'local/paatshala:use',
    ],
    'local_paatshala_journal_get' => [
        'classname' => 'local_paatshala\external\journal_get',
        'methodname' => 'execute',
        'description' => 'One journal entry.',
        'type' => 'read',
        'capabilities' => 'local/paatshala:use',
    ],
    'local_paatshala_undo' => [
        'classname' => 'local_paatshala\external\undo',
        'methodname' => 'execute',
        'description' => 'Undo (or redo) a journal entry if the target still has the entry\'s after-state.',
        'type' => 'write',
        'capabilities' => 'local/paatshala:use',
    ],
    'local_paatshala_move_module' => [
        'classname' => 'local_paatshala\external\move_module',
        'methodname' => 'execute',
        'description' => 'Move an activity to a section, before another activity, with moveto_module(). ' .
            'Journaled, undoable.',
        'type' => 'write',
        'capabilities' => 'local/paatshala:use, moodle/course:manageactivities',
    ],
    'local_paatshala_move_section' => [
        'classname' => 'local_paatshala\external\move_section',
        'methodname' => 'execute',
        'description' => 'Move a course section with move_section_to(). Journaled, undoable.',
        'type' => 'write',
        'capabilities' => 'local/paatshala:use, moodle/course:movesections',
    ],
    'local_paatshala_create_section' => [
        'classname' => 'local_paatshala\external\create_section',
        'methodname' => 'execute',
        'description' => 'Create a course section with course_create_section(). Journaled; undo deletes it ' .
            'while empty.',
        'type' => 'write',
        'capabilities' => 'local/paatshala:use, moodle/course:update, moodle/course:movesections',
    ],
    'local_paatshala_assign_get_overrides' => [
        'classname' => 'local_paatshala\external\assign_get_overrides',
        'methodname' => 'execute',
        'description' => 'User and group overrides of an assignment.',
        'type' => 'read',
        'capabilities' => 'local/paatshala:use, mod/assign:manageoverrides',
    ],
    'local_paatshala_assign_save_override' => [
        'classname' => 'local_paatshala\external\assign_save_override',
        'methodname' => 'execute',
        'description' => 'Add or change an assignment user or group override, as ' .
            'mod/assign/overrideedit.php. Journaled, undoable.',
        'type' => 'write',
        'capabilities' => 'local/paatshala:use, mod/assign:manageoverrides',
    ],
    'local_paatshala_assign_save_grade' => [
        'classname' => 'local_paatshala\external\assign_save_grade',
        'methodname' => 'execute',
        'description' => 'Grade the latest attempt of an assignment through assign::save_grade(), with the ' .
            'grading form\'s "Notify students" switch. Journaled, undoable (undo never notifies).',
        'type' => 'write',
        'capabilities' => 'local/paatshala:use, mod/assign:grade',
    ],
    'local_paatshala_assign_delete_override' => [
        'classname' => 'local_paatshala\external\assign_delete_override',
        'methodname' => 'execute',
        'description' => 'Delete an assignment override with assign::delete_override(). Journaled; undo recreates it.',
        'type' => 'write',
        'capabilities' => 'local/paatshala:use, mod/assign:manageoverrides',
    ],
    'local_paatshala_copy_activity' => [
        'classname' => 'local_paatshala\\external\\copy_activity',
        'methodname' => 'execute',
        'description' => 'Copy one activity without user data into a course (backup and restore, as ' .
            'Duplicate and Import). Arrives hidden. Undo deletes it.',
        'type' => 'write',
        'capabilities' => 'local/paatshala:use, moodle/course:manageactivities, ' .
            'moodle/backup:backuptargetimport, moodle/restore:restoretargetimport',
    ],
    'local_paatshala_create_activity' => [
        'classname' => 'local_paatshala\\external\\create_activity',
        'methodname' => 'execute',
        'description' => 'Create an assignment, page, URL or label through the settings form path. Hidden ' .
            'by default. Undo deletes it.',
        'type' => 'write',
        'capabilities' => 'local/paatshala:use, moodle/course:manageactivities',
    ],
    'local_paatshala_create_course' => [
        'classname' => 'local_paatshala\\external\\create_course',
        'methodname' => 'execute',
        'description' => 'Create a hidden course with create_course(). Undo deletes it.',
        'type' => 'write',
        'capabilities' => 'local/paatshala:use, moodle/course:create',
    ],
    'local_paatshala_duplicate_course' => [
        'classname' => 'local_paatshala\\external\\duplicate_course',
        'methodname' => 'execute',
        'description' => 'Copy a course without user data, dates moved to a new start date. Hidden. Undo deletes it.',
        'type' => 'write',
        'capabilities' => 'local/paatshala:use, moodle/course:create, moodle/restore:restorecourse, ' .
            'moodle/restore:rolldates, moodle/backup:backupcourse',
    ],
    'local_paatshala_delete_created' => [
        'classname' => 'local_paatshala\\external\\delete_created',
        'methodname' => 'execute',
        'description' => 'Delete an activity or course the plugin created, if it holds no student data, ' .
            'through the recycle bin.',
        'type' => 'write',
        'capabilities' => 'local/paatshala:use, moodle/course:manageactivities, moodle/course:delete',
    ],
    'local_paatshala_recyclebin_list' => [
        'classname' => 'local_paatshala\\external\\recyclebin_list',
        'methodname' => 'execute',
        'description' => 'Items in a course or category recycle bin.',
        'type' => 'read',
        'capabilities' => 'local/paatshala:use, tool/recyclebin:viewitems',
    ],
    'local_paatshala_recyclebin_restore' => [
        'classname' => 'local_paatshala\\external\\recyclebin_restore',
        'methodname' => 'execute',
        'description' => 'Restore a recycle bin item with tool_recyclebin. Undo deletes it again.',
        'type' => 'write',
        'capabilities' => 'local/paatshala:use, tool/recyclebin:restoreitems',
    ],
    'local_paatshala_update_assign_settings' => [
        'classname' => 'local_paatshala\\external\\update_assign_settings',
        'methodname' => 'execute',
        'description' => 'Change whitelisted assignment settings (name, submission and feedback types and limits, ' .
            'submission statement, attempts) through the settings form path. Journaled, undoable.',
        'type' => 'write',
        'capabilities' => 'local/paatshala:use, moodle/course:manageactivities',
    ],
    'local_paatshala_workshop_set_dates' => [
        'classname' => 'local_paatshala\\external\\workshop_set_dates',
        'methodname' => 'execute',
        'description' => 'Change workshop submission and assessment dates through the settings form path. Journaled, ' .
            'undoable.',
        'type' => 'write',
        'capabilities' => 'local/paatshala:use, moodle/course:manageactivities',
    ],
    'local_paatshala_quiz_set_dates' => [
        'classname' => 'local_paatshala\\external\\quiz_set_dates',
        'methodname' => 'execute',
        'description' => 'Change quiz open and close dates through the settings form path. Journaled, undoable.',
        'type' => 'write',
        'capabilities' => 'local/paatshala:use, moodle/course:manageactivities',
    ],
];

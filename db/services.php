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
        'description' => 'Move an activity to a section, before another activity, with moveto_module(). Journaled, undoable.',
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
        'description' => 'Create a course section with course_create_section(). Journaled; undo deletes it while empty.',
        'type' => 'write',
        'capabilities' => 'local/paatshala:use, moodle/course:update, moodle/course:movesections',
    ],
];

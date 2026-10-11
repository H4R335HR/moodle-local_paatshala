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
 * local_paatshala_journal_get.
 *
 * @package    local_paatshala
 * @copyright  2026 local_paatshala contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_paatshala\external;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/externallib.php');

use external_api;
use external_function_parameters;
use external_single_structure;
use external_value;
use local_paatshala\gate;
use local_paatshala\journal;

/**
 * local_paatshala_journal_get: one journal entry.
 *
 * @package    local_paatshala
 * @copyright  2026 local_paatshala contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class journal_get extends external_api {

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'id' => new external_value(PARAM_INT, 'Journal entry id'),
        ]);
    }

    /**
     * Get an entry.
     *
     * @param int $id
     * @return array
     */
    public static function execute($id): array {
        $params = self::validate_parameters(self::execute_parameters(), ['id' => $id]);
        gate::require_enabled();
        $entry = journal::get($params['id']);
        gate::course((int)$entry->courseid);
        return journal::export($entry);
    }

    /**
     * Return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return self::entry_structure();
    }

    /**
     * Structure of a journal entry.
     *
     * @return external_single_structure
     */
    public static function entry_structure(): external_single_structure {
        return new external_single_structure([
            'id' => new external_value(PARAM_INT, 'Journal id'),
            'timecreated' => new external_value(PARAM_INT, 'When'),
            'userid' => new external_value(PARAM_INT, 'Who called the function (0 if anonymised)'),
            'courseid' => new external_value(PARAM_INT, 'Course'),
            'cmid' => new external_value(PARAM_INT, 'Course module, if any', VALUE_OPTIONAL),
            'kind' => new external_value(PARAM_ALPHANUMEXT, 'Kind of change'),
            'target' => new external_value(PARAM_RAW, 'JSON: ids and names of what was changed'),
            'before' => new external_value(PARAM_RAW, 'JSON: values replaced'),
            'after' => new external_value(PARAM_RAW, 'JSON: values written'),
            'undoable' => new external_value(PARAM_BOOL, 'Can be undone'),
            'createdbyplugin' => new external_value(PARAM_BOOL, 'This change created the object'),
            'undoof' => new external_value(PARAM_INT, 'Journal id this entry undid', VALUE_OPTIONAL),
            'undoneby' => new external_value(PARAM_INT, 'Journal id of the entry that undid this one', VALUE_OPTIONAL),
            'notifies' => new external_value(PARAM_BOOL, 'Moodle notified students'),
        ]);
    }
}

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
 * local_paatshala_journal_list.
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
use external_multiple_structure;
use external_single_structure;
use external_value;
use local_paatshala\gate;
use local_paatshala\journal;

/**
 * local_paatshala_journal_list: journal entries of a course, newest first.
 *
 * @package    local_paatshala
 * @copyright  2026 local_paatshala contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class journal_list extends external_api {

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course id'),
            'since' => new external_value(PARAM_INT, 'Only entries created at or after this time', VALUE_DEFAULT, 0),
            'kind' => new external_value(PARAM_ALPHANUMEXT, 'Only entries of this kind', VALUE_DEFAULT, ''),
            'limit' => new external_value(PARAM_INT, 'Maximum entries, 0 for all', VALUE_DEFAULT, 100),
        ]);
    }

    /**
     * List entries.
     *
     * @param int $courseid
     * @param int $since
     * @param string $kind
     * @param int $limit
     * @return array
     */
    public static function execute($courseid, $since = 0, $kind = '', $limit = 100): array {
        $params = self::validate_parameters(self::execute_parameters(),
            ['courseid' => $courseid, 'since' => $since, 'kind' => $kind, 'limit' => $limit]);
        gate::course($params['courseid']);
        $entries = [];
        foreach (journal::entries($params['courseid'], $params['since'], $params['kind'], max(0, $params['limit'])) as $r) {
            if (journal::visible($r)) {
                $entries[] = journal::export($r);
            }
        }
        return ['entries' => $entries];
    }

    /**
     * Return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'entries' => new external_multiple_structure(journal_get::entry_structure()),
        ]);
    }
}

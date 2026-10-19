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
 * local_paatshala_update_content.
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
use local_paatshala\local\executor;
use local_paatshala\local\kind\module_content as handler;

/**
 * local_paatshala_update_content: change one text field of an activity, keeping its files.
 *
 * @package    local_paatshala
 * @copyright  2026 local_paatshala contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class update_content extends external_api {

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module id'),
            'field' => new external_value(PARAM_ALPHANUMEXT,
                'intro (description); page: content; workshop: instructauthors, instructreviewers, conclusion'),
            'text' => new external_value(PARAM_RAW,
                'New text, stored as given; the activity\'s files are referred to as @@PLUGINFILE@@/<name>'),
            'format' => new external_value(PARAM_INT, '0 moodle, 1 html, 2 plain, 4 markdown'),
            'expected' => new external_value(PARAM_RAW,
                'JSON object with some of text, format and files (as local_paatshala_get_content returns them): ' .
                'refuse with errorcode conflict unless the current values match', VALUE_DEFAULT, null),
            'dropreferences' => new external_value(PARAM_BOOL,
                'Allow the new text to stop referring to files it referred to (the files are kept either way)',
                VALUE_DEFAULT, false),
        ]);
    }

    /**
     * Change the text.
     *
     * @param int $cmid
     * @param string $field
     * @param string $text
     * @param int $format
     * @param string|null $expected
     * @param bool $dropreferences
     * @return array
     */
    public static function execute($cmid, $field, $text, $format, $expected = null, $dropreferences = false): array {
        $params = self::validate_parameters(self::execute_parameters(), ['cmid' => $cmid, 'field' => $field,
            'text' => $text, 'format' => $format, 'expected' => $expected, 'dropreferences' => $dropreferences]);
        $handler = new handler($params['cmid'], $params['field']);
        $handler->allow_dropping((bool)$params['dropreferences']);
        return executor::change($handler, ['text' => $params['text'], 'format' => $params['format']],
            $params['expected']);
    }

    /**
     * Return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return executor::returns();
    }
}

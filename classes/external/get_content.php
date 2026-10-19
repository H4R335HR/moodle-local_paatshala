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
 * local_paatshala_get_content.
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
use local_paatshala\local\content;

/**
 * local_paatshala_get_content: the text fields of an activity as stored, with their files.
 *
 * Text exactly as stored (@@PLUGINFILE@@ placeholders kept, no format_text()), so a field
 * can be passed back as local_paatshala_update_content's `expected`.
 *
 * @package    local_paatshala
 * @copyright  2026 local_paatshala contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_content extends external_api {

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module id'),
        ]);
    }

    /**
     * Read the text fields.
     *
     * @param int $cmid
     * @return array
     */
    public static function execute($cmid): array {
        $params = self::validate_parameters(self::execute_parameters(), ['cmid' => $cmid]);
        list($cm, $course, $context) = gate::module($params['cmid']);
        // As local_paatshala_update_content (the settings page).
        require_capability('moodle/course:manageactivities', $context);

        $fields = [];
        foreach (content::field_names($cm->modname) as $field) {
            $state = content::read_field($cm, $context, $field);
            $spec = content::spec($cm->modname, $field);
            $files = [];
            foreach (content::area_files($context, $cm->modname, $spec['filearea']) as $file) {
                $files[] = content::file_entry($file) + ['filesize' => (int)$file->get_filesize(),
                    'mimetype' => (string)$file->get_mimetype()];
            }
            $references = content::references($state['text']);
            $paths = array_map([content::class, 'path'], $state['files']);
            $fields[] = [
                'field' => $field,
                'text' => $state['text'],
                'format' => $state['format'],
                'files' => $files,
                'references' => $references,
                'missing' => array_values(array_diff($references, $paths)),
            ];
        }
        return [
            'cmid' => (int)$cm->id,
            'courseid' => (int)$course->id,
            'modname' => $cm->modname,
            'name' => (string)$cm->name,
            'fields' => $fields,
        ];
    }

    /**
     * Return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'cmid' => new external_value(PARAM_INT, 'Course module id'),
            'courseid' => new external_value(PARAM_INT, 'Course id'),
            'modname' => new external_value(PARAM_PLUGIN, 'Module type'),
            'name' => new external_value(PARAM_RAW, 'Activity name as stored'),
            'fields' => new external_multiple_structure(new external_single_structure([
                'field' => new external_value(PARAM_ALPHANUMEXT,
                    'intro (description); page: content; workshop: instructauthors, instructreviewers, conclusion'),
                'text' => new external_value(PARAM_RAW, 'Text as stored, @@PLUGINFILE@@/<name> placeholders kept'),
                'format' => new external_value(PARAM_INT, '0 moodle, 1 html, 2 plain, 4 markdown'),
                'files' => new external_multiple_structure(new external_single_structure([
                    'filepath' => new external_value(PARAM_RAW, 'Path in the area, / for the top'),
                    'filename' => new external_value(PARAM_RAW, 'File name'),
                    'contenthash' => new external_value(PARAM_ALPHANUM, 'SHA-1 of the content'),
                    'filesize' => new external_value(PARAM_INT, 'Bytes'),
                    'mimetype' => new external_value(PARAM_RAW, 'MIME type'),
                ]), 'The field\'s file area (itemid 0), directories left out, sorted by path'),
                'references' => new external_multiple_structure(new external_value(PARAM_RAW, 'Path'),
                    'Files the text refers to through @@PLUGINFILE@@/, URL-decoded, sorted'),
                'missing' => new external_multiple_structure(new external_value(PARAM_RAW, 'Path'),
                    'References with no file in the area'),
            ])),
        ]);
    }
}

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
 * Activity texts and their file areas.
 *
 * @package    local_paatshala
 * @copyright  2026 local_paatshala contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_paatshala\local;

/**
 * Activity texts and their file areas, and the file lists the plugin compares.
 *
 * Texts: the description, a page's content, a workshop's instructions and conclusion.
 * A file list is a set of {filepath, filename, contenthash}: two lists are the same when
 * they hold the same entries, in any order, duplicates counted once (files_set()).
 *
 * @package    local_paatshala
 * @copyright  2026 local_paatshala contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class content {

    /** @var array The description of every activity with FEATURE_MOD_INTRO (course/modlib.php:576-581, 702-705). */
    const INTRO = ['column' => 'intro', 'format' => 'introformat', 'editor' => 'introeditor', 'filearea' => 'intro'];

    /**
     * @var array Further text fields per module type: field => its column, format column, the
     *     settings form's editor (data_preprocessing() of mod_page_mod_form and
     *     mod_workshop_mod_form) and the file area (itemid 0).
     */
    const FIELDS = [
        'page' => [
            'content' => ['column' => 'content', 'format' => 'contentformat', 'editor' => 'page',
                'filearea' => 'content'],
        ],
        'workshop' => [
            'instructauthors' => ['column' => 'instructauthors', 'format' => 'instructauthorsformat',
                'editor' => 'instructauthorseditor', 'filearea' => 'instructauthors'],
            'instructreviewers' => ['column' => 'instructreviewers', 'format' => 'instructreviewersformat',
                'editor' => 'instructreviewerseditor', 'filearea' => 'instructreviewers'],
            'conclusion' => ['column' => 'conclusion', 'format' => 'conclusionformat', 'editor' => 'conclusioneditor',
                'filearea' => 'conclusion'],
        ],
    ];

    /** @var array Other file areas the settings form round-trips (assign: intro attachments). */
    const OTHER_AREAS = ['assign' => ['introattachment']];

    /** @var string[] Module types whose name Moodle derives from the description (label_update_instance()). */
    const NAMED_BY_TEXT = ['label'];

    /**
     * @var array How a settings-form save stores the areas where it can drop files: module type =>
     *     filearea (itemid 0) => [subdirs, site maxbytes]. Page content: page_get_editor_options()
     *     (subfolders kept, the site's maxbytes). Saved without subfolders, with no size limit:
     *     assign::save_intro_draft_files(), feedback_update_instance() (feedback_get_editor_options())
     *     and lesson_update_media_file(). Every other area the forms save keeps subfolders and has no
     *     size limit, or (quiz overall feedback) leaves the old files in place.
     */
    const SAVED_AREAS = [
        'page' => ['content' => [true, true]],
        'assign' => ['introattachment' => [false, false]],
        'feedback' => ['page_after_submit' => [false, false]],
        'lesson' => ['mediafile' => [false, false]],
    ];

    /**
     * Files a settings-form save of the activity would drop, by the rules of
     * file_save_draft_area_files() (lib/filelib.php:1097-1129): a file in a subfolder of an area
     * saved without subfolders ("subfolder"), and a file above the area's maxbytes whose uploader
     * may not ignore size limits ("size"; get_user_max_upload_file_size() is asked about the
     * uploader, or the caller for a file without one, as Moodle does).
     *
     * Read-only. modform::save()'s guard stays the authority: a save that would drop anything is
     * refused with filesaltered.
     *
     * @param \stdClass $cm Course module record with modname.
     * @param \context $context The module context.
     * @return array List of {area, path, name, reason}, sorted.
     */
    public static function at_risk(\stdClass $cm, \context $context): array {
        global $CFG;
        $result = [];
        $fs = get_file_storage();
        foreach (self::SAVED_AREAS[$cm->modname] ?? [] as $filearea => list($subdirs, $sitelimit)) {
            $component = 'mod_' . $cm->modname;
            $maxbytes = $sitelimit ? (int)$CFG->maxbytes : 0;
            if ($maxbytes == USER_CAN_IGNORE_FILE_SIZE_LIMITS) {
                $maxbytes = 0;
            }
            foreach ($fs->get_area_files($context->id, $component, $filearea, 0, 'id', false) as $file) {
                $reason = null;
                if (!$subdirs && $file->get_filepath() !== '/') {
                    $reason = 'subfolder';
                } else if ($maxbytes && $maxbytes < $file->get_filesize()
                        && get_user_max_upload_file_size($context, $maxbytes, 0, 0, $file->get_userid())
                            != USER_CAN_IGNORE_FILE_SIZE_LIMITS) {
                    $reason = 'size';
                }
                if ($reason !== null) {
                    $result[] = ['area' => $component . '/' . $filearea, 'path' => $file->get_filepath(),
                        'name' => $file->get_filename(), 'reason' => $reason];
                }
            }
        }
        usort($result, function(array $a, array $b) {
            return strcmp($a['area'], $b['area']) ?: (strcmp($a['path'], $b['path']) ?: strcmp($a['name'], $b['name']));
        });
        return $result;
    }

    /**
     * Return structure of at_risk().
     *
     * @return \external_multiple_structure
     */
    public static function at_risk_structure(): \external_multiple_structure {
        return new \external_multiple_structure(new \external_single_structure([
            'area' => new \external_value(PARAM_RAW, 'component/filearea, e.g. mod_page/content'),
            'path' => new \external_value(PARAM_RAW, 'Folder in the area, / for the top'),
            'name' => new \external_value(PARAM_RAW, 'File name'),
            'reason' => new \external_value(PARAM_ALPHA, 'size: above the site\'s maximum upload size, and its uploader ' .
                'may not ignore size limits; subfolder: in a folder of an area Moodle saves without folders'),
        ]), 'Files any settings-form save of this activity would drop (refused with filesaltered); empty when none');
    }

    /**
     * The text fields of a module type.
     *
     * @param string $modname
     * @return string[]
     */
    public static function field_names(string $modname): array {
        $fields = plugin_supports('mod', $modname, FEATURE_MOD_INTRO, true) ? ['intro'] : [];
        return array_merge($fields, array_keys(self::FIELDS[$modname] ?? []));
    }

    /**
     * Column, format column, editor and file area of a field.
     *
     * @param string $modname
     * @param string $field
     * @return array
     */
    public static function spec(string $modname, string $field): array {
        return $field === 'intro' ? self::INTRO : self::FIELDS[$modname][$field];
    }

    /**
     * The file areas of a module type's text fields and the other areas its settings form
     * round-trips, as component/filearea.
     *
     * @param string $modname
     * @return string[]
     */
    public static function known_areas(string $modname): array {
        $areas = [];
        foreach (self::field_names($modname) as $field) {
            $areas[] = 'mod_' . $modname . '/' . self::spec($modname, $field)['filearea'];
        }
        foreach (self::OTHER_AREAS[$modname] ?? [] as $area) {
            $areas[] = 'mod_' . $modname . '/' . $area;
        }
        foreach (array_keys(self::SAVED_AREAS[$modname] ?? []) as $area) {
            $areas[] = 'mod_' . $modname . '/' . $area;
        }
        return array_values(array_unique($areas));
    }

    /**
     * Files of a text area (itemid 0), directories left out, sorted by path.
     *
     * @param \context $context
     * @param string $modname
     * @param string $filearea
     * @return \stored_file[]
     */
    public static function area_files(\context $context, string $modname, string $filearea): array {
        $files = array_values(get_file_storage()->get_area_files($context->id, 'mod_' . $modname, $filearea, 0, 'id',
            false));
        usort($files, function(\stored_file $a, \stored_file $b) {
            return strcmp($a->get_filepath(), $b->get_filepath()) ?: strcmp($a->get_filename(), $b->get_filename());
        });
        return $files;
    }

    /**
     * A file as a file list holds it.
     *
     * @param \stored_file $file
     * @return array
     */
    public static function file_entry(\stored_file $file): array {
        return ['filepath' => $file->get_filepath(), 'filename' => $file->get_filename(),
            'contenthash' => $file->get_contenthash()];
    }

    /**
     * A file list as a set: entries reduced to {filepath, filename, contenthash} (further keys
     * such as filesize are dropped), duplicates removed, sorted by path, name and hash.
     *
     * @param mixed $files
     * @return array
     * @throws \invalid_parameter_exception for anything but a list of such entries
     */
    public static function files_set($files): array {
        if (!is_array($files)) {
            throw new \invalid_parameter_exception('files must be a list of {filepath, filename, contenthash}');
        }
        $entries = [];
        foreach ($files as $file) {
            if (!is_array($file) || !isset($file['filepath'], $file['filename'], $file['contenthash'])
                    || !is_string($file['filepath']) || !is_string($file['filename']) || !is_string($file['contenthash'])) {
                throw new \invalid_parameter_exception('files must be a list of {filepath, filename, contenthash}');
            }
            $entry = ['filepath' => $file['filepath'], 'filename' => $file['filename'],
                'contenthash' => $file['contenthash']];
            $entries[json_encode(array_values($entry))] = $entry;
        }
        ksort($entries, SORT_STRING);
        return array_values($entries);
    }

    /**
     * Whether two file lists hold the same files (as sets, see files_set()).
     *
     * @param mixed $a
     * @param mixed $b
     * @return bool
     */
    public static function same_files($a, $b): bool {
        return self::files_set($a) === self::files_set($b);
    }

    /**
     * The path a text uses for a file after @@PLUGINFILE@@/.
     *
     * @param array $entry File entry (file_entry()).
     * @return string
     */
    public static function path(array $entry): string {
        return ltrim($entry['filepath'] . $entry['filename'], '/');
    }

    /**
     * The files a text refers to through @@PLUGINFILE@@/, as paths (URL-decoded), sorted.
     *
     * A reference ends at whitespace, a quote, <, >, ?, #, ) or a backslash (an attribute's
     * end, a query string such as ?time=, a fragment, a Markdown link's end).
     *
     * @param string $text
     * @return string[]
     */
    public static function references(string $text): array {
        preg_match_all('~@@PLUGINFILE@@/([^\s"\'<>?#)\\\\]+)~', $text, $matches);
        $paths = [];
        foreach ($matches[1] as $raw) {
            $path = rawurldecode($raw);
            if (substr($path, -1) !== '/') {
                $paths[$path] = true;
            }
        }
        $paths = array_keys($paths);
        sort($paths, SORT_STRING);
        return $paths;
    }

    /**
     * A text field's state.
     *
     * @param \stdClass $cm
     * @param \context $context
     * @param string $field
     * @return array {field, text, format, files}
     */
    public static function read_field(\stdClass $cm, \context $context, string $field): array {
        global $DB;
        $spec = self::spec($cm->modname, $field);
        $record = $DB->get_record($cm->modname, ['id' => $cm->instance], $spec['column'] . ', ' . $spec['format'],
            MUST_EXIST);
        return [
            'field' => $field,
            'text' => (string)$record->{$spec['column']},
            'format' => (int)$record->{$spec['format']},
            'files' => array_map([self::class, 'file_entry'], self::area_files($context, $cm->modname, $spec['filearea'])),
        ];
    }
}

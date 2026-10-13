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
 * A copy of an activity, made by the plugin.
 *
 * @package    local_paatshala
 * @copyright  2026 local_paatshala contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_paatshala\local\kind;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');
require_once($CFG->libdir . '/filelib.php');

use local_paatshala\gate;

/**
 * A copy of an activity, possibly into another course.
 *
 * Made like duplicate_module() (course/lib.php), the course page's Duplicate, but with
 * the target course chosen: a one-activity backup in import mode (no user data) restored
 * into the target course, then hidden and moved into place.
 *
 * @package    local_paatshala
 * @copyright  2026 local_paatshala contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class copy_activity extends created_module {

    /** @var \stdClass|null Source course module (creation only). */
    protected $source;

    /** @var int Target section number (creation only). */
    protected $sectionnum = 0;

    /** @var int Course module to place the copy before (creation only). */
    protected $beforecmid = 0;

    /**
     * Kind name.
     *
     * @return string
     */
    public static function kind(): string {
        return 'copy_activity';
    }

    /**
     * Set what to copy and where; runs the source gate and every capability check.
     *
     * Capabilities as core's Duplicate (course/mod.php: moodle/course:manageactivities in
     * the source module) and Import (backup/import.php: moodle/backup:backuptargetimport in
     * the source course, moodle/restore:restoretargetimport in the target course), plus
     * moodle/course:manageactivities in the target course, where the activity is added.
     *
     * @param int $sourcecmid
     * @param int $sectionnum
     * @param int $beforecmid
     * @return copy_activity
     */
    public function from_source(int $sourcecmid, int $sectionnum, int $beforecmid): copy_activity {
        global $DB;
        list($source, $sourcecourse, $sourcecontext) = gate::module($sourcecmid);
        require_capability('moodle/course:manageactivities', $sourcecontext);
        require_capability('moodle/backup:backuptargetimport', $sourcecontext->get_course_context());
        require_capability('moodle/restore:restoretargetimport', $this->context);
        require_capability('moodle/course:manageactivities', $this->context);

        if (!plugin_supports('mod', $source->modname, FEATURE_BACKUP_MOODLE2)) {
            throw new \moodle_exception('duplicatenosupport', 'error', '',
                (object)['modtype' => $source->modname, 'modname' => format_string($source->name)]);
        }
        $section = $DB->get_record('course_sections', ['course' => $this->course->id, 'section' => $sectionnum]);
        if (!$section) {
            throw new \invalid_parameter_exception('Section ' . $sectionnum . ' does not exist in the target course');
        }
        if ($beforecmid && !in_array($beforecmid, array_map('intval', explode(',', (string)$section->sequence)), true)) {
            throw new \invalid_parameter_exception('Activity ' . $beforecmid . ' is not in section ' . $sectionnum);
        }
        $this->source = $source;
        $this->sectionnum = $sectionnum;
        $this->beforecmid = $beforecmid;
        return $this;
    }

    /**
     * Copy as duplicate_module() does, into the target course; hide and place it.
     *
     * @return array
     */
    protected function create(): array {
        global $CFG, $DB, $USER;
        if (!$this->source) {
            throw new \coding_exception('from_source() first');
        }
        $source = $this->source;

        $bc = new \backup_controller(\backup::TYPE_1ACTIVITY, $source->id, \backup::FORMAT_MOODLE,
            \backup::INTERACTIVE_NO, \backup::MODE_IMPORT, $USER->id);
        $backupid = $bc->get_backupid();
        $backupbasepath = $bc->get_plan()->get_basepath();
        $bc->execute_plan();
        $bc->destroy();

        $rolesbefore = $DB->count_records('role_assignments', ['userid' => $USER->id, 'contextid' => $this->context->id]);
        $rc = new \restore_controller($backupid, $this->course->id, \backup::INTERACTIVE_NO, \backup::MODE_IMPORT,
            $USER->id, \backup::TARGET_CURRENT_ADDING);
        // As duplicate_module(): keep group references.
        $groupsetting = $rc->get_plan()->get_setting('groups');
        if (empty($groupsetting->get_value())) {
            $groupsetting->set_value(true);
        }
        $sourcecontext = \context_module::instance($source->id);
        if (!$rc->execute_precheck()) {
            $results = $rc->get_precheck_results();
            if (!empty($results['errors'])) {
                $rc->destroy();
                if (empty($CFG->keeptempdirectoriesonbackup)) {
                    fulldelete($backupbasepath);
                }
                throw new \moodle_exception('backupprecheckerrors', 'webservice', '', implode(' ', $results['errors']));
            }
        }
        $rc->execute_plan();

        $newcmid = null;
        foreach ($rc->get_plan()->get_tasks() as $task) {
            if (is_subclass_of($task, 'restore_activity_task') && $task->get_old_contextid() == $sourcecontext->id) {
                $newcmid = (int)$task->get_moduleid();
                break;
            }
        }
        $rc->destroy();
        if (empty($CFG->keeptempdirectoriesonbackup)) {
            fulldelete($backupbasepath);
        }
        if (!$newcmid) {
            throw new \moodle_exception('copyfailed', 'local_paatshala');
        }
        $this->cmid = $newcmid;

        // Arrives hidden, then goes where asked.
        set_coursemodule_visible($newcmid, 0);
        $newcm = $DB->get_record('course_modules', ['id' => $newcmid], '*', MUST_EXIST);
        $section = $DB->get_record('course_sections', ['course' => $this->course->id, 'section' => $this->sectionnum],
            '*', MUST_EXIST);
        $beforemod = $this->beforecmid ? $DB->get_record('course_modules', ['id' => $this->beforecmid]) : null;
        moveto_module($newcm, $section, $beforemod);

        // As duplicate_module(): calendar events and the course_module_created event.
        $newcm = get_coursemodule_from_id('', $newcmid, $this->course->id, false, MUST_EXIST);
        course_module_update_calendar_events($newcm->modname, null, $newcm);
        $cminfo = get_fast_modinfo($this->course->id)->get_cm($newcmid);
        \core\event\course_module_created::create_from_cm($cminfo)->trigger();

        // Restore's restore_fix_restorer_access_step gives a restorer without moodle/course:update
        // the role $CFG->restorernewroleid in the course, as Import does.
        $warnings = [];
        if ($DB->count_records('role_assignments', ['userid' => $USER->id, 'contextid' => $this->context->id])
                > $rolesbefore) {
            $warnings[] = ['restorerrole', get_string('warning_restorerrole', 'local_paatshala')];
        }
        return $warnings;
    }

    /**
     * Target: also the source.
     *
     * @return array
     */
    public function target(): array {
        $target = parent::target();
        if ($this->source) {
            $target['sourcecmid'] = (int)$this->source->id;
            $target['sourcecourseid'] = (int)$this->source->course;
        }
        return $target;
    }
}

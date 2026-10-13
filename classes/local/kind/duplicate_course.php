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
 * A copy of a course, made by the plugin.
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
 * A copy of a course without user data, with its dates moved to a new start date.
 *
 * Follows core_course_external::duplicate_course() (course/externallib.php): a course
 * backup in MODE_SAMESITE without users, role assignments, comments, completion, logs or
 * grade history, restored into a course from restore_dbops::create_new_course(). Unlike
 * that function it also sets the restore setting course_startdate, as the restore page
 * does when the start date is changed, so restore_step::apply_date_offset() moves every
 * restored date by (new start date - original start date). The copy is hidden.
 *
 * @package    local_paatshala
 * @copyright  2026 local_paatshala contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class duplicate_course extends created_course {

    /** @var array Backup settings, as core_course_external::duplicate_course() without users. */
    const BACKUP_SETTINGS = [
        'activities' => 1,
        'blocks' => 1,
        'filters' => 1,
        'users' => 0,
        'enrolments' => \backup::ENROL_WITHUSERS,
        'role_assignments' => 0,
        'comments' => 0,
        'userscompletion' => 0,
        'logs' => 0,
        'grade_histories' => 0,
    ];

    /** @var \stdClass|null Source course (creation only). */
    protected $source;

    /** @var string */
    protected $fullname;

    /** @var string */
    protected $shortname;

    /** @var int */
    protected $startdate;

    /**
     * Kind name.
     *
     * @return string
     */
    public static function kind(): string {
        return 'duplicate_course';
    }

    /**
     * Set and check the copy.
     *
     * The source course must be in local_paatshala/allowedcourses. Capabilities as
     * core_course_external::duplicate_course(): moodle/course:create and
     * moodle/restore:restorecourse in the category, moodle/backup:backupcourse in the source
     * course; plus moodle/restore:rolldates and moodle/restore:configure in the category and
     * moodle/backup:configure in the source course. Without them restore_check and
     * backup_check lock the settings (restore_check locks course_startdate), so the dates
     * would not move or user data could not be left out.
     *
     * @param int $sourcecourseid
     * @param string $fullname
     * @param string $shortname
     * @param int $startdate
     * @return duplicate_course
     */
    public function from_source(int $sourcecourseid, string $fullname, string $shortname, int $startdate): duplicate_course {
        $sourcecontext = gate::course($sourcecourseid);
        require_capability('moodle/course:create', $this->context);
        require_capability('moodle/restore:restorecourse', $this->context);
        require_capability('moodle/restore:rolldates', $this->context);
        require_capability('moodle/restore:configure', $this->context);
        require_capability('moodle/backup:backupcourse', $sourcecontext);
        require_capability('moodle/backup:configure', $sourcecontext);

        $fullname = trim($fullname);
        $shortname = trim($shortname);
        if ($fullname === '' || $shortname === '' || \core_text::strlen($fullname) > 254 ||
                \core_text::strlen($shortname) > 255) {
            throw new \invalid_parameter_exception('fullname (1-254 characters) and shortname (1-255) are required');
        }
        if ($startdate <= 0) {
            throw new \invalid_parameter_exception('startdate must be a unix timestamp');
        }
        self::require_shortname_free($shortname);
        $this->source = get_course($sourcecourseid);
        $this->fullname = $fullname;
        $this->shortname = $shortname;
        $this->startdate = $startdate;
        return $this;
    }

    /**
     * Back up and restore, as core_course_external::duplicate_course() with course_startdate set.
     *
     * @return array
     */
    protected function create(): array {
        global $CFG, $DB, $USER;
        if (!$this->source) {
            throw new \coding_exception('from_source() first');
        }

        $bc = new \backup_controller(\backup::TYPE_1COURSE, $this->source->id, \backup::FORMAT_MOODLE,
            \backup::INTERACTIVE_NO, \backup::MODE_SAMESITE, $USER->id);
        self::apply_settings($bc->get_plan());
        $backupid = $bc->get_backupid();
        $backupbasepath = $bc->get_plan()->get_basepath();
        $bc->execute_plan();
        $results = $bc->get_results();
        $file = $results['backup_destination'];
        $bc->destroy();

        if (!file_exists($backupbasepath . '/moodle_backup.xml')) {
            $file->extract_to_pathname(get_file_packer('application/vnd.moodle.backup'), $backupbasepath);
        }

        $newcourseid = \restore_dbops::create_new_course($this->fullname, $this->shortname, $this->categoryid);
        $rc = new \restore_controller($backupid, $newcourseid, \backup::INTERACTIVE_NO, \backup::MODE_SAMESITE,
            $USER->id, \backup::TARGET_NEW_COURSE);
        // As core_course_external::duplicate_course(): restore settings that are locked stay as they are.
        foreach (self::BACKUP_SETTINGS as $name => $value) {
            $setting = $rc->get_plan()->get_setting($name);
            if ($setting->get_status() == \backup_setting::NOT_LOCKED) {
                $setting->set_value($value);
            }
        }
        // As the restore page when the start date is changed: dates move by the difference.
        $startsetting = $rc->get_plan()->get_setting('course_startdate');
        if ($startsetting->get_status() != \backup_setting::NOT_LOCKED) {
            throw new \moodle_exception('rolldateslocked', 'local_paatshala');
        }
        $startsetting->set_value($this->startdate);

        if (!$rc->execute_precheck()) {
            $precheck = $rc->get_precheck_results();
            if (is_array($precheck) && !empty($precheck['errors'])) {
                $rc->destroy();
                if (empty($CFG->keeptempdirectoriesonbackup)) {
                    fulldelete($backupbasepath);
                }
                throw new \moodle_exception('backupprecheckerrors', 'webservice', '', implode(' ', $precheck['errors']));
            }
        }
        $rc->execute_plan();
        $rc->destroy();

        $course = $DB->get_record('course', ['id' => $newcourseid], '*', MUST_EXIST);
        $course->fullname = $this->fullname;
        $course->shortname = $this->shortname;
        $course->visible = 0;
        $course->visibleold = 0;
        $DB->update_record('course', $course);
        rebuild_course_cache($newcourseid, true);

        if (empty($CFG->keeptempdirectoriesonbackup)) {
            fulldelete($backupbasepath);
        }
        $file->delete();

        $this->courseid = (int)$newcourseid;
        return self::allowlist_warning($this->courseid);
    }

    /**
     * Set the backup settings. A setting locked (by permission or config) must already hold
     * the wanted value; core_course_external::duplicate_course() would throw on the backup's
     * users setting when moodle/backup:userinfo is missing.
     *
     * @param \base_plan $plan
     */
    protected static function apply_settings(\base_plan $plan) {
        foreach (self::BACKUP_SETTINGS as $name => $value) {
            if (!$plan->setting_exists($name)) {
                continue;
            }
            $setting = $plan->get_setting($name);
            if ($setting->get_status() == \backup_setting::NOT_LOCKED) {
                $setting->set_value($value);
            } else if ($setting->get_value() != $value) {
                throw new \moodle_exception('backupsettinglocked', 'local_paatshala', '', $name);
            }
        }
    }

    /**
     * Target: also the source.
     *
     * @return array
     */
    public function target(): array {
        $target = parent::target();
        if ($this->source) {
            $target['sourcecourseid'] = (int)$this->source->id;
        }
        return $target;
    }
}

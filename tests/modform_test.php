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
 * Tests for the load-merge-save helper.
 *
 * @package    local_paatshala
 * @copyright  2026 local_paatshala contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_paatshala;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once(__DIR__ . '/helper.php');
require_once($CFG->dirroot . '/mod/assign/locallib.php');

use local_paatshala\local\modform;

/**
 * Tests for the load-merge-save helper.
 *
 * @package    local_paatshala
 * @copyright  2026 local_paatshala contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class modform_test extends testcase {

    /** @var string[] Fields ignored when comparing rows. */
    const TIMEFIELDS = ['timemodified'];

    /**
     * An assignment with non-default settings everywhere the brief names.
     *
     * Non-default submission and feedback plugin settings, an image embedded in the
     * intro, an intro attachment, an availability restriction, completion rules, tags,
     * a competency, an outcome, a grade to pass, a grade category, a rubric and an
     * idnumber.
     *
     * @return array [course, cm]
     */
    protected function create_rich_assignment(): array {
        global $CFG, $DB;
        $CFG->enableavailability = 1;
        $CFG->enablecompletion = 1;
        $CFG->enableoutcomes = 1;
        set_config('enabled', 1, 'core_competency');
        $generator = $this->getDataGenerator();

        $course = $generator->create_course(['enablecompletion' => 1]);
        $gradecategory = $generator->create_grade_category(['courseid' => $course->id]);
        $scale = $generator->create_scale(['courseid' => $course->id, 'scale' => 'Weak,Fair,Strong']);
        $outcome = $generator->create_grade_outcome(['courseid' => $course->id, 'shortname' => 'OUT1',
            'scaleid' => $scale->id]);
        $availability = json_encode(\core_availability\tree::get_root_json(
            [\availability_date\condition::get_json('>=', 1893456000)]));

        $assign = $generator->create_module('assign', [
            'course' => $course->id,
            'name' => 'Rich assignment',
            'idnumber' => 'RICH1',
            'intro' => 'Intro with <img src="@@PLUGINFILE@@/pic.png" alt="pic">',
            'introformat' => FORMAT_HTML,
            'alwaysshowdescription' => 0,
            'allowsubmissionsfromdate' => 1893000000,
            'duedate' => 1894000017,
            'cutoffdate' => 1895000033,
            'gradingduedate' => 1896000051,
            'grade' => 80,
            'gradepass' => 40,
            'gradecat' => $gradecategory->id,
            'submissiondrafts' => 1,
            'requiresubmissionstatement' => 1,
            'attemptreopenmethod' => ASSIGN_ATTEMPT_REOPEN_METHOD_MANUAL,
            'maxattempts' => 3,
            'sendnotifications' => 1,
            'sendlatenotifications' => 1,
            'sendstudentnotifications' => 0,
            'assignsubmission_file_enabled' => 1,
            'assignsubmission_file_maxfiles' => 7,
            'assignsubmission_file_maxsizebytes' => 1048576,
            'assignsubmission_file_filetypes' => '.pdf,.docx',
            'assignsubmission_onlinetext_enabled' => 1,
            'assignsubmission_onlinetext_wordlimit' => 250,
            'assignsubmission_onlinetext_wordlimit_enabled' => 1,
            // The settings page always posts this as 1 while comments are on ($CFG->usecomments).
            'assignsubmission_comments_enabled' => 1,
            'assignfeedback_comments_enabled' => 1,
            'assignfeedback_comments_commentinline' => 1,
            'assignfeedback_file_enabled' => 1,
            'availability' => $availability,
            'completion' => COMPLETION_TRACKING_AUTOMATIC,
            'completionview' => 1,
            'completionusegrade' => 1,
            'completionsubmit' => 1,
            'completionexpected' => 1897000000,
            'tags' => ['Lab', 'Report'],
            'outcome_' . $outcome->id => 1,
        ]);
        $cm = get_coursemodule_from_id('assign', $assign->cmid, 0, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);

        // Files: the embedded intro image and an intro attachment.
        $fs = get_file_storage();
        $fs->create_file_from_string(['contextid' => $context->id, 'component' => 'mod_assign', 'filearea' => 'intro',
            'itemid' => 0, 'filepath' => '/', 'filename' => 'pic.png'], 'PNGDATA');
        $fs->create_file_from_string(['contextid' => $context->id, 'component' => 'mod_assign',
            'filearea' => ASSIGN_INTROATTACHMENT_FILEAREA, 'itemid' => 0, 'filepath' => '/',
            'filename' => 'brief.pdf'], 'PDFDATA');

        // Competency.
        $lpg = $generator->get_plugin_generator('core_competency');
        $framework = $lpg->create_framework();
        $competency = $lpg->create_competency(['competencyframeworkid' => $framework->get('id')]);
        $lpg->create_course_competency(['courseid' => $course->id, 'competencyid' => $competency->get('id')]);
        $lpg->create_course_module_competency(['cmid' => $cm->id, 'competencyid' => $competency->get('id'),
            'ruleoutcome' => \core_competency\course_module_competency::OUTCOME_EVIDENCE]);

        // Rubric.
        $rubricgenerator = $generator->get_plugin_generator('gradingform_rubric');
        $rubricgenerator->create_instance($context, 'mod_assign', 'submissions', 'Rubric', 'A rubric',
            ['Quality' => ['Poor' => 0, 'Good' => 5, 'Excellent' => 10]]);

        $this->assertEquals($availability, $DB->get_field('course_modules', 'availability', ['id' => $cm->id]));
        return [$course, $cm];
    }

    /**
     * Rows that a settings save could touch.
     *
     * @param \stdClass $cm
     * @return array
     */
    protected function snapshot(\stdClass $cm): array {
        global $DB;
        $context = \context_module::instance($cm->id);
        $strip = function(array $rows, array $ignore = []) {
            $result = [];
            foreach ($rows as $row) {
                $row = (array)$row;
                foreach (array_merge(self::TIMEFIELDS, $ignore) as $field) {
                    unset($row[$field]);
                }
                $result[] = $row;
            }
            return $result;
        };
        $area = $DB->get_record('grading_areas', ['contextid' => $context->id, 'component' => 'mod_assign',
            'areaname' => 'submissions']);
        return [
            'course_modules' => $strip($DB->get_records('course_modules', ['id' => $cm->id])),
            'assign' => $strip($DB->get_records('assign', ['id' => $cm->instance])),
            'assign_plugin_config' => $strip($DB->get_records('assign_plugin_config', ['assignment' => $cm->instance],
                'id')),
            'grade_items' => $strip($DB->get_records('grade_items', ['itemmodule' => 'assign',
                'iteminstance' => $cm->instance], 'id')),
            // Moodle: assign::update_calendar() always saves module events with userid 0 (calendar_event
            // stamps the creating user on create), on the settings page too.
            'event' => $strip($DB->get_records('event', ['modulename' => 'assign', 'instance' => $cm->instance], 'id'),
                ['userid']),
            'files' => $strip($DB->get_records('files', ['contextid' => $context->id], 'id')),
            'tag_instance' => $strip($DB->get_records('tag_instance', ['itemtype' => 'course_modules',
                'itemid' => $cm->id], 'id')),
            'competency_modulecomp' => $strip($DB->get_records('competency_modulecomp', ['cmid' => $cm->id], 'id')),
            'grading_areas' => $strip($area ? [$area] : []),
            'grading_definitions' => $strip($area ? $DB->get_records('grading_definitions', ['areaid' => $area->id]) : []),
        ];
    }

    /**
     * Saving with no changes leaves every row as it was, apart from timemodified.
     */
    public function test_save_without_changes_is_a_noop() {
        $this->resetAfterTest();
        $this->setAdminUser();
        list($course, $cm) = $this->create_rich_assignment();
        \external_api::validate_context(\context_module::instance($cm->id));

        $before = $this->snapshot($cm);
        $this->assertCount(2, $before['tag_instance']);
        $this->assertCount(1, $before['competency_modulecomp']);
        // The assignment's grade item and the outcome's.
        $this->assertCount(2, $before['grade_items']);
        // Due, grading due and completion expected.
        $this->assertCount(3, $before['event']);
        $this->assertNotEmpty($before['assign_plugin_config']);

        modform::save($cm, $course, []);

        $this->assertEquals($before, $this->snapshot($cm));
    }

    /**
     * Changing one field changes only that field.
     */
    public function test_save_one_field() {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        list($course, $cm) = $this->create_rich_assignment();
        \external_api::validate_context(\context_module::instance($cm->id));

        $before = $this->snapshot($cm);
        modform::save($cm, $course, ['duedate' => 1894500000]);
        $after = $this->snapshot($cm);

        $this->assertEquals(1894500000, $after['assign'][0]['duedate']);
        $after['assign'][0]['duedate'] = $before['assign'][0]['duedate'];
        foreach ($after['event'] as $i => $event) {
            if ($event['eventtype'] === ASSIGN_EVENT_TYPE_DUE) {
                $this->assertEquals(1894500000, $event['timestart']);
                $this->assertEquals(1894500000, $event['timesort']);
                $after['event'][$i]['timestart'] = $before['event'][$i]['timestart'];
                $after['event'][$i]['timesort'] = $before['event'][$i]['timesort'];
            }
        }
        $this->assertEquals($before, $after);
    }

    /**
     * The settings form's validation refuses what the settings page would.
     */
    public function test_save_refuses_what_the_form_refuses() {
        $this->resetAfterTest();
        $this->setAdminUser();
        list($course, $cm) = $this->create_rich_assignment();
        \external_api::validate_context(\context_module::instance($cm->id));

        $e = $this->assert_error('invalidsettings', function() use ($cm, $course) {
            modform::save($cm, $course, ['cutoffdate' => 1893500000]);
        });
        $this->assertStringContainsString('cutoffdate', $e->getMessage());
    }

    /**
     * Rows of a module's own table, its grade items, events and files (timemodified aside).
     *
     * @param \stdClass $cm
     * @param string[] $extratables Table => field holding the instance id.
     * @return array
     */
    protected function module_snapshot(\stdClass $cm, array $extratables): array {
        global $DB;
        $context = \context_module::instance($cm->id);
        $strip = function(array $rows, array $ignore = []) {
            $result = [];
            foreach ($rows as $row) {
                $row = (array)$row;
                foreach (array_merge(self::TIMEFIELDS, $ignore) as $field) {
                    unset($row[$field]);
                }
                $result[] = $row;
            }
            return $result;
        };
        $snapshot = [
            'course_modules' => $strip($DB->get_records('course_modules', ['id' => $cm->id])),
            $cm->modname => $strip($DB->get_records($cm->modname, ['id' => $cm->instance])),
            'grade_items' => $strip($DB->get_records('grade_items', ['itemmodule' => $cm->modname,
                'iteminstance' => $cm->instance], 'id')),
            'event' => $strip($DB->get_records('event', ['modulename' => $cm->modname, 'instance' => $cm->instance],
                'id'), ['userid']),
            'files' => $strip($DB->get_records('files', ['contextid' => $context->id], 'id')),
        ];
        foreach ($extratables as $table => $field) {
            $snapshot[$table] = $strip($DB->get_records($table, [$field => $cm->instance], 'id'));
        }
        if ($cm->modname === 'workshop') {
            // Moodle: workshop_calendar_update() reuses the existing event rows in database order
            // on every settings save, so which row holds which event can change (on the settings
            // page too). Compare the events by type instead of by row.
            $events = $strip($DB->get_records('event', ['modulename' => 'workshop', 'instance' => $cm->instance]),
                ['userid', 'id']);
            usort($events, function($a, $b) {
                return strcmp($a['eventtype'], $b['eventtype']);
            });
            $snapshot['event'] = $events;
        }
        return $snapshot;
    }

    /**
     * Quiz with non-default review options, overall feedback, access rules and questions:
     * saving without changes leaves it as it was.
     */
    public function test_quiz_save_without_changes_is_a_noop() {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id,
            'timeopen' => 1893456017, 'timeclose' => 1894000033, 'timelimit' => 3600, 'overduehandling' => 'graceperiod',
            'graceperiod' => 600, 'attempts' => 2, 'grademethod' => QUIZ_ATTEMPTFIRST, 'questionsperpage' => 2,
            'navmethod' => 'sequential', 'shuffleanswers' => 0, 'preferredbehaviour' => 'immediatefeedback',
            'quizpassword' => 'secret', 'subnet' => '10.0.0.0/8', 'browsersecurity' => 'securewindow',
            'reviewattempt' => 0x10000 | 0x01000, 'reviewcorrectness' => 0x00100, 'reviewmarks' => 0x10000,
            'reviewspecificfeedback' => 0, 'reviewgeneralfeedback' => 0x00010, 'reviewrightanswer' => 0,
            'reviewoverallfeedback' => 0x00010, 'showuserpicture' => 1, 'decimalpoints' => 1, 'questiondecimalpoints' => 2,
            'sumgrades' => 2, 'grade' => 50]);
        // The generator turns every review option on; set a mix directly, as the settings page
        // would store it, so unchecked options are exercised.
        $DB->update_record('quiz', (object)['id' => $quiz->id, 'reviewattempt' => 0x10000 | 0x01000 | 0x00100,
            'reviewcorrectness' => 0x00100, 'reviewmarks' => 0x10000 | 0x00100 | 0x00010, 'reviewspecificfeedback' => 0,
            'reviewgeneralfeedback' => 0x00010, 'reviewrightanswer' => 0, 'reviewoverallfeedback' => 0x00010]);
        $quizobj = $DB->get_record('quiz', ['id' => $quiz->id]);
        $qgen = $this->getDataGenerator()->get_plugin_generator('core_question');
        $category = $qgen->create_question_category();
        foreach ([1, 2] as $i) {
            $question = $qgen->create_question('truefalse', null, ['category' => $category->id]);
            quiz_add_quiz_question($question->id, $quizobj);
        }
        // Overall feedback with two bands.
        $DB->delete_records('quiz_feedback', ['quizid' => $quiz->id]);
        $DB->insert_record('quiz_feedback', (object)['quizid' => $quiz->id, 'feedbacktext' => '<p>Well done</p>',
            'feedbacktextformat' => FORMAT_HTML, 'mingrade' => 25, 'maxgrade' => 51]);
        $DB->insert_record('quiz_feedback', (object)['quizid' => $quiz->id, 'feedbacktext' => '<p>Try again</p>',
            'feedbacktextformat' => FORMAT_HTML, 'mingrade' => 0, 'maxgrade' => 25]);
        $cm = get_coursemodule_from_id('quiz', $quiz->cmid, 0, false, MUST_EXIST);
        \external_api::validate_context(\context_module::instance($cm->id));

        $tables = ['quiz_feedback' => 'quizid', 'quiz_slots' => 'quizid', 'quiz_sections' => 'quizid',
            'quizaccess_seb_quizsettings' => 'quizid'];
        if (!$DB->get_manager()->table_exists('quizaccess_seb_quizsettings')) {
            unset($tables['quizaccess_seb_quizsettings']);
        }
        $before = $this->module_snapshot($cm, $tables);
        $this->assertCount(2, $before['quiz_feedback']);
        // Moodle: quiz_after_add_or_update() deletes and reinserts the overall feedback on
        // every settings save, so its ids change on the settings page too.
        $nofeedbackids = function(array $snapshot) {
            foreach ($snapshot['quiz_feedback'] as $i => $row) {
                unset($snapshot['quiz_feedback'][$i]['id']);
            }
            return $snapshot;
        };
        $before = $nofeedbackids($before);
        $this->assertEquals(0x10000 | 0x01000 | 0x00100, $before['quiz'][0]['reviewattempt']);
        $this->assertEquals(0, $before['quiz'][0]['reviewrightanswer']);
        modform::save($cm, $course, []);
        $this->assertEquals($before, $nofeedbackids($this->module_snapshot($cm, $tables)));

        modform::save($cm, $course, ['timeclose' => 1894500000]);
        $after = $nofeedbackids($this->module_snapshot($cm, $tables));
        $this->assertEquals(1894500000, $after['quiz'][0]['timeclose']);
        $after['quiz'][0]['timeclose'] = $before['quiz'][0]['timeclose'];
        unset($after['event'], $before['event']);
        $this->assertEquals($before, $after);
    }

    /**
     * Workshop with dates, the automatic switch, grades and an assessment strategy: saving
     * without changes leaves it as it was.
     */
    public function test_workshop_save_without_changes_is_a_noop() {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $workshop = $this->getDataGenerator()->create_module('workshop', ['course' => $course->id,
            'strategy' => 'accumulative', 'grade' => 70, 'gradinggrade' => 30, 'submissiongradepass' => 35,
            'gradinggradepass' => 15, 'nattachments' => 2, 'submissionfiletypes' => '.pdf', 'latesubmissions' => 1,
            'maxbytes' => 1048576, 'examplesmode' => 1, 'useselfassessment' => 1, 'usepeerassessment' => 1,
            'submissionstart' => 1893456017, 'submissionend' => 1894000033, 'assessmentstart' => 1894100000,
            'assessmentend' => 1894900000, 'phaseswitchassessment' => 1, 'instructauthors' => '<p>Write it</p>',
            'instructreviewers' => '<p>Review it</p>', 'conclusion' => '<p>Done</p>']);
        $cm = get_coursemodule_from_id('workshop', $workshop->cmid, 0, false, MUST_EXIST);
        \external_api::validate_context(\context_module::instance($cm->id));

        $before = $this->module_snapshot($cm, []);
        modform::save($cm, $course, []);
        $this->assertEquals($before, $this->module_snapshot($cm, []));
    }
}

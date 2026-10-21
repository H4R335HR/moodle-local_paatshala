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
 * Access restrictions (availability) of a section or an activity.
 *
 * @package    local_paatshala
 * @copyright  2026 local_paatshala contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_paatshala\local\kind;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->libdir . '/completionlib.php');

use local_paatshala\journal;
use local_paatshala\local\availability_report;
use local_paatshala\local\accesstree;
use local_paatshala\local\accesstree_exception;
use local_paatshala\local\completion;

/**
 * Access restrictions (availability) of a section or an activity: the stored JSON tree.
 *
 * State: {availability: the raw JSON as stored, '' for none}. A call sets or removes any of
 * the group, "from", "until", completion and grade restrictions (local\accesstree) in one write; undo writes a
 * journal entry's `before` string back exactly. Values compare as decoded structures (key
 * order and whitespace aside).
 *
 * @package    local_paatshala
 * @copyright  2026 local_paatshala contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class availability extends base {

    /** @var array|null The edit asked for, as accesstree::edit() changes; null for an undo. */
    protected $request = null;

    /** @var array Warnings for the write. */
    protected $warnings = [];

    /**
     * The availability column as stored (null for none).
     *
     * @return string|null
     */
    abstract protected function stored();

    /**
     * Save a tree through the settings page's own path.
     *
     * @param string $json '' for none.
     */
    abstract protected function store(string $json);

    /**
     * Fields.
     *
     * @return string[]
     */
    public function fields(): array {
        return ['availability'];
    }

    /**
     * Current state.
     *
     * @return array
     */
    public function read(): array {
        $raw = $this->stored();
        return ['availability' => $raw === null ? '' : (string)$raw];
    }

    /**
     * Ask for restrictions to be set or removed.
     *
     * @param array $parts Part (group, from, until, completion, grade) => ['action' => 'set'|'remove',
     *     'whenunmet' => 'hide'|'show', 'groupids' => int[] (group), 'time' => int (dates) or
     *     'conditions' (completion: list of {cmid, state complete|incomplete|pass|fail}; grade: list of
     *     {cmid or gradeitemid, min?, max?})]; parts not given are left as they are.
     */
    public function request(array $parts) {
        // Beyond the settings pages: the caller must keep access to what it restricts. The
        // web service checks the module context with require_login() (external_api::
        // validate_context(), lib/externallib.php:498), which refuses an activity the user
        // cannot see (lib/moodlelib.php:3026-3028); ignoreavailabilityrestrictions is what
        // lets staff see restricted sections and activities (lib/modinfolib.php:2008, 2839).
        // Without it, the caller could not read, change or undo the restriction afterwards.
        require_capability('moodle/course:ignoreavailabilityrestrictions', $this->context);
        if (!$parts) {
            throw new \invalid_parameter_exception('Give at least one of group, from, until, completion and grade');
        }
        $changes = [];
        foreach ($parts as $part => $given) {
            $action = $given['action'] ?? null;
            if (!in_array($action, ['set', 'remove'], true)) {
                throw new \invalid_parameter_exception($part . ': action must be set or remove');
            }
            $change = ['action' => $action];
            if ($action === 'set') {
                $whenunmet = $given['whenunmet'] ?? 'hide';
                if (!in_array($whenunmet, ['hide', 'show'], true)) {
                    throw new \invalid_parameter_exception($part . ': whenunmet must be hide or show');
                }
                $change['show'] = $whenunmet === 'show';
                if ($part === 'group') {
                    $change['groupids'] = array_map('intval', $given['groupids'] ?? []);
                } else if ($part === 'completion') {
                    $change['conditions'] = $this->completion_conditions($given['conditions'] ?? []);
                } else if ($part === 'grade') {
                    $change['conditions'] = $this->grade_conditions($given['conditions'] ?? []);
                } else {
                    $change['time'] = (int)($given['time'] ?? 0);
                }
            }
            $changes[$part] = $change;
        }
        // Refuses an unknown part, no groups, no time or until not after from, before any read.
        self::apply_edit(null, $changes);
        $this->request = $changes;
    }

    /**
     * The condition plugins the request writes.
     *
     * @return string[]
     */
    public function types(): array {
        $types = [];
        foreach (array_keys($this->request ?? []) as $part) {
            $types[] = in_array($part, ['from', 'until'], true) ? 'date' : $part;
        }
        return array_values(array_unique($types));
    }

    /**
     * Completion conditions as given, as the tree stores them.
     *
     * @param array $conditions List of {cmid, state}.
     * @return array List of ['cm' => int, 'e' => int].
     */
    protected function completion_conditions(array $conditions): array {
        $result = [];
        foreach ($conditions as $condition) {
            $state = $condition['state'] ?? null;
            if (!isset(completion::STATES[$state])) {
                throw new \invalid_parameter_exception('completion: state must be complete, incomplete, pass or fail');
            }
            $result[] = ['cm' => (int)($condition['cmid'] ?? 0), 'e' => completion::STATES[$state]];
        }
        return $result;
    }

    /**
     * Grade conditions as given, with an activity's grade item looked up (itemtype mod, item
     * number 0), as the tree stores them.
     *
     * @param array $conditions List of {cmid or gradeitemid, min?, max?}.
     * @return array List of ['id' => int, 'min' => float?, 'max' => float?].
     */
    protected function grade_conditions(array $conditions): array {
        global $DB;
        $result = [];
        foreach ($conditions as $condition) {
            $cmid = (int)($condition['cmid'] ?? 0);
            $itemid = (int)($condition['gradeitemid'] ?? 0);
            if (($cmid > 0) === ($itemid > 0)) {
                throw new \invalid_parameter_exception('grade: give exactly one of cmid and gradeitemid per condition');
            }
            if ($cmid) {
                $cms = get_fast_modinfo($this->course)->cms;
                if (!isset($cms[$cmid]) || $cms[$cmid]->deletioninprogress) {
                    throw new \moodle_exception('invalidsettings', 'local_paatshala', '',
                        get_string('condition_foreigncm', 'local_paatshala', $cmid));
                }
                $itemid = (int)$DB->get_field('grade_items', 'id', ['courseid' => $this->course->id, 'itemtype' => 'mod',
                    'itemmodule' => $cms[$cmid]->modname, 'iteminstance' => $cms[$cmid]->instance, 'itemnumber' => 0]);
                if (!$itemid) {
                    throw new \moodle_exception('invalidsettings', 'local_paatshala', '',
                        get_string('condition_nogradeitem', 'local_paatshala', $cmid));
                }
            }
            $item = ['id' => $itemid];
            foreach (['min', 'max'] as $bound) {
                if (isset($condition[$bound])) {
                    $value = (float)$condition[$bound];
                    // Stored as the form's JavaScript stores it: a JSON number (parseFloat()).
                    $item[$bound] = $value == (int)$value ? (int)$value : $value;
                }
            }
            $result[] = $item;
        }
        return $result;
    }

    /**
     * The tree the request makes of a state, as stored JSON.
     *
     * @param array $current
     * @return string
     * @throws \moodle_exception invalidavailability, invalidsettings (until not after from)
     */
    public function edited(array $current): string {
        $tree = $this->parse($current['availability']);
        $result = self::apply_edit($tree, $this->request);
        if (accesstree::same($tree, $result)) {
            // Nothing to change: keep the stored string, so the write is a no-op.
            return $current['availability'];
        }
        return accesstree::encode($result);
    }

    /**
     * Edit a tree, with the editor's refusals as web service errors.
     *
     * @param \stdClass|null $tree
     * @param array $changes
     * @return \stdClass|null
     * @throws \moodle_exception
     */
    protected static function apply_edit($tree, array $changes) {
        try {
            return accesstree::edit($tree, $changes);
        } catch (accesstree_exception $e) {
            if ($e->errorcode === 'invalidparameter') {
                throw new \invalid_parameter_exception($e->getMessage());
            }
            throw new \moodle_exception($e->errorcode, 'local_paatshala', '', $e->getMessage());
        }
    }

    /**
     * Decode a stored value, refusing one Moodle cannot read.
     *
     * @param string $json
     * @return \stdClass|null
     * @throws \moodle_exception invalidavailability
     */
    protected function parse(string $json) {
        $error = availability_report::parse_error($json);
        if ($error !== null) {
            throw new \moodle_exception('invalidavailability', 'local_paatshala', '',
                get_string('storedinvalid', 'local_paatshala', $error));
        }
        return accesstree::decode($json);
    }

    /**
     * `expected` holds {availability}; compared as decoded structures.
     *
     * @param array|null $wanted
     * @param array $current
     */
    public function require_expected($wanted, array $current) {
        if ($wanted === null) {
            return;
        }
        if (!is_string($wanted['availability'] ?? null) || !$this->same_tree($wanted['availability'],
                $current['availability'])) {
            $json = journal::encode($current);
            throw new \moodle_exception('conflict', 'local_paatshala', '', $json, $json);
        }
    }

    /**
     * Undo needs the tree the entry left.
     *
     * @param array $after
     * @param array $before
     * @param array $current
     */
    public function check_undo(array $after, array $before, array $current) {
        $this->require_expected($after, $current);
    }

    /**
     * Whether two stored values hold the same tree.
     *
     * @param string $a
     * @param string $b
     * @return bool
     */
    protected function same_tree(string $a, string $b): bool {
        try {
            return accesstree::same(accesstree::decode($a), accesstree::decode($b));
        } catch (accesstree_exception $e) {
            return $a === $b;
        }
    }

    /**
     * Site settings, groups, dates, and the tree to write.
     *
     * @param array $values
     * @param array $current
     */
    public function validate(array $values, array $current) {
        global $DB;
        // An activity's availability is only saved with enableavailability on
        // (course/modlib.php:542); a section's is only on the form then (course/editsection.php:109).
        // An undo writes back a stored tree: the strict parse below refuses it if it names
        // a condition plugin that has been disabled since.
        availability_report::require_enabled($this->types());
        if ($this->request !== null) {
            // The tree to write was worked out from a read before the transaction.
            if ($this->edited($current) !== $values['availability']) {
                $json = journal::encode($current);
                throw new \moodle_exception('conflict', 'local_paatshala', '', $json, $json);
            }
            $group = $this->request['group'] ?? null;
            if ($group !== null && $group['action'] === 'set') {
                foreach (array_unique($group['groupids']) as $groupid) {
                    if (!$DB->record_exists('groups', ['id' => $groupid, 'courseid' => $this->course->id])) {
                        throw new \moodle_exception('invalidgroup', 'local_paatshala', '', $groupid);
                    }
                    if (!$DB->record_exists('groups_members', ['groupid' => $groupid])) {
                        $this->warnings[] = ['emptygroup', get_string('warning_emptygroup', 'local_paatshala', $groupid)];
                    }
                }
            }
            $this->check_conditions();
            $until = $this->request['until'] ?? null;
            if ($until !== null && $until['action'] === 'set' && $until['time'] <= time()) {
                // Allowed, as the form allows it (availability/condition/date has no such
                // check): those who had access lose it at once.
                $this->warnings[] = ['untilpassed', get_string('warning_untilpassed', 'local_paatshala',
                    gmdate('Y-m-d H:i', $until['time']) . ' UTC')];
            }
        }
        $error = availability_report::parse_error($values['availability']);
        if ($error !== null) {
            throw new \moodle_exception('invalidavailability', 'local_paatshala', '', $error);
        }
    }

    /**
     * The checks of the completion and grade restriction forms for the conditions being set
     * (availability_completion and availability_grade frontend.php and their form JavaScript),
     * and the direct deadlocks.
     *
     * @throws \moodle_exception completiondisabled, invalidsettings, nocompletion, deadlock
     */
    protected function check_conditions() {
        global $DB;
        $completionpart = $this->request['completion'] ?? null;
        $gradepart = $this->request['grade'] ?? null;
        if (($completionpart === null || $completionpart['action'] !== 'set') &&
                ($gradepart === null || $gradepart['action'] !== 'set')) {
            return;
        }
        $modinfo = get_fast_modinfo($this->course);
        // What can only be reached once this item is available: the activity, or every activity of the section.
        $members = $this->cm ? [(int)$this->cm->id] : array_map('intval', $modinfo->sections[$this->section_number()] ?? []);
        $invalid = function(string $string, $a) {
            throw new \moodle_exception('invalidsettings', 'local_paatshala', '', get_string($string, 'local_paatshala', $a));
        };
        if ($completionpart !== null && $completionpart['action'] === 'set') {
            // The completion condition is only offered with completion on in the course
            // (availability/condition/completion/classes/frontend.php:80-91).
            completion::require_enabled($this->course);
            foreach ($completionpart['conditions'] as $condition) {
                $cmid = $condition['cm'];
                $target = $modinfo->cms[$cmid] ?? null;
                // The frontend (frontend.php:60-72) lists the course's activities with completion on, not being
                // deleted, not this one.
                if ($target === null || $target->deletioninprogress) {
                    $invalid('condition_foreigncm', $cmid);
                }
                if ($this->cm && (int)$this->cm->id === $cmid) {
                    $invalid('condition_self', $cmid);
                }
                if (!$target->completion) {
                    throw new \moodle_exception('nocompletion', 'local_paatshala', '', $cmid . ' "' . $target->name . '"');
                }
                // The form refuses pass or fail for an activity without a grade condition
                // (availability/condition/completion/yui/src/form/js/form.js:80-86).
                if (in_array($condition['e'], [COMPLETION_COMPLETE_PASS, COMPLETION_COMPLETE_FAIL], true) &&
                        $target->completiongradeitemnumber === null) {
                    $invalid('condition_passfail', $cmid);
                }
                if ($condition['e'] === COMPLETION_INCOMPLETE) {
                    continue;
                }
                $a = (object)['cmid' => $cmid, 'name' => $target->name];
                if (in_array($cmid, $members, true)) {
                    throw new \moodle_exception('deadlock', 'local_paatshala', '',
                        get_string('condition_deadlock_section', 'local_paatshala', $a));
                }
                $section = $DB->get_field('course_sections', 'availability', ['id' => $target->section]);
                $requires = array_merge(self::required_cms((string)$DB->get_field('course_modules', 'availability',
                    ['id' => $cmid])), self::required_cms((string)$section));
                if (array_intersect($requires, $members)) {
                    throw new \moodle_exception('deadlock', 'local_paatshala', '',
                        get_string('condition_deadlock_cycle', 'local_paatshala', $a));
                }
            }
        }
        if ($gradepart !== null && $gradepart['action'] === 'set') {
            foreach ($gradepart['conditions'] as $condition) {
                $item = $DB->get_record('grade_items', ['id' => $condition['id']]);
                // The frontend (frontend.php:45-66) lists the course's grade items, leaving out those of activities being
                // deleted and the restricted activity's own.
                if (!$item || (int)$item->courseid !== (int)$this->course->id || ($item->itemtype === 'mod' &&
                        course_module_instance_pending_deletion($item->courseid, $item->itemmodule, $item->iteminstance))) {
                    $invalid('condition_foreigngrade', $condition['id']);
                }
                if ($item->itemtype !== 'mod') {
                    continue;
                }
                if ($this->cm && $item->itemmodule === $this->cm->modname && (int)$item->iteminstance ===
                        (int)$this->cm->instance) {
                    $invalid('condition_self', $this->cm->id);
                }
                foreach ($members as $cmid) {
                    $member = $modinfo->cms[$cmid];
                    if ($member->modname === $item->itemmodule && (int)$member->instance === (int)$item->iteminstance) {
                        $this->warnings[] = ['gradedeadlock', get_string('warning_gradedeadlock', 'local_paatshala',
                            'cm ' . $cmid . ' "' . $member->name . '"')];
                    }
                }
            }
        }
    }

    /**
     * The section number of the target (a section, or the activity's section).
     *
     * @return int
     */
    protected function section_number(): int {
        global $DB;
        return (int)$DB->get_field('course_sections', 'section', ['id' => $this->cm ? $this->cm->section :
            $this->target()['sectionid']]);
    }

    /**
     * The activities a stored tree requires to be complete (complete, pass or fail) whatever else
     * holds: completion conditions reached from the root through "all of" sets only, not negated.
     *
     * @param string $json
     * @return int[]
     */
    public static function required_cms(string $json): array {
        $tree = $json === '' ? null : json_decode($json);
        $required = [];
        $walk = function($node) use (&$walk, &$required) {
            if (!is_object($node)) {
                return;
            }
            if (isset($node->type)) {
                if ($node->type === 'completion' && isset($node->cm) && is_number($node->cm) && isset($node->e) &&
                        in_array((int)$node->e, [COMPLETION_COMPLETE, COMPLETION_COMPLETE_PASS, COMPLETION_COMPLETE_FAIL],
                        true)) {
                    $required[] = (int)$node->cm;
                }
                return;
            }
            if (($node->op ?? null) !== '&' || !isset($node->c) || !is_array($node->c)) {
                return;
            }
            foreach ($node->c as $child) {
                $walk($child);
            }
        };
        $walk($tree);
        return array_values(array_unique($required));
    }

    /**
     * Save the tree, unless it is the stored one.
     *
     * @param array $values
     * @param array $current
     * @return array
     */
    public function write(array $values, array $current): array {
        if ($values['availability'] !== $current['availability']) {
            $this->store($values['availability']);
        }
        return $this->warnings;
    }
}

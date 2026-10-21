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
 * Activity completion settings.
 *
 * @package    local_paatshala
 * @copyright  2026 local_paatshala contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_paatshala\local\kind;

use local_paatshala\gate;
use local_paatshala\journal;
use local_paatshala\local\completion;
use local_paatshala\local\modform;
use local_paatshala\local\state;

/**
 * Activity completion settings, through the settings form path.
 *
 * "Activity completion" on the settings page, saved through modform::save() ->
 * update_moduleinfo(), as course/modedit.php saves them.
 *
 * State: the settings (completion::fields()), the completion data counts (completion::COUNTS),
 * and after a write that reset completion data, `reset`: the counts that were reset. A change to
 * anything but the expected date is saved with completionunlocked, as the settings page posts it
 * when nobody has completed the activity or after "Unlock completion options"
 * (course/moodleform_mod.php:324-366); update_moduleinfo() then writes the settings and runs
 * completion_info::reset_all_state() (course/modlib.php:529-541, 640-642), which deletes every
 * user's completion of the activity (lib/completionlib.php:895-930) and, when the activity is a
 * course completion criterion, every course completion record of the course
 * (delete_all_state(), lib/completionlib.php:855-886). Students' data is only reset with
 * resetcompletion; staff rows are, with a warning. Undo writes the settings back; the reset data
 * is not restored (see the README, "Activity completion").
 *
 * @package    local_paatshala
 * @copyright  2026 local_paatshala contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class module_completion extends base {

    /** @var bool Whether students' completion data may be reset. */
    protected $resetcompletion = false;

    /** @var bool Whether completion may be turned off on an activity restrictions point at. */
    protected $force = false;

    /** @var array|null What validate() decided: settings to write, unlock, reset counts, warnings. */
    protected $plan = null;

    /** @var array|null Counts reset by the write, for the state read after it. */
    protected $reset = null;

    /**
     * Gate and capability checks: the settings page (course/modedit.php -> can_update_moduleinfo(),
     * course/modlib.php:470-475) requires moodle/course:manageactivities in the module context.
     *
     * @param int $cmid
     */
    public function __construct(int $cmid) {
        list($this->cm, $this->course, $this->context) = gate::module($cmid);
        require_capability('moodle/course:manageactivities', $this->context);
    }

    /**
     * Kind name.
     *
     * @return string
     */
    public static function kind(): string {
        return 'module_completion';
    }

    /**
     * Handler for a journal target.
     *
     * @param array $target
     * @return base
     */
    public static function from_target(array $target): base {
        return new self((int)$target['cmid']);
    }

    /**
     * Options of the write: resetcompletion, force.
     *
     * @param array $options
     */
    public function set_options(array $options) {
        $this->resetcompletion = !empty($options['resetcompletion']);
        $this->force = !empty($options['force']);
    }

    /**
     * The settings fields of this activity.
     *
     * @return string[]
     */
    protected function settings_fields(): array {
        return completion::fields($this->cm->modname);
    }

    /**
     * Fields: the settings, the data counts (compared, never written) and `reset`.
     *
     * @return string[]
     */
    public function fields(): array {
        return array_merge($this->settings_fields(), completion::COUNTS, ['reset']);
    }

    /**
     * `expected` holds settings and data counts.
     *
     * @return string[]
     */
    public function expected_fields(): array {
        return array_merge($this->settings_fields(), completion::COUNTS);
    }

    /**
     * Current state.
     *
     * @return array
     */
    public function read(): array {
        $state = completion::settings($this->cm) + completion::counts(completion::data($this->cm));
        if ($this->reset !== null) {
            $state['reset'] = $this->reset;
        }
        return $state;
    }

    /**
     * Check the fields a caller gives, before any read.
     *
     * @param array $given Field => value, from the fields JSON.
     * @return array The values to write (completion as a number).
     * @throws \moodle_exception fieldexcluded, invalidsettings
     */
    public function request(array $given): array {
        if (!$given) {
            throw new \invalid_parameter_exception('Give at least one completion setting in fields');
        }
        if (!array_key_exists($this->cm->modname, completion::RULES)) {
            throw new \moodle_exception('fieldexcluded', 'local_paatshala', '', (object)[
                'field' => implode(', ', array_keys($given)),
                'reason' => get_string('excluded_completionmodule', 'local_paatshala', $this->cm->modname)]);
        }
        $known = array_merge(completion::STANDARD, completion::all_rules());
        foreach ($given as $field => $value) {
            if (!in_array($field, $known, true)) {
                throw new \moodle_exception('fieldexcluded', 'local_paatshala', '', (object)['field' => $field,
                    'reason' => get_string('excluded_notcompletion', 'local_paatshala')]);
            }
            if (!in_array($field, $this->settings_fields(), true)) {
                $this->refuse('completionnotoffered', $field);
            }
            if (is_array($value) || is_object($value)) {
                throw new \invalid_parameter_exception($field . ' must be a scalar');
            }
        }
        if (array_key_exists('completion', $given)) {
            $given['completion'] = completion::mode($given['completion']);
        }
        return $given;
    }

    /**
     * Refuse with invalidsettings, the reason from a language string.
     *
     * @param string $string
     * @param mixed $a
     * @throws \moodle_exception invalidsettings
     */
    protected function refuse(string $string, $a = null) {
        if ($a === null || is_string($a)) {
            $a = (object)['field' => $a, 'modname' => $this->cm->modname];
        }
        throw new \moodle_exception('invalidsettings', 'local_paatshala', '', get_string($string, 'local_paatshala', $a));
    }

    /**
     * A 0/1 value.
     *
     * @param string $field
     * @param mixed $value
     * @return int
     */
    protected static function flag(string $field, $value): int {
        if (is_bool($value)) {
            return (int)$value;
        }
        if (!is_numeric($value) || !in_array((int)$value, [0, 1], true) || (float)$value != (int)$value) {
            throw new \invalid_parameter_exception($field . ' must be 0 or 1');
        }
        return (int)$value;
    }

    /**
     * The settings a change asks for, merged into the current ones and refused where the settings
     * form would not offer or accept them.
     *
     * The form hides the automatic rules unless Automatic is chosen (moodleform_mod.php:685, 709,
     * 739, 746) but still posts them, and set_module_viewed() marks a Manual activity complete on
     * view when its hidden "must view" is still ticked (lib/completionlib.php:725-750); so a mode
     * change away from Automatic clears them, a mode change to None clears the expected date (hidden
     * under None, moodleform_mod.php:763), and a rule given with another mode is refused.
     *
     * @param array $given
     * @param array $current
     * @return array Settings field => value.
     */
    protected function merged(array $given, array $current): array {
        $supports = completion::supports($this->cm->modname);
        $new = state::subset($current, $this->settings_fields());
        $modechanged = array_key_exists('completion', $given) && (int)$given['completion'] !== $current['completion'];
        if (array_key_exists('completion', $given)) {
            $new['completion'] = (int)$given['completion'];
        }
        $rulefields = array_merge(['completionview', 'completionusegrade', 'completiongradeitemnumber'],
            $supports['rules']);
        $nonzero = [];
        foreach ($given as $field => $value) {
            switch ($field) {
                case 'completion':
                    continue 2;
                case 'completionexpected':
                    if (!is_numeric($value) || (int)$value < 0 || (float)$value != (int)$value) {
                        throw new \invalid_parameter_exception('completionexpected must be a unix timestamp, or 0 for none');
                    }
                    $new[$field] = (int)$value;
                    continue 2;
                case 'completionview':
                    if (!$supports['view']) {
                        $this->refuse('completionnotoffered', $field);
                    }
                    $new[$field] = self::flag($field, $value);
                    break;
                case 'completionusegrade':
                    if ($supports['gradefield'] !== 'completionusegrade') {
                        $this->refuse($supports['gradefield'] === '' ? 'completionnotoffered' : 'completionusegradeitem',
                            $field);
                    }
                    $new[$field] = self::flag($field, $value);
                    $new['completiongradeitemnumber'] = $new[$field] ? 0 : null;
                    break;
                case 'completiongradeitemnumber':
                    if ($supports['gradefield'] !== 'completiongradeitemnumber') {
                        $this->refuse('completionnotoffered', $field);
                    }
                    if ($value === null || $value === '' || (is_numeric($value) && (int)$value === -1)) {
                        $new[$field] = null;
                    } else {
                        $numbers = array_column($supports['gradeitems'], 'itemnumber');
                        if (!is_numeric($value) || !in_array((int)$value, $numbers, true)) {
                            throw new \invalid_parameter_exception('completiongradeitemnumber must be one of ' .
                                implode(', ', $numbers) . ', or null for none');
                        }
                        $new[$field] = (int)$value;
                    }
                    $new['completionusegrade'] = $new[$field] === null ? 0 : 1;
                    break;
                default:
                    // The module's own rules (request() checked they are this module's).
                    if (in_array($field, completion::COUNT_RULES, true)) {
                        if (!is_numeric($value) || (int)$value < 0 || (float)$value != (int)$value) {
                            throw new \invalid_parameter_exception($field . ' must be a count, 0 for off');
                        }
                        $new[$field] = (int)$value;
                    } else {
                        $new[$field] = self::flag($field, $value);
                    }
            }
            if (!empty($new[$field]) || ($field === 'completiongradeitemnumber' && $new[$field] !== null)) {
                $nonzero[] = $field;
            }
        }

        if ($new['completion'] !== COMPLETION_TRACKING_AUTOMATIC) {
            if ($nonzero) {
                $this->refuse('completionrulesautomatic', implode(', ', $nonzero));
            }
            if ($modechanged) {
                foreach ($rulefields as $field) {
                    $new[$field] = $field === 'completiongradeitemnumber' ? null : 0;
                }
            }
        } else {
            if (!$supports['automatic']) {
                $this->refuse('completionnoautomatic');
            }
            $enabled = !empty($new['completionview']) || $new['completiongradeitemnumber'] !== null;
            foreach ($supports['rules'] as $rule) {
                $enabled = $enabled || !empty($new[$rule]);
            }
            if (!$enabled) {
                // The form's own refusal (course/moodleform_mod.php:471-496).
                throw new \moodle_exception('invalidsettings', 'local_paatshala', '',
                    'completion: ' . get_string('badautocompletion', 'completion'));
            }
            if ($this->cm->modname === 'quiz') {
                // The form disables them otherwise (mod/quiz/mod_form.php:624, 628), and
                // quiz_process_options() clears them (mod/quiz/lib.php:1124-1130).
                if (!empty($new['completionpass']) && empty($new['completionusegrade'])) {
                    $this->refuse('completionquizpass');
                }
                if (!empty($new['completionattemptsexhausted']) && empty($new['completionpass'])) {
                    $this->refuse('completionquizattempts');
                }
            }
        }
        if ($new['completion'] === COMPLETION_TRACKING_NONE) {
            if (!empty($given['completionexpected'])) {
                $this->refuse('completionexpectednone');
            }
            if ($modechanged) {
                $new['completionexpected'] = 0;
            }
        }
        return $new;
    }

    /**
     * Site and course completion, the settings, existing completion data, restrictions pointing at
     * the activity.
     *
     * @param array $values
     * @param array $current
     */
    public function validate(array $values, array $current) {
        completion::require_enabled($this->course);
        $settings = state::subset($values, $this->settings_fields());
        $current = state::subset($current, array_merge($this->settings_fields(), completion::COUNTS));
        // Undo writes the entry's before back as it was stored.
        $new = $this->undoing ? array_merge(state::subset($current, $this->settings_fields()), $settings)
            : $this->merged($settings, $current);
        $changed = [];
        foreach ($this->settings_fields() as $field) {
            if (!state::same($new[$field], $current[$field])) {
                $changed[] = $field;
            }
        }
        $this->plan = null;
        if (!$changed) {
            return;
        }
        $warnings = [];
        $reset = null;
        $unlock = (bool)array_diff($changed, ['completionexpected']);
        if ($unlock) {
            $students = $current['students'] + $current['coursestudents'];
            $others = $current['others'] + $current['courseothers'];
            $a = (object)state::subset($current, completion::COUNTS);
            if ($students && !$this->resetcompletion) {
                throw new \moodle_exception('completiondata', 'local_paatshala', '', $a);
            }
            if ($students || $others) {
                $reset = state::subset($current, completion::COUNTS);
                $warnings[] = ['completionreset', get_string('warning_completionreset', 'local_paatshala', $a)];
            }
        }
        $modechanged = in_array('completion', $changed, true);
        $gradeoff = $current['completiongradeitemnumber'] !== null && $new['completiongradeitemnumber'] === null;
        if ($modechanged || $gradeoff) {
            $references = completion::references($this->course)[(int)$this->cm->id] ?? [];
            if ($references) {
                $names = [];
                $passfail = false;
                foreach ($references as $reference) {
                    $names[] = ($reference['type'] === 'section' ? 'section ' . $reference['sectionnum'] . ' "' :
                        'cm ' . $reference['cmid'] . ' "') . $reference['name'] . '" (' . $reference['path'] . ')';
                    $passfail = $passfail || in_array($reference['state'], [COMPLETION_COMPLETE_PASS,
                        COMPLETION_COMPLETE_FAIL], true);
                }
                $list = implode('; ', array_unique($names));
                if ($new['completion'] === COMPLETION_TRACKING_NONE) {
                    if (!$this->force) {
                        throw new \moodle_exception('referenced', 'local_paatshala', '', $list, $list);
                    }
                    $warnings[] = ['referenced', get_string('warning_referencedoff', 'local_paatshala', $list)];
                } else if ($modechanged) {
                    $warnings[] = ['referenced', get_string('warning_referenced', 'local_paatshala', $list)];
                }
                if ($gradeoff && $passfail && $new['completion'] !== COMPLETION_TRACKING_NONE) {
                    $warnings[] = ['referencedpassfail', get_string('warning_referencedpassfail', 'local_paatshala', $list)];
                }
            }
        }
        $this->plan = ['settings' => $new, 'unlock' => $unlock, 'reset' => $reset, 'warnings' => $warnings];
    }

    /**
     * Save through the settings form path.
     *
     * @param array $values
     * @param array $current
     * @return array Warnings.
     */
    public function write(array $values, array $current): array {
        if ($this->plan === null) {
            return [];
        }
        $new = $this->plan['settings'];
        // The expected date is written by update_moduleinfo() with or without the unlock (course/modlib.php:538-540).
        $changes = ['completionexpected' => $new['completionexpected']];
        if ($this->plan['unlock']) {
            $changes['completionunlocked'] = 1;
            $changes['completion'] = $new['completion'];
            // A checkbox: 0 is not posted (modform::drop_unchecked()), and set_moduleinfo_defaults() makes it 0.
            $changes['completionview'] = $new['completionview'];
            $gradefield = completion::supports($this->cm->modname)['gradefield'];
            if ($gradefield === 'completionusegrade') {
                // In set_moduleinfo_defaults() the checkbox gives item 0 (course/modlib.php:416-420); '' is null
                // in update_moduleinfo() (course/modlib.php:531-535).
                $changes['completionusegrade'] = $new['completionusegrade'];
                $changes['completiongradeitemnumber'] = $new['completionusegrade'] ? 0 : '';
            } else {
                // The select posts a string ('' for none); the loaded data holds completionusegrade, which
                // set_moduleinfo_defaults() would turn into item 0.
                $changes['completionusegrade'] = 0;
                $changes['completiongradeitemnumber'] = $new['completiongradeitemnumber'] === null ? '' :
                    (string)$new['completiongradeitemnumber'];
            }
            foreach (completion::RULES[$this->cm->modname] as $rule) {
                if (in_array($rule, completion::COUNT_RULES, true)) {
                    // Forum: a checkbox and a count (mod/forum/mod_form.php:438-470, 505-520).
                    $changes[$rule . 'enabled'] = $new[$rule] > 0 ? 1 : 0;
                    $changes[$rule] = $new[$rule];
                } else {
                    $changes[$rule] = $new[$rule];
                }
            }
        }
        modform::save($this->cm, $this->course, $changes);
        $this->reset = $this->plan['reset'];
        return $this->plan['warnings'];
    }

    /**
     * Refuse a setting Moodle did not store as asked.
     *
     * @param array $values
     * @param array $state
     * @throws \moodle_exception notsaved
     */
    public function check_written(array $values, array $state) {
        if ($this->plan === null) {
            return;
        }
        foreach ($this->plan['settings'] as $field => $value) {
            if (!state::same($value, $state[$field] ?? null)) {
                throw new \moodle_exception('notsaved', 'local_paatshala', '', (object)['field' => $field,
                    'reason' => get_string('notsaved_different', 'local_paatshala', (object)[
                        'asked' => journal::encode($value), 'stored' => journal::encode($state[$field] ?? null)])]);
            }
        }
    }

    /**
     * The journal holds every setting, the data counts, and what was reset.
     *
     * @param array $values
     * @return string[]
     */
    public function journal_keys(array $values): array {
        return $this->fields();
    }

    /**
     * Undo needs the settings and data counts the entry left.
     *
     * @param array $after
     * @param array $before
     * @param array $current
     */
    public function check_undo(array $after, array $before, array $current) {
        state::require_matches(state::subset($after, $this->expected_fields()), $current);
    }

    /**
     * Target.
     *
     * @return array
     */
    public function target(): array {
        return ['cmid' => (int)$this->cm->id, 'modname' => $this->cm->modname, 'name' => $this->cm->name];
    }
}

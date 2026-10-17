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

use local_paatshala\journal;
use local_paatshala\local\availability_report;
use local_paatshala\local\grouptree;
use local_paatshala\local\grouptree_exception;

/**
 * Access restrictions (availability) of a section or an activity: the stored JSON tree.
 *
 * State: {availability: the raw JSON as stored, '' for none}. A call sets or removes the
 * group restriction (local\grouptree); undo writes a journal entry's `before` string back
 * exactly. Values compare as decoded structures (key order and whitespace aside).
 *
 * @package    local_paatshala
 * @copyright  2026 local_paatshala contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class availability extends base {

    /** @var array|null The edit asked for: action, groupids, show; null for an undo. */
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
     * Ask for a group restriction to be set or removed.
     *
     * @param string $action 'set' or 'remove'
     * @param int[] $groupids
     * @param string $whenunmet 'hide' or 'show'
     */
    public function request(string $action, array $groupids, string $whenunmet) {
        // Beyond the settings pages: the caller must keep access to what it restricts. The
        // web service checks the module context with require_login() (external_api::
        // validate_context(), lib/externallib.php:498), which refuses an activity the user
        // cannot see (lib/moodlelib.php:3026-3028); ignoreavailabilityrestrictions is what
        // lets staff see restricted sections and activities (lib/modinfolib.php:2008, 2839).
        // Without it, the caller could not read, change or undo the restriction afterwards.
        require_capability('moodle/course:ignoreavailabilityrestrictions', $this->context);
        if (!in_array($action, ['set', 'remove'], true)) {
            throw new \invalid_parameter_exception('action must be set or remove');
        }
        if ($action === 'set' && !in_array($whenunmet, ['hide', 'show'], true)) {
            throw new \invalid_parameter_exception('whenunmet must be hide or show');
        }
        $this->request = ['action' => $action, 'groupids' => array_map('intval', $groupids),
            'show' => $whenunmet === 'show'];
    }

    /**
     * The tree the request makes of a state, as stored JSON.
     *
     * @param array $current
     * @return string
     * @throws \moodle_exception invalidavailability
     */
    public function edited(array $current): string {
        $tree = $this->parse($current['availability']);
        try {
            $result = grouptree::edit($tree, $this->request['action'], $this->request['groupids'],
                $this->request['show']);
        } catch (grouptree_exception $e) {
            throw new \moodle_exception('invalidavailability', 'local_paatshala', '', $e->getMessage());
        }
        if (grouptree::same($tree, $result)) {
            // Nothing to change: keep the stored string, so the write is a no-op.
            return $current['availability'];
        }
        return grouptree::encode($result);
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
        return grouptree::decode($json);
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
            return grouptree::same(grouptree::decode($a), grouptree::decode($b));
        } catch (grouptree_exception $e) {
            return $a === $b;
        }
    }

    /**
     * Site settings, groups, and the tree to write.
     *
     * @param array $values
     * @param array $current
     */
    public function validate(array $values, array $current) {
        global $DB;
        // An activity's availability is only saved with enableavailability on
        // (course/modlib.php:542); a section's is only on the form then (course/editsection.php:109).
        availability_report::require_enabled();
        if ($this->request !== null) {
            // The tree to write was worked out from a read before the transaction.
            if ($this->edited($current) !== $values['availability']) {
                $json = journal::encode($current);
                throw new \moodle_exception('conflict', 'local_paatshala', '', $json, $json);
            }
            if ($this->request['action'] === 'set') {
                foreach (array_unique($this->request['groupids']) as $groupid) {
                    if (!$DB->record_exists('groups', ['id' => $groupid, 'courseid' => $this->course->id])) {
                        throw new \moodle_exception('invalidgroup', 'local_paatshala', '', $groupid);
                    }
                    if (!$DB->record_exists('groups_members', ['groupid' => $groupid])) {
                        $this->warnings[] = ['emptygroup', get_string('warning_emptygroup', 'local_paatshala', $groupid)];
                    }
                }
            }
        }
        $error = availability_report::parse_error($values['availability']);
        if ($error !== null) {
            throw new \moodle_exception('invalidavailability', 'local_paatshala', '', $error);
        }
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

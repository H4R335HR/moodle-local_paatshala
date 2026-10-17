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
 * Edit the group and date restrictions of an availability tree.
 *
 * @package    local_paatshala
 * @copyright  2026 local_paatshala contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_paatshala\local;

/**
 * Edit the group and date restrictions of an availability tree, as decoded JSON.
 *
 * Pure: no database, no Moodle API. The structure is the one
 * \core_availability\tree::__construct() reads (availability/classes/tree.php:137-242):
 * a root has op '&', '|', '!&' or '!|' and children c; '&' and '!|' roots carry one
 * showc flag per child, '|' and '!&' roots one show flag; subtrees have neither. A
 * condition has a type. A group condition (availability/condition/group/classes/
 * condition.php:51-61) has an int id, or none for "any group"; a date condition
 * (availability/condition/date/classes/condition.php:55-70) has d '>=' (from) or '<'
 * (until) and an int time t.
 *
 * Three parts are managed, each a top-level child of an '&' root with its own showc flag:
 * - group: a group condition with an id, or an '|' subtree of one or more such
 *   conditions (several groups: a member of any of them has access);
 * - from: a date condition with d '>=';
 * - until: a date condition with d '<'.
 * Children other than the managed ones, and their showc flags, are carried over as the
 * same decoded objects. A part not being changed is not looked at, so a tree with, say, a
 * nested date can still have its group restriction changed.
 *
 * @package    local_paatshala
 * @copyright  2026 local_paatshala contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class accesstree {

    /** @var string[] Managed parts, in the order new children are appended. */
    const PARTS = ['group', 'from', 'until'];

    /** @var string[] Date direction of each date part. */
    const DIRECTION = ['from' => '>=', 'until' => '<'];

    /** @var string[] How a part is named in reasons. */
    const LABEL = ['group' => 'group', 'from' => 'date "from"', 'until' => 'date "until"'];

    /**
     * Set or remove managed parts.
     *
     * @param \stdClass|null $tree Decoded stored tree, or null for none.
     * @param array $changes Part => ['action' => 'set'|'remove', and for set: 'show' (bool:
     *     shown with the restriction message when unmet, false: hidden entirely), 'groupids'
     *     (group) or 'time' (from, until)].
     * @return \stdClass|null The new tree, or null for no restrictions.
     * @throws accesstree_exception With the reason the tree cannot be edited.
     */
    public static function edit($tree, array $changes) {
        if (!$changes) {
            throw new accesstree_exception('nothing to change: give group, from or until', 'invalidparameter');
        }
        foreach ($changes as $part => $change) {
            if (!in_array($part, self::PARTS, true)) {
                throw new accesstree_exception('unknown part ' . $part, 'invalidparameter');
            }
            if (!in_array($change['action'] ?? null, ['set', 'remove'], true)) {
                throw new accesstree_exception($part . ': action must be set or remove', 'invalidparameter');
            }
        }
        if ($tree !== null && !is_object($tree)) {
            throw new accesstree_exception('the stored tree is not an object');
        }
        if ($tree !== null) {
            self::check_shape($tree, '', true);
        }
        $indexes = [];
        foreach ($changes as $part => $change) {
            $indexes[$part] = $tree === null ? null : self::managed_index($tree, $part);
        }

        $sets = array_filter($changes, function($change) {
            return $change['action'] === 'set';
        });
        $children = [];
        foreach ($sets as $part => $change) {
            $children[$part] = self::child($part, $change);
        }

        if (!$sets) {
            if ($tree === null) {
                return null;
            }
            $base = clone $tree;
        } else if ($tree === null || !$tree->c) {
            // Nothing stored, or an empty tree (no restriction): a new '&' root.
            $base = (object)['op' => '&', 'c' => [], 'showc' => []];
        } else if ($tree->op === '&') {
            $base = clone $tree;
        } else {
            $base = self::wrap($tree);
        }
        $drop = [];
        foreach (self::PARTS as $part) {
            if (!isset($changes[$part])) {
                continue;
            }
            // Indexes are positions in the stored '&' root; a new or wrapped root has none.
            $index = $base->op === '&' && $tree !== null && $tree->op === '&' ? $indexes[$part] : null;
            if ($changes[$part]['action'] === 'remove') {
                if ($index !== null) {
                    $drop[] = $index;
                }
                continue;
            }
            $show = !empty($changes[$part]['show']);
            if ($index === null) {
                $base->c[] = $children[$part];
                $base->showc[] = $show;
            } else {
                $base->c[$index] = $children[$part];
                $base->showc[$index] = $show;
            }
        }
        if ($drop) {
            $base->c = array_values(array_diff_key($base->c, array_flip($drop)));
            $base->showc = array_values(array_diff_key($base->showc, array_flip($drop)));
        }
        if (!$base->c) {
            // No restrictions left: null, as the activity form stores (course/modlib.php:555-561).
            return null;
        }

        // The window must not be empty: until after from (the stored one when not given).
        $from = isset($changes['from']) ? ($changes['from']['action'] === 'set' ? $changes['from']['time'] : null)
            : self::stored_time($base, 'from');
        $until = isset($changes['until']) ? ($changes['until']['action'] === 'set' ? $changes['until']['time'] : null)
            : self::stored_time($base, 'until');
        if ((isset($changes['from']) || isset($changes['until'])) && $from !== null && $until !== null &&
                $until <= $from) {
            throw new accesstree_exception('"until" (' . gmdate('Y-m-d H:i', $until) . ' UTC) must be after "from" (' .
                gmdate('Y-m-d H:i', $from) . ' UTC), or nobody would ever have access', 'invalidsettings');
        }
        return $base;
    }

    /**
     * Wrap a non-'&' root as the first child of a new '&' root.
     *
     * @param \stdClass $tree
     * @return \stdClass
     * @throws accesstree_exception
     */
    protected static function wrap(\stdClass $tree): \stdClass {
        $old = clone $tree;
        if ($tree->op === '|' || $tree->op === '!&') {
            // The old root becomes a subtree (subtrees carry no show flag) whose showc entry
            // is its old show flag: it fails, and is shown or hidden, as before
            // (tree::check_available(), availability/classes/tree.php:259-290).
            unset($old->show);
            return (object)['op' => '&', 'c' => [$old], 'showc' => [$tree->show]];
        }
        // An '!|' root: its children have their own showc flags; as one subtree it gets one.
        $values = array_values(array_unique(array_map('intval', $tree->showc)));
        if (count($values) !== 1) {
            throw new accesstree_exception('the root is "none of" (!|) with different display settings per ' .
                'condition, which cannot be kept when another restriction is added; edit it in Moodle');
        }
        unset($old->showc);
        return (object)['op' => '&', 'c' => [$old], 'showc' => [(bool)$values[0]]];
    }

    /**
     * The managed child for a part.
     *
     * @param string $part
     * @param array $change
     * @return \stdClass
     */
    protected static function child(string $part, array $change): \stdClass {
        if ($part === 'group') {
            return self::managed_child($change['groupids'] ?? []);
        }
        $time = $change['time'] ?? null;
        if (!is_int($time) || $time <= 0) {
            throw new accesstree_exception($part . ': time must be a unix timestamp', 'invalidparameter');
        }
        return (object)['type' => 'date', 'd' => self::DIRECTION[$part], 't' => $time];
    }

    /**
     * The managed group child for some groups.
     *
     * @param int[] $groupids
     * @return \stdClass
     */
    public static function managed_child(array $groupids): \stdClass {
        $ids = [];
        foreach ($groupids as $id) {
            if (!is_int($id) || $id <= 0) {
                throw new accesstree_exception('group ids must be positive integers', 'invalidparameter');
            }
            if (!in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }
        if (!$ids) {
            throw new accesstree_exception('give at least one group', 'invalidparameter');
        }
        $conditions = array_map(function($id) {
            return (object)['type' => 'group', 'id' => $id];
        }, $ids);
        return count($conditions) === 1 ? $conditions[0] : (object)['op' => '|', 'c' => $conditions];
    }

    /**
     * Position of a part's managed child, after checking that no other condition of that
     * kind exists.
     *
     * @param \stdClass $tree
     * @param string $part
     * @return int|null
     * @throws accesstree_exception
     */
    public static function managed_index(\stdClass $tree, string $part) {
        self::check_shape($tree, '', true);
        $managed = null;
        foreach ($tree->c as $index => $child) {
            if ($tree->op === '&' && self::is_managed($child, $part)) {
                if ($managed !== null) {
                    throw new accesstree_exception('c[' . $managed . '] and c[' . $index . '] are both ' .
                        self::LABEL[$part] . ' restrictions; edit them in Moodle');
                }
                $managed = $index;
                continue;
            }
            self::refuse($child, 'c[' . $index . ']', $part);
        }
        return $managed;
    }

    /**
     * The group ids the managed group child names.
     *
     * @param \stdClass $tree
     * @return int[]
     */
    public static function managed_groups(\stdClass $tree): array {
        $index = self::managed_index($tree, 'group');
        if ($index === null) {
            return [];
        }
        $child = $tree->c[$index];
        $conditions = isset($child->type) ? [$child] : $child->c;
        return array_map(function($condition) {
            return $condition->id;
        }, $conditions);
    }

    /**
     * The time of a date part's managed child, or null.
     *
     * @param \stdClass $tree
     * @param string $part 'from' or 'until'
     * @return int|null
     */
    public static function managed_time(\stdClass $tree, string $part) {
        $index = self::managed_index($tree, $part);
        return $index === null ? null : $tree->c[$index]->t;
    }

    /**
     * The time of the one top-level date condition of a direction in an '&' root, without
     * refusing anything (for a part that is not being changed).
     *
     * @param \stdClass $tree
     * @param string $part
     * @return int|null
     */
    protected static function stored_time(\stdClass $tree, string $part) {
        if ($tree->op !== '&') {
            return null;
        }
        $times = [];
        foreach ($tree->c as $child) {
            if (self::is_managed($child, $part)) {
                $times[] = $child->t;
            }
        }
        return count($times) === 1 ? $times[0] : null;
    }

    /**
     * Whether a top-level child is a part's managed child.
     *
     * @param mixed $node
     * @param string $part
     * @return bool
     */
    protected static function is_managed($node, string $part): bool {
        if ($part !== 'group') {
            return is_object($node) && ($node->type ?? null) === 'date' && ($node->d ?? null) === self::DIRECTION[$part]
                && isset($node->t) && is_int($node->t);
        }
        if (self::is_group_with_id($node)) {
            return true;
        }
        if (!is_object($node) || isset($node->type) || ($node->op ?? null) !== '|' || empty($node->c)) {
            return false;
        }
        foreach ($node->c as $condition) {
            if (!self::is_group_with_id($condition)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Whether a node is a group condition naming a group.
     *
     * @param mixed $node
     * @return bool
     */
    protected static function is_group_with_id($node): bool {
        return is_object($node) && ($node->type ?? null) === 'group' && isset($node->id) && is_int($node->id);
    }

    /**
     * Refuse any condition of a part's kind in a node that is not its managed child.
     *
     * @param mixed $node
     * @param string $path
     * @param string $part
     * @throws accesstree_exception
     */
    protected static function refuse($node, string $path, string $part) {
        if (!is_object($node)) {
            return;
        }
        if (isset($node->type)) {
            $where = ' this plugin does not manage (inside a negated or mixed set of conditions, or not at the top ' .
                'of an "all of" root); edit it in Moodle';
            if ($part === 'group' && $node->type === 'group') {
                if (!property_exists($node, 'id')) {
                    throw new accesstree_exception($path . ' is an "any group" restriction; edit it in Moodle');
                }
                throw new accesstree_exception($path . ' is a group restriction' . $where);
            }
            if ($part !== 'group' && $node->type === 'date' && ($node->d ?? null) === self::DIRECTION[$part]) {
                throw new accesstree_exception($path . ' is a ' . self::LABEL[$part] . ' restriction' . $where);
            }
            return;
        }
        foreach ($node->c ?? [] as $index => $child) {
            self::refuse($child, ($path === '' ? '' : $path . '.') . 'c[' . $index . ']', $part);
        }
    }

    /**
     * Check the parts of the structure this class relies on (tree.php:137-242 checks the
     * rest when the result is validated).
     *
     * @param \stdClass $node
     * @param string $path
     * @param bool $root
     * @throws accesstree_exception
     */
    protected static function check_shape(\stdClass $node, string $path, bool $root) {
        $where = $path === '' ? 'the root' : $path;
        if (!in_array($node->op ?? null, ['&', '|', '!&', '!|'], true)) {
            throw new accesstree_exception($where . ' has no valid op');
        }
        if (!isset($node->c) || !is_array($node->c)) {
            throw new accesstree_exception($where . ' has no list of conditions (c)');
        }
        if ($root) {
            if ($node->op === '&' || $node->op === '!|') {
                if (!isset($node->showc) || !is_array($node->showc) || count($node->showc) !== count($node->c)) {
                    throw new accesstree_exception('the root has no showc flag for each condition');
                }
            } else if (!isset($node->show) || !is_bool($node->show)) {
                throw new accesstree_exception('the root has no show flag');
            }
        }
        foreach ($node->c as $index => $child) {
            if (is_object($child) && !isset($child->type)) {
                self::check_shape($child, ($path === '' ? '' : $path . '.') . 'c[' . $index . ']', false);
            }
        }
    }

    /**
     * Whether two decoded trees are the same structure (key order and whitespace aside).
     *
     * @param \stdClass|null $a
     * @param \stdClass|null $b
     * @return bool
     */
    public static function same($a, $b): bool {
        return self::canonical($a) === self::canonical($b);
    }

    /**
     * A decoded value as nested arrays with object keys sorted.
     *
     * @param mixed $value
     * @return mixed
     */
    protected static function canonical($value) {
        if (is_object($value)) {
            $value = (array)$value;
            ksort($value);
            return ['{}' => array_map([self::class, 'canonical'], $value)];
        }
        if (is_array($value)) {
            return array_map([self::class, 'canonical'], $value);
        }
        return $value;
    }

    /**
     * Encode a tree as stored ('' for none), as the form's JavaScript does (JSON.stringify).
     *
     * @param \stdClass|null $tree
     * @return string
     */
    public static function encode($tree): string {
        return $tree === null ? '' : json_encode($tree, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Decode a stored value ('' or null for none).
     *
     * @param string|null $json
     * @return \stdClass|null
     * @throws accesstree_exception When it is not a JSON object.
     */
    public static function decode($json) {
        if ($json === null || $json === '') {
            return null;
        }
        $tree = json_decode($json);
        if (!is_object($tree)) {
            throw new accesstree_exception('Invalid availability text');
        }
        return $tree;
    }
}

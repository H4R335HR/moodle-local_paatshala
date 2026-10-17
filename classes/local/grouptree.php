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
 * Edit the group restriction of an availability tree.
 *
 * @package    local_paatshala
 * @copyright  2026 local_paatshala contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_paatshala\local;

/**
 * Edit the group restriction of an availability tree, as decoded JSON.
 *
 * Pure: no database, no Moodle API. The structure is the one
 * \core_availability\tree::__construct() reads (availability/classes/tree.php:137-242):
 * a root has op '&', '|', '!&' or '!|' and children c; '&' and '!|' roots carry one
 * showc flag per child, '|' and '!&' roots one show flag; subtrees have neither. A
 * condition has a type; a group condition (availability/condition/group/classes/
 * condition.php:51-61) has an int id, or none for "any group".
 *
 * The managed child is a top-level child of an '&' root that is a group condition with an
 * id, or an '|' subtree of one or more such conditions (several groups: a member of any
 * of them has access). Children other than the managed child, and their showc flags, are
 * carried over as the same decoded objects.
 *
 * @package    local_paatshala
 * @copyright  2026 local_paatshala contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class grouptree {

    /**
     * Set or remove the managed group restriction.
     *
     * @param \stdClass|null $tree Decoded stored tree, or null for none.
     * @param string $action 'set' or 'remove'.
     * @param int[] $groupids For set: groups whose members have access.
     * @param bool $show For set: show the item with the restriction message when unmet
     *     (false: hide it entirely).
     * @return \stdClass|null The new tree, or null for no restrictions.
     * @throws grouptree_exception With the reason the tree cannot be edited.
     */
    public static function edit($tree, string $action, array $groupids = [], bool $show = false) {
        if ($action !== 'set' && $action !== 'remove') {
            throw new grouptree_exception('action must be set or remove');
        }
        if ($tree !== null && !is_object($tree)) {
            throw new grouptree_exception('the stored tree is not an object');
        }
        $managed = $tree === null ? null : self::managed_index($tree);

        if ($action === 'remove') {
            if ($managed === null) {
                return $tree;
            }
            $result = clone $tree;
            $result->c = array_values(array_diff_key($tree->c, [$managed => 1]));
            $result->showc = array_values(array_diff_key($tree->showc, [$managed => 1]));
            // No restrictions left: null, as the activity form stores (course/modlib.php:555-561).
            return $result->c ? $result : null;
        }

        $child = self::managed_child($groupids);
        if ($tree === null || !$tree->c) {
            // Nothing stored, or an empty tree (no restriction): a new '&' root.
            return (object)['op' => '&', 'c' => [$child], 'showc' => [$show]];
        }
        if ($tree->op === '&') {
            $result = clone $tree;
            $result->c = $tree->c;
            $result->showc = $tree->showc;
            if ($managed === null) {
                $result->c[] = $child;
                $result->showc[] = $show;
            } else {
                $result->c[$managed] = $child;
                $result->showc[$managed] = $show;
            }
            return $result;
        }
        if ($tree->op === '|' || $tree->op === '!&') {
            // The old root becomes a subtree (subtrees carry no show flag) whose showc entry
            // is its old show flag: it fails, and is shown or hidden, as before
            // (tree::check_available(), availability/classes/tree.php:259-290).
            $old = clone $tree;
            unset($old->show);
            return (object)['op' => '&', 'c' => [$old, $child], 'showc' => [$tree->show, $show]];
        }
        // An '!|' root: its children have their own showc flags; as one subtree it gets one.
        $values = array_values(array_unique(array_map('intval', $tree->showc)));
        if (count($values) !== 1) {
            throw new grouptree_exception('the root is "none of" (!|) with different display settings per ' .
                'condition, which cannot be kept when another restriction is added; edit it in Moodle');
        }
        $old = clone $tree;
        unset($old->showc);
        return (object)['op' => '&', 'c' => [$old, $child], 'showc' => [(bool)$values[0], $show]];
    }

    /**
     * The managed child for some groups.
     *
     * @param int[] $groupids
     * @return \stdClass
     */
    public static function managed_child(array $groupids): \stdClass {
        $ids = [];
        foreach ($groupids as $id) {
            if (!is_int($id) || $id <= 0) {
                throw new grouptree_exception('group ids must be positive integers');
            }
            if (!in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }
        if (!$ids) {
            throw new grouptree_exception('give at least one group');
        }
        $conditions = array_map(function($id) {
            return (object)['type' => 'group', 'id' => $id];
        }, $ids);
        return count($conditions) === 1 ? $conditions[0] : (object)['op' => '|', 'c' => $conditions];
    }

    /**
     * Position of the managed child, after checking that no other group condition exists.
     *
     * @param \stdClass $tree
     * @return int|null
     * @throws grouptree_exception
     */
    public static function managed_index(\stdClass $tree) {
        self::check_shape($tree, '', true);
        $managed = null;
        foreach ($tree->c as $index => $child) {
            if ($tree->op === '&' && self::is_managed($child)) {
                if ($managed !== null) {
                    throw new grouptree_exception('c[' . $managed . '] and c[' . $index . '] are both group ' .
                        'restrictions; edit them in Moodle');
                }
                $managed = $index;
                continue;
            }
            self::refuse_groups($child, 'c[' . $index . ']');
        }
        return $managed;
    }

    /**
     * The group ids a managed child names.
     *
     * @param \stdClass $tree
     * @return int[]
     */
    public static function managed_groups(\stdClass $tree): array {
        $index = self::managed_index($tree);
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
     * Whether a top-level child is a managed child.
     *
     * @param mixed $node
     * @return bool
     */
    protected static function is_managed($node): bool {
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
     * Refuse any group condition in a node that is not the managed child.
     *
     * @param mixed $node
     * @param string $path
     * @throws grouptree_exception
     */
    protected static function refuse_groups($node, string $path) {
        if (!is_object($node)) {
            return;
        }
        if (isset($node->type)) {
            if ($node->type !== 'group') {
                return;
            }
            if (!property_exists($node, 'id')) {
                throw new grouptree_exception($path . ' is an "any group" restriction; edit it in Moodle');
            }
            throw new grouptree_exception($path . ' is a group restriction this plugin does not manage (inside a ' .
                'negated or mixed set of conditions, or not at the top of an "all of" root); edit it in Moodle');
        }
        foreach ($node->c ?? [] as $index => $child) {
            self::refuse_groups($child, ($path === '' ? '' : $path . '.') . 'c[' . $index . ']');
        }
    }

    /**
     * Check the parts of the structure this class relies on (tree.php:137-242 checks the
     * rest when the result is validated).
     *
     * @param \stdClass $node
     * @param string $path
     * @param bool $root
     * @throws grouptree_exception
     */
    protected static function check_shape(\stdClass $node, string $path, bool $root) {
        $where = $path === '' ? 'the root' : $path;
        if (!in_array($node->op ?? null, ['&', '|', '!&', '!|'], true)) {
            throw new grouptree_exception($where . ' has no valid op');
        }
        if (!isset($node->c) || !is_array($node->c)) {
            throw new grouptree_exception($where . ' has no list of conditions (c)');
        }
        if ($root) {
            if ($node->op === '&' || $node->op === '!|') {
                if (!isset($node->showc) || !is_array($node->showc) || count($node->showc) !== count($node->c)) {
                    throw new grouptree_exception('the root has no showc flag for each condition');
                }
            } else if (!isset($node->show) || !is_bool($node->show)) {
                throw new grouptree_exception('the root has no show flag');
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
     * @throws grouptree_exception When it is not a JSON object.
     */
    public static function decode($json) {
        if ($json === null || $json === '') {
            return null;
        }
        $tree = json_decode($json);
        if (!is_object($tree)) {
            throw new grouptree_exception('Invalid availability text');
        }
        return $tree;
    }
}

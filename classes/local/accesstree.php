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
 * Edit the group, date, completion and grade restrictions of an availability tree.
 *
 * @package    local_paatshala
 * @copyright  2026 local_paatshala contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_paatshala\local;

/**
 * Edit the group, date, completion and grade restrictions of an availability tree, as decoded JSON.
 *
 * Pure: no database, no Moodle API. The structure is the one
 * \core_availability\tree::__construct() reads (availability/classes/tree.php:137-242):
 * a root has op '&', '|', '!&' or '!|' and children c; '&' and '!|' roots carry one
 * showc flag per child, '|' and '!&' roots one show flag; subtrees have neither. A
 * condition has a type. A group condition (availability/condition/group/classes/
 * condition.php:51-61) has an int id, or none for "any group"; a date condition
 * (availability/condition/date/classes/condition.php:55-70) has d '>=' (from) or '<'
 * (until) and an int time t. A completion condition
 * (availability/condition/completion/classes/condition.php:54-73) has a number cm and an
 * expected state e (0 incomplete, 1 complete, 2 complete with pass, 3 complete with fail); a
 * grade condition (availability/condition/grade/classes/condition.php:52-77) has an int grade
 * item id and optional numbers min and max (percentages, min included, max not).
 *
 * Five parts are managed, made of top-level children of an '&' root, each with its own showc flag:
 * - group: a group condition with an id, or an '|' subtree of one or more such
 *   conditions (several groups: a member of any of them has access);
 * - from: a date condition with d '>=';
 * - until: a date condition with d '<';
 * - completion: every top-level child that is a single completion condition, each with its
 *   own showc flag, as Moodle's restriction form stores several "Activity completion"
 *   conditions (availability/yui/src/form/js/form.js:766-789: each condition added to the root
 *   is a child of it, with its eye icon's showc); or, when it is the only candidate, one '&'
 *   subtree made only of completion conditions (built by hand as a restriction set);
 * - grade: the same with grade conditions.
 * All conditions of a part must hold. Setting a completion or grade part writes it flat, as the
 * form does: one top-level child per condition, each with the given showc, at the position of
 * the part's first child (a nested set is rewritten flat), or appended.
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
    const PARTS = ['group', 'from', 'until', 'completion', 'grade'];

    /** @var string[] Parts made of top-level conditions of a type (or one '&' set of them). */
    const CONDITION_PARTS = ['completion' => 'completion', 'grade' => 'grade'];

    /** @var string[] Date direction of each date part. */
    const DIRECTION = ['from' => '>=', 'until' => '<'];

    /** @var string[] How a part is named in reasons. */
    const LABEL = ['group' => 'group', 'from' => 'date "from"', 'until' => 'date "until"',
        'completion' => 'completion', 'grade' => 'grade'];

    /**
     * Set or remove managed parts.
     *
     * @param \stdClass|null $tree Decoded stored tree, or null for none.
     * @param array $changes Part => ['action' => 'set'|'remove', and for set: 'show' (bool:
     *     shown with the restriction message when unmet, false: hidden entirely), 'groupids'
     *     (group), 'time' (from, until) or 'conditions' (completion: list of ['cm' => int, 'e' =>
     *     int]; grade: list of ['id' => int, and optionally 'min', 'max' => int|float])].
     * @return \stdClass|null The new tree, or null for no restrictions.
     * @throws accesstree_exception With the reason the tree cannot be edited.
     */
    public static function edit($tree, array $changes) {
        if (!$changes) {
            throw new accesstree_exception('nothing to change: give group, from, until, completion or grade',
                'invalidparameter');
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
        // Positions of each changed part's children in the stored root (refusing what cannot be edited).
        $indexes = [];
        foreach ($changes as $part => $change) {
            $indexes[$part] = $tree === null ? [] : self::part_indexes($tree, $part);
        }

        $children = [];
        foreach ($changes as $part => $change) {
            if ($change['action'] !== 'set') {
                continue;
            }
            $children[$part] = self::children($part, $change);
            $show = !empty($change['show']);
            if ($indexes[$part] && isset(self::CONDITION_PARTS[$part]) &&
                    self::same_part($tree, $indexes[$part], $children[$part], $show)) {
                // The same conditions (in any order) with the same display: left exactly as stored.
                unset($changes[$part], $children[$part]);
            }
        }
        $sets = array_filter($changes, function($change) {
            return $change['action'] === 'set';
        });

        // Whether the result is built on the stored root itself (so the indexes apply).
        $stored = false;
        if (!$sets) {
            if ($tree === null) {
                return null;
            }
            $base = clone $tree;
            $stored = true;
        } else if ($tree === null || !$tree->c) {
            // Nothing stored, or an empty tree (no restriction): a new '&' root.
            $base = (object)['op' => '&', 'c' => [], 'showc' => []];
        } else if ($tree->op === '&') {
            $base = clone $tree;
            $stored = true;
        } else {
            $base = self::wrap($tree);
        }
        if ($base->op !== '&') {
            // Only removals, on a root that has no managed parts (part_indexes() refused any
            // condition of a removed part's type in it): nothing to drop.
            return $base->c ? $base : null;
        }

        // Which part owns each child of the stored '&' root (a new or wrapped root has none).
        $owner = [];
        if ($stored && $tree->op === '&') {
            foreach ($changes as $part => $change) {
                foreach ($indexes[$part] as $index) {
                    $owner[$index] = $part;
                }
            }
        }
        // Rebuild the root in one pass: children of unchanged parts and other conditions stay where
        // they are with their showc; a removed part's children go; a part being set gets its new
        // children at the position of its first child.
        $c = [];
        $showc = [];
        $placed = [];
        foreach ($base->c as $index => $child) {
            $part = $owner[$index] ?? null;
            if ($part === null) {
                $c[] = $child;
                $showc[] = $base->showc[$index];
                continue;
            }
            if ($changes[$part]['action'] === 'remove' || isset($placed[$part])) {
                continue;
            }
            foreach ($children[$part] as $new) {
                $c[] = $new;
                $showc[] = !empty($changes[$part]['show']);
            }
            $placed[$part] = true;
        }
        foreach (self::PARTS as $part) {
            if (!isset($sets[$part]) || isset($placed[$part])) {
                continue;
            }
            foreach ($children[$part] as $new) {
                $c[] = $new;
                $showc[] = !empty($changes[$part]['show']);
            }
        }
        $base->c = $c;
        $base->showc = $showc;
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
     * Whether a completion or grade part already holds these conditions (as a set: any order) with
     * this display setting on each of its children.
     *
     * @param \stdClass $tree
     * @param int[] $indexes The part's children.
     * @param \stdClass[] $conditions New conditions.
     * @param bool $show
     * @return bool
     */
    protected static function same_part(\stdClass $tree, array $indexes, array $conditions, bool $show): bool {
        $stored = [];
        foreach ($indexes as $index) {
            if ((bool)$tree->showc[$index] !== $show) {
                return false;
            }
            $child = $tree->c[$index];
            foreach (isset($child->type) ? [$child] : $child->c as $condition) {
                $stored[] = json_encode(self::canonical($condition));
            }
        }
        $given = array_map(function($condition) {
            return json_encode(self::canonical($condition));
        }, $conditions);
        sort($stored);
        sort($given);
        return $stored === $given;
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
     * The new top-level children for a part being set: one for group, from and until; one per
     * condition for completion and grade.
     *
     * @param string $part
     * @param array $change
     * @return \stdClass[]
     */
    protected static function children(string $part, array $change): array {
        if ($part === 'group') {
            return [self::managed_child($change['groupids'] ?? [])];
        }
        if (isset(self::CONDITION_PARTS[$part])) {
            return self::conditions_children($part, $change['conditions'] ?? []);
        }
        $time = $change['time'] ?? null;
        if (!is_int($time) || $time <= 0) {
            throw new accesstree_exception($part . ': time must be a unix timestamp', 'invalidparameter');
        }
        return [(object)['type' => 'date', 'd' => self::DIRECTION[$part], 't' => $time]];
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
     * The conditions of a completion or grade part, one top-level child each, in the order given.
     * A completion condition is stored as the form's JavaScript stores it ({type, cm, e},
     * availability/condition/completion/yui/src/form/js/form.js:69-72); a grade condition with min
     * and max only when given (availability/condition/grade/yui/src/form/js/form.js:110-118).
     *
     * @param string $part completion or grade
     * @param array $conditions
     * @return \stdClass[]
     * @throws accesstree_exception
     */
    public static function conditions_children(string $part, array $conditions): array {
        if (!$conditions) {
            throw new accesstree_exception($part . ': give at least one condition', 'invalidparameter');
        }
        $nodes = [];
        $seen = [];
        foreach ($conditions as $condition) {
            if ($part === 'completion') {
                $cm = $condition['cm'] ?? null;
                $e = $condition['e'] ?? null;
                if (!is_int($cm) || $cm <= 0 || !is_int($e) || !in_array($e, [0, 1, 2, 3], true)) {
                    throw new accesstree_exception('completion: each condition needs a course module id and a state ' .
                        '(0 incomplete, 1 complete, 2 pass, 3 fail)', 'invalidparameter');
                }
                $key = 'cm ' . $cm;
                $node = (object)['type' => 'completion', 'cm' => $cm, 'e' => $e];
            } else {
                $id = $condition['id'] ?? null;
                if (!is_int($id) || $id <= 0) {
                    throw new accesstree_exception('grade: each condition needs a grade item id', 'invalidparameter');
                }
                $node = (object)['type' => 'grade', 'id' => $id];
                foreach (['min', 'max'] as $bound) {
                    if (!array_key_exists($bound, $condition) || $condition[$bound] === null) {
                        continue;
                    }
                    $value = $condition[$bound];
                    if (!is_int($value) && !is_float($value)) {
                        throw new accesstree_exception('grade: ' . $bound . ' must be a number', 'invalidparameter');
                    }
                    $node->$bound = $value;
                }
                // As the form's JavaScript refuses (availability/condition/grade/yui/src/form/js/form.js:128-160).
                if ((isset($node->min) && ($node->min < 0 || $node->min > 100)) ||
                        (isset($node->max) && ($node->max < 0 || $node->max > 100)) ||
                        (isset($node->min) && isset($node->max) && $node->min >= $node->max)) {
                    throw new accesstree_exception('grade item ' . $id . ': min and max are percentages from 0 to ' .
                        '100, and min must be below max', 'invalidsettings');
                }
                $key = 'grade item ' . $id;
            }
            if (isset($seen[$key])) {
                throw new accesstree_exception($key . ' is given twice', 'invalidparameter');
            }
            $seen[$key] = true;
            $nodes[] = $node;
        }
        return $nodes;
    }

    /**
     * The conditions of a completion or grade part (flat siblings or the nested set), or [] when
     * there is none.
     *
     * @param \stdClass $tree
     * @param string $part completion or grade
     * @return \stdClass[]
     * @throws accesstree_exception
     */
    public static function managed_conditions(\stdClass $tree, string $part): array {
        $conditions = [];
        foreach (self::part_indexes($tree, $part) as $index) {
            $child = $tree->c[$index];
            foreach (isset($child->type) ? [$child] : $child->c as $condition) {
                $conditions[] = $condition;
            }
        }
        return $conditions;
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
        if (isset(self::CONDITION_PARTS[$part])) {
            $indexes = self::part_indexes($tree, $part);
            return $indexes ? $indexes[0] : null;
        }
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
     * Positions of a part's children in an '&' root, after checking that no other condition of
     * that kind exists: [] or [i] for group, from and until; for completion and grade, every
     * top-level single condition of the type (flat siblings), or the one '&' set of them.
     *
     * @param \stdClass $tree
     * @param string $part
     * @return int[]
     * @throws accesstree_exception
     */
    public static function part_indexes(\stdClass $tree, string $part): array {
        if (!isset(self::CONDITION_PARTS[$part])) {
            $index = self::managed_index($tree, $part);
            return $index === null ? [] : [$index];
        }
        self::check_shape($tree, '', true);
        $flat = [];
        $sets = [];
        foreach ($tree->c as $index => $child) {
            if ($tree->op === '&' && self::is_condition($child, $part)) {
                $flat[] = $index;
                continue;
            }
            if ($tree->op === '&' && self::is_managed($child, $part)) {
                $sets[] = $index;
                continue;
            }
            self::refuse($child, 'c[' . $index . ']', $part);
        }
        if (count($sets) > 1) {
            throw new accesstree_exception('c[' . $sets[0] . '] and c[' . $sets[1] . '] are both ' .
                self::LABEL[$part] . ' restrictions; edit them in Moodle');
        }
        if ($sets && $flat) {
            throw new accesstree_exception('c[' . $sets[0] . '] (an "all of" set of ' . self::LABEL[$part] .
                ' conditions) and ' . implode(', ', array_map(function($index) {
                    return 'c[' . $index . ']';
                }, $flat)) . ' are all ' . self::LABEL[$part] . ' restrictions, so which is the ' . self::LABEL[$part] .
                ' part is ambiguous; edit them in Moodle');
        }
        return $sets ?: $flat;
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
        if (isset(self::CONDITION_PARTS[$part])) {
            if (self::is_condition($node, $part)) {
                return true;
            }
            if (!is_object($node) || isset($node->type) || ($node->op ?? null) !== '&' || empty($node->c) ||
                    !is_array($node->c)) {
                return false;
            }
            foreach ($node->c as $condition) {
                if (!self::is_condition($condition, $part)) {
                    return false;
                }
            }
            return true;
        }
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
     * Whether a node is a completion or grade condition with the fields the part writes: a
     * completion condition with an int cm and state, a grade condition with an int id.
     *
     * @param mixed $node
     * @param string $part completion or grade
     * @return bool
     */
    protected static function is_condition($node, string $part): bool {
        if (!is_object($node) || ($node->type ?? null) !== self::CONDITION_PARTS[$part]) {
            return false;
        }
        if ($part === 'completion') {
            return isset($node->cm) && is_int($node->cm) && isset($node->e) && is_int($node->e);
        }
        return isset($node->id) && is_int($node->id);
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
            if (isset(self::CONDITION_PARTS[$part])) {
                if ($node->type === self::CONDITION_PARTS[$part]) {
                    throw new accesstree_exception($path . ' is a ' . self::LABEL[$part] . ' restriction' . $where);
                }
                return;
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

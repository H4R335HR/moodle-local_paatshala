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
 * Tests for the group and date restriction tree editing.
 *
 * @package    local_paatshala
 * @copyright  2026 local_paatshala contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_paatshala;

use local_paatshala\local\accesstree;
use local_paatshala\local\accesstree_exception;

/**
 * Tests for the group and date restriction tree editing.
 *
 * @package    local_paatshala
 * @copyright  2026 local_paatshala contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class accesstree_test extends \advanced_testcase {

    /** @var string Date condition. */
    const DATE = '{"type":"date","d":">=","t":1893456000}';

    /** @var string Completion condition. */
    const COMPLETION = '{"type":"completion","cm":12,"e":1}';

    /** @var string Grade condition. */
    const GRADE = '{"type":"grade","id":34,"min":50.5}';

    /**
     * Edit a stored JSON string; the result is valid for Moodle's tree.
     *
     * @param string $json
     * @param string|array $action 'set' or 'remove' for the group part, or the changes
     * @param int[] $groupids
     * @param bool $show
     * @return \stdClass|null
     */
    protected function edit(string $json, $action, array $groupids = [], bool $show = false) {
        $changes = is_array($action) ? $action : ['group' => $this->group($action, $groupids, $show)];
        $result = accesstree::edit(accesstree::decode($json), $changes);
        if ($result !== null) {
            new \core_availability\tree(json_decode(accesstree::encode($result)));
        }
        return $result;
    }

    /**
     * A group change.
     *
     * @param string $action
     * @param int[] $groupids
     * @param bool $show
     * @return array
     */
    protected function group(string $action, array $groupids = [], bool $show = false): array {
        return ['action' => $action, 'groupids' => $groupids, 'show' => $show];
    }

    /**
     * A date change.
     *
     * @param int|null $time Null to remove.
     * @param bool $show
     * @return array
     */
    protected function date($time, bool $show = false): array {
        return $time === null ? ['action' => 'remove'] : ['action' => 'set', 'time' => $time, 'show' => $show];
    }

    /**
     * Decode a JSON fragment.
     *
     * @param string $json
     * @return mixed
     */
    protected function j(string $json) {
        return json_decode($json);
    }

    /**
     * Assert that the edit is refused, with a reason containing some text.
     *
     * @param string $json
     * @param string|array $action 'set' or 'remove' for the group part (groups [5]), or the changes
     * @param string $reason
     * @param string $errorcode
     */
    protected function assert_refused(string $json, $action, string $reason, string $errorcode = 'invalidavailability') {
        $changes = is_array($action) ? $action : ['group' => $this->group($action, [5])];
        try {
            accesstree::edit(accesstree::decode($json), $changes);
            $this->fail('Expected a refusal: ' . $reason);
        } catch (accesstree_exception $e) {
            $this->assertStringContainsString($reason, $e->getMessage());
            $this->assertSame($errorcode, $e->errorcode);
        }
    }

    /**
     * No tree: a new "all of" root with the group condition.
     */
    public function test_no_tree() {
        $this->resetAfterTest();
        $result = $this->edit('', 'set', [3348]);
        $this->assertSame('{"op":"&","c":[{"type":"group","id":3348}],"showc":[false]}', accesstree::encode($result));
        $result = $this->edit('', 'set', [3348], true);
        $this->assertSame([true], $result->showc);
        // An empty tree, as the section form stores after removing everything.
        $result = $this->edit('{"op":"&","c":[],"showc":[]}', 'set', [7]);
        $this->assertSame('{"op":"&","c":[{"type":"group","id":7}],"showc":[false]}', accesstree::encode($result));
        $this->assertNull($this->edit('', 'remove'));
    }

    /**
     * An "all of" root with other conditions: they and their display flags stay, the
     * group condition is appended.
     */
    public function test_and_root_keeps_other_conditions() {
        $this->resetAfterTest();
        $stored = '{"op":"&","c":[' . self::DATE . ',' . self::COMPLETION . ',' . self::GRADE . '],' .
            '"showc":[true,false,true]}';
        $result = $this->edit($stored, 'set', [3348]);
        $this->assertCount(4, $result->c);
        foreach ([self::DATE, self::COMPLETION, self::GRADE] as $index => $condition) {
            $this->assertTrue(accesstree::same($this->j($condition), $result->c[$index]));
        }
        $this->assertSame([true, false, true, false], $result->showc);
        $this->assertTrue(accesstree::same($this->j('{"type":"group","id":3348}'), $result->c[3]));
        $this->assertSame(50.5, $result->c[2]->min);
    }

    /**
     * An existing group condition is replaced in place; several groups make an "any of".
     */
    public function test_replace_in_place_and_several_groups() {
        $this->resetAfterTest();
        $stored = '{"op":"&","c":[' . self::DATE . ',{"type":"group","id":5},' . self::GRADE . '],' .
            '"showc":[true,false,true]}';
        $result = $this->edit($stored, 'set', [7, 8, 7], true);
        $this->assertTrue(accesstree::same(
            $this->j('{"op":"|","c":[{"type":"group","id":7},{"type":"group","id":8}]}'), $result->c[1]));
        $this->assertSame([true, true, true], $result->showc);
        $this->assertTrue(accesstree::same($this->j(self::DATE), $result->c[0]));
        $this->assertTrue(accesstree::same($this->j(self::GRADE), $result->c[2]));

        // And back to one group, still in place.
        $again = $this->edit(accesstree::encode($result), 'set', [9]);
        $this->assertTrue(accesstree::same($this->j('{"type":"group","id":9}'), $again->c[1]));
        $this->assertSame([true, false, true], $again->showc);
        $this->assertSame([9], accesstree::managed_groups($again));
        $this->assertSame([7, 8], accesstree::managed_groups($result));
    }

    /**
     * "Any of" and "not all of" roots are wrapped: the old root keeps its logic and its
     * display flag.
     */
    public function test_wrap_or_and_not_and() {
        $this->resetAfterTest();
        foreach (['|', '!&'] as $op) {
            $stored = '{"op":"' . $op . '","show":false,"c":[' . self::DATE . ',' . self::COMPLETION . ']}';
            $result = $this->edit($stored, 'set', [3348], true);
            $this->assertSame('&', $result->op);
            $this->assertTrue(accesstree::same(
                $this->j('{"op":"' . $op . '","c":[' . self::DATE . ',' . self::COMPLETION . ']}'), $result->c[0]));
            $this->assertSame([false, true], $result->showc);

            // Removing the group again keeps the wrap.
            $removed = $this->edit(accesstree::encode($result), 'remove');
            $this->assertSame('&', $removed->op);
            $this->assertCount(1, $removed->c);
            $this->assertSame([false], $removed->showc);
        }
    }

    /**
     * "None of" roots are wrapped only when all their display flags agree.
     */
    public function test_wrap_not_or() {
        $this->resetAfterTest();
        $stored = '{"op":"!|","c":[' . self::DATE . ',' . self::COMPLETION . '],"showc":[true,true]}';
        $result = $this->edit($stored, 'set', [3348]);
        $this->assertTrue(accesstree::same(
            $this->j('{"op":"!|","c":[' . self::DATE . ',' . self::COMPLETION . ']}'), $result->c[0]));
        $this->assertSame([true, false], $result->showc);

        $mixed = '{"op":"!|","c":[' . self::DATE . ',' . self::COMPLETION . '],"showc":[true,false]}';
        $this->assert_refused($mixed, 'set', 'different display settings');
    }

    /**
     * Remove: to null when nothing else is left, keeping the other conditions otherwise.
     */
    public function test_remove() {
        $this->resetAfterTest();
        $this->assertNull($this->edit('{"op":"&","c":[{"type":"group","id":5}],"showc":[false]}', 'remove'));
        $this->assertNull($this->edit('{"op":"&","c":[{"op":"|","c":[{"type":"group","id":5},' .
            '{"type":"group","id":6}]}],"showc":[true]}', 'remove'));

        $stored = '{"op":"&","c":[' . self::DATE . ',{"type":"group","id":5},' . self::GRADE . '],' .
            '"showc":[true,false,false]}';
        $result = $this->edit($stored, 'remove');
        $this->assertCount(2, $result->c);
        $this->assertTrue(accesstree::same($this->j(self::DATE), $result->c[0]));
        $this->assertTrue(accesstree::same($this->j(self::GRADE), $result->c[1]));
        $this->assertSame([true, false], $result->showc);
    }

    /**
     * Group conditions anywhere but the managed child are refused, naming where.
     */
    public function test_refusals() {
        $this->resetAfterTest();
        // Inside a negated subtree.
        $this->assert_refused('{"op":"&","c":[{"op":"!&","c":[{"type":"group","id":5}]}],"showc":[true]}', 'set',
            'c[0].c[0]');
        // Mixed into an "any of" with another condition type.
        $this->assert_refused('{"op":"&","c":[{"op":"|","c":[{"type":"group","id":5},' . self::DATE . ']}],' .
            '"showc":[true]}', 'set', 'c[0].c[0] is a group restriction');
        // Two candidates.
        $this->assert_refused('{"op":"&","c":[{"type":"group","id":5},{"type":"group","id":6}],' .
            '"showc":[true,true]}', 'set', 'c[0] and c[1] are both');
        // Any group.
        $this->assert_refused('{"op":"&","c":[{"type":"group"}],"showc":[true]}', 'set', 'c[0] is an "any group"');
        // In a root that is not "all of".
        $this->assert_refused('{"op":"|","show":true,"c":[{"type":"group","id":5}]}', 'set', 'c[0] is a group');
        // Nested in an "all of" subtree.
        $this->assert_refused('{"op":"&","c":[{"op":"&","c":[' . self::DATE . ',{"type":"group","id":5}]}],' .
            '"showc":[true]}', 'remove', 'c[0].c[1]');
        // Broken structure.
        $this->assert_refused('{"op":"|","c":[' . self::DATE . ']}', 'set', 'no show flag');
        $this->assert_refused('{"op":"&","c":[' . self::DATE . ']}', 'set', 'no showc flag');
        $this->assert_refused('{"op":"?","c":[]}', 'set', 'no valid op');
        $this->assert_refused('', ['group' => $this->group('set')], 'at least one group', 'invalidparameter');
        try {
            accesstree::decode('{"op":');
            $this->fail('Invalid JSON');
        } catch (accesstree_exception $e) {
            $this->assertStringContainsString('Invalid availability text', $e->getMessage());
        }
    }

    /**
     * Edits that change nothing compare as the same structure, whatever the key order.
     */
    public function test_nochange() {
        $this->resetAfterTest();
        $stored = '{"showc":[true,false],"op":"&","c":[' . self::DATE . ',{"id":5,"type":"group"}]}';
        $tree = accesstree::decode($stored);
        $this->assertTrue(accesstree::same($tree, accesstree::edit($tree, ['group' => $this->group('set', [5])])));
        $this->assertFalse(accesstree::same($tree, accesstree::edit($tree, ['group' => $this->group('set', [5], true)])));
        $this->assertTrue(accesstree::same($tree, accesstree::edit($tree, ['from' => $this->date(1893456000, true)])));
        $this->assertFalse(accesstree::same($tree, accesstree::edit($tree, ['from' => $this->date(1893456000)])));
        $plain = accesstree::decode('{"op":"&","c":[' . self::DATE . '],"showc":[true]}');
        $this->assertTrue(accesstree::same($plain, accesstree::edit($plain, ['group' => $this->group('remove')])));
        $this->assertTrue(accesstree::same($plain, accesstree::edit($plain, ['until' => $this->date(null)])));
        $this->assertTrue(accesstree::same(null, accesstree::edit(null, ['group' => $this->group('remove'),
            'from' => $this->date(null)])));
        // A list and an object are not the same.
        $this->assertFalse(accesstree::same($this->j('{"op":"&","c":[],"showc":[]}'), $this->j('{"op":"&","c":{},"showc":[]}')));
    }

    /** @var int A "from" time. */
    const FROM = 1893456000;

    /** @var int An "until" time, after FROM. */
    const UNTIL = 1896134400;

    /**
     * Dates on an empty tree: separate children in group, from, until order.
     */
    public function test_dates_no_tree() {
        $this->resetAfterTest();
        $result = $this->edit('', ['from' => $this->date(self::FROM)]);
        $this->assertSame('{"op":"&","c":[{"type":"date","d":">=","t":1893456000}],"showc":[false]}',
            accesstree::encode($result));
        $result = $this->edit('', ['until' => $this->date(self::UNTIL, true)]);
        $this->assertSame('{"op":"&","c":[{"type":"date","d":"<","t":1896134400}],"showc":[true]}',
            accesstree::encode($result));

        // All three in one call, whatever order they are given in.
        $result = $this->edit('{"op":"&","c":[],"showc":[]}', ['until' => $this->date(self::UNTIL, true),
            'from' => $this->date(self::FROM), 'group' => $this->group('set', [3348])]);
        $this->assertSame('{"op":"&","c":[{"type":"group","id":3348},{"type":"date","d":">=","t":1893456000},' .
            '{"type":"date","d":"<","t":1896134400}],"showc":[false,false,true]}', accesstree::encode($result));
        $this->assertSame([3348], accesstree::managed_groups($result));
        $this->assertSame(self::FROM, accesstree::managed_time($result, 'from'));
        $this->assertSame(self::UNTIL, accesstree::managed_time($result, 'until'));
        $this->assertNull(accesstree::managed_time($this->edit('', ['from' => $this->date(self::FROM)]), 'until'));
    }

    /**
     * Stored dates are replaced in place, each with its own display flag; other children stay.
     */
    public function test_dates_replace_in_place() {
        $this->resetAfterTest();
        $stored = '{"op":"&","c":[{"type":"date","d":"<","t":1900000000},' . self::GRADE . ',' .
            '{"type":"group","id":5},{"type":"date","d":">=","t":1800000000}],"showc":[false,true,false,true]}';
        $result = $this->edit($stored, ['from' => $this->date(self::FROM), 'until' => $this->date(self::UNTIL, true)]);
        $this->assertSame('{"op":"&","c":[{"type":"date","d":"<","t":1896134400},' . self::GRADE . ',' .
            '{"type":"group","id":5},{"type":"date","d":">=","t":1893456000}],"showc":[true,true,false,false]}',
            accesstree::encode($result));

        // Only one date given: the other keeps its time and flag.
        $result = $this->edit($stored, ['until' => $this->date(self::UNTIL)]);
        $this->assertSame([false, true, false, true], $result->showc);
        $this->assertSame(self::UNTIL, $result->c[0]->t);
        $this->assertSame(1800000000, $result->c[3]->t);
    }

    /**
     * Removing one date keeps the other, the group and everything else.
     */
    public function test_remove_one_date() {
        $this->resetAfterTest();
        $stored = '{"op":"&","c":[{"type":"group","id":5},' . self::COMPLETION . ',' .
            '{"type":"date","d":">=","t":1893456000},{"type":"date","d":"<","t":1896134400}],' .
            '"showc":[false,true,true,false]}';
        $result = $this->edit($stored, ['from' => $this->date(null)]);
        $this->assertSame('{"op":"&","c":[{"type":"group","id":5},' . self::COMPLETION . ',' .
            '{"type":"date","d":"<","t":1896134400}],"showc":[false,true,false]}', accesstree::encode($result));
        $result = $this->edit($stored, ['until' => $this->date(null), 'group' => $this->group('remove')]);
        $this->assertSame('{"op":"&","c":[' . self::COMPLETION . ',{"type":"date","d":">=","t":1893456000}],' .
            '"showc":[true,true]}', accesstree::encode($result));
        // Everything managed gone and nothing else: no restriction.
        $this->assertNull($this->edit('{"op":"&","c":[{"type":"date","d":">=","t":1893456000},' .
            '{"type":"date","d":"<","t":1896134400}],"showc":[true,true]}',
            ['from' => $this->date(null), 'until' => $this->date(null)]));
        // A date removed with none stored, and a group set in the same call.
        $result = $this->edit('{"op":"&","c":[' . self::COMPLETION . '],"showc":[true]}',
            ['until' => $this->date(null), 'group' => $this->group('set', [7])]);
        $this->assertSame('{"op":"&","c":[' . self::COMPLETION . ',{"type":"group","id":7}],"showc":[true,false]}',
            accesstree::encode($result));
    }

    /**
     * A non-"all of" root is wrapped once for group and dates together.
     */
    public function test_dates_wrap() {
        $this->resetAfterTest();
        $stored = '{"op":"|","show":true,"c":[' . self::COMPLETION . ',' . self::GRADE . ']}';
        $result = $this->edit($stored, ['from' => $this->date(self::FROM), 'group' => $this->group('set', [5], true)]);
        $this->assertSame('{"op":"&","c":[{"op":"|","c":[' . self::COMPLETION . ',' . self::GRADE . ']},' .
            '{"type":"group","id":5},{"type":"date","d":">=","t":1893456000}],"showc":[true,true,false]}',
            accesstree::encode($result));
        // Removing a date from a non-"all of" root with none in it: no change.
        $tree = accesstree::decode($stored);
        $this->assertTrue(accesstree::same($tree, accesstree::edit($tree, ['until' => $this->date(null)])));
    }

    /**
     * Dates the plugin does not manage are refused, but only when that part is edited.
     */
    public function test_date_refusals() {
        $this->resetAfterTest();
        $nested = '{"op":"&","c":[{"op":"|","c":[{"type":"date","d":">=","t":1893456000},' . self::COMPLETION . ']}],' .
            '"showc":[true]}';
        $this->assert_refused($nested, ['from' => $this->date(self::FROM)], 'c[0].c[0] is a date "from" restriction');
        $this->assert_refused($nested, ['from' => $this->date(null)], 'c[0].c[0] is a date "from"');
        // The group and the other date can still be changed.
        $result = $this->edit($nested, ['group' => $this->group('set', [5]), 'until' => $this->date(self::UNTIL)]);
        $this->assertCount(3, $result->c);
        $this->assertTrue(accesstree::same($this->j($nested)->c[0], $result->c[0]));

        // Two top-level candidates.
        $this->assert_refused('{"op":"&","c":[{"type":"date","d":"<","t":1896134400},' .
            '{"type":"date","d":"<","t":1900000000}],"showc":[true,true]}', ['until' => $this->date(self::UNTIL)],
            'c[0] and c[1] are both date "until" restrictions');
        // Negated: "not from" is an until in effect, but not one the plugin manages.
        $this->assert_refused('{"op":"!&","show":true,"c":[{"type":"date","d":"<","t":1896134400}]}',
            ['until' => $this->date(self::UNTIL)], 'c[0] is a date "until" restriction');
        // In a root that is not "all of".
        $this->assert_refused('{"op":"|","show":true,"c":[{"type":"date","d":">=","t":1893456000},' . self::GRADE . ']}',
            ['from' => $this->date(self::FROM)], 'c[0] is a date "from"');
        // A bad time.
        $this->assert_refused('', ['from' => ['action' => 'set', 'time' => 0]], 'unix timestamp', 'invalidparameter');
        $this->assert_refused('', ['until' => ['action' => 'set']], 'unix timestamp', 'invalidparameter');
        // The request itself.
        $this->assert_refused('', [], 'nothing to change', 'invalidparameter');
        $this->assert_refused('', ['grouping' => ['action' => 'remove']], 'unknown part', 'invalidparameter');
        $this->assert_refused('', ['from' => ['action' => 'add', 'time' => self::FROM]], 'set or remove',
            'invalidparameter');
    }

    /**
     * "until" must be after "from": both given, or one given and the other stored.
     */
    public function test_until_after_from() {
        $this->resetAfterTest();
        $this->assert_refused('', ['from' => $this->date(self::UNTIL), 'until' => $this->date(self::FROM)],
            'must be after "from"', 'invalidsettings');
        $this->assert_refused('', ['from' => $this->date(self::FROM), 'until' => $this->date(self::FROM)],
            'must be after "from"', 'invalidsettings');
        $stored = '{"op":"&","c":[{"type":"date","d":">=","t":1893456000}],"showc":[true]}';
        $this->assert_refused($stored, ['until' => $this->date(self::FROM - 60)], 'must be after "from"',
            'invalidsettings');
        $stored = '{"op":"&","c":[{"type":"date","d":"<","t":1896134400}],"showc":[true]}';
        $this->assert_refused($stored, ['from' => $this->date(self::UNTIL)], 'must be after "from"', 'invalidsettings');
        // Removing the stored one in the same call makes the new one fine.
        $result = $this->edit($stored, ['from' => $this->date(self::UNTIL + 60), 'until' => $this->date(null)]);
        $this->assertSame(self::UNTIL + 60, accesstree::managed_time($result, 'from'));
        $this->assertNull(accesstree::managed_time($result, 'until'));
        // One minute apart is enough.
        $this->assertNotNull($this->edit($stored, ['from' => $this->date(self::UNTIL - 60)]));
    }

    /**
     * A completion change.
     *
     * @param array|null $conditions List of [cm, e]; null to remove.
     * @param bool $show
     * @return array
     */
    protected function completion($conditions, bool $show = false): array {
        if ($conditions === null) {
            return ['action' => 'remove'];
        }
        return ['action' => 'set', 'show' => $show, 'conditions' => array_map(function($pair) {
            return ['cm' => $pair[0], 'e' => $pair[1]];
        }, $conditions)];
    }

    /**
     * A grade change.
     *
     * @param array|null $conditions List of ['id' => .., 'min' => .., 'max' => ..]; null to remove.
     * @param bool $show
     * @return array
     */
    protected function grade($conditions, bool $show = false): array {
        return $conditions === null ? ['action' => 'remove'] :
            ['action' => 'set', 'show' => $show, 'conditions' => $conditions];
    }

    /**
     * The stored JSON of a restriction made with Moodle's own form (3.8.2+ 20200508): two activity
     * completion conditions (the second hidden entirely with its eye icon), a grade condition and a
     * group condition, each a top-level child with its own showc.
     *
     * @return string
     */
    protected function form_json(): string {
        return trim(file_get_contents(__DIR__ . '/fixtures/form_completion_grade_group.json'));
    }

    /**
     * The form's own JSON: completion and grade parts recognised; set, remove and nochange on them;
     * changing the group part leaves the completion siblings and their showc byte for byte.
     */
    public function test_form_json() {
        $this->resetAfterTest();
        $json = $this->form_json();
        $this->assertSame('{"op":"&","c":[{"type":"completion","cm":6,"e":1},{"type":"completion","cm":7,"e":1},' .
            '{"type":"grade","id":3,"min":50},{"type":"group","id":2}],"showc":[true,false,true,true]}', $json);
        $tree = accesstree::decode($json);
        $this->assertSame([0, 1], accesstree::part_indexes($tree, 'completion'));
        $this->assertSame([2], accesstree::part_indexes($tree, 'grade'));
        $this->assertSame([3], accesstree::part_indexes($tree, 'group'));
        $this->assertSame([], accesstree::part_indexes($tree, 'from'));
        $this->assertCount(2, accesstree::managed_conditions($tree, 'completion'));

        // The group part changes; everything else stays byte for byte.
        $result = $this->edit($json, ['group' => $this->group('set', [9], true)]);
        $this->assertSame('{"op":"&","c":[{"type":"completion","cm":6,"e":1},{"type":"completion","cm":7,"e":1},' .
            '{"type":"grade","id":3,"min":50},{"type":"group","id":9}],"showc":[true,false,true,true]}',
            accesstree::encode($result));
        $result = $this->edit($json, ['group' => $this->group('remove'), 'from' => $this->date(self::FROM)]);
        $this->assertSame('{"op":"&","c":[{"type":"completion","cm":6,"e":1},{"type":"completion","cm":7,"e":1},' .
            '{"type":"grade","id":3,"min":50},{"type":"date","d":">=","t":' . self::FROM . '}],' .
            '"showc":[true,false,true,false]}', accesstree::encode($result));

        // Set: the new conditions flat, from the first sibling's position, each with the given display.
        $result = $this->edit($json, ['completion' => $this->completion([[7, 1], [8, 1], [6, 1]], true)]);
        $this->assertSame('{"op":"&","c":[{"type":"completion","cm":7,"e":1},{"type":"completion","cm":8,"e":1},' .
            '{"type":"completion","cm":6,"e":1},{"type":"grade","id":3,"min":50},{"type":"group","id":2}],' .
            '"showc":[true,true,true,true,true]}', accesstree::encode($result));
        // Remove: the siblings and their showc go.
        $result = $this->edit($json, ['completion' => $this->completion(null)]);
        $this->assertSame('{"op":"&","c":[{"type":"grade","id":3,"min":50},{"type":"group","id":2}],' .
            '"showc":[true,true]}', accesstree::encode($result));
        $result = $this->edit($json, ['grade' => $this->grade([['id' => 3, 'min' => 60]])]);
        $this->assertSame([true, false, false, true], $result->showc);
        $this->assertSame(60, $result->c[2]->min);

        // Nochange: the same conditions with the display each already has.
        $uniform = str_replace('"showc":[true,false,true,true]', '"showc":[false,false,true,true]', $json);
        $result = $this->edit($uniform, ['completion' => $this->completion([[7, 1], [6, 1]])]);
        $this->assertSame($uniform, accesstree::encode($result));
        // The mixed display of the form's JSON makes "both shown" a change.
        $result = $this->edit($json, ['completion' => $this->completion([[6, 1], [7, 1]], true)]);
        $this->assertSame([true, true, true, true], $result->showc);
    }

    /**
     * Completion and grade parts alone on no tree: one top-level child per condition.
     */
    public function test_completion_and_grade_no_tree() {
        $this->resetAfterTest();
        $result = $this->edit('', ['completion' => $this->completion([[12, 1]])]);
        $this->assertSame('{"op":"&","c":[{"type":"completion","cm":12,"e":1}],"showc":[false]}',
            accesstree::encode($result));
        $result = $this->edit('', ['completion' => $this->completion([[12, 1], [13, 2], [14, 0]], true)]);
        $this->assertSame('{"op":"&","c":[{"type":"completion","cm":12,"e":1},{"type":"completion","cm":13,"e":2},' .
            '{"type":"completion","cm":14,"e":0}],"showc":[true,true,true]}', accesstree::encode($result));
        $this->assertCount(3, accesstree::managed_conditions($result, 'completion'));
        $this->assertSame([0, 1, 2], accesstree::part_indexes($result, 'completion'));

        // Grade: neither bound (must have a grade), min only, both.
        $result = $this->edit('', ['grade' => $this->grade([['id' => 34]])]);
        $this->assertSame('{"op":"&","c":[{"type":"grade","id":34}],"showc":[false]}', accesstree::encode($result));
        $result = $this->edit('', ['grade' => $this->grade([['id' => 34, 'min' => 50.5], ['id' => 35, 'min' => 40,
            'max' => 80]])]);
        $this->assertSame('{"op":"&","c":[{"type":"grade","id":34,"min":50.5},{"type":"grade","id":35,"min":40,' .
            '"max":80}],"showc":[false,false]}', accesstree::encode($result));
        $this->assertSame([], accesstree::managed_conditions($result, 'completion'));
        $this->assertNull($this->edit('', ['completion' => $this->completion(null)]));
    }

    /**
     * All five parts on no tree, in the order group, from, until, completion, grade; the group and
     * date parts find their children next to completion and grade siblings and never move them.
     */
    public function test_new_parts_with_group_and_dates() {
        $this->resetAfterTest();
        $result = $this->edit('', ['grade' => $this->grade([['id' => 34, 'min' => 50]]),
            'completion' => $this->completion([[12, 1], [13, 1]]), 'until' => $this->date(self::UNTIL),
            'group' => $this->group('set', [5]), 'from' => $this->date(self::FROM, true)]);
        $json = accesstree::encode($result);
        $this->assertSame('{"op":"&","c":[{"type":"group","id":5},{"type":"date","d":">=","t":' . self::FROM . '},' .
            '{"type":"date","d":"<","t":' . self::UNTIL . '},{"type":"completion","cm":12,"e":1},' .
            '{"type":"completion","cm":13,"e":1},{"type":"grade","id":34,"min":50}],' .
            '"showc":[false,true,false,false,false,false]}', $json);
        $this->assertSame([5], accesstree::managed_groups($result));
        $this->assertSame(self::FROM, accesstree::managed_time($result, 'from'));
        $this->assertSame(self::UNTIL, accesstree::managed_time($result, 'until'));
        $this->assertSame([3, 4], accesstree::part_indexes($result, 'completion'));
        $this->assertSame([5], accesstree::part_indexes($result, 'grade'));

        // Changing the group or a date leaves the completion siblings alone, and the other way round.
        $group = $this->edit($json, ['group' => $this->group('set', [6, 7])]);
        foreach ([1, 2, 3, 4, 5] as $index) {
            $this->assertTrue(accesstree::same($result->c[$index], $group->c[$index]));
        }
        $this->assertSame($result->showc, $group->showc);
        $completion = $this->edit($json, ['completion' => $this->completion([[14, 1]], true)]);
        $this->assertSame('{"op":"&","c":[{"type":"group","id":5},{"type":"date","d":">=","t":' . self::FROM . '},' .
            '{"type":"date","d":"<","t":' . self::UNTIL . '},{"type":"completion","cm":14,"e":1},' .
            '{"type":"grade","id":34,"min":50}],"showc":[false,true,false,true,false]}', accesstree::encode($completion));
        // A nested "all of" set of completion conditions is not taken for a group or date part.
        $nested = '{"op":"&","c":[{"op":"&","c":[' . self::COMPLETION . ',{"type":"completion","cm":13,"e":0}]}],' .
            '"showc":[true]}';
        $added = $this->edit($nested, ['group' => $this->group('set', [5]), 'from' => $this->date(self::FROM)]);
        $this->assertCount(3, $added->c);
        $this->assertSame([0], accesstree::part_indexes($added, 'completion'));
        $this->assertSame(1, accesstree::managed_index($added, 'group'));
        $this->assertSame(2, accesstree::managed_index($added, 'from'));
    }

    /**
     * Flat siblings with mixed display are kept when another part changes; set gives every new
     * sibling the given display and keeps the first sibling's position; other children keep their
     * order.
     */
    public function test_flat_siblings_mixed_display() {
        $this->resetAfterTest();
        $stored = '{"op":"&","c":[' . self::COMPLETION . ',' . self::DATE . ',{"type":"completion","cm":13,"e":0},' .
            '{"type":"group","id":5}],"showc":[true,false,false,true]}';
        $result = $this->edit($stored, ['from' => $this->date(self::FROM + 60, true), 'group' => $this->group('remove')]);
        $this->assertSame('{"op":"&","c":[' . self::COMPLETION . ',{"type":"date","d":">=","t":' . (self::FROM + 60) .
            '},{"type":"completion","cm":13,"e":0}],"showc":[true,true,false]}', accesstree::encode($result));

        $result = $this->edit($stored, ['completion' => $this->completion([[20, 1], [21, 1], [22, 2]], true)]);
        $this->assertSame('{"op":"&","c":[{"type":"completion","cm":20,"e":1},{"type":"completion","cm":21,"e":1},' .
            '{"type":"completion","cm":22,"e":2},' . self::DATE . ',{"type":"group","id":5}],' .
            '"showc":[true,true,true,false,true]}', accesstree::encode($result));
        // The first sibling after other children: the new ones go where it was.
        $stored = '{"op":"&","c":[' . self::DATE . ',' . self::COMPLETION . ',{"type":"group","id":5},' .
            '{"type":"completion","cm":13,"e":0}],"showc":[false,true,true,false]}';
        $result = $this->edit($stored, ['completion' => $this->completion([[30, 1]])]);
        $this->assertSame('{"op":"&","c":[' . self::DATE . ',{"type":"completion","cm":30,"e":1},' .
            '{"type":"group","id":5}],"showc":[false,false,true]}', accesstree::encode($result));
        // Grade siblings the same way.
        $stored = '{"op":"&","c":[' . self::GRADE . ',' . self::COMPLETION . ',{"type":"grade","id":35}],' .
            '"showc":[false,true,true]}';
        $this->assertSame([0, 2], accesstree::part_indexes(accesstree::decode($stored), 'grade'));
        $result = $this->edit($stored, ['completion' => $this->completion([[12, 1]], false)]);
        $this->assertSame('{"op":"&","c":[' . self::GRADE . ',' . self::COMPLETION . ',{"type":"grade","id":35}],' .
            '"showc":[false,false,true]}', accesstree::encode($result));
        $this->assertNull($this->edit($stored, ['completion' => $this->completion(null),
            'grade' => $this->grade(null)]));
    }

    /**
     * A nested "all of" set made only of completion conditions is the completion part when it is the
     * only candidate; setting the part rewrites it flat; flat siblings next to such a set are refused.
     */
    public function test_nested_set() {
        $this->resetAfterTest();
        $stored = '{"op":"&","c":[{"type":"group","id":5},{"op":"&","c":[' . self::COMPLETION . ',' .
            '{"type":"completion","cm":13,"e":1}]},' . self::DATE . '],"showc":[false,true,false]}';
        $tree = accesstree::decode($stored);
        $this->assertSame([1], accesstree::part_indexes($tree, 'completion'));
        $this->assertCount(2, accesstree::managed_conditions($tree, 'completion'));
        // The same conditions in another order with the same display: left as stored.
        $same = $this->edit($stored, ['completion' => $this->completion([[13, 1], [12, 1]], true)]);
        $this->assertSame(accesstree::encode($tree), accesstree::encode($same));
        // Set: flat siblings where the set was.
        $result = $this->edit($stored, ['completion' => $this->completion([[14, 1], [15, 1]])]);
        $this->assertSame('{"op":"&","c":[{"type":"group","id":5},{"type":"completion","cm":14,"e":1},' .
            '{"type":"completion","cm":15,"e":1},' . self::DATE . '],"showc":[false,false,false,false]}',
            accesstree::encode($result));
        $this->assertNull($this->edit('{"op":"&","c":[{"op":"&","c":[' . self::GRADE . ']}],"showc":[true]}',
            ['grade' => $this->grade(null)]));

        // Flat siblings and a set: ambiguous, both paths named.
        $both = '{"op":"&","c":[' . self::COMPLETION . ',{"type":"group","id":5},{"op":"&","c":[' .
            '{"type":"completion","cm":13,"e":1}]}],"showc":[false,false,true]}';
        $this->assert_refused($both, ['completion' => $this->completion([[14, 1]])],
            'c[2] (an "all of" set of completion conditions) and c[0] are all completion restrictions');
        $this->assert_refused($both, ['completion' => $this->completion(null)], 'ambiguous');
        // Two sets.
        $this->assert_refused('{"op":"&","c":[{"op":"&","c":[' . self::COMPLETION . ']},{"op":"&","c":[' .
            '{"type":"completion","cm":13,"e":1}]}],"showc":[true,true]}', ['completion' => $this->completion(null)],
            'c[0] and c[1] are both completion restrictions');
        // The group part is not affected by the ambiguity.
        $this->assertCount(3, $this->edit($both, ['group' => $this->group('set', [6])])->c);
    }

    /**
     * "Any of" and "not all of" roots are wrapped as for the group part.
     */
    public function test_completion_wrap() {
        $this->resetAfterTest();
        $stored = '{"op":"|","show":true,"c":[' . self::DATE . ',{"type":"group","id":5}]}';
        $result = $this->edit($stored, ['completion' => $this->completion([[12, 1], [13, 1]])]);
        $this->assertSame('{"op":"&","c":[{"op":"|","c":[' . self::DATE . ',{"type":"group","id":5}]},' .
            '{"type":"completion","cm":12,"e":1},{"type":"completion","cm":13,"e":1}],"showc":[true,false,false]}',
            accesstree::encode($result));
        $stored = '{"op":"!|","c":[' . self::DATE . '],"showc":[false]}';
        $result = $this->edit($stored, ['grade' => $this->grade([['id' => 34]], true)]);
        $this->assertSame([false, true], $result->showc);
        $this->assertSame('!|', $result->c[0]->op);
        // Removing a part that is not there from such a root: unchanged.
        $this->assertSame('{"op":"|","show":true,"c":[' . self::DATE . ',{"type":"group","id":5}]}',
            accesstree::encode($this->edit('{"op":"|","show":true,"c":[' . self::DATE . ',{"type":"group","id":5}]}',
            ['completion' => $this->completion(null)])));
    }

    /**
     * Completion and grade conditions anywhere but their part are refused, naming where;
     * conditions of a type the call does not change are not looked at.
     */
    public function test_completion_and_grade_refusals() {
        $this->resetAfterTest();
        $set = ['completion' => $this->completion([[12, 1]])];
        $grade = ['grade' => $this->grade([['id' => 34]])];
        // Negated, in a subtree or as the root.
        $this->assert_refused('{"op":"&","c":[{"op":"!&","c":[' . self::COMPLETION . ']}],"showc":[true]}', $set,
            'c[0].c[0] is a completion restriction');
        $this->assert_refused('{"op":"!&","show":true,"c":[' . self::GRADE . ']}', $grade,
            'c[0] is a grade restriction');
        // Mixed into an "any of".
        $this->assert_refused('{"op":"&","c":[{"op":"|","c":[' . self::COMPLETION . ',' . self::DATE . ']}],' .
            '"showc":[true]}', $set, 'c[0].c[0] is a completion');
        // An "all of" set mixing completion and grade conditions: neither part manages it.
        $mixed = '{"op":"&","c":[{"op":"&","c":[' . self::COMPLETION . ',' . self::GRADE . ']}],"showc":[true]}';
        $this->assert_refused($mixed, $set, 'c[0].c[0] is a completion');
        $this->assert_refused($mixed, $grade, 'c[0].c[1] is a grade');
        // Nested deeper.
        $this->assert_refused('{"op":"&","c":[{"op":"&","c":[{"op":"&","c":[' . self::GRADE . ']}]}],"showc":[true]}',
            $grade, 'c[0].c[0].c[0] is a grade');
        // A completion condition with a cm the form would not store (a string) is not part of the part.
        $this->assert_refused('{"op":"&","c":[{"type":"completion","cm":"12","e":1}],"showc":[true]}', $set,
            'c[0] is a completion');
        // Removing is refused the same way.
        $this->assert_refused($mixed, ['completion' => $this->completion(null)], 'c[0].c[0]');

        // Two top-level completion conditions are the part (as the form stores them), not two candidates.
        $two = '{"op":"&","c":[' . self::COMPLETION . ',{"type":"completion","cm":13,"e":1}],"showc":[true,true]}';
        $this->assertSame([0, 1], accesstree::part_indexes(accesstree::decode($two), 'completion'));
        $this->assertSame('{"op":"&","c":[{"type":"completion","cm":14,"e":1}],"showc":[false]}',
            accesstree::encode($this->edit($two, ['completion' => $this->completion([[14, 1]])])));

        // The types not being changed are never inspected: a negated grade does not stop a completion
        // change, a completion condition inside an "any of" does not stop a group change.
        $result = $this->edit('{"op":"&","c":[{"op":"!&","c":[' . self::GRADE . ']}],"showc":[true]}', $set);
        $this->assertCount(2, $result->c);
        $result = $this->edit('{"op":"&","c":[{"op":"|","c":[' . self::COMPLETION . ',' . self::DATE . ']}],' .
            '"showc":[true]}', 'set', [5]);
        $this->assertCount(2, $result->c);
    }

    /**
     * Bad conditions in the request.
     */
    public function test_completion_and_grade_bad_conditions() {
        $this->resetAfterTest();
        $this->assert_refused('', ['completion' => ['action' => 'set', 'conditions' => []]], 'at least one condition',
            'invalidparameter');
        $this->assert_refused('', ['completion' => $this->completion([[12, 5]])], 'state', 'invalidparameter');
        $this->assert_refused('', ['completion' => $this->completion([[0, 1]])], 'course module id', 'invalidparameter');
        $this->assert_refused('', ['completion' => $this->completion([[12, 1], [12, 0]])], 'cm 12 is given twice',
            'invalidparameter');
        $this->assert_refused('', ['grade' => $this->grade([['id' => 34, 'min' => 60, 'max' => 60]])], 'min must be below',
            'invalidsettings');
        $this->assert_refused('', ['grade' => $this->grade([['id' => 34, 'min' => 120]])], 'percentages',
            'invalidsettings');
        $this->assert_refused('', ['grade' => $this->grade([['id' => 34, 'max' => -1]])], 'percentages',
            'invalidsettings');
        $this->assert_refused('', ['grade' => $this->grade([['id' => 34], ['id' => 34, 'min' => 10]])],
            'grade item 34 is given twice', 'invalidparameter');
        $this->assert_refused('', ['grade' => $this->grade([['id' => 34, 'min' => '50']])], 'must be a number',
            'invalidparameter');
        $this->assert_refused('', ['grade' => $this->grade([['min' => 50]])], 'grade item id', 'invalidparameter');
    }

    /**
     * Setting what is stored, as a set in any order with the given display on each, gives the tree as
     * stored, in its stored order.
     */
    public function test_completion_and_grade_nochange() {
        $this->resetAfterTest();
        $stored = '{"op":"&","c":[{"type":"group","id":5},{"type":"completion","cm":12,"e":1},' .
            '{"type":"completion","cm":13,"e":2},{"type":"grade","id":34,"min":50.5},{"type":"grade","id":35,' .
            '"max":40}],"showc":[false,true,true,false,false]}';
        $result = $this->edit($stored, ['completion' => $this->completion([[13, 2], [12, 1]], true),
            'grade' => $this->grade([['id' => 35, 'max' => 40], ['id' => 34, 'min' => 50.5]])]);
        $this->assertSame($stored, accesstree::encode($result));
        // A different state, bound or display is a change.
        $this->assertNotSame($stored, accesstree::encode($this->edit($stored,
            ['completion' => $this->completion([[13, 1], [12, 1]], true)])));
        $this->assertNotSame($stored, accesstree::encode($this->edit($stored,
            ['grade' => $this->grade([['id' => 35, 'max' => 40], ['id' => 34, 'min' => 50]])])));
        $this->assertNotSame($stored, accesstree::encode($this->edit($stored,
            ['completion' => $this->completion([[13, 2], [12, 1]], false)])));
        // A subset is a change.
        $this->assertNotSame($stored, accesstree::encode($this->edit($stored,
            ['completion' => $this->completion([[12, 1]], true)])));
    }
}

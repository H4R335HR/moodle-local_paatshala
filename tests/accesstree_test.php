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
        $this->assert_refused('', ['completion' => ['action' => 'remove']], 'unknown part', 'invalidparameter');
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
}

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
 * Tests for the group restriction tree editing.
 *
 * @package    local_paatshala
 * @copyright  2026 local_paatshala contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_paatshala;

use local_paatshala\local\grouptree;
use local_paatshala\local\grouptree_exception;

/**
 * Tests for the group restriction tree editing.
 *
 * @package    local_paatshala
 * @copyright  2026 local_paatshala contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class grouptree_test extends \advanced_testcase {

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
     * @param string $action
     * @param int[] $groupids
     * @param bool $show
     * @return \stdClass|null
     */
    protected function edit(string $json, string $action, array $groupids = [], bool $show = false) {
        $result = grouptree::edit(grouptree::decode($json), $action, $groupids, $show);
        if ($result !== null) {
            new \core_availability\tree(json_decode(grouptree::encode($result)));
        }
        return $result;
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
     * @param string $action
     * @param string $reason
     */
    protected function assert_refused(string $json, string $action, string $reason) {
        try {
            grouptree::edit(grouptree::decode($json), $action, [5]);
            $this->fail('Expected a refusal: ' . $reason);
        } catch (grouptree_exception $e) {
            $this->assertStringContainsString($reason, $e->getMessage());
        }
    }

    /**
     * No tree: a new "all of" root with the group condition.
     */
    public function test_no_tree() {
        $this->resetAfterTest();
        $result = $this->edit('', 'set', [3348]);
        $this->assertSame('{"op":"&","c":[{"type":"group","id":3348}],"showc":[false]}', grouptree::encode($result));
        $result = $this->edit('', 'set', [3348], true);
        $this->assertSame([true], $result->showc);
        // An empty tree, as the section form stores after removing everything.
        $result = $this->edit('{"op":"&","c":[],"showc":[]}', 'set', [7]);
        $this->assertSame('{"op":"&","c":[{"type":"group","id":7}],"showc":[false]}', grouptree::encode($result));
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
            $this->assertTrue(grouptree::same($this->j($condition), $result->c[$index]));
        }
        $this->assertSame([true, false, true, false], $result->showc);
        $this->assertTrue(grouptree::same($this->j('{"type":"group","id":3348}'), $result->c[3]));
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
        $this->assertTrue(grouptree::same(
            $this->j('{"op":"|","c":[{"type":"group","id":7},{"type":"group","id":8}]}'), $result->c[1]));
        $this->assertSame([true, true, true], $result->showc);
        $this->assertTrue(grouptree::same($this->j(self::DATE), $result->c[0]));
        $this->assertTrue(grouptree::same($this->j(self::GRADE), $result->c[2]));

        // And back to one group, still in place.
        $again = $this->edit(grouptree::encode($result), 'set', [9]);
        $this->assertTrue(grouptree::same($this->j('{"type":"group","id":9}'), $again->c[1]));
        $this->assertSame([true, false, true], $again->showc);
        $this->assertSame([9], grouptree::managed_groups($again));
        $this->assertSame([7, 8], grouptree::managed_groups($result));
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
            $this->assertTrue(grouptree::same(
                $this->j('{"op":"' . $op . '","c":[' . self::DATE . ',' . self::COMPLETION . ']}'), $result->c[0]));
            $this->assertSame([false, true], $result->showc);

            // Removing the group again keeps the wrap.
            $removed = $this->edit(grouptree::encode($result), 'remove');
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
        $this->assertTrue(grouptree::same(
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
        $this->assertTrue(grouptree::same($this->j(self::DATE), $result->c[0]));
        $this->assertTrue(grouptree::same($this->j(self::GRADE), $result->c[1]));
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
        try {
            grouptree::edit(null, 'set', []);
            $this->fail('No groups');
        } catch (grouptree_exception $e) {
            $this->assertStringContainsString('at least one group', $e->getMessage());
        }
        try {
            grouptree::decode('{"op":');
            $this->fail('Invalid JSON');
        } catch (grouptree_exception $e) {
            $this->assertStringContainsString('Invalid availability text', $e->getMessage());
        }
    }

    /**
     * Edits that change nothing compare as the same structure, whatever the key order.
     */
    public function test_nochange() {
        $this->resetAfterTest();
        $stored = '{"showc":[true,false],"op":"&","c":[' . self::DATE . ',{"id":5,"type":"group"}]}';
        $tree = grouptree::decode($stored);
        $this->assertTrue(grouptree::same($tree, grouptree::edit($tree, 'set', [5], false)));
        $this->assertFalse(grouptree::same($tree, grouptree::edit($tree, 'set', [5], true)));
        $plain = grouptree::decode('{"op":"&","c":[' . self::DATE . '],"showc":[true]}');
        $this->assertTrue(grouptree::same($plain, grouptree::edit($plain, 'remove')));
        $this->assertTrue(grouptree::same(null, grouptree::edit(null, 'remove')));
        // A list and an object are not the same.
        $this->assertFalse(grouptree::same($this->j('{"op":"&","c":[],"showc":[]}'), $this->j('{"op":"&","c":{},"showc":[]}')));
    }
}

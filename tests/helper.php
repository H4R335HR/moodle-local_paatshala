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
 * Shared test helpers for local_paatshala.
 *
 * @package    local_paatshala
 * @copyright  2026 local_paatshala contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_paatshala;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/externallib.php');
require_once($CFG->dirroot . '/webservice/tests/helpers.php');

/**
 * Shared test helpers: plugin settings and a ws_automation-like user.
 *
 * @package    local_paatshala
 * @copyright  2026 local_paatshala contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class testcase extends \externallib_advanced_testcase {

    /** @var int Role id of the web service role. */
    protected $wsroleid;

    /** @var \stdClass Web service user. */
    protected $wsuser;

    /**
     * Enable the plugin and allow courses.
     *
     * @param string $courses allowedcourses value.
     */
    protected function enable_plugin(string $courses = '*') {
        // Refused changes roll back their delegated transaction; without this, the test's own
        // wrapping transaction would refuse every later commit in the same test.
        $this->preventResetByRollback();
        set_config('enabled', 1, 'local_paatshala');
        set_config('allowedcourses', $courses, 'local_paatshala');
    }

    /**
     * Create a ws_automation-like user with a system role holding the plugin capability,
     * course access and the given capabilities, and log in as them.
     *
     * @param string[] $capabilities
     * @return \stdClass
     */
    protected function login_ws_user(array $capabilities): \stdClass {
        $this->wsuser = $this->getDataGenerator()->create_user();
        $this->wsroleid = $this->getDataGenerator()->create_role();
        $syscontext = \context_system::instance();
        $base = ['local/paatshala:use', 'moodle/course:view', 'moodle/course:viewhiddenactivities',
            'moodle/course:viewhiddencourses', 'mod/assign:view', 'mod/workshop:view', 'mod/quiz:view', 'mod/page:view'];
        foreach (array_merge($base, $capabilities) as $capability) {
            assign_capability($capability, CAP_ALLOW, $this->wsroleid, $syscontext->id, true);
        }
        role_assign($this->wsroleid, $this->wsuser->id, $syscontext->id);
        accesslib_clear_all_caches_for_unit_testing();
        $this->setUser($this->wsuser);
        return $this->wsuser;
    }

    /**
     * Take a capability away from the web service role.
     *
     * @param string $capability
     */
    protected function remove_capability(string $capability) {
        unassign_capability($capability, $this->wsroleid, \context_system::instance()->id);
        accesslib_clear_all_caches_for_unit_testing();
    }

    /**
     * Assert that a call fails with a moodle_exception of this errorcode.
     *
     * @param string $errorcode
     * @param callable $call
     * @return \moodle_exception
     */
    protected function assert_error(string $errorcode, callable $call): \moodle_exception {
        try {
            $call();
        } catch (\moodle_exception $e) {
            $this->assertSame($errorcode, $e->errorcode, $e->getMessage() . ' ' . $e->debuginfo);
            return $e;
        }
        $this->fail("Expected error $errorcode");
    }

    /**
     * Journal entry as an array with before/after/target decoded.
     *
     * @param int $id
     * @return array
     */
    protected function journal_entry(int $id): array {
        $entry = (array)journal::get($id);
        foreach (['target', 'beforestate', 'afterstate'] as $field) {
            $entry[$field] = json_decode($entry[$field], true);
        }
        return $entry;
    }
}

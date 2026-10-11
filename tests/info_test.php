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
 * Tests for local_paatshala_info and the plugin gate.
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

use context_course;
use context_coursecat;
use context_system;
use external_api;
use local_paatshala\external\info;
use moodle_exception;
use required_capability_exception;
use stdClass;

/**
 * Tests for local_paatshala_info and the plugin gate.
 *
 * @package    local_paatshala
 * @copyright  2026 local_paatshala contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class info_test extends \externallib_advanced_testcase {

    /**
     * Create a user holding local/paatshala:use at system level and log in as them.
     *
     * @return stdClass
     */
    protected function login_ws_user(): stdClass {
        $user = $this->getDataGenerator()->create_user();
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability('local/paatshala:use', CAP_ALLOW, $roleid, context_system::instance()->id);
        role_assign($roleid, $user->id, context_system::instance()->id);
        $this->setUser($user);
        return $user;
    }

    /**
     * Info reports version, settings, functions and recycle bin settings.
     */
    public function test_info() {
        $this->resetAfterTest();
        $this->login_ws_user();
        set_config('enabled', 1, 'local_paatshala');
        set_config('allowedcourses', '4, 5', 'local_paatshala');
        set_config('coursebinenable', 1, 'tool_recyclebin');
        set_config('coursebinexpiry', 2 * WEEKSECS, 'tool_recyclebin');
        set_config('categorybinenable', 0, 'tool_recyclebin');

        $result = external_api::clean_returnvalue(info::execute_returns(), info::execute());

        $plugin = new stdClass();
        require(__DIR__ . '/../version.php');
        $this->assertSame($plugin->version, $result['version']);
        $this->assertSame($plugin->release, $result['release']);
        $this->assertTrue($result['enabled']);
        $this->assertSame('4, 5', $result['allowedcourses']);
        $this->assertSame('', $result['allowedcategories']);
        $this->assertContains('local_paatshala_info', $result['functions']);
        $this->assertTrue($result['recyclebin']['coursebinenable']);
        $this->assertSame(2 * WEEKSECS, $result['recyclebin']['coursebinexpiry']);
        $this->assertFalse($result['recyclebin']['categorybinenable']);
    }

    /**
     * Every function in db/services.php is installed and resolves to a valid implementation.
     */
    public function test_services_resolve() {
        global $CFG, $DB;
        $functions = [];
        include($CFG->dirroot . '/local/paatshala/db/services.php');
        foreach ($functions as $name => $definition) {
            $this->assertTrue($DB->record_exists('external_functions', ['name' => $name]), $name);
            $info = external_api::external_function_info($name);
            $this->assertSame($definition['classname'], $info->classname);
            $this->assertInstanceOf(\external_function_parameters::class, $info->parameters_desc);
            $this->assertNotNull($info->returns_desc, $name);
        }
    }

    /**
     * Info answers while the plugin is disabled, so the caller can see that it is.
     */
    public function test_info_when_disabled() {
        $this->resetAfterTest();
        $this->login_ws_user();
        $result = external_api::clean_returnvalue(info::execute_returns(), info::execute());
        $this->assertFalse($result['enabled']);
    }

    /**
     * Info needs local/paatshala:use in the system context.
     */
    public function test_info_requires_capability() {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        $this->expectException(required_capability_exception::class);
        info::execute();
    }

    /**
     * Allowlist parsing.
     */
    public function test_parse_list() {
        $this->assertSame([], gate::parse_list(''));
        $this->assertSame([], gate::parse_list(false));
        $this->assertNull(gate::parse_list('*'));
        $this->assertNull(gate::parse_list(' * '));
        $this->assertSame([4, 5, 12], gate::parse_list('4, 5,12,4'));
    }

    /**
     * Course allowlist: ids, "*", empty, and never the site course.
     */
    public function test_course_allowed() {
        $this->resetAfterTest();
        $this->assertFalse(gate::course_allowed(5));
        set_config('allowedcourses', '5', 'local_paatshala');
        $this->assertTrue(gate::course_allowed(5));
        $this->assertFalse(gate::course_allowed(6));
        set_config('allowedcourses', '*', 'local_paatshala');
        $this->assertTrue(gate::course_allowed(6));
        $this->assertFalse(gate::course_allowed(SITEID));
    }

    /**
     * The course gate checks enabled, allowlist, then the capability.
     */
    public function test_course_gate() {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $roleid = $this->getDataGenerator()->create_role();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'editingteacher');
        $this->setUser($user);

        try {
            gate::course($course->id);
            $this->fail('Expected disabled');
        } catch (moodle_exception $e) {
            $this->assertSame('disabled', $e->errorcode);
        }

        set_config('enabled', 1, 'local_paatshala');
        try {
            gate::course($course->id);
            $this->fail('Expected coursenotallowed');
        } catch (moodle_exception $e) {
            $this->assertSame('coursenotallowed', $e->errorcode);
        }

        set_config('allowedcourses', '*', 'local_paatshala');
        try {
            gate::course($course->id);
            $this->fail('Expected nopermissions');
        } catch (required_capability_exception $e) {
            $this->assertSame('nopermissions', $e->errorcode);
        }

        assign_capability('local/paatshala:use', CAP_ALLOW, $roleid, context_system::instance()->id);
        role_assign($roleid, $user->id, context_course::instance($course->id)->id);
        $this->assertSame(context_course::instance($course->id)->id, gate::course($course->id)->id);
    }

    /**
     * The category gate checks allowedcategories.
     */
    public function test_category_gate() {
        $this->resetAfterTest();
        $this->setAdminUser();
        $category = $this->getDataGenerator()->create_category();
        set_config('enabled', 1, 'local_paatshala');
        try {
            gate::category($category->id);
            $this->fail('Expected categorynotallowed');
        } catch (moodle_exception $e) {
            $this->assertSame('categorynotallowed', $e->errorcode);
        }
        set_config('allowedcategories', (string)$category->id, 'local_paatshala');
        $this->assertSame(context_coursecat::instance($category->id)->id, gate::category($category->id)->id);
    }
}

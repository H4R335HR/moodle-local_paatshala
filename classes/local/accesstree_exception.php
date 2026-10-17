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
 * Why an availability tree cannot be edited.
 *
 * @package    local_paatshala
 * @copyright  2026 local_paatshala contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_paatshala\local;

/**
 * Why an availability tree cannot be edited; the message is the reason.
 *
 * @package    local_paatshala
 * @copyright  2026 local_paatshala contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class accesstree_exception extends \Exception {

    /** @var string The web service error code to report it with. */
    public $errorcode;

    /**
     * Constructor.
     *
     * @param string $message The reason.
     * @param string $errorcode invalidavailability (the tree), invalidsettings (the dates) or
     *     invalidparameter (the request).
     */
    public function __construct(string $message, string $errorcode = 'invalidavailability') {
        parent::__construct($message);
        $this->errorcode = $errorcode;
    }
}

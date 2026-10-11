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
 * State comparison for compare-and-set.
 *
 * @package    local_paatshala
 * @copyright  2026 local_paatshala contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_paatshala\local;

use local_paatshala\journal;

/**
 * State comparison for compare-and-set.
 *
 * @package    local_paatshala
 * @copyright  2026 local_paatshala contributors
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class state {

    /**
     * Decode an `expected` parameter.
     *
     * @param string|null $json JSON object, or null/'' for none.
     * @param string[] $allowed Keys the function's input has.
     * @return array|null Null when not given.
     * @throws \invalid_parameter_exception on bad JSON or unknown keys.
     */
    public static function decode_expected($json, array $allowed) {
        if ($json === null || trim($json) === '') {
            return null;
        }
        $decoded = json_decode($json, true);
        if (!is_array($decoded) || ($decoded && array_keys($decoded) === range(0, count($decoded) - 1))) {
            throw new \invalid_parameter_exception('expected must be a JSON object');
        }
        $unknown = array_diff(array_keys($decoded), $allowed);
        if ($unknown) {
            throw new \invalid_parameter_exception('expected has unknown keys: ' . implode(', ', $unknown) .
                '; allowed: ' . implode(', ', $allowed));
        }
        foreach ($decoded as $key => $value) {
            if (is_array($value)) {
                throw new \invalid_parameter_exception('expected.' . $key . ' must be a scalar');
            }
        }
        return $decoded;
    }

    /**
     * Whether two values are the same setting value (numbers compare as numbers).
     *
     * @param mixed $a
     * @param mixed $b
     * @return bool
     */
    public static function same($a, $b): bool {
        if (is_bool($a)) {
            $a = (int)$a;
        }
        if (is_bool($b)) {
            $b = (int)$b;
        }
        if ($a === null || $b === null) {
            return $a === $b;
        }
        if (is_numeric($a) && is_numeric($b)) {
            return (float)$a == (float)$b;
        }
        return (string)$a === (string)$b;
    }

    /**
     * Whether $current holds every value in $wanted.
     *
     * @param array $wanted
     * @param array $current
     * @return bool
     */
    public static function matches(array $wanted, array $current): bool {
        foreach ($wanted as $key => $value) {
            if (!array_key_exists($key, $current) || !self::same($value, $current[$key])) {
                return false;
            }
        }
        return true;
    }

    /**
     * Refuse with errorcode 'conflict' unless $current holds every value in $wanted.
     *
     * The current state goes in the message as well as the debuginfo: the REST server
     * only returns debuginfo when debugging is on (webservice/rest/locallib.php).
     *
     * @param array|null $wanted Null: nothing to check.
     * @param array $current
     * @throws \moodle_exception conflict
     */
    public static function require_matches($wanted, array $current) {
        if ($wanted !== null && !self::matches($wanted, $current)) {
            $json = journal::encode($current);
            throw new \moodle_exception('conflict', 'local_paatshala', '', $json, $json);
        }
    }

    /**
     * The values of some keys only.
     *
     * @param array $state
     * @param string[] $keys
     * @return array
     */
    public static function subset(array $state, array $keys): array {
        $result = [];
        foreach ($keys as $key) {
            if (array_key_exists($key, $state)) {
                $result[$key] = $state[$key];
            }
        }
        return $result;
    }
}

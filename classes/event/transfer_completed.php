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
 * Event: a queued server-side transfer finished successfully.
 *
 * @package    repository_largefile
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace repository_largefile\event;

/**
 * Event: a queued server-side transfer finished successfully.
 *
 * @package    repository_largefile
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class transfer_completed extends \core\event\base {
    /**
     * Initialise the event data.
     *
     * @return void
     */
    protected function init() {
        $this->data['crud'] = 'c';
        $this->data['edulevel'] = self::LEVEL_OTHER;
    }

    /**
     * Build the event from a finished transfer row.
     *
     * @param \stdClass $transfer The transfer row.
     * @param string $result A short description of the result (e.g. a file name).
     * @return self
     */
    public static function for_transfer(\stdClass $transfer, string $result): self {
        return self::create([
            'context' => \context_system::instance(),
            'userid' => (int) $transfer->userid,
            'other' => ['type' => $transfer->type, 'result' => $result],
        ]);
    }

    /**
     * The event name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('eventtransfercompleted', 'repository_largefile');
    }

    /**
     * A human-readable description of the event.
     *
     * @return string
     */
    public function get_description() {
        return "A queued transfer of type '{$this->other['type']}' for the user with id " .
            "'{$this->userid}' completed, producing '{$this->other['result']}'.";
    }
}

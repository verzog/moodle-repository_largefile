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
 * Event: a backup was published as a share to a peer.
 *
 * @package    repository_largefile
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace repository_largefile\event;

/**
 * Event: a backup was published as a share to a peer.
 *
 * @package    repository_largefile
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class share_created extends \core\event\base {
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
     * Build the event for a newly created share row.
     *
     * @param \stdClass $share The share row.
     * @return self
     */
    public static function for_share(\stdClass $share): self {
        return self::create([
            'context' => \context_system::instance(),
            'userid' => (int) $share->userid,
            'other' => [
                'token' => $share->token,
                'peerid' => (int) $share->peerid,
                'filename' => $share->filename,
            ],
        ]);
    }

    /**
     * The event name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('eventsharecreated', 'repository_largefile');
    }

    /**
     * A human-readable description of the event.
     *
     * @return string
     */
    public function get_description() {
        return "The user with id '{$this->userid}' published backup '{$this->other['filename']}' " .
            "(token {$this->other['token']}) as a share to peer {$this->other['peerid']}.";
    }
}

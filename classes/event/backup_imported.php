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
 * Event: a backup shared by a peer was imported into this site.
 *
 * @package    repository_largefile
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace repository_largefile\event;

/**
 * Event: a backup shared by a peer was imported into this site.
 *
 * @package    repository_largefile
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class backup_imported extends \core\event\base {
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
     * Build the event.
     *
     * @param int $userid The importing user.
     * @param string $peername The source peer's name.
     * @param string $filename The imported file name.
     * @return self
     */
    public static function build(int $userid, string $peername, string $filename): self {
        return self::create([
            'context' => \context_system::instance(),
            'userid' => $userid,
            'other' => ['peername' => $peername, 'filename' => $filename],
        ]);
    }

    /**
     * The event name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('eventbackupimported', 'repository_largefile');
    }

    /**
     * A human-readable description of the event.
     *
     * @return string
     */
    public function get_description() {
        return "The user with id '{$this->userid}' imported the shared backup " .
            "'{$this->other['filename']}' from peer '{$this->other['peername']}'.";
    }
}

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
 * CRUD for trusted peer sites and their pairing secrets.
 *
 * The pairing secret is stored encrypted with the site key (\core\encryption),
 * so a database dump alone does not leak it, and never leaves the server in a
 * share URL — it only ever authenticates a request via HMAC ({@see signer}).
 *
 * @package    repository_largefile
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace repository_largefile\local;

/**
 * CRUD for trusted peer sites and their pairing secrets.
 *
 * @package    repository_largefile
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class peer_manager {
    /** @var string The peers table. */
    private const TABLE = 'repository_largefile_peers';

    /**
     * Create a trusted peer.
     *
     * @param string $name The peer's display name (unique).
     * @param string $secret The pairing secret, in the clear.
     * @return int The new peer id.
     */
    public static function create(string $name, string $secret): int {
        global $DB;
        $now = time();
        return (int) $DB->insert_record(self::TABLE, (object) [
            'name' => $name,
            'sharedsecret' => \core\encryption::encrypt($secret),
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
    }

    /**
     * Update a peer's name and, optionally, its secret.
     *
     * @param int $id The peer id.
     * @param string $name The new display name.
     * @param string|null $secret A new secret, or null to keep the existing one.
     * @return void
     */
    public static function update(int $id, string $name, ?string $secret = null): void {
        global $DB;
        $record = (object) ['id' => $id, 'name' => $name, 'timemodified' => time()];
        if ($secret !== null && $secret !== '') {
            $record->sharedsecret = \core\encryption::encrypt($secret);
        }
        $DB->update_record(self::TABLE, $record);
    }

    /**
     * Delete a peer.
     *
     * @param int $id The peer id.
     * @return void
     */
    public static function delete(int $id): void {
        global $DB;
        $DB->delete_records(self::TABLE, ['id' => $id]);
    }

    /**
     * Fetch a peer row (without decrypting its secret).
     *
     * @param int $id The peer id.
     * @return \stdClass|null The row, or null if not found.
     */
    public static function get(int $id): ?\stdClass {
        global $DB;
        $record = $DB->get_record(self::TABLE, ['id' => $id], '*', IGNORE_MISSING);
        return $record ?: null;
    }

    /**
     * Decrypt and return a peer's pairing secret.
     *
     * @param int $id The peer id.
     * @return string|null The secret, or null if the peer does not exist.
     */
    public static function get_secret(int $id): ?string {
        $peer = self::get($id);
        if (!$peer) {
            return null;
        }
        return \core\encryption::decrypt($peer->sharedsecret);
    }

    /**
     * List all peers, ordered by name.
     *
     * @return array Array of peer rows.
     */
    public static function get_all(): array {
        global $DB;
        return $DB->get_records(self::TABLE, null, 'name ASC');
    }

    /**
     * A menu of peers (id => name) for a select element.
     *
     * @return array id => name.
     */
    public static function menu(): array {
        $menu = [];
        foreach (self::get_all() as $peer) {
            $menu[$peer->id] = $peer->name;
        }
        return $menu;
    }
}

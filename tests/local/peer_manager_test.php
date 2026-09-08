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

namespace repository_largefile\local;

/**
 * Tests for trusted-peer CRUD, including the registered site URL and its host.
 *
 * @package    repository_largefile
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \repository_largefile\local\peer_manager
 */
final class peer_manager_test extends \advanced_testcase {
    /**
     * A created peer stores its site URL and exposes its host, lower-cased.
     *
     * @return void
     */
    public function test_create_stores_baseurl_and_host(): void {
        $this->resetAfterTest();
        $id = peer_manager::create('Partner', str_repeat('s', 24), 'https://Peer.Example.org:8443/moodle');

        $peer = peer_manager::get($id);
        $this->assertSame('https://Peer.Example.org:8443/moodle', $peer->baseurl);
        $this->assertSame('peer.example.org', peer_manager::get_host($id));
    }

    /**
     * Updating a peer can change its site URL while keeping the secret.
     *
     * @return void
     */
    public function test_update_changes_baseurl(): void {
        $this->resetAfterTest();
        $id = peer_manager::create('Partner', str_repeat('s', 24), 'https://old.example.org');

        peer_manager::update($id, 'Partner', null, 'https://new.example.org');

        $this->assertSame('new.example.org', peer_manager::get_host($id));
        // The secret is untouched when the update passes null for it.
        $this->assertSame(str_repeat('s', 24), peer_manager::get_secret($id));
    }

    /**
     * A peer with no registered site URL has no host (the legacy path).
     *
     * @return void
     */
    public function test_missing_baseurl_yields_null_host(): void {
        $this->resetAfterTest();
        $id = peer_manager::create('Legacy', str_repeat('s', 24));

        $this->assertSame('', peer_manager::get($id)->baseurl);
        $this->assertNull(peer_manager::get_host($id));
    }

    /**
     * get_host returns null for a peer that does not exist.
     *
     * @return void
     */
    public function test_host_of_unknown_peer_is_null(): void {
        $this->resetAfterTest();
        $this->assertNull(peer_manager::get_host(999999));
    }

    /**
     * Deleting a peer revokes every share published to it (rows and encrypted files),
     * leaving another peer's shares untouched.
     *
     * @return void
     */
    public function test_delete_revokes_the_peers_shares(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $gone = peer_manager::create('Gone', str_repeat('s', 24), 'https://gone.example.org');
        $kept = peer_manager::create('Kept', str_repeat('k', 24), 'https://kept.example.org');
        $plain = make_request_directory() . '/backup.mbz';
        file_put_contents($plain, random_bytes(64));
        $goneshare = share_manager::create($gone, $plain, 'backup.mbz', 0, 0, 2);
        $keptshare = share_manager::create($kept, $plain, 'backup.mbz', 0, 0, 2);

        peer_manager::delete($gone);

        $this->assertNull(peer_manager::get($gone));
        $this->assertNull(share_manager::get_by_token($goneshare->token));
        $this->assertNull(share_manager::get_encrypted_file($goneshare));
        $this->assertNotNull(share_manager::get_by_token($keptshare->token));
        $this->assertNotNull(share_manager::get_encrypted_file($keptshare));
        $this->assertSame(1, $DB->count_records('repository_largefile_shares'));
    }

    /**
     * A peer site URL must be a real URL and use https, unless the site has opted in
     * to insecure peers with the config-only flag.
     *
     * @return void
     */
    public function test_baseurl_policy(): void {
        $this->resetAfterTest();
        $this->assertNull(peer_manager::baseurl_error('https://peer.example.org/moodle'));
        $this->assertSame('errorpeerbadurl', peer_manager::baseurl_error('peer.example.org'));
        $this->assertSame('errorpeerbadurl', peer_manager::baseurl_error('ftp://peer.example.org'));
        $this->assertSame('errorpeerinsecureurl', peer_manager::baseurl_error('http://peer.example.org'));

        set_config('allowinsecurepeers', 1, 'largefile');
        $this->assertNull(peer_manager::baseurl_error('http://peer.example.org'));
        $this->assertSame('errorpeerbadurl', peer_manager::baseurl_error('not a url'));
    }
}

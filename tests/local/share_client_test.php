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
 * Tests for the receiver's peer-host security gate.
 *
 * @package    repository_largefile
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \repository_largefile\local\share_client
 */
final class share_client_test extends \advanced_testcase {
    /**
     * Invoke the private peer_security() gate for a peer and share base URL.
     *
     * @param int $peerid The peer id.
     * @param string $base The share endpoint base URL.
     * @return object|null The security helper the gate returns.
     */
    private function invoke_peer_security(int $peerid, string $base): ?object {
        $method = new \ReflectionMethod(share_client::class, 'peer_security');
        $method->setAccessible(true);
        return $method->invoke(null, $peerid, $base);
    }

    /**
     * A share on the peer's registered host yields a scoped security helper.
     *
     * @return void
     */
    public function test_matching_host_returns_scoped_helper(): void {
        $this->resetAfterTest();
        $id = peer_manager::create('Partner', str_repeat('s', 24), 'https://peer.example.org');

        $helper = $this->invoke_peer_security($id, 'https://peer.example.org/repository/largefile/share.php');

        $this->assertInstanceOf(peer_curl_security::class, $helper);
    }

    /**
     * A share whose host differs from the peer's registered host is rejected.
     *
     * @return void
     */
    public function test_mismatched_host_is_rejected(): void {
        $this->resetAfterTest();
        $id = peer_manager::create('Partner', str_repeat('s', 24), 'https://peer.example.org');

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessageMatches('/registered site URL/');
        $this->invoke_peer_security($id, 'https://evil.example.org/repository/largefile/share.php');
    }

    /**
     * A legacy peer with no registered site URL defers to the site default (null).
     *
     * @return void
     */
    public function test_legacy_peer_defers_to_site_default(): void {
        $this->resetAfterTest();
        $id = peer_manager::create('Legacy', str_repeat('s', 24));

        $this->assertNull($this->invoke_peer_security($id, 'https://anywhere.example.org/share.php'));
    }
}

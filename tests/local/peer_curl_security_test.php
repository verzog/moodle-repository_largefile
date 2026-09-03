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
 * Tests for the peer-scoped cURL security helper.
 *
 * @package    repository_largefile
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \repository_largefile\local\peer_curl_security
 */
final class peer_curl_security_test extends \advanced_testcase {
    /**
     * Block a host by name so the site policy is active without needing DNS.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        set_config('curlsecurityblockedhosts', "blocked.example.org\npeer.example.org");
        set_config('curlsecurityallowedport', '');
    }

    /**
     * The one registered peer host is exempt even when the site policy blocks it.
     *
     * @return void
     */
    public function test_registered_peer_host_is_allowed(): void {
        $helper = new peer_curl_security('peer.example.org');
        $this->assertFalse($helper->url_is_blocked('https://peer.example.org/repository/largefile/share.php?token=x'));
        // Host match is case-insensitive.
        $this->assertFalse($helper->url_is_blocked('https://PEER.example.org/share.php'));
    }

    /**
     * Any other host still falls through to the site block (no open redirect out).
     *
     * @return void
     */
    public function test_other_hosts_stay_blocked(): void {
        $helper = new peer_curl_security('peer.example.org');
        // A different, explicitly blocked host — e.g. a redirect target — stays blocked.
        $this->assertTrue($helper->url_is_blocked('https://blocked.example.org/x'));
        // An unparseable URL is treated as blocked.
        $this->assertTrue($helper->url_is_blocked('not a url'));
    }

    /**
     * With no allowed host the helper behaves exactly like the site policy.
     *
     * @return void
     */
    public function test_empty_allowed_host_defers_to_site_policy(): void {
        $helper = new peer_curl_security('');
        $this->assertTrue($helper->url_is_blocked('https://peer.example.org/share.php'));
        $this->assertFalse($helper->url_is_blocked('https://ok.example.org/share.php'));
    }
}

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
 * Tests for publishing, claiming downloads of and revoking shares.
 *
 * @package    repository_largefile
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \repository_largefile\local\share_manager
 */
final class share_manager_test extends \advanced_testcase {
    /**
     * Publish a small share to a fresh peer with the given caps.
     *
     * @param int $expires Unix expiry, or 0 for never.
     * @param int $maxdownloads Download cap, or 0 for unlimited.
     * @return \stdClass The share row.
     */
    private function publish(int $expires, int $maxdownloads): \stdClass {
        $peerid = peer_manager::create('Peer ' . uniqid(), str_repeat('s', 24), 'https://peer.example.org');
        $plain = make_request_directory() . '/backup.mbz';
        file_put_contents($plain, random_bytes(64));
        return share_manager::create($peerid, $plain, 'backup.mbz', $expires, $maxdownloads, 2);
    }

    /**
     * A capped share hands out exactly as many downloads as its cap, then refuses;
     * the count is taken under a row lock so no two claims can share the last slot.
     *
     * @return void
     */
    public function test_claim_download_honours_the_cap(): void {
        $this->resetAfterTest();
        $share = $this->publish(0, 1);

        $this->assertTrue(share_manager::claim_download((int) $share->id));
        $this->assertFalse(share_manager::claim_download((int) $share->id));
        $this->assertSame(1, (int) share_manager::get_by_token($share->token)->downloadcount);
        $this->assertFalse(share_manager::is_valid(share_manager::get_by_token($share->token)));
    }

    /**
     * An uncapped share keeps counting downloads without ever refusing one, while an
     * expired share and an unknown share are refused.
     *
     * @return void
     */
    public function test_claim_download_unlimited_expired_and_missing(): void {
        $this->resetAfterTest();
        $open = $this->publish(0, 0);
        for ($i = 0; $i < 3; $i++) {
            $this->assertTrue(share_manager::claim_download((int) $open->id));
        }
        $this->assertSame(3, (int) share_manager::get_by_token($open->token)->downloadcount);

        $expired = $this->publish(time() - 10, 0);
        $this->assertFalse(share_manager::claim_download((int) $expired->id));
        $this->assertFalse(share_manager::claim_download(999999));
    }

    /**
     * Revoking every share of a peer removes their rows and encrypted files only.
     *
     * @return void
     */
    public function test_delete_for_peer(): void {
        $this->resetAfterTest();
        $a = $this->publish(0, 0);
        $b = $this->publish(0, 0);

        $this->assertSame(1, share_manager::delete_for_peer((int) $a->peerid));
        $this->assertNull(share_manager::get_by_token($a->token));
        $this->assertNull(share_manager::get_encrypted_file($a));
        $this->assertNotNull(share_manager::get_by_token($b->token));
        $this->assertNotNull(share_manager::get_encrypted_file($b));
        $this->assertSame(0, share_manager::delete_for_peer((int) $a->peerid));
    }
}

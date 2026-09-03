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
 * Tests for the transfer runner's outcome handling.
 *
 * @package    repository_largefile
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace repository_largefile\local;

/**
 * Tests for {@see transfer_runner}.
 *
 * @package    repository_largefile
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \repository_largefile\local\transfer_runner
 */
final class transfer_runner_test extends \advanced_testcase {
    /**
     * A transfer of an unrecognised type is marked failed rather than throwing.
     *
     * @return void
     */
    public function test_unknown_type_marks_failed(): void {
        $this->resetAfterTest(true);
        $id = transfer_manager::create('bogus', 1, []);
        transfer_runner::run(transfer_manager::get($id));
        $transfer = transfer_manager::get($id);
        $this->assertSame(transfer_manager::STATUS_FAILED, $transfer->status);
        $this->assertEquals(1, $transfer->attempts);
        $this->assertNotEmpty($transfer->error);
    }

    /**
     * A URL import whose URL is not a valid http(s) link fails cleanly, without a
     * network attempt, and leaves no staged file behind.
     *
     * @return void
     */
    public function test_url_import_rejects_unfetchable(): void {
        global $DB;
        $this->resetAfterTest(true);
        $id = transfer_manager::create(
            transfer_manager::TYPE_URL,
            1,
            ['url' => 'ftp://internal.example/secret'],
            0,
            \context_system::instance()->id
        );
        transfer_runner::run(transfer_manager::get($id));
        $this->assertSame(transfer_manager::STATUS_FAILED, transfer_manager::get($id)->status);
        $this->assertSame(0, $DB->count_records('repository_largefile_chunks'));
    }

    /**
     * A background publish encrypts the staged draft, records a share with a link,
     * and removes the plaintext source once it is done.
     *
     * @return void
     */
    public function test_share_publish_encrypts_and_links(): void {
        global $DB;
        $this->resetAfterTest(true);
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $peerid = peer_manager::create('Peer', str_repeat('s', 24), 'https://peer.example.org');

        // Stage a backup in the user's draft area, as the create-share form would.
        $usercontext = \context_user::instance($user->id);
        $draftid = file_get_unused_draft_itemid();
        $fs = get_file_storage();
        $fs->create_file_from_string([
            'contextid' => $usercontext->id,
            'component' => 'user',
            'filearea' => 'draft',
            'itemid' => $draftid,
            'filepath' => '/',
            'filename' => 'backup.mbz',
            'userid' => $user->id,
        ], 'PLAINTEXT-BACKUP-CONTENTS');

        $before = time();
        $id = transfer_manager::create(
            transfer_manager::TYPE_PUBLISH,
            (int) $user->id,
            ['peerid' => $peerid, 'draftid' => $draftid, 'expiryduration' => DAYSECS, 'maxdownloads' => 1],
            0,
            \context_system::instance()->id,
            'backup.mbz'
        );
        transfer_runner::run(transfer_manager::get($id));

        $transfer = transfer_manager::get($id);
        $this->assertSame(transfer_manager::STATUS_COMPLETED, $transfer->status);
        $this->assertStringContainsString('/repository/largefile/share.php', (string) $transfer->result);
        $this->assertStringContainsString('token=', (string) $transfer->result);
        // A share row was created, and the plaintext draft source was removed.
        $this->assertEquals(1, $DB->count_records('repository_largefile_shares'));
        $this->assertFalse($fs->file_exists($usercontext->id, 'user', 'draft', $draftid, '/', 'backup.mbz'));
        // The expiry is measured from when the share was created, not when queued.
        $share = $DB->get_record('repository_largefile_shares', []);
        $this->assertGreaterThanOrEqual($before + DAYSECS, (int) $share->expires);
    }

    /**
     * A publish whose staged draft is missing fails cleanly and creates no share.
     *
     * @return void
     */
    public function test_share_publish_missing_source_fails(): void {
        global $DB;
        $this->resetAfterTest(true);
        $user = $this->getDataGenerator()->create_user();
        $peerid = peer_manager::create('Peer', str_repeat('s', 24), 'https://peer.example.org');

        $id = transfer_manager::create(
            transfer_manager::TYPE_PUBLISH,
            (int) $user->id,
            ['peerid' => $peerid, 'draftid' => 999999, 'expiryduration' => 0, 'maxdownloads' => 1],
            0,
            \context_system::instance()->id,
            'gone.mbz'
        );
        transfer_runner::run(transfer_manager::get($id));

        $this->assertSame(transfer_manager::STATUS_FAILED, transfer_manager::get($id)->status);
        $this->assertSame(0, $DB->count_records('repository_largefile_shares'));
    }
}

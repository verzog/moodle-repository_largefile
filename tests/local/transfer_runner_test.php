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
     * Stage a plaintext backup for a publish transfer, as the create-share form does.
     *
     * @param int $transferid The publish transfer's id (the staged file's item id).
     * @param string $filename The staged file name.
     * @return void
     */
    private function stage_publish_source(int $transferid, string $filename): void {
        get_file_storage()->create_file_from_string([
            'contextid' => \context_system::instance()->id,
            'component' => 'repository_largefile',
            'filearea' => transfer_manager::PENDING_FILEAREA,
            'itemid' => $transferid,
            'filepath' => '/',
            'filename' => $filename,
        ], 'PLAINTEXT-BACKUP-CONTENTS');
    }

    /**
     * A background publish encrypts the staged backup, records a share with a link,
     * and removes the plaintext source once it is done.
     *
     * @return void
     */
    public function test_share_publish_encrypts_and_links(): void {
        global $DB;
        $this->resetAfterTest(true);
        $user = $this->getDataGenerator()->create_user();
        $peerid = peer_manager::create('Peer', str_repeat('s', 24), 'https://peer.example.org');

        $before = time();
        $id = transfer_manager::create(
            transfer_manager::TYPE_PUBLISH,
            (int) $user->id,
            ['peerid' => $peerid, 'expiryduration' => DAYSECS, 'maxdownloads' => 1],
            0,
            \context_system::instance()->id,
            'backup.mbz'
        );
        $this->stage_publish_source($id, 'backup.mbz');
        transfer_runner::run(transfer_manager::get($id));

        $transfer = transfer_manager::get($id);
        $this->assertSame(transfer_manager::STATUS_COMPLETED, $transfer->status);
        $this->assertStringContainsString('/repository/largefile/share.php', (string) $transfer->result);
        $this->assertStringContainsString('token=', (string) $transfer->result);
        // A share row was created, and the staged plaintext source was removed.
        $this->assertEquals(1, $DB->count_records('repository_largefile_shares'));
        $staged = get_file_storage()->get_area_files(
            \context_system::instance()->id,
            'repository_largefile',
            transfer_manager::PENDING_FILEAREA,
            $id,
            'id DESC',
            false
        );
        $this->assertEmpty($staged);
        // The expiry is measured from when the share was created, not when queued.
        $share = $DB->get_record('repository_largefile_shares', []);
        $this->assertGreaterThanOrEqual($before + DAYSECS, (int) $share->expires);
    }

    /**
     * A publish whose staged source is missing fails cleanly and creates no share.
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
            ['peerid' => $peerid, 'expiryduration' => 0, 'maxdownloads' => 1],
            0,
            \context_system::instance()->id,
            'gone.mbz'
        );
        transfer_runner::run(transfer_manager::get($id));

        $this->assertSame(transfer_manager::STATUS_FAILED, transfer_manager::get($id)->status);
        $this->assertSame(0, $DB->count_records('repository_largefile_shares'));
    }

    /**
     * The cleanup task removes a staged source left behind by a failed publish.
     *
     * @return void
     */
    public function test_cleanup_purges_orphaned_publish_source(): void {
        $this->resetAfterTest(true);
        $user = $this->getDataGenerator()->create_user();

        $id = transfer_manager::create(
            transfer_manager::TYPE_PUBLISH,
            (int) $user->id,
            ['peerid' => 1, 'expiryduration' => 0, 'maxdownloads' => 1],
            0,
            \context_system::instance()->id,
            'orphan.mbz'
        );
        $this->stage_publish_source($id, 'orphan.mbz');
        transfer_manager::claim($id);
        transfer_manager::mark_failed($id, 'boom');

        (new \repository_largefile\task\cleanup_chunks())->execute();

        $staged = get_file_storage()->get_area_files(
            \context_system::instance()->id,
            'repository_largefile',
            transfer_manager::PENDING_FILEAREA,
            $id,
            'id DESC',
            false
        );
        $this->assertEmpty($staged);
    }
}

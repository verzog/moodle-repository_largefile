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
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace repository_largefile\local;

/**
 * Tests for {@see transfer_runner}.
 *
 * @package    repository_largefile
 * @copyright  2026 Vernon Spain
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
        // A completed publish now notifies the owner, so capture the message.
        $this->redirectMessages();
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
        // A failed publish now notifies the owner, so capture the message.
        $this->redirectMessages();
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
     * Stage a completed chunked upload owned by a user, as the uploader does.
     *
     * @param int $userid The owner.
     * @param string $filename The staged file name.
     * @param string $contents The staged plaintext.
     * @return string The staged token id.
     */
    private function stage_completed_upload(int $userid, string $filename, string $contents): string {
        $token = \repository_largefile\chunk_store::create_token_for(
            $userid,
            \context_system::instance()->id,
            -1
        );
        $tmp = make_request_directory() . '/' . $filename;
        file_put_contents($tmp, $contents);
        \repository_largefile\chunk_store::adopt_file($token, $tmp, $filename);
        return $token;
    }

    /**
     * A publish that names a staged upload by token encrypts straight from the staged
     * file, records a share with a link, and leaves the staged upload in place.
     *
     * @return void
     */
    public function test_share_publish_from_token(): void {
        global $DB;
        $this->resetAfterTest(true);
        // Redirect messages so the completion notification does not error; delivery
        // itself is the message subsystem's concern, not asserted here.
        $this->redirectMessages();
        $user = $this->getDataGenerator()->create_user();
        $peerid = peer_manager::create('Peer', str_repeat('s', 24), 'https://peer.example.org');

        $token = $this->stage_completed_upload((int) $user->id, 'staged.mbz', 'PLAINTEXT-BACKUP');
        $id = transfer_manager::create(
            transfer_manager::TYPE_PUBLISH,
            (int) $user->id,
            [
                'peerid' => $peerid,
                'expiryduration' => DAYSECS,
                'maxdownloads' => 1,
                'sourcetype' => backup_source::TYPE_TOKEN,
                'token' => $token,
            ],
            0,
            \context_system::instance()->id,
            'staged.mbz'
        );
        transfer_runner::run(transfer_manager::get($id));

        $transfer = transfer_manager::get($id);
        $this->assertSame(transfer_manager::STATUS_COMPLETED, $transfer->status);
        $this->assertStringContainsString('token=', (string) $transfer->result);
        $this->assertEquals(1, $DB->count_records('repository_largefile_shares'));
        // The staged upload is left in place (an ordinary completed upload the owner
        // may reuse); it is not consumed by publishing.
        $this->assertNotNull(\repository_largefile\chunk_store::get_record($token));
    }

    /**
     * A publish that names a backup already in Moodle encrypts straight from the
     * stored file and leaves the original in place.
     *
     * @return void
     */
    public function test_share_publish_from_stored_file(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->redirectMessages();
        $user = $this->getDataGenerator()->create_user();
        $peerid = peer_manager::create('Peer', str_repeat('s', 24), 'https://peer.example.org');

        $usercontext = \context_user::instance((int) $user->id);
        $file = get_file_storage()->create_file_from_string([
            'contextid' => $usercontext->id,
            'component' => 'user',
            'filearea' => 'backup',
            'itemid' => 0,
            'filepath' => '/',
            'filename' => 'course.mbz',
        ], 'COURSE-BACKUP-CONTENTS');

        $id = transfer_manager::create(
            transfer_manager::TYPE_PUBLISH,
            (int) $user->id,
            [
                'peerid' => $peerid,
                'expiryduration' => 0,
                'maxdownloads' => 1,
                'sourcetype' => backup_source::TYPE_STORED,
                'fileid' => (int) $file->get_id(),
            ],
            0,
            \context_system::instance()->id,
            'course.mbz'
        );
        transfer_runner::run(transfer_manager::get($id));

        $this->assertSame(transfer_manager::STATUS_COMPLETED, transfer_manager::get($id)->status);
        $this->assertEquals(1, $DB->count_records('repository_largefile_shares'));
        // The user's own backup is untouched.
        $this->assertTrue(get_file_storage()->file_exists(
            $usercontext->id,
            'user',
            'backup',
            0,
            '/',
            'course.mbz'
        ));
    }

    /**
     * A publish that names a stored file the owner is not entitled to (here, another
     * user's backup) fails cleanly and creates no share.
     *
     * @return void
     */
    public function test_share_publish_stored_file_unauthorised_fails(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->redirectMessages();
        $owner = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        $peerid = peer_manager::create('Peer', str_repeat('s', 24), 'https://peer.example.org');

        // A backup in a different user's private backup area.
        $othercontext = \context_user::instance((int) $other->id);
        $file = get_file_storage()->create_file_from_string([
            'contextid' => $othercontext->id,
            'component' => 'user',
            'filearea' => 'backup',
            'itemid' => 0,
            'filepath' => '/',
            'filename' => 'notyours.mbz',
        ], 'SECRET');

        $id = transfer_manager::create(
            transfer_manager::TYPE_PUBLISH,
            (int) $owner->id,
            [
                'peerid' => $peerid,
                'expiryduration' => 0,
                'maxdownloads' => 1,
                'sourcetype' => backup_source::TYPE_STORED,
                'fileid' => (int) $file->get_id(),
            ],
            0,
            \context_system::instance()->id,
            'notyours.mbz'
        );
        transfer_runner::run(transfer_manager::get($id));

        $this->assertSame(transfer_manager::STATUS_FAILED, transfer_manager::get($id)->status);
        $this->assertSame(0, $DB->count_records('repository_largefile_shares'));
    }

    /**
     * The cleanup task keeps a staged upload that a scheduled publish still needs,
     * even past its retention window, and removes it once no live publish references it.
     *
     * @return void
     */
    public function test_cleanup_keeps_token_referenced_by_pending_publish(): void {
        global $DB;
        $this->resetAfterTest(true);
        // Retire completed uploads immediately, so only the pending-publish guard
        // could keep the staged file.
        set_config('state2duration', 0, 'largefile');
        $user = $this->getDataGenerator()->create_user();

        $token = $this->stage_completed_upload((int) $user->id, 'pending.mbz', 'DATA');
        // Backdate the staged upload so it is comfortably past its (zero) retention:
        // the pending-publish guard, not its age, must be the only thing keeping it,
        // and once nothing references it the same-second purge boundary cannot mask
        // its removal.
        $DB->set_field(
            \repository_largefile\chunk_store::TABLE,
            'lastmodified',
            time() - 100,
            ['id' => $token]
        );
        $id = transfer_manager::create(
            transfer_manager::TYPE_PUBLISH,
            (int) $user->id,
            ['peerid' => 1, 'sourcetype' => backup_source::TYPE_TOKEN, 'token' => $token],
            0,
            \context_system::instance()->id,
            'pending.mbz'
        );

        (new \repository_largefile\task\cleanup_chunks())->execute();
        $this->assertNotNull(
            \repository_largefile\chunk_store::get_record($token),
            'A staged upload referenced by a scheduled publish must survive cleanup.'
        );

        // Once the publish is cancelled, nothing references the token, so it is swept.
        transfer_manager::cancel($id);
        (new \repository_largefile\task\cleanup_chunks())->execute();
        $this->assertNull(\repository_largefile\chunk_store::get_record($token));
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

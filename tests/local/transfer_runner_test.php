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

    /**
     * Enable the Large file repository type, as it is on a site that uses it.
     *
     * Moodle drops notifications from a disabled plugin's message providers, and a
     * fresh test site has no repository instance for this plugin, so without this
     * the restore notifications would never reach the message sink.
     *
     * @return void
     */
    private function enable_repository(): void {
        global $DB;
        if (!$DB->record_exists('repository', ['type' => 'largefile'])) {
            $DB->insert_record('repository', (object) ['type' => 'largefile', 'visible' => 1, 'sortorder' => 1]);
        }
        \core_plugin_manager::reset_caches();
    }

    /**
     * A queued restore preparation copies the completed upload into the chosen
     * course's backup area, consumes the upload, links to the restore wizard and
     * notifies the operator.
     *
     * @return void
     */
    public function test_restore_prep_copies_into_course_backup_area(): void {
        $this->resetAfterTest(true);
        $this->enable_repository();
        $this->setAdminUser();
        $admin = get_admin();
        $course = $this->getDataGenerator()->create_course();
        $token = $this->stage_completed_upload((int) $admin->id, 'course.mbz', 'BACKUPDATA');

        $id = transfer_manager::create(
            transfer_manager::TYPE_RESTORE,
            (int) $admin->id,
            ['token' => $token, 'courseid' => (int) $course->id],
            0,
            \context_course::instance($course->id)->id,
            'course.mbz'
        );
        $sink = $this->redirectMessages();
        transfer_runner::run(transfer_manager::get($id));
        $messages = $sink->get_messages();
        $sink->close();

        $transfer = transfer_manager::get($id);
        $this->assertSame(transfer_manager::STATUS_COMPLETED, $transfer->status, (string) $transfer->error);
        $this->assertSame('course.mbz', $transfer->result);
        $file = get_file_storage()->get_file(
            \context_course::instance($course->id)->id,
            'backup',
            'course',
            0,
            '/',
            'course.mbz'
        );
        $this->assertNotFalse($file);
        $this->assertSame('BACKUPDATA', $file->get_content());
        $this->assertNull(\repository_largefile\chunk_store::get_record($token));

        $url = transfer_runner::restore_url($transfer);
        $this->assertNotNull($url);
        $this->assertSame($file->get_pathnamehash(), $url->get_param('pathnamehash'));

        $this->assertCount(1, $messages);
        $this->assertSame('restoreready', $messages[0]->eventtype);
    }

    /**
     * A restore preparation queued by a user who cannot restore into the course fails
     * and leaves the completed upload untouched.
     *
     * @return void
     */
    public function test_restore_prep_without_capability_fails(): void {
        $this->resetAfterTest(true);
        $this->enable_repository();
        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $token = $this->stage_completed_upload((int) $user->id, 'course.mbz', 'BACKUPDATA');

        $id = transfer_manager::create(
            transfer_manager::TYPE_RESTORE,
            (int) $user->id,
            ['token' => $token, 'courseid' => (int) $course->id],
            0,
            \context_course::instance($course->id)->id,
            'course.mbz'
        );
        $sink = $this->redirectMessages();
        transfer_runner::run(transfer_manager::get($id));
        $sink->close();

        $this->assertSame(transfer_manager::STATUS_FAILED, transfer_manager::get($id)->status);
        $this->assertNotNull(\repository_largefile\chunk_store::get_record($token));
    }

    /**
     * An automatic restore queued by a user who cannot create courses in the category
     * fails without creating a course, and leaves the completed upload untouched.
     *
     * @return void
     */
    public function test_auto_restore_without_capability_fails(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->enable_repository();
        $user = $this->getDataGenerator()->create_user();
        $category = $this->getDataGenerator()->create_category();
        $token = $this->stage_completed_upload((int) $user->id, 'course.mbz', 'BACKUPDATA');
        $coursecount = $DB->count_records('course');

        $id = transfer_manager::create(
            transfer_manager::TYPE_AUTORESTORE,
            (int) $user->id,
            ['token' => $token, 'categoryid' => (int) $category->id],
            0,
            \context_coursecat::instance($category->id)->id,
            'course.mbz'
        );
        $sink = $this->redirectMessages();
        transfer_runner::run(transfer_manager::get($id));
        $sink->close();

        $this->assertSame(transfer_manager::STATUS_FAILED, transfer_manager::get($id)->status);
        $this->assertSame($coursecount, $DB->count_records('course'));
        $this->assertNotNull(\repository_largefile\chunk_store::get_record($token));
    }

    /**
     * An automatic restore of a real course backup creates a new course in the chosen
     * category holding the backed-up content, and consumes the completed upload.
     *
     * @return void
     */
    public function test_auto_restore_creates_course_in_category(): void {
        global $CFG, $DB, $USER;
        require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
        $this->resetAfterTest(true);
        $this->enable_repository();
        $this->setAdminUser();
        $admin = get_admin();
        $generator = $this->getDataGenerator();
        $source = $generator->create_course(['fullname' => 'Source course', 'shortname' => 'SRC']);
        $generator->create_module('page', ['course' => $source->id, 'name' => 'Restored page']);
        $category = $generator->create_category();

        // Back the course up, then stage the .mbz as a completed chunked upload.
        $bc = new \backup_controller(
            \backup::TYPE_1COURSE,
            $source->id,
            \backup::FORMAT_MOODLE,
            \backup::INTERACTIVE_NO,
            \backup::MODE_GENERAL,
            (int) $admin->id
        );
        $bc->execute_plan();
        $backupfile = $bc->get_results()['backup_destination'];
        $bc->destroy();
        $token = $this->stage_completed_upload((int) $admin->id, 'source.mbz', $backupfile->get_content());

        $id = transfer_manager::create(
            transfer_manager::TYPE_AUTORESTORE,
            (int) $admin->id,
            ['token' => $token, 'categoryid' => (int) $category->id],
            0,
            \context_coursecat::instance($category->id)->id,
            'source.mbz'
        );
        $sink = $this->redirectMessages();
        transfer_runner::run(transfer_manager::get($id));
        $messages = $sink->get_messages();
        $sink->close();
        $this->assertEquals((int) $admin->id, (int) $USER->id, 'The task user must be put back after the restore.');

        $transfer = transfer_manager::get($id);
        $this->assertSame(transfer_manager::STATUS_COMPLETED, $transfer->status, (string) $transfer->error);
        $newcourse = $DB->get_record('course', ['id' => (int) $transfer->result], '*', MUST_EXIST);
        $this->assertNotEquals((int) $source->id, (int) $newcourse->id);
        $this->assertEquals((int) $category->id, (int) $newcourse->category);
        $this->assertTrue($DB->record_exists('page', ['course' => $newcourse->id, 'name' => 'Restored page']));
        $this->assertNull(\repository_largefile\chunk_store::get_record($token));
        $this->assertCount(1, $messages);
        $this->assertSame('restoreready', $messages[0]->eventtype);
    }

    /**
     * The cleanup task keeps a staged upload that a queued restore still needs.
     *
     * @return void
     */
    public function test_cleanup_keeps_token_referenced_by_pending_restore(): void {
        global $DB;
        $this->resetAfterTest(true);
        set_config('state2duration', 0, 'largefile');
        $user = $this->getDataGenerator()->create_user();
        $token = $this->stage_completed_upload((int) $user->id, 'pending.mbz', 'DATA');
        $DB->set_field(
            \repository_largefile\chunk_store::TABLE,
            'lastmodified',
            time() - 100,
            ['id' => $token]
        );
        $id = transfer_manager::create(
            transfer_manager::TYPE_AUTORESTORE,
            (int) $user->id,
            ['token' => $token, 'categoryid' => 1],
            0,
            \context_system::instance()->id,
            'pending.mbz'
        );

        (new \repository_largefile\task\cleanup_chunks())->execute();
        $this->assertNotNull(\repository_largefile\chunk_store::get_record($token));

        transfer_manager::cancel($id);
        (new \repository_largefile\task\cleanup_chunks())->execute();
        $this->assertNull(\repository_largefile\chunk_store::get_record($token));
    }

    /**
     * A restore preparation retried after an interrupted attempt that had already
     * stored the copy finishes up instead of copying again or failing.
     *
     * @return void
     */
    public function test_restore_prep_retry_after_copy_finishes_up(): void {
        $this->resetAfterTest(true);
        $this->enable_repository();
        $this->setAdminUser();
        $admin = get_admin();
        $course = $this->getDataGenerator()->create_course();
        $coursecontext = \context_course::instance($course->id);
        $token = $this->stage_completed_upload((int) $admin->id, 'course.mbz', 'BACKUPDATA');
        // The earlier attempt checkpointed its target name and stored the copy, then died.
        get_file_storage()->create_file_from_string([
            'contextid' => $coursecontext->id,
            'component' => 'backup',
            'filearea' => 'course',
            'itemid' => 0,
            'filepath' => '/',
            'filename' => 'course.mbz',
        ], 'BACKUPDATA');
        $id = transfer_manager::create(
            transfer_manager::TYPE_RESTORE,
            (int) $admin->id,
            ['token' => $token, 'courseid' => (int) $course->id, 'storedname' => 'course.mbz'],
            0,
            $coursecontext->id,
            'course.mbz'
        );
        $sink = $this->redirectMessages();
        transfer_runner::run(transfer_manager::get($id));
        $sink->close();

        $transfer = transfer_manager::get($id);
        $this->assertSame(transfer_manager::STATUS_COMPLETED, $transfer->status, (string) $transfer->error);
        $this->assertSame('course.mbz', $transfer->result);
        $files = get_file_storage()->get_area_files($coursecontext->id, 'backup', 'course', 0, 'id', false);
        $this->assertCount(1, $files, 'The retry must not store a second copy.');
        $this->assertNull(\repository_largefile\chunk_store::get_record($token));
    }

    /**
     * An automatic restore retried after an interrupted attempt whose restore had
     * already finished returns that course instead of restoring a second one.
     *
     * @return void
     */
    public function test_auto_restore_retry_after_restore_finishes_up(): void {
        global $DB;
        $this->resetAfterTest(true);
        $this->enable_repository();
        $this->setAdminUser();
        $admin = get_admin();
        $category = $this->getDataGenerator()->create_category();
        $restored = $this->getDataGenerator()->create_course(['category' => $category->id]);
        $token = $this->stage_completed_upload((int) $admin->id, 'course.mbz', 'BACKUPDATA');
        $coursecount = $DB->count_records('course');
        $id = transfer_manager::create(
            transfer_manager::TYPE_AUTORESTORE,
            (int) $admin->id,
            [
                'token' => $token,
                'categoryid' => (int) $category->id,
                'courseid' => (int) $restored->id,
                'restored' => 1,
            ],
            0,
            \context_coursecat::instance($category->id)->id,
            'course.mbz'
        );
        $sink = $this->redirectMessages();
        transfer_runner::run(transfer_manager::get($id));
        $sink->close();

        $transfer = transfer_manager::get($id);
        $this->assertSame(transfer_manager::STATUS_COMPLETED, $transfer->status, (string) $transfer->error);
        $this->assertEquals((int) $restored->id, (int) $transfer->result);
        $this->assertSame($coursecount, $DB->count_records('course'));
        $this->assertNull(\repository_largefile\chunk_store::get_record($token));
    }

    /**
     * Removing every completed upload in bulk leaves alone an upload that a queued
     * restore still needs.
     *
     * @return void
     */
    public function test_bulk_remove_keeps_upload_of_queued_restore(): void {
        $this->resetAfterTest(true);
        $user = $this->getDataGenerator()->create_user();
        $kept = $this->stage_completed_upload((int) $user->id, 'kept.mbz', 'DATA');
        $gone = $this->stage_completed_upload((int) $user->id, 'gone.mbz', 'DATA');
        transfer_manager::create(
            transfer_manager::TYPE_RESTORE,
            (int) $user->id,
            ['token' => $kept, 'courseid' => 2],
            0,
            \context_system::instance()->id,
            'kept.mbz'
        );

        $removed = \repository_largefile\chunk_store::delete_all_in_state(
            \repository_largefile\chunk_store::STATE_COMPLETED,
            transfer_manager::active_source_tokens()
        );
        $this->assertSame(1, $removed);
        $this->assertNotNull(\repository_largefile\chunk_store::get_record($kept));
        $this->assertNull(\repository_largefile\chunk_store::get_record($gone));
    }

    /**
     * With the course backup area destination disabled, the automatic restore is
     * still offered to a user who may create courses; the wizard route is not.
     *
     * @return void
     */
    public function test_available_restore_modes(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $this->assertSame(
            [
                \repository_largefile\form\completed_restore_form::MODE_WIZARD,
                \repository_largefile\form\completed_restore_form::MODE_AUTO,
            ],
            \repository_largefile\form\completed_restore_form::available_modes()
        );
        set_config('dest_coursebackup', 0, 'largefile');
        $this->assertSame(
            [\repository_largefile\form\completed_restore_form::MODE_AUTO],
            \repository_largefile\form\completed_restore_form::available_modes()
        );
        $this->setUser($this->getDataGenerator()->create_user());
        $this->assertSame([], \repository_largefile\form\completed_restore_form::available_modes());
    }

    /**
     * A queued send copies someone else's completed upload into the operator's own
     * private files, consumes the upload, and notifies the operator.
     *
     * @return void
     */
    public function test_send_to_private_files(): void {
        $this->resetAfterTest(true);
        $this->enable_repository();
        $this->setAdminUser();
        $admin = get_admin();
        $owner = $this->getDataGenerator()->create_user();
        $token = $this->stage_completed_upload((int) $owner->id, 'notes.pdf', 'PDFDATA');

        $id = transfer_manager::create(
            transfer_manager::TYPE_SEND,
            (int) $admin->id,
            ['token' => $token, 'destination' => import_policy::DEST_PRIVATEFILES, 'courseid' => 0],
            0,
            \context_system::instance()->id,
            'notes.pdf'
        );
        $sink = $this->redirectMessages();
        transfer_runner::run(transfer_manager::get($id));
        $messages = $sink->get_messages();
        $sink->close();

        $transfer = transfer_manager::get($id);
        $this->assertSame(transfer_manager::STATUS_COMPLETED, $transfer->status, (string) $transfer->error);
        $this->assertSame('notes.pdf', $transfer->result);
        $file = get_file_storage()->get_file(
            \context_user::instance($admin->id)->id,
            'user',
            'private',
            0,
            '/',
            'notes.pdf'
        );
        $this->assertNotFalse($file);
        $this->assertSame('PDFDATA', $file->get_content());
        $this->assertNull(\repository_largefile\chunk_store::get_record($token));
        $this->assertCount(1, $messages);
        $this->assertSame('uploadsent', $messages[0]->eventtype);
    }

    /**
     * A queued send to a course's backup area by a user who may not add a backup
     * there fails and leaves the completed upload untouched.
     *
     * @return void
     */
    public function test_send_to_course_backup_without_capability_fails(): void {
        $this->resetAfterTest(true);
        $this->enable_repository();
        $operator = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $systemcontext = \context_system::instance();
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability('repository/largefile:import', CAP_ALLOW, $roleid, $systemcontext->id);
        role_assign($roleid, $operator->id, $systemcontext->id);
        $token = $this->stage_completed_upload((int) $operator->id, 'course.mbz', 'BACKUPDATA');

        $id = transfer_manager::create(
            transfer_manager::TYPE_SEND,
            (int) $operator->id,
            ['token' => $token, 'destination' => import_policy::DEST_COURSEBACKUP, 'courseid' => (int) $course->id],
            0,
            \context_course::instance($course->id)->id,
            'course.mbz'
        );
        $sink = $this->redirectMessages();
        transfer_runner::run(transfer_manager::get($id));
        $messages = $sink->get_messages();
        $sink->close();

        $transfer = transfer_manager::get($id);
        $this->assertSame(transfer_manager::STATUS_FAILED, $transfer->status);
        $this->assertSame(get_string('errornocoursebackupcap', 'repository_largefile'), $transfer->error);
        $this->assertNotNull(\repository_largefile\chunk_store::get_record($token));
        $this->assertCount(1, $messages);
        $this->assertSame('uploadsent', $messages[0]->eventtype);
    }

    /**
     * A queued send to the picker (not a real destination for Send to…) is refused.
     *
     * @return void
     */
    public function test_send_to_picker_refused(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $admin = get_admin();
        $token = $this->stage_completed_upload((int) $admin->id, 'notes.pdf', 'PDFDATA');
        $id = transfer_manager::create(
            transfer_manager::TYPE_SEND,
            (int) $admin->id,
            ['token' => $token, 'destination' => import_policy::DEST_PICKER],
            0,
            \context_system::instance()->id,
            'notes.pdf'
        );
        $sink = $this->redirectMessages();
        transfer_runner::run(transfer_manager::get($id));
        $sink->close();

        $this->assertSame(transfer_manager::STATUS_FAILED, transfer_manager::get($id)->status);
        $this->assertNotNull(\repository_largefile\chunk_store::get_record($token));
    }

    /**
     * A retried send whose checkpointed name was since taken by an unrelated file
     * (different content) does not adopt that file: it stores its own copy under a
     * new name and leaves the unrelated file alone.
     *
     * @return void
     */
    public function test_send_retry_ignores_unrelated_file_with_checkpointed_name(): void {
        $this->resetAfterTest(true);
        $this->enable_repository();
        $this->setAdminUser();
        $admin = get_admin();
        $usercontext = \context_user::instance($admin->id);
        $token = $this->stage_completed_upload((int) $admin->id, 'notes.pdf', 'UPLOADED');
        // The earlier attempt checkpointed the name, then died before copying; the
        // user has since added an unrelated file of that name.
        get_file_storage()->create_file_from_string([
            'contextid' => $usercontext->id,
            'component' => 'user',
            'filearea' => 'private',
            'itemid' => 0,
            'filepath' => '/',
            'filename' => 'notes.pdf',
        ], 'SOMETHINGELSE');
        $id = transfer_manager::create(
            transfer_manager::TYPE_SEND,
            (int) $admin->id,
            ['token' => $token, 'destination' => import_policy::DEST_PRIVATEFILES, 'storedname' => 'notes.pdf'],
            0,
            \context_system::instance()->id,
            'notes.pdf'
        );
        $sink = $this->redirectMessages();
        transfer_runner::run(transfer_manager::get($id));
        $sink->close();

        $transfer = transfer_manager::get($id);
        $this->assertSame(transfer_manager::STATUS_COMPLETED, $transfer->status, (string) $transfer->error);
        $this->assertNotSame('notes.pdf', $transfer->result);
        $fs = get_file_storage();
        $this->assertSame(
            'SOMETHINGELSE',
            $fs->get_file($usercontext->id, 'user', 'private', 0, '/', 'notes.pdf')->get_content()
        );
        $this->assertSame(
            'UPLOADED',
            $fs->get_file($usercontext->id, 'user', 'private', 0, '/', $transfer->result)->get_content()
        );
        $this->assertNull(\repository_largefile\chunk_store::get_record($token));
    }

    /**
     * An upload with a send queued is not offered, and cannot be chosen, as the
     * source of a new share publication, which would find it already consumed.
     *
     * @return void
     */
    public function test_queued_send_source_not_offered_for_sharing(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $admin = get_admin();
        $token = $this->stage_completed_upload((int) $admin->id, 'course.mbz', 'DATA');
        $this->assertNotNull(backup_source::resolve('token:' . $token, (int) $admin->id));
        $this->assertArrayHasKey('token:' . $token, backup_source::menu_for_user((int) $admin->id));

        transfer_manager::create(
            transfer_manager::TYPE_SEND,
            (int) $admin->id,
            ['token' => $token, 'destination' => import_policy::DEST_PRIVATEFILES],
            0,
            \context_system::instance()->id,
            'course.mbz'
        );
        $this->assertNull(backup_source::resolve('token:' . $token, (int) $admin->id));
        $this->assertArrayNotHasKey('token:' . $token, backup_source::menu_for_user((int) $admin->id));
    }

    /**
     * A completed send to private files links there only for the operator it was
     * sent for; a course backup area send links to that course's restore screen.
     *
     * @return void
     */
    public function test_send_url_depends_on_viewer(): void {
        $this->resetAfterTest(true);
        $course = $this->getDataGenerator()->create_course();
        $private = (object) [
            'type' => transfer_manager::TYPE_SEND,
            'userid' => 5,
            'payload' => json_encode(['destination' => import_policy::DEST_PRIVATEFILES]),
        ];
        $this->assertNotNull(transfer_runner::send_url($private, 5));
        $this->assertNull(transfer_runner::send_url($private, 6));
        $backuparea = (object) [
            'type' => transfer_manager::TYPE_SEND,
            'userid' => 5,
            'payload' => json_encode(['destination' => import_policy::DEST_BACKUPAREA]),
        ];
        $this->assertNull(transfer_runner::send_url($backuparea, 5));
        $coursebackup = (object) [
            'type' => transfer_manager::TYPE_SEND,
            'userid' => 5,
            'payload' => json_encode(['destination' => import_policy::DEST_COURSEBACKUP, 'courseid' => (int) $course->id]),
        ];
        $url = transfer_runner::send_url($coursebackup, 6);
        $this->assertNotNull($url);
        $this->assertEquals(\context_course::instance($course->id)->id, $url->get_param('contextid'));
    }
}

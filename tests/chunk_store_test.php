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

namespace repository_largefile;

/**
 * Tests for the chunked-upload store.
 *
 * @package    repository_largefile
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \repository_largefile\chunk_store
 */
final class chunk_store_test extends \advanced_testcase {
    /**
     * A brand-new token starts in the unused state and owned by the current user.
     *
     * @return void
     */
    public function test_create_token(): void {
        global $USER;
        $this->resetAfterTest();
        $this->setAdminUser();

        $id = chunk_store::create_token(\context_system::instance()->id, -1);
        $this->assertNotNull($id);
        $record = chunk_store::get_record($id);
        $this->assertNotNull($record);
        $this->assertEquals($USER->id, $record->userid);
        $this->assertEquals(chunk_store::STATE_UNUSED, (int) $record->state);
        $this->assertFalse(chunk_store::is_complete($id));
    }

    /**
     * A guest may not create an upload token.
     *
     * @return void
     */
    public function test_guest_cannot_create_token(): void {
        $this->resetAfterTest();
        $this->setGuestUser();
        $this->assertNull(chunk_store::create_token(\context_system::instance()->id, -1));
    }

    /**
     * A file uploaded in two chunks assembles to the original bytes and is marked
     * complete, and appears in the owner's completed listing.
     *
     * @return void
     */
    public function test_two_chunk_assembly(): void {
        global $USER;
        $this->resetAfterTest();
        $this->setAdminUser();

        $data = random_bytes(5000);
        $id = chunk_store::create_token(\context_system::instance()->id, -1);

        // First chunk (0..3000): starts the upload but does not finish it.
        $record = chunk_store::get_record($id);
        $error = chunk_store::apply_start($record, 0, 3000, strlen($data), 'big.bin', substr($data, 0, 3000));
        $this->assertNull($error);
        $this->assertFalse(chunk_store::is_complete($id));
        $progress = chunk_store::get_progress($id);
        $this->assertEquals(3000, $progress['currentpos']);
        $this->assertEquals(chunk_store::STATE_STARTED, $progress['state']);

        // Second chunk (3000..5000): completes the file.
        $record = chunk_store::get_record($id);
        $error = chunk_store::apply_proceed($record, 3000, 5000, substr($data, 3000));
        $this->assertNull($error);
        $this->assertTrue(chunk_store::is_complete($id));

        $this->assertStringEqualsFile(chunk_store::get_path_for_id($id), $data);

        $completed = chunk_store::list_completed((int) $USER->id);
        $this->assertCount(1, $completed);
        $only = reset($completed);
        $this->assertEquals('big.bin', $only->filename);
        $this->assertEquals(5000, (int) $only->length);

        chunk_store::delete($id);
        $this->assertNull(chunk_store::get_record($id));
        $this->assertFileDoesNotExist(chunk_store::get_path_for_id($id));
    }

    /**
     * is_background() tells a Background Fetch upload (out-of-order, survives a
     * closed tab) from an in-page sequential upload, so the Transfers monitor can
     * label each. Only the background path sets the received-range map.
     *
     * @return void
     */
    public function test_is_background_distinguishes_upload_mode(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        // A fresh, unused token has no received map: not (yet) a background upload.
        $freshid = chunk_store::create_token(\context_system::instance()->id, -1);
        $this->assertFalse(chunk_store::is_background(chunk_store::get_record($freshid)));

        // An in-page (sequential) upload never sets the received map.
        $fgdata = random_bytes(2000);
        $fgid = chunk_store::create_token(\context_system::instance()->id, -1);
        chunk_store::apply_start(chunk_store::get_record($fgid), 0, 1000, strlen($fgdata), 'fg.bin', substr($fgdata, 0, 1000));
        $this->assertFalse(chunk_store::is_background(chunk_store::get_record($fgid)));

        // A Background Fetch upload is initialised with a received map.
        $bgid = chunk_store::create_token(\context_system::instance()->id, -1);
        chunk_store::begin_random(chunk_store::get_record($bgid), 2000, 'bg.mp4');
        $this->assertTrue(chunk_store::is_background(chunk_store::get_record($bgid)));

        // It stays identifiable as background once a chunk has landed.
        $bgdata = random_bytes(2000);
        chunk_store::write_range(chunk_store::get_record($bgid), 1000, 2000, substr($bgdata, 1000, 1000));
        $this->assertTrue(chunk_store::is_background(chunk_store::get_record($bgid)));
    }

    /**
     * A single chunk that spans the whole file completes it immediately.
     *
     * @return void
     */
    public function test_single_chunk_completes(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $data = random_bytes(1024);
        $id = chunk_store::create_token(\context_system::instance()->id, -1);
        $record = chunk_store::get_record($id);
        $this->assertNull(chunk_store::apply_start($record, 0, 1024, 1024, 'small.bin', $data));
        $this->assertTrue(chunk_store::is_complete($id));
    }

    /**
     * Re-sending a chunk the server already stored is accepted as a no-op and does
     * not corrupt the file (resume after a lost response).
     *
     * @return void
     */
    public function test_resent_chunk_is_idempotent(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $data = random_bytes(4000);
        $id = chunk_store::create_token(\context_system::instance()->id, -1);
        $record = chunk_store::get_record($id);
        chunk_store::apply_start($record, 0, 2000, strlen($data), 'x.bin', substr($data, 0, 2000));

        // Resend the first chunk: currentpos must not advance and bytes must stand.
        $record = chunk_store::get_record($id);
        $this->assertNull(chunk_store::apply_proceed($record, 0, 2000, substr($data, 0, 2000)));
        $this->assertEquals(2000, chunk_store::get_progress($id)['currentpos']);

        $record = chunk_store::get_record($id);
        chunk_store::apply_proceed($record, 2000, 4000, substr($data, 2000));
        $this->assertStringEqualsFile(chunk_store::get_path_for_id($id), $data);
    }

    /**
     * A chunk that does not begin where the last one ended is rejected.
     *
     * @return void
     */
    public function test_check_bounds_rejects_gap(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $id = chunk_store::create_token(\context_system::instance()->id, -1);
        $record = chunk_store::get_record($id);
        chunk_store::apply_start($record, 0, 1000, 3000, 'x.bin', random_bytes(1000));

        $record = chunk_store::get_record($id);
        // Starts at 2000 but only 1000 bytes are stored — a gap.
        $this->assertNotNull(chunk_store::check_bounds($record, 2000, 3000));
        // End beyond the declared length.
        $this->assertNotNull(chunk_store::check_bounds($record, 1000, 4000));
        // Valid continuation.
        $this->assertNull(chunk_store::check_bounds($record, 1000, 3000));
    }

    /**
     * apply_start rejects a chunk for a file larger than the token's cap.
     *
     * @return void
     */
    public function test_start_rejects_oversize(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $id = chunk_store::create_token(\context_system::instance()->id, 1000);
        $record = chunk_store::get_record($id);
        $this->assertNotNull(chunk_store::apply_start($record, 0, 500, 2000, 'x.bin', random_bytes(500)));
    }

    /**
     * adopt_file takes an already-downloaded file as the token's payload and marks
     * it complete (the URL-import path).
     *
     * @return void
     */
    public function test_adopt_file(): void {
        global $CFG;
        $this->resetAfterTest();
        $this->setAdminUser();

        $src = $CFG->tempdir . '/repository_largefile_adopt_' . uniqid() . '.bin';
        $data = random_bytes(2048);
        file_put_contents($src, $data);

        $id = chunk_store::create_token(\context_system::instance()->id, -1);
        $this->assertTrue(chunk_store::adopt_file($id, $src, 'fetched.bin'));
        $this->assertTrue(chunk_store::is_complete($id));
        $this->assertFileDoesNotExist($src, 'source file should have been moved');
        $this->assertStringEqualsFile(chunk_store::get_path_for_id($id), $data);
        $record = chunk_store::get_record($id);
        $this->assertEquals('fetched.bin', $record->filename);
        $this->assertEquals(2048, (int) $record->length);
    }

    /**
     * reset discards a partial upload and returns the token to the unused state.
     *
     * @return void
     */
    public function test_reset(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $id = chunk_store::create_token(\context_system::instance()->id, -1);
        $record = chunk_store::get_record($id);
        chunk_store::apply_start($record, 0, 500, 5000, 'x.bin', random_bytes(500));
        $this->assertFileExists(chunk_store::get_path_for_id($id));

        chunk_store::reset($id);
        $this->assertFileDoesNotExist(chunk_store::get_path_for_id($id));
        $record = chunk_store::get_record($id);
        $this->assertEquals(chunk_store::STATE_UNUSED, (int) $record->state);
        $this->assertEquals(0, (int) $record->currentpos);
    }

    /**
     * A Background Fetch upload assembles correctly when its chunks arrive out of
     * order, marks complete once every byte has landed, and tolerates a re-sent
     * chunk without double-counting or completing early.
     *
     * @return void
     */
    public function test_write_range_out_of_order(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $id = chunk_store::create_token(\context_system::instance()->id, -1);
        $record = chunk_store::get_record($id);

        $data = random_bytes(2500);
        $this->assertNull(chunk_store::begin_random($record, strlen($data), 'video.mp4'));

        $record = chunk_store::get_record($id);
        $mid = chunk_store::write_range($record, 1000, 2000, substr($data, 1000, 1000));
        $this->assertIsArray($mid);
        $this->assertFalse($mid['complete']);

        $record = chunk_store::get_record($id);
        $tail = chunk_store::write_range($record, 2000, 2500, substr($data, 2000, 500));
        $this->assertFalse($tail['complete']);

        // Re-send the middle chunk: it must not double-count or complete early.
        $record = chunk_store::get_record($id);
        $dup = chunk_store::write_range($record, 1000, 2000, substr($data, 1000, 1000));
        $this->assertFalse($dup['complete']);
        $this->assertEquals(1500, $dup['currentpos']);

        $record = chunk_store::get_record($id);
        $head = chunk_store::write_range($record, 0, 1000, substr($data, 0, 1000));
        $this->assertTrue($head['complete']);
        $this->assertTrue(chunk_store::is_complete($id));
        $this->assertSame($data, file_get_contents(chunk_store::get_path_for_id($id)));
    }

    /**
     * write_range rejects an out-of-bounds range and a body that is the wrong length.
     *
     * @return void
     */
    public function test_write_range_rejects_bad_input(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $id = chunk_store::create_token(\context_system::instance()->id, -1);
        $record = chunk_store::get_record($id);
        chunk_store::begin_random($record, 1000, 'video.mp4');

        $record = chunk_store::get_record($id);
        $this->assertIsString(chunk_store::write_range($record, 500, 2000, str_repeat('x', 1500)));
        $record = chunk_store::get_record($id);
        $this->assertIsString(chunk_store::write_range($record, 0, 100, 'short'));
        $this->assertFalse(chunk_store::is_complete($id));
    }

    /**
     * begin_random enforces the accepted-file-type policy before any bytes arrive.
     *
     * @return void
     */
    public function test_begin_random_enforces_type_policy(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('restricttypes', 1, 'largefile');
        set_config('accept_video', 0, 'largefile');

        $id = chunk_store::create_token(\context_system::instance()->id, -1);
        $record = chunk_store::get_record($id);
        $this->assertIsString(chunk_store::begin_random($record, 100, 'video.mp4'));

        $record = chunk_store::get_record($id);
        $this->assertNull(chunk_store::begin_random($record, 100, 'course.mbz'));
    }

    /**
     * missing_ranges() reports the gaps not yet received, so a stalled Background
     * Fetch upload can be finished by re-uploading exactly those ranges — and once
     * they are filled the upload completes.
     *
     * @return void
     */
    public function test_missing_ranges(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $id = chunk_store::create_token(\context_system::instance()->id, -1);
        chunk_store::begin_random(chunk_store::get_record($id), 1000, 'video.mp4');

        // Nothing received yet: the whole file is missing.
        $this->assertSame([[0, 1000]], chunk_store::missing_ranges($id));

        // Receive the middle only: a gap remains before and after it.
        chunk_store::write_range(chunk_store::get_record($id), 400, 600, random_bytes(200));
        $this->assertSame([[0, 400], [600, 1000]], chunk_store::missing_ranges($id));

        // Fill the head; one tail gap remains and the upload is not complete.
        chunk_store::write_range(chunk_store::get_record($id), 0, 400, random_bytes(400));
        $this->assertSame([[600, 1000]], chunk_store::missing_ranges($id));
        $this->assertFalse(chunk_store::is_complete($id));

        // Fill the tail: nothing missing, and the upload is now complete.
        chunk_store::write_range(chunk_store::get_record($id), 600, 1000, random_bytes(400));
        $this->assertSame([], chunk_store::missing_ranges($id));
        $this->assertTrue(chunk_store::is_complete($id));

        // An unknown token reports null (not an empty gap list).
        $this->assertNull(chunk_store::missing_ranges('0000000001'));
    }

    /**
     * delete_if_started() removes an in-progress upload but refuses to delete one
     * that has completed (it is the user's file now) or does not exist, reporting
     * the real outcome each time.
     *
     * @return void
     */
    public function test_delete_if_started(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        // A started (still in-progress) upload is removed, row and file.
        $started = chunk_store::create_token(\context_system::instance()->id, -1);
        chunk_store::begin_random(chunk_store::get_record($started), 2000, 'v.mp4');
        chunk_store::write_range(chunk_store::get_record($started), 0, 1000, random_bytes(1000));
        $this->assertSame('removed', chunk_store::delete_if_started($started));
        $this->assertNull(chunk_store::get_record($started));

        // A completed upload finished after the row was shown is NOT deleted.
        $done = chunk_store::create_token(\context_system::instance()->id, -1);
        $donerec = chunk_store::get_record($done);
        $this->assertNull(chunk_store::apply_start($donerec, 0, 500, 500, 'done.bin', random_bytes(500)));
        $this->assertTrue(chunk_store::is_complete($done));
        $this->assertSame('notstarted', chunk_store::delete_if_started($done));
        $this->assertNotNull(chunk_store::get_record($done));

        // An unknown token: nothing to remove.
        $this->assertSame('notstarted', chunk_store::delete_if_started('0000000002'));
    }

    /**
     * delete_all_started() removes every in-progress upload but leaves a completed
     * one, and reports how many it removed.
     *
     * @return void
     */
    public function test_delete_all_started(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $ctx = \context_system::instance()->id;

        // Two in-progress uploads and one completed.
        $a = chunk_store::create_token($ctx, -1);
        chunk_store::begin_random(chunk_store::get_record($a), 1000, 'a.mp4');
        chunk_store::write_range(chunk_store::get_record($a), 0, 400, random_bytes(400));
        $b = chunk_store::create_token($ctx, -1);
        chunk_store::begin_random(chunk_store::get_record($b), 1000, 'b.mp4');
        $done = chunk_store::create_token($ctx, -1);
        $donerec = chunk_store::get_record($done);
        $this->assertNull(chunk_store::apply_start($donerec, 0, 300, 300, 'done.bin', random_bytes(300)));

        $this->assertSame(2, chunk_store::delete_all_started());
        $this->assertNull(chunk_store::get_record($a));
        $this->assertNull(chunk_store::get_record($b));
        // The completed upload is untouched.
        $this->assertNotNull(chunk_store::get_record($done));
        // Nothing left to remove on a second run.
        $this->assertSame(0, chunk_store::delete_all_started());
    }

    /**
     * delete_in_state() and delete_all_in_state() also clear completed uploads (staged
     * files never selected), and the state guard stops one path deleting the other's
     * uploads.
     *
     * @return void
     */
    public function test_delete_completed_uploads(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $ctx = \context_system::instance()->id;

        // A completed staged upload.
        $done = chunk_store::create_token($ctx, -1);
        $donerec = chunk_store::get_record($done);
        $this->assertNull(chunk_store::apply_start($donerec, 0, 300, 300, 'done.bin', random_bytes(300)));
        $this->assertTrue(chunk_store::is_complete($done));

        // The "stalled" path refuses it (wrong state), the completed path removes it.
        $this->assertSame('notstarted', chunk_store::delete_in_state($done, chunk_store::STATE_STARTED));
        $this->assertNotNull(chunk_store::get_record($done));
        $this->assertSame('removed', chunk_store::delete_in_state($done, chunk_store::STATE_COMPLETED));
        $this->assertNull(chunk_store::get_record($done));

        // Bulk clear of completed uploads leaves an in-progress one alone.
        $c1 = chunk_store::create_token($ctx, -1);
        $this->assertNull(chunk_store::apply_start(chunk_store::get_record($c1), 0, 100, 100, 'c1.bin', random_bytes(100)));
        $c2 = chunk_store::create_token($ctx, -1);
        $this->assertNull(chunk_store::apply_start(chunk_store::get_record($c2), 0, 100, 100, 'c2.bin', random_bytes(100)));
        $started = chunk_store::create_token($ctx, -1);
        chunk_store::begin_random(chunk_store::get_record($started), 1000, 'v.mp4');

        $this->assertSame(2, chunk_store::delete_all_in_state(chunk_store::STATE_COMPLETED));
        $this->assertNull(chunk_store::get_record($c1));
        $this->assertNull(chunk_store::get_record($c2));
        $this->assertNotNull(chunk_store::get_record($started));
    }
}

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

namespace repository_largefile\privacy;

use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use core_privacy\tests\provider_testcase;
use repository_largefile\chunk_store;
use repository_largefile\local\peer_manager;
use repository_largefile\local\share_manager;
use repository_largefile\local\transfer_manager;

/**
 * Tests for the privacy provider: context discovery, export and erasure across
 * every table that carries a user id (chunks, shares and transfers).
 *
 * @package    repository_largefile
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \repository_largefile\privacy\provider
 */
final class provider_test extends provider_testcase {
    /**
     * Seed one chunked upload (in a course context), one share and one transfer for a
     * user, returning [$user, $coursecontext].
     *
     * @return array
     */
    private function seed_user_data(): array {
        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $coursecontext = \context_course::instance($course->id);
        $this->setUser($user);

        $id = chunk_store::create_token($coursecontext->id, -1);
        chunk_store::apply_start(chunk_store::get_record($id), 0, 100, 100, 'lecture.mp4', random_bytes(100));

        // Peer names are unique, so each seeded user gets their own peer.
        $peerid = peer_manager::create('Partner ' . $user->id, str_repeat('s', 24), 'https://peer.example.org');
        $plain = make_request_directory() . '/backup.mbz';
        file_put_contents($plain, random_bytes(64));
        share_manager::create($peerid, $plain, 'backup.mbz', 0, 0, (int) $user->id);

        transfer_manager::create(
            transfer_manager::TYPE_URL,
            (int) $user->id,
            ['url' => 'https://example.org/file.zip'],
            0,
            \context_system::instance()->id,
            'file.zip'
        );
        return [$user, $coursecontext];
    }

    /**
     * The contexts reported for a user are the upload's own context plus the system
     * context (where shares and transfers live), on every supported database.
     *
     * @return void
     */
    public function test_get_contexts_for_userid(): void {
        $this->resetAfterTest();
        [$user, $coursecontext] = $this->seed_user_data();

        $contextids = provider::get_contexts_for_userid((int) $user->id)->get_contextids();
        sort($contextids);

        $expected = [(int) $coursecontext->id, (int) \context_system::instance()->id];
        sort($expected);
        $this->assertSame($expected, array_map('intval', $contextids));

        // A user with no data reports no contexts.
        $other = $this->getDataGenerator()->create_user();
        $this->assertSame([], provider::get_contexts_for_userid((int) $other->id)->get_contextids());
    }

    /**
     * The users found in a context: the upload's context lists its uploader, the
     * system context lists share publishers and transfer owners.
     *
     * @return void
     */
    public function test_get_users_in_context(): void {
        $this->resetAfterTest();
        [$user, $coursecontext] = $this->seed_user_data();

        $userlist = new userlist($coursecontext, 'repository_largefile');
        provider::get_users_in_context($userlist);
        $this->assertSame([(int) $user->id], array_map('intval', $userlist->get_userids()));

        $userlist = new userlist(\context_system::instance(), 'repository_largefile');
        provider::get_users_in_context($userlist);
        $this->assertSame([(int) $user->id], array_map('intval', $userlist->get_userids()));
    }

    /**
     * Export writes the upload record (with its bytes), the share metadata and the
     * transfer record to the approved contexts.
     *
     * @return void
     */
    public function test_export_user_data(): void {
        $this->resetAfterTest();
        [$user, $coursecontext] = $this->seed_user_data();
        $system = \context_system::instance();

        $contextlist = new approved_contextlist($user, 'repository_largefile', [$coursecontext->id, $system->id]);
        provider::export_user_data($contextlist);

        $uploads = writer::with_context($coursecontext)->get_data([get_string('privacy:chunkspath', 'repository_largefile')]);
        $this->assertCount(1, $uploads->uploads);
        $this->assertSame('lecture.mp4', $uploads->uploads[0]->filename);
        $this->assertSame(100, $uploads->uploads[0]->filesize);

        $shares = writer::with_context($system)->get_data([get_string('manageshares', 'repository_largefile')]);
        $this->assertCount(1, $shares->shares);
        $this->assertSame('backup.mbz', $shares->shares[0]->filename);

        $transfers = writer::with_context($system)->get_data([get_string('transfers', 'repository_largefile')]);
        $this->assertCount(1, $transfers->transfers);
        $this->assertSame('file.zip', $transfers->transfers[0]->filename);
    }

    /**
     * Deleting for one user removes their upload (row and partial file), share (row
     * and encrypted file) and transfer, leaving another user's data intact.
     *
     * @return void
     */
    public function test_delete_data_for_user(): void {
        global $DB;
        $this->resetAfterTest();
        [$user, $coursecontext] = $this->seed_user_data();
        [$other] = $this->seed_user_data();
        $system = \context_system::instance();

        $contextlist = new approved_contextlist($user, 'repository_largefile', [$coursecontext->id, $system->id]);
        provider::delete_data_for_user($contextlist);

        $this->assertSame(0, $DB->count_records('repository_largefile_chunks', ['userid' => $user->id]));
        $this->assertSame(0, $DB->count_records('repository_largefile_shares', ['userid' => $user->id]));
        $this->assertSame(0, $DB->count_records('repository_largefile_transfers', ['userid' => $user->id]));
        $this->assertSame(0, $DB->count_records('files', [
            'component' => 'repository_largefile', 'filearea' => 'share', 'userid' => $user->id,
        ]));

        $this->assertSame(1, $DB->count_records('repository_largefile_chunks', ['userid' => $other->id]));
        $this->assertSame(1, $DB->count_records('repository_largefile_shares', ['userid' => $other->id]));
        $this->assertSame(1, $DB->count_records('repository_largefile_transfers', ['userid' => $other->id]));
    }

    /**
     * Deleting for a whole context clears every user's rows there; at the system
     * context that includes all shares and transfers.
     *
     * @return void
     */
    public function test_delete_data_for_all_users_in_context(): void {
        global $DB;
        $this->resetAfterTest();
        [, $coursecontext] = $this->seed_user_data();
        $this->seed_user_data();

        provider::delete_data_for_all_users_in_context($coursecontext);
        $this->assertSame(0, $DB->count_records('repository_largefile_chunks', ['contextid' => $coursecontext->id]));
        $this->assertSame(2, $DB->count_records('repository_largefile_shares'));

        provider::delete_data_for_all_users_in_context(\context_system::instance());
        $this->assertSame(0, $DB->count_records('repository_largefile_shares'));
        $this->assertSame(0, $DB->count_records('repository_largefile_transfers'));
    }

    /**
     * Deleting for a list of users in a context removes exactly those users' data.
     *
     * @return void
     */
    public function test_delete_data_for_users(): void {
        global $DB;
        $this->resetAfterTest();
        [$user] = $this->seed_user_data();
        [$other] = $this->seed_user_data();
        $system = \context_system::instance();

        $userlist = new approved_userlist($system, 'repository_largefile', [(int) $user->id]);
        provider::delete_data_for_users($userlist);

        $this->assertSame(0, $DB->count_records('repository_largefile_shares', ['userid' => $user->id]));
        $this->assertSame(0, $DB->count_records('repository_largefile_transfers', ['userid' => $user->id]));
        $this->assertSame(1, $DB->count_records('repository_largefile_shares', ['userid' => $other->id]));
        $this->assertSame(1, $DB->count_records('repository_largefile_transfers', ['userid' => $other->id]));
    }
}

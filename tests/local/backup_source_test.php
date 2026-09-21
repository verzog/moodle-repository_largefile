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
 * Tests for the reference-only share-source selector.
 *
 * @package    repository_largefile
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace repository_largefile\local;

/**
 * Tests for {@see backup_source}.
 *
 * @package    repository_largefile
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \repository_largefile\local\backup_source
 */
final class backup_source_test extends \advanced_testcase {
    /**
     * Stage a completed chunked upload owned by a user.
     *
     * @param int $userid The owner.
     * @param string $filename The staged file name.
     * @return string The staged token id.
     */
    private function stage_upload(int $userid, string $filename): string {
        $token = \repository_largefile\chunk_store::create_token_for(
            $userid,
            \context_system::instance()->id,
            -1
        );
        $tmp = make_request_directory() . '/' . $filename;
        file_put_contents($tmp, 'DATA');
        \repository_largefile\chunk_store::adopt_file($token, $tmp, $filename);
        return $token;
    }

    /**
     * Create a file in a user's private backup area.
     *
     * @param int $userid The owner.
     * @param string $filename The file name.
     * @return \stored_file The created file.
     */
    private function make_backup_file(int $userid, string $filename): \stored_file {
        return get_file_storage()->create_file_from_string([
            'contextid' => \context_user::instance($userid)->id,
            'component' => 'user',
            'filearea' => 'backup',
            'itemid' => 0,
            'filepath' => '/',
            'filename' => $filename,
        ], 'BACKUP');
    }

    /**
     * The menu lists a user's own staged uploads and backup-area files, and each
     * listed value resolves back to that source.
     *
     * @return void
     */
    public function test_menu_lists_own_sources(): void {
        $this->resetAfterTest(true);
        $user = $this->getDataGenerator()->create_user();

        $token = $this->stage_upload((int) $user->id, 'staged.mbz');
        $file = $this->make_backup_file((int) $user->id, 'course.mbz');

        $menu = backup_source::menu_for_user((int) $user->id);
        $this->assertArrayHasKey(backup_source::TYPE_TOKEN . ':' . $token, $menu);
        $this->assertArrayHasKey(backup_source::TYPE_STORED . ':' . $file->get_id(), $menu);
    }

    /**
     * A staged upload resolves for its owner but not for anyone else.
     *
     * @return void
     */
    public function test_token_resolves_only_for_owner(): void {
        $this->resetAfterTest(true);
        $owner = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        $token = $this->stage_upload((int) $owner->id, 'staged.mbz');
        $value = backup_source::TYPE_TOKEN . ':' . $token;

        $resolved = backup_source::resolve($value, (int) $owner->id);
        $this->assertNotNull($resolved);
        $this->assertSame(backup_source::TYPE_TOKEN, $resolved['type']);
        $this->assertSame($token, $resolved['token']);

        $this->assertNull(backup_source::resolve($value, (int) $other->id));
    }

    /**
     * A user's own backup-area file authorises for them; another user's does not,
     * whichever id is posted back.
     *
     * @return void
     */
    public function test_stored_authorisation_is_owner_scoped(): void {
        $this->resetAfterTest(true);
        $owner = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        $mine = $this->make_backup_file((int) $owner->id, 'mine.mbz');
        $theirs = $this->make_backup_file((int) $other->id, 'theirs.mbz');

        $this->assertNotNull(backup_source::authorize_stored((int) $mine->get_id(), (int) $owner->id));
        $this->assertNull(backup_source::authorize_stored((int) $theirs->get_id(), (int) $owner->id));

        // The same holds through resolve(), which the form posts back through.
        $this->assertNotNull(
            backup_source::resolve(backup_source::TYPE_STORED . ':' . $mine->get_id(), (int) $owner->id)
        );
        $this->assertNull(
            backup_source::resolve(backup_source::TYPE_STORED . ':' . $theirs->get_id(), (int) $owner->id)
        );
    }

    /**
     * A malformed or unknown source value resolves to null rather than a file.
     *
     * @return void
     */
    public function test_malformed_value_resolves_to_null(): void {
        $this->resetAfterTest(true);
        $user = $this->getDataGenerator()->create_user();

        $this->assertNull(backup_source::resolve('', (int) $user->id));
        $this->assertNull(backup_source::resolve('nonsense', (int) $user->id));
        $this->assertNull(backup_source::resolve('stored:999999', (int) $user->id));
        $this->assertNull(backup_source::resolve('token:doesnotexist', (int) $user->id));
    }
}

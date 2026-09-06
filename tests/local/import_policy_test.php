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

use repository_largefile\chunk_store;

/**
 * Tests for the import policy: type detection, the accepted-type/destination gates,
 * and routing an imported file to each destination.
 *
 * @package    repository_largefile
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \repository_largefile\local\import_policy
 */
final class import_policy_test extends \advanced_testcase {
    /**
     * Extensions are mapped to the expected kinds, unknown ones to "other".
     *
     * @return void
     */
    public function test_detect_type(): void {
        $this->assertSame(import_policy::TYPE_BACKUP, import_policy::detect_type('course.mbz'));
        $this->assertSame(import_policy::TYPE_IMSCC, import_policy::detect_type('export.imscc'));
        $this->assertSame(import_policy::TYPE_SCORM, import_policy::detect_type('package.zip'));
        $this->assertSame(import_policy::TYPE_VIDEO, import_policy::detect_type('lecture.MP4'));
        $this->assertSame(import_policy::TYPE_OTHER, import_policy::detect_type('notes.pdf'));
    }

    /**
     * With restriction off (the default), every kind is accepted and the picker
     * advertises all file types.
     *
     * @return void
     */
    public function test_unrestricted_accepts_everything(): void {
        $this->resetAfterTest(true);
        $this->assertFalse(import_policy::restricts_types());
        $this->assertTrue(import_policy::is_type_accepted(import_policy::TYPE_OTHER));
        $this->assertTrue(import_policy::is_type_accepted(import_policy::TYPE_VIDEO));
        $this->assertSame('*', import_policy::supported_filetypes());
    }

    /**
     * With restriction on, only the ticked kinds are accepted and the picker
     * advertises just their extensions/groups.
     *
     * @return void
     */
    public function test_restriction_limits_accepted_types(): void {
        $this->resetAfterTest(true);
        set_config('restricttypes', 1, 'largefile');
        set_config('accept_backup', 1, 'largefile');
        set_config('accept_scorm', 0, 'largefile');
        set_config('accept_imscc', 0, 'largefile');
        set_config('accept_video', 0, 'largefile');

        $this->assertTrue(import_policy::is_type_accepted(import_policy::TYPE_BACKUP));
        $this->assertFalse(import_policy::is_type_accepted(import_policy::TYPE_SCORM));
        $this->assertFalse(import_policy::is_type_accepted(import_policy::TYPE_OTHER));
        $this->assertSame(['.mbz'], import_policy::supported_filetypes());
    }

    /**
     * Destinations offered for a kind are the suitable ones intersected with those
     * enabled site-wide, and the default is the first offered.
     *
     * @return void
     */
    public function test_destinations_for_kind(): void {
        $this->resetAfterTest(true);
        // All destinations enabled by default.
        $this->assertSame(
            [import_policy::DEST_BACKUPAREA, import_policy::DEST_PRIVATEFILES],
            import_policy::destinations_for(import_policy::TYPE_BACKUP)
        );
        $this->assertSame(
            [import_policy::DEST_PICKER, import_policy::DEST_PRIVATEFILES],
            import_policy::destinations_for(import_policy::TYPE_VIDEO)
        );
        $this->assertSame(import_policy::DEST_BACKUPAREA, import_policy::default_destination(import_policy::TYPE_BACKUP));

        // Disable the backup area: a backup then defaults to private files.
        set_config('dest_backuparea', 0, 'largefile');
        $this->assertSame([import_policy::DEST_PRIVATEFILES], import_policy::destinations_for(import_policy::TYPE_BACKUP));
        $this->assertSame(import_policy::DEST_PRIVATEFILES, import_policy::default_destination(import_policy::TYPE_BACKUP));
    }

    /**
     * A backup routed to the backup area lands in user/backup, ready to restore.
     *
     * @return void
     */
    public function test_store_to_backup_area(): void {
        $this->resetAfterTest(true);
        $user = $this->getDataGenerator()->create_user();
        $src = make_request_directory() . '/course.mbz';
        file_put_contents($src, 'backup-bytes');

        $name = import_policy::store_imported_file(
            (int) $user->id,
            $src,
            'course.mbz',
            import_policy::DEST_BACKUPAREA
        );

        $this->assertSame('course.mbz', $name);
        $usercontext = \context_user::instance($user->id);
        $this->assertTrue(get_file_storage()->file_exists($usercontext->id, 'user', 'backup', 0, '/', 'course.mbz'));
    }

    /**
     * A video routed to the picker becomes a completed staged upload for the owner.
     *
     * @return void
     */
    public function test_store_to_picker(): void {
        $this->resetAfterTest(true);
        $user = $this->getDataGenerator()->create_user();
        $src = make_request_directory() . '/lecture.mp4';
        file_put_contents($src, 'video-bytes');

        $name = import_policy::store_imported_file(
            (int) $user->id,
            $src,
            'lecture.mp4',
            import_policy::DEST_PICKER
        );

        $this->assertSame('lecture.mp4', $name);
        $completed = chunk_store::list_completed((int) $user->id);
        $names = array_map(fn($r) => $r->filename, $completed);
        $this->assertContains('lecture.mp4', $names);
    }

    /**
     * An empty destination is resolved to the kind's default (a backup -> backup area).
     *
     * @return void
     */
    public function test_store_auto_destination(): void {
        $this->resetAfterTest(true);
        $user = $this->getDataGenerator()->create_user();
        $src = make_request_directory() . '/auto.mbz';
        file_put_contents($src, 'backup-bytes');

        import_policy::store_imported_file((int) $user->id, $src, 'auto.mbz', '');

        $usercontext = \context_user::instance($user->id);
        $this->assertTrue(get_file_storage()->file_exists($usercontext->id, 'user', 'backup', 0, '/', 'auto.mbz'));
    }

    /**
     * A destination that does not suit the file's kind is refused.
     *
     * @return void
     */
    public function test_store_rejects_disallowed_destination(): void {
        $this->resetAfterTest(true);
        $user = $this->getDataGenerator()->create_user();
        $src = make_request_directory() . '/course.mbz';
        file_put_contents($src, 'backup-bytes');

        $this->expectException(\moodle_exception::class);
        // A backup cannot be sent to the large file picker.
        import_policy::store_imported_file((int) $user->id, $src, 'course.mbz', import_policy::DEST_PICKER);
    }

    /**
     * A kind the site does not accept is refused before storage.
     *
     * @return void
     */
    public function test_store_rejects_unaccepted_type(): void {
        $this->resetAfterTest(true);
        set_config('restricttypes', 1, 'largefile');
        set_config('accept_video', 0, 'largefile');
        $user = $this->getDataGenerator()->create_user();
        $src = make_request_directory() . '/lecture.mp4';
        file_put_contents($src, 'video-bytes');

        $this->expectException(\moodle_exception::class);
        import_policy::store_imported_file((int) $user->id, $src, 'lecture.mp4', import_policy::DEST_PICKER);
    }
}

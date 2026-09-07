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
        // All destinations enabled by default. A backup suits the private and course
        // backup areas and private files; video suits the picker and private files.
        $this->assertSame(
            [import_policy::DEST_BACKUPAREA, import_policy::DEST_COURSEBACKUP, import_policy::DEST_PRIVATEFILES],
            import_policy::destinations_for(import_policy::TYPE_BACKUP)
        );
        $this->assertSame(
            [import_policy::DEST_PICKER, import_policy::DEST_PRIVATEFILES],
            import_policy::destinations_for(import_policy::TYPE_VIDEO)
        );
        // The course backup area needs a chosen course, so it is never the auto route.
        $this->assertSame(import_policy::DEST_BACKUPAREA, import_policy::default_destination(import_policy::TYPE_BACKUP));

        // Disable the backup area: a backup then defaults to private files (still not
        // the course backup area, which is always an explicit choice).
        set_config('dest_backuparea', 0, 'largefile');
        $this->assertSame(
            [import_policy::DEST_COURSEBACKUP, import_policy::DEST_PRIVATEFILES],
            import_policy::destinations_for(import_policy::TYPE_BACKUP)
        );
        $this->assertSame(import_policy::DEST_PRIVATEFILES, import_policy::default_destination(import_policy::TYPE_BACKUP));

        // With only the course backup area left, there is no automatic destination.
        set_config('dest_privatefiles', 0, 'largefile');
        $this->assertSame([import_policy::DEST_COURSEBACKUP], import_policy::destinations_for(import_policy::TYPE_BACKUP));
        $this->assertNull(import_policy::default_destination(import_policy::TYPE_BACKUP));
    }

    /**
     * The course backup area is offered only for backups, never for other kinds.
     *
     * @return void
     */
    public function test_course_backup_only_suits_backups(): void {
        $this->resetAfterTest(true);
        $this->assertTrue(
            import_policy::is_destination_allowed(import_policy::TYPE_BACKUP, import_policy::DEST_COURSEBACKUP)
        );
        $this->assertFalse(
            import_policy::is_destination_allowed(import_policy::TYPE_VIDEO, import_policy::DEST_COURSEBACKUP)
        );
        $this->assertFalse(
            import_policy::is_destination_allowed(import_policy::TYPE_SCORM, import_policy::DEST_COURSEBACKUP)
        );
    }

    /**
     * A backup routed to a course's backup area lands in that course's backup/course
     * file area when the user may upload a backup there.
     *
     * @return void
     */
    public function test_store_to_course_backup_area(): void {
        $this->resetAfterTest(true);
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        // A teacher (editing) holds moodle/restore:uploadfile in the course.
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');

        $src = make_request_directory() . '/course.mbz';
        file_put_contents($src, 'backup-bytes');

        $name = import_policy::store_imported_file(
            (int) $teacher->id,
            $src,
            'course.mbz',
            import_policy::DEST_COURSEBACKUP,
            0,
            (int) $course->id
        );

        $this->assertSame('course.mbz', $name);
        $coursecontext = \context_course::instance($course->id);
        $this->assertTrue(get_file_storage()->file_exists($coursecontext->id, 'backup', 'course', 0, '/', 'course.mbz'));
    }

    /**
     * Routing to a course backup area without a chosen course is refused.
     *
     * @return void
     */
    public function test_store_course_backup_requires_course(): void {
        $this->resetAfterTest(true);
        $user = $this->getDataGenerator()->create_user();
        $src = make_request_directory() . '/course.mbz';
        file_put_contents($src, 'backup-bytes');

        $this->expectException(\moodle_exception::class);
        import_policy::store_imported_file(
            (int) $user->id,
            $src,
            'course.mbz',
            import_policy::DEST_COURSEBACKUP
        );
    }

    /**
     * Routing to a course backup area is refused when the user lacks the capability to
     * add a backup to that course — the check re-run server-side for background jobs.
     *
     * @return void
     */
    public function test_store_course_backup_requires_capability(): void {
        $this->resetAfterTest(true);
        $course = $this->getDataGenerator()->create_course();
        // A student is enrolled but does not hold moodle/restore:uploadfile.
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');

        $src = make_request_directory() . '/course.mbz';
        file_put_contents($src, 'backup-bytes');

        $this->assertFalse(import_policy::can_use_course_backup((int) $student->id, (int) $course->id));
        $this->expectException(\moodle_exception::class);
        import_policy::store_imported_file(
            (int) $student->id,
            $src,
            'course.mbz',
            import_policy::DEST_COURSEBACKUP,
            0,
            (int) $course->id
        );
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

    /**
     * The picker-enabled flag follows the destination setting.
     *
     * @return void
     */
    public function test_picker_enabled_reflects_setting(): void {
        $this->resetAfterTest(true);
        $this->assertTrue(import_policy::picker_enabled());
        set_config('dest_picker', 0, 'largefile');
        $this->assertFalse(import_policy::picker_enabled());
    }

    /**
     * A direct upload is refused when the picker is disabled or the kind is not
     * accepted, and allowed otherwise.
     *
     * @return void
     */
    public function test_upload_rejection_reason(): void {
        $this->resetAfterTest(true);
        // Unrestricted, picker enabled: anything is allowed in.
        $this->assertNull(import_policy::upload_rejection_reason('lecture.mp4'));

        // Picker disabled: refused whatever the kind.
        set_config('dest_picker', 0, 'largefile');
        $this->assertNotNull(import_policy::upload_rejection_reason('lecture.mp4'));
        set_config('dest_picker', 1, 'largefile');

        // Restricted with video off: a video upload is refused, a backup is not.
        set_config('restricttypes', 1, 'largefile');
        set_config('accept_video', 0, 'largefile');
        $this->assertNotNull(import_policy::upload_rejection_reason('lecture.mp4'));
        $this->assertNull(import_policy::upload_rejection_reason('course.mbz'));
    }

    /**
     * When restriction is on but no kind is ticked, the plugin advertises no real
     * type — in particular it does not claim to accept backups.
     *
     * @return void
     */
    public function test_supported_filetypes_empty_policy_hides_backups(): void {
        $this->resetAfterTest(true);
        set_config('restricttypes', 1, 'largefile');
        foreach (['backup', 'scorm', 'imscc', 'video'] as $kind) {
            set_config('accept_' . $kind, 0, 'largefile');
        }
        $types = import_policy::supported_filetypes();
        $this->assertIsArray($types);
        $this->assertNotContains('.mbz', $types);
    }
}

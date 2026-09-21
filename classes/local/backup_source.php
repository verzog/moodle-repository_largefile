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
 * Selectable sources for a backup share, chosen by reference (never copied).
 *
 * The create-share form must let a user pick a large file to publish without the
 * file picker's copy-into-a-draft-area step, which for a multi-gigabyte backup runs
 * in the foreground and hits the web-server timeout. Instead the user chooses one of
 * two kinds of source that already exist on the server:
 *
 *  - a large file they have already staged through this plugin's chunked uploader
 *    (a {@see chunk_store} row in the completed state); or
 *  - a backup already held in Moodle — their private backup area or private files
 *    (their own user context), or a course's backup area they may download from.
 *
 * The chosen source is recorded on the queued publish transfer as a reference only,
 * and the background job reads its bytes directly, so nothing large is copied in the
 * web request. Every listing and every resolution re-derives the user's permission
 * from the source itself, so a value posted back to the form (or replayed by a
 * background job) can never reach a file the user is not entitled to.
 *
 * @package    repository_largefile
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace repository_largefile\local;

use repository_largefile\chunk_store;

/**
 * Selectable, reference-only sources for a backup share.
 *
 * @package    repository_largefile
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class backup_source {
    /** @var string Source kind: a staged chunked upload owned by the user. */
    public const TYPE_TOKEN = 'token';

    /** @var string Source kind: a stored file already held in Moodle. */
    public const TYPE_STORED = 'stored';

    /** @var int Most course-backup rows to examine when building the menu, so a site
     * with very many course backups never walks the whole files table. */
    private const COURSE_BACKUP_SCAN_CAP = 5000;

    /** @var int Most selectable sources to show in the menu. */
    private const MENU_LIMIT = 200;

    /**
     * The share-source options for a user, keyed by an opaque value the form posts
     * back. A value is "token:&lt;id&gt;" for a staged upload or "stored:&lt;fileid&gt;"
     * for a file already in Moodle; only sources the user is entitled to are listed.
     *
     * @param int $userid The user creating the share.
     * @return array Map of value => human-readable label.
     */
    public static function menu_for_user(int $userid): array {
        $menu = [];
        foreach (self::staged_uploads($userid) as $value => $label) {
            $menu[$value] = $label;
        }
        foreach (self::existing_backups($userid) as $value => $label) {
            $menu[$value] = $label;
        }
        return array_slice($menu, 0, self::MENU_LIMIT, true);
    }

    /**
     * Resolve a value posted by the form to a normalised, re-authorised descriptor.
     *
     * The user's entitlement is checked again here, from the source itself, so a
     * tampered or stale value yields null rather than reaching another user's file.
     *
     * @param string $value The posted source value ("token:..." or "stored:...").
     * @param int $userid The user creating the share.
     * @return array|null Keys 'type', 'filename', 'filesize' plus 'token' or 'fileid';
     *                    null when the value is malformed or not permitted.
     */
    public static function resolve(string $value, int $userid): ?array {
        if (preg_match('/^token:([A-Za-z0-9]+)$/', $value, $m)) {
            $id = $m[1];
            $record = chunk_store::get_record($id);
            if (!$record || (int) $record->userid !== $userid || !chunk_store::is_complete($id)) {
                return null;
            }
            return [
                'type' => self::TYPE_TOKEN,
                'token' => $id,
                'filename' => (string) $record->filename,
                'filesize' => (int) $record->length,
            ];
        }
        if (preg_match('/^stored:(\d+)$/', $value, $m)) {
            $file = self::authorize_stored((int) $m[1], $userid);
            if (!$file) {
                return null;
            }
            return [
                'type' => self::TYPE_STORED,
                'fileid' => (int) $file->get_id(),
                'filename' => (string) $file->get_filename(),
                'filesize' => (int) $file->get_filesize(),
            ];
        }
        return null;
    }

    /**
     * Load a stored file by id, but only if the user is entitled to share it.
     *
     * Permission is derived from the file's own location, not from anything the
     * caller supplies, so this is safe to call with a file id that came from the
     * browser or from a queued job's payload.
     *
     * @param int $fileid The stored file's id.
     * @param int $userid The user the share runs for.
     * @return \stored_file|null The file when permitted, otherwise null.
     */
    public static function authorize_stored(int $fileid, int $userid): ?\stored_file {
        $file = get_file_storage()->get_file_by_id($fileid);
        if (!$file || $file->is_directory()) {
            return null;
        }
        return self::is_allowed_stored($file, $userid) ? $file : null;
    }

    /**
     * Whether a user may share a given stored file, judged from where it lives.
     *
     * A file in the user's own backup area or private files is theirs; a course
     * backup file is shareable only by a user who may download that course's
     * backups.
     *
     * @param \stored_file $file The candidate file.
     * @param int $userid The user the share runs for.
     * @return bool True when the user is entitled to share it.
     */
    private static function is_allowed_stored(\stored_file $file, int $userid): bool {
        $component = $file->get_component();
        $filearea = $file->get_filearea();
        $contextid = (int) $file->get_contextid();

        if ($component === 'user' && in_array($filearea, ['backup', 'private'], true)) {
            return $contextid === (int) \context_user::instance($userid)->id;
        }
        if ($component === 'backup' && $filearea === 'course') {
            $context = \context::instance_by_id($contextid, IGNORE_MISSING);
            return $context
                && (int) $context->contextlevel === CONTEXT_COURSE
                && has_capability('moodle/backup:downloadfile', $context, $userid);
        }
        return false;
    }

    /**
     * The user's completed chunked uploads, as menu options.
     *
     * @param int $userid The user creating the share.
     * @return array Map of "token:id" => label.
     */
    private static function staged_uploads(int $userid): array {
        $options = [];
        foreach (chunk_store::list_completed($userid) as $record) {
            $filename = (string) $record->filename;
            if ($filename === '') {
                continue;
            }
            $value = self::TYPE_TOKEN . ':' . $record->id;
            $options[$value] = self::label(
                $filename,
                (int) $record->length,
                get_string('sourceuploaded', 'repository_largefile')
            );
        }
        return $options;
    }

    /**
     * The user's existing Moodle backups, as menu options: their own backup area
     * and private files, then course backups they may download.
     *
     * @param int $userid The user creating the share.
     * @return array Map of "stored:fileid" => label.
     */
    private static function existing_backups(int $userid): array {
        $options = [];
        $fs = get_file_storage();
        $usercontext = \context_user::instance($userid);

        $ownareas = [
            'backup' => get_string('sourcebackuparea', 'repository_largefile'),
            'private' => get_string('sourceprivatefiles', 'repository_largefile'),
        ];
        foreach ($ownareas as $filearea => $origin) {
            $files = $fs->get_area_files(
                $usercontext->id,
                'user',
                $filearea,
                false,
                'timemodified DESC',
                false
            );
            foreach ($files as $file) {
                $value = self::TYPE_STORED . ':' . $file->get_id();
                $options[$value] = self::label((string) $file->get_filename(), (int) $file->get_filesize(), $origin);
            }
        }

        foreach (self::course_backups($userid) as $value => $label) {
            $options[$value] = $label;
        }
        return $options;
    }

    /**
     * Course-backup-area files the user may download, as menu options. Rows are
     * capability-checked in newest-first order and collected until the menu is full,
     * so an accessible backup is never hidden behind a pre-limit of inaccessible
     * newer ones; the scan is still bounded so it never walks the whole files table.
     *
     * @param int $userid The user creating the share.
     * @return array Map of "stored:fileid" => label.
     */
    private static function course_backups(int $userid): array {
        global $DB;
        $sql = "SELECT f.id, f.filename, f.filesize, f.contextid
                  FROM {files} f
                  JOIN {context} ctx ON ctx.id = f.contextid AND ctx.contextlevel = :courselevel
                 WHERE f.component = :component
                   AND f.filearea = :filearea
                   AND f.filename <> '.'
              ORDER BY f.timemodified DESC, f.id DESC";
        $rs = $DB->get_recordset_sql($sql, [
            'courselevel' => CONTEXT_COURSE,
            'component' => 'backup',
            'filearea' => 'course',
        ]);
        $origin = get_string('sourcecoursebackup', 'repository_largefile');
        $options = [];
        $examined = 0;
        foreach ($rs as $row) {
            if (++$examined > self::COURSE_BACKUP_SCAN_CAP) {
                break;
            }
            $context = \context::instance_by_id((int) $row->contextid, IGNORE_MISSING);
            if (!$context || !has_capability('moodle/backup:downloadfile', $context, $userid)) {
                continue;
            }
            $value = self::TYPE_STORED . ':' . $row->id;
            $options[$value] = self::label((string) $row->filename, (int) $row->filesize, $origin);
            if (count($options) >= self::MENU_LIMIT) {
                break;
            }
        }
        $rs->close();
        return $options;
    }

    /**
     * Compose a menu label from a file name, size and origin.
     *
     * @param string $filename The file name.
     * @param int $filesize The size in bytes.
     * @param string $origin A short description of where the file lives.
     * @return string The label.
     */
    private static function label(string $filename, int $filesize, string $origin): string {
        return get_string(
            'sharesourcelabel',
            'repository_largefile',
            (object) [
                'filename' => $filename,
                'size' => display_size($filesize),
                'origin' => $origin,
            ]
        );
    }
}

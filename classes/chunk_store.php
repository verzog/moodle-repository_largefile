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
 * Server-side state and disk storage for chunked large-file uploads.
 *
 * Derived from the chunked-upload logic in tool_canvasuplifter (itself folded in
 * from local_chunkupload, 2020 Justus Dieckmann WWU), generalised here so a large
 * file can be uploaded to a repository in chunks without hitting PHP's
 * per-request upload/post size limits. Each in-flight upload is one row in
 * {repository_largefile_chunks} plus a partial file on disk under dataroot; the
 * file grows as chunks arrive and is handed to the file picker's draft area once
 * complete (see {@see \repository_largefile::get_file()}).
 *
 * @package    repository_largefile
 * @copyright  2026 Vernon Spain
 * @copyright  2020 Justus Dieckmann WWU
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace repository_largefile;

/**
 * Server-side state and disk storage for chunked large-file uploads.
 *
 * @package    repository_largefile
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class chunk_store {
    /** @var int Token generated when the picker screen rendered, no upload yet. */
    public const STATE_UNUSED = 0;

    /** @var int Upload has started but not all chunks have arrived. */
    public const STATE_STARTED = 1;

    /** @var int All chunks received; the file is complete. */
    public const STATE_COMPLETED = 2;

    /** @var string Database table backing the upload tokens. */
    public const TABLE = 'repository_largefile_chunks';

    /**
     * Create a new upload token owned by the current user, and return its id.
     *
     * @param int $contextid Context the upload was started in.
     * @param int $maxbytes Maximum accepted size in bytes, or -1 for unlimited.
     * @return string|null The new token id, or null for a guest (who may not upload).
     */
    public static function create_token(int $contextid, int $maxbytes): ?string {
        global $DB, $USER;

        if (isguestuser() || !isloggedin()) {
            return null;
        }

        do {
            $id = (string) random_int(1, 10000000000);
        } while ($DB->record_exists(self::TABLE, ['id' => $id]));

        $record = new \stdClass();
        $record->id = $id;
        $record->userid = $USER->id;
        $record->contextid = $contextid;
        $record->maxlength = $maxbytes;
        $record->state = self::STATE_UNUSED;
        $record->currentpos = 0;
        $record->length = 0;
        $record->lastmodified = time();
        $DB->insert_record_raw(self::TABLE, $record, false, false, true);
        return $id;
    }

    /**
     * Create a new upload token owned by a specific user, and return its id.
     *
     * Unlike {@see create_token()} this does not read the current session, so it
     * can be used by a server-side transfer (running under cron) to stage a file
     * on a user's behalf. The caller is responsible for the user being allowed to
     * receive the file.
     *
     * @param int $userid The user to own the token.
     * @param int $contextid Context to record against the token.
     * @param int $maxbytes Maximum accepted size in bytes, or -1 for unlimited.
     * @return string The new token id.
     */
    public static function create_token_for(int $userid, int $contextid, int $maxbytes): string {
        global $DB;

        do {
            $id = (string) random_int(1, 10000000000);
        } while ($DB->record_exists(self::TABLE, ['id' => $id]));

        $record = new \stdClass();
        $record->id = $id;
        $record->userid = $userid;
        $record->contextid = $contextid;
        $record->maxlength = $maxbytes;
        $record->state = self::STATE_UNUSED;
        $record->currentpos = 0;
        $record->length = 0;
        $record->lastmodified = time();
        $DB->insert_record_raw(self::TABLE, $record, false, false, true);
        return $id;
    }

    /**
     * Fetch one token row.
     *
     * @param string $id The token id.
     * @return \stdClass|null The row, or null if it does not exist.
     */
    public static function get_record(string $id): ?\stdClass {
        global $DB;
        $record = $DB->get_record(self::TABLE, ['id' => $id], '*', IGNORE_MISSING);
        return $record ?: null;
    }

    /**
     * List a user's completed, not-yet-consumed staged uploads, newest first.
     *
     * @param int $userid The owning user's id.
     * @return array Array of token rows in the completed state.
     */
    public static function list_completed(int $userid): array {
        global $DB;
        return $DB->get_records(self::TABLE, [
            'userid' => $userid,
            'state' => self::STATE_COMPLETED,
        ], 'lastmodified DESC');
    }

    /**
     * Adopt an already-downloaded file as the stored file for a token (used by the
     * URL-fetch path). The source file is moved into the token's chunk path and
     * the row is marked completed.
     *
     * @param string $id The token id.
     * @param string $srcpath Absolute path of the fetched file to adopt.
     * @param string $filename The file's display name.
     * @return bool True on success.
     */
    public static function adopt_file(string $id, string $srcpath, string $filename): bool {
        global $CFG, $DB;
        $record = self::get_record($id);
        if (!$record || !is_readable($srcpath)) {
            return false;
        }
        $dirpath = self::get_base_folder();
        if (!file_exists($dirpath)) {
            mkdir($dirpath, $CFG->directorypermissions, true);
        }
        $target = self::get_path_for_id($id);
        if (!@rename($srcpath, $target)) {
            if (!@copy($srcpath, $target)) {
                return false;
            }
            @unlink($srcpath);
        }
        $record->filename = $filename;
        $record->length = (int) filesize($target);
        $record->currentpos = $record->length;
        $record->state = self::STATE_COMPLETED;
        $record->lastmodified = time();
        $DB->update_record(self::TABLE, $record);

        // The fetchurl endpoint runs without the session lock, so a concurrent
        // cancel may have deleted this token between the lookup above and now — in
        // which case the update wrote nothing and the moved payload would sit under
        // dataroot with no row for the cleanup task to find. Re-check and remove it.
        if (!self::get_record($id)) {
            if (file_exists($target)) {
                @unlink($target);
            }
            return false;
        }
        return true;
    }

    /**
     * Initialise a token for an out-of-order (Background Fetch) upload: record the
     * total length and file name, mark it started, and create the destination file
     * pre-sized so chunks can be written at any offset. The max-length and
     * accepted-file-kind policy are enforced here, up front, because a Background
     * Fetch cannot surface a per-chunk rejection to the user later.
     *
     * @param \stdClass $record The token row.
     * @param int $length Total file length in bytes.
     * @param string $filename The uploaded file's name.
     * @return string|null An error message, or null on success.
     */
    public static function begin_random($record, int $length, string $filename): ?string {
        global $CFG, $DB;
        if ($length <= 0) {
            return 'Must not be empty!';
        }
        if ((int) $record->maxlength !== -1 && $length > (int) $record->maxlength) {
            return get_string('errorfiletoobig', 'moodle', (int) $record->maxlength);
        }
        $reason = local\import_policy::upload_rejection_reason($filename);
        if ($reason !== null) {
            return $reason;
        }
        $dirpath = self::get_base_folder();
        if (!file_exists($dirpath)) {
            mkdir($dirpath, $CFG->directorypermissions, true);
        }
        $target = self::get_path_for_id($record->id);
        // Pre-size the file so out-of-order writes land at their true offset.
        $handle = @fopen($target, 'c');
        if ($handle === false) {
            return 'Failed to create the upload file.';
        }
        @ftruncate($handle, $length);
        @fclose($handle);
        $record->filename = $filename;
        $record->length = $length;
        $record->currentpos = 0;
        $record->receivedmap = '[]';
        $record->state = self::STATE_STARTED;
        $record->lastmodified = time();
        $DB->update_record(self::TABLE, $record);
        return null;
    }

    /**
     * The largest chunk body the endpoint accepts: twice the configured chunk size,
     * so a resumed upload whose token was issued under a larger setting still
     * finishes, while a modified client cannot post an arbitrarily large body.
     *
     * @return int Bytes.
     */
    public static function max_chunk_bytes(): int {
        $chunkmb = (int) get_config('largefile', 'chunksize');
        if ($chunkmb <= 0) {
            $chunkmb = 20;
        }
        return 2 * $chunkmb * 1024 * 1024;
    }

    /**
     * Spool the raw request body to a temporary stream instead of buffering it in
     * memory: small bodies stay in memory, anything larger spills to a temp file. At
     * most $expected + 1 bytes are read, so an oversize body is detected by the
     * length check without being stored in full.
     *
     * @param int $expected The number of bytes the chunk should contain.
     * @return resource|null A rewound, seekable stream holding the body, or null on failure.
     */
    public static function spool_request_body(int $expected) {
        $in = @fopen('php://input', 'rb');
        $spool = @fopen('php://temp/maxmemory:2097152', 'w+b');
        if ($in === false || $spool === false) {
            return null;
        }
        stream_copy_to_stream($in, $spool, max(0, $expected) + 1);
        fclose($in);
        rewind($spool);
        return $spool;
    }

    /**
     * The length in bytes of a chunk body given as a string or a seekable stream.
     *
     * @param string|resource $content The chunk body.
     * @return int Bytes, or -1 if it cannot be measured.
     */
    private static function body_length($content): int {
        if (is_string($content)) {
            return strlen($content);
        }
        if (is_resource($content)) {
            $stat = fstat($content);
            return is_array($stat) && isset($stat['size']) ? (int) $stat['size'] : -1;
        }
        return -1;
    }

    /**
     * Write a chunk body (from the given offset within it) to an open file handle
     * positioned where the bytes belong.
     *
     * @param resource $handle The destination file handle, already positioned.
     * @param string|resource $content The chunk body.
     * @param int $skip Bytes at the start of the body to skip (already stored).
     * @return int|false Bytes written, or false on failure.
     */
    private static function write_body($handle, $content, int $skip = 0) {
        if (is_string($content)) {
            return @fwrite($handle, $skip > 0 ? substr($content, $skip) : $content);
        }
        if (!is_resource($content) || @fseek($content, $skip) !== 0) {
            return false;
        }
        $written = @stream_copy_to_stream($content, $handle);
        return $written === false ? false : (int) $written;
    }

    /**
     * Write one chunk of an out-of-order upload at its byte offset, recording the
     * range received and completing the upload once every byte has arrived. Safe to
     * call for the same range twice (a Background Fetch retry): the range set is
     * idempotent and the file write is positional. Concurrent chunk writes are
     * serialised on a short per-token lock while the range set is updated.
     *
     * @param \stdClass $record The token row (its length must be set by begin_random()).
     * @param int $start Offset the chunk begins at.
     * @param int $end Offset the chunk ends at (exclusive).
     * @param string|resource $content The chunk bytes (a string or a seekable stream); length must
     *        equal end minus start.
     * @return array|string An array {complete, currentpos} on success, or an error string.
     */
    public static function write_range($record, int $start, int $end, $content) {
        global $DB;
        $length = (int) $record->length;
        if ($length <= 0) {
            return 'This upload was not initialised for Background Fetch.';
        }
        if ($start < 0 || $end > $length || $start >= $end) {
            return 'Chunk range is out of bounds.';
        }
        if (self::body_length($content) !== $end - $start) {
            return 'Filechunk is not as long as it should be.';
        }

        // Write the bytes and record the range under one short per-token lock, so the
        // two stay consistent: the chunk is either fully applied (bytes on disk AND
        // its range counted) or not applied at all. Doing the disk write outside the
        // lock could leave bytes on disk that a failed range-set update never counts,
        // stranding an upload that is physically complete but marked unfinished.
        $lockfactory = \core\lock\lock_config::get_lock_factory('repository_largefile_bg');
        $lock = $lockfactory->get_lock($record->id, 10);
        if (!$lock) {
            return 'Could not acquire the upload lock.';
        }
        try {
            $fresh = self::get_record($record->id);
            if (!$fresh) {
                return 'The upload was cancelled.';
            }
            $target = self::get_path_for_id($record->id);
            $handle = @fopen($target, 'c+');
            if ($handle === false) {
                return 'Failed to open the upload file.';
            }
            $seeked = @fseek($handle, $start) === 0;
            $written = $seeked ? self::write_body($handle, $content) : false;
            @fclose($handle);
            if (!$seeked) {
                return 'Failed to seek in the upload file.';
            }
            if ($written !== $end - $start) {
                return 'Failed to write chunk to disk.';
            }
            $ranges = self::add_range(json_decode($fresh->receivedmap ?: '[]', true) ?: [], $start, $end);
            $covered = self::covered_bytes($ranges);
            $complete = $covered >= $length;
            $fresh->receivedmap = json_encode($ranges);
            $fresh->currentpos = min($covered, $length);
            $fresh->lastmodified = time();
            if ($complete) {
                $fresh->state = self::STATE_COMPLETED;
            }
            $DB->update_record(self::TABLE, $fresh);
            return ['complete' => $complete, 'currentpos' => (int) $fresh->currentpos];
        } finally {
            $lock->release();
        }
    }

    /**
     * The byte ranges of an out-of-order upload that have *not* yet been received —
     * the gaps in [0, length) not covered by the received-range map. A Background
     * Fetch upload that stalled part-way (the browser fails the whole fetch if any
     * one chunk request fails) can be finished by uploading exactly these ranges,
     * so re-selecting the same file resumes it rather than starting over.
     *
     * @param string $id The upload token id.
     * @return array|null List of [start, end) gaps (empty when complete), or null if
     *         the token is unknown.
     */
    public static function missing_ranges(string $id): ?array {
        global $DB;
        $record = $DB->get_record(self::TABLE, ['id' => $id], 'id, length, receivedmap', IGNORE_MISSING);
        if (!$record) {
            return null;
        }
        $length = (int) $record->length;
        if ($length <= 0) {
            return [];
        }
        $ranges = json_decode($record->receivedmap ?: '[]', true) ?: [];
        usort($ranges, fn($a, $b) => $a[0] <=> $b[0]);
        $missing = [];
        $cursor = 0;
        foreach ($ranges as $range) {
            $rangestart = (int) $range[0];
            if ($rangestart > $cursor) {
                $missing[] = [$cursor, $rangestart];
            }
            $cursor = max($cursor, (int) $range[1]);
        }
        if ($cursor < $length) {
            $missing[] = [$cursor, $length];
        }
        return $missing;
    }

    /**
     * Merge a new [start, end) range into a sorted, non-overlapping range list.
     *
     * @param array $ranges Existing list of [start, end] pairs.
     * @param int $start New range start.
     * @param int $end New range end (exclusive).
     * @return array The merged list of [start, end] pairs.
     */
    private static function add_range(array $ranges, int $start, int $end): array {
        $ranges[] = [$start, $end];
        usort($ranges, fn($a, $b) => $a[0] <=> $b[0]);
        $merged = [];
        foreach ($ranges as $range) {
            $last = count($merged) - 1;
            if ($merged && $range[0] <= $merged[$last][1]) {
                $merged[$last][1] = max($merged[$last][1], $range[1]);
            } else {
                $merged[] = [(int) $range[0], (int) $range[1]];
            }
        }
        return $merged;
    }

    /**
     * Total bytes covered by a merged range list.
     *
     * @param array $ranges List of [start, end] pairs.
     * @return int The covered byte count.
     */
    private static function covered_bytes(array $ranges): int {
        $total = 0;
        foreach ($ranges as $range) {
            $total += $range[1] - $range[0];
        }
        return $total;
    }

    /**
     * Base folder under dataroot where partial chunk files live.
     *
     * @return string Absolute path ending in a directory separator.
     */
    public static function get_base_folder(): string {
        global $CFG;
        return "$CFG->dataroot/repository_largefile/chunks/";
    }

    /**
     * Absolute path of the partial file for a token.
     *
     * @param string|null $id The token id.
     * @return string|null The path, or null when no id was given.
     */
    public static function get_path_for_id(?string $id): ?string {
        if ($id === null || $id === '') {
            return null;
        }
        return self::get_base_folder() . $id;
    }

    /**
     * Snapshot of how far the server has actually stored an in-flight upload, so
     * the browser can reconcile against it after a failed chunk (e.g. a 504 that
     * timed out the response but still committed the write) instead of
     * dead-ending on a chunk-alignment error.
     *
     * @param string $id The token id.
     * @return array|null Keys state, currentpos, length; or null if not found.
     */
    public static function get_progress(string $id): ?array {
        global $DB;
        $record = $DB->get_record(self::TABLE, ['id' => $id], 'id, state, currentpos, length', IGNORE_MISSING);
        if (!$record) {
            return null;
        }
        return [
            'state' => (int) $record->state,
            'currentpos' => (int) $record->currentpos,
            'length' => (int) $record->length,
        ];
    }

    /**
     * Whether a chunk row is a Background Fetch upload (one that keeps running after
     * the tab is closed) rather than an in-page chunked upload. Only the background
     * path initialises the received-range map ({@see self::begin_random()}); the
     * sequential in-page path never touches it, so a non-null receivedmap is the
     * reliable marker.
     *
     * @param \stdClass $row A chunk row (must include the receivedmap field).
     * @return bool True when the upload is a Background Fetch upload.
     */
    public static function is_background($row): bool {
        return isset($row->receivedmap) && $row->receivedmap !== null && $row->receivedmap !== '';
    }

    /**
     * Write the first chunk of a new upload, creating the partial file on disk.
     *
     * @param \stdClass $record The token row (mutated and saved on success).
     * @param int $start Offset the chunk begins at (must be 0 for the first chunk).
     * @param int $end Offset the chunk ends at.
     * @param int $length Total declared file length in bytes.
     * @param string $filename The uploaded file's name.
     * @param string|resource $content The chunk bytes (a string or a seekable stream); length must equal end.
     * @return string|null An error message, or null on success.
     */
    public static function apply_start($record, int $start, int $end, int $length, string $filename, $content): ?string {
        global $CFG, $DB;

        if ($length <= 0) {
            return 'Must not be empty!';
        }
        if ($start !== 0) {
            return 'A start chunk must begin at 0.';
        }
        if ((int) $record->maxlength !== -1 && $length > (int) $record->maxlength) {
            return get_string('errorfiletoobig', 'moodle', (int) $record->maxlength);
        }
        // Enforce the site's upload policy at the first chunk, before any bytes are
        // written: the picker must be an enabled destination and the file's kind
        // must be accepted, so a rejected upload never lands on disk.
        $reason = local\import_policy::upload_rejection_reason($filename);
        if ($reason !== null) {
            return $reason;
        }
        if ($end > $length) {
            return 'Chunk is longer than specified length';
        }
        if (self::body_length($content) !== $end) {
            return 'Filechunk is not as long as it should be.';
        }

        // Write and record under the per-token lock, which a concurrent admin removal
        // ({@see self::delete_in_state()}) also holds: so a removal cannot see this
        // upload as still in progress and then delete a file this call is completing,
        // and — because the row is re-checked here first — a removal that already ran
        // cannot be raced into recreating an orphaned file for a deleted upload.
        $lockfactory = \core\lock\lock_config::get_lock_factory('repository_largefile_bg');
        $lock = $lockfactory->get_lock($record->id, 10);
        if (!$lock) {
            return 'Could not acquire the upload lock.';
        }
        try {
            if (!self::get_record($record->id)) {
                return 'The upload was cancelled.';
            }
            $dirpath = self::get_base_folder();
            if (!file_exists($dirpath)) {
                mkdir($dirpath, $CFG->directorypermissions, true);
            }
            // Only advance the stored position by the bytes actually persisted, so a
            // short write (disk full, quota) can never mark the upload further along
            // than the file really is — which would hand the picker a truncated file.
            $handle = @fopen(self::get_path_for_id($record->id), 'wb');
            $written = $handle === false ? false : self::write_body($handle, $content);
            if ($handle !== false) {
                @fclose($handle);
            }
            if ($written === false || $written !== $end) {
                return 'Failed to write chunk to disk.';
            }

            $record->currentpos = $end;
            $record->length = $length;
            $record->lastmodified = time();
            $record->state = $end === $length ? self::STATE_COMPLETED : self::STATE_STARTED;
            $record->filename = $filename;
            $DB->update_record(self::TABLE, $record);
            return null;
        } finally {
            $lock->release();
        }
    }

    /**
     * Append a "proceed" chunk to a partially uploaded file, tolerating a re-sent
     * or partially written chunk left behind by an interrupted request.
     *
     * The stored currentpos is the source of truth: any bytes a half-finished
     * attempt wrote past it are truncated before writing, so re-sending a chunk
     * can never double-append; a chunk already fully stored (the client retried
     * after a lost response) is accepted as a no-op.
     *
     * @param \stdClass $record The token row (mutated and saved on success).
     * @param int $start Offset the client believes the chunk begins at.
     * @param int $end Offset the chunk ends at.
     * @param string|resource $content The chunk bytes (a string or a seekable stream); length must equal
     *        end minus start.
     * @return string|null An error message, or null on success.
     */
    public static function apply_proceed($record, int $start, int $end, $content): ?string {
        global $DB;
        $error = self::check_bounds($record, $start, $end);
        if ($error !== null) {
            return $error;
        }
        if (self::body_length($content) !== $end - $start) {
            return 'Filechunk is not as long as it should be.';
        }

        // Serialise on the per-token lock a concurrent admin removal also holds, so a
        // removal cannot see this upload in progress and then delete a file this call
        // is completing. The row is re-checked inside the lock: if a removal already
        // took it, this returns cleanly instead of writing on into a deleted upload.
        $lockfactory = \core\lock\lock_config::get_lock_factory('repository_largefile_bg');
        $lock = $lockfactory->get_lock($record->id, 10);
        if (!$lock) {
            return 'Could not acquire the upload lock.';
        }
        try {
            if (!self::get_record($record->id)) {
                return 'The upload was cancelled.';
            }
            $path = self::get_path_for_id($record->id);
            if ($path === null || !file_exists($path)) {
                return 'Begin of file does not exist on this server.';
            }

            $currentpos = (int) $record->currentpos;
            if ($end > $currentpos) {
                // Trust the stored position: drop any bytes an interrupted retry left
                // past it, then write only the portion beyond currentpos.
                $handle = fopen($path, 'r+b');
                if ($handle === false) {
                    return 'Begin of file does not exist on this server.';
                }
                $expected = $end - $currentpos;
                if (ftruncate($handle, $currentpos) === false || fseek($handle, $currentpos) !== 0) {
                    fclose($handle);
                    return 'Could not position the upload file for writing.';
                }
                // Advance the stored position only by the bytes fwrite actually
                // persisted, so a short write (disk full, quota) never marks the
                // upload further along than the file really is; the client then
                // resumes from the true position. Persist that position before
                // reporting the failure so the resume is accurate.
                $written = self::write_body($handle, $content, $currentpos - $start);
                fclose($handle);
                if ($written === false) {
                    return 'Failed to write chunk to disk.';
                }
                $record->currentpos = $currentpos + $written;
                if ($written < $expected) {
                    $record->state = self::STATE_STARTED;
                    $record->lastmodified = time();
                    $DB->update_record(self::TABLE, $record);
                    return 'Failed to write the whole chunk to disk.';
                }
            }
            // Otherwise the whole chunk is already stored — accept it as a no-op.
            $record->state = (int) $record->currentpos === (int) $record->length
                ? self::STATE_COMPLETED : self::STATE_STARTED;
            $record->lastmodified = time();
            $DB->update_record(self::TABLE, $record);
            return null;
        } finally {
            $lock->release();
        }
    }

    /**
     * Validate a "proceed" chunk's byte range against the stored upload — the
     * checks that need no request body, so the endpoint can reject a malformed or
     * replayed request from its token's state alone before buffering the payload.
     *
     * @param \stdClass $record The token row.
     * @param int $start Offset the client believes the chunk begins at.
     * @param int $end Offset the chunk ends at.
     * @return string|null An error message, or null if the range is acceptable.
     */
    public static function check_bounds($record, int $start, int $end): ?string {
        if ($start < 0 || $end < $start) {
            return 'Filechunk range is invalid.';
        }
        if ($start > (int) $record->currentpos) {
            return 'Filechunk does not begin where the last one left off.';
        }
        if ($end > (int) $record->length) {
            return 'Filechunk is too long and exceeds the length of the whole file.';
        }
        return null;
    }

    /**
     * Whether a completed file is stored for the given token.
     *
     * @param string|null $id The token id.
     * @return bool True when the upload finished and the file is on disk.
     */
    public static function is_complete(?string $id): bool {
        if ($id === null || $id === '') {
            return false;
        }
        $record = self::get_record($id);
        if (!$record || (int) $record->state !== self::STATE_COMPLETED) {
            return false;
        }
        $path = self::get_path_for_id($id);
        return $path !== null && file_exists($path);
    }

    /**
     * Reset a token to the unused state and remove any partial file.
     *
     * @param string $id The token id.
     * @return void
     */
    public static function reset(string $id): void {
        global $DB;
        $path = self::get_path_for_id($id);
        if ($path !== null && file_exists($path)) {
            unlink($path);
        }
        $record = self::get_record($id);
        if ($record) {
            $record->currentpos = 0;
            $record->length = 0;
            $record->filename = '';
            $record->state = self::STATE_UNUSED;
            $record->lastmodified = time();
            $DB->update_record(self::TABLE, $record);
        }
    }

    /**
     * Delete a token row and its file.
     *
     * The file is removed first, and the tracking row is dropped only once the
     * bytes are gone. If the unlink fails (a transient permission or open-file
     * problem), the row is kept so the cleanup task retries and privacy deletion
     * cannot report success while the payload still sits under dataroot.
     *
     * @param string $id The token id.
     * @return void
     */
    public static function delete(string $id): void {
        global $DB;
        $path = self::get_path_for_id($id);
        if ($path !== null && file_exists($path) && !@unlink($path) && file_exists($path)) {
            // The bytes could not be removed; leave the row for a later retry.
            return;
        }
        $DB->delete_records(self::TABLE, ['id' => $id]);
    }

    /**
     * Remove a still-in-progress upload on demand — its row and partial file — but
     * only while it is genuinely unfinished. The state is re-read under the same
     * per-token lock the background writer uses, so an upload that *completed* after
     * the admin's page was rendered is never deleted (its file is now the user's),
     * and a resuming background {@see self::write_range()} cannot interleave and
     * leave an orphaned partial with no row. The real outcome is reported, so a
     * filesystem failure is not announced as success.
     *
     * @param string $id The upload token id.
     * @return string 'removed' (row and file gone), 'notstarted' (unknown or already
     *         complete — nothing removed), or 'failed' (lock unavailable, or the file
     *         could not be unlinked and the row was kept for the cleanup task).
     */
    public static function delete_if_started(string $id): string {
        return self::delete_in_state($id, self::STATE_STARTED);
    }

    /**
     * Remove an upload on demand — its row and its file — but only while it is in the
     * expected state, re-checked under the same per-token lock the background writer
     * uses. Removing an in-progress upload (STATE_STARTED) reclaims a stalled partial;
     * removing a completed one (STATE_COMPLETED) discards a staged file the owner
     * uploaded but has not yet selected. The state guard means a stale link cannot
     * delete an upload that has since moved on, and the true outcome is reported.
     *
     * @param string $id The upload token id.
     * @param int $state The state the row must still be in (a STATE_* constant).
     * @return string 'removed', 'notstarted' (unknown or no longer in that state —
     *         nothing removed), or 'failed' (lock unavailable, or the file could not
     *         be unlinked and the row was kept for the cleanup task).
     */
    public static function delete_in_state(string $id, int $state): string {
        $lockfactory = \core\lock\lock_config::get_lock_factory('repository_largefile_bg');
        $lock = $lockfactory->get_lock($id, 10);
        if (!$lock) {
            return 'failed';
        }
        try {
            $record = self::get_record($id);
            if (!$record || (int) $record->state !== $state) {
                return 'notstarted';
            }
            self::delete($id);
            // A surviving row means delete() could not unlink the file.
            return self::get_record($id) ? 'failed' : 'removed';
        } finally {
            $lock->release();
        }
    }

    /**
     * Remove every in-progress upload site-wide, each through {@see self::delete_if_started()}
     * so the same lock and state re-check apply — a partial that completed in the
     * meantime is left alone. For an admin reclaiming disk when stalled uploads have
     * built up in the chunk area.
     *
     * @return int How many uploads were actually removed.
     */
    public static function delete_all_started(): int {
        return self::delete_all_in_state(self::STATE_STARTED);
    }

    /**
     * Remove every upload site-wide currently in the given state, each through
     * {@see self::delete_in_state()} so the same lock and state re-check apply. For an
     * admin reclaiming disk — STATE_STARTED clears stalled partials, STATE_COMPLETED
     * clears staged files that were uploaded but never selected.
     *
     * @param int $state The state to clear (a STATE_* constant).
     * @return int How many uploads were actually removed.
     */
    public static function delete_all_in_state(int $state): int {
        global $DB;
        $ids = $DB->get_fieldset_select(self::TABLE, 'id', 'state = :state', ['state' => $state]);
        $removed = 0;
        foreach ($ids as $id) {
            if (self::delete_in_state((string) $id, $state) === 'removed') {
                $removed++;
            }
        }
        return $removed;
    }
}

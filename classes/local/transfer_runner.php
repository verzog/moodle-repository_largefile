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
 * Executes a single queued transfer on the server, unattended.
 *
 * Runs under cron (no session), so it never reads the current user: everything
 * it does is on behalf of the transfer's own {@see transfer_manager} row. A URL
 * import is fetched (SSRF-aware); a share import is fetched, decrypted and verified
 * ({@see share_client}). Either is then routed by {@see import_policy} to the
 * destination recorded for the transfer — the large-file picker, the private backup
 * area (restorable), or private files — defaulting to the picker for a URL import
 * and the backup area for a peer share. All outcomes are recorded back on the
 * transfer row so the admin monitor and the owner can see what happened.
 *
 * @package    repository_largefile
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace repository_largefile\local;

/**
 * Executes a single queued transfer on the server.
 *
 * @package    repository_largefile
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class transfer_runner {
    /**
     * Run one transfer, recording success or failure on its row.
     *
     * @param \stdClass $transfer The transfer row to execute.
     * @return void
     */
    public static function run(\stdClass $transfer): void {
        if (!transfer_manager::claim((int) $transfer->id)) {
            // Cancelled or already taken since the batch was read — skip it.
            return;
        }
        try {
            \core_php_time_limit::raise();
            raise_memory_limit(MEMORY_EXTRA);
            $payload = transfer_manager::payload($transfer);
            if ($transfer->type === transfer_manager::TYPE_URL) {
                $result = self::run_url_import($transfer, $payload);
            } else if ($transfer->type === transfer_manager::TYPE_SHARE) {
                $result = self::run_share_import($transfer, $payload);
            } else if ($transfer->type === transfer_manager::TYPE_PUBLISH) {
                $result = self::run_share_publish($transfer, $payload);
            } else if ($transfer->type === transfer_manager::TYPE_RESTORE) {
                $result = self::run_restore_prep($transfer, $payload);
            } else if ($transfer->type === transfer_manager::TYPE_AUTORESTORE) {
                $result = self::run_auto_restore($transfer, $payload);
            } else {
                throw new \moodle_exception('errortransferunknown', 'repository_largefile');
            }
            transfer_manager::mark_completed((int) $transfer->id, $result);
            \repository_largefile\event\transfer_completed::for_transfer($transfer, $result)->trigger();
            if ($transfer->type === transfer_manager::TYPE_PUBLISH) {
                self::notify_publish((int) $transfer->userid, (string) $transfer->filename, $result, null);
            } else if (self::is_restore($transfer)) {
                $transfer->result = $result;
                self::notify_restore($transfer, null);
            }
        } catch (\Throwable $e) {
            transfer_manager::mark_failed((int) $transfer->id, $e->getMessage());
            if ($transfer->type === transfer_manager::TYPE_PUBLISH) {
                self::notify_publish((int) $transfer->userid, (string) $transfer->filename, null, $e->getMessage());
            } else if (self::is_restore($transfer)) {
                self::notify_restore($transfer, $e->getMessage());
            }
        }
    }

    /**
     * Notify a publisher whose share transfer was failed outside {@see self::run()}
     * — e.g. by {@see transfer_manager::reclaim_stale()} after repeated interruptions
     * — so the promised failure notification still reaches them.
     *
     * @param \stdClass $transfer The failed publish transfer row (with its error set).
     * @return void
     */
    public static function notify_publish_failure(\stdClass $transfer): void {
        self::notify_publish(
            (int) $transfer->userid,
            (string) ($transfer->filename ?? ''),
            null,
            (string) ($transfer->error ?? '')
        );
    }

    /**
     * Whether a transfer is one of the two restore kinds, which notify their operator.
     *
     * @param \stdClass $transfer A transfer row.
     * @return bool True for a prepared or an automatic restore.
     */
    public static function is_restore(\stdClass $transfer): bool {
        return in_array($transfer->type, [transfer_manager::TYPE_RESTORE, transfer_manager::TYPE_AUTORESTORE], true);
    }

    /**
     * Notify the operator of a restore that was failed outside {@see self::run()}
     * (e.g. by {@see transfer_manager::reclaim_stale()}), so they are not left
     * waiting for a restore link that will never come.
     *
     * @param \stdClass $transfer The failed restore transfer row (with its error set).
     * @return void
     */
    public static function notify_restore_failure(\stdClass $transfer): void {
        self::notify_restore($transfer, (string) ($transfer->error ?? ''));
    }

    /**
     * The restore wizard URL for a completed restore-preparation transfer: Moodle's
     * restore.php pinned to the backup file the transfer placed in the course backup
     * area, so the operator lands straight on the first restore step.
     *
     * @param \stdClass $transfer A restore transfer row whose result is the stored file name.
     * @return \moodle_url|null The URL, or null when the course or the file is gone.
     */
    public static function restore_url(\stdClass $transfer): ?\moodle_url {
        $courseid = (int) (transfer_manager::payload($transfer)['courseid'] ?? 0);
        $filename = (string) ($transfer->result ?? '');
        if ($courseid <= 0 || $filename === '') {
            return null;
        }
        $coursecontext = \context_course::instance($courseid, IGNORE_MISSING);
        if (!$coursecontext) {
            return null;
        }
        $file = get_file_storage()->get_file($coursecontext->id, 'backup', 'course', 0, '/', $filename);
        if (!$file) {
            return null;
        }
        return new \moodle_url('/backup/restore.php', [
            'contextid' => $coursecontext->id,
            'pathnamehash' => $file->get_pathnamehash(),
            'contenthash' => $file->get_contenthash(),
        ]);
    }

    /**
     * Tell the operator a queued restore is ready — with a link straight into the
     * restore wizard — or that it failed. A messaging failure is swallowed: the
     * outcome is already recorded on the transfer row, so it must not fail the job.
     *
     * @param \stdClass $transfer The restore transfer row (with result set on success).
     * @param string|null $error The failure message, or null on success.
     * @return void
     */
    private static function notify_restore(\stdClass $transfer, ?string $error): void {
        $user = \core_user::get_user((int) $transfer->userid);
        if (!$user) {
            return;
        }
        $name = (string) ($transfer->filename ?? '') !== '' ? (string) $transfer->filename : '-';
        $auto = $transfer->type === transfer_manager::TYPE_AUTORESTORE;
        $url = null;
        if ($error === null) {
            // An automatic restore's result is the new course's id; a prepared one's
            // is the backup file waiting for the restore wizard.
            $url = $auto
                ? new \moodle_url('/course/view.php', ['id' => (int) $transfer->result])
                : self::restore_url($transfer);
        }
        $url = $url ?? new \moodle_url('/repository/largefile/transfers.php');

        $message = new \core\message\message();
        $message->component = 'repository_largefile';
        $message->name = 'restoreready';
        $message->userfrom = \core_user::get_noreply_user();
        $message->userto = $user;
        $message->notification = 1;
        $message->courseid = SITEID;
        $message->contexturl = $url->out(false);
        if ($error === null && $auto) {
            $message->contexturlname = get_string('restoreviewcourse', 'repository_largefile');
            $message->subject = get_string('notifyrestoredsubject', 'repository_largefile', $name);
            $body = get_string('notifyrestoredbody', 'repository_largefile', $name) . "\n\n" . $url->out(false);
        } else if ($error === null) {
            $message->contexturlname = get_string('restorecontinue', 'repository_largefile');
            $message->subject = get_string('notifyrestorereadysubject', 'repository_largefile', $name);
            $body = get_string('notifyrestorereadybody', 'repository_largefile', $name) . "\n\n" . $url->out(false);
        } else {
            $message->contexturlname = get_string('transfers', 'repository_largefile');
            $message->subject = get_string('notifyrestorefailedsubject', 'repository_largefile', $name);
            $body = get_string(
                'notifyrestorefailedbody',
                'repository_largefile',
                (object) ['filename' => $name, 'error' => $error]
            );
        }
        $message->fullmessage = $body;
        $message->fullmessageformat = FORMAT_PLAIN;
        $message->fullmessagehtml = text_to_html($body);
        $message->smallmessage = $message->subject;

        try {
            message_send($message);
        } catch (\Throwable $e) {
            debugging(
                'repository_largefile: restore notification could not be sent: ' . $e->getMessage(),
                DEBUG_DEVELOPER
            );
        }
    }

    /**
     * Notify the publisher that a queued share has finished — with its link on
     * success, or the error on failure. A messaging failure is swallowed: the
     * outcome is already recorded on the transfer row, so it must not fail the job.
     *
     * @param int $userid The publishing user.
     * @param string $filename The published file name (may be empty).
     * @param string|null $link The share link on success, or null on failure.
     * @param string|null $error The failure message, or null on success.
     * @return void
     */
    private static function notify_publish(int $userid, string $filename, ?string $link, ?string $error): void {
        $user = \core_user::get_user($userid);
        if (!$user) {
            return;
        }
        $name = $filename !== '' ? $filename : '-';
        $sharesurl = new \moodle_url('/repository/largefile/manage_shares.php');

        $message = new \core\message\message();
        $message->component = 'repository_largefile';
        $message->name = 'sharepublished';
        $message->userfrom = \core_user::get_noreply_user();
        $message->userto = $user;
        $message->notification = 1;
        $message->courseid = SITEID;
        $message->contexturl = $sharesurl->out(false);
        $message->contexturlname = get_string('manageshares', 'repository_largefile');

        if ($error === null) {
            $message->subject = get_string('notifysharereadysubject', 'repository_largefile', $name);
            $body = get_string('notifysharereadybody', 'repository_largefile', $name);
            if ($link !== null && $link !== '') {
                $body .= "\n\n" . $link;
            }
        } else {
            $message->subject = get_string('notifysharefailedsubject', 'repository_largefile', $name);
            $body = get_string(
                'notifysharefailedbody',
                'repository_largefile',
                (object) ['filename' => $name, 'error' => $error]
            );
        }
        $message->fullmessage = $body;
        $message->fullmessageformat = FORMAT_PLAIN;
        $message->fullmessagehtml = text_to_html($body);
        $message->smallmessage = $message->subject;

        try {
            message_send($message);
        } catch (\Throwable $e) {
            debugging(
                'repository_largefile: share notification could not be sent: ' . $e->getMessage(),
                DEBUG_DEVELOPER
            );
        }
    }

    /**
     * Fetch a URL server-side and stage it into the owner's large-file picker.
     *
     * @param \stdClass $transfer The transfer row.
     * @param array $payload Decoded payload; expects a 'url' key.
     * @return string The staged file name.
     * @throws \moodle_exception On a transport or staging failure.
     */
    private static function run_url_import(\stdClass $transfer, array $payload): string {
        global $CFG;
        $url = (string) ($payload['url'] ?? '');
        if (!url_fetcher::is_fetchable_url($url)) {
            throw new \moodle_exception('errorshareinvalidurl', 'repository_largefile');
        }
        $fetcher = new url_fetcher();
        // Label the row with the server's file name as soon as the response headers
        // arrive (the download itself may run for a long time), then with the final
        // derived name once it completes.
        $onname = function (string $name) use ($transfer): void {
            transfer_manager::set_filename((int) $transfer->id, $name);
        };
        $fetched = $fetcher->fetch($url, (int) ($CFG->maxbytes ?? 0), null, null, [], true, $onname);
        transfer_manager::set_filename((int) $transfer->id, (string) $fetched['filename']);

        // No recorded choice means "auto": the policy routes to the kind's default
        // enabled destination (for a URL import, historically the large-file picker).
        $destination = (string) ($payload['destination'] ?? '');
        $contextid = (int) ($transfer->contextid ?: \context_system::instance()->id);
        return import_policy::store_imported_file(
            (int) $transfer->userid,
            $fetched['path'],
            $fetched['filename'],
            $destination,
            $contextid,
            (int) ($payload['targetcourseid'] ?? 0)
        );
    }

    /**
     * Fetch, decrypt and verify a peer's shared backup and save it into the owner's
     * private backup area, ready to restore.
     *
     * The file is stored in the user context's `backup` file area (not generic
     * private files), so it appears directly under "User private backup area" on
     * every course's restore screen with a one-click restore link — which is where
     * a course backup is actually usable.
     *
     * @param \stdClass $transfer The transfer row.
     * @param array $payload Decoded payload; expects 'peerid' and 'shareurl'.
     * @return string The saved file name.
     * @throws \moodle_exception On any transport, authentication or integrity failure.
     */
    private static function run_share_import(\stdClass $transfer, array $payload): string {
        $peerid = (int) ($payload['peerid'] ?? 0);
        $shareurl = (string) ($payload['shareurl'] ?? '');
        // Show the file name on the Transfers page as soon as the peer's metadata
        // reveals it, rather than only after the (possibly long) download.
        $result = share_client::import($peerid, $shareurl, function (array $meta) use ($transfer): void {
            transfer_manager::set_filename((int) $transfer->id, clean_param((string) $meta['filename'], PARAM_FILE));
        });

        // No recorded choice means "auto": the policy routes to the kind's default
        // enabled destination (for a peer share, historically the private backup area).
        $destination = (string) ($payload['destination'] ?? '');
        $contextid = (int) ($transfer->contextid ?: \context_system::instance()->id);
        $filename = import_policy::store_imported_file(
            (int) $transfer->userid,
            $result['path'],
            $result['filename'],
            $destination,
            $contextid,
            (int) ($payload['targetcourseid'] ?? 0)
        );

        // Emit the same domain event as the synchronous import path (import.php),
        // so audit integrations see every imported backup regardless of how it was
        // started.
        $peer = peer_manager::get($peerid);
        \repository_largefile\event\backup_imported::build(
            (int) $transfer->userid,
            $peer ? $peer->name : '',
            $filename
        )->trigger();
        return $filename;
    }

    /**
     * Encrypt a staged backup for a peer and publish it, server-side.
     *
     * The uploaded backup was staged by the create-share form into a plugin-owned
     * area keyed by this transfer's id, so no copy of a large backup is made in the
     * web request and Moodle's draft cleanup cannot remove it before the job runs.
     * Here it is encrypted and stored ({@see share_manager}); the staged source is
     * removed on success (and, on failure, swept by the cleanup task).
     *
     * @param \stdClass $transfer The transfer row.
     * @param array $payload Decoded payload; expects 'peerid', 'expiryduration' and 'maxdownloads'.
     * @return string The share link to hand to the peer.
     * @throws \moodle_exception If the staged file is gone or encryption fails.
     */
    private static function run_share_publish(\stdClass $transfer, array $payload): string {
        $peerid = (int) ($payload['peerid'] ?? 0);
        // The expiry is measured from when the share actually exists, not from when
        // it was queued, so a cron delay does not eat into the share's lifetime.
        $duration = (int) ($payload['expiryduration'] ?? 0);
        $expires = $duration > 0 ? time() + $duration : 0;
        $maxdownloads = (int) ($payload['maxdownloads'] ?? 0);

        // Report progress on the transfer row, throttled to at most once a second so
        // a long encryption stays observable without hammering the database. The final
        // update (100%) is never throttled away: it tells the Transfers page that
        // encryption is over and the encrypted file is now being stored — a step that
        // reports no progress of its own and can take minutes.
        $lastupdate = 0;
        $onprogress = function (int $done, int $total) use ($transfer, &$lastupdate): void {
            $now = time();
            if ($total > 0 && ($now !== $lastupdate || $done >= $total)) {
                $lastupdate = $now;
                transfer_manager::set_progress((int) $transfer->id, (int) floor($done * 100 / $total));
            }
        };

        $sourcetype = (string) ($payload['sourcetype'] ?? '');

        if ($sourcetype === backup_source::TYPE_TOKEN) {
            // A large file staged through this plugin's chunked uploader: encrypt
            // straight from the staged file on disk, then discard it. Re-check the job
            // owner still owns a completed staged file, so a payload that outlived its
            // source (or names another user's) cannot be published.
            $token = (string) ($payload['token'] ?? '');
            $record = \repository_largefile\chunk_store::get_record($token);
            if (
                !$record
                || (int) $record->userid !== (int) $transfer->userid
                || !\repository_largefile\chunk_store::is_complete($token)
            ) {
                throw new \moodle_exception('errorsharenofile', 'repository_largefile');
            }
            $path = \repository_largefile\chunk_store::get_path_for_id($token);
            if ($path === null || !is_file($path)) {
                throw new \moodle_exception('errorsharenofile', 'repository_largefile');
            }
            $filename = (string) $record->filename;
            share_manager::delete_unstored($peerid, $filename, (int) $transfer->userid);
            $share = share_manager::create(
                $peerid,
                $path,
                $filename,
                $expires,
                $maxdownloads,
                (int) $transfer->userid,
                $onprogress
            );
            \repository_largefile\event\share_created::for_share($share)->trigger();
            // The staged upload is left in place, not deleted here: it is an ordinary
            // completed upload that the owner may also publish again, send or restore,
            // so consuming it once must not pull it out from under those. The cleanup
            // task retires it on its normal completed-upload retention, and the
            // pending-publish guard keeps it until every queued publish of it has run.
            return (new \moodle_url('/repository/largefile/share.php', ['token' => $share->token]))->out(false);
        }

        if ($sourcetype === backup_source::TYPE_STORED) {
            // A backup already held in Moodle: encrypt straight from the stored file
            // (no plaintext temp copy) and leave the original in place. Permission is
            // re-derived from the file itself, so an unattended job cannot publish a
            // file its owner may no longer access.
            $file = backup_source::authorize_stored((int) ($payload['fileid'] ?? 0), (int) $transfer->userid);
            if (!$file) {
                throw new \moodle_exception('errorsharenofile', 'repository_largefile');
            }
            share_manager::delete_unstored($peerid, $file->get_filename(), (int) $transfer->userid);
            $share = share_manager::create_from_storedfile(
                $peerid,
                $file,
                $expires,
                $maxdownloads,
                (int) $transfer->userid,
                $onprogress
            );
            \repository_largefile\event\share_created::for_share($share)->trigger();
            return (new \moodle_url('/repository/largefile/share.php', ['token' => $share->token]))->out(false);
        }

        // Legacy path: an older create-share form staged the plaintext into a
        // plugin-owned area keyed by this transfer's id. Still handled so a job queued
        // before the reference-based form is honoured.
        $fs = get_file_storage();
        $files = $fs->get_area_files(
            \context_system::instance()->id,
            'repository_largefile',
            transfer_manager::PENDING_FILEAREA,
            (int) $transfer->id,
            'id DESC',
            false
        );
        $file = reset($files);
        if (!$file) {
            throw new \moodle_exception('errorsharenofile', 'repository_largefile');
        }
        // A previous attempt at this publication may have died between recording the
        // share and storing its encrypted file (the lease then returns the job here).
        // Such a share can never be downloaded, so remove it rather than leave a
        // duplicate, file-less entry beside the one this attempt creates.
        share_manager::delete_unstored($peerid, $file->get_filename(), (int) $transfer->userid);
        $share = share_manager::create_from_storedfile(
            $peerid,
            $file,
            $expires,
            $maxdownloads,
            (int) $transfer->userid,
            $onprogress
        );
        \repository_largefile\event\share_created::for_share($share)->trigger();
        // The plaintext source is no longer needed once it is encrypted and stored.
        transfer_manager::delete_publish_source((int) $transfer->id);

        return (new \moodle_url('/repository/largefile/share.php', ['token' => $share->token]))->out(false);
    }

    /**
     * Copy a completed chunked upload (.mbz) into a course's backup area, ready for
     * the restore wizard.
     *
     * This is the slow half of "Restore…" on the Transfers page: hashing and copying
     * a multi-gigabyte backup into the file pool takes minutes, far longer than a web
     * request survives behind a proxy, so it runs here under cron instead. The job
     * runs unattended, so the operator's rights are re-checked now rather than
     * trusted from when it was queued.
     *
     * @param \stdClass $transfer The transfer row.
     * @param array $payload Decoded payload; expects 'token' and 'courseid'.
     * @return string The stored backup file name in the course backup area.
     * @throws \moodle_exception If the upload is gone or the operator lacks the rights.
     */
    private static function run_restore_prep(\stdClass $transfer, array $payload): string {
        $userid = (int) $transfer->userid;
        $token = (string) ($payload['token'] ?? '');
        $courseid = (int) ($payload['courseid'] ?? 0);
        $coursecontext = $courseid > 0 ? \context_course::instance($courseid, IGNORE_MISSING) : null;
        if (
            !$coursecontext
                || !has_capability('repository/largefile:import', \context_system::instance(), $userid)
                || !has_capability('moodle/restore:uploadfile', $coursecontext, $userid)
                || !has_capability('moodle/restore:restorecourse', $coursecontext, $userid)
        ) {
            throw new \moodle_exception('errornocoursebackupcap', 'repository_largefile');
        }

        // Hold the same per-token lock the Transfers page actions (Send to…, Remove)
        // take, so none of them can move or delete the source while it is copied.
        $lockfactory = \core\lock\lock_config::get_lock_factory('repository_largefile_bg');
        $lock = $token !== '' ? $lockfactory->get_lock($token, 60) : false;
        if (!$lock) {
            throw new \moodle_exception('errorrestorebusy', 'repository_largefile');
        }
        try {
            $record = \repository_largefile\chunk_store::get_record($token);
            if (!$record || (int) $record->state !== \repository_largefile\chunk_store::STATE_COMPLETED) {
                throw new \moodle_exception('completeduploadgone', 'repository_largefile');
            }
            $srcpath = \repository_largefile\chunk_store::get_path_for_id($record->id);
            if (!$srcpath || !is_file($srcpath)) {
                throw new \moodle_exception('completeduploadnofile', 'repository_largefile');
            }
            if (import_policy::detect_type((string) $record->filename) !== import_policy::TYPE_BACKUP) {
                throw new \moodle_exception('errorrestorenotbackup', 'repository_largefile');
            }
            transfer_manager::set_filename((int) $transfer->id, (string) $record->filename);
            $stored = import_policy::store_imported_file(
                $userid,
                $srcpath,
                (string) $record->filename,
                import_policy::DEST_COURSEBACKUP,
                (int) $record->contextid,
                $courseid
            );
            // Remove the row via chunk_store::delete(), which re-attempts the source
            // unlink and keeps the row for the cleanup task if the bytes remain.
            \repository_largefile\chunk_store::delete((string) $record->id);
            return $stored;
        } finally {
            $lock->release();
        }
    }

    /**
     * Restore a completed chunked upload (.mbz) unattended into a new course in a
     * chosen category, with the site's default restore settings.
     *
     * Mirrors core's admin/cli/restore_backup.php. The backup is unpacked straight
     * from the staged upload into the backup temp directory — no copy into the file
     * pool first, which for a very large backup saves both time and its full size in
     * disk. The staged upload is removed only once the restore has succeeded, so a
     * failed restore can simply be tried again.
     *
     * @param \stdClass $transfer The transfer row.
     * @param array $payload Decoded payload; expects 'token' and 'categoryid'.
     * @return string The new course's id.
     * @throws \moodle_exception If the upload is gone, the operator lacks the rights,
     *         or the restore's prechecks fail.
     */
    private static function run_auto_restore(\stdClass $transfer, array $payload): string {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');

        $userid = (int) $transfer->userid;
        $token = (string) ($payload['token'] ?? '');
        $categoryid = (int) ($payload['categoryid'] ?? 0);
        $catcontext = $categoryid > 0 ? \context_coursecat::instance($categoryid, IGNORE_MISSING) : null;
        if (
            !$catcontext
                || !has_capability('repository/largefile:import', \context_system::instance(), $userid)
                || !has_capability('moodle/course:create', $catcontext, $userid)
                || !has_capability('moodle/restore:restorecourse', $catcontext, $userid)
        ) {
            throw new \moodle_exception('errornocategorycap', 'repository_largefile');
        }

        // Unpack under the same per-token lock the Transfers page actions take, so the
        // source cannot be moved or removed mid-read; the restore itself then works
        // from the unpacked copy and needs no lock.
        $lockfactory = \core\lock\lock_config::get_lock_factory('repository_largefile_bg');
        $lock = $token !== '' ? $lockfactory->get_lock($token, 60) : false;
        if (!$lock) {
            throw new \moodle_exception('errorrestorebusy', 'repository_largefile');
        }
        $backupdir = \restore_controller::get_tempdir_name(SITEID, $userid);
        $path = make_backup_temp_directory($backupdir);
        try {
            try {
                $record = \repository_largefile\chunk_store::get_record($token);
                if (!$record || (int) $record->state !== \repository_largefile\chunk_store::STATE_COMPLETED) {
                    throw new \moodle_exception('completeduploadgone', 'repository_largefile');
                }
                $srcpath = \repository_largefile\chunk_store::get_path_for_id($record->id);
                if (!$srcpath || !is_file($srcpath)) {
                    throw new \moodle_exception('completeduploadnofile', 'repository_largefile');
                }
                if (import_policy::detect_type((string) $record->filename) !== import_policy::TYPE_BACKUP) {
                    throw new \moodle_exception('errorrestorenotbackup', 'repository_largefile');
                }
                transfer_manager::set_filename((int) $transfer->id, (string) $record->filename);
                $packer = get_file_packer('application/vnd.moodle.backup');
                if (!$packer->extract_to_pathname($srcpath, $path)) {
                    throw new \moodle_exception('errorrestoreextract', 'repository_largefile');
                }
            } finally {
                $lock->release();
            }

            [$fullname, $shortname] = \restore_dbops::calculate_course_names(
                0,
                get_string('restoringcourse', 'backup'),
                get_string('restoringcourseshortname', 'backup')
            );
            $courseid = \restore_dbops::create_new_course($fullname, $shortname, $categoryid);
            $rc = null;
            try {
                $rc = new \restore_controller(
                    $backupdir,
                    $courseid,
                    \backup::INTERACTIVE_NO,
                    \backup::MODE_GENERAL,
                    $userid,
                    \backup::TARGET_NEW_COURSE
                );
                if ($rc->get_status() == \backup::STATUS_REQUIRE_CONV) {
                    $rc->convert();
                }
                if (!$rc->execute_precheck()) {
                    $results = $rc->get_precheck_results();
                    throw new \moodle_exception(
                        'errorrestoreprecheck',
                        'repository_largefile',
                        '',
                        implode('; ', $results['errors'] ?? [])
                    );
                }
                $rc->execute_plan();
                $isfullcourse = $rc->get_type() === \backup::TYPE_1COURSE;
                $info = $rc->get_info();
            } catch (\Throwable $e) {
                // Do not leave the empty placeholder course behind.
                delete_course($courseid, false);
                throw $e;
            } finally {
                if ($rc) {
                    $rc->destroy();
                }
            }

            // A course backup names the new course itself; an activity or section
            // backup does not, so name the course after the backup's original course
            // (as the core CLI restore does) instead of leaving the placeholder name.
            if (!$isfullcourse) {
                [$fullname, $shortname] = \restore_dbops::calculate_course_names(
                    0,
                    $info->original_course_fullname ?? get_string('restoretonewcourse', 'backup'),
                    $info->original_course_shortname ?? get_string('newcourse')
                );
                $DB->update_record('course', (object) [
                    'id' => $courseid,
                    'fullname' => $fullname,
                    'shortname' => $shortname,
                    'visible' => 1,
                ]);
            }
        } finally {
            // A successful plan removes its own temp directory; a failed one may not.
            if (is_dir($path)) {
                fulldelete($path);
            }
        }

        // The restore succeeded, so the staged upload has served its purpose.
        \repository_largefile\chunk_store::delete_in_state($token, \repository_largefile\chunk_store::STATE_COMPLETED);
        return (string) $courseid;
    }
}

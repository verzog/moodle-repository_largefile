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
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace repository_largefile\local;

/**
 * Executes a single queued transfer on the server.
 *
 * @package    repository_largefile
 * @copyright  2026 SCCA
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
            } else {
                throw new \moodle_exception('errortransferunknown', 'repository_largefile');
            }
            transfer_manager::mark_completed((int) $transfer->id, $result);
            \repository_largefile\event\transfer_completed::for_transfer($transfer, $result)->trigger();
        } catch (\Throwable $e) {
            transfer_manager::mark_failed((int) $transfer->id, $e->getMessage());
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
        $fetched = $fetcher->fetch($url, (int) ($CFG->maxbytes ?? 0));

        // No recorded choice means "auto": the policy routes to the kind's default
        // enabled destination (for a URL import, historically the large-file picker).
        $destination = (string) ($payload['destination'] ?? '');
        $contextid = (int) ($transfer->contextid ?: \context_system::instance()->id);
        return import_policy::store_imported_file(
            (int) $transfer->userid,
            $fetched['path'],
            $fetched['filename'],
            $destination,
            $contextid
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
        $result = share_client::import($peerid, $shareurl);

        // No recorded choice means "auto": the policy routes to the kind's default
        // enabled destination (for a peer share, historically the private backup area).
        $destination = (string) ($payload['destination'] ?? '');
        $contextid = (int) ($transfer->contextid ?: \context_system::instance()->id);
        $filename = import_policy::store_imported_file(
            (int) $transfer->userid,
            $result['path'],
            $result['filename'],
            $destination,
            $contextid
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

        // Encrypt straight from the staged stored file (no plaintext temp copy) and
        // report progress on the transfer row, throttled to at most once a second so
        // a long encryption stays observable without hammering the database.
        $lastupdate = 0;
        $onprogress = function (int $done, int $total) use ($transfer, &$lastupdate): void {
            $now = time();
            if ($total > 0 && $now !== $lastupdate) {
                $lastupdate = $now;
                transfer_manager::set_progress((int) $transfer->id, (int) floor($done * 100 / $total));
            }
        };
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
}

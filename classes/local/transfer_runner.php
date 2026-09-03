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
 * import is fetched (SSRF-aware) and staged into the owner's file picker; a share
 * import is fetched, decrypted and verified ({@see share_client}) and saved into
 * the owner's private files, ready to restore. All outcomes are recorded back on
 * the transfer row so the admin monitor and the owner can see what happened.
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
        transfer_manager::mark_running((int) $transfer->id);
        try {
            \core_php_time_limit::raise();
            raise_memory_limit(MEMORY_EXTRA);
            $payload = transfer_manager::payload($transfer);
            if ($transfer->type === transfer_manager::TYPE_URL) {
                $result = self::run_url_import($transfer, $payload);
            } else if ($transfer->type === transfer_manager::TYPE_SHARE) {
                $result = self::run_share_import($transfer, $payload);
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

        $contextid = (int) ($transfer->contextid ?: \context_system::instance()->id);
        $token = chunk_store::create_token_for((int) $transfer->userid, $contextid, -1);
        if (!chunk_store::adopt_file($token, $fetched['path'], $fetched['filename'])) {
            throw new \moodle_exception('errordownloadfailed', 'repository_largefile');
        }
        return $fetched['filename'];
    }

    /**
     * Fetch, decrypt and verify a peer's shared backup and save it to the owner's
     * private files.
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

        $fs = get_file_storage();
        $usercontext = \context_user::instance((int) $transfer->userid);
        $filename = $result['filename'];
        if ($fs->file_exists($usercontext->id, 'user', 'private', 0, '/', $filename)) {
            $filename = time() . '-' . $filename;
        }
        $fs->create_file_from_pathname([
            'contextid' => $usercontext->id,
            'component' => 'user',
            'filearea' => 'private',
            'itemid' => 0,
            'filepath' => '/',
            'filename' => $filename,
            'userid' => (int) $transfer->userid,
        ], $result['path']);
        @unlink($result['path']);
        return $filename;
    }
}

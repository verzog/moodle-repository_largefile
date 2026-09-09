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
 * Cleanup task for stale chunked-upload tokens and files.
 *
 * Derived from local_chunkupload (2020 Justus Dieckmann WWU).
 *
 * @package    repository_largefile
 * @copyright  2026 Vernon Spain
 * @copyright  2020 Justus Dieckmann WWU
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace repository_largefile\task;

use repository_largefile\chunk_store;

/**
 * Cleanup task for stale chunked-upload tokens and files.
 *
 * @package    repository_largefile
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class cleanup_chunks extends \core\task\scheduled_task {
    /**
     * Returns the name of the scheduled task.
     *
     * @return string
     */
    public function get_name() {
        return get_string('cleanup_task', 'repository_largefile');
    }

    /**
     * Removes old chunked-upload files and their tracking rows.
     *
     * @return void
     */
    public function execute() {
        // The retention durations are stored as repository type options (under the
        // bare type name), the same place type_config_form() saves them.
        $config = get_config('largefile');
        $state0duration = $config->state0duration ?? 3600;
        $state1duration = $config->state1duration ?? 3600;
        $state2duration = $config->state2duration ?? 86400;

        $this->purge(chunk_store::STATE_UNUSED, (int) $state0duration);
        $this->purge(chunk_store::STATE_STARTED, (int) $state1duration);
        $this->purge(chunk_store::STATE_COMPLETED, (int) $state2duration);
        $this->purge_orphaned_rows();
        $this->purge_export_files();
        $this->purge_expired_shares();
        $this->purge_old_nonces();
        $this->purge_orphaned_publish_sources();
        $this->purge_old_transfers();
    }

    /**
     * Drop any chunk row whose file has been externally removed — an operator's
     * manual rm on the chunk area, a filesystem snapshot restore, or a similar
     * out-of-band edit. Left behind, the row shows up in Completed uploads (or
     * Uploads in progress) as an entry that cannot be routed, restored or
     * resumed; sweeping it here means an admin never has to hunt down that state
     * from the UI. Deletion goes through the locked, state-guarded path so a
     * concurrent Send/Restore is not interleaved.
     *
     * @return void
     */
    private function purge_orphaned_rows(): void {
        global $DB;
        // Only STARTED and COMPLETED rows are expected to have a file on disk;
        // an UNUSED row is a token issued but not yet written, so a missing
        // file there is normal, not orphan state.
        [$insql, $params] = $DB->get_in_or_equal(
            [chunk_store::STATE_STARTED, chunk_store::STATE_COMPLETED],
            SQL_PARAMS_NAMED
        );
        $rows = $DB->get_records_select(chunk_store::TABLE, "state $insql", $params, '', 'id, state');
        foreach ($rows as $row) {
            $path = chunk_store::get_path_for_id((string) $row->id);
            if ($path !== null && !file_exists($path)) {
                chunk_store::delete_in_state((string) $row->id, (int) $row->state);
            }
        }
    }

    /**
     * Remove any staged publication source whose transfer has finished (completed,
     * failed or cancelled) or no longer exists. Success and cancellation delete the
     * source directly; this sweep is the backstop for a failed publish, so a large
     * plaintext backup is never left behind.
     *
     * @return void
     */
    private function purge_orphaned_publish_sources(): void {
        global $DB;
        $fs = get_file_storage();
        $sysid = \context_system::instance()->id;
        $finished = [
            \repository_largefile\local\transfer_manager::STATUS_COMPLETED,
            \repository_largefile\local\transfer_manager::STATUS_FAILED,
            \repository_largefile\local\transfer_manager::STATUS_CANCELLED,
        ];
        $rs = $DB->get_recordset_select(
            'files',
            "component = :component AND filearea = :filearea AND contextid = :ctx AND filename <> '.'",
            [
                'component' => 'repository_largefile',
                'filearea' => \repository_largefile\local\transfer_manager::PENDING_FILEAREA,
                'ctx' => $sysid,
            ],
            '',
            'id, itemid'
        );
        foreach ($rs as $filerec) {
            $status = $DB->get_field('repository_largefile_transfers', 'status', ['id' => $filerec->itemid]);
            if ($status === false || in_array($status, $finished, true)) {
                $file = $fs->get_file_by_id($filerec->id);
                if ($file) {
                    $file->delete();
                }
            }
        }
        $rs->close();
    }

    /**
     * Drop finished transfers (completed, failed or cancelled) once they are a
     * week old, so the monitor keeps a recent history without growing forever.
     *
     * @return void
     */
    private function purge_old_transfers(): void {
        \repository_largefile\local\transfer_manager::purge_old(time() - (7 * DAYSECS));
    }

    /**
     * Delete shares that have expired or reached their download limit, along with
     * their encrypted files.
     *
     * @return void
     */
    private function purge_expired_shares(): void {
        global $DB;
        $now = time();
        $sql = '(expires <> 0 AND expires < :now) OR (maxdownloads <> 0 AND downloadcount >= maxdownloads)';
        $ids = $DB->get_fieldset_select('repository_largefile_shares', 'id', $sql, ['now' => $now]);
        foreach ($ids as $id) {
            \repository_largefile\local\share_manager::delete((int) $id);
        }
    }

    /**
     * Drop spent request nonces older than the signing window; past it, a nonce
     * can never pass the timestamp check anyway, so re-use is already impossible.
     *
     * @return void
     */
    private function purge_old_nonces(): void {
        global $DB;
        $cutoff = time() - \repository_largefile\local\signer::nonce_retention();
        $DB->delete_records_select('repository_largefile_nonces', 'timecreated < :cutoff', ['cutoff' => $cutoff]);
    }

    /**
     * Remove the short-lived stored_file copies made for privacy exports. They
     * only need to survive the export request that created them, so anything more
     * than an hour old is stale and is deleted to reclaim the space.
     *
     * @return void
     */
    private function purge_export_files(): void {
        global $DB;
        $fs = get_file_storage();
        $rs = $DB->get_recordset_select(
            'files',
            "component = :component AND filearea = :filearea AND filename <> '.' AND timecreated < :cutoff",
            ['component' => 'repository_largefile', 'filearea' => 'privacyexport', 'cutoff' => time() - HOURSECS],
            '',
            'id'
        );
        foreach ($rs as $filerec) {
            $file = $fs->get_file_by_id($filerec->id);
            if ($file) {
                $file->delete();
            }
        }
        $rs->close();
    }

    /**
     * Delete every token in the given state last touched before the cutoff, plus
     * its partial file on disk.
     *
     * @param int $state The chunk_store state to purge.
     * @param int $maxage Maximum age in seconds before a row is removed.
     * @return void
     */
    private function purge(int $state, int $maxage): void {
        global $DB;
        $ids = $DB->get_fieldset_select(
            chunk_store::TABLE,
            'id',
            'lastmodified < :time AND state = :state',
            ['time' => time() - $maxage, 'state' => $state]
        );
        // Delete each via chunk_store's state-guarded, locked path: the same lock a
        // Send-to/Restore admin action takes, so cleanup cannot pull the file out
        // from under a routing operation whose completed source it would otherwise
        // race on. The state guard also means a row that has since moved on (an
        // upload that finished after the id was listed) is left alone. A row whose
        // file could not be unlinked is kept for the next run to retry.
        foreach ($ids as $id) {
            chunk_store::delete_in_state((string) $id, $state);
        }
    }
}

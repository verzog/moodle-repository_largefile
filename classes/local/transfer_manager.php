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
 * Data access for the queue of scheduled, unattended server-side transfers.
 *
 * A transfer is a piece of work the server can do on its own — fetch a file from
 * a URL, or fetch and decrypt a backup shared by a peer — so a user can queue it
 * (optionally for a quiet time, e.g. overnight) and walk away. The rows are
 * executed by {@see \repository_largefile\task\process_transfers}; this class
 * only reads and writes them, so it stays free of network and file I/O and is
 * unit-testable.
 *
 * @package    repository_largefile
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace repository_largefile\local;

/**
 * Data access for the scheduled-transfer queue.
 *
 * @package    repository_largefile
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class transfer_manager {
    /** @var string Database table backing the transfer queue. */
    public const TABLE = 'repository_largefile_transfers';

    /** @var string A server-side fetch of a file from a URL, staged for the picker. */
    public const TYPE_URL = 'urlimport';

    /** @var string A fetch-and-decrypt of a backup shared by a peer. */
    public const TYPE_SHARE = 'shareimport';

    /** @var string Queued, waiting for its scheduled time (or the next run). */
    public const STATUS_SCHEDULED = 'scheduled';

    /** @var string Being processed right now by the scheduled task. */
    public const STATUS_RUNNING = 'running';

    /** @var string Finished successfully. */
    public const STATUS_COMPLETED = 'completed';

    /** @var string Finished with an error. */
    public const STATUS_FAILED = 'failed';

    /** @var string Cancelled by a user before it ran. */
    public const STATUS_CANCELLED = 'cancelled';

    /** @var int Attempts after which a repeatedly interrupted transfer is failed. */
    public const MAX_ATTEMPTS = 3;

    /**
     * Queue a transfer.
     *
     * @param string $type One of the TYPE_* constants.
     * @param int $userid The user the transfer runs for and as.
     * @param array $payload Type-specific parameters (persisted as JSON).
     * @param int $scheduledtime Earliest time to run, or 0 for as soon as possible.
     * @param int $contextid Context the transfer was created in, or 0.
     * @param string $filename Best-known target file name, for display.
     * @return int The new transfer's id.
     */
    public static function create(
        string $type,
        int $userid,
        array $payload,
        int $scheduledtime = 0,
        int $contextid = 0,
        string $filename = ''
    ): int {
        global $DB;
        $record = (object) [
            'type' => $type,
            'userid' => $userid,
            'contextid' => $contextid ?: null,
            'filename' => $filename !== '' ? $filename : null,
            'payload' => json_encode($payload),
            'status' => self::STATUS_SCHEDULED,
            // An immediate transfer's effective due time is now, so it is ordered
            // chronologically against explicitly scheduled ones rather than always
            // jumping the queue (which a steady stream of immediates could otherwise
            // use to starve an overnight transfer). "Run now" is recognised for
            // display by scheduledtime being no later than timecreated.
            'scheduledtime' => $scheduledtime > 0 ? $scheduledtime : time(),
            'attempts' => 0,
            'timecreated' => time(),
        ];
        return (int) $DB->insert_record(self::TABLE, $record);
    }

    /**
     * Fetch one transfer row.
     *
     * @param int $id The transfer id.
     * @return \stdClass|null The row, or null if it does not exist.
     */
    public static function get(int $id): ?\stdClass {
        global $DB;
        $record = $DB->get_record(self::TABLE, ['id' => $id], '*', IGNORE_MISSING);
        return $record ?: null;
    }

    /**
     * Decode a transfer's JSON payload.
     *
     * @param \stdClass $transfer The transfer row.
     * @return array The decoded payload, or an empty array if unreadable.
     */
    public static function payload(\stdClass $transfer): array {
        $data = json_decode((string) $transfer->payload, true);
        return is_array($data) ? $data : [];
    }

    /**
     * Scheduled transfers whose time has come, oldest scheduled first.
     *
     * The scheduled task holds a lock while it runs, so a plain read-then-mark is
     * enough — two runs cannot process the same row concurrently.
     *
     * @param int $now The current time.
     * @param int $limit Maximum rows to return (0 for no limit).
     * @return array The due transfer rows.
     */
    public static function get_due(int $now, int $limit = 0): array {
        global $DB;
        return $DB->get_records_select(
            self::TABLE,
            "status = :status AND scheduledtime <= :now",
            ['status' => self::STATUS_SCHEDULED, 'now' => $now],
            'scheduledtime ASC, id ASC',
            '*',
            0,
            $limit
        );
    }

    /**
     * Atomically claim a scheduled transfer for running, counting the attempt.
     *
     * The scheduled-to-running move is guarded on the row still being scheduled,
     * so a transfer an administrator cancelled (or another run already took) while
     * an earlier one in the same batch was downloading is not resurrected: the
     * guarded update matches nothing and the claim fails, and the caller then skips
     * the stale work.
     *
     * @param int $id The transfer id.
     * @return bool True if this call claimed the transfer; false if it was no
     *              longer scheduled (cancelled, already running, or gone).
     */
    public static function claim(int $id): bool {
        global $DB;
        $transaction = $DB->start_delegated_transaction();
        // Lock the row while still scheduled; a concurrent cancel blocks until we
        // commit and then sees it running, so it cannot cancel work already taken,
        // and a second claimer finds nothing to lock and gives up.
        $sql = "SELECT * FROM {" . self::TABLE . "} WHERE id = :id AND status = :status FOR UPDATE";
        $transfer = $DB->get_record_sql($sql, ['id' => $id, 'status' => self::STATUS_SCHEDULED], IGNORE_MISSING);
        if (!$transfer) {
            $transaction->allow_commit();
            return false;
        }
        $DB->update_record(self::TABLE, (object) [
            'id' => $id,
            'status' => self::STATUS_RUNNING,
            'timestarted' => time(),
            'attempts' => (int) $transfer->attempts + 1,
        ]);
        $transaction->allow_commit();
        return true;
    }

    /**
     * Return transfers stuck in the running state past a lease to the queue.
     *
     * A run interrupted by a worker restart or host shutdown leaves its row
     * running forever; nothing else would ever pick it up. Past the lease, such a
     * row is rescheduled to be retried, or failed once it has used up its attempts.
     *
     * @param int $before Reclaim running rows whose timestarted is before this.
     * @return void
     */
    public static function reclaim_stale(int $before): void {
        global $DB;
        $rows = $DB->get_records_select(
            self::TABLE,
            "status = :running AND timestarted IS NOT NULL AND timestarted < :before",
            ['running' => self::STATUS_RUNNING, 'before' => $before]
        );
        foreach ($rows as $row) {
            if ((int) $row->attempts >= self::MAX_ATTEMPTS) {
                self::mark_failed((int) $row->id, get_string('errortransferstalled', 'repository_largefile'));
            } else {
                $DB->set_field(self::TABLE, 'status', self::STATUS_SCHEDULED, ['id' => $row->id]);
            }
        }
    }

    /**
     * Mark a transfer completed, recording where its result landed.
     *
     * @param int $id The transfer id.
     * @param string $result A short description of the result (e.g. a file name).
     * @return void
     */
    public static function mark_completed(int $id, string $result): void {
        global $DB;
        $DB->update_record(self::TABLE, (object) [
            'id' => $id,
            'status' => self::STATUS_COMPLETED,
            'result' => $result,
            'error' => null,
            'timecompleted' => time(),
        ]);
    }

    /**
     * Mark a transfer failed, recording the error.
     *
     * @param int $id The transfer id.
     * @param string $error The failure message.
     * @return void
     */
    public static function mark_failed(int $id, string $error): void {
        global $DB;
        $DB->update_record(self::TABLE, (object) [
            'id' => $id,
            'status' => self::STATUS_FAILED,
            'error' => $error,
            'timecompleted' => time(),
        ]);
    }

    /**
     * Cancel a transfer that has not started yet.
     *
     * @param int $id The transfer id.
     * @return bool True if it was cancelled; false if it was already running or done.
     */
    public static function cancel(int $id): bool {
        global $DB;
        $transfer = self::get($id);
        if (!$transfer || $transfer->status !== self::STATUS_SCHEDULED) {
            return false;
        }
        $DB->update_record(self::TABLE, (object) [
            'id' => $id,
            'status' => self::STATUS_CANCELLED,
            'timecompleted' => time(),
        ]);
        return true;
    }

    /**
     * Delete a transfer row.
     *
     * @param int $id The transfer id.
     * @return void
     */
    public static function delete(int $id): void {
        global $DB;
        $DB->delete_records(self::TABLE, ['id' => $id]);
    }

    /**
     * All transfers, newest first, each with the owning user's name for display.
     *
     * @param int $limit Maximum rows to return (0 for no limit).
     * @return array The transfer rows, with an extra `username` field.
     */
    public static function list_all(int $limit = 0): array {
        global $DB;
        // The get_sql() selects fragment is a bare, comma-separated field list
        // (no leading comma), so join it to t.* with our own comma.
        $namefields = \core_user\fields::for_name()->get_sql('u', false, '', '', false)->selects;
        $sql = "SELECT t.*, {$namefields}
                  FROM {" . self::TABLE . "} t
                  JOIN {user} u ON u.id = t.userid
              ORDER BY t.timecreated DESC, t.id DESC";
        $rows = $DB->get_records_sql($sql, [], 0, $limit);
        foreach ($rows as $row) {
            $row->username = fullname($row);
        }
        return $rows;
    }

    /**
     * A user's own transfers, newest first.
     *
     * @param int $userid The owning user's id.
     * @return array The transfer rows.
     */
    public static function list_for_user(int $userid): array {
        global $DB;
        return $DB->get_records(self::TABLE, ['userid' => $userid], 'timecreated DESC, id DESC');
    }

    /**
     * Remove finished transfers (completed, failed or cancelled) older than a time.
     *
     * @param int $before Remove finished rows whose timecompleted is before this.
     * @return void
     */
    public static function purge_old(int $before): void {
        global $DB;
        $finished = [self::STATUS_COMPLETED, self::STATUS_FAILED, self::STATUS_CANCELLED];
        [$insql, $params] = $DB->get_in_or_equal($finished, SQL_PARAMS_NAMED);
        $params['before'] = $before;
        $DB->delete_records_select(
            self::TABLE,
            "status $insql AND timecompleted IS NOT NULL AND timecompleted < :before",
            $params
        );
    }
}

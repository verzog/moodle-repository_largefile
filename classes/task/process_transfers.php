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
 * Scheduled task that runs due queued transfers, unattended.
 *
 * @package    repository_largefile
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace repository_largefile\task;

use repository_largefile\local\transfer_manager;
use repository_largefile\local\transfer_runner;

/**
 * Runs due queued transfers.
 *
 * @package    repository_largefile
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class process_transfers extends \core\task\scheduled_task {
    /** @var int Most transfers to run in a single pass, so one run cannot dominate cron. */
    private const BATCH = 10;

    /**
     * @var int Seconds a transfer may stay running before it is treated as interrupted.
     * A scheduled task holds its lock for the whole run, so a live worker (however
     * slow) is never reclaimed mid-encryption — only a run whose worker actually died
     * releases the lock and lets a later run reclaim the row, so a modest lease
     * recovers a dead job within about an hour rather than leaving it stuck for most
     * of a day.
     */
    /** @var int Sanity floor for the reclaim lease: shorter than this would risk
     *   reclaiming a peer download that is still legitimately running. */
    private const MIN_LEASE = HOURSECS;

    /** @var int Sanity ceiling: past this a died worker takes far too long to be
     *   returned to the queue. Well within the 7-day retention window. */
    private const MAX_LEASE = 12 * HOURSECS;

    /** @var int Default reclaim lease when the admin setting is unset (6 hours). */
    private const DEFAULT_LEASE = 6 * HOURSECS;

    /**
     * The reclaim lease: a running transfer whose timestarted is older than this is
     * treated as a died worker and returned to the queue. Read from the plugin's
     * admin setting, bounded to a sane range so a misconfigured value can neither
     * reclaim legitimately running peer downloads nor let a truly died worker sit
     * for days.
     *
     * @return int Seconds.
     */
    private static function lease_seconds(): int {
        $seconds = (int) get_config('largefile', 'transferlease');
        if ($seconds < self::MIN_LEASE) {
            $seconds = self::DEFAULT_LEASE;
        }
        return min($seconds, self::MAX_LEASE);
    }

    /**
     * Task name shown in the admin task list.
     *
     * @return string The task name.
     */
    public function get_name(): string {
        return get_string('task:processtransfers', 'repository_largefile');
    }

    /**
     * Execute every due transfer, up to the per-run batch size.
     *
     * @return void
     */
    public function execute(): void {
        // Return any transfer left running by an interrupted earlier run to the
        // queue before picking up new work.
        transfer_manager::reclaim_stale(time() - self::lease_seconds());
        $due = transfer_manager::get_due(time(), self::BATCH);
        foreach ($due as $transfer) {
            transfer_runner::run($transfer);
        }
    }
}

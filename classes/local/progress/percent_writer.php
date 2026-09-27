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
 * Writes a job's progress onto its queued transfer as a percentage.
 *
 * @package    repository_largefile
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace repository_largefile\local\progress;

use repository_largefile\local\transfer_manager;

/**
 * Maps a proportion (0 to 1) of one stage of a job into that stage's slice of the
 * transfer's percentage, and records it — throttled, and only ever forwards.
 *
 * @package    repository_largefile
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class percent_writer {
    /** @var int The transfer to record progress on. */
    private int $transferid;

    /** @var int The transfer percentage this stage starts at. */
    private int $from;

    /** @var int The transfer percentage this stage ends at. */
    private int $to;

    /** @var int Minimum seconds between database writes. */
    private int $interval;

    /** @var int When the last write happened (0 for never). */
    private int $lastwrite = 0;

    /** @var int The highest percentage recorded so far. */
    private int $highest;

    /**
     * Create the writer.
     *
     * @param int $transferid The transfer to record progress on.
     * @param int $from The transfer percentage this stage starts at.
     * @param int $to The transfer percentage this stage ends at.
     * @param int $interval Minimum seconds between database writes.
     */
    public function __construct(int $transferid, int $from, int $to, int $interval = 5) {
        $this->transferid = $transferid;
        $this->from = max(0, min(100, $from));
        $this->to = max($this->from, min(100, $to));
        $this->interval = max(0, $interval);
        $this->highest = $this->from;
    }

    /**
     * Record the stage's progress, unless it was recorded moments ago.
     *
     * @param float $proportion How far through the stage (0 to 1).
     * @param bool $final True when the stage has finished, to write it regardless.
     * @return void
     */
    public function write(float $proportion, bool $final = false): void {
        $percent = $this->from + (int) floor(($this->to - $this->from) * max(0.0, min(1.0, $proportion)));
        if ($percent <= $this->highest && !$final) {
            return;
        }
        $percent = max($percent, $this->highest);
        $now = time();
        if (!$final && $this->lastwrite && $now - $this->lastwrite < $this->interval) {
            return;
        }
        $this->highest = $percent;
        $this->lastwrite = $now;
        transfer_manager::set_progress($this->transferid, $percent);
    }
}

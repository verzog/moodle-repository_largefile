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
 * Reports a restore's progress onto its queued transfer, for the Transfers page.
 *
 * @package    repository_largefile
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace repository_largefile\local\progress;

/**
 * A progress reporter for a restore controller that records the restore's
 * progress as a percentage on the transfer running it.
 *
 * The restore's own progress (0 to 1) is mapped into a slice of the transfer's
 * percentage — for an automatic restore, the part after the backup is unpacked —
 * and written at most every few seconds, so a restore that reports thousands of
 * small steps does not hammer the database. The percentage only ever moves
 * forward, so an indeterminate section cannot make it jump back.
 *
 * @package    repository_largefile
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class restore_progress extends \core\progress\base {
    /** @var percent_writer Writes the mapped percentage onto the transfer. */
    private percent_writer $writer;

    /**
     * Create the reporter.
     *
     * @param int $transferid The transfer the restore belongs to.
     * @param int $from The transfer percentage the restore starts at.
     * @param int $to The transfer percentage the restore ends at.
     * @param int $interval Minimum seconds between database writes.
     */
    public function __construct(int $transferid, int $from, int $to, int $interval = 5) {
        $this->writer = new percent_writer($transferid, $from, $to, $interval);
    }

    /**
     * Record the current progress (called by the progress base class).
     *
     * @return void
     */
    public function update_progress() {
        [$min] = $this->get_progress_proportion_range();
        $this->writer->write((float) $min, !$this->is_in_progress_section());
    }
}

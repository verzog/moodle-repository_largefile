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
 * Reports a backup's unpacking progress onto its queued transfer.
 *
 * @package    repository_largefile
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace repository_largefile\local\progress;

defined('MOODLE_INTERNAL') || die();

require_once($GLOBALS['CFG']->libdir . '/filestorage/file_progress.php');

/**
 * A file-packer progress callback that records how far unpacking a backup has got
 * as a percentage on the transfer doing it. Unpacking a very large backup takes a
 * while, so it gets its own slice of the transfer's percentage before the restore.
 *
 * @package    repository_largefile
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class extract_progress implements \file_progress {
    /** @var percent_writer Writes the mapped percentage onto the transfer. */
    private percent_writer $writer;

    /**
     * Create the callback.
     *
     * @param int $transferid The transfer doing the unpacking.
     * @param int $from The transfer percentage unpacking starts at.
     * @param int $to The transfer percentage unpacking ends at.
     * @param int $interval Minimum seconds between database writes.
     */
    public function __construct(int $transferid, int $from, int $to, int $interval = 5) {
        $this->writer = new percent_writer($transferid, $from, $to, $interval);
    }

    /**
     * Called by the file packer as it unpacks.
     *
     * @param int $progress Units done, or file_progress::INDETERMINATE.
     * @param int $max Units in all, or file_progress::INDETERMINATE.
     * @return void
     */
    public function progress($progress = self::INDETERMINATE, $max = self::INDETERMINATE) {
        if ($progress === self::INDETERMINATE || $max === self::INDETERMINATE || $max <= 0) {
            return;
        }
        $this->writer->write(min(1.0, max(0.0, $progress / $max)), $progress >= $max);
    }
}

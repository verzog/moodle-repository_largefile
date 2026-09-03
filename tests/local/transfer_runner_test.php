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
 * Tests for the transfer runner's outcome handling.
 *
 * @package    repository_largefile
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace repository_largefile\local;

/**
 * Tests for {@see transfer_runner}.
 *
 * @package    repository_largefile
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \repository_largefile\local\transfer_runner
 */
final class transfer_runner_test extends \advanced_testcase {
    /**
     * A transfer of an unrecognised type is marked failed rather than throwing.
     *
     * @return void
     */
    public function test_unknown_type_marks_failed(): void {
        $this->resetAfterTest(true);
        $id = transfer_manager::create('bogus', 1, []);
        transfer_runner::run(transfer_manager::get($id));
        $transfer = transfer_manager::get($id);
        $this->assertSame(transfer_manager::STATUS_FAILED, $transfer->status);
        $this->assertEquals(1, $transfer->attempts);
        $this->assertNotEmpty($transfer->error);
    }

    /**
     * A URL import whose URL is not a valid http(s) link fails cleanly, without a
     * network attempt, and leaves no staged file behind.
     *
     * @return void
     */
    public function test_url_import_rejects_unfetchable(): void {
        global $DB;
        $this->resetAfterTest(true);
        $id = transfer_manager::create(
            transfer_manager::TYPE_URL,
            1,
            ['url' => 'ftp://internal.example/secret'],
            0,
            \context_system::instance()->id
        );
        transfer_runner::run(transfer_manager::get($id));
        $this->assertSame(transfer_manager::STATUS_FAILED, transfer_manager::get($id)->status);
        $this->assertSame(0, $DB->count_records('repository_largefile_chunks'));
    }
}

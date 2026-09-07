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

namespace repository_largefile\local;

use repository_largefile\chunk_store;

/**
 * Tests for the running-progress readout.
 *
 * @package    repository_largefile
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \repository_largefile\local\manage_page
 */
final class manage_page_test extends \advanced_testcase {
    /**
     * A running publish with a known size reports percent, throughput and an ETA.
     *
     * @return void
     */
    public function test_running_progress_shows_rate_and_eta(): void {
        global $DB;
        $this->resetAfterTest(true);
        // 100 MiB backup, half done over 10 seconds ~= 5 MB/s.
        $id = transfer_manager::create(
            transfer_manager::TYPE_PUBLISH,
            1,
            ['peerid' => 1, 'filesize' => 100 * 1024 * 1024],
            0,
            \context_system::instance()->id,
            'backup.mbz'
        );
        transfer_manager::claim($id);
        $DB->set_field(transfer_manager::TABLE, 'timestarted', time() - 10, ['id' => $id]);
        transfer_manager::set_progress($id, 50);

        $summary = manage_page::running_progress(transfer_manager::get($id));

        $this->assertStringContainsString('50%', $summary);
        $this->assertStringContainsString('/s', $summary);
        $this->assertStringContainsString('left', $summary);
        $this->assertStringContainsString('running for', $summary);
    }

    /**
     * A run whose percent has not advanced for far longer than its own average step
     * is flagged as stalled — no speed or ETA, so "stuck" reads differently from
     * "slow" at a glance.
     *
     * @return void
     */
    public function test_running_progress_flags_a_stall(): void {
        global $DB;
        $this->resetAfterTest(true);
        $id = transfer_manager::create(
            transfer_manager::TYPE_PUBLISH,
            1,
            ['peerid' => 1, 'filesize' => 100 * 1024 * 1024],
            0,
            \context_system::instance()->id,
            'backup.mbz'
        );
        transfer_manager::claim($id);
        // Started 10 minutes ago, reached 50%, but has not advanced for 5 minutes.
        $DB->set_field(transfer_manager::TABLE, 'timestarted', time() - 600, ['id' => $id]);
        transfer_manager::set_progress($id, 50);
        $DB->set_field(transfer_manager::TABLE, 'progressupdated', time() - 300, ['id' => $id]);

        $summary = manage_page::running_progress(transfer_manager::get($id));

        $this->assertStringContainsString('50%', $summary);
        $this->assertStringContainsString('no progress for', $summary);
        $this->assertStringNotContainsString('/s', $summary);
    }

    /**
     * Without a recorded size (or before any progress) only the percent shows, so
     * the readout never divides by zero or invents a rate.
     *
     * @return void
     */
    public function test_running_progress_without_size_is_just_percent(): void {
        $this->resetAfterTest(true);
        $id = transfer_manager::create(
            transfer_manager::TYPE_PUBLISH,
            1,
            ['peerid' => 1],
            0,
            \context_system::instance()->id,
            'backup.mbz'
        );
        transfer_manager::claim($id);

        $summary = manage_page::running_progress(transfer_manager::get($id));

        $this->assertStringContainsString('0%', $summary);
        $this->assertStringNotContainsString('/s', $summary);
    }

    /**
     * The uploads-in-progress region shows the empty-state notice with no uploads,
     * and lists an active upload with its file, mode and percent — the exact markup
     * the Transfers page and its live-refresh endpoint both emit.
     *
     * @return void
     */
    public function test_active_uploads_html(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();

        // No uploads in progress: the empty-state notice.
        $this->assertStringContainsString(
            get_string('nouploadsinprogress', 'repository_largefile'),
            manage_page::active_uploads_html()
        );

        // A Background Fetch upload, half received and still in progress.
        $id = chunk_store::create_token(\context_system::instance()->id, -1);
        chunk_store::begin_random(chunk_store::get_record($id), 4000, 'lecture.mp4');
        chunk_store::write_range(chunk_store::get_record($id), 0, 2000, random_bytes(2000));

        $html = manage_page::active_uploads_html();
        $this->assertStringContainsString('lecture.mp4', $html);
        $this->assertStringContainsString(get_string('uploadmodebackground', 'repository_largefile'), $html);
        $this->assertStringContainsString('50%', $html);
    }

    /**
     * An upload one chunk short is floored, never rounded up: it must read short of
     * 100% (a completed upload leaves this table, so "100%" here would be a lie).
     *
     * @return void
     */
    public function test_active_uploads_html_floors_progress(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();

        // 9990 of 10000 bytes received = 99.9%.
        $id = chunk_store::create_token(\context_system::instance()->id, -1);
        chunk_store::begin_random(chunk_store::get_record($id), 10000, 'nearly.mp4');
        chunk_store::write_range(chunk_store::get_record($id), 0, 9990, random_bytes(9990));

        $html = manage_page::active_uploads_html();
        $this->assertStringContainsString('99%', $html);
        $this->assertStringNotContainsString('100%', $html);
    }
}

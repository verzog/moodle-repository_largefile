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
 * @copyright  2026 Vernon Spain
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
     * At 100% the encryption is done and the encrypted file is being stored, a step
     * that reports no progress: the readout says so rather than flagging a stall or
     * inventing a rate and ETA.
     *
     * @return void
     */
    public function test_running_progress_reports_storing_at_100_percent(): void {
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
        $DB->set_field(transfer_manager::TABLE, 'timestarted', time() - 600, ['id' => $id]);
        transfer_manager::set_progress($id, 100);
        $DB->set_field(transfer_manager::TABLE, 'progressupdated', time() - 300, ['id' => $id]);

        $summary = manage_page::running_progress(transfer_manager::get($id));

        $this->assertStringContainsString('100%', $summary);
        $this->assertStringContainsString(get_string('transferstoring', 'repository_largefile'), $summary);
        $this->assertStringNotContainsString('no progress for', $summary);
        $this->assertStringNotContainsString('/s', $summary);
        $this->assertStringContainsString('running for', $summary);
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
        // Each row offers a Remove action targeting that upload's token.
        $this->assertStringContainsString('action=removeupload', $html);
        $this->assertStringContainsString('uploadid=' . $id, $html);
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

    /**
     * The Connection cell reads "Not checked yet", "Connected · checked … ago" or
     * "Failed · checked … ago — reason" from the recorded last check.
     *
     * @return void
     */
    public function test_peer_check_html(): void {
        $never = (object) ['lastcheck' => null, 'lastcheckok' => null, 'lastcheckmessage' => null];
        $nevertext = get_string('peerchecknever', 'repository_largefile');
        $this->assertStringContainsString($nevertext, manage_page::peer_check_html($never));

        $ok = (object) ['lastcheck' => time() - 60, 'lastcheckok' => 1, 'lastcheckmessage' => 'Connected.'];
        $html = manage_page::peer_check_html($ok);
        $this->assertStringContainsString('bg-success', $html);
        $this->assertStringContainsString('checked', $html);

        $failed = (object) ['lastcheck' => time() - 60, 'lastcheckok' => 0, 'lastcheckmessage' => 'Could not <b>connect</b>'];
        $html = manage_page::peer_check_html($failed);
        $this->assertStringContainsString('bg-danger', $html);
        $this->assertStringContainsString('Could not &lt;b&gt;connect', $html);
    }

    /**
     * Each transfer status renders as a badge with its own colour class and label, and
     * an unknown status degrades to a neutral badge rather than an error.
     *
     * @return void
     */
    public function test_transfer_status_badge(): void {
        $running = manage_page::transfer_status_badge(transfer_manager::STATUS_RUNNING);
        $this->assertStringContainsString('badge', $running);
        $this->assertStringContainsString('bg-primary', $running);
        $this->assertStringContainsString(get_string('transferstatus_running', 'repository_largefile'), $running);
        $this->assertStringContainsString('bg-success', manage_page::transfer_status_badge(transfer_manager::STATUS_COMPLETED));
        $this->assertStringContainsString('bg-danger', manage_page::transfer_status_badge(transfer_manager::STATUS_FAILED));
        $this->assertStringContainsString('bg-secondary', manage_page::transfer_status_badge(transfer_manager::STATUS_SCHEDULED));
        $this->assertStringContainsString('bg-dark', manage_page::transfer_status_badge(transfer_manager::STATUS_CANCELLED));
        $unknown = manage_page::transfer_status_badge('weird');
        $this->assertStringContainsString('bg-secondary', $unknown);
        $this->assertStringContainsString('weird', $unknown);
    }

    /**
     * The Transfers table names what each row is moving: the recorded file name when
     * known, otherwise the share's host or the URL's file name and host, muted.
     *
     * @return void
     */
    public function test_transfer_file_label(): void {
        $this->resetAfterTest();
        $system = \context_system::instance()->id;

        $share = transfer_manager::create(
            transfer_manager::TYPE_SHARE,
            1,
            ['peerid' => 1, 'shareurl' => 'https://learn.example.org/repository/largefile/share.php?token=abc'],
            0,
            $system
        );
        $label = manage_page::transfer_file_label(transfer_manager::get($share));
        $this->assertStringContainsString('learn.example.org', $label);
        $this->assertStringContainsString('text-muted', $label);
        $this->assertStringNotContainsString('token=abc', $label);

        $url = transfer_manager::create(
            transfer_manager::TYPE_URL,
            1,
            ['url' => 'https://files.example.org/backups/course%20one.mbz?sig=1'],
            0,
            $system
        );
        $label = manage_page::transfer_file_label(transfer_manager::get($url));
        $this->assertStringContainsString('course one.mbz', $label);
        $this->assertStringContainsString('files.example.org', $label);
        $this->assertStringNotContainsString('sig=1', $label);

        // Once the runner records the real name, that is shown instead.
        transfer_manager::set_filename($share, 'backup-moodle2-course-1.mbz');
        $this->assertSame('backup-moodle2-course-1.mbz', manage_page::transfer_file_label(transfer_manager::get($share)));

        // A row with neither a name nor a recognisable source shows a dash, never blank.
        $bare = transfer_manager::create(transfer_manager::TYPE_SHARE, 1, [], 0, $system);
        $this->assertSame('—', manage_page::transfer_file_label(transfer_manager::get($bare)));
    }

    /**
     * The completed-uploads table shows the empty-state notice with none, and lists a
     * completed staged upload with its file and a Remove action targeting it.
     *
     * @return void
     */
    public function test_completed_uploads_html(): void {
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $baseurl = new \moodle_url('/repository/largefile/transfers.php');

        // None yet: the empty-state notice.
        $this->assertStringContainsString(
            get_string('nocompleteduploads', 'repository_largefile'),
            manage_page::completed_uploads_html($baseurl)
        );

        // A completed (staged, unselected) upload is listed with a Remove action.
        $id = chunk_store::create_token(\context_system::instance()->id, -1);
        $rec = chunk_store::get_record($id);
        $this->assertNull(chunk_store::apply_start($rec, 0, 400, 400, 'staged.mbz', random_bytes(400)));
        $this->assertTrue(chunk_store::is_complete($id));

        $html = manage_page::completed_uploads_html($baseurl);
        $this->assertStringContainsString('staged.mbz', $html);
        $this->assertStringContainsString('action=removecompleted', $html);
        $this->assertStringContainsString('uploadid=' . $id, $html);
    }
}

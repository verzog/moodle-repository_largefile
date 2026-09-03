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
 * Tests for the scheduled-transfer queue data access.
 *
 * @package    repository_largefile
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace repository_largefile\local;

/**
 * Tests for {@see transfer_manager}.
 *
 * @package    repository_largefile
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \repository_largefile\local\transfer_manager
 */
final class transfer_manager_test extends \advanced_testcase {
    /**
     * Create stores the row and payload, and reads back cleanly.
     *
     * @return void
     */
    public function test_create_and_get(): void {
        $this->resetAfterTest(true);
        $id = transfer_manager::create(
            transfer_manager::TYPE_URL,
            42,
            ['url' => 'https://example.com/a.imscc'],
            0,
            123,
            'a.imscc'
        );
        $transfer = transfer_manager::get($id);
        $this->assertNotNull($transfer);
        $this->assertSame(transfer_manager::TYPE_URL, $transfer->type);
        $this->assertEquals(42, $transfer->userid);
        $this->assertSame(transfer_manager::STATUS_SCHEDULED, $transfer->status);
        $this->assertSame(['url' => 'https://example.com/a.imscc'], transfer_manager::payload($transfer));
    }

    /**
     * get_due returns only scheduled rows whose time has come, oldest first.
     *
     * @return void
     */
    public function test_get_due(): void {
        $this->resetAfterTest(true);
        $now = time();
        $soon = transfer_manager::create(transfer_manager::TYPE_URL, 1, ['url' => 'https://e/1'], $now - 10);
        $later = transfer_manager::create(transfer_manager::TYPE_URL, 1, ['url' => 'https://e/2'], $now + 1000);
        $asap = transfer_manager::create(transfer_manager::TYPE_URL, 1, ['url' => 'https://e/3'], 0);

        $due = transfer_manager::get_due($now);
        $ids = array_keys($due);
        $this->assertContains($soon, $ids);
        $this->assertContains($asap, $ids);
        $this->assertNotContains($later, $ids);

        // A running transfer is not due even if its time passed.
        transfer_manager::mark_running($soon);
        $due = transfer_manager::get_due($now);
        $this->assertArrayNotHasKey($soon, $due);
    }

    /**
     * Running, completing and failing move the row through its lifecycle.
     *
     * @return void
     */
    public function test_lifecycle(): void {
        $this->resetAfterTest(true);
        $id = transfer_manager::create(transfer_manager::TYPE_SHARE, 5, ['peerid' => 1, 'shareurl' => 'https://p/s?token=x']);

        transfer_manager::mark_running($id);
        $transfer = transfer_manager::get($id);
        $this->assertSame(transfer_manager::STATUS_RUNNING, $transfer->status);
        $this->assertEquals(1, $transfer->attempts);
        $this->assertNotEmpty($transfer->timestarted);

        transfer_manager::mark_completed($id, 'backup.mbz');
        $transfer = transfer_manager::get($id);
        $this->assertSame(transfer_manager::STATUS_COMPLETED, $transfer->status);
        $this->assertSame('backup.mbz', $transfer->result);
        $this->assertNotEmpty($transfer->timecompleted);

        $failid = transfer_manager::create(transfer_manager::TYPE_URL, 5, ['url' => 'https://e/x']);
        transfer_manager::mark_running($failid);
        transfer_manager::mark_failed($failid, 'boom');
        $this->assertSame(transfer_manager::STATUS_FAILED, transfer_manager::get($failid)->status);
        $this->assertSame('boom', transfer_manager::get($failid)->error);
    }

    /**
     * Only a not-yet-started transfer can be cancelled.
     *
     * @return void
     */
    public function test_cancel(): void {
        $this->resetAfterTest(true);
        $id = transfer_manager::create(transfer_manager::TYPE_URL, 7, ['url' => 'https://e/y']);
        $this->assertTrue(transfer_manager::cancel($id));
        $this->assertSame(transfer_manager::STATUS_CANCELLED, transfer_manager::get($id)->status);

        $running = transfer_manager::create(transfer_manager::TYPE_URL, 7, ['url' => 'https://e/z']);
        transfer_manager::mark_running($running);
        $this->assertFalse(transfer_manager::cancel($running));
        $this->assertSame(transfer_manager::STATUS_RUNNING, transfer_manager::get($running)->status);
    }

    /**
     * purge_old drops finished rows past the cut-off but keeps live and recent ones.
     *
     * @return void
     */
    public function test_purge_old(): void {
        global $DB;
        $this->resetAfterTest(true);

        $old = transfer_manager::create(transfer_manager::TYPE_URL, 1, ['url' => 'https://e/old']);
        transfer_manager::mark_completed($old, 'x');
        $DB->set_field(transfer_manager::TABLE, 'timecompleted', time() - 1000, ['id' => $old]);

        $recent = transfer_manager::create(transfer_manager::TYPE_URL, 1, ['url' => 'https://e/recent']);
        transfer_manager::mark_completed($recent, 'y');

        $pending = transfer_manager::create(transfer_manager::TYPE_URL, 1, ['url' => 'https://e/pending']);

        transfer_manager::purge_old(time() - 500);

        $this->assertNull(transfer_manager::get($old));
        $this->assertNotNull(transfer_manager::get($recent));
        $this->assertNotNull(transfer_manager::get($pending));
    }

    /**
     * A user's transfers are listed newest first.
     *
     * @return void
     */
    public function test_list_for_user(): void {
        $this->resetAfterTest(true);
        transfer_manager::create(transfer_manager::TYPE_URL, 9, ['url' => 'https://e/1']);
        transfer_manager::create(transfer_manager::TYPE_URL, 9, ['url' => 'https://e/2']);
        transfer_manager::create(transfer_manager::TYPE_URL, 10, ['url' => 'https://e/3']);

        $this->assertCount(2, transfer_manager::list_for_user(9));
        $this->assertCount(1, transfer_manager::list_for_user(10));
    }

    /**
     * list_all returns every transfer with the owning user's display name.
     *
     * @return void
     */
    public function test_list_all(): void {
        $this->resetAfterTest(true);
        $user = $this->getDataGenerator()->create_user(['firstname' => 'Ada', 'lastname' => 'Lovelace']);
        transfer_manager::create(transfer_manager::TYPE_URL, (int) $user->id, ['url' => 'https://e/1']);
        transfer_manager::create(transfer_manager::TYPE_URL, (int) $user->id, ['url' => 'https://e/2']);

        $all = transfer_manager::list_all();
        $this->assertCount(2, $all);
        $first = reset($all);
        $this->assertSame('Ada Lovelace', $first->username);
    }
}

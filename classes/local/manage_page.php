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
 * Shared page setup and navigation for the plugin's standalone management pages.
 *
 * A repository plugin cannot register pages in Moodle's admin settings tree, so
 * the peers, shares, import and transfers screens are standalone pages linked
 * from the plugin's configuration page. On their own they would have no way back
 * or across, so this helper gives them all a common breadcrumb (leading back to
 * the configuration page) and a tab bar that links every management page plus a
 * "Settings" tab back to the configuration page.
 *
 * @package    repository_largefile
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace repository_largefile\local;

/**
 * Shared page setup and navigation for the management pages.
 *
 * @package    repository_largefile
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class manage_page {
    /**
     * URL of the plugin's configuration page (the management pages' home).
     *
     * @return \moodle_url The repository type configuration page.
     */
    public static function config_url(): \moodle_url {
        return new \moodle_url('/admin/repository.php', ['action' => 'edit', 'repos' => 'largefile']);
    }

    /**
     * A one-line progress summary for a running transfer.
     *
     * Shows the percent and how long it has been running; when the transfer records
     * its size (a publish) it adds a rough average throughput and an estimated time
     * remaining. If the percent has not advanced for far longer than its own average
     * step, it is flagged as stalled instead — so a merely slow run (percent still
     * creeping up) is distinguishable from a stuck one at a glance.
     *
     * @param \stdClass $transfer A running transfer row.
     * @return string The summary, e.g. "47% · avg 85.3 MB/s · about 35 mins left · running for 12 mins".
     */
    public static function running_progress(\stdClass $transfer): string {
        $now = time();
        $percent = (int) $transfer->progress;
        $started = (int) $transfer->timestarted;
        $elapsed = $started ? max(1, $now - $started) : 0;
        $lastadvance = (int) ($transfer->progressupdated ?: $transfer->timestarted);
        $total = (int) (transfer_manager::payload($transfer)['filesize'] ?? 0);

        $parts = [$percent . '%'];
        // Stalled: still mid-run, but no forward step for well over its own average
        // step time (and at least two minutes), which no longer looks like progress.
        $stepaverage = $percent > 0 ? $elapsed / $percent : 0;
        $sincestep = $lastadvance ? $now - $lastadvance : 0;
        $stalled = $percent > 0 && $percent < 100 && $sincestep > max(120, (int) (3 * $stepaverage));

        if ($stalled) {
            $parts[] = get_string('transferstalled', 'repository_largefile', format_time($sincestep));
        } else if ($total > 0 && $percent > 0 && $elapsed > 0) {
            $done = (int) ($total * $percent / 100);
            $rate = (int) ($done / $elapsed);
            if ($rate > 0) {
                $parts[] = get_string('transferrate', 'repository_largefile', display_size($rate));
                $parts[] = get_string('transfereta', 'repository_largefile', format_time((int) (($total - $done) / $rate)));
            }
        }
        if ($elapsed > 0) {
            $parts[] = get_string('transferrunningfor', 'repository_largefile', format_time($elapsed));
        }
        return implode(' · ', $parts);
    }

    /**
     * Render the "uploads in progress" region: a table of every chunked upload
     * streaming in site-wide (marked Background or In-page), or a notice when there
     * is none. Returned as an HTML string so the Transfers page and its live-refresh
     * AJAX endpoint render byte-for-byte the same markup.
     *
     * @return string The rendered table, or the empty-state notification.
     */
    public static function active_uploads_html(): string {
        global $DB, $OUTPUT;
        $active = $DB->get_records_select(
            'repository_largefile_chunks',
            'state = :state',
            ['state' => \repository_largefile\chunk_store::STATE_STARTED],
            'lastmodified DESC'
        );
        if (!$active) {
            return $OUTPUT->notification(
                get_string('nouploadsinprogress', 'repository_largefile'),
                \core\output\notification::NOTIFY_INFO
            );
        }
        // Bulk-load the distinct upload owners in one query rather than one per row:
        // this renderer now runs on a short poll, so a per-row user lookup would be
        // an N+1 query source repeated every few seconds for every watching admin.
        $userids = [];
        foreach ($active as $row) {
            if ($row->userid) {
                $userids[(int) $row->userid] = true;
            }
        }
        $users = $userids ? $DB->get_records_list('user', 'id', array_keys($userids)) : [];
        $table = new \html_table();
        $table->head = [
            get_string('transferuser', 'repository_largefile'),
            get_string('sharefilecol', 'repository_largefile'),
            get_string('uploadmode', 'repository_largefile'),
            get_string('transferprogress', 'repository_largefile'),
            get_string('uploadlastactivity', 'repository_largefile'),
            get_string('actions'),
        ];
        $baseurl = new \moodle_url('/repository/largefile/transfers.php');
        foreach ($active as $row) {
            $user = $row->userid && isset($users[$row->userid]) ? $users[$row->userid] : null;
            $length = (int) $row->length;
            // Floor, never round: a not-yet-complete upload must not read "100%".
            // A completed upload leaves this table, so 100% here would be a lie —
            // an out-of-order upload one chunk short would otherwise round up to it.
            $pct = $length > 0 ? (int) floor((int) $row->currentpos * 100 / $length) . '%' : '—';
            // A Background Fetch upload keeps streaming even after its tab is closed;
            // an in-page upload only progresses while its browser tab is open.
            $modekey = \repository_largefile\chunk_store::is_background($row)
                ? 'uploadmodebackground'
                : 'uploadmodeforeground';
            // Remove a stalled upload on demand: an experimental background upload can
            // stall a chunk short and sit here until the cleanup task's retention
            // window elapses; this deletes the row and its partial file straight away.
            $remove = \html_writer::link(
                new \moodle_url($baseurl, ['action' => 'removeupload', 'uploadid' => $row->id, 'sesskey' => sesskey()]),
                get_string('remove')
            );
            $table->data[] = [
                $user ? fullname($user) : '—',
                format_string((string) $row->filename),
                get_string($modekey, 'repository_largefile'),
                $pct,
                userdate((int) $row->lastmodified),
                $remove,
            ];
        }
        return \html_writer::table($table);
    }

    /**
     * Render the "completed uploads" table: staged files that finished uploading but
     * have not been selected into an activity yet, so they are still sitting in the
     * chunk area consuming disk until they are used or the cleanup task removes them.
     * Each row offers a Remove action so an admin can reclaim that space. Returned as
     * HTML; an empty-state notice when there are none.
     *
     * @param \moodle_url $baseurl The Transfers page URL the Remove links post back to.
     * @return string The rendered table, or the empty-state notification.
     */
    public static function completed_uploads_html(\moodle_url $baseurl): string {
        global $DB, $OUTPUT;
        $completed = $DB->get_records_select(
            'repository_largefile_chunks',
            'state = :state',
            ['state' => \repository_largefile\chunk_store::STATE_COMPLETED],
            'lastmodified DESC'
        );
        if (!$completed) {
            return $OUTPUT->notification(
                get_string('nocompleteduploads', 'repository_largefile'),
                \core\output\notification::NOTIFY_INFO
            );
        }
        $userids = [];
        foreach ($completed as $row) {
            if ($row->userid) {
                $userids[(int) $row->userid] = true;
            }
        }
        $users = $userids ? $DB->get_records_list('user', 'id', array_keys($userids)) : [];
        $table = new \html_table();
        $table->head = [
            get_string('transferuser', 'repository_largefile'),
            get_string('sharefilecol', 'repository_largefile'),
            get_string('uploadsize', 'repository_largefile'),
            get_string('uploadlastactivity', 'repository_largefile'),
            get_string('actions'),
        ];
        foreach ($completed as $row) {
            $user = $row->userid && isset($users[$row->userid]) ? $users[$row->userid] : null;
            $remove = \html_writer::link(
                new \moodle_url($baseurl, ['action' => 'removecompleted', 'uploadid' => $row->id, 'sesskey' => sesskey()]),
                get_string('remove')
            );
            $table->data[] = [
                $user ? fullname($user) : '—',
                format_string((string) $row->filename),
                display_size((int) $row->length),
                userdate((int) $row->lastmodified),
                $remove,
            ];
        }
        return \html_writer::table($table);
    }

    /**
     * Set a management page up with the admin layout, its title, and a breadcrumb
     * that leads back to the plugin's configuration page.
     *
     * @param \moodle_url $url The page's own URL.
     * @param string $title The page title and heading.
     * @return void
     */
    public static function setup(\moodle_url $url, string $title): void {
        global $PAGE;
        $PAGE->set_context(\context_system::instance());
        $PAGE->set_url($url);
        $PAGE->set_pagelayout('admin');
        $PAGE->set_title($title);
        $PAGE->set_heading($title);
        // The configuration page is core admin, so only link back to it for a user
        // who can actually open it; a delegated manager sees the title alone.
        if (has_capability('moodle/site:config', \context_system::instance())) {
            $PAGE->navbar->add(get_string('pluginname', 'repository_largefile'), self::config_url());
        }
        $PAGE->navbar->add($title);
    }

    /**
     * Render the management tab bar, with the given tab marked current.
     *
     * A "Settings" tab returns to the configuration page (only for a user who can
     * open it); the rest link the management pages the current user may reach.
     *
     * @param string $active The key of the current tab (settings, peers, shares,
     *        import or transfers).
     * @return string The rendered tab tree.
     */
    public static function tabs(string $active): string {
        global $OUTPUT;
        $context = \context_system::instance();

        $tabs = [];
        // The configuration page is core admin, so only offer the Settings tab to a
        // user who can actually open it; a delegated manager sees the rest alone.
        if (has_capability('moodle/site:config', $context)) {
            $tabs[] = new \tabobject('settings', self::config_url(), get_string('settings'));
        }
        if (has_capability('repository/largefile:share', $context)) {
            $tabs[] = new \tabobject(
                'peers',
                new \moodle_url('/repository/largefile/manage_peers.php'),
                get_string('managepeers', 'repository_largefile')
            );
            $tabs[] = new \tabobject(
                'shares',
                new \moodle_url('/repository/largefile/manage_shares.php'),
                get_string('manageshares', 'repository_largefile')
            );
        }
        if (has_capability('repository/largefile:import', $context)) {
            $tabs[] = new \tabobject(
                'import',
                new \moodle_url('/repository/largefile/import.php'),
                get_string('importshared', 'repository_largefile')
            );
            $tabs[] = new \tabobject(
                'transfers',
                new \moodle_url('/repository/largefile/transfers.php'),
                get_string('transfers', 'repository_largefile')
            );
        }
        return $OUTPUT->tabtree($tabs, $active);
    }
}

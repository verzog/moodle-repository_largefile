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
 * Queue a server-side transfer and watch every transfer and upload on the site.
 *
 * @package    repository_largefile
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use repository_largefile\local\peer_manager;
use repository_largefile\local\transfer_manager;
use repository_largefile\local\manage_page;
use repository_largefile\form\transfer_form;

// Repository plugins are not part of the admin settings tree, so this page stands
// alone: it is reached from the plugin's configuration page and gated by the
// import capability (which a manager can hold without full site config).
require_login();
$context = context_system::instance();
require_capability('repository/largefile:import', $context);

$action = optional_param('action', '', PARAM_ALPHA);
$id = optional_param('id', 0, PARAM_INT);
$baseurl = new moodle_url('/repository/largefile/transfers.php');
manage_page::setup($baseurl, get_string('transfers', 'repository_largefile'));

// A share publication belongs to the sharing capability, not the import one this
// page requires, so an import-only operator neither sees nor can act on it.
$canshare = has_capability('repository/largefile:share', $context);
$mayacton = function (int $transferid) use ($canshare): bool {
    $transfer = transfer_manager::get($transferid);
    return $transfer && ($transfer->type !== transfer_manager::TYPE_PUBLISH || $canshare);
};

if ($action === 'cancel' && $id) {
    require_sesskey();
    if ($mayacton($id)) {
        transfer_manager::cancel($id);
        transfer_manager::delete_publish_source($id);
    }
    redirect($baseurl, get_string('transfercancelled', 'repository_largefile'));
}
if ($action === 'remove' && $id) {
    require_sesskey();
    if ($mayacton($id)) {
        transfer_manager::delete_publish_source($id);
        transfer_manager::delete($id);
    }
    redirect($baseurl, get_string('transferremoved', 'repository_largefile'));
}

$peers = peer_manager::menu();
$form = new transfer_form($baseurl->out(false), ['peers' => $peers]);
if ($data = $form->get_data()) {
    $when = ($data->when ?? 'now') === 'at' ? (int) $data->scheduledtime : 0;
    if ($data->type === transfer_manager::TYPE_SHARE && $peers) {
        transfer_manager::create(
            transfer_manager::TYPE_SHARE,
            (int) $USER->id,
            ['peerid' => (int) $data->peerid, 'shareurl' => $data->shareurl],
            $when
        );
    } else {
        transfer_manager::create(
            transfer_manager::TYPE_URL,
            (int) $USER->id,
            ['url' => $data->url],
            $when,
            $context->id
        );
    }
    redirect($baseurl, get_string('transferqueued', 'repository_largefile'));
}

echo $OUTPUT->header();
echo manage_page::tabs('transfers');
echo $OUTPUT->heading(get_string('transfers', 'repository_largefile'));
echo html_writer::tag('p', get_string('transfers_desc', 'repository_largefile'), ['class' => 'text-muted']);

// Uploads currently streaming in from a browser (site-wide).
$active = $DB->get_records_select(
    'repository_largefile_chunks',
    'state = :state',
    ['state' => \repository_largefile\chunk_store::STATE_STARTED],
    'lastmodified DESC'
);
echo $OUTPUT->heading(get_string('uploadsinprogress', 'repository_largefile'), 3);
if ($active) {
    $table = new html_table();
    $table->head = [
        get_string('transferuser', 'repository_largefile'),
        get_string('sharefilecol', 'repository_largefile'),
        get_string('transferprogress', 'repository_largefile'),
        get_string('shareexpirescol', 'repository_largefile'),
    ];
    foreach ($active as $row) {
        $user = $row->userid ? \core_user::get_user($row->userid) : null;
        $length = (int) $row->length;
        $pct = $length > 0 ? round((int) $row->currentpos * 100 / $length) . '%' : '—';
        $table->data[] = [
            $user ? fullname($user) : '—',
            format_string((string) $row->filename),
            $pct,
            userdate((int) $row->lastmodified),
        ];
    }
    echo html_writer::table($table);
} else {
    echo $OUTPUT->notification(get_string('nouploadsinprogress', 'repository_largefile'), \core\output\notification::NOTIFY_INFO);
}

// Queued, running and finished server-side transfers (site-wide).
$transfers = transfer_manager::list_all();
echo $OUTPUT->heading(get_string('transferqueue', 'repository_largefile'), 3);
if ($transfers) {
    $typenames = [
        transfer_manager::TYPE_URL => get_string('transfertypeurl', 'repository_largefile'),
        transfer_manager::TYPE_SHARE => get_string('transfertypeshare', 'repository_largefile'),
        transfer_manager::TYPE_PUBLISH => get_string('transfertypepublish', 'repository_largefile'),
    ];
    $table = new html_table();
    $table->head = [
        get_string('transfertype', 'repository_largefile'),
        get_string('transferuser', 'repository_largefile'),
        get_string('transferstatus', 'repository_largefile'),
        get_string('transferscheduledtime', 'repository_largefile'),
        get_string('transferoutcome', 'repository_largefile'),
        get_string('actions'),
    ];
    foreach ($transfers as $transfer) {
        // A publication is only shown to a user who holds the sharing capability.
        if ($transfer->type === transfer_manager::TYPE_PUBLISH && !$canshare) {
            continue;
        }
        $when = (int) $transfer->scheduledtime <= (int) $transfer->timecreated
            ? get_string('transferwhennow', 'repository_largefile')
            : userdate((int) $transfer->scheduledtime);
        if ($transfer->status === transfer_manager::STATUS_COMPLETED) {
            $outcome = s((string) $transfer->result);
        } else if ($transfer->status === transfer_manager::STATUS_FAILED) {
            $outcome = html_writer::tag('span', s((string) $transfer->error), ['class' => 'text-danger']);
        } else if ($transfer->status === transfer_manager::STATUS_RUNNING) {
            // Only the publish runner reports a percentage; for the import types show
            // elapsed time alone rather than a misleading 0%.
            $elapsed = $transfer->timestarted
                ? get_string('transferrunningfor', 'repository_largefile', format_time(time() - (int) $transfer->timestarted))
                : '';
            $percent = $transfer->type === transfer_manager::TYPE_PUBLISH ? ((int) $transfer->progress) . '% ' : '';
            $outcome = trim($percent . $elapsed) ?: '—';
        } else {
            $outcome = '—';
        }
        $actions = '';
        if ($transfer->status === transfer_manager::STATUS_SCHEDULED) {
            $actions = html_writer::link(
                new moodle_url($baseurl, ['action' => 'cancel', 'id' => $transfer->id, 'sesskey' => sesskey()]),
                get_string('cancel')
            );
        } else if ($transfer->status !== transfer_manager::STATUS_RUNNING) {
            $actions = html_writer::link(
                new moodle_url($baseurl, ['action' => 'remove', 'id' => $transfer->id, 'sesskey' => sesskey()]),
                get_string('delete')
            );
        }
        $table->data[] = [
            $typenames[$transfer->type] ?? s($transfer->type),
            format_string((string) $transfer->username),
            get_string('transferstatus_' . $transfer->status, 'repository_largefile'),
            $when,
            $outcome,
            $actions,
        ];
    }
    echo html_writer::table($table);
} else {
    echo $OUTPUT->notification(get_string('notransfers', 'repository_largefile'), \core\output\notification::NOTIFY_INFO);
}

echo $OUTPUT->heading(get_string('transfernew', 'repository_largefile'), 3);
$form->display();
echo $OUTPUT->footer();

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

// Live-refresh endpoint: return just the uploads-in-progress region so the page's
// JS can poll it every few seconds without reloading the whole page (which would
// disturb the admin's "queue a new transfer" form). Read-only and admin-gated by
// the capability check above; the sesskey keeps it same-origin. The region is
// returned as JSON so that if the session has expired — require_login() then
// redirects this fetch to the login page, which still arrives as HTTP 200 — the
// client can tell the login page from a real fragment and refuse to inject it.
if (optional_param('ajax', '', PARAM_ALPHA) === 'uploads') {
    require_sesskey();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['html' => manage_page::active_uploads_html()]);
    die;
}

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
// Remove a stalled in-progress chunked upload on demand (row + partial file), so an
// admin need not wait for the cleanup task's retention window. Gated by the import
// capability required above; the token id is a chunk_store id (a numeric string).
// delete_if_started() re-checks the state under the background writer's lock, so an
// upload that completed since the page was rendered is never deleted, and the true
// outcome is reported rather than always claiming success.
if ($action === 'removeupload') {
    require_sesskey();
    $uploadid = optional_param('uploadid', '', PARAM_ALPHANUM);
    $outcome = $uploadid !== '' ? \repository_largefile\chunk_store::delete_if_started($uploadid) : 'notstarted';
    $messages = [
        'removed' => 'uploadremoved',
        'notstarted' => 'uploadalreadyfinished',
        'failed' => 'uploadremovefailed',
    ];
    redirect($baseurl, get_string($messages[$outcome], 'repository_largefile'));
}
// Remove every in-progress upload at once, to reclaim disk when stalled uploads have
// built up. Destructive (it interrupts any upload still genuinely streaming), so it
// asks for confirmation first.
if ($action === 'removeallstalled') {
    require_sesskey();
    if (optional_param('confirm', 0, PARAM_BOOL)) {
        $removed = \repository_largefile\chunk_store::delete_all_started();
        redirect($baseurl, get_string('uploadsremoved', 'repository_largefile', $removed));
    }
    echo $OUTPUT->header();
    echo $OUTPUT->confirm(
        get_string('confirmremoveallstalled', 'repository_largefile'),
        new moodle_url($baseurl, ['action' => 'removeallstalled', 'confirm' => 1, 'sesskey' => sesskey()]),
        $baseurl
    );
    echo $OUTPUT->footer();
    exit;
}

$peers = peer_manager::menu();
$form = new transfer_form($baseurl->out(false), ['peers' => $peers]);
if ($data = $form->get_data()) {
    $when = ($data->when ?? 'now') === 'at' ? (int) $data->scheduledtime : 0;
    // Empty means "auto": the policy routes to the file kind's default destination.
    $destination = $data->destination ?? '';
    // Target course for the course backup area destination (0 otherwise).
    $targetcourseid = (int) ($data->courseid ?? 0);
    if ($data->type === transfer_manager::TYPE_SHARE && $peers) {
        transfer_manager::create(
            transfer_manager::TYPE_SHARE,
            (int) $USER->id,
            [
                'peerid' => (int) $data->peerid,
                'shareurl' => $data->shareurl,
                'destination' => $destination,
                'targetcourseid' => $targetcourseid,
            ],
            $when
        );
    } else {
        transfer_manager::create(
            transfer_manager::TYPE_URL,
            (int) $USER->id,
            ['url' => $data->url, 'destination' => $destination, 'targetcourseid' => $targetcourseid],
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

// Uploads currently streaming in from a browser (site-wide). The region is
// refreshed in place every few seconds by the transfers_monitor module (see the
// js_call_amd below), so a background upload's progress climbs without a reload.
echo $OUTPUT->heading(get_string('uploadsinprogress', 'repository_largefile'), 3);
echo html_writer::div(manage_page::active_uploads_html(), '', ['id' => 'largefile-active-uploads']);
$PAGE->requires->js_call_amd('repository_largefile/transfers_monitor', 'init', [[
    'url' => (new moodle_url($baseurl, ['ajax' => 'uploads', 'sesskey' => sesskey()]))->out(false),
    'region' => 'largefile-active-uploads',
    'interval' => 5000,
]]);
// Bulk "reclaim disk" action, shown only when there is more than one upload to clear
// (a single one has its own Remove link). Confirmed before it runs.
if ($DB->count_records('repository_largefile_chunks', ['state' => \repository_largefile\chunk_store::STATE_STARTED]) > 1) {
    echo html_writer::div(
        html_writer::link(
            new moodle_url($baseurl, ['action' => 'removeallstalled', 'sesskey' => sesskey()]),
            get_string('removeallstalled', 'repository_largefile'),
            ['class' => 'btn btn-secondary']
        ),
        'mb-3'
    );
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
            // Only the publish runner reports progress: show its percent, throughput
            // and ETA. For the import types show elapsed time alone (no 0%).
            if ($transfer->type === transfer_manager::TYPE_PUBLISH) {
                $outcome = manage_page::running_progress($transfer);
            } else {
                $outcome = $transfer->timestarted
                    ? get_string('transferrunningfor', 'repository_largefile', format_time(time() - (int) $transfer->timestarted))
                    : '—';
            }
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

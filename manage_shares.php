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
 * Publish and revoke encrypted backup shares to trusted peers.
 *
 * @package    repository_largefile
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use repository_largefile\local\peer_manager;
use repository_largefile\local\share_manager;
use repository_largefile\local\transfer_manager;
use repository_largefile\local\manage_page;
use repository_largefile\form\share_form;
use repository_largefile\event\share_created;

// Repository plugins are not part of the admin settings tree, so this management
// page stands alone: it is reached from the plugin's configuration page and gated
// by the sharing capability (which a manager can hold without full site config).
require_login();
$context = context_system::instance();
require_capability('repository/largefile:share', $context);

$action = optional_param('action', '', PARAM_ALPHA);
$id = optional_param('id', 0, PARAM_INT);
$baseurl = new moodle_url('/repository/largefile/manage_shares.php');
manage_page::setup($baseurl, get_string('manageshares', 'repository_largefile'));

if ($action === 'revoke' && $id) {
    require_sesskey();
    share_manager::delete($id);
    redirect($baseurl, get_string('sharedeleted', 'repository_largefile'));
}
if ($action === 'cancelpublish' && $id) {
    require_sesskey();
    // A publisher may cancel only their own queued publication.
    $pending = transfer_manager::get($id);
    if ($pending && $pending->type === transfer_manager::TYPE_PUBLISH && (int) $pending->userid === (int) $USER->id) {
        transfer_manager::cancel($id);
        transfer_manager::delete_publish_source($id);
    }
    redirect($baseurl, get_string('transfercancelled', 'repository_largefile'));
}

$peers = peer_manager::menu();
$newshare = null;

if ($peers) {
    $form = new share_form($baseurl->out(false), ['peers' => $peers]);
    if ($data = $form->get_data()) {
        // Pull the uploaded file out of the draft area into a temp path to encrypt.
        $fs = get_file_storage();
        $usercontext = context_user::instance($USER->id);
        $files = $fs->get_area_files($usercontext->id, 'user', 'draft', $data->sharefile, 'id DESC', false);
        $file = reset($files);
        if ($file && !empty($data->background)) {
            // Encrypt and publish on the server, immune to the web request timeout
            // that a large backup would otherwise hit. The scheduled task encrypts
            // it and the link appears on this page.
            $transferid = transfer_manager::create(
                transfer_manager::TYPE_PUBLISH,
                (int) $USER->id,
                [
                    'peerid' => (int) $data->peerid,
                    // Store the requested duration, not an absolute time: the runner
                    // starts the expiry when the share is actually created.
                    'expiryduration' => empty($data->expiry) ? 0 : (int) $data->expiry,
                    'maxdownloads' => max(0, (int) $data->maxdownloads),
                ],
                0,
                $context->id,
                $file->get_filename()
            );
            // Stage the upload into a plugin-owned area keyed by the transfer id, so
            // it survives Moodle's draft-area cleanup until the job runs. Referencing
            // the stored file copies only a file record — the (multi-gigabyte) bytes
            // are shared, not duplicated.
            $fs->create_file_from_storedfile([
                'contextid' => $context->id,
                'component' => 'repository_largefile',
                'filearea' => transfer_manager::PENDING_FILEAREA,
                'itemid' => $transferid,
                'filepath' => '/',
                'filename' => $file->get_filename(),
            ], $file);
            // Return to this page (the publisher holds the sharing capability, which
            // the site-wide Transfers monitor does not require); the pending job and
            // then its link appear below.
            redirect($baseurl, get_string('sharequeued', 'repository_largefile'));
        }
        if ($file) {
            $temp = make_request_directory() . '/' . $file->get_filename();
            $file->copy_content_to($temp);
            $newshare = share_manager::create(
                (int) $data->peerid,
                $temp,
                $file->get_filename(),
                empty($data->expiry) ? 0 : time() + (int) $data->expiry,
                max(0, (int) $data->maxdownloads),
                (int) $USER->id
            );
            share_created::for_share($newshare)->trigger();
        }
    }
}

echo $OUTPUT->header();
echo manage_page::tabs('shares');
echo $OUTPUT->heading(get_string('manageshares', 'repository_largefile'));
echo html_writer::tag('p', get_string('manageshares_desc', 'repository_largefile'), ['class' => 'text-muted']);

if ($newshare) {
    $link = (new moodle_url('/repository/largefile/share.php', ['token' => $newshare->token]))->out(false);
    echo $OUTPUT->notification(get_string('sharecreated', 'repository_largefile'), \core\output\notification::NOTIFY_SUCCESS);
    echo html_writer::tag('p', get_string('sharelinkinfo', 'repository_largefile'));
    echo html_writer::tag('pre', s($link), ['class' => 'p-2 bg-light border rounded']);
}

if (!$peers) {
    echo $OUTPUT->notification(get_string('nopeersforshare', 'repository_largefile'), \core\output\notification::NOTIFY_WARNING);
    echo html_writer::link(
        new moodle_url('/repository/largefile/manage_peers.php'),
        get_string('managepeers', 'repository_largefile')
    );
    echo $OUTPUT->footer();
    die;
}

// Backups still being encrypted in the background (this user's own jobs), so the
// publisher can watch progress and see the link land without leaving this page.
$pending = array_filter(
    transfer_manager::list_for_user((int) $USER->id),
    fn($t) => $t->type === transfer_manager::TYPE_PUBLISH && $t->status !== transfer_manager::STATUS_COMPLETED
);
if ($pending) {
    echo $OUTPUT->heading(get_string('pendingpublications', 'repository_largefile'), 3);
    $ptable = new html_table();
    $ptable->head = [
        get_string('sharefilecol', 'repository_largefile'),
        get_string('transferstatus', 'repository_largefile'),
        get_string('transferoutcome', 'repository_largefile'),
        get_string('actions'),
    ];
    foreach ($pending as $job) {
        if ($job->status === transfer_manager::STATUS_RUNNING) {
            // Show percent complete and how long it has been running, so a slow (or
            // stuck) encryption is legible instead of an opaque "running".
            $elapsed = $job->timestarted
                ? get_string('transferrunningfor', 'repository_largefile', format_time(time() - (int) $job->timestarted))
                : '';
            $outcome = trim(((int) $job->progress) . '%' . ' ' . $elapsed);
        } else if ($job->status === transfer_manager::STATUS_FAILED) {
            $outcome = html_writer::tag('span', s((string) $job->error), ['class' => 'text-danger']);
        } else {
            $outcome = '—';
        }
        $cancel = $job->status === transfer_manager::STATUS_SCHEDULED
            ? html_writer::link(
                new moodle_url($baseurl, ['action' => 'cancelpublish', 'id' => $job->id, 'sesskey' => sesskey()]),
                get_string('cancel')
            )
            : '';
        $ptable->data[] = [
            format_string((string) $job->filename),
            get_string('transferstatus_' . $job->status, 'repository_largefile'),
            $outcome,
            $cancel,
        ];
    }
    echo html_writer::table($ptable);
}

$shares = share_manager::list_all();
if ($shares) {
    echo $OUTPUT->heading(get_string('sharesheading', 'repository_largefile'), 3);
    $table = new html_table();
    $table->head = [
        get_string('sharefilecol', 'repository_largefile'),
        get_string('sharepeer', 'repository_largefile'),
        get_string('sharelinkcol', 'repository_largefile'),
        get_string('shareexpirescol', 'repository_largefile'),
        get_string('sharedownloadscol', 'repository_largefile'),
        get_string('actions'),
    ];
    foreach ($shares as $share) {
        $expiry = (int) $share->expires === 0
            ? get_string('expiresnever', 'repository_largefile')
            : userdate((int) $share->expires);
        $downloads = (int) $share->maxdownloads === 0
            ? (int) $share->downloadcount . ' / ' . get_string('unlimited', 'repository_largefile')
            : (int) $share->downloadcount . ' / ' . (int) $share->maxdownloads;
        $link = (new moodle_url('/repository/largefile/share.php', ['token' => $share->token]))->out(false);
        $revoke = html_writer::link(
            new moodle_url($baseurl, ['action' => 'revoke', 'id' => $share->id, 'sesskey' => sesskey()]),
            get_string('revokeshare', 'repository_largefile'),
            ['onclick' => "return confirm('" . get_string('revokeshareconfirm', 'repository_largefile') . "');"]
        );
        $table->data[] = [
            format_string($share->filename),
            format_string($share->peername ?? ''),
            html_writer::tag('code', s($link), ['class' => 'text-break']),
            $expiry,
            $downloads,
            $revoke,
        ];
    }
    echo html_writer::table($table);
}

echo $OUTPUT->heading(get_string('createshare', 'repository_largefile'), 3);
$form->display();
echo $OUTPUT->footer();

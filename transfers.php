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
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use repository_largefile\chunk_store;
use repository_largefile\local\import_policy;
use repository_largefile\local\peer_manager;
use repository_largefile\local\transfer_manager;
use repository_largefile\local\manage_page;
use repository_largefile\form\completed_restore_form;
use repository_largefile\form\completed_send_form;
use repository_largefile\form\stored_restore_form;
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
// Route a completed-but-unselected upload straight to a real destination (private
// backup area / course backup area / private files) via a small form, so a large
// backup does not have to be re-selected through the (slow) large-file picker.
// The file's kind narrows the destinations offered; sending is gated by the same
// policy the ordinary import path applies, and the source is consumed on success.
if ($action === 'sendcompleted') {
    require_sesskey();
    $uploadid = optional_param('uploadid', '', PARAM_ALPHANUM);
    if ($uploadid !== '' && in_array($uploadid, transfer_manager::active_source_tokens(), true)) {
        redirect($baseurl, get_string('uploadbusyqueued', 'repository_largefile'));
    }
    $record = $uploadid !== '' ? chunk_store::get_record($uploadid) : null;
    if (!$record || (int) $record->state !== chunk_store::STATE_COMPLETED) {
        redirect($baseurl, get_string('completeduploadgone', 'repository_largefile'));
    }
    // Guard the destination form with a file-exists check too: an orphan row
    // whose payload has been externally removed cannot be routed anywhere, and
    // displaying the destination picker only to fail on submit wastes the
    // operator's time. The inner file-exists check under the lock still stands,
    // for the race window between this GET and the eventual POST.
    $srcpath = chunk_store::get_path_for_id($record->id);
    if (!$srcpath || !file_exists($srcpath)) {
        redirect($baseurl, get_string('completeduploadnofile', 'repository_largefile'));
    }
    $type = import_policy::detect_type((string) $record->filename);
    $destinations = [];
    foreach (import_policy::destinations_for($type) as $dest) {
        // The file is already staged in the large-file picker (that is what the
        // chunk store *is*), so offering "picker" as a Send destination would
        // just move the bytes to a new token — an operation with no visible
        // effect, since the user must still reopen the same picker to use it.
        if ($dest === import_policy::DEST_PICKER) {
            continue;
        }
        $destinations[$dest] = import_policy::destination_label($dest);
    }
    if (!$destinations) {
        redirect(
            $baseurl,
            get_string('errordestnotallowed', 'repository_largefile', import_policy::type_label($type))
        );
    }
    $form = new completed_send_form(
        new moodle_url($baseurl, ['action' => 'sendcompleted', 'uploadid' => $uploadid]),
        ['uploadid' => $uploadid, 'filename' => $record->filename, 'destinations' => $destinations]
    );
    if ($form->is_cancelled()) {
        redirect($baseurl);
    }
    if ($data = $form->get_data()) {
        $destination = (string) ($data->destination ?? array_key_first($destinations));
        if (!isset($destinations[$destination])) {
            redirect(
                $baseurl,
                get_string('errordestnotallowed', 'repository_largefile', import_policy::type_label($type)),
                null,
                \core\output\notification::NOTIFY_ERROR
            );
        }
        $courseid = 0;
        $contextid = context_system::instance()->id;
        if ($destination === import_policy::DEST_COURSEBACKUP) {
            // Re-checked here (and again by the runner) so a spoofed form cannot
            // route a file into a course the operator may not add a backup to.
            $courseid = (int) ($data->courseid ?? 0);
            if ($courseid <= 0 || !import_policy::can_use_course_backup((int) $USER->id, $courseid)) {
                redirect(
                    $baseurl,
                    get_string('errornocoursebackupcap', 'repository_largefile'),
                    null,
                    \core\output\notification::NOTIFY_ERROR
                );
            }
            $contextid = \context_course::instance($courseid)->id;
        }
        // Hashing and copying a multi-gigabyte file into the file pool takes
        // minutes — far longer than a web request lives behind a proxy, which
        // answers with a gateway timeout. So queue the copy as a background
        // transfer: the runner re-checks the policy and rights, copies the file
        // under cron into the operator's destination (not the upload owner's: a
        // manager may route someone else's upload into their own areas or the
        // course they picked), then notifies the operator. The upload is re-checked
        // and the job queued under the upload's lock, so two operators submitting
        // at once cannot both queue it.
        $lockfactory = \core\lock\lock_config::get_lock_factory('repository_largefile_bg');
        $lock = $lockfactory->get_lock($record->id, 10);
        if (!$lock) {
            redirect(
                $baseurl,
                get_string('errorrestorebusy', 'repository_largefile'),
                null,
                \core\output\notification::NOTIFY_ERROR
            );
        }
        try {
            // Re-check the upload under its lock: a Remove or cleanup run may have
            // taken it since the form was shown, and a job that needs it (a restore,
            // send or share publication) may have been queued meanwhile.
            $fresh = chunk_store::get_record($record->id);
            $freshpath = $fresh ? chunk_store::get_path_for_id($fresh->id) : null;
            $blocked = null;
            if (!$fresh || (int) $fresh->state !== chunk_store::STATE_COMPLETED) {
                $blocked = 'completeduploadgone';
            } else if (!$freshpath || !file_exists($freshpath)) {
                $blocked = 'completeduploadnofile';
            } else if (in_array((string) $record->id, transfer_manager::active_source_tokens(), true)) {
                $blocked = 'uploadalreadyqueued';
            }
            if ($blocked === null) {
                transfer_manager::create(
                    transfer_manager::TYPE_SEND,
                    (int) $USER->id,
                    [
                        'token' => (string) $record->id,
                        'destination' => $destination,
                        'courseid' => $courseid,
                        'filesize' => (int) $record->length,
                    ],
                    0,
                    $contextid,
                    (string) $record->filename
                );
            }
        } finally {
            $lock->release();
        }
        if ($blocked !== null) {
            redirect($baseurl, get_string($blocked, 'repository_largefile'));
        }
        redirect(
            $baseurl,
            get_string('sendqueued', 'repository_largefile', (object) [
                'file' => format_string((string) $record->filename),
                'destination' => import_policy::destination_label($destination),
            ]),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }
    echo $OUTPUT->header();
    echo manage_page::tabs('transfers');
    echo $OUTPUT->heading(get_string('sendcompletedheading', 'repository_largefile'));
    echo html_writer::tag('p', get_string('sendcompleted_desc', 'repository_largefile'), ['class' => 'text-muted']);
    $form->display();
    echo $OUTPUT->footer();
    exit;
}
// Restore a completed .mbz upload directly: either copy the file into a chosen
// course's backup area, ready for Moodle's restore wizard, or restore it unattended
// into a new course in a chosen category. Both run as background transfers, since a
// multi-gigabyte backup cannot be copied or restored within a web request. Saves the
// user opening the file picker on the restore screen and re-picking the upload.
if ($action === 'restorecompleted') {
    require_sesskey();
    $uploadid = optional_param('uploadid', '', PARAM_ALPHANUM);
    $record = $uploadid !== '' ? chunk_store::get_record($uploadid) : null;
    if (!$record || (int) $record->state !== chunk_store::STATE_COMPLETED) {
        redirect($baseurl, get_string('completeduploadgone', 'repository_largefile'));
    }
    // See the sendcompleted handler: bail on a row whose file is already gone
    // instead of walking the operator through the course picker only to fail.
    $srcpath = chunk_store::get_path_for_id($record->id);
    if (!$srcpath || !file_exists($srcpath)) {
        redirect($baseurl, get_string('completeduploadnofile', 'repository_largefile'));
    }
    if (import_policy::detect_type((string) $record->filename) !== import_policy::TYPE_BACKUP) {
        redirect($baseurl, get_string('errorrestorenotbackup', 'repository_largefile'));
    }
    if (!completed_restore_form::available_modes()) {
        redirect($baseurl, get_string('errorrestoremodeunavailable', 'repository_largefile'));
    }
    $form = new completed_restore_form(
        new moodle_url($baseurl, ['action' => 'restorecompleted', 'uploadid' => $uploadid]),
        ['uploadid' => $uploadid, 'filename' => $record->filename]
    );
    if ($form->is_cancelled()) {
        redirect($baseurl);
    }
    if ($data = $form->get_data()) {
        $mode = (string) ($data->mode ?? '');
        if ($mode === completed_restore_form::MODE_AUTO) {
            // Restore unattended into a new course. The form validated the category
            // against the operator's rights; the runner re-checks them when it runs.
            $categoryid = (int) $data->categoryid;
            $type = transfer_manager::TYPE_AUTORESTORE;
            $payload = ['categoryid' => $categoryid];
            $contextid = \context_coursecat::instance($categoryid)->id;
            $queuedkey = 'restoreautoqueued';
        } else {
            $courseid = (int) ($data->courseid ?? 0);
            $coursecontext = \context_course::instance($courseid, IGNORE_MISSING);
            // Both caps re-checked here so a background course change or a spoofed
            // form can never route a file into a course whose restore the user cannot
            // then start. The course picker already limited the options to these.
            if (
                !$coursecontext
                    || !has_capability('moodle/restore:uploadfile', $coursecontext)
                    || !has_capability('moodle/restore:restorecourse', $coursecontext)
            ) {
                redirect(
                    $baseurl,
                    get_string('errornocoursebackupcap', 'repository_largefile'),
                    null,
                    \core\output\notification::NOTIFY_ERROR
                );
            }
            // Copying a multi-gigabyte backup into the course backup area (hashing it
            // into the file pool) takes minutes — far longer than a web request lives
            // behind a proxy, which answers with a gateway timeout. So queue the copy
            // as a background transfer: the runner re-checks these rights, copies the
            // file under cron, then notifies the operator with a link straight into
            // the restore wizard (also shown on this page's transfer queue).
            $type = transfer_manager::TYPE_RESTORE;
            $payload = ['courseid' => $courseid];
            $contextid = $coursecontext->id;
            $queuedkey = 'restorequeued';
        }
        $payload += ['token' => (string) $record->id, 'filesize' => (int) $record->length];

        // Both kinds of restore run in the background, so never queue one for an
        // upload another job still needs. The upload is re-checked and the job
        // queued under the upload's lock, so two operators submitting at once cannot
        // both get through.
        $lockfactory = \core\lock\lock_config::get_lock_factory('repository_largefile_bg');
        $lock = $lockfactory->get_lock($record->id, 10);
        if (!$lock) {
            redirect(
                $baseurl,
                get_string('errorrestorebusy', 'repository_largefile'),
                null,
                \core\output\notification::NOTIFY_ERROR
            );
        }
        try {
            // Re-check the upload under its lock: a Remove or cleanup run may have
            // taken it since the form was shown, and a job that needs it (a restore,
            // send or share publication) may have been queued meanwhile.
            $fresh = chunk_store::get_record($record->id);
            $freshpath = $fresh ? chunk_store::get_path_for_id($fresh->id) : null;
            $blocked = null;
            if (!$fresh || (int) $fresh->state !== chunk_store::STATE_COMPLETED) {
                $blocked = 'completeduploadgone';
            } else if (!$freshpath || !file_exists($freshpath)) {
                $blocked = 'completeduploadnofile';
            } else if (in_array((string) $record->id, transfer_manager::active_source_tokens(), true)) {
                $blocked = 'uploadalreadyqueued';
            }
            if ($blocked === null) {
                transfer_manager::create($type, (int) $USER->id, $payload, 0, $contextid, (string) $record->filename);
            }
        } finally {
            $lock->release();
        }
        if ($blocked !== null) {
            redirect($baseurl, get_string($blocked, 'repository_largefile'));
        }
        redirect(
            $baseurl,
            get_string($queuedkey, 'repository_largefile', format_string((string) $record->filename)),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }
    echo $OUTPUT->header();
    echo manage_page::tabs('transfers');
    echo $OUTPUT->heading(get_string('restorecompletedheading', 'repository_largefile'));
    echo html_writer::tag('p', get_string('restorecompleted_desc', 'repository_largefile'), ['class' => 'text-muted']);
    $form->display();
    echo $OUTPUT->footer();
    exit;
}
// Restore a course backup that is already held in Moodle (the operator's backup area
// or private files, or a course backup area they may download from) into a new
// course, unattended in the background — so a backup already on the site never has
// to be uploaded again, nor restored through the wizard, whose steps time out on a
// very large backup. The backup itself is left where it is.
if ($action === 'restorestored') {
    require_sesskey();
    $sources = \repository_largefile\local\backup_source::restorable_backups((int) $USER->id);
    if (!$sources || !completed_restore_form::auto_restore_categories()) {
        redirect($baseurl, get_string('restorestorednone', 'repository_largefile'));
    }
    $form = new stored_restore_form(
        new moodle_url($baseurl, ['action' => 'restorestored']),
        ['sources' => $sources]
    );
    if ($form->is_cancelled()) {
        redirect($baseurl);
    }
    if ($data = $form->get_data()) {
        // Re-derive the operator's right to the file from the file itself.
        $fileid = preg_match('/^stored:(\d+)$/', (string) $data->source, $m) ? (int) $m[1] : 0;
        $file = \repository_largefile\local\backup_source::authorize_stored($fileid, (int) $USER->id);
        if (!$file || import_policy::detect_type($file->get_filename()) !== import_policy::TYPE_BACKUP) {
            redirect(
                $baseurl,
                get_string('errorrestorenofile', 'repository_largefile'),
                null,
                \core\output\notification::NOTIFY_ERROR
            );
        }
        $categoryid = (int) $data->categoryid;
        // One restore of a given backup at a time, decided under a lock so a
        // double-submitted form cannot queue two.
        $lockfactory = \core\lock\lock_config::get_lock_factory('repository_largefile_bg');
        $lock = $lockfactory->get_lock('stored' . $fileid, 10);
        if (!$lock) {
            redirect(
                $baseurl,
                get_string('errorrestorebusy', 'repository_largefile'),
                null,
                \core\output\notification::NOTIFY_ERROR
            );
        }
        try {
            $alreadyqueued = in_array($fileid, transfer_manager::active_stored_restore_fileids(), true);
            if (!$alreadyqueued) {
                transfer_manager::create(
                    transfer_manager::TYPE_AUTORESTORE,
                    (int) $USER->id,
                    [
                        'sourcetype' => \repository_largefile\local\backup_source::TYPE_STORED,
                        'fileid' => $fileid,
                        'categoryid' => $categoryid,
                        'filesize' => (int) $file->get_filesize(),
                    ],
                    0,
                    \context_coursecat::instance($categoryid)->id,
                    (string) $file->get_filename()
                );
            }
        } finally {
            $lock->release();
        }
        if ($alreadyqueued) {
            redirect($baseurl, get_string('restorestoredalready', 'repository_largefile'));
        }
        redirect(
            $baseurl,
            get_string('restoreautoqueued', 'repository_largefile', format_string($file->get_filename())),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }
    echo $OUTPUT->header();
    echo manage_page::tabs('transfers');
    echo $OUTPUT->heading(get_string('restorestoredheading', 'repository_largefile'));
    echo html_writer::tag('p', get_string('restorestored_desc', 'repository_largefile'), ['class' => 'text-muted']);
    $form->display();
    echo $OUTPUT->footer();
    exit;
}
// Remove a single completed-but-unselected upload (a staged file the owner uploaded
// but never picked into an activity), to reclaim its disk. delete_in_state() locks
// and confirms the row is still completed before deleting.
if ($action === 'removecompleted') {
    require_sesskey();
    $uploadid = optional_param('uploadid', '', PARAM_ALPHANUM);
    if ($uploadid !== '' && in_array($uploadid, transfer_manager::active_source_tokens(), true)) {
        redirect($baseurl, get_string('uploadbusyqueued', 'repository_largefile'));
    }
    $outcome = $uploadid !== ''
        ? \repository_largefile\chunk_store::delete_in_state($uploadid, \repository_largefile\chunk_store::STATE_COMPLETED)
        : 'notstarted';
    $messages = [
        'removed' => 'uploadremoved',
        'notstarted' => 'uploadalreadyfinished',
        'failed' => 'uploadremovefailed',
    ];
    redirect($baseurl, get_string($messages[$outcome], 'repository_largefile'));
}
// Remove every completed-but-unselected upload at once. Destructive (each is a file
// the owner uploaded and might still intend to use), so it confirms first.
if ($action === 'removeallcompleted') {
    require_sesskey();
    if (optional_param('confirm', 0, PARAM_BOOL)) {
        // Leave alone any upload a queued or running job (a restore or a share
        // publication) still needs, as the cleanup task does.
        $removed = \repository_largefile\chunk_store::delete_all_in_state(
            \repository_largefile\chunk_store::STATE_COMPLETED,
            transfer_manager::active_source_tokens()
        );
        redirect($baseurl, get_string('uploadsremoved', 'repository_largefile', $removed));
    }
    echo $OUTPUT->header();
    echo $OUTPUT->confirm(
        get_string('confirmremoveallcompleted', 'repository_largefile'),
        new moodle_url($baseurl, ['action' => 'removeallcompleted', 'confirm' => 1, 'sesskey' => sesskey()]),
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
        // Record the URL's file name now so the queue shows what is being fetched;
        // the runner replaces it with the server-supplied name once the download starts.
        $urlname = clean_param(rawurldecode(basename((string) parse_url($data->url, PHP_URL_PATH))), PARAM_FILE);
        transfer_manager::create(
            transfer_manager::TYPE_URL,
            (int) $USER->id,
            ['url' => $data->url, 'destination' => $destination, 'targetcourseid' => $targetcourseid],
            $when,
            $context->id,
            $urlname
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

// Completed uploads: files that finished uploading but were never selected into an
// activity, so they still occupy the chunk area (until used or the cleanup task
// removes them). Hidden behind a toggle since they are usually of no concern — an
// admin reclaiming disk can reveal them and delete them individually or in bulk.
$completedcount = $DB->count_records(
    'repository_largefile_chunks',
    ['state' => \repository_largefile\chunk_store::STATE_COMPLETED]
);
$showcompleted = optional_param('showcompleted', 0, PARAM_BOOL);
if ($showcompleted) {
    echo $OUTPUT->heading(get_string('completeduploads', 'repository_largefile'), 3);
    echo html_writer::tag('p', get_string('completeduploads_desc', 'repository_largefile'), ['class' => 'text-muted']);
    echo manage_page::completed_uploads_html($baseurl);
    if ($completedcount > 1) {
        echo html_writer::div(
            html_writer::link(
                new moodle_url($baseurl, ['action' => 'removeallcompleted', 'sesskey' => sesskey()]),
                get_string('removeallcompleted', 'repository_largefile'),
                ['class' => 'btn btn-secondary']
            ),
            'mb-3'
        );
    }
    echo html_writer::div(html_writer::link($baseurl, get_string('hidecompleteduploads', 'repository_largefile')), 'mb-3');
} else {
    echo html_writer::div(
        html_writer::link(
            new moodle_url($baseurl, ['showcompleted' => 1]),
            get_string('showcompleteduploads', 'repository_largefile', $completedcount)
        ),
        'mb-3'
    );
}

// Restore a backup already held in Moodle, offered to an operator who may create a
// course somewhere (the page lists only backups they may use).
if (completed_restore_form::auto_restore_categories()) {
    echo html_writer::div(
        html_writer::link(
            new moodle_url($baseurl, ['action' => 'restorestored', 'sesskey' => sesskey()]),
            get_string('restorestored', 'repository_largefile'),
            ['class' => 'btn btn-secondary']
        ),
        'mb-3'
    );
}

// Queued, running and finished server-side transfers (site-wide).
$transfers = transfer_manager::list_all();
echo $OUTPUT->heading(get_string('transferqueue', 'repository_largefile'), 3);
// A one-line key to the statuses, with the same badges the table uses.
echo html_writer::tag('p', get_string('transferqueue_desc', 'repository_largefile', (object) [
    'scheduled' => manage_page::transfer_status_badge(transfer_manager::STATUS_SCHEDULED),
    'running' => manage_page::transfer_status_badge(transfer_manager::STATUS_RUNNING),
    'completed' => manage_page::transfer_status_badge(transfer_manager::STATUS_COMPLETED),
    'failed' => manage_page::transfer_status_badge(transfer_manager::STATUS_FAILED),
]), ['class' => 'text-muted small']);
if ($transfers) {
    $typenames = [
        transfer_manager::TYPE_URL => get_string('transfertypeurl', 'repository_largefile'),
        transfer_manager::TYPE_SHARE => get_string('transfertypeshare', 'repository_largefile'),
        transfer_manager::TYPE_PUBLISH => get_string('transfertypepublish', 'repository_largefile'),
        transfer_manager::TYPE_RESTORE => get_string('transfertyperestore', 'repository_largefile'),
        transfer_manager::TYPE_AUTORESTORE => get_string('transfertypeautorestore', 'repository_largefile'),
        transfer_manager::TYPE_SEND => get_string('transfertypesend', 'repository_largefile'),
    ];
    $table = new html_table();
    $table->head = [
        get_string('transfertype', 'repository_largefile'),
        get_string('transferfile', 'repository_largefile'),
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
            if ($transfer->type === transfer_manager::TYPE_AUTORESTORE) {
                // The result is the restored course's id: link to the course itself.
                $course = $DB->get_record('course', ['id' => (int) $transfer->result], 'id, fullname', IGNORE_MISSING);
                $outcome = $course
                    ? html_writer::link(
                        new moodle_url('/course/view.php', ['id' => $course->id]),
                        format_string($course->fullname)
                    )
                    : s((string) $transfer->result);
            } else {
                $outcome = s((string) $transfer->result);
            }
            // A completed send links to where the file landed, when the viewer can
            // open it (another operator's private files cannot be).
            $sendurl = $transfer->type === transfer_manager::TYPE_SEND
                ? \repository_largefile\local\transfer_runner::send_url($transfer, (int) $USER->id)
                : null;
            if ($sendurl) {
                $outcome .= ' · ' . html_writer::link($sendurl, get_string('sendopendestination', 'repository_largefile'));
            }
            // A prepared restore links straight into the restore wizard on the file.
            $restoreurl = $transfer->type === transfer_manager::TYPE_RESTORE
                ? \repository_largefile\local\transfer_runner::restore_url($transfer)
                : null;
            if ($restoreurl) {
                $outcome .= ' · ' . html_writer::link($restoreurl, get_string('restorecontinue', 'repository_largefile'));
            }
        } else if ($transfer->status === transfer_manager::STATUS_FAILED) {
            $outcome = html_writer::tag('span', s((string) $transfer->error), ['class' => 'text-danger']);
        } else if ($transfer->status === transfer_manager::STATUS_RUNNING) {
            // Only the publish runner reports progress: show its percent, throughput
            // and ETA. For the import types show elapsed time alone (no 0%); a restore
            // is one long copy into the file store, so say that is what is happening.
            if ($transfer->type === transfer_manager::TYPE_PUBLISH) {
                $outcome = manage_page::running_progress($transfer);
            } else if (\repository_largefile\local\transfer_runner::is_consumer($transfer) && $transfer->timestarted) {
                $steps = [
                    transfer_manager::TYPE_RESTORE => get_string('restorecopying', 'repository_largefile'),
                    transfer_manager::TYPE_AUTORESTORE => get_string('restorerunning', 'repository_largefile'),
                    transfer_manager::TYPE_SEND => get_string(
                        'sendcopying',
                        'repository_largefile',
                        import_policy::destination_label((string) (transfer_manager::payload($transfer)['destination'] ?? ''))
                    ),
                ];
                $parts = [$steps[$transfer->type]];
                if ($transfer->type === transfer_manager::TYPE_AUTORESTORE) {
                    // An automatic restore reports a percentage: unpacking the backup
                    // is the first slice of it, then the restore itself.
                    $percent = (int) $transfer->progress;
                    if ($percent < \repository_largefile\local\transfer_runner::AUTORESTORE_UNPACK_PERCENT) {
                        $parts = [get_string('restoreunpacking', 'repository_largefile')];
                    }
                    $parts[] = $percent . '%';
                }
                $parts[] = get_string(
                    'transferrunningfor',
                    'repository_largefile',
                    format_time(time() - (int) $transfer->timestarted)
                );
                $outcome = implode(' · ', $parts);
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
            manage_page::transfer_file_label($transfer),
            format_string((string) $transfer->username),
            manage_page::transfer_status_badge((string) $transfer->status),
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

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
use repository_largefile\form\share_form;
use repository_largefile\event\share_created;

admin_externalpage_setup('repository_largefile_shares');
$context = context_system::instance();
require_capability('repository/largefile:share', $context);

$action = optional_param('action', '', PARAM_ALPHA);
$id = optional_param('id', 0, PARAM_INT);
$baseurl = new moodle_url('/repository/largefile/manage_shares.php');
$PAGE->set_url($baseurl);

if ($action === 'revoke' && $id) {
    require_sesskey();
    share_manager::delete($id);
    redirect($baseurl, get_string('sharedeleted', 'repository_largefile'));
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

$shares = share_manager::list_all();
if ($shares) {
    $table = new html_table();
    $table->head = [
        get_string('sharefilecol', 'repository_largefile'),
        get_string('sharepeer', 'repository_largefile'),
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
        $revoke = html_writer::link(
            new moodle_url($baseurl, ['action' => 'revoke', 'id' => $share->id, 'sesskey' => sesskey()]),
            get_string('revokeshare', 'repository_largefile'),
            ['onclick' => "return confirm('" . get_string('revokeshareconfirm', 'repository_largefile') . "');"]
        );
        $table->data[] = [
            format_string($share->filename),
            format_string($share->peername ?? ''),
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

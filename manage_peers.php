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
 * Manage the trusted peers this site can share backups with.
 *
 * @package    repository_largefile
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use repository_largefile\local\peer_manager;
use repository_largefile\local\manage_page;
use repository_largefile\local\share_client;
use repository_largefile\form\peer_form;

// Repository plugins are not part of the admin settings tree, so this management
// page stands alone: it is reached from the plugin's configuration page and gated
// by the sharing capability (which a manager can hold without full site config).
require_login();
$context = context_system::instance();
require_capability('repository/largefile:share', $context);

$action = optional_param('action', '', PARAM_ALPHA);
$id = optional_param('id', 0, PARAM_INT);
$baseurl = new moodle_url('/repository/largefile/manage_peers.php');
manage_page::setup($baseurl, get_string('managepeers', 'repository_largefile'));

// Deleting a peer also revokes every share published to it, so confirm on a page
// (a POSTed continue button) rather than with an inline script prompt.
if ($action === 'delete' && $id) {
    require_sesskey();
    if (optional_param('confirm', 0, PARAM_BOOL)) {
        peer_manager::delete($id);
        redirect($baseurl, get_string('peerdeleted', 'repository_largefile'));
    }
    echo $OUTPUT->header();
    echo $OUTPUT->confirm(
        get_string('deletepeerconfirm', 'repository_largefile'),
        new moodle_url($baseurl, ['action' => 'delete', 'id' => $id, 'confirm' => 1, 'sesskey' => sesskey()]),
        $baseurl
    );
    echo $OUTPUT->footer();
    exit;
}

// Live connection check: one signed round trip to the peer's share endpoint. The
// outcome is shown now and remembered in the listing.
if ($action === 'check' && $id) {
    require_sesskey();
    $result = share_client::ping($id);
    peer_manager::record_check($id, $result['ok'], $result['message']);
    redirect(
        $baseurl,
        $result['message'],
        null,
        $result['ok'] ? \core\output\notification::NOTIFY_SUCCESS : \core\output\notification::NOTIFY_ERROR
    );
}

$form = new peer_form($baseurl->out(false), ['id' => $id]);
if ($id && $action === 'edit') {
    $peer = peer_manager::get($id);
    if ($peer) {
        $form->set_data(['id' => $peer->id, 'name' => $peer->name, 'baseurl' => $peer->baseurl]);
    }
}

if ($form->is_cancelled()) {
    redirect($baseurl);
} else if ($data = $form->get_data()) {
    if (!empty($data->id)) {
        peer_manager::update((int) $data->id, $data->name, $data->secret ?: null, $data->baseurl);
    } else {
        peer_manager::create($data->name, $data->secret, $data->baseurl);
    }
    redirect($baseurl, get_string('peersaved', 'repository_largefile'));
}

echo $OUTPUT->header();
echo manage_page::tabs('peers');
echo $OUTPUT->heading(get_string('managepeers', 'repository_largefile'));
echo html_writer::tag('p', get_string('managepeers_desc', 'repository_largefile'), ['class' => 'text-muted']);

$peers = peer_manager::get_all();
if ($peers) {
    $table = new html_table();
    $table->head = [
        get_string('peername', 'repository_largefile'),
        get_string('peerurl', 'repository_largefile'),
        get_string('peercheckcol', 'repository_largefile'),
        get_string('actions'),
    ];
    foreach ($peers as $peer) {
        $check = html_writer::link(
            new moodle_url($baseurl, ['action' => 'check', 'id' => $peer->id, 'sesskey' => sesskey()]),
            get_string('peercheck', 'repository_largefile')
        );
        $edit = html_writer::link(
            new moodle_url($baseurl, ['action' => 'edit', 'id' => $peer->id]),
            get_string('edit')
        );
        $delete = html_writer::link(
            new moodle_url($baseurl, ['action' => 'delete', 'id' => $peer->id, 'sesskey' => sesskey()]),
            get_string('delete')
        );
        $table->data[] = [
            format_string($peer->name),
            s((string) $peer->baseurl),
            manage_page::peer_check_html($peer),
            "$check &nbsp; $edit &nbsp; $delete",
        ];
    }
    echo html_writer::table($table);
} else {
    echo $OUTPUT->notification(get_string('nopeers', 'repository_largefile'), \core\output\notification::NOTIFY_INFO);
}

echo $OUTPUT->heading($id ? get_string('editpeer', 'repository_largefile') : get_string('addpeer', 'repository_largefile'), 3);
$form->display();
echo $OUTPUT->footer();

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
 * Import a backup shared by a trusted peer into this site's private files.
 *
 * @package    repository_largefile
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use repository_largefile\local\peer_manager;
use repository_largefile\local\share_client;
use repository_largefile\local\manage_page;
use repository_largefile\local\transfer_manager;
use repository_largefile\event\backup_imported;

// Repository plugins are not part of the admin settings tree, so this page stands
// alone: it is reached from the plugin's configuration page and gated by the
// import capability (which a manager can hold without full site config).
require_login();
$context = context_system::instance();
require_capability('repository/largefile:import', $context);

$baseurl = new moodle_url('/repository/largefile/import.php');
manage_page::setup($baseurl, get_string('importshared', 'repository_largefile'));

$peers = peer_manager::menu();
$error = null;
$imported = null;

if ($peers) {
    $form = new \repository_largefile\form\import_form($baseurl->out(false), ['peers' => $peers]);
    if ($data = $form->get_data()) {
        if (!empty($data->background)) {
            // Run the import on the server, immune to the web request timeout that
            // a large backup would otherwise hit. It is picked up by the scheduled
            // task and lands in the user's private files, like the direct import.
            transfer_manager::create(
                transfer_manager::TYPE_SHARE,
                (int) $USER->id,
                ['peerid' => (int) $data->peerid, 'shareurl' => $data->shareurl],
                0
            );
            redirect(
                new moodle_url('/repository/largefile/transfers.php'),
                get_string('importqueued', 'repository_largefile')
            );
        }
        \core_php_time_limit::raise();
        raise_memory_limit(MEMORY_EXTRA);
        try {
            $result = share_client::import((int) $data->peerid, $data->shareurl);
            // Store the recovered backup in the user's private files, ready to restore.
            $fs = get_file_storage();
            $usercontext = context_user::instance($USER->id);
            $filename = $result['filename'];
            if ($fs->file_exists($usercontext->id, 'user', 'private', 0, '/', $filename)) {
                $filename = time() . '-' . $filename;
            }
            $fs->create_file_from_pathname([
                'contextid' => $usercontext->id,
                'component' => 'user',
                'filearea' => 'private',
                'itemid' => 0,
                'filepath' => '/',
                'filename' => $filename,
            ], $result['path']);
            $peer = peer_manager::get((int) $data->peerid);
            backup_imported::build((int) $USER->id, $peer ? $peer->name : '', $filename)->trigger();
            $imported = $filename;
        } catch (\moodle_exception $e) {
            $error = $e->getMessage();
        }
    }
}

echo $OUTPUT->header();
echo manage_page::tabs('import');
echo $OUTPUT->heading(get_string('importshared', 'repository_largefile'));
echo html_writer::tag('p', get_string('importshared_desc', 'repository_largefile'), ['class' => 'text-muted']);

if ($error !== null) {
    echo $OUTPUT->notification($error, \core\output\notification::NOTIFY_ERROR);
}
if ($imported !== null) {
    echo $OUTPUT->notification(
        get_string('importsuccess', 'repository_largefile', s($imported)),
        \core\output\notification::NOTIFY_SUCCESS
    );
}

if (!$peers) {
    echo $OUTPUT->notification(get_string('nopeersforshare', 'repository_largefile'), \core\output\notification::NOTIFY_WARNING);
    echo html_writer::link(
        new moodle_url('/repository/largefile/manage_peers.php'),
        get_string('managepeers', 'repository_largefile')
    );
} else {
    $form->display();
}

echo $OUTPUT->footer();

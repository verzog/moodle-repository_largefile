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
 * Standalone upload page for the Large file repository.
 *
 * The plugin's chunked uploader is normally reached from the file picker inside
 * a course activity's "Choose file" dialogue — a slow step for a large backup
 * whose only use case is to route it through Send to… or Restore… on the
 * Transfers page anyway. This page exposes the same uploader as its own tab so
 * an admin can start a large upload without opening a course activity first.
 * On completion the file lands as a chunk_store row in STATE_COMPLETED, exactly
 * where the Transfers page's Completed uploads section reads it.
 *
 * @package    repository_largefile
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/repository/lib.php');

use repository_largefile\local\import_policy;
use repository_largefile\local\manage_page;

require_login();
$context = context_system::instance();
// This page's whole point is to route a completed upload through the Transfers
// page (Send to… / Restore…), so require the same capability Transfers itself
// requires; without it, both next-step links this page presents would be
// rejected there and the operator would be left with a staged file they
// cannot use. That in turn requires the picker's own gate — the view
// capability, and the picker as an enabled destination site-wide — because
// the uploader stages nothing on either path with the picker off.
require_capability('repository/largefile:view', $context);
require_capability('repository/largefile:import', $context);
if (!import_policy::picker_enabled()) {
    throw new \moodle_exception('errorpickerdisabled', 'repository_largefile');
}
// The upload_ajax.php endpoint also refuses tokens when the repository type is
// disabled or hidden site-wide, so a page that could not actually accept an
// upload should not advertise the tab or the Start-upload button.
$repotype = repository::get_type_by_typename('largefile');
if (!$repotype || !$repotype->get_visible()) {
    throw new \moodle_exception('errorpickerdisabled', 'repository_largefile');
}

$baseurl = new moodle_url('/repository/largefile/upload.php');
manage_page::setup($baseurl, get_string('uploadtab', 'repository_largefile'));

$transfersurl = new moodle_url('/repository/largefile/transfers.php', ['showcompleted' => 1]);

echo $OUTPUT->header();
echo manage_page::tabs('upload');
echo $OUTPUT->heading(get_string('uploadtab', 'repository_largefile'));
echo html_writer::tag('p', get_string('uploadtab_desc', 'repository_largefile'), ['class' => 'text-muted']);

// Start-upload button: opens the same dialogue the file picker's upload event
// does, without going through a picker at all.
echo html_writer::div(
    html_writer::tag(
        'button',
        get_string('uploadtabstart', 'repository_largefile'),
        [
            'type' => 'button',
            'class' => 'btn btn-primary',
            'data-region' => 'largefile-upload-start',
        ]
    ),
    'mb-3'
);
// Persistent link to the Transfers page so a user can navigate on foot, and
// then again after a successful upload (surfaced through a Moodle notification
// on the same page — see the AMD module).
echo html_writer::div(
    html_writer::link($transfersurl, get_string('uploadtabgotransfers', 'repository_largefile')),
    'mb-3'
);

$PAGE->requires->js_call_amd('repository_largefile/upload', 'initStandalone', [[
    'contextId' => $context->id,
    'transfersUrl' => $transfersurl->out(false),
    'trigger' => '[data-region="largefile-upload-start"]',
]]);

echo $OUTPUT->footer();

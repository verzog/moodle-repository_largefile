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
 * The Large file repository: import a file from a URL or a chunked upload.
 *
 * @package    repository_largefile
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/repository/lib.php');

use repository_largefile\chunk_store;

/**
 * The Large file repository.
 *
 * Both ways of bringing in a file — a server-side fetch from a URL, and a
 * chunked browser upload that is not bound by PHP's per-request upload size —
 * end up as a "staged" file on disk (one {repository_largefile_chunks} row plus
 * a file under dataroot). The file picker lists a user's staged files and, when
 * one is selected, {@see get_file()} hands its bytes to the draft area. Because
 * the file arrives through the picker's "download" action rather than a
 * multipart upload, PHP's upload_max_filesize / post_max_size never apply.
 *
 * The upload/URL UI is launched from the file picker's "Upload a file" toolbar
 * button: {@see get_listing()} advertises `uploadfile`/`uploadevent`, the
 * bundled AMD module subscribes to that event and opens a dialogue, and its
 * completion callback re-lists this repository so the new staged file appears.
 *
 * @package    repository_largefile
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class repository_largefile extends repository {
    /** @var string PubSub event the file picker publishes when the upload button is clicked. */
    public const UPLOAD_EVENT = 'repository_largefile_upload';

    /** @var string Prefix distinguishing a staged-file token source from anything else. */
    private const SOURCE_PREFIX = 'largefile:';

    /**
     * Constructor.
     *
     * @param int $repositoryid Repository instance id.
     * @param \stdClass|int $context Context this instance runs in.
     * @param array $options Repository options.
     */
    public function __construct($repositoryid, $context = SYSCONTEXTID, $options = []) {
        global $PAGE;
        parent::__construct($repositoryid, $context, $options);
        // Register the dialogue handler for the file picker's upload button. It is
        // a no-op until the "Upload a file" button publishes the upload event.
        $PAGE->requires->js_call_amd('repository_largefile/upload', 'init');
    }

    /**
     * No interactive login is needed; the picker goes straight to the listing.
     *
     * @return bool Always true.
     */
    public function check_login() {
        return true;
    }

    /**
     * List the current user's staged (completed) large files.
     *
     * @param string $path Ignored; this repository is flat.
     * @param string $page Ignored; the listing is not paged.
     * @return array The file-picker listing, advertising the custom upload flow.
     */
    public function get_listing($path = '', $page = '') {
        global $OUTPUT, $USER;

        $list = [];
        foreach (chunk_store::list_completed((int) $USER->id) as $record) {
            $filename = (string) $record->filename;
            $list[] = [
                'title' => $filename,
                'source' => self::SOURCE_PREFIX . $record->id,
                'size' => (int) $record->length,
                'datemodified' => (int) $record->lastmodified,
                'datecreated' => (int) $record->lastmodified,
                'thumbnail' => $OUTPUT->image_url(file_extension_icon($filename, 64))->out(false),
                'thumbnail_height' => 64,
                'thumbnail_width' => 64,
            ];
        }

        // Only advertise the upload button when the picker is an enabled destination;
        // when it is off, the plugin stages nothing into the picker (the upload
        // dialogue's chunk and URL-fetch paths refuse it too).
        $canupload = \repository_largefile\local\import_policy::picker_enabled();
        $listing = [
            'list' => $list,
            'dynload' => false,
            'nologin' => true,
            'nosearch' => true,
            'norefresh' => false,
            'uploadfile' => $canupload,
            'repo_id' => $this->id,
            'contextid' => $this->context->id,
            'sesskey' => sesskey(),
        ];
        if ($canupload) {
            $listing['uploadevent'] = self::UPLOAD_EVENT;
        }
        return $listing;
    }

    /**
     * Hand a selected staged file to the file picker (which copies it into the
     * draft area). The staged file is moved into the per-request temp directory
     * and its tracking row removed, so it is consumed exactly once.
     *
     * @param string $source The listing item's source (a staged-file token).
     * @param string $filename Filename the picker wants to save the file as.
     * @return array Keys 'path' (temp file to copy in) and 'url'.
     */
    public function get_file($source, $filename = '') {
        global $CFG, $USER;

        $id = $this->token_from_source($source);
        $record = $id !== null ? chunk_store::get_record($id) : null;
        if (!$record || (int) $record->userid !== (int) $USER->id || !chunk_store::is_complete($id)) {
            throw new \moodle_exception('tokenexpired', 'repository_largefile');
        }

        // Hand the staged file to the picker without consuming it. Setting
        // repository_no_delete stops move_to_filepool() unlinking the returned
        // path (the same mechanism repository_filesystem uses), so the original
        // stays put: a selection the picker then rejects — most predictably a
        // non-privileged user whose file exceeds the destination's maxbytes (see
        // README, "Destination size limits") — can be retried without
        // re-uploading, and the cleanup task removes the staged file after its
        // retention window rather than this method deleting it up front.
        $CFG->repository_no_delete = true;
        return ['path' => chunk_store::get_path_for_id($id), 'url' => ''];
    }

    /**
     * Extract the staged-file token from a listing source, or null if it is not
     * one of ours.
     *
     * @param string $source The listing item's source.
     * @return string|null The token id, or null.
     */
    private function token_from_source(string $source): ?string {
        if (strpos($source, self::SOURCE_PREFIX) !== 0) {
            return null;
        }
        $id = substr($source, strlen(self::SOURCE_PREFIX));
        return ($id !== '' && ctype_digit($id)) ? $id : null;
    }

    /**
     * Files are copied into Moodle (not linked), so only the internal return type
     * is supported.
     *
     * @return int The FILE_INTERNAL return type.
     */
    public function supported_returntypes() {
        return FILE_INTERNAL;
    }

    /**
     * Every file type is accepted; the destination form still applies its own
     * accepted-types restriction to the picked file.
     *
     * @return string The "all types" marker.
     */
    public function supported_filetypes() {
        return \repository_largefile\local\import_policy::supported_filetypes();
    }

    /**
     * Staged uploads belong to the user who made them, so this repository holds
     * per-user data.
     *
     * @return bool Always true.
     */
    public function contains_private_data() {
        return true;
    }

    /**
     * Names of the global (type) options this repository stores.
     *
     * Repository plugins do not load settings.php the way most plugins do, so the
     * tunables live on the repository's own configuration page (reached from Site
     * administration > Plugins > Repositories > Large file) via
     * {@see self::type_config_form()}. They are persisted under the bare type name,
     * so they are read back with get_config('largefile', ...).
     *
     * @return array The option names to persist.
     */
    public static function get_type_option_names() {
        return array_merge(
            parent::get_type_option_names(),
            ['chunksize', 'state0duration', 'state1duration', 'state2duration'],
            // Import policy: opt-in type restriction, the accepted kinds, and the
            // destinations an imported file may be routed to.
            ['restricttypes', 'accept_backup', 'accept_scorm', 'accept_imscc', 'accept_video'],
            ['dest_backuparea', 'dest_picker', 'dest_privatefiles']
        );
    }

    /**
     * Add the plugin's global settings (and links to its management pages) to the
     * repository type configuration form.
     *
     * @param object $mform The type configuration form.
     * @param string $classname The repository class name.
     * @return void
     */
    public static function type_config_form($mform, $classname = 'repository') {
        parent::type_config_form($mform, $classname);

        $mform->addElement('header', 'largefilechunkheader', get_string('settings', 'repository_largefile'));

        $mform->addElement('text', 'chunksize', get_string('setting:chunksize', 'repository_largefile'), ['size' => 6]);
        $mform->setType('chunksize', PARAM_INT);
        $mform->setDefault('chunksize', 20);
        $mform->addHelpButton('chunksize', 'setting:chunksize', 'repository_largefile');

        $mform->addElement('duration', 'state0duration', get_string('setting:state0duration', 'repository_largefile'));
        $mform->setDefault('state0duration', 3600);
        $mform->addHelpButton('state0duration', 'setting:state0duration', 'repository_largefile');

        $mform->addElement('duration', 'state1duration', get_string('setting:state1duration', 'repository_largefile'));
        $mform->setDefault('state1duration', 3600);
        $mform->addHelpButton('state1duration', 'setting:state1duration', 'repository_largefile');

        $mform->addElement('duration', 'state2duration', get_string('setting:state2duration', 'repository_largefile'));
        $mform->setDefault('state2duration', 86400);
        $mform->addHelpButton('state2duration', 'setting:state2duration', 'repository_largefile');

        // Import policy: which file kinds are accepted, and where an import may land.
        $mform->addElement('header', 'largefilepolicyheader', get_string('setting:policyheader', 'repository_largefile'));

        $mform->addElement('advcheckbox', 'restricttypes', get_string('setting:restricttypes', 'repository_largefile'));
        $mform->setDefault('restricttypes', 0);
        $mform->addHelpButton('restricttypes', 'setting:restricttypes', 'repository_largefile');

        foreach (['backup', 'scorm', 'imscc', 'video'] as $kind) {
            $mform->addElement(
                'advcheckbox',
                'accept_' . $kind,
                get_string('setting:accept', 'repository_largefile'),
                get_string('filetype_' . $kind, 'repository_largefile')
            );
            $mform->setDefault('accept_' . $kind, 1);
            $mform->hideIf('accept_' . $kind, 'restricttypes', 'notchecked');
        }

        foreach (['backuparea', 'picker', 'privatefiles'] as $dest) {
            $mform->addElement(
                'advcheckbox',
                'dest_' . $dest,
                get_string('setting:destination', 'repository_largefile'),
                get_string('destination_' . $dest, 'repository_largefile')
            );
            $mform->setDefault('dest_' . $dest, 1);
        }
        $mform->addHelpButton('dest_backuparea', 'setting:destination', 'repository_largefile');

        // Links to the plugin's management pages, so everything for this plugin is
        // reached one level under its own configuration page.
        $links = [
            \html_writer::link(
                new \moodle_url('/repository/largefile/manage_peers.php'),
                get_string('managepeers', 'repository_largefile')
            ),
            \html_writer::link(
                new \moodle_url('/repository/largefile/manage_shares.php'),
                get_string('manageshares', 'repository_largefile')
            ),
            \html_writer::link(
                new \moodle_url('/repository/largefile/import.php'),
                get_string('importshared', 'repository_largefile')
            ),
            \html_writer::link(
                new \moodle_url('/repository/largefile/transfers.php'),
                get_string('transfers', 'repository_largefile')
            ),
        ];
        $mform->addElement('header', 'largefilesharingheader', get_string('sharingmanagement', 'repository_largefile'));
        $mform->addElement('static', 'largefilelinks', '', \html_writer::alist($links));
    }
}

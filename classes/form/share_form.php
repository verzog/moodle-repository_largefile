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
 * Create-a-share form: pick a file and a peer, set expiry and a download cap.
 *
 * @package    repository_largefile
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace repository_largefile\form;

defined('MOODLE_INTERNAL') || die();

require_once($GLOBALS['CFG']->libdir . '/formslib.php');

/**
 * Create-a-share form.
 *
 * @package    repository_largefile
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class share_form extends \moodleform {
    /**
     * Define the form.
     *
     * @return void
     */
    protected function definition() {
        $mform = $this->_form;

        $mform->addElement(
            'select',
            'peerid',
            get_string('sharepeer', 'repository_largefile'),
            $this->_customdata['peers']
        );
        $mform->addRule('peerid', get_string('required'), 'required', null, 'client');

        // The file is chosen by reference — either a large file already staged
        // through this plugin's uploader, or a backup already held in Moodle — so
        // nothing large is copied in this request; the background job reads the
        // chosen source directly. A file too big for the browser upload dialogue is
        // uploaded first on the Upload tab, then appears here to select.
        $mform->addElement(
            'autocomplete',
            'sharesource',
            get_string('sharefile', 'repository_largefile'),
            $this->_customdata['sources'] ?? [],
            ['noselectionstring' => get_string('choosedots')]
        );
        $mform->addRule('sharesource', get_string('required'), 'required', null, 'client');
        $mform->addHelpButton('sharesource', 'sharefile', 'repository_largefile');

        $uploadlink = \html_writer::link(
            new \moodle_url('/repository/largefile/upload.php'),
            get_string('sharesourceuploadlink', 'repository_largefile')
        );
        $mform->addElement(
            'static',
            'sharesourcehint',
            '',
            get_string('sharesourcehint', 'repository_largefile', $uploadlink)
        );

        $mform->addElement(
            'duration',
            'expiry',
            get_string('shareexpiry', 'repository_largefile'),
            ['optional' => true]
        );
        $mform->addHelpButton('expiry', 'shareexpiry', 'repository_largefile');
        $mform->setDefault('expiry', DAYSECS);

        $mform->addElement(
            'text',
            'maxdownloads',
            get_string('sharemaxdownloads', 'repository_largefile'),
            ['size' => 6]
        );
        $mform->setType('maxdownloads', PARAM_INT);
        // Three, not one: a download is counted when it starts, so a transfer cut off
        // by a network fault uses one up; a couple of retries must not need re-publishing.
        $mform->setDefault('maxdownloads', 3);
        $mform->addHelpButton('maxdownloads', 'sharemaxdownloads', 'repository_largefile');

        $this->add_action_buttons(true, get_string('createshare', 'repository_largefile'));
    }
}

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
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace repository_largefile\form;

defined('MOODLE_INTERNAL') || die();

require_once($GLOBALS['CFG']->libdir . '/formslib.php');

/**
 * Create-a-share form.
 *
 * @package    repository_largefile
 * @copyright  2026 SCCA
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

        // The file is uploaded through the picker (chunked upload is available in
        // that picker for a backup bigger than the PHP limit).
        $mform->addElement(
            'filepicker',
            'sharefile',
            get_string('sharefile', 'repository_largefile'),
            null,
            ['maxbytes' => 0, 'accepted_types' => '*']
        );
        $mform->addRule('sharefile', get_string('required'), 'required', null, 'client');

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
        $mform->setDefault('maxdownloads', 1);
        $mform->addHelpButton('maxdownloads', 'sharemaxdownloads', 'repository_largefile');

        // Encrypting a large backup in the foreground can exceed the web server's
        // request timeout (a 504). Running it on the server avoids that.
        $mform->addElement('advcheckbox', 'background', get_string('sharepublishbackground', 'repository_largefile'));
        $mform->setDefault('background', 1);
        $mform->addHelpButton('background', 'sharepublishbackground', 'repository_largefile');

        $this->add_action_buttons(true, get_string('createshare', 'repository_largefile'));
    }
}

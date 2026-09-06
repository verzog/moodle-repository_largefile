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
 * Import-a-shared-backup form: a peer and the share link they sent.
 *
 * @package    repository_largefile
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace repository_largefile\form;

defined('MOODLE_INTERNAL') || die();

require_once($GLOBALS['CFG']->libdir . '/formslib.php');

/**
 * Import-a-shared-backup form.
 *
 * @package    repository_largefile
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class import_form extends \moodleform {
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
            get_string('importpeer', 'repository_largefile'),
            $this->_customdata['peers']
        );
        $mform->addRule('peerid', get_string('required'), 'required', null, 'client');

        $mform->addElement('text', 'shareurl', get_string('importurl', 'repository_largefile'), ['size' => 80]);
        $mform->setType('shareurl', PARAM_RAW_TRIMMED);
        $mform->addRule('shareurl', get_string('required'), 'required', null, 'client');
        $mform->addHelpButton('shareurl', 'importurl', 'repository_largefile');

        // Where the recovered file should be stored. Only offered when the site
        // enables more than one destination. "Automatic" (the default, empty value)
        // routes to the file's kind default once fetched; the explicit choices are
        // narrowed to that kind (a backup cannot go to the picker, etc.).
        $destinations = \repository_largefile\local\import_policy::destination_menu();
        if (count($destinations) > 1) {
            $choices = ['' => get_string('destinationauto', 'repository_largefile')] + $destinations;
            $mform->addElement('select', 'destination', get_string('importdestination', 'repository_largefile'), $choices);
            $mform->setDefault('destination', '');
            $mform->addHelpButton('destination', 'importdestination', 'repository_largefile');
        }

        // Importing a large backup in the foreground can exceed the web server's
        // request timeout (a 504). Running it on the server avoids that.
        $mform->addElement('advcheckbox', 'background', get_string('importbackground', 'repository_largefile'));
        $mform->setDefault('background', 1);
        $mform->addHelpButton('background', 'importbackground', 'repository_largefile');

        $this->add_action_buttons(false, get_string('importbutton', 'repository_largefile'));
    }
}

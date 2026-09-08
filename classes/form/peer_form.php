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
 * Add/edit form for a trusted peer.
 *
 * @package    repository_largefile
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace repository_largefile\form;

defined('MOODLE_INTERNAL') || die();

require_once($GLOBALS['CFG']->libdir . '/formslib.php');

/**
 * Add/edit form for a trusted peer.
 *
 * @package    repository_largefile
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class peer_form extends \moodleform {
    /**
     * Define the form.
     *
     * @return void
     */
    protected function definition() {
        $mform = $this->_form;
        $editing = !empty($this->_customdata['id']);

        $mform->addElement('hidden', 'id', 0);
        $mform->setType('id', PARAM_INT);

        $mform->addElement('text', 'name', get_string('peername', 'repository_largefile'), ['size' => 50]);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', get_string('required'), 'required', null, 'client');

        $mform->addElement('text', 'baseurl', get_string('peerurl', 'repository_largefile'), ['size' => 50]);
        $mform->setType('baseurl', PARAM_URL);
        $mform->addRule('baseurl', get_string('required'), 'required', null, 'client');
        $mform->addHelpButton('baseurl', 'peerurl', 'repository_largefile');

        $mform->addElement(
            'textarea',
            'secret',
            get_string('peersecret', 'repository_largefile'),
            ['rows' => 2, 'cols' => 60]
        );
        $mform->setType('secret', PARAM_RAW_TRIMMED);
        $mform->addHelpButton('secret', 'peersecret', 'repository_largefile');
        if (!$editing) {
            $mform->addRule('secret', get_string('required'), 'required', null, 'client');
            // Offer a strong random secret by default (256 bits, hex), so an administrator
            // does not have to invent one — a memorable phrase is far easier to brute-force
            // offline from a captured signed request. The other site pastes this value; or
            // replace it with the one that site generated.
            $mform->setDefault('secret', \repository_largefile\local\crypto::generate_secret());
        }

        $this->add_action_buttons();
    }

    /**
     * Validate the form.
     *
     * @param array $data Submitted data.
     * @param array $files Submitted files.
     * @return array Errors keyed by element name.
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        // A short secret is trivially brute-forceable; require real entropy.
        if (!empty($data['secret']) && strlen(trim($data['secret'])) < 24) {
            $errors['secret'] = get_string('errorsecrettooshort', 'repository_largefile');
        }
        // The site URL fixes the one host exempt from the cURL block, so it must be a
        // real https URL with a host (PARAM_URL alone allows a bare path, and http is
        // refused unless the site opted in — see peer_manager::baseurl_error()).
        $baseurl = trim($data['baseurl'] ?? '');
        $urlerror = $baseurl !== '' ? \repository_largefile\local\peer_manager::baseurl_error($baseurl) : null;
        if ($urlerror !== null) {
            $errors['baseurl'] = get_string($urlerror, 'repository_largefile');
        }
        return $errors;
    }
}

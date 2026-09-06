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
 * Queue-a-transfer form: choose a URL or peer-share import and when to run it.
 *
 * @package    repository_largefile
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace repository_largefile\form;

defined('MOODLE_INTERNAL') || die();

require_once($GLOBALS['CFG']->libdir . '/formslib.php');

use repository_largefile\local\transfer_manager;

/**
 * Queue-a-transfer form.
 *
 * @package    repository_largefile
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class transfer_form extends \moodleform {
    /**
     * Define the form.
     *
     * @return void
     */
    protected function definition() {
        $mform = $this->_form;
        $peers = $this->_customdata['peers'] ?? [];

        $types = [transfer_manager::TYPE_URL => get_string('transfertypeurl', 'repository_largefile')];
        if ($peers) {
            $types[transfer_manager::TYPE_SHARE] = get_string('transfertypeshare', 'repository_largefile');
        }
        $mform->addElement('select', 'type', get_string('transfertype', 'repository_largefile'), $types);

        $mform->addElement('text', 'url', get_string('transferurl', 'repository_largefile'), ['size' => 60]);
        $mform->setType('url', PARAM_RAW_TRIMMED);
        $mform->hideIf('url', 'type', 'neq', transfer_manager::TYPE_URL);

        if ($peers) {
            $mform->addElement('select', 'peerid', get_string('sharepeer', 'repository_largefile'), $peers);
            $mform->hideIf('peerid', 'type', 'neq', transfer_manager::TYPE_SHARE);

            $mform->addElement('text', 'shareurl', get_string('importurl', 'repository_largefile'), ['size' => 60]);
            $mform->setType('shareurl', PARAM_RAW_TRIMMED);
            $mform->hideIf('shareurl', 'type', 'neq', transfer_manager::TYPE_SHARE);
        }

        // Where the fetched file should be stored, when the site offers a choice.
        // The choices are narrowed to the file's kind once it is fetched.
        $destinations = \repository_largefile\local\import_policy::destination_menu();
        if (count($destinations) > 1) {
            $mform->addElement('select', 'destination', get_string('importdestination', 'repository_largefile'), $destinations);
            $mform->addHelpButton('destination', 'importdestination', 'repository_largefile');
        }

        $when = [
            'now' => get_string('transferwhennow', 'repository_largefile'),
            'at' => get_string('transferwhenat', 'repository_largefile'),
        ];
        $mform->addElement('select', 'when', get_string('transferwhen', 'repository_largefile'), $when);
        $mform->addHelpButton('when', 'transferwhen', 'repository_largefile');

        $mform->addElement('date_time_selector', 'scheduledtime', get_string('transferscheduledtime', 'repository_largefile'));
        $mform->setDefault('scheduledtime', time() + DAYSECS);
        $mform->hideIf('scheduledtime', 'when', 'neq', 'at');

        $this->add_action_buttons(true, get_string('transferqueue', 'repository_largefile'));
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
        if (($data['type'] ?? '') === transfer_manager::TYPE_URL) {
            if (empty($data['url'])) {
                $errors['url'] = get_string('required');
            }
        } else if (($data['type'] ?? '') === transfer_manager::TYPE_SHARE) {
            if (empty($data['shareurl'])) {
                $errors['shareurl'] = get_string('required');
            }
        }
        if (($data['when'] ?? 'now') === 'at' && (int) ($data['scheduledtime'] ?? 0) < time()) {
            $errors['scheduledtime'] = get_string('transferscheduledpast', 'repository_largefile');
        }
        return $errors;
    }
}

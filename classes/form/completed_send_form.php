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
 * Send-a-completed-upload form: pick the destination for a staged upload.
 *
 * @package    repository_largefile
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace repository_largefile\form;

defined('MOODLE_INTERNAL') || die();

require_once($GLOBALS['CFG']->libdir . '/formslib.php');

/**
 * Route a completed chunked upload straight to a real destination (private backup
 * area, a course's backup area, or private files) without going through the file
 * picker. Reuses the site's enabled destinations and the same course capability
 * gate the URL import form applies, so the completed-upload flow follows the same
 * policy as an ordinary import.
 *
 * @package    repository_largefile
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class completed_send_form extends \moodleform {
    /**
     * Define the form.
     *
     * @return void
     */
    protected function definition() {
        $mform = $this->_form;

        $mform->addElement('hidden', 'action', 'sendcompleted');
        $mform->setType('action', PARAM_ALPHA);
        $mform->addElement('hidden', 'uploadid', $this->_customdata['uploadid'] ?? '');
        $mform->setType('uploadid', PARAM_ALPHANUM);
        $mform->addElement('hidden', 'confirm', 1);
        $mform->setType('confirm', PARAM_BOOL);

        $mform->addElement(
            'static',
            'file',
            get_string('sharefilecol', 'repository_largefile'),
            format_string((string) ($this->_customdata['filename'] ?? ''))
        );

        // Destinations, filtered to the ones site-enabled *and* suitable for this
        // file's kind (so a backup does not offer the picker, etc). If only one is
        // available it is still shown as static, so the user sees where it goes.
        $destinations = $this->_customdata['destinations'] ?? [];
        if (count($destinations) > 1) {
            $mform->addElement(
                'select',
                'destination',
                get_string('importdestination', 'repository_largefile'),
                $destinations
            );
            $mform->setDefault('destination', array_key_first($destinations));
        } else if ($destinations) {
            $mform->addElement(
                'static',
                'destinationlabel',
                get_string('importdestination', 'repository_largefile'),
                reset($destinations)
            );
            $mform->addElement('hidden', 'destination', array_key_first($destinations));
            $mform->setType('destination', PARAM_ALPHA);
        }

        // The course backup area destination needs a target course; only offer the
        // course picker when that destination is enabled and reachable for the file.
        $coursebackup = \repository_largefile\local\import_policy::DEST_COURSEBACKUP;
        if (array_key_exists($coursebackup, $destinations)) {
            $courseopts = ['requiredcapabilities' => ['moodle/restore:uploadfile']];
            $mform->addElement(
                'course',
                'courseid',
                get_string('importcourse', 'repository_largefile'),
                $courseopts
            );
            if (count($destinations) > 1) {
                $mform->hideIf('courseid', 'destination', 'neq', $coursebackup);
            }
        }

        $this->add_action_buttons(true, get_string('sendcompletedbutton', 'repository_largefile'));
    }

    /**
     * Require a target course when the course backup area destination is chosen.
     *
     * @param array $data Submitted data.
     * @param array $files Submitted files.
     * @return array Errors keyed by element name.
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        $coursebackup = \repository_largefile\local\import_policy::DEST_COURSEBACKUP;
        if (($data['destination'] ?? '') === $coursebackup && empty($data['courseid'])) {
            $errors['courseid'] = get_string('errornocoursechosen', 'repository_largefile');
        }
        return $errors;
    }
}

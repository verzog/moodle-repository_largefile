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
 * Restore-a-completed-upload form: pick the target course to restore the backup into.
 *
 * @package    repository_largefile
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace repository_largefile\form;

defined('MOODLE_INTERNAL') || die();

require_once($GLOBALS['CFG']->libdir . '/formslib.php');

/**
 * Restore a completed chunked upload straight into a course, without the user
 * having to reopen the file picker on the course restore screen. The file is
 * copied into the target course's backup area and Moodle's restore wizard is
 * then loaded on it directly. The course picker is limited to courses the user
 * may both upload a backup into and start a restore in.
 *
 * @package    repository_largefile
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class completed_restore_form extends \moodleform {
    /**
     * Define the form.
     *
     * @return void
     */
    protected function definition() {
        $mform = $this->_form;

        $mform->addElement('hidden', 'action', 'restorecompleted');
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

        // A restore drives both upload and restore, so limit the course picker to
        // courses where the user holds both — offering a course they could not
        // finish the restore in would surface the failure only after the file was
        // already copied in and pathnamehash computed.
        $courseopts = ['requiredcapabilities' => ['moodle/restore:uploadfile', 'moodle/restore:restorecourse']];
        $mform->addElement(
            'course',
            'courseid',
            get_string('restorecompletedcourse', 'repository_largefile'),
            $courseopts
        );
        $mform->addHelpButton('courseid', 'restorecompletedcourse', 'repository_largefile');

        $this->add_action_buttons(true, get_string('restorecompletedbutton', 'repository_largefile'));
    }

    /**
     * Require a target course.
     *
     * @param array $data Submitted data.
     * @param array $files Submitted files.
     * @return array Errors keyed by element name.
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        if (empty($data['courseid'])) {
            $errors['courseid'] = get_string('errornocoursechosen', 'repository_largefile');
        }
        return $errors;
    }
}

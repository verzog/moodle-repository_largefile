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
 * Restore-an-existing-backup form: pick a backup already on the site and a category.
 *
 * @package    repository_largefile
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace repository_largefile\form;

defined('MOODLE_INTERNAL') || die();

require_once($GLOBALS['CFG']->libdir . '/formslib.php');

/**
 * Restore a course backup that is already held in Moodle — in the user's backup
 * area or private files, or a course's backup area they may download from — into a
 * new course, unattended and in the background, like the automatic restore of a
 * completed upload. A very large backup therefore never has to be uploaded again,
 * nor restored through the restore wizard, whose in-browser steps time out.
 *
 * @package    repository_largefile
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class stored_restore_form extends \moodleform {
    /**
     * Define the form.
     *
     * @return void
     */
    protected function definition() {
        $mform = $this->_form;

        $mform->addElement('hidden', 'action', 'restorestored');
        $mform->setType('action', PARAM_ALPHA);

        $mform->addElement(
            'select',
            'source',
            get_string('restorestoredsource', 'repository_largefile'),
            $this->_customdata['sources'] ?? []
        );
        $mform->setType('source', PARAM_RAW_TRIMMED);
        $mform->addHelpButton('source', 'restorestoredsource', 'repository_largefile');

        // Only categories the operator may both create a course in and restore
        // into; the site's default category is preselected when it qualifies.
        $categories = completed_restore_form::auto_restore_categories();
        $mform->addElement(
            'select',
            'categoryid',
            get_string('restoreautocategory', 'repository_largefile'),
            $categories
        );
        $mform->setType('categoryid', PARAM_INT);
        $default = \core_course_category::get_default();
        if ($default && isset($categories[$default->id])) {
            $mform->setDefault('categoryid', $default->id);
        }
        $mform->addHelpButton('categoryid', 'restoreautocategory', 'repository_largefile');

        $this->add_action_buttons(true, get_string('restorecompletedbutton', 'repository_largefile'));
    }

    /**
     * Require a listed backup and an allowed category.
     *
     * @param array $data Submitted data.
     * @param array $files Submitted files.
     * @return array Errors keyed by element name.
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        if (!isset(($this->_customdata['sources'] ?? [])[$data['source'] ?? ''])) {
            $errors['source'] = get_string('errorrestorenofile', 'repository_largefile');
        }
        if (empty($data['categoryid']) || !isset(completed_restore_form::auto_restore_categories()[$data['categoryid']])) {
            $errors['categoryid'] = get_string('errornocategorychosen', 'repository_largefile');
        }
        return $errors;
    }
}

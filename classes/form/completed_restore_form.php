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
 * Restore-a-completed-upload form: pick an existing course, or a category for a new one.
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
 * having to reopen the file picker on the course restore screen. Either the file
 * is copied (in the background) into an existing course's backup area, ready for
 * Moodle's restore wizard, or it is restored unattended into a new course in a
 * chosen category. The pickers only offer courses and categories the user may
 * restore into.
 *
 * @package    repository_largefile
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class completed_restore_form extends \moodleform {
    /** @var string Copy into an existing course's backup area, then open the restore wizard. */
    public const MODE_WIZARD = 'wizard';

    /** @var string Restore unattended into a new course in a chosen category. */
    public const MODE_AUTO = 'auto';

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

        // Two ways to restore: prepare the file in an existing course and open the
        // restore wizard on it (the operator then picks settings), or restore it
        // unattended into a brand-new course with the site's default settings.
        $modes = [
            $mform->createElement(
                'radio',
                'mode',
                '',
                get_string('restoremodewizard', 'repository_largefile'),
                self::MODE_WIZARD
            ),
            $mform->createElement(
                'radio',
                'mode',
                '',
                get_string('restoremodeauto', 'repository_largefile'),
                self::MODE_AUTO
            ),
        ];
        $mform->addGroup($modes, 'modegroup', get_string('restoremode', 'repository_largefile'), '<br>', false);
        $mform->setType('mode', PARAM_ALPHA);
        $mform->setDefault('mode', self::MODE_WIZARD);
        $mform->addHelpButton('modegroup', 'restoremode', 'repository_largefile');

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
        $mform->hideIf('courseid', 'mode', 'neq', self::MODE_WIZARD);

        // Only categories the operator may both create a course in and restore
        // into; the site's default category is preselected when it qualifies.
        $categories = self::auto_restore_categories();
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
        $mform->hideIf('categoryid', 'mode', 'neq', self::MODE_AUTO);

        $this->add_action_buttons(true, get_string('restorecompletedbutton', 'repository_largefile'));
    }

    /**
     * Categories the current user may restore a backup into as a new course.
     *
     * @return array Category id => display name.
     */
    public static function auto_restore_categories(): array {
        $categories = [];
        foreach (\core_course_category::make_categories_list('moodle/course:create') as $id => $name) {
            if (has_capability('moodle/restore:restorecourse', \context_coursecat::instance($id))) {
                $categories[$id] = $name;
            }
        }
        return $categories;
    }

    /**
     * Require a target course (wizard mode) or category (automatic mode).
     *
     * @param array $data Submitted data.
     * @param array $files Submitted files.
     * @return array Errors keyed by element name.
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        if (($data['mode'] ?? '') === self::MODE_AUTO) {
            if (empty($data['categoryid']) || !isset(self::auto_restore_categories()[$data['categoryid']])) {
                $errors['categoryid'] = get_string('errornocategorychosen', 'repository_largefile');
            }
        } else if (empty($data['courseid'])) {
            $errors['courseid'] = get_string('errornocoursechosen', 'repository_largefile');
        }
        return $errors;
    }
}

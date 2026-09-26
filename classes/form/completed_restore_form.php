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

use repository_largefile\local\import_policy;

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
        // unattended into a brand-new course with the site's default settings. Only
        // the ways available here are offered; with just one, no choice is shown.
        $modes = self::available_modes();
        if (count($modes) > 1) {
            $radios = [];
            foreach ($modes as $mode) {
                $radios[] = $mform->createElement(
                    'radio',
                    'mode',
                    '',
                    get_string('restoremode' . $mode, 'repository_largefile'),
                    $mode
                );
            }
            $mform->addGroup($radios, 'modegroup', get_string('restoremode', 'repository_largefile'), '<br>', false);
            $mform->addHelpButton('modegroup', 'restoremode', 'repository_largefile');
        } else {
            $mform->addElement('hidden', 'mode');
        }
        $mform->setType('mode', PARAM_ALPHA);
        $mform->setDefault('mode', reset($modes));

        if (in_array(self::MODE_WIZARD, $modes, true)) {
            // A restore drives both upload and restore, so limit the course picker to
            // courses where the user holds both — offering a course they could not
            // finish the restore in would surface the failure only after the file was
            // already copied in.
            $courseopts = ['requiredcapabilities' => ['moodle/restore:uploadfile', 'moodle/restore:restorecourse']];
            $mform->addElement(
                'course',
                'courseid',
                get_string('restorecompletedcourse', 'repository_largefile'),
                $courseopts
            );
            $mform->addHelpButton('courseid', 'restorecompletedcourse', 'repository_largefile');
            $mform->hideIf('courseid', 'mode', 'neq', self::MODE_WIZARD);
        }

        if (in_array(self::MODE_AUTO, $modes, true)) {
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
        }

        $this->add_action_buttons(true, get_string('restorecompletedbutton', 'repository_largefile'));
    }

    /**
     * The ways the current user may restore a completed backup upload here: the
     * wizard route needs the course backup area destination to be enabled (that is
     * where the file is copied), the automatic route needs a category the user may
     * create and restore a course in.
     *
     * @return string[] MODE_* constants, the wizard first when both are available.
     */
    public static function available_modes(): array {
        $modes = [];
        if (import_policy::is_destination_allowed(import_policy::TYPE_BACKUP, import_policy::DEST_COURSEBACKUP)) {
            $modes[] = self::MODE_WIZARD;
        }
        if (self::auto_restore_categories()) {
            $modes[] = self::MODE_AUTO;
        }
        return $modes;
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
        if (!in_array($data['mode'] ?? '', self::available_modes(), true)) {
            $errors['modegroup'] = get_string('errorrestoremodeunavailable', 'repository_largefile');
        } else if (($data['mode'] ?? '') === self::MODE_AUTO) {
            if (empty($data['categoryid']) || !isset(self::auto_restore_categories()[$data['categoryid']])) {
                $errors['categoryid'] = get_string('errornocategorychosen', 'repository_largefile');
            }
        } else if (empty($data['courseid'])) {
            $errors['courseid'] = get_string('errornocoursechosen', 'repository_largefile');
        }
        return $errors;
    }
}

<?php
// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Upload form for CSV to Course.
 *
 * @package    tool_csvtocourse
 * @copyright  2026 Román Huerta Manrique <lastent@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_csvtocourse\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Form to upload a CSV file and create a course.
 */
class upload_form extends \moodleform {
    /**
     * Form definition.
     */
    protected function definition() {
        $mform = $this->_form;

        // CSV file upload.
        $mform->addElement(
            'filepicker',
            'csvfile',
            get_string('csvfile', 'tool_csvtocourse'),
            null,
            ['accepted_types' => '.csv']
        );
        $mform->addHelpButton('csvfile', 'csvfile', 'tool_csvtocourse');
        $mform->addRule('csvfile', null, 'required', null, 'client');

        // Course full name.
        $mform->addElement(
            'text',
            'coursefullname',
            get_string('coursefullname', 'tool_csvtocourse'),
            ['size' => '50']
        );
        $mform->setType('coursefullname', PARAM_TEXT);
        $mform->addRule('coursefullname', null, 'required', null, 'client');

        // Course short name.
        $mform->addElement(
            'text',
            'courseshortname',
            get_string('courseshortname', 'tool_csvtocourse'),
            ['size' => '30']
        );
        $mform->setType('courseshortname', PARAM_TEXT);
        $mform->addRule('courseshortname', null, 'required', null, 'client');

        // Course category.
        $displaylist = \core_course_category::make_categories_list();
        $mform->addElement(
            'autocomplete',
            'category',
            get_string('coursecategory', 'tool_csvtocourse'),
            $displaylist
        );
        $mform->addRule('category', null, 'required', null, 'client');

        $this->add_action_buttons(true, get_string('createcourse', 'tool_csvtocourse'));
    }

    /**
     * Validate the form data.
     *
     * @param array $data  Form data.
     * @param array $files Uploaded files.
     * @return array Validation errors.
     */
    public function validation($data, $files) {
        global $DB;
        $errors = parent::validation($data, $files);

        // Check for duplicate course shortname.
        if (!empty($data['courseshortname'])) {
            if ($DB->record_exists('course', ['shortname' => $data['courseshortname']])) {
                $errors['courseshortname'] = get_string('shortnametaken', 'tool_csvtocourse');
            }
        }

        return $errors;
    }
}

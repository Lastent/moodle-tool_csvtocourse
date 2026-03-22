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
 * CSV to Course – main page.
 *
 * Displays the upload form and handles CSV → MBZ → restore flow.
 *
 * @package    tool_csvtocourse
 * @copyright  2026 Román Huerta Manrique <lastent@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');

admin_externalpage_setup('tool_csvtocourse');
require_capability('tool/csvtocourse:use', context_system::instance());

$form = new \tool_csvtocourse\form\upload_form();

if ($form->is_cancelled()) {
    redirect(new moodle_url('/admin/tool/csvtocourse/index.php'));
}

if ($data = $form->get_data()) {
    // Parse the uploaded CSV.
    $csvfile = $form->save_temp_file('csvfile');
    if (empty($csvfile)) {
        throw new moodle_exception('invalidcsv', 'tool_csvtocourse');
    }

    try {
        $rows = \tool_csvtocourse\mbz_generator::parse_csv($csvfile);
    } finally {
        @unlink($csvfile);
    }

    if (empty($rows)) {
        throw new moodle_exception('emptycsv', 'tool_csvtocourse');
    }

    // Generate MBZ structure in temp directory.
    $generator = new \tool_csvtocourse\mbz_generator();
    $tempdir = $generator->generate(
        $rows,
        $data->coursefullname,
        $data->courseshortname
    );

    // Restore into a new course.
    try {
        $categoryid = (int)$data->category;
        $newcourseid = \restore_dbops::create_new_course('', '', $categoryid);

        $rc = new \restore_controller(
            $tempdir,
            $newcourseid,
            \backup::INTERACTIVE_NO,
            \backup::MODE_GENERAL,
            $USER->id,
            \backup::TARGET_NEW_COURSE
        );

        if (!$rc->execute_precheck()) {
            $rc->destroy();
            throw new moodle_exception('restorefailed', 'tool_csvtocourse');
        }

        $rc->execute_plan();
        $rc->destroy();
    } catch (\Exception $e) {
        // Clean up temp directory on failure.
        fulldelete($CFG->tempdir . '/backup/' . $tempdir);
        throw new moodle_exception('restorefailed', 'tool_csvtocourse', '', null, $e->getMessage());
    }

    // Clean up and redirect.
    fulldelete($CFG->tempdir . '/backup/' . $tempdir);

    redirect(
        new moodle_url('/course/view.php', ['id' => $newcourseid]),
        get_string('coursecreated', 'tool_csvtocourse'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

// Display the form.
echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('pluginname', 'tool_csvtocourse'));
echo \html_writer::tag(
    'div',
    get_string('csvformat_help', 'tool_csvtocourse'),
    ['class' => 'alert alert-info']
);

// Download sample CSV button.
$sampleurl = new moodle_url('/admin/tool/csvtocourse/download_sample.php', ['sesskey' => sesskey()]);
echo \html_writer::tag(
    'div',
    $OUTPUT->single_button($sampleurl, get_string('downloadsample', 'tool_csvtocourse'), 'get'),
    ['class' => 'mb-3']
);

$form->display();
echo $OUTPUT->footer();

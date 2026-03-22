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
 * Language strings for tool_csvtocourse.
 *
 * @package    tool_csvtocourse
 * @copyright  2026 Román Huerta Manrique <lastent@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'CSV to Course';
$string['csvtocourse:use'] = 'Use CSV to Course';
$string['csvtocourse'] = 'CSV to Course';
$string['csvfile'] = 'CSV file';
$string['csvfile_help'] = 'Upload a CSV file with the course structure. The file must have the following columns: section_id, section_name, activity_type, activity_name, content_text, source_url_path';
$string['coursefullname'] = 'Course full name';
$string['courseshortname'] = 'Course short name';
$string['coursecategory'] = 'Course category';
$string['createcourse'] = 'Create course';
$string['coursecreated'] = 'Course created successfully! Redirecting...';
$string['emptycsv'] = 'The CSV file is empty or has no valid rows.';
$string['invalidcsv'] = 'The CSV file is invalid. Please check the format and required columns.';
$string['restorefailed'] = 'Course restore failed. Please check the CSV content and try again.';
$string['shortnametaken'] = 'This short name is already used by another course.';
$string['csvformat_help'] = 'The CSV file must contain these columns: <strong>section_id</strong>, <strong>section_name</strong>, <strong>activity_type</strong>, <strong>activity_name</strong>, <strong>content_text</strong>, <strong>source_url_path</strong>.<br>
Optional date columns: <strong>date_start</strong>, <strong>date_end</strong>, <strong>date_cutoff</strong> (format: YYYY-MM-DD or YYYY-MM-DD HH:MM). Leave blank for defaults.<br>
Date columns apply to: <em>forum</em> (due/cutoff), <em>assign</em> (open/due/cutoff), <em>quiz</em> (open/close), <em>feedback</em> (open/close).<br>
Supported activity types: <em>label, url, resource, page, forum, assign, quiz, feedback</em>.<br>
Section 0 is the General section. Leave activity_type empty for section-only rows.';
$string['downloadsample'] = 'Download sample CSV';
$string['privacy:metadata'] = 'The CSV to Course plugin does not store any personal data.';

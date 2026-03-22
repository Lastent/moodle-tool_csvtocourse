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
 * MBZ Generator – ports the Python main.py logic to PHP.
 *
 * Generates Moodle backup (MBZ) directory structure from CSV data,
 * ready to be restored via restore_controller.
 *
 * Supported activity types: label, url, resource, page, forum, assign, quiz, feedback.
 * Course format: topics (standard Moodle).
 *
 * @package    tool_csvtocourse
 * @copyright  2026 Román Huerta Manrique <lastent@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_csvtocourse;

/**
 * Generates MBZ backup structure from parsed CSV rows.
 */
class mbz_generator {
    /** @var string Moodle backup null placeholder. */
    const NULL_VALUE = '$@NULL@$';

    /** @var string[] Valid activity types. */
    const VALID_TYPES = ['label', 'url', 'resource', 'page', 'forum', 'assign', 'quiz', 'feedback'];

    /** @var int Current timestamp used throughout generation. */
    private int $now;

    /** @var int Context ID counter. */
    private int $ctxcounter;

    /** @var int Section ID counter. */
    private int $sectionidcounter;

    /** @var int Module ID counter. */
    private int $moduleidcounter;

    /** @var int Activity ID counter. */
    private int $actidcounter;

    /** @var int Grade item ID counter. */
    private int $gradeitemcounter;

    /** @var int Plugin config ID counter. */
    private int $pluginidcounter;

    // Date helper.

    /**
     * Parse a date string into a Unix-timestamp string for Moodle XML.
     *
     * Accepted formats: 'YYYY-MM-DD HH:MM' or 'YYYY-MM-DD'.
     * When only a date is given, $defaulttime is appended (e.g. '00:00' for
     * start dates, '23:59' for end/cutoff dates).
     * Returns '0' for empty/blank strings (meaning "not set").
     *
     * Uses the server's local timezone so dates display correctly in Moodle.
     *
     * @param string $datestr     The date string from the CSV.
     * @param string $defaulttime Default time when only a date is provided.
     * @return string Unix timestamp as string, or '0'.
     */
    public static function parse_date(string $datestr, string $defaulttime = '00:00'): string {
        $s = trim($datestr);
        if ($s === '') {
            return '0';
        }

        // Try full format first: YYYY-MM-DD HH:MM.
        $dt = \DateTime::createFromFormat('!Y-m-d H:i', $s);
        if ($dt !== false && !self::date_has_errors($dt)) {
            return (string)$dt->getTimestamp();
        }

        // Try date-only format: YYYY-MM-DD (append default time).
        $combined = $s . ' ' . $defaulttime;
        $dt = \DateTime::createFromFormat('!Y-m-d H:i', $combined);
        if ($dt !== false && !self::date_has_errors($dt)) {
            return (string)$dt->getTimestamp();
        }

        // Fallback: try strtotime (handles many formats).
        $ts = strtotime($s);
        if ($ts !== false && $ts > 0) {
            return (string)$ts;
        }

        debugging(
            "parse_date: Invalid date format '{$s}' – expected YYYY-MM-DD or YYYY-MM-DD HH:MM",
            DEBUG_DEVELOPER
        );
        return '0';
    }

    /**
     * Check if a DateTime parsed by createFromFormat had warnings/errors.
     *
     * @param \DateTime $dt The DateTime object to check.
     * @return bool True if there were errors or warnings.
     */
    private static function date_has_errors(\DateTime $dt): bool {
        $errors = \DateTime::getLastErrors();
        if ($errors === false) {
            return false;
        }
        return ($errors['warning_count'] > 0 || $errors['error_count'] > 0);
    }

    // CSV parsing.

    /**
     * Parse a CSV file into an array of associative rows.
     *
     * @param string $filepath Path to the CSV file.
     * @return array Array of associative arrays (column => value).
     * @throws \moodle_exception If file cannot be read or has invalid headers.
     */
    public static function parse_csv(string $filepath): array {
        $fh = fopen($filepath, 'r');
        if ($fh === false) {
            throw new \moodle_exception('invalidcsv', 'tool_csvtocourse');
        }

        $header = fgetcsv($fh);
        if ($header === false || empty($header)) {
            fclose($fh);
            throw new \moodle_exception('invalidcsv', 'tool_csvtocourse');
        }

        // Strip BOM from first header field.
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
        $header = array_map('trim', $header);

        // Validate required columns.
        $required = ['section_id', 'section_name', 'activity_type', 'activity_name'];
        foreach ($required as $col) {
            if (!in_array($col, $header)) {
                fclose($fh);
                throw new \moodle_exception('invalidcsv', 'tool_csvtocourse');
            }
        }

        $rows = [];
        while (($data = fgetcsv($fh)) !== false) {
            if (count($data) < count($header)) {
                // Pad short rows.
                $data = array_pad($data, count($header), '');
            }
            $row = [];
            for ($i = 0; $i < count($header); $i++) {
                $row[$header[$i]] = $data[$i] ?? '';
            }
            $rows[] = $row;
        }
        fclose($fh);

        return $rows;
    }

    // XML helpers.

    /**
     * Create a new DOMDocument with a root element.
     *
     * @param string $roottag Root element tag name.
     * @param array  $attrs   Root element attributes.
     * @return array [$doc, $root]
     */
    private function create_doc(string $roottag, array $attrs = []): array {
        $doc = new \DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = true;
        $root = $doc->createElement($roottag);
        foreach ($attrs as $k => $v) {
            $root->setAttribute($k, (string)$v);
        }
        $doc->appendChild($root);
        return [$doc, $root];
    }

    /**
     * Add a sub-element to a parent element.
     *
     * @param \DOMElement $parent Parent element.
     * @param string      $tag    Tag name.
     * @param mixed       $text   Text content (null = no text node).
     * @param array       $attrs  Element attributes.
     * @return \DOMElement The created element.
     */
    private function se(\DOMElement $parent, string $tag, $text = null, array $attrs = []): \DOMElement {
        $doc = $parent->ownerDocument;
        $el = $doc->createElement($tag);
        foreach ($attrs as $k => $v) {
            $el->setAttribute($k, (string)$v);
        }
        if ($text !== null) {
            $el->appendChild($doc->createTextNode((string)$text));
        }
        $parent->appendChild($el);
        return $el;
    }

    // Activity XML builders.

    /**
     * Build label activity XML.
     * @param int $actid Activity ID.
     * @param int $moduleid Module ID.
     * @param int $ctxid Context ID.
     * @param string $name Label name.
     * @param string $intro Label intro.
     * @return string
     */
    private function build_label_xml(
        int $actid,
        int $moduleid,
        int $ctxid,
        string $name,
        string $intro
    ): string {
        [$doc, $root] = $this->create_doc('activity', [
            'id' => $actid, 'moduleid' => $moduleid,
            'modulename' => 'label', 'contextid' => $ctxid,
        ]);
        $lbl = $this->se($root, 'label', null, ['id' => $actid]);
        $this->se($lbl, 'name', $name);
        $this->se($lbl, 'intro', $intro ?: '');
        $this->se($lbl, 'introformat', '1');
        $this->se($lbl, 'timemodified', (string)$this->now);
        return $doc->saveXML();
    }

    /**
     * Build URL activity XML.
     * @param int $actid Activity ID.
     * @param int $moduleid Module ID.
     * @param int $ctxid Context ID.
     * @param string $name URL name.
     * @param string $intro URL intro.
     * @param string $url The URL.
     * @return string
     */
    private function build_url_xml(
        int $actid,
        int $moduleid,
        int $ctxid,
        string $name,
        string $intro,
        string $url
    ): string {
        [$doc, $root] = $this->create_doc('activity', [
            'id' => $actid, 'moduleid' => $moduleid,
            'modulename' => 'url', 'contextid' => $ctxid,
        ]);
        $u = $this->se($root, 'url', null, ['id' => $actid]);
        $this->se($u, 'name', $name);
        $this->se($u, 'intro', $intro ?: '');
        $this->se($u, 'introformat', '1');
        $this->se($u, 'externalurl', $url ?: 'https://example.com');
        $this->se($u, 'display', '0');
        $this->se($u, 'displayoptions', 'a:1:{s:10:"printintro";i:1;}');
        $this->se($u, 'parameters', 'a:0:{}');
        $this->se($u, 'timemodified', (string)$this->now);
        return $doc->saveXML();
    }

    /**
     * Build resource (file) activity XML.
     * @param int $actid Activity ID.
     * @param int $moduleid Module ID.
     * @param int $ctxid Context ID.
     * @param string $name Resource name.
     * @param string $intro Resource intro.
     * @return string
     */
    private function build_resource_xml(
        int $actid,
        int $moduleid,
        int $ctxid,
        string $name,
        string $intro
    ): string {
        [$doc, $root] = $this->create_doc('activity', [
            'id' => $actid, 'moduleid' => $moduleid,
            'modulename' => 'resource', 'contextid' => $ctxid,
        ]);
        $r = $this->se($root, 'resource', null, ['id' => $actid]);
        $this->se($r, 'name', $name);
        $this->se($r, 'intro', $intro ?: '');
        $this->se($r, 'introformat', '1');
        $this->se($r, 'tobemigrated', '0');
        $this->se($r, 'legacyfiles', '0');
        $this->se($r, 'legacyfileslast', self::NULL_VALUE);
        $this->se($r, 'display', '0');
        $this->se($r, 'displayoptions', 'a:1:{s:10:"printintro";i:0;}');
        $this->se($r, 'filterfiles', '0');
        $this->se($r, 'revision', '1');
        $this->se($r, 'timemodified', (string)$this->now);
        return $doc->saveXML();
    }

    /**
     * Build page activity XML.
     * @param int $actid Activity ID.
     * @param int $moduleid Module ID.
     * @param int $ctxid Context ID.
     * @param string $name Page name.
     * @param string $intro Page intro and content.
     * @return string
     */
    private function build_page_xml(
        int $actid,
        int $moduleid,
        int $ctxid,
        string $name,
        string $intro
    ): string {
        [$doc, $root] = $this->create_doc(
            'activity',
            [
                'id' => $actid,
                'moduleid' => $moduleid,
                'modulename' => 'page',
                'contextid' => $ctxid,
            ]
        );
        $p = $this->se($root, 'page', null, ['id' => $actid]);
        $this->se($p, 'name', $name);
        $this->se($p, 'intro', $intro ?: '');
        $this->se($p, 'introformat', '1');
        $this->se($p, 'content', $intro ?: '');
        $this->se($p, 'contentformat', '1');
        $this->se($p, 'legacyfiles', '0');
        $this->se($p, 'legacyfileslast', self::NULL_VALUE);
        $this->se($p, 'display', '5');
        $this->se($p, 'displayoptions', 'a:2:{s:10:"printintro";s:1:"0";s:17:"printlastmodified";s:1:"1";}');
        $this->se($p, 'revision', '1');
        $this->se($p, 'timemodified', (string)$this->now);
        return $doc->saveXML();
    }

    /**
     * Build forum activity XML.
     * @param int $actid Activity ID.
     * @param int $moduleid Module ID.
     * @param int $ctxid Context ID.
     * @param string $name Forum name.
     * @param string $intro Forum intro.
     * @param string $datestart Start date timestamp.
     * @param string $dateend End date timestamp.
     * @param string $datecutoff Cutoff date timestamp.
     * @return string
     */
    private function build_forum_xml(
        int $actid,
        int $moduleid,
        int $ctxid,
        string $name,
        string $intro,
        string $datestart = '0',
        string $dateend = '0',
        string $datecutoff = '0'
    ): string {
        $effectivecutoff = ($datecutoff !== '0') ? $datecutoff : $dateend;
        [$doc, $root] = $this->create_doc('activity', [
            'id' => $actid, 'moduleid' => $moduleid,
            'modulename' => 'forum', 'contextid' => $ctxid,
        ]);
        $f = $this->se($root, 'forum', null, ['id' => $actid]);
        $this->se($f, 'type', 'general');
        $this->se($f, 'name', $name);
        $this->se($f, 'intro', $intro ?: '');
        $this->se($f, 'introformat', '1');
        $this->se($f, 'duedate', $dateend);
        $this->se($f, 'cutoffdate', $effectivecutoff);
        $this->se($f, 'assessed', '0');
        $this->se($f, 'assesstimestart', '0');
        $this->se($f, 'assesstimefinish', '0');
        $this->se($f, 'scale', '0');
        $this->se($f, 'maxbytes', '0');
        $this->se($f, 'maxattachments', '9');
        $this->se($f, 'forcesubscribe', '0');
        $this->se($f, 'trackingtype', '1');
        $this->se($f, 'rsstype', '0');
        $this->se($f, 'rssarticles', '0');
        $this->se($f, 'timemodified', (string)$this->now);
        $this->se($f, 'warnafter', '0');
        $this->se($f, 'blockafter', '0');
        $this->se($f, 'blockperiod', '0');
        $this->se($f, 'completiondiscussions', '0');
        $this->se($f, 'completionreplies', '0');
        $this->se($f, 'completionposts', '0');
        $this->se($f, 'displaywordcount', '0');
        $this->se($f, 'lockdiscussionafter', '0');
        $this->se($f, 'grade_forum', '0');
        $this->se($f, 'discussions');
        $this->se($f, 'subscriptions');
        $this->se($f, 'digests');
        $this->se($f, 'readposts');
        $this->se($f, 'trackedprefs');
        $this->se($f, 'grades');
        return $doc->saveXML();
    }

    /**
     * Build assign activity XML.
     * @param int $actid Activity ID.
     * @param int $moduleid Module ID.
     * @param int $ctxid Context ID.
     * @param string $name Assign name.
     * @param string $intro Assign intro.
     * @param string $datestart Start date timestamp.
     * @param string $dateend End date timestamp.
     * @param string $datecutoff Cutoff date timestamp.
     * @return string
     */
    private function build_assign_xml(
        int $actid,
        int $moduleid,
        int $ctxid,
        string $name,
        string $intro,
        string $datestart = '0',
        string $dateend = '0',
        string $datecutoff = '0'
    ): string {
        // Resolve dates: use provided values or fall back to defaults.
        $allowfrom = ($datestart !== '0') ? $datestart : (string)$this->now;
        $duets = ($dateend !== '0') ? $dateend : (string)($this->now + 7 * 86400);
        $cutoff = ($datecutoff !== '0') ? $datecutoff : $dateend;
        $gradingdue = ($duets !== '0') ? (string)((int)$duets + 7 * 86400) : '0';

        [$doc, $root] = $this->create_doc('activity', [
            'id' => $actid, 'moduleid' => $moduleid,
            'modulename' => 'assign', 'contextid' => $ctxid,
        ]);
        $a = $this->se($root, 'assign', null, ['id' => $actid]);
        $this->se($a, 'name', $name);
        $this->se($a, 'intro', $intro ?: '');
        $this->se($a, 'introformat', '1');
        $this->se($a, 'alwaysshowdescription', '1');
        $this->se($a, 'submissiondrafts', '0');
        $this->se($a, 'sendnotifications', '0');
        $this->se($a, 'sendlatenotifications', '0');
        $this->se($a, 'sendstudentnotifications', '1');
        $this->se($a, 'duedate', $duets);
        $this->se($a, 'cutoffdate', $cutoff);
        $this->se($a, 'gradingduedate', $gradingdue);
        $this->se($a, 'allowsubmissionsfromdate', $allowfrom);
        $this->se($a, 'grade', '100');
        $this->se($a, 'timemodified', (string)$this->now);
        $this->se($a, 'completionsubmit', '1');
        $this->se($a, 'requiresubmissionstatement', '0');
        $this->se($a, 'teamsubmission', '0');
        $this->se($a, 'requireallteammemberssubmit', '0');
        $this->se($a, 'teamsubmissiongroupingid', '0');
        $this->se($a, 'blindmarking', '0');
        $this->se($a, 'hidegrader', '0');
        $this->se($a, 'revealidentities', '0');
        $this->se($a, 'attemptreopenmethod', 'untilpass');
        $this->se($a, 'maxattempts', '1');
        $this->se($a, 'markingworkflow', '0');
        $this->se($a, 'markingallocation', '0');
        $this->se($a, 'markinganonymous', '0');
        $this->se($a, 'preventsubmissionnotingroup', '0');
        $this->se($a, 'activity', '');
        $this->se($a, 'activityformat', '1');
        $this->se($a, 'timelimit', '0');
        $this->se($a, 'submissionattachments', '0');
        $this->se($a, 'gradepenalty', '0');
        $this->se($a, 'userflags');
        $this->se($a, 'submissions');
        $this->se($a, 'grades');

        // Plugin configs.
        $pc = $this->se($a, 'plugin_configs');
        $configs = [
            ['onlinetext', 'assignsubmission', 'enabled', '1'],
            ['onlinetext', 'assignsubmission', 'wordlimit', '0'],
            ['onlinetext', 'assignsubmission', 'wordlimitenabled', '0'],
            ['file', 'assignsubmission', 'enabled', '1'],
            ['file', 'assignsubmission', 'maxfilesubmissions', '20'],
            ['file', 'assignsubmission', 'maxsubmissionsizebytes', '0'],
            ['file', 'assignsubmission', 'filetypeslist', ''],
            ['comments', 'assignsubmission', 'enabled', '1'],
            ['comments', 'assignfeedback', 'enabled', '1'],
            ['comments', 'assignfeedback', 'commentinline', '0'],
            ['editpdf', 'assignfeedback', 'enabled', '1'],
            ['offline', 'assignfeedback', 'enabled', '0'],
            ['file', 'assignfeedback', 'enabled', '0'],
        ];
        foreach ($configs as [$plugin, $subtype, $cfgname, $cfgval]) {
            $pid = $this->pluginidcounter++;
            $pcfg = $this->se($pc, 'plugin_config', null, ['id' => $pid]);
            $this->se($pcfg, 'plugin', $plugin);
            $this->se($pcfg, 'subtype', $subtype);
            $this->se($pcfg, 'name', $cfgname);
            $this->se($pcfg, 'value', $cfgval);
        }

        $this->se($a, 'overrides');
        return $doc->saveXML();
    }

    /**
     * Build quiz activity XML.
     * @param int $actid Activity ID.
     * @param int $moduleid Module ID.
     * @param int $ctxid Context ID.
     * @param string $name Quiz name.
     * @param string $intro Quiz intro.
     * @param string $datestart Start date timestamp.
     * @param string $dateend End date timestamp.
     * @return string
     */
    private function build_quiz_xml(
        int $actid,
        int $moduleid,
        int $ctxid,
        string $name,
        string $intro,
        string $datestart = '0',
        string $dateend = '0'
    ): string {
        [$doc, $root] = $this->create_doc('activity', [
            'id' => $actid, 'moduleid' => $moduleid,
            'modulename' => 'quiz', 'contextid' => $ctxid,
        ]);
        $q = $this->se($root, 'quiz', null, ['id' => $actid]);
        $this->se($q, 'name', $name);
        $this->se($q, 'intro', $intro ?: '');
        $this->se($q, 'introformat', '1');
        $this->se($q, 'timeopen', $datestart);
        $this->se($q, 'timeclose', $dateend);
        $this->se($q, 'timelimit', '0');
        $this->se($q, 'overduehandling', 'autosubmit');
        $this->se($q, 'graceperiod', '0');
        $this->se($q, 'preferredbehaviour', 'deferredfeedback');
        $this->se($q, 'canredoquestions', '0');
        $this->se($q, 'attempts_number', '0');
        $this->se($q, 'attemptonlast', '0');
        $this->se($q, 'grademethod', '1');
        $this->se($q, 'decimalpoints', '2');
        $this->se($q, 'questiondecimalpoints', '-1');
        $this->se($q, 'reviewattempt', '69904');
        $this->se($q, 'reviewcorrectness', '69904');
        $this->se($q, 'reviewmaxmarks', '69904');
        $this->se($q, 'reviewmarks', '69904');
        $this->se($q, 'reviewspecificfeedback', '69904');
        $this->se($q, 'reviewgeneralfeedback', '69904');
        $this->se($q, 'reviewrightanswer', '69904');
        $this->se($q, 'reviewoverallfeedback', '4368');
        $this->se($q, 'questionsperpage', '1');
        $this->se($q, 'navmethod', 'free');
        $this->se($q, 'shuffleanswers', '1');
        $this->se($q, 'sumgrades', '0.00000');
        $this->se($q, 'grade', '10.00000');
        $this->se($q, 'timecreated', (string)$this->now);
        $this->se($q, 'timemodified', (string)$this->now);
        $this->se($q, 'password', '');
        $this->se($q, 'subnet', '');
        $this->se($q, 'browsersecurity', '-');
        $this->se($q, 'delay1', '0');
        $this->se($q, 'delay2', '0');
        $this->se($q, 'showuserpicture', '0');
        $this->se($q, 'showblocks', '0');
        $this->se($q, 'completionattemptsexhausted', '0');
        $this->se($q, 'completionminattempts', '0');
        $this->se($q, 'allowofflineattempts', '0');
        $this->se($q, 'question_instances');
        $this->se($q, 'feedbacks');
        $this->se($q, 'overrides');
        $this->se($q, 'grades');
        $this->se($q, 'attempts');
        return $doc->saveXML();
    }

    /**
     * Build feedback activity XML.
     * @param int $actid Activity ID.
     * @param int $moduleid Module ID.
     * @param int $ctxid Context ID.
     * @param string $name Feedback name.
     * @param string $intro Feedback intro.
     * @param string $datestart Start date timestamp.
     * @param string $dateend End date timestamp.
     * @return string
     */
    private function build_feedback_xml(
        int $actid,
        int $moduleid,
        int $ctxid,
        string $name,
        string $intro,
        string $datestart = '0',
        string $dateend = '0'
    ): string {
        [$doc, $root] = $this->create_doc('activity', [
            'id' => $actid, 'moduleid' => $moduleid,
            'modulename' => 'feedback', 'contextid' => $ctxid,
        ]);
        $f = $this->se($root, 'feedback', null, ['id' => $actid]);
        $this->se($f, 'name', $name);
        $this->se($f, 'intro', $intro ?: '');
        $this->se($f, 'introformat', '1');
        $this->se($f, 'anonymous', '1');
        $this->se($f, 'email_notification', '0');
        $this->se($f, 'multiple_submit', '0');
        $this->se($f, 'autonumbering', '1');
        $this->se($f, 'site_after_submit', '');
        $this->se($f, 'page_after_submit', '');
        $this->se($f, 'page_after_submitformat', '1');
        $this->se($f, 'publish_stats', '0');
        $this->se($f, 'timeopen', $datestart);
        $this->se($f, 'timeclose', $dateend);
        $this->se($f, 'timemodified', (string)$this->now);
        $this->se($f, 'completionsubmit', '0');
        $this->se($f, 'items');
        $this->se($f, 'completeds');
        return $doc->saveXML();
    }

    // ─────────────────────────────────────────────────────
    // Module XML builder (common for all activity types)
    // ─────────────────────────────────────────────────────

    /**
     * Build module.xml for an activity.
     * @param int $moduleid Module ID.
     * @param string $modname Module name.
     * @param int $sectionid Section ID.
     * @param int $sectionnumber Section number.
     * @return string
     */
    private function build_module_xml(
        int $moduleid,
        string $modname,
        int $sectionid,
        int $sectionnumber
    ): string {
        [$doc, $root] = $this->create_doc('module', [
            'id' => $moduleid, 'version' => (string)$this->get_backup_version(),
        ]);
        $this->se($root, 'modulename', $modname);
        $this->se($root, 'sectionid', (string)$sectionid);
        $this->se($root, 'sectionnumber', (string)$sectionnumber);
        $this->se($root, 'idnumber', '');
        $this->se($root, 'added', (string)$this->now);
        $this->se($root, 'score', '0');
        $this->se($root, 'indent', '0');
        $this->se($root, 'visible', '1');
        $this->se($root, 'visibleoncoursepage', '1');
        $this->se($root, 'visibleold', '1');
        $this->se($root, 'groupmode', '0');
        $this->se($root, 'groupingid', '0');
        $this->se($root, 'completion', '0');
        $this->se($root, 'completiongradeitemnumber', self::NULL_VALUE);
        $this->se($root, 'completionpassgrade', '0');
        $this->se($root, 'completionview', '0');
        $this->se($root, 'completionexpected', '0');
        $this->se($root, 'availability', self::NULL_VALUE);
        $this->se($root, 'showdescription', $modname === 'label' ? '1' : '0');
        $this->se($root, 'downloadcontent', '1');
        $this->se($root, 'lang', '');
        $this->se($root, 'tags');
        return $doc->saveXML();
    }

    // ─────────────────────────────────────────────────────
    // Auxiliary per-activity XML builders
    // ─────────────────────────────────────────────────────

    /**
     * Empty grades XML.
     */
    private function empty_grades_xml(): string {
        [$doc, $root] = $this->create_doc('activity_gradebook');
        $this->se($root, 'grade_items');
        $this->se($root, 'grade_letters');
        return $doc->saveXML();
    }

    /**
     * Grades XML for activities with grading (assign, quiz).
     * @param int $giid Grade item ID.
     * @param int $gradecatid Grade category ID.
     * @param int $actid Activity ID.
     * @param string $name Grade item name.
     * @return string
     */
    private function assign_grades_xml(
        int $giid,
        int $gradecatid,
        int $actid,
        string $name
    ): string {
        [$doc, $root] = $this->create_doc('activity_gradebook');
        $gi = $this->se($root, 'grade_items');
        $item = $this->se($gi, 'grade_item', null, ['id' => $giid]);
        $this->se($item, 'categoryid', (string)$gradecatid);
        $this->se($item, 'itemname', $name);
        $this->se($item, 'itemtype', 'mod');
        $this->se($item, 'itemmodule', 'assign');
        $this->se($item, 'iteminstance', (string)$actid);
        $this->se($item, 'itemnumber', '0');
        $this->se($item, 'iteminfo', self::NULL_VALUE);
        $this->se($item, 'idnumber', '');
        $this->se($item, 'calculation', self::NULL_VALUE);
        $this->se($item, 'gradetype', '1');
        $this->se($item, 'grademax', '100.00000');
        $this->se($item, 'grademin', '0.00000');
        $this->se($item, 'scaleid', self::NULL_VALUE);
        $this->se($item, 'outcomeid', self::NULL_VALUE);
        $this->se($item, 'gradepass', '0.00000');
        $this->se($item, 'multfactor', '1.00000');
        $this->se($item, 'plusfactor', '0.00000');
        $this->se($item, 'aggregationcoef', '0.00000');
        $this->se($item, 'aggregationcoef2', '1.00000');
        $this->se($item, 'weightoverride', '0');
        $this->se($item, 'sortorder', '2');
        $this->se($item, 'display', '0');
        $this->se($item, 'decimals', self::NULL_VALUE);
        $this->se($item, 'hidden', '0');
        $this->se($item, 'locked', '0');
        $this->se($item, 'locktime', '0');
        $this->se($item, 'needsupdate', '0');
        $this->se($item, 'timecreated', (string)$this->now);
        $this->se($item, 'timemodified', (string)$this->now);
        $this->se($item, 'grade_grades');
        $this->se($root, 'grade_letters');
        return $doc->saveXML();
    }

    /**
     * Empty grade history XML.
     */
    private function empty_grade_history_xml(): string {
        [$doc, $root] = $this->create_doc('grade_history');
        $this->se($root, 'grade_grades');
        return $doc->saveXML();
    }

    /**
     * Empty roles XML.
     */
    private function empty_roles_xml(): string {
        [$doc, $root] = $this->create_doc('roles');
        $this->se($root, 'role_overrides');
        $this->se($root, 'role_assignments');
        return $doc->saveXML();
    }

    /**
     * Empty inforef XML.
     */
    private function empty_inforef_xml(): string {
        [$doc, $root] = $this->create_doc('inforef');
        return $doc->saveXML();
    }

    /**
     * Inforef XML with grade item reference.
     * @param int $giid Grade item ID.
     * @return string
     */
    private function gradeitem_inforef_xml(int $giid): string {
        [$doc, $root] = $this->create_doc('inforef');
        $ref = $this->se($root, 'grade_itemref');
        $gi = $this->se($ref, 'grade_item');
        $this->se($gi, 'id', (string)$giid);
        return $doc->saveXML();
    }

    /**
     * Grading XML for assign activities.
     * @param int $actid Activity ID.
     * @return string
     */
    private function assign_grading_xml(int $actid): string {
        [$doc, $root] = $this->create_doc('areas');
        $area = $this->se($root, 'area', null, ['id' => $actid]);
        $this->se($area, 'areaname', 'submissions');
        $this->se($area, 'activemethod', self::NULL_VALUE);
        $this->se($area, 'definitions');
        return $doc->saveXML();
    }

    // ─────────────────────────────────────────────────────
    // Course-level XML builders
    // ─────────────────────────────────────────────────────

    /**
     * Build course/course.xml.
     * @param int $courseid Course ID.
     * @param int $ctxid Context ID.
     * @param string $fullname Course full name.
     * @param string $shortname Course short name.
     * @return string
     */
    private function build_course_xml(
        int $courseid,
        int $ctxid,
        string $fullname,
        string $shortname
    ): string {
        $end = (string)($this->now + 365 * 86400);
        [$doc, $root] = $this->create_doc('course', [
            'id' => $courseid, 'contextid' => $ctxid,
        ]);
        $this->se($root, 'shortname', $shortname);
        $this->se($root, 'fullname', $fullname);
        $this->se($root, 'idnumber', '');
        $this->se($root, 'summary', '');
        $this->se($root, 'summaryformat', '1');
        $this->se($root, 'format', 'topics');
        $this->se($root, 'showgrades', '1');
        $this->se($root, 'newsitems', '0');
        $this->se($root, 'startdate', (string)$this->now);
        $this->se($root, 'enddate', $end);
        $this->se($root, 'marker', '0');
        $this->se($root, 'maxbytes', '0');
        $this->se($root, 'legacyfiles', '0');
        $this->se($root, 'showreports', '0');
        $this->se($root, 'visible', '1');
        $this->se($root, 'groupmode', '0');
        $this->se($root, 'groupmodeforce', '0');
        $this->se($root, 'defaultgroupingid', '0');
        $this->se($root, 'lang', '');
        $this->se($root, 'theme', '');
        $this->se($root, 'timecreated', (string)$this->now);
        $this->se($root, 'timemodified', (string)$this->now);
        $this->se($root, 'requested', '0');
        $this->se($root, 'showactivitydates', '1');
        $this->se($root, 'showcompletionconditions', '1');
        $this->se($root, 'pdfexportfont', self::NULL_VALUE);
        $this->se($root, 'enablecompletion', '1');
        $this->se($root, 'completionnotify', '0');
        $cat = $this->se($root, 'category', null, ['id' => '1']);
        $this->se($cat, 'name', 'Default');
        $this->se($cat, 'description', '');
        $this->se($root, 'tags');
        $this->se($root, 'customfields');

        // Course format options (topics).
        $opts = $this->se($root, 'courseformatoptions');
        $opt1 = $this->se($opts, 'courseformatoption');
        $this->se($opt1, 'format', 'topics');
        $this->se($opt1, 'sectionid', '0');
        $this->se($opt1, 'name', 'coursedisplay');
        $this->se($opt1, 'value', '0');
        $opt2 = $this->se($opts, 'courseformatoption');
        $this->se($opt2, 'format', 'topics');
        $this->se($opt2, 'sectionid', '0');
        $this->se($opt2, 'name', 'hiddensections');
        $this->se($opt2, 'value', '1');
        return $doc->saveXML();
    }

    /**
     * Build course/enrolments.xml.
     */
    private function build_enrolments_xml(): string {
        [$doc, $root] = $this->create_doc('enrolments');
        $enrols = $this->se($root, 'enrols');

        // Manual enrolment.
        $this->add_enrol($enrols, 1, 'manual', 0, 5, ['customint1' => '1']);
        // Guest enrolment.
        $this->add_enrol($enrols, 2, 'guest', 1, 0, [], '');
        // Self enrolment.
        $this->add_enrol($enrols, 3, 'self', 1, 5, [
            'customint1' => '0', 'customint2' => '0', 'customint3' => '0',
            'customint4' => '1', 'customint5' => '0', 'customint6' => '1',
        ]);

        return $doc->saveXML();
    }

    /**
     * Helper to add an enrolment method element.
     * @param \DOMElement $parent The parent DOM element.
     * @param int $eid Enrolment ID.
     * @param string $method Enrolment method name.
     * @param int $status Enrolment status.
     * @param int $roleid Role ID.
     * @param array $extra Extra attributes.
     * @param string|null $password Optional enrolment password.
     */
    private function add_enrol(
        \DOMElement $parent,
        int $eid,
        string $method,
        int $status,
        int $roleid,
        array $extra = [],
        ?string $password = null
    ): void {
        $e = $this->se($parent, 'enrol', null, ['id' => $eid]);
        $this->se($e, 'enrol', $method);
        $this->se($e, 'status', (string)$status);
        $this->se($e, 'name', self::NULL_VALUE);
        $this->se($e, 'enrolperiod', '0');
        $this->se($e, 'enrolstartdate', '0');
        $this->se($e, 'enrolenddate', '0');
        $this->se($e, 'expirynotify', '0');
        $this->se($e, 'expirythreshold', '86400');
        $this->se($e, 'notifyall', '0');
        $this->se($e, 'password', $password !== null ? $password : self::NULL_VALUE);
        $this->se($e, 'cost', self::NULL_VALUE);
        $this->se($e, 'currency', self::NULL_VALUE);
        $this->se($e, 'roleid', (string)$roleid);
        for ($i = 1; $i <= 8; $i++) {
            $key = "customint{$i}";
            $this->se($e, $key, $extra[$key] ?? self::NULL_VALUE);
        }
        for ($i = 1; $i <= 3; $i++) {
            $this->se($e, "customchar{$i}", self::NULL_VALUE);
        }
        for ($i = 1; $i <= 2; $i++) {
            $this->se($e, "customdec{$i}", self::NULL_VALUE);
        }
        for ($i = 1; $i <= 4; $i++) {
            $this->se($e, "customtext{$i}", self::NULL_VALUE);
        }
        $this->se($e, 'timecreated', (string)$this->now);
        $this->se($e, 'timemodified', (string)$this->now);
        $this->se($e, 'user_enrolments');
    }

    /**
     * Build course/roles.xml.
     */
    private function build_course_roles_xml(): string {
        [$doc, $root] = $this->create_doc('roles');
        $this->se($root, 'role_overrides');
        $this->se($root, 'role_assignments');
        return $doc->saveXML();
    }

    /**
     * Build course/inforef.xml.
     */
    private function build_course_inforef_xml(): string {
        [$doc, $root] = $this->create_doc('inforef');
        $ref = $this->se($root, 'roleref');
        $r = $this->se($ref, 'role');
        $this->se($r, 'id', '5');
        return $doc->saveXML();
    }

    /**
     * Build course/completiondefaults.xml.
     */
    private function build_completiondefaults_xml(): string {
        [$doc, $root] = $this->create_doc('course_completion_defaults');
        return $doc->saveXML();
    }

    // ─────────────────────────────────────────────────────
    // Root-level XML builders
    // ─────────────────────────────────────────────────────

    /**
     * Build files.xml (empty – no embedded files).
     */
    private function build_files_xml(): string {
        [$doc, $root] = $this->create_doc('files');
        return $doc->saveXML();
    }

    /**
     * Build completion.xml.
     */
    private function build_completion_xml(): string {
        [$doc, $root] = $this->create_doc('course_completion');
        return $doc->saveXML();
    }

    /**
     * Build scales.xml.
     */
    private function build_scales_xml(): string {
        [$doc, $root] = $this->create_doc('scales_definition');
        return $doc->saveXML();
    }

    /**
     * Build outcomes.xml.
     */
    private function build_outcomes_xml(): string {
        [$doc, $root] = $this->create_doc('outcomes_definition');
        return $doc->saveXML();
    }

    /**
     * Build questions.xml.
     */
    private function build_questions_xml(): string {
        [$doc, $root] = $this->create_doc('question_categories');
        return $doc->saveXML();
    }

    /**
     * Build groups.xml.
     */
    private function build_groups_xml(): string {
        [$doc, $root] = $this->create_doc('groups');
        $this->se($root, 'groupcustomfields');
        $gs = $this->se($root, 'groupings');
        $this->se($gs, 'groupingcustomfields');
        return $doc->saveXML();
    }

    /**
     * Build roles.xml (root-level roles definition).
     */
    private function build_roles_definition_xml(): string {
        [$doc, $root] = $this->create_doc('roles_definition');
        $r = $this->se($root, 'role', null, ['id' => '5']);
        $this->se($r, 'name', '');
        $this->se($r, 'shortname', 'student');
        $this->se($r, 'nameincourse', self::NULL_VALUE);
        $this->se($r, 'description', '');
        $this->se($r, 'sortorder', '5');
        $this->se($r, 'archetype', 'student');
        return $doc->saveXML();
    }

    /**
     * Build grade_history.xml (root level).
     */
    private function build_grade_history_root_xml(): string {
        [$doc, $root] = $this->create_doc('grade_history');
        $this->se($root, 'grade_grades');
        return $doc->saveXML();
    }

    /**
     * Build gradebook.xml.
     * @param int $gradecatid Grade category ID.
     * @param int $giid Grade item ID.
     * @return string
     */
    private function build_gradebook_xml(
        int $gradecatid,
        int $giid
    ): string {
        [$doc, $root] = $this->create_doc('gradebook');
        $this->se($root, 'attributes');

        // Grade category.
        $cats = $this->se($root, 'grade_categories');
        $cat = $this->se($cats, 'grade_category', null, ['id' => $gradecatid]);
        $this->se($cat, 'parent', self::NULL_VALUE);
        $this->se($cat, 'depth', '1');
        $this->se($cat, 'path', "/{$gradecatid}/");
        $this->se($cat, 'fullname', '?');
        $this->se($cat, 'aggregation', '13');
        $this->se($cat, 'keephigh', '0');
        $this->se($cat, 'droplow', '0');
        $this->se($cat, 'aggregateonlygraded', '1');
        $this->se($cat, 'aggregateoutcomes', '0');
        $this->se($cat, 'timecreated', (string)$this->now);
        $this->se($cat, 'timemodified', (string)$this->now);
        $this->se($cat, 'hidden', '0');

        // Course grade item.
        $items = $this->se($root, 'grade_items');
        $item = $this->se($items, 'grade_item', null, ['id' => $giid]);
        $this->se($item, 'categoryid', self::NULL_VALUE);
        $this->se($item, 'itemname', self::NULL_VALUE);
        $this->se($item, 'itemtype', 'course');
        $this->se($item, 'itemmodule', self::NULL_VALUE);
        $this->se($item, 'iteminstance', (string)$gradecatid);
        $this->se($item, 'itemnumber', self::NULL_VALUE);
        $this->se($item, 'iteminfo', self::NULL_VALUE);
        $this->se($item, 'idnumber', self::NULL_VALUE);
        $this->se($item, 'calculation', self::NULL_VALUE);
        $this->se($item, 'gradetype', '1');
        $this->se($item, 'grademax', '100.00000');
        $this->se($item, 'grademin', '0.00000');
        $this->se($item, 'scaleid', self::NULL_VALUE);
        $this->se($item, 'outcomeid', self::NULL_VALUE);
        $this->se($item, 'gradepass', '0.00000');
        $this->se($item, 'multfactor', '1.00000');
        $this->se($item, 'plusfactor', '0.00000');
        $this->se($item, 'aggregationcoef', '0.00000');
        $this->se($item, 'aggregationcoef2', '0.00000');
        $this->se($item, 'weightoverride', '0');
        $this->se($item, 'sortorder', '1');
        $this->se($item, 'display', '0');
        $this->se($item, 'decimals', self::NULL_VALUE);
        $this->se($item, 'hidden', '0');
        $this->se($item, 'locked', '0');
        $this->se($item, 'locktime', '0');
        $this->se($item, 'needsupdate', '0');
        $this->se($item, 'timecreated', (string)$this->now);
        $this->se($item, 'timemodified', (string)$this->now);
        $this->se($item, 'grade_grades');

        $this->se($root, 'grade_letters');
        $settings = $this->se($root, 'grade_settings');
        $s = $this->se($settings, 'grade_setting', null, ['id' => '']);
        $this->se($s, 'name', 'minmaxtouse');
        $this->se($s, 'value', '1');
        return $doc->saveXML();
    }

    /**
     * Build section XML.
     * @param int $sectionid Section ID.
     * @param int $sectionnumber Section sequence number.
     * @param string $name Section name.
     * @param array $sequenceids Array of module IDs in this section.
     * @return string
     */
    private function build_section_xml(
        int $sectionid,
        int $sectionnumber,
        string $name,
        array $sequenceids
    ): string {
        [$doc, $root] = $this->create_doc('section', ['id' => $sectionid]);
        $this->se($root, 'number', (string)$sectionnumber);
        $this->se($root, 'name', $name);
        $this->se($root, 'summary', '');
        $this->se($root, 'summaryformat', '1');
        $this->se($root, 'sequence', implode(',', $sequenceids));
        $this->se($root, 'visible', '1');
        $this->se($root, 'availabilityjson', self::NULL_VALUE);
        $this->se($root, 'component', self::NULL_VALUE);
        $this->se($root, 'itemid', self::NULL_VALUE);
        $this->se($root, 'timemodified', (string)$this->now);
        return $doc->saveXML();
    }

    /**
     * Build section inforef.xml (empty).
     */
    private function build_section_inforef_xml(): string {
        [$doc, $root] = $this->create_doc('inforef');
        return $doc->saveXML();
    }

    // ─────────────────────────────────────────────────────
    // moodle_backup.xml builder
    // ─────────────────────────────────────────────────────

    /**
     * Build the moodle_backup.xml descriptor.
     * @param int $courseid Course ID.
     * @param int $ctxid Context ID.
     * @param string $fullname Course full name.
     * @param string $shortname Course short name.
     * @param array $activitiesinfo Array of activities metadata.
     * @param array $sectionsinfo Array of sections metadata.
     * @return string
     */
    private function build_moodle_backup_xml(
        int $courseid,
        int $ctxid,
        string $fullname,
        string $shortname,
        array $activitiesinfo,
        array $sectionsinfo
    ): string {
        global $CFG;

        $backupversion = $this->get_backup_version();
        $backuprelease = $this->get_backup_release();
        $moodleversion = $CFG->version ?? $backupversion;
        $moodlerelease = $CFG->release ?? $backuprelease;

        [$doc, $root] = $this->create_doc('moodle_backup');
        $info = $this->se($root, 'information');

        $backupname = 'backup-moodle2-course-' . $courseid . '-'
            . str_replace(' ', '_', $shortname) . '.mbz';

        $this->se($info, 'name', $backupname);
        $this->se($info, 'moodle_version', (string)$moodleversion);
        $this->se($info, 'moodle_release', (string)$moodlerelease);
        $this->se($info, 'backup_version', (string)$backupversion);
        $this->se($info, 'backup_release', (string)$backuprelease);
        $this->se($info, 'backup_date', (string)$this->now);
        $this->se($info, 'mnet_remoteusers', '0');
        $this->se($info, 'include_files', '0');
        $this->se($info, 'include_file_references_to_external_content', '0');
        $this->se($info, 'original_wwwroot', $CFG->wwwroot ?? 'https://localhost');
        $this->se($info, 'original_site_identifier_hash', md5((string)$this->now));
        $this->se($info, 'original_course_id', (string)$courseid);
        $this->se($info, 'original_course_format', 'topics');
        $this->se($info, 'original_course_fullname', $fullname);
        $this->se($info, 'original_course_shortname', $shortname);
        $this->se($info, 'original_course_startdate', (string)$this->now);
        $this->se($info, 'original_course_enddate', (string)($this->now + 365 * 86400));
        $this->se($info, 'original_course_contextid', (string)$ctxid);
        $this->se($info, 'original_system_contextid', '1');

        // Details.
        $details = $this->se($info, 'details');
        $detail = $this->se($details, 'detail', null, [
            'backup_id' => md5($this->now . $courseid),
        ]);
        $this->se($detail, 'type', 'course');
        $this->se($detail, 'format', 'moodle2');
        $this->se($detail, 'interactive', '1');
        $this->se($detail, 'mode', '70');
        $this->se($detail, 'execution', '2');
        $this->se($detail, 'executiontime', '0');

        // Contents.
        $contents = $this->se($info, 'contents');

        // Activities in contents.
        $actsel = $this->se($contents, 'activities');
        foreach ($activitiesinfo as $ai) {
            $act = $this->se($actsel, 'activity');
            $this->se($act, 'moduleid', (string)$ai['module_id']);
            $this->se($act, 'sectionid', (string)$ai['section_id']);
            $this->se($act, 'modulename', $ai['modulename']);
            $this->se($act, 'title', $ai['title']);
            $this->se($act, 'directory', $ai['directory']);
            $this->se($act, 'insubsection', '');
        }

        // Sections in contents.
        $sectsel = $this->se($contents, 'sections');
        foreach ($sectionsinfo as $si) {
            $sec = $this->se($sectsel, 'section');
            $this->se($sec, 'sectionid', (string)$si['section_id']);
            $this->se($sec, 'title', $si['title']);
            $this->se($sec, 'directory', $si['directory']);
            $this->se($sec, 'parentcmid', '');
            $this->se($sec, 'modname', '');
        }

        // Course in contents.
        $courseel = $this->se($contents, 'course');
        $this->se($courseel, 'courseid', (string)$courseid);
        $this->se($courseel, 'title', $fullname);
        $this->se($courseel, 'directory', 'course');

        // Settings.
        $settings = $this->se($info, 'settings');

        $rootsettings = [
            ['filename', $backupname],
            ['users', '0'], ['anonymize', '0'], ['role_assignments', '0'],
            ['activities', '1'], ['blocks', '0'], ['files', '0'],
            ['filters', '0'], ['comments', '0'], ['badges', '0'],
            ['calendarevents', '0'], ['userscompletion', '0'],
            ['logs', '0'], ['grade_histories', '0'], ['groups', '0'],
            ['competencies', '0'], ['customfield', '0'],
            ['contentbankcontent', '0'], ['xapistate', '0'],
            ['legacyfiles', '1'],
        ];
        foreach ($rootsettings as [$sname, $sval]) {
            $s = $this->se($settings, 'setting');
            $this->se($s, 'level', 'root');
            $this->se($s, 'name', $sname);
            $this->se($s, 'value', $sval);
        }

        // Section settings.
        foreach ($sectionsinfo as $si) {
            $sid = $si['section_id'];
            $sdir = "section_{$sid}";
            foreach (['included', 'userinfo'] as $suffix) {
                $s = $this->se($settings, 'setting');
                $this->se($s, 'level', 'section');
                $this->se($s, 'section', $sdir);
                $this->se($s, 'name', "{$sdir}_{$suffix}");
                $this->se($s, 'value', $suffix === 'included' ? '1' : '0');
            }
        }

        // Activity settings.
        foreach ($activitiesinfo as $ai) {
            $adir = $ai['modulename'] . '_' . $ai['module_id'];
            foreach (['included', 'userinfo'] as $suffix) {
                $s = $this->se($settings, 'setting');
                $this->se($s, 'level', 'activity');
                $this->se($s, 'activity', $adir);
                $this->se($s, 'name', "{$adir}_{$suffix}");
                $this->se($s, 'value', $suffix === 'included' ? '1' : '0');
            }
        }

        return $doc->saveXML();
    }

    // ─────────────────────────────────────────────────────
    // Version helpers
    // ─────────────────────────────────────────────────────

    /**
     * Get the backup version number to use, matching the Moodle installation.
     */
    private function get_backup_version(): string {
        global $CFG;
        return (string)($CFG->version ?? '2024100700');
    }

    /**
     * Get the backup release string.
     */
    private function get_backup_release(): string {
        global $CFG;
        if (!empty($CFG->release)) {
            // Extract major.minor (e.g., "4.5" from "4.5.1 (Build: ...)").
            if (preg_match('/^(\d+\.\d+)/', $CFG->release, $m)) {
                return $m[1];
            }
        }
        return '4.5';
    }

    // Main generation method

    /**
     * Generate the MBZ backup directory structure from CSV rows.
     *
     * Writes all XML files to a temp directory under $CFG->tempdir/backup/
     * and returns the temp directory name for use with restore_controller.
     *
     * @param array  $rows      Parsed CSV rows (array of assoc arrays).
     * @param string $fullname  Course full name.
     * @param string $shortname Course short name.
     * @return string The temp directory name (relative, for restore_controller).
     */
    public function generate(array $rows, string $fullname, string $shortname): string {
        global $CFG;

        $this->now = time();

        // Initialize ID counters.
        $courseid = 1;
        $coursectx = 100;
        $this->ctxcounter = 101;
        $this->sectionidcounter = 1000;
        $this->moduleidcounter = 1;
        $this->actidcounter = 1;
        $this->gradeitemcounter = 101;
        $this->pluginidcounter = 500;
        $gradecatid = 1;
        $coursegradeitemid = 100;

        // Collect sections from CSV.
        $sections = []; // sec_num => name (ordered).
        foreach ($rows as $row) {
            $secnum = (int)trim($row['section_id']);
            if (!isset($sections[$secnum])) {
                $sections[$secnum] = trim($row['section_name']);
            }
        }

        // Assign section IDs.
        $sectionids = []; // sec_num => internal section_id.
        foreach ($sections as $secnum => $secname) {
            $sectionids[$secnum] = $this->sectionidcounter++;
        }

        // Build activities.
        $sectionsequences = array_fill_keys(array_keys($sections), []);
        $activitiesinfo = [];
        $activityfiles = [];

        foreach ($rows as $row) {
            $secnum = (int)trim($row['section_id']);
            $acttype = strtolower(trim($row['activity_type'] ?? ''));
            $actname = trim($row['activity_name'] ?? '');
            $content = trim($row['content_text'] ?? '');
            $url = trim($row['source_url_path'] ?? '');

            // Parse optional date columns.
            $datestart = self::parse_date($row['date_start'] ?? '', '00:00');
            $dateend = self::parse_date($row['date_end'] ?? '', '23:59');
            $datecutoff = self::parse_date($row['date_cutoff'] ?? '', '23:59');

            if (empty($acttype)) {
                continue; // Section-only row.
            }

            if (!in_array($acttype, self::VALID_TYPES)) {
                debugging("Unknown activity type '{$acttype}' – skipping '{$actname}'", DEBUG_DEVELOPER);
                continue;
            }

            $moduleid = $this->moduleidcounter++;
            $actid = $this->actidcounter++;
            $ctxid = $this->ctxcounter++;
            $sectionid = $sectionids[$secnum];

            $sectionsequences[$secnum][] = $moduleid;

            // Build activity-specific XML.
            switch ($acttype) {
                case 'label':
                    $actxml = $this->build_label_xml($actid, $moduleid, $ctxid, $actname, $content);
                    break;
                case 'url':
                    $actxml = $this->build_url_xml($actid, $moduleid, $ctxid, $actname, $content, $url);
                    break;
                case 'resource':
                    $actxml = $this->build_resource_xml($actid, $moduleid, $ctxid, $actname, $content);
                    break;
                case 'page':
                    $actxml = $this->build_page_xml($actid, $moduleid, $ctxid, $actname, $content);
                    break;
                case 'forum':
                    $actxml = $this->build_forum_xml(
                        $actid,
                        $moduleid,
                        $ctxid,
                        $actname,
                        $content,
                        $datestart,
                        $dateend,
                        $datecutoff
                    );
                    break;
                case 'assign':
                    $actxml = $this->build_assign_xml(
                        $actid,
                        $moduleid,
                        $ctxid,
                        $actname,
                        $content,
                        $datestart,
                        $dateend,
                        $datecutoff
                    );
                    break;
                case 'quiz':
                    $actxml = $this->build_quiz_xml(
                        $actid,
                        $moduleid,
                        $ctxid,
                        $actname,
                        $content,
                        $datestart,
                        $dateend
                    );
                    break;
                case 'feedback':
                    $actxml = $this->build_feedback_xml(
                        $actid,
                        $moduleid,
                        $ctxid,
                        $actname,
                        $content,
                        $datestart,
                        $dateend
                    );
                    break;
            }

            $modulexml = $this->build_module_xml($moduleid, $acttype, $sectionid, $secnum);

            // Grade items.
            $hasgrade = in_array($acttype, ['assign', 'quiz']);
            if ($hasgrade) {
                $giid = $this->gradeitemcounter++;
                $gradesxml = $this->assign_grades_xml($giid, $gradecatid, $actid, $actname);
                $inforefxml = $this->gradeitem_inforef_xml($giid);
            } else {
                $gradesxml = $this->empty_grades_xml();
                $inforefxml = $this->empty_inforef_xml();
            }

            $dirname = "activities/{$acttype}_{$moduleid}";
            $files = [
                ["{$acttype}.xml", $actxml],
                ['module.xml', $modulexml],
                ['grades.xml', $gradesxml],
                ['grade_history.xml', $this->empty_grade_history_xml()],
                ['inforef.xml', $inforefxml],
                ['roles.xml', $this->empty_roles_xml()],
            ];
            if ($acttype === 'assign') {
                $files[] = ['grading.xml', $this->assign_grading_xml($actid)];
            }

            $activityfiles[$dirname] = $files;

            $activitiesinfo[] = [
                'module_id' => $moduleid,
                'section_id' => $sectionid,
                'modulename' => $acttype,
                'title' => $actname,
                'directory' => $dirname,
            ];
        }

        // Build sections.
        $sectionsinfo = [];
        $sectionfiles = [];
        foreach ($sections as $secnum => $secname) {
            $sid = $sectionids[$secnum];
            $seq = $sectionsequences[$secnum];
            $secxml = $this->build_section_xml($sid, $secnum, $secname, $seq);
            $dirname = "sections/section_{$sid}";
            $sectionfiles[$dirname] = [
                ['section.xml', $secxml],
                ['inforef.xml', $this->build_section_inforef_xml()],
            ];
            $sectionsinfo[] = [
                'section_id' => $sid,
                'title' => $secname,
                'directory' => $dirname,
            ];
        }

        // Build course-level files.
        $coursefiles = [
            'course/course.xml' => $this->build_course_xml(
                $courseid,
                $coursectx,
                $fullname,
                $shortname
            ),
            'course/enrolments.xml' => $this->build_enrolments_xml(),
            'course/roles.xml' => $this->build_course_roles_xml(),
            'course/inforef.xml' => $this->build_course_inforef_xml(),
            'course/completiondefaults.xml' => $this->build_completiondefaults_xml(),
        ];

        // Build root-level files.
        $rootfiles = [
            'moodle_backup.xml' => $this->build_moodle_backup_xml(
                $courseid,
                $coursectx,
                $fullname,
                $shortname,
                $activitiesinfo,
                $sectionsinfo
            ),
            'files.xml' => $this->build_files_xml(),
            'completion.xml' => $this->build_completion_xml(),
            'gradebook.xml' => $this->build_gradebook_xml($gradecatid, $coursegradeitemid),
            'grade_history.xml' => $this->build_grade_history_root_xml(),
            'scales.xml' => $this->build_scales_xml(),
            'outcomes.xml' => $this->build_outcomes_xml(),
            'questions.xml' => $this->build_questions_xml(),
            'groups.xml' => $this->build_groups_xml(),
            'roles.xml' => $this->build_roles_definition_xml(),
        ];

        // Write all files to temp backup directory.
        $tempdir = 'csvtocourse_' . $this->now . '_' . substr(md5(random_string(10)), 0, 8);
        $temppath = make_backup_temp_directory($tempdir);

        global $CFG;

        // Root files.
        foreach ($rootfiles as $fname => $content) {
            file_put_contents($temppath . '/' . $fname, $content);
        }

        // Course files.
        mkdir($temppath . '/course', $CFG->directorypermissions, true);
        foreach ($coursefiles as $fpath => $content) {
            file_put_contents($temppath . '/' . $fpath, $content);
        }

        // Section files.
        foreach ($sectionfiles as $dirname => $filelist) {
            mkdir($temppath . '/' . $dirname, $CFG->directorypermissions, true);
            foreach ($filelist as [$fname, $content]) {
                file_put_contents($temppath . '/' . $dirname . '/' . $fname, $content);
            }
        }

        // Activity files.
        foreach ($activityfiles as $dirname => $filelist) {
            mkdir($temppath . '/' . $dirname, $CFG->directorypermissions, true);
            foreach ($filelist as [$fname, $content]) {
                file_put_contents($temppath . '/' . $dirname . '/' . $fname, $content);
            }
        }

        return $tempdir;
    }
}

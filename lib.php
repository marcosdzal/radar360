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
 * Library functions for local_sentinel.
 *
 * @package    local_sentinel
 * @copyright  2024 Marcos
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Adds the "Radar 360º" link to the user profile navigation.
 * Called by Moodle core for every user profile page.
 *
 * @param \core_user\output\myprofile\tree $tree   Profile navigation tree.
 * @param stdClass                         $user   Profile owner.
 * @param bool                             $iscurrentuser True when viewing own profile.
 * @param stdClass|null                    $course Current course context (null = site).
 */
function local_sentinel_myprofile_navigation(\core_user\output\myprofile\tree $tree, $user, $iscurrentuser, $course) {
    global $USER;

    global $DB;

    $systemcontext = \context_system::instance();
    $coursecontext = (!empty($course->id) && $course->id != SITEID)
        ? \context_course::instance($course->id) : null;

    $canview = $iscurrentuser
        || has_capability('local/sentinel:viewstudentreport', $systemcontext)
        || ($coursecontext && has_capability('local/sentinel:viewstudentreport', $coursecontext));

    // When viewing from site level, check if viewer has the capability in any
    // course where the profile owner is enrolled.
    $linkcourseid = $coursecontext ? $course->id : 0;
    if (!$canview && !$coursecontext && !$iscurrentuser) {
        $enrolled = enrol_get_users_courses($user->id, true, 'id', 'fullname ASC');
        foreach ($enrolled as $ec) {
            $ctx = \context_course::instance($ec->id, IGNORE_MISSING);
            if ($ctx && has_capability('local/sentinel:viewstudentreport', $ctx)) {
                $canview = true;
                $linkcourseid = $ec->id;
                break;
            }
        }
    }

    if (!$canview) {
        return;
    }

    $params = ['userid' => $user->id];
    if ($linkcourseid > 0) {
        $params['courseid'] = $linkcourseid;
    }
    $url  = new \moodle_url('/local/sentinel/student_report.php', $params);

    $node = new \core_user\output\myprofile\node(
        'reports',
        'sentinel_report',
        get_string('report:title', 'local_sentinel'),
        null,
        $url
    );

    $tree->add_node($node);
}

/**
 * Adds "Radar 360º" to the user navigation dropdown (top-right menu).
 * Students see it alongside "Mis calificaciones" and "Tablero".
 */
function local_sentinel_extend_navigation_user(\navigation_node $nav, \stdClass $user,
        \context_user $context, \stdClass $course) {
    global $USER;

    $coursecontext = (!empty($course->id) && $course->id != SITEID)
        ? \context_course::instance($course->id) : null;
    $canview = ($USER->id === $user->id)
        || has_capability('local/sentinel:viewstudentreport', \context_system::instance())
        || ($coursecontext && has_capability('local/sentinel:viewstudentreport', $coursecontext));

    if (!$canview) {
        return;
    }

    $params = ['userid' => $user->id];
    if ($coursecontext) {
        $params['courseid'] = $course->id;
    }
    $url = new \moodle_url('/local/sentinel/student_report.php', $params);
    $nav->add(
        get_string('report:title', 'local_sentinel'),
        $url,
        \navigation_node::TYPE_SETTING,
        null,
        'sentinel_report',
        new \pix_icon('i/report', '')
    );
}

/**
 * Extends the course navigation with Sentinel links.
 */
function local_sentinel_extend_navigation_course(\navigation_node $nav, \stdClass $course, \context_course $context) {
    global $USER;

    $canview = has_capability('local/sentinel:viewstudentreport', $context)
            || has_capability('local/sentinel:viewstudentreport', \context_system::instance());
    $canmanage = has_capability('local/sentinel:managedescriptors', $context)
              || has_capability('local/sentinel:managedescriptors', \context_system::instance());

    // Students enrolled in the course see a direct "Radar 360°" tab to their own report.
    $is_student = !$canview && !$canmanage && is_enrolled($context, $USER, '', true);
    if ($is_student) {
        $student_url = new \moodle_url('/local/sentinel/student_report.php', [
            'userid'   => $USER->id,
            'courseid' => $course->id,
        ]);
        $node = $nav->add(
            get_string('report:title', 'local_sentinel'),
            $student_url,
            \navigation_node::TYPE_SETTING,
            null,
            'sentinel_student_tab',
            new \pix_icon('i/report', '')
        );
        $node->showinsecondarynavigation = true;
        return;
    }

    if (!$canview && !$canmanage) {
        return;
    }

    $sentinel = $nav->add(
        get_string('report:title', 'local_sentinel'),
        new \moodle_url('/local/sentinel/course_report.php', ['courseid' => $course->id]),
        \navigation_node::TYPE_CONTAINER,
        null,
        'sentinel_root',
        new \pix_icon('i/report', '')
    );
    $sentinel->forceintomoremenu = true;

    if ($canmanage) {
        $sentinel->add(
            get_string('curriculum:title', 'local_sentinel'),
            new \moodle_url('/local/sentinel/curriculum_config.php', ['courseid' => $course->id]),
            \navigation_node::TYPE_SETTING,
            null,
            'sentinel_curriculum',
            new \pix_icon('i/settings', '')
        );

        $sentinel->add(
            get_string('desgrane:title', 'local_sentinel'),
            new \moodle_url('/local/sentinel/desgrane.php', ['courseid' => $course->id]),
            \navigation_node::TYPE_SETTING,
            null,
            'sentinel_desgrane',
            new \pix_icon('i/settings', '')
        );

        $sentinel->add(
            get_string('mapdesc:navlink', 'local_sentinel'),
            new \moodle_url('/local/sentinel/admin/map_descriptors.php'),
            \navigation_node::TYPE_SETTING,
            null,
            'sentinel_mapdesc',
            new \pix_icon('i/settings', '')
        );
    }
}

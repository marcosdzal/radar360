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
 * Adds the "Perfil de salida" link to the user profile navigation.
 * Called by Moodle core for every user profile page.
 *
 * @param \core_user\output\myprofile\tree $tree   Profile navigation tree.
 * @param stdClass                         $user   Profile owner.
 * @param bool                             $iscurrentuser True when viewing own profile.
 * @param stdClass|null                    $course Current course context (null = site).
 */
function local_sentinel_myprofile_navigation(\core_user\output\myprofile\tree $tree, $user, $iscurrentuser, $course) {
    global $USER;

    $systemcontext = \context_system::instance();

    $canview = $iscurrentuser
        || has_capability('local/sentinel:viewstudentreport', $systemcontext);

    if (!$canview) {
        return;
    }

    $params = ['userid' => $user->id];
    if (!empty($course->id) && $course->id != SITEID) {
        $params['courseid'] = $course->id;
    }
    $url  = new \moodle_url('/local/sentinel/student_report.php', $params);

    // Crear categoría propia bajo 'reports' si no existe ya.
    $category = new \core_user\output\myprofile\category(
        'local_sentinel',
        get_string('pluginname', 'local_sentinel'),
        'reports'
    );
    try {
        $tree->add_category($category);
    } catch (\Throwable $e) {
        // Ya existe — ignorar.
    }

    $node = new \core_user\output\myprofile\node(
        'local_sentinel',
        'sentinel_report',
        get_string('report:title', 'local_sentinel'),
        null,
        $url
    );

    $tree->add_node($node);
}

/**
 * Adds "Perfil de salida" to the user navigation dropdown (top-right menu).
 * Students see it alongside "Mis calificaciones" and "Tablero".
 */
function local_sentinel_extend_navigation_user(\navigation_node $nav, \stdClass $user,
        \context_user $context, \stdClass $course) {
    global $USER;

    $canview = ($USER->id === $user->id)
        || has_capability('local/sentinel:viewstudentreport', \context_system::instance());

    if (!$canview) {
        return;
    }

    $params = ['userid' => $user->id];
    if (!empty($course->id) && $course->id != SITEID) {
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
 * Extends the course navigation to add a Sentinel admin link for site admins.
 * Shows as "Sentinel – Descriptores" under the course navigation.
 */
function local_sentinel_extend_navigation_course(\navigation_node $nav, \stdClass $course, \context_course $context) {
    if (!has_capability('local/sentinel:managedescriptors', \context_system::instance())) {
        return;
    }

    $url = new \moodle_url('/local/sentinel/admin/map_descriptors.php');
    $node = $nav->add(
        get_string('mapdesc:navlink', 'local_sentinel'),
        $url,
        \navigation_node::TYPE_SETTING,
        null,
        'sentinel_mapdesc',
        new \pix_icon('i/settings', '')
    );
    $node->showinflatnavigation = false;
}

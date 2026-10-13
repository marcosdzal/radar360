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

namespace local_sentinel\hook;

/**
 * Hook listener: adds the Sentinel "Perfil de salida" tab to the course secondary navigation.
 *
 * @package    local_sentinel
 * @copyright  2024 Marcos
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class secondary_nav {

    /**
     * Adds "Perfil de salida" tab to course secondary navigation.
     * Links directly to the student report (diana/tarjetas) of the first
     * enrolled student, with the student selector for switching.
     *
     * @param \core\hook\navigation\secondary_extend $hook
     */
    public static function extend_course_nav(
        \core\hook\navigation\secondary_extend $hook
    ): void {
        global $COURSE, $PAGE, $DB;

        if (empty($COURSE->id) || $COURSE->id == SITEID) {
            return;
        }

        $contextlevel = $PAGE->context->contextlevel ?? 0;
        if ($contextlevel !== CONTEXT_COURSE && $contextlevel !== CONTEXT_MODULE) {
            return;
        }

        $context = \context_course::instance($COURSE->id);
        $canview = has_capability('local/sentinel:viewstudentreport', $context)
                || has_capability('local/sentinel:viewstudentreport', \context_system::instance());

        if (!$canview) {
            return;
        }

        $first = $DB->get_record_sql(
            "SELECT u.id
               FROM {user} u
               JOIN {role_assignments} ra ON ra.userid = u.id
               JOIN {role} r ON r.id = ra.roleid AND r.shortname = 'student'
               JOIN {context} ctx ON ctx.id = ra.contextid
                    AND ctx.contextlevel = " . CONTEXT_COURSE . "
                    AND ctx.instanceid = ?
              WHERE u.deleted = 0
              ORDER BY u.lastname, u.firstname
              LIMIT 1",
            [$COURSE->id]
        );
        if (!$first) {
            return;
        }

        $secondary = $hook->get_secondaryview();
        $url = new \moodle_url('/local/sentinel/student_report.php', [
            'userid'   => $first->id,
            'courseid' => $COURSE->id,
        ]);
        $secondary->add(
            get_string('report:title', 'local_sentinel'),
            $url,
            \navigation_node::TYPE_CUSTOM,
            null,
            'sentinel_course_report',
            new \pix_icon('i/report', '')
        );
    }
}

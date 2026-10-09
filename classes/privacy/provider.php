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
 * Privacy API implementation for local_sentinel.
 *
 * Personal data stored by this plugin:
 *   - local_sentinel_student_photo: photo uploaded by a teacher for a student.
 *     · studentid   → identifies the student (data subject).
 *     · uploaderid  → identifies the teacher who uploaded the photo.
 *   - core_files (local_sentinel / student_photo area): the actual image file.
 *
 * @package    local_sentinel
 * @copyright  2024 Marcos
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_sentinel\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

defined('MOODLE_INTERNAL') || die();

class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\plugin\provider,
    \core_privacy\local\request\core_userlist_provider {

    // ── Metadata ─────────────────────────────────────────────────────────────

    public static function get_metadata(collection $collection): collection {

        $collection->add_database_table(
            'local_sentinel_student_photo',
            [
                'studentid'    => 'privacy:metadata:local_sentinel_student_photo:studentid',
                'uploaderid'   => 'privacy:metadata:local_sentinel_student_photo:uploaderid',
                'timemodified' => 'privacy:metadata:local_sentinel_student_photo:timemodified',
            ],
            'privacy:metadata:local_sentinel_student_photo'
        );

        $collection->add_subsystem_link(
            'core_files',
            [],
            'privacy:metadata:core_files'
        );

        return $collection;
    }

    // ── Context discovery ─────────────────────────────────────────────────────

    public static function get_contexts_for_userid(int $userid): contextlist {
        global $DB;

        $contextlist = new contextlist();

        // Photos are stored at system context; check both student and uploader roles.
        if ($DB->record_exists_select(
            'local_sentinel_student_photo',
            'studentid = :s OR uploaderid = :u',
            ['s' => $userid, 'u' => $userid]
        )) {
            $contextlist->add_system_context();
        }

        return $contextlist;
    }

    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();
        if (!($context instanceof \context_system)) {
            return;
        }

        // Students who have a photo + teachers who uploaded one.
        $sql = 'SELECT studentid  AS userid FROM {local_sentinel_student_photo}
                UNION
                SELECT uploaderid AS userid FROM {local_sentinel_student_photo} WHERE uploaderid > 0';
        $userlist->add_from_sql('userid', $sql, []);
    }

    // ── Export ────────────────────────────────────────────────────────────────

    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;

        $user = $contextlist->get_user();

        foreach ($contextlist->get_contexts() as $context) {
            if (!($context instanceof \context_system)) {
                continue;
            }

            // ── Data where the user is the student (photo subject) ────────────
            $record = $DB->get_record('local_sentinel_student_photo', ['studentid' => $user->id]);
            if ($record) {
                writer::with_context($context)->export_data(
                    [get_string('pluginname', 'local_sentinel'),
                     get_string('privacy:studentphoto', 'local_sentinel')],
                    (object)[
                        'studentid'    => $record->studentid,
                        'uploaderid'   => $record->uploaderid,
                        'timemodified' => transform::datetime($record->timemodified),
                    ]
                );

                // Export the actual image file.
                writer::with_context($context)->export_area_files(
                    [get_string('pluginname', 'local_sentinel'),
                     get_string('privacy:studentphoto', 'local_sentinel')],
                    'local_sentinel',
                    'student_photo',
                    $user->id
                );
            }

            // ── Data where the user is the uploader (teacher) ─────────────────
            $uploaded = $DB->get_records('local_sentinel_student_photo', ['uploaderid' => $user->id]);
            if ($uploaded) {
                $rows = array_map(fn($r) => [
                    'studentid'    => $r->studentid,
                    'timemodified' => transform::datetime($r->timemodified),
                ], array_values($uploaded));

                writer::with_context($context)->export_data(
                    [get_string('pluginname', 'local_sentinel'),
                     get_string('privacy:uploadedphotos', 'local_sentinel')],
                    (object)['photos' => $rows]
                );
            }
        }
    }

    // ── Deletion ──────────────────────────────────────────────────────────────

    public static function delete_data_for_all_users_in_context(\context $context): void {
        global $DB;

        if (!($context instanceof \context_system)) {
            return;
        }

        $fs = get_file_storage();
        $fs->delete_area_files($context->id, 'local_sentinel', 'student_photo');
        $DB->delete_records('local_sentinel_student_photo');
    }

    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        global $DB;

        $user = $contextlist->get_user();

        foreach ($contextlist->get_contexts() as $context) {
            if (!($context instanceof \context_system)) {
                continue;
            }

            // Delete the student's own photo and file.
            $fs = get_file_storage();
            $fs->delete_area_files($context->id, 'local_sentinel', 'student_photo', $user->id);
            $DB->delete_records('local_sentinel_student_photo', ['studentid' => $user->id]);

            // Anonymise records where this user was the uploader
            // (we cannot delete another student's photo on their behalf).
            $DB->set_field('local_sentinel_student_photo', 'uploaderid', 0,
                           ['uploaderid' => $user->id]);
        }
    }

    public static function delete_data_for_users(approved_userlist $userlist): void {
        global $DB;

        $context = $userlist->get_context();
        if (!($context instanceof \context_system)) {
            return;
        }

        $userids = $userlist->get_userids();
        $fs      = get_file_storage();

        foreach ($userids as $userid) {
            $fs->delete_area_files($context->id, 'local_sentinel', 'student_photo', $userid);
            $DB->delete_records('local_sentinel_student_photo', ['studentid' => $userid]);
        }

        // Anonymise uploader references for deleted users.
        [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'u');
        $DB->set_field_select('local_sentinel_student_photo', 'uploaderid', 0,
                              "uploaderid $insql", $params);
    }
}

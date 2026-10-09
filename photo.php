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
 * Student photo endpoint for local_sentinel.
 *
 * GET  ?action=serve&studentid=X  → serves the stored image
 * POST ?action=upload              → upload/replace photo (multipart: studentid, sesskey, photo)
 * POST ?action=delete              → delete photo (params: studentid, sesskey)
 *
 * @package    local_sentinel
 * @copyright  2024 Marcos
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

// Buffer all output so PHP notices/warnings don't corrupt JSON responses.
ob_start();

$action    = required_param('action', PARAM_ALPHA);
$studentid = required_param('studentid', PARAM_INT);

require_login();

if (isguestuser()) {
    http_response_code(403);
    die('Forbidden');
}

$syscontext = context_system::instance();

/**
 * Returns true if $viewerid can see the sentinel photo of $studentid:
 * they must share at least one course where the viewer can grade.
 */
function sentinel_viewer_shares_course(int $viewerid, int $studentid): bool {
    $courses = enrol_get_all_users_courses($studentid, true);
    foreach ($courses as $course) {
        $ctx = context_course::instance($course->id);
        if (has_capability('mod/assign:grade', $ctx, $viewerid)) {
            return true;
        }
    }
    return false;
}

// ── SERVE ─────────────────────────────────────────────────────────────────────
if ($action === 'serve') {
    ob_end_clean();
    // Sanity-check the requested student exists.
    if (!$DB->record_exists('user', ['id' => $studentid, 'deleted' => 0])) {
        http_response_code(404);
        die('Not found');
    }

    // Students can view their own photo; teachers must share a course with the student.
    if ($USER->id !== $studentid && !sentinel_viewer_shares_course($USER->id, $studentid)) {
        http_response_code(403);
        die('Forbidden');
    }

    $fs    = get_file_storage();
    $files = $fs->get_area_files($syscontext->id, 'local_sentinel', 'student_photo', $studentid, '', false);

    if (empty($files)) {
        http_response_code(404);
        die('Not found');
    }

    $file = reset($files);
    send_stored_file($file, 0, 0, true);
    exit;
}

// ── UPLOAD ────────────────────────────────────────────────────────────────────
if ($action === 'upload') {
    ob_end_clean();
    header('Content-Type: application/json');

    if (!confirm_sesskey()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Sesión caducada. Recarga la página (Ctrl+Shift+R).']);
        exit;
    }

    if (!has_capability('local/sentinel:managephotos', $syscontext)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Sin permiso para gestionar fotos.']);
        exit;
    }

    // Verify the target student exists and is not the uploader themselves.
    if (!$DB->record_exists('user', ['id' => $studentid, 'deleted' => 0])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Unknown student']);
        exit;
    }
    if ($USER->id === $studentid) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Forbidden']);
        exit;
    }

    if (empty($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'No file uploaded']);
        exit;
    }

    // File size limit: 5 MB.
    $maxbytes = 5 * 1024 * 1024;
    if ($_FILES['photo']['size'] > $maxbytes) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'File too large (max 5 MB)']);
        exit;
    }

    $tmppath = $_FILES['photo']['tmp_name'];

    // Validate actual MIME type using finfo (ignores client-supplied type).
    $allowed_mimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $finfo    = new finfo(FILEINFO_MIME_TYPE);
    $realmime = $finfo->file($tmppath);
    if (!in_array($realmime, $allowed_mimes, true)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid image type']);
        exit;
    }

    $origname = clean_filename($_FILES['photo']['name']);
    $fs       = get_file_storage();

    // Replace any existing photo for this student.
    $fs->delete_area_files($syscontext->id, 'local_sentinel', 'student_photo', $studentid);

    $fileinfo = [
        'contextid' => $syscontext->id,
        'component' => 'local_sentinel',
        'filearea'  => 'student_photo',
        'itemid'    => $studentid,
        'filepath'  => '/',
        'filename'  => $origname,
    ];
    $fs->create_file_from_pathname($fileinfo, $tmppath);

    // Upsert DB record.
    $rec = $DB->get_record('local_sentinel_student_photo', ['studentid' => $studentid]);
    if ($rec) {
        $rec->uploaderid   = $USER->id;
        $rec->timemodified = time();
        $DB->update_record('local_sentinel_student_photo', $rec);
    } else {
        $DB->insert_record('local_sentinel_student_photo', (object)[
            'studentid'    => $studentid,
            'uploaderid'   => $USER->id,
            'timemodified' => time(),
        ]);
    }

    $url = (new moodle_url('/local/sentinel/photo.php', [
        'action'    => 'serve',
        'studentid' => $studentid,
        'ts'        => time(),
    ]))->out(false);

    echo json_encode(['success' => true, 'url' => $url]);
    exit;
}

// ── DELETE ────────────────────────────────────────────────────────────────────
if ($action === 'delete') {
    ob_end_clean();
    header('Content-Type: application/json');

    if (!confirm_sesskey()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Sesión caducada. Recarga la página.']);
        exit;
    }

    if (!has_capability('local/sentinel:managephotos', $syscontext)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Sin permiso para gestionar fotos.']);
        exit;
    }

    if ($USER->id === $studentid) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Forbidden']);
        exit;
    }

    $fs = get_file_storage();
    $fs->delete_area_files($syscontext->id, 'local_sentinel', 'student_photo', $studentid);
    $DB->delete_records('local_sentinel_student_photo', ['studentid' => $studentid]);

    echo json_encode(['success' => true]);
    exit;
}

http_response_code(400);
die('Unknown action');

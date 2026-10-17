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
 * Informe de perfil de salida de un alumno.
 *
 * URL: /local/sentinel/student_report.php?userid=NNN[&cutoff=TIMESTAMP][&export=csv]
 *
 * @package    local_sentinel
 * @copyright  2024 Marcos
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/local/sentinel/classes/descriptor_calculator.php');

use local_sentinel\descriptor_calculator;

$userid  = required_param('userid', PARAM_INT);
$cutoff  = optional_param('cutoff', 0, PARAM_INT);
$export  = optional_param('export', '', PARAM_ALPHA);
$norm    = optional_param('norm', 'madrid', PARAM_ALPHA);
$inittab = optional_param('tab', '', PARAM_ALPHA);

require_login();

$systemcontext = context_system::instance();
$student       = core_user::get_user($userid, '*', MUST_EXIST);

$iscurrentuser = ((int)$USER->id === (int)$userid);
if (!$iscurrentuser) {
    $canview_report = has_capability('local/sentinel:viewstudentreport', $systemcontext);
    if (!$canview_report) {
        $student_courses_check = enrol_get_users_courses($userid, true, 'id');
        foreach ($student_courses_check as $sc) {
            $cctx = context_course::instance($sc->id, IGNORE_MISSING);
            if ($cctx && has_capability('local/sentinel:viewstudentreport', $cctx)) {
                $canview_report = true;
                break;
            }
        }
    }
    if (!$canview_report) {
        require_capability('local/sentinel:viewstudentreport', $systemcontext);
    }
}

// ── Normativas autonómicas ────────────────────────────────────────────────────
$norm_configs = [
    'madrid'   => ['label' => 'Madrid (BOCM 2022)', 'levels' => [
        ['min' => 9, 'key' => 'avanzada',   'text' => 'Avanzada',      'range' => '≥9'],
        ['min' => 7, 'key' => 'adquirida',  'text' => 'Adquirida',     'range' => '≥7'],
        ['min' => 5, 'key' => 'enproceso',  'text' => 'En proceso',    'range' => '≥5'],
        ['min' => 0, 'key' => 'iniciacion', 'text' => 'Iniciación',    'range' => '<5'],
    ]],
    'lomloe'   => ['label' => 'LOMLOE (RD 217/2022)', 'levels' => [
        ['min' => 9, 'key' => 'avanzada',   'text' => 'Grado alto',    'range' => '≥9'],
        ['min' => 7, 'key' => 'adquirida',  'text' => 'Grado básico',  'range' => '≥7'],
        ['min' => 5, 'key' => 'enproceso',  'text' => 'En proceso',    'range' => '≥5'],
        ['min' => 0, 'key' => 'iniciacion', 'text' => 'Inicio',        'range' => '<5'],
    ]],
    'galicia'  => ['label' => 'Galicia (DOG)', 'levels' => [
        ['min' => 9, 'key' => 'avanzada',   'text' => 'Sobresaliente', 'range' => '≥9'],
        ['min' => 7, 'key' => 'adquirida',  'text' => 'Notable',       'range' => '≥7'],
        ['min' => 5, 'key' => 'enproceso',  'text' => 'Suficiente',    'range' => '≥5'],
        ['min' => 0, 'key' => 'iniciacion', 'text' => 'Insuficiente',  'range' => '<5'],
    ]],
    'andalucia'=> ['label' => 'Andalucía (BOJA)', 'levels' => [
        ['min' => 9, 'key' => 'avanzada',   'text' => 'Excelente',     'range' => '≥9'],
        ['min' => 7, 'key' => 'adquirida',  'text' => 'Notable',       'range' => '≥7'],
        ['min' => 5, 'key' => 'enproceso',  'text' => 'Suficiente',    'range' => '≥5'],
        ['min' => 0, 'key' => 'iniciacion', 'text' => 'Insuficiente',  'range' => '<5'],
    ]],
];
$tnorm = $norm_configs[$norm] ?? $norm_configs['madrid'];
$GLOBALS['sn_norm_levels'] = $tnorm['levels'];

// ── Lista de alumnos para el selector ─────────────────────────────────────────
$role_student = $DB->get_record('role', ['shortname' => 'student']);
$selector_students = [];
if ($role_student) {
    $sql_sel = "SELECT DISTINCT u.id, u.firstname, u.lastname,
                       u.firstnamephonetic, u.lastnamephonetic,
                       u.alternatename, u.middlename
                FROM {user} u
                JOIN {role_assignments} ra ON ra.userid = u.id
                WHERE ra.roleid = :roleid
                  AND u.deleted = 0 AND u.suspended = 0
                ORDER BY u.lastname, u.firstname";
    $selector_students = $DB->get_records_sql($sql_sel, ['roleid' => $role_student->id]);
}

$bc_courseid = optional_param('courseid', 0, PARAM_INT);

$PAGE->set_context($systemcontext);
$PAGE->set_url(new moodle_url('/local/sentinel/student_report.php',
    array_filter(['userid' => $userid, 'courseid' => $bc_courseid])));
$PAGE->set_title(get_string('report:title', 'local_sentinel') . ' — ' . fullname($student));
$PAGE->set_heading(get_string('report:title', 'local_sentinel'));
$PAGE->set_pagelayout('standard');

// ── Breadcrumb ────────────────────────────────────────────────────────────────
$courses_nav = enrol_get_users_courses($userid, true, 'id,shortname,fullname');
$bc_course = null;
if ($bc_courseid && isset($courses_nav[$bc_courseid])) {
    $bc_course = $courses_nav[$bc_courseid];
} elseif (!empty($courses_nav)) {
    $bc_course = reset($courses_nav);
}
if ($bc_course) {
    $PAGE->navbar->add($bc_course->shortname, new moodle_url('/course/view.php', ['id' => $bc_course->id]));
    $PAGE->navbar->add(
        get_string('grades', 'grades'),
        new moodle_url('/grade/report/index.php', ['id' => $bc_course->id])
    );
}
$PAGE->navbar->add(fullname($student), new moodle_url('/user/profile.php', ['id' => $userid]));
$PAGE->navbar->add(get_string('report:title', 'local_sentinel'));

// ── Datos principales ─────────────────────────────────────────────────────────
$cutoffdate  = $cutoff > 0 ? $cutoff : null;
$data        = descriptor_calculator::get_student_data($userid, null, $cutoffdate);
$areas       = $data['areas'];
$periods     = $data['periods'];
$summary     = $data['summary'];

// (Comparativa vs clase eliminada — puede dar falsa impresión de buen rendimiento.)

// ── Datos de competencias específicas (por materia) ──────────────────────────
$student_courses_all = enrol_get_users_courses($userid, true, 'id,fullname,shortname');
$desc_group_totals = descriptor_calculator::get_descriptor_group_totals(array_keys($student_courses_all));
$is_admin = has_capability('moodle/site:config', $systemcontext);
$tutor_role = $DB->get_record('role', ['shortname' => 'tutor']);
$is_tutor_user = false;
if ($tutor_role) {
    foreach ($student_courses_all as $sc) {
        $cctx = context_course::instance($sc->id);
        if (user_has_role_assignment($USER->id, $tutor_role->id, $cctx->id)) {
            $is_tutor_user = true;
            break;
        }
    }
}
$visible_courses = [];
$can_see_all = $iscurrentuser || $is_admin;
foreach ($student_courses_all as $sc) {
    if ($can_see_all) {
        $visible_courses[$sc->id] = $sc;
    } else {
        $cctx = context_course::instance($sc->id);
        $has_role = $DB->record_exists('role_assignments', [
            'userid' => $USER->id,
            'contextid' => $cctx->id,
        ]);
        if ($has_role || has_capability('mod/assign:grade', $cctx, null, false)) {
            $visible_courses[$sc->id] = $sc;
        }
    }
}
$selected_courseid = optional_param('courseid', 0, PARAM_INT);
if (!$selected_courseid && !empty($visible_courses)) {
    $selected_courseid = (int)array_key_first($visible_courses);
}
$ce_data = null;
if ($selected_courseid && isset($visible_courses[$selected_courseid])) {
    $ce_data = descriptor_calculator::get_student_ce_data($userid, $selected_courseid);
}

// Pre-calcular notas por periodo para el historial de cada descriptor y área.
$period_grades = []; // [period_id][dcode] = nota
$period_area_grades = []; // [period_id][acode] = partial_grade
foreach ($periods as $p) {
    $pd = descriptor_calculator::get_student_data($userid, null, (int)$p->cutoffdate);
    foreach ($pd['areas'] as $acode => $adata) {
        $period_area_grades[$p->id][$acode] = $adata['partial_grade'];
        foreach ($adata['descriptors'] as $dcode => $ddata) {
            $period_grades[$p->id][$dcode] = $ddata['grade'];
        }
    }
}

// ── Exportar CSV ──────────────────────────────────────────────────────────────
if ($export === 'csv') {
    $filename = clean_filename(fullname($student) . '_perfil_salida_' . date('Ymd') . '.csv');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
    fputcsv($out, ['Área', 'Nota CC', 'Descriptor', 'Nota descriptor', 'Ocasiones', 'Materias']);
    foreach ($areas as $acode => $adata) {
        foreach ($adata['descriptors'] as $dcode => $ddata) {
            fputcsv($out, [
                $adata['name'],
                number_format($adata['grade'], 2),
                $dcode,
                $ddata['grade'] !== null ? number_format($ddata['grade'], 2) : '',
                $ddata['occasions'],
                implode(', ', $ddata['courses']),
            ]);
        }
    }
    fclose($out);
    exit;
}

// ── Helpers ───────────────────────────────────────────────────────────────────

function sentinel_badge(float $grade, string $size = 'md'): string {
    $color = descriptor_calculator::grade_colour($grade);
    $fs    = $size === 'lg' ? '1.4em' : '1.05em';
    $pad   = $size === 'lg' ? '6px 16px' : '3px 10px';
    return html_writer::tag('span', number_format($grade, 1), [
        'class' => 'sentinel-grade-badge',
        'style' => "background:{$color};color:#fff;font-size:{$fs};padding:{$pad};border-radius:5px;font-weight:700;display:inline-block",
    ]);
}

function sentinel_bar(float $grade, string $color, int $width = 72): string {
    $pct   = min(100, max(0, $grade / 10 * 100));
    $style = $width === 100
        ? 'background:#e9ecef;height:5px;border-radius:4px;width:100%;display:block;margin:2px 0'
        : 'background:#e9ecef;height:7px;border-radius:4px;width:' . $width . 'px;display:inline-block;vertical-align:middle';
    return '<div style="' . $style . '">'
         . '<div style="width:' . $pct . '%;background:' . $color . ';height:100%;border-radius:4px;transition:width .4s"></div>'
         . '</div>';
}

function sentinel_level_badge(float $grade): string {
    $levels = $GLOBALS['sn_norm_levels'];
    foreach ($levels as $l) {
        if ($grade >= $l['min']) {
            return '<span class="sentinel-level-badge snl-' . $l['key'] . '">' . $l['text'] . '</span>';
        }
    }
    return '<span class="sentinel-level-badge snl-iniciacion">' . $levels[3]['text'] . '</span>';
}

/**
 * Renders an inline sparkline SVG from a list of [timestamp, grade] points.
 *
 * @param array  $points     List of [timestamp, grade(0-10)] sorted by time.
 * @param string $color      Stroke color.
 * @param int    $w          SVG width in px.
 * @param int    $h          SVG height in px.
 * @param array  $dua_points Optional DUA overlay points (same format, dotted at 35%).
 * @return string HTML <span> with the SVG, or '' if <2 points in both series.
 */
function sentinel_sparkline_svg(array $points, string $color, int $w = 56, int $h = 18, array $dua_points = []): string {
    if (count($points) < 2 && count($dua_points) < 2) {
        return '';
    }
    $all_times = array_merge(array_column($points, 0), array_column($dua_points, 0));
    if (empty($all_times)) {
        return '';
    }
    $min_t = min($all_times);
    $max_t = max($all_times);
    $range = max($max_t - $min_t, 1);

    $svg = '<span class="sn-obj-sparkline-wrap">'
         . '<svg width="' . $w . '" height="' . $h . '" viewBox="0 0 ' . $w . ' ' . $h . '">';

    if (count($points) >= 2) {
        $pts = [];
        foreach ($points as $pt) {
            $sx = round(($pt[0] - $min_t) / $range * $w, 1);
            $sy = round($h - ($pt[1] / 10) * $h, 1);
            $pts[] = "{$sx},{$sy}";
        }
        $svg .= '<polyline points="' . implode(' ', $pts) . '" fill="none" stroke="' . $color
              . '" stroke-width="1.5" stroke-linejoin="round" stroke-linecap="round"/>';
    }

    if (count($dua_points) >= 2) {
        $pts = [];
        foreach ($dua_points as $pt) {
            $sx = round(($pt[0] - $min_t) / $range * $w, 1);
            $sy = round($h - ($pt[1] / 10) * $h, 1);
            $pts[] = "{$sx},{$sy}";
        }
        $svg .= '<polyline points="' . implode(' ', $pts) . '" fill="none" stroke="' . $color
              . '" stroke-width="1.2" stroke-dasharray="2,2" opacity=".35"'
              . ' stroke-linejoin="round" stroke-linecap="round"/>';
    }

    $svg .= '</svg></span>';
    return $svg;
}

// Media parcial = media de áreas con trabajo (solo descriptor trabajados).
$worked_areas = array_filter($areas, fn($a) => $a['partial_grade'] !== null);
$media_global = count($worked_areas) > 0
    ? round(array_sum(array_column($worked_areas, 'partial_grade')) / count($worked_areas), 1)
    : null;

// Media LOMLOE = media de las 8 áreas con denominador total (unworked = 0).
$lomloe_area_grades = array_map(fn($a) => $a['grade'], $areas);
$lomloe_global = count($lomloe_area_grades) > 0
    ? round(array_sum($lomloe_area_grades) / count($lomloe_area_grades), 1)
    : null;

// Representatividad global (all courses) and per-course if selected.
$repr_global = descriptor_calculator::get_representativity_global($userid);
$repr_course = $selected_courseid
    ? descriptor_calculator::get_representativity($userid, $selected_courseid)
    : null;

// ── Render ────────────────────────────────────────────────────────────────────
echo $OUTPUT->header();
?>
<style>
/* ── Evitar salto horizontal por scrollbar ─────────────────────────── */
html { scrollbar-gutter: stable; }
/* ── Reset y base ─────────────────────────────────────────────────── */
.sentinel-report { font-family: inherit; max-width: 1100px; margin: 0 auto;
    padding: 1rem; border-radius: 16px; transition: background .35s ease; }
.sentinel-report.sn-bg-key      { background: #f4f6f9; }
.sentinel-report.sn-bg-specific { background: #fffdf5; }
.sentinel-panel  { display: none; }
.sentinel-panel.active { display: block; }
.sn-back-link {
    display: inline-block; margin-bottom: .5rem;
    font-size: .82em; font-weight: 600; color: #6b7280;
    text-decoration: none; cursor: pointer;
}
.sn-back-link:hover { color: #374151; text-decoration: underline; }

/* ── Etiqueta de sección (badge pequeño) ────────────────────────── */
.sn-header-tag {
    font-size: .56em; font-weight: 700; text-transform: uppercase;
    letter-spacing: .05em; padding: 2px 8px; border-radius: 4px;
    line-height: 1.3; white-space: nowrap;
}
.sn-header-tag-key {
    color: #3b5998; background: #e8ecf4;
}
.sn-header-tag-specific {
    color: #92400e; background: rgba(245,158,11,.15);
}
/* ── Contenedor sticky ──────────────────────────────────────────── */
.sn-header-wrap {
    position: sticky; top: 55px; z-index: 100;
    margin-bottom: 1.2rem;
}

/* ── Cabecera del alumno ──────────────────────────────────────────── */
.sn-student-header {
    background: #fff; border: 1px solid #e5e7eb; border-radius: 14px;
    padding: 1rem 1.5rem;
    display: flex; align-items: center; gap: 1rem;
    box-shadow: 0 2px 8px rgba(0,0,0,.05);
}
.sn-identity { min-width: 0; flex: 1; }
.sn-student-header .sn-avatar {
    width: 52px; height: 52px; border-radius: 50%;
    background: #0d6efd; color: #fff;
    display: flex; align-items: center; justify-content: center;
    font-weight: 700; font-size: 1.1em; flex-shrink: 0;
    overflow: hidden;
}
.sn-avatar-photo {
    object-fit: cover; background: transparent !important;
    border: 2px solid #e5e7eb;
    transition: transform .2s ease, box-shadow .2s ease;
    cursor: pointer;
}
.sn-avatar-photo:hover {
    transform: scale(2.5);
    box-shadow: 0 4px 20px rgba(0,0,0,.25);
    z-index: 200;
    position: relative;
}
.sn-student-header .sn-name   { font-size: 1.15em; font-weight: 700; color: #111; }
.sn-student-header .sn-meta   { font-size: .82em; color: #6b7280; margin-top: 2px; }

/* ── Mini diana en header ──────────────────────────────────────── */
.sn-mini-radar-wrap {
    flex-shrink: 0; cursor: pointer;
    width: 72px; height: 72px;
    border-radius: 50%;
    background: radial-gradient(circle, #f8f9fa 60%, #fff 100%);
    border: 2px solid #e9ecef;
    display: flex; align-items: center; justify-content: center;
    transition: all .3s cubic-bezier(.4,0,.2,1);
    position: relative;
}
.sn-mini-radar-wrap::after {
    content: 'Ver diana'; position: absolute; bottom: -2px;
    font-size: .55em; font-weight: 700; color: #6c757d;
    background: #fff; border: 1px solid #e9ecef; border-radius: 8px;
    padding: 1px 6px; letter-spacing: .03em; text-transform: uppercase;
    opacity: 0; transition: opacity .2s;
    pointer-events: none;
}
.sn-mini-radar-wrap:hover {
    border-color: #93c5fd; box-shadow: 0 0 0 3px rgba(37,99,235,.1);
    transform: scale(1.05);
}
.sn-mini-radar-wrap:hover::after { opacity: 1; }
#sn-mini-radar { display: block; }

/* ── Header stats ──────────────────────────────────────────────── */
.sn-header-stats {
    display: flex; gap: 1rem; flex-shrink: 0;
    align-items: center; justify-content: flex-end;
}
.sn-stat-block { text-align: center; flex: 0 0 auto; min-width: 0; }
.sn-stat-num  { font-size: 1.6em; font-weight: 800; color: #111; line-height: 1; }
.sn-stat-num.green { color: #15803d; }
.sn-stat-label { font-size: .68em; font-weight: 600; color: #9ca3af;
    letter-spacing: .06em; text-transform: uppercase; margin-top: 2px; }
.sn-stat-divider { width: 1px; background: #e5e7eb; height: 36px; }
.sn-repr-wrap {
    position: relative; display: inline-block;
}
.sn-repr-badge {
    display: inline-flex; align-items: center; gap: 5px;
    font-size: .72em; font-weight: 700; padding: 3px 10px; border-radius: 14px;
    cursor: help;
}
.sn-repr-tip {
    display: none; position: absolute; bottom: calc(100% + 8px); left: 50%;
    transform: translateX(-50%); z-index: 100;
    width: 300px; padding: 14px 16px; border-radius: 12px;
    background: #fff; border: 1px solid #e2e8f0;
    box-shadow: 0 8px 24px rgba(0,0,0,.12), 0 2px 6px rgba(0,0,0,.06);
    font-size: .82em; font-weight: 400; line-height: 1.5; color: #374151;
    pointer-events: none; white-space: normal; text-align: left;
}
.sn-repr-tip::after {
    content: ''; position: absolute; top: 100%; left: 50%;
    transform: translateX(-50%);
    border: 6px solid transparent; border-top-color: #fff;
}
.sn-repr-wrap:hover .sn-repr-tip { display: block; }
.sn-repr-tip--down {
    bottom: auto; top: calc(100% + 8px);
}
.sn-repr-tip--down::after {
    top: auto; bottom: 100%;
    border-top-color: transparent; border-bottom-color: #fff;
}
.sn-repr-tip-head {
    font-weight: 700; font-size: 1em; margin-bottom: 6px;
}
.sn-repr-tip-head--red   { color: #dc2626; }
.sn-repr-tip-head--amber { color: #d97706; }
.sn-repr-tip-head--green { color: #16a34a; }
.sn-repr-tip-head--grey  { color: #9ca3af; }
.sn-repr-tip-pct {
    font-size: 1.6em; font-weight: 800; line-height: 1; margin-bottom: 6px;
    font-variant-numeric: tabular-nums;
}
.sn-repr-tip-rec {
    margin-top: 8px; padding-top: 8px; border-top: 1px solid #f1f5f9;
    font-size: .88em; color: #6b7280; font-style: italic;
}
.sn-repr-badge--red   { background: #fef2f2; color: #dc2626; border: 1px solid #fecaca; }
.sn-repr-badge--amber { background: #fffbeb; color: #d97706; border: 1px solid #fde68a; }
.sn-repr-badge--green { background: #f0fdf4; color: #16a34a; border: 1px solid #bbf7d0; }
.sn-repr-badge--grey  { background: #f3f4f6; color: #9ca3af; border: 1px solid #e5e7eb; }
.sn-repr-dot {
    width: 8px; height: 8px; border-radius: 50%;
}
.sn-repr-dot--red   { background: #ef4444; }
.sn-repr-dot--amber { background: #f59e0b; }
.sn-repr-dot--green { background: #22c55e; }
.sn-repr-dot--grey  { background: #d1d5db; }
.sn-card-repr {
    display: flex; align-items: center; gap: 5px; margin-top: 6px;
    font-size: .68em; font-weight: 600;
}
.sn-card-repr--red   .sn-card-repr-txt { color: #dc2626; }
.sn-card-repr--amber .sn-card-repr-txt { color: #d97706; }
.sn-card-repr--green .sn-card-repr-txt { color: #16a34a; }
.sn-card-repr--grey  .sn-card-repr-txt { color: #9ca3af; }
.sn-card-repr-dot {
    width: 7px; height: 7px; border-radius: 50%; flex-shrink: 0;
}
.sn-card-repr-dot--red   { background: #ef4444; }
.sn-card-repr-dot--amber { background: #f59e0b; }
.sn-card-repr-dot--green { background: #22c55e; }
.sn-card-repr-dot--grey  { background: #d1d5db; }

/* Objectives — unified lollipop + detail cards */
.sn-obj-tooltip-text {
    font-size: .72em; color: #9ca3af; font-style: italic; margin-top: 8px;
    padding: 8px 12px; background: #f9fafb; border-radius: 8px;
    border: 1px solid #f3f4f6;
}
.sn-obj-lollipop-wrap {
    position: relative; padding: 16px 12px 8px; margin-bottom: 18px;
    background: #fff; border: 1px solid #e5e7eb; border-radius: 14px;
}
.sn-obj-lollipop-wrap svg { display: block; width: 100%; height: auto; }
.sn-obj-tip {
    display: none; position: absolute; z-index: 50; pointer-events: none;
    background: #fff; border: 1px solid #e2e5ea; border-radius: 10px;
    box-shadow: 0 6px 24px rgba(0,0,0,.10); padding: 10px 14px;
    min-width: 220px; max-width: 340px; font-size: .78rem; line-height: 1.45;
}
.sn-obj-tip-code { font-weight: 800; font-size: .95rem; margin-bottom: 3px; }
.sn-obj-tip-text { color: #6b7280; margin-bottom: 6px; }
.sn-obj-tip-grade { font-weight: 700; font-variant-numeric: tabular-nums; }
.sn-obj-grid { display: flex; flex-direction: column; gap: 10px; transition: max-height .3s ease, opacity .25s ease; }
.sn-obj-grid.sn-collapsed { max-height: 0 !important; opacity: 0; margin: 0; pointer-events: none; overflow: hidden; }
.sn-obj-collapse-btn {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 6px 14px; border-radius: 8px;
    border: 1px solid #E2E8F0; background: #FAFBFD;
    color: #64748B; font-size: .72rem; font-weight: 600;
    font-family: system-ui, -apple-system, sans-serif;
    cursor: pointer; transition: all .18s ease;
    letter-spacing: .03em; margin: 10px 0 6px;
}
.sn-obj-collapse-btn:hover {
    background: #F1F5F9; color: #334155;
    border-color: #CBD5E1; box-shadow: 0 1px 4px rgba(0,0,0,.06);
}
.sn-obj-collapse-btn svg { transition: transform .2s; }
.sn-obj-collapse-btn.sn-collapsed svg { transform: rotate(-90deg); }
.sn-obj-card {
    padding: 14px 16px; border-radius: 12px;
    background: #fff; border: 1px solid #e5e7eb; transition: box-shadow .2s;
}
.sn-obj-card:hover { box-shadow: 0 2px 12px rgba(0,0,0,.06); }
.sn-obj-header {
    display: flex; align-items: flex-start; gap: 10px; margin-bottom: 4px;
}
.sn-obj-code {
    width: 28px; height: 28px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-weight: 800; font-size: .82em; color: #fff; text-transform: lowercase;
    flex-shrink: 0;
}
.sn-obj-text {
    flex: 1; min-width: 0; font-size: .78em; color: #4b5563; line-height: 1.5;
}
.sn-obj-avg {
    font-weight: 800; font-size: 1.15em; flex-shrink: 0;
    font-variant-numeric: tabular-nums;
}
.sn-obj-sparkline-wrap {
    display: inline-flex; align-items: center; gap: 6px; margin-left: 4px;
    vertical-align: middle;
}
.sn-obj-sparkline-wrap svg { display: block; }
.sn-obj-pills {
    display: flex; flex-wrap: wrap; gap: 4px; margin-top: 6px; padding-left: 38px;
}
.sn-obj-pill {
    font-size: .65rem; font-weight: 600; padding: 2px 8px; border-radius: 10px;
    background: #f1f5f9; color: #475569; letter-spacing: .03em;
    position: relative; cursor: default;
}
.sn-obj-pill .sn-pill-tip {
    display: none; position: absolute; bottom: calc(100% + 8px); left: 50%;
    transform: translateX(-50%); z-index: 60; pointer-events: none;
    background: #fff; border: 1px solid #e2e5ea; border-radius: 10px;
    box-shadow: 0 6px 24px rgba(0,0,0,.10); padding: 10px 14px;
    min-width: 200px; max-width: 340px; width: max-content;
    font-size: .75rem; font-weight: 400; line-height: 1.5; color: #374151;
    text-align: left; letter-spacing: 0;
}
@media (max-width: 680px) {
    .sn-obj-pill .sn-pill-tip {
        left: 0; transform: none; min-width: 180px; max-width: 280px;
    }
    .sn-obj-pill .sn-pill-tip::after { left: 16px; }
}
.sn-obj-pill .sn-pill-tip::after {
    content: ''; position: absolute; top: 100%; left: 50%; transform: translateX(-50%);
    border: 6px solid transparent; border-top-color: #fff;
}
.sn-obj-pill:hover .sn-pill-tip { display: block; }
.sn-obj-pill .sn-pill-tip-code {
    font-weight: 800; font-size: .95em; margin-bottom: 6px;
    letter-spacing: .04em;
}
.sn-obj-pill .sn-pill-tip-desc {
    display: block; font-style: italic; color: #4b5563; line-height: 1.55;
    margin-top: 8px;
}
.sn-obj-footer {
    display: flex; gap: 8px; align-items: center; margin-top: 6px;
}
.sn-stats-row {
    display: flex; gap: 1.2rem; flex-shrink: 0;
    align-items: center; justify-content: flex-end;
}
@media (max-width: 680px) {
    .sn-student-header { flex-wrap: wrap; gap: .8rem; }
    .sn-mini-radar-wrap { width: 60px; height: 60px; order: -1; }
    #sn-mini-radar { width: 60px; height: 60px; }
    .sn-header-stats { width: 100%; justify-content: space-around; }
}

/* ── Pestañas vista (dos grupos) ──────────────────────────────────── */
/* ── Macro tabs (Visión Global / Materia) ────────────────────── */
.sn-macro-tabs-wrap {
    position: relative;
}
.sn-macro-tabs {
    display: flex; gap: 5px; margin-bottom: 0; padding: 0;
    flex-wrap: wrap;
}
.sn-macro-tab {
    font-weight: 600; font-size: .78rem; padding: 7px 13px;
    border-radius: 10px 10px 0 0; border: none; cursor: pointer;
    transition: all .3s cubic-bezier(.4,0,.2,1);
    position: relative; letter-spacing: .01em;
    display: inline-flex; align-items: center; gap: 5px;
    white-space: nowrap;
}
.sn-macro-tab .sn-tab-icon {
    width: 18px; height: 18px; display: inline-flex; align-items: center;
    justify-content: center; border-radius: 5px; font-size: .7rem;
    transition: all .3s;
}
.sn-macro-global {
    background: #e9ecef; color: #868e96;
}
.sn-macro-global .sn-tab-icon { background: #ced4da; color: #495057; }
.sn-macro-global:hover:not(.active) {
    background: #dee2e6; color: #495057;
}
.sn-macro-global.active {
    background: linear-gradient(135deg, #495057 0%, #343a40 100%);
    color: #fff;
    box-shadow: 0 4px 12px rgba(52,58,64,.35), inset 0 1px 0 rgba(255,255,255,.1);
}
.sn-macro-global.active .sn-tab-icon { background: rgba(255,255,255,.2); color: #fff; }
.sn-macro-subject {
    background: #fff3bf; color: #c28b00;
}
.sn-macro-subject .sn-tab-icon { background: #ffe066; color: #a17000; }
.sn-macro-subject:hover:not(.active) {
    background: #ffe066; color: #a17000;
}
.sn-macro-subject.active {
    background: linear-gradient(135deg, #fab005 0%, #f59f00 100%);
    color: #fff;
    box-shadow: 0 4px 12px rgba(245,159,0,.4), inset 0 1px 0 rgba(255,255,255,.2);
}
.sn-macro-subject.active .sn-tab-icon { background: rgba(255,255,255,.25); color: #fff; }

/* ── Sub-navigation row ──────────────────────────────────────── */
.sn-sub-nav {
    display: flex; align-items: center; justify-content: space-between;
    padding: 10px 16px; margin-bottom: 1rem; gap: 10px;
    border-radius: 0 0 14px 14px;
    border: 1px solid #e5e7eb; border-top: none;
    transition: background .3s;
}
@media (max-width: 600px) { .sn-sub-nav { flex-wrap: wrap; } }
.sn-sub-nav--global { background: linear-gradient(180deg, #f1f3f5 0%, #f8f9fa 100%); }
.sn-sub-nav--subject { background: linear-gradient(180deg, #fff8e1 0%, #fffdf5 100%); border-color: #fde68a; }
.sn-subtabs-group { display: flex; gap: 6px; }
@media (max-width: 600px) { .sn-subtabs-group { flex-wrap: wrap; } }
.sn-subtab {
    background: transparent; border: none;
    padding: 6px 12px; border-radius: 20px;
    font-size: .8rem; font-weight: 600; color: #868e96; cursor: pointer;
    transition: all .25s cubic-bezier(.4,0,.2,1);
    display: inline-flex; align-items: center; gap: 6px;
    position: relative; white-space: nowrap;
}
.sn-subtab .sn-st-icon {
    width: 18px; height: 18px; display: inline-flex; align-items: center;
    justify-content: center; font-size: .7rem; flex-shrink: 0;
}
.sn-subtab:hover:not(.active) { color: #495057; background: rgba(0,0,0,.04); }
.sn-subtab.active {
    color: #fff;
    box-shadow: 0 2px 8px rgba(0,0,0,.12);
}
.sn-sub-nav--global .sn-subtab.active {
    background: linear-gradient(135deg, #495057 0%, #343a40 100%);
}
.sn-sub-nav--subject .sn-subtab.active {
    background: linear-gradient(135deg, #fab005 0%, #e8a000 100%);
    color: #fff;
}
.sn-view-toggle {
    display: flex; background: rgba(0,0,0,.06); padding: 3px;
    border-radius: 20px; gap: 2px; flex-shrink: 0;
}
.sn-toggle-btn {
    border: none; background: transparent; padding: 5px 12px;
    font-size: .76rem; font-weight: 600; border-radius: 16px;
    cursor: pointer; color: #868e96; transition: all .25s cubic-bezier(.4,0,.2,1);
    display: inline-flex; align-items: center; gap: 5px;
}
.sn-toggle-btn svg { width: 14px; height: 14px; filter: grayscale(1) opacity(.4); transition: filter .25s; }
.sn-toggle-btn.active {
    background: #fff; color: #212529;
    box-shadow: 0 2px 6px rgba(0,0,0,.1);
}
.sn-toggle-btn.active svg { filter: none; }
.sn-toggle-btn:hover:not(.active) { color: #495057; background: rgba(255,255,255,.5); }

.sn-view-pane { display: none; }
.sn-view-pane.active { display: block; }
#sentinel-pane-diana {
    background: #fff; border-radius: 12px; padding: 1rem; margin: 0 -.5rem;
}

/* ── Placeholder panes ───────────────────────────────────────── */
.sn-placeholder-pane {
    text-align: center; padding: 3rem 1.5rem;
    background: linear-gradient(135deg, #fff 0%, #f8f9fa 100%);
    border: 2px dashed #dee2e6; border-radius: 16px;
    color: #adb5bd;
}
.sn-placeholder-pane .sn-ph-icon { font-size: 2.5rem; margin-bottom: .8rem; opacity: .6; }
.sn-placeholder-pane h4 { font-size: 1rem; font-weight: 700; color: #495057; margin: 0 0 .4rem; }
.sn-placeholder-pane p { font-size: .85rem; margin: 0; max-width: 400px; margin-inline: auto; line-height: 1.5; color: #868e96; }

/* ── Subject-specific panes (warm tint) ─────────────────────────── */
.sn-subject-banner {
    background: linear-gradient(135deg, #fffbeb 0%, #fef3c7 100%);
    border: 1px solid #fde68a; border-radius: 10px;
    padding: .7rem 1.2rem; margin-bottom: 1rem;
    display: flex; align-items: center; gap: 1rem; flex-wrap: wrap;
}
.sn-subject-name { font-weight: 700; font-size: 1.05em; color: #92400e; }
.sn-subject-grade-box {
    margin-left: auto; text-align: center;
}
.sn-subject-grade-num {
    font-size: 1.8em; font-weight: 900; line-height: 1;
    font-variant-numeric: tabular-nums;
}
.sn-subject-grade-label {
    font-size: .65em; font-weight: 600; color: #b45309;
    text-transform: uppercase; letter-spacing: .08em; margin-top: 2px;
}
.sn-course-select {
    font-size: .84em; padding: 5px 10px; border: 1.5px solid #fbbf24;
    border-radius: 8px; background: #fff; cursor: pointer;
    color: #92400e; font-weight: 600; outline: none;
    transition: border-color .15s;
}
.sn-course-select:focus { border-color: #f59e0b; box-shadow: 0 0 0 2px rgba(245,158,11,.2); }
.sn-ce-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
    gap: 8px; margin-bottom: 1.5rem;
}
.sn-ce-card {
    border-radius: 8px; padding: 10px 12px;
    background: #fff; border: 1.5px solid transparent;
    cursor: pointer; transition: transform .1s, box-shadow .15s;
    box-shadow: 0 1px 3px rgba(0,0,0,.07); overflow: hidden;
}
.sn-ce-card:hover { transform: translateY(-2px); box-shadow: 0 6px 16px rgba(0,0,0,.11); background: var(--sn-tint) !important; }

/* ── Banner de contexto CE (indica que estás en competencias específicas) */
.sn-ce-context-bar {
    background: linear-gradient(135deg, #fffbeb 0%, #fef3c7 100%);
    border: 1px solid #fde68a; border-radius: 8px;
    padding: 6px 14px; margin-bottom: 10px;
    font-size: .78em; color: #92400e; line-height: 1.4;
}
.sn-ce-context-bar strong { font-weight: 700; }
.sn-ce-context-icon { font-size: 1em; }
.sn-cc-context-bar {
    background: linear-gradient(135deg, #f0f4f8 0%, #e2e8f0 100%);
    border: 1px solid #cbd5e1; border-radius: 8px;
    padding: 6px 14px; margin-bottom: 10px;
    font-size: .78em; color: #334155; font-weight: 600; line-height: 1.4;
}

/* ── Leyenda niveles (oculta) ──────── */
.sn-level-legend { display: none; }
.sn-csv-btn {
    margin-left: auto; display: inline-flex; align-items: center; gap: 4px;
    font-size: .92em; font-weight: 600; color: #6b7280; text-decoration: none;
    padding: 2px 10px; border: 1px solid #d1d5db; border-radius: 5px;
    background: #fff; transition: all .15s; cursor: pointer;
}
.sn-csv-btn:hover { background: #f1f5f9; color: #374151; border-color: #9ca3af; }

/* ── Badges de nivel ──────────────────────────────────────────────── */
.sentinel-level-badge {
    display: inline-block; font-size: .82em; font-weight: 700;
    padding: 3px 10px; border-radius: 20px; letter-spacing: .02em;
}
.snl-avanzada  { background: #dcfce7; color: #15803d; }
.snl-adquirida { background: #d1fae5; color: #065f46; }
.snl-enproceso { background: #fef3c7; color: #92400e; }
.snl-iniciacion{ background: #fee2e2; color: #991b1b; }

/* ── Sección ──────────────────────────────────────────────────────── */
.sn-section-title {
    font-size: .72em; font-weight: 700; letter-spacing: .1em;
    text-transform: uppercase; color: #9ca3af; margin-bottom: .9rem;
}

/* ── Tarjetas área (Nivel 1) — estilo prototipo ───────────────────── */
.sentinel-card-band {
    height: 8px; border-radius: 8px 8px 0 0;
    margin: -1.2rem -1.4rem .9rem;
}
.sentinel-area-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
    gap: 8px; margin-bottom: 1.5rem;
}
.sentinel-area-card {
    border-radius: 8px; padding: 10px 12px;
    background: #fff; border: 1.5px solid transparent;
    cursor: pointer; transition: transform .1s, box-shadow .15s;
    box-shadow: 0 1px 3px rgba(0,0,0,.07); overflow: hidden;
}
.sentinel-area-card:hover { transform: translateY(-2px); box-shadow: 0 6px 16px rgba(0,0,0,.11); background: var(--sn-tint) !important; }
.sn-ck-top  { display:flex; align-items:center; justify-content:space-between; margin-bottom:4px; }
.sn-ck-code { font-size:.75em; font-weight:700; letter-spacing:.04em; text-transform:uppercase; }
.sn-ck-grade{ font-size:1.3em; font-weight:800; line-height:1; font-variant-numeric:tabular-nums; }
.sn-ck-global { font-size:.72em; margin-bottom:5px; line-height:1.2; }
.sn-ck-name    { font-size:.72em; color:#6b7280; margin-bottom:2px; line-height:1.3; min-height:2.2em; }
.sn-ck-courses { font-size:.66em; color:#c0c5cc; margin-bottom:5px; line-height:1.2; }
.sn-ck-meta { display:flex; gap:8px; margin-top:6px; flex-wrap:wrap; }
.sn-ck-stat { font-size:.72em; color:#6b7280; }
.sn-ck-stat b { font-weight:700; color:#374151; }
/* legacy kept for L2/L3 */
.sn-area-code-pill {
    display: inline-block; font-size: .74em; font-weight: 700;
    padding: 3px 11px; border-radius: 5px; margin-bottom: .35rem;
    letter-spacing: .06em; text-transform: uppercase; color: #fff;
}
.sn-dot { color: #d1d5db; }

/* ── Tarjetas descriptor (Nivel 2) — estilo prototipo ────────────── */
.sentinel-desc-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
    gap: 8px; margin: 1rem 0;
}
.sentinel-desc-card {
    border-radius: 8px; padding: 10px 12px;
    background: #fff; border: 1.5px solid transparent;
    cursor: pointer; transition: border-color .15s, transform .1s, box-shadow .15s, background .15s;
    box-shadow: 0 1px 3px rgba(0,0,0,.07); overflow: hidden;
}
.sentinel-desc-card .sentinel-card-band { margin: -10px -12px 8px; }
.sentinel-desc-card:hover { transform: translateY(-2px); box-shadow: 0 6px 16px rgba(0,0,0,.11); background: var(--sn-tint) !important; }
/* Two-column header: code+grade left, history+compare right */
.sn-dt     { display:flex; align-items:flex-start; justify-content:space-between; margin-bottom:5px; gap:8px; }
.sn-dt-l   { display:flex; flex-direction:column; gap:2px; }
.sn-dgrade-row { display:flex; align-items:baseline; gap:5px; }
.sn-dgrade { font-size:1.1em; font-weight:800; line-height:1; font-variant-numeric:tabular-nums; }
.sn-docc   { font-size:.7em; color:#9ca3af; }
.sn-d-courses { font-size:.66em; color:#c0c5cc; margin-top:4px; line-height:1.2; }
.sn-dt-r   { display:flex; flex-direction:column; align-items:flex-end; gap:4px; flex-shrink:0; }
.sn-d-trend{ display:flex; gap:5px; font-size:.65em; }
.sn-d-t    { display:flex; flex-direction:column; align-items:center; gap:1px; }
.sn-d-tval { font-weight:700; }
.sn-d-tlbl { color:#9ca3af; font-size:.85em; }
.sn-d-footer { display:flex; align-items:center; justify-content:space-between; margin-top:6px; }
.sn-d-arr  { font-size:.72em; color:#9ca3af; cursor:pointer; transition:color .12s; }
.sn-d-arr:hover { color:#2563eb; }
.sentinel-desc-unworked {
    cursor: default !important;
    border: 1.5px dashed #d1d5db !important;
    background: #fafafa !important;
    opacity: .75;
}
.sentinel-desc-unworked:hover {
    box-shadow: 0 1px 4px rgba(0,0,0,.07) !important;
    transform: none !important;
    border-color: #d1d5db !important;
}
.sn-area-chip {
    display: inline-block; font-size: .7em; font-weight: 700;
    padding: 2px 8px; border-radius: 5px; margin-bottom: .55rem;
}
.sn-desc-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: .4rem; }
.sn-desc-code   { font-weight: 800; font-size: .92em; letter-spacing: .04em; }
.sn-desc-text   {
    font-size: .84em; color: #6b7280; line-height: 1.5; margin-bottom: .45rem;
    display: -webkit-box; -webkit-line-clamp: 3; -webkit-box-orient: vertical; overflow: hidden;
}
.sn-desc-text-unworked { font-size: .8em; color: #9ca3af; line-height: 1.5; font-style: italic; margin-top: .4rem; }
.sn-desc-footer {
    font-size: .76em; color: #9ca3af; margin-top: .45rem;
    padding-top: .4rem; border-top: 1px solid #f3f4f6;
    display: flex; align-items: center; gap: .4rem; flex-wrap: wrap;
}
.sn-desc-footer strong { color: #6b7280; }
.sn-history { font-size: .73em; color: #9ca3af; margin-top: .3rem; }
.sn-history-grades { display: flex; gap: .25rem; align-items: baseline; }
.sn-hg { font-weight: 700; }
.sn-detail-link {
    font-size: .76em; color: #0d6efd; text-decoration: none;
    margin-top: .4rem; display: block;
}
.sn-detail-link:hover { text-decoration: underline; }

/* ── Nivel 3: tabla ───────────────────────────────────────────────── */
.sentinel-back {
    border: none; background: none; color: #0d6efd;
    cursor: pointer; font-size: .88em; padding: 0; margin-bottom: 1rem;
}
.sentinel-back:hover { text-decoration: underline; }
.sn-l3-header {
    background: #f8fafc; border-radius: 8px; padding: 1rem 1.2rem;
    margin-bottom: 1.2rem; border-left: 3px solid #e5e7eb;
}
.sn-l3-badge-row { display: flex; align-items: center; gap: .6rem; margin-bottom: .4rem; }
.sn-l3-desc { font-size: .88em; color: #6b7280; line-height: 1.55; margin-top: .35rem; }
.sn-act-table td { font-size: .9em; vertical-align: middle; }
.sn-act-table th { font-size: .8em; font-weight: 600; color: #6b7280; }

/* ── Selector de alumno ───────────────────────────────────────────── */
.sn-picker-bar {
    display: flex; align-items: center; gap: .5rem;
    margin-bottom: 1rem; flex-wrap: wrap;
}
.sn-picker-bar label {
    font-size: .82em; font-weight: 600; color: #6b7280;
    white-space: nowrap; margin: 0;
}
.sn-picker-filter {
    width: 160px; font-size: .84em;
    border: 1px solid #d1d5db; border-radius: 6px;
    padding: 5px 10px; outline: none;
    transition: border-color .15s;
}
.sn-picker-filter:focus { border-color: #0d6efd; box-shadow: 0 0 0 2px rgba(13,110,253,.15); }
.sn-picker-select {
    flex: 1; min-width: 200px; max-width: 380px;
    font-size: .84em; border: 1px solid #d1d5db; border-radius: 6px;
    padding: 5px 10px; background: #fff; cursor: pointer;
    outline: none; transition: border-color .15s;
}
.sn-picker-select:focus { border-color: #0d6efd; box-shadow: 0 0 0 2px rgba(13,110,253,.15); }
</style>
<?php

// ── Cabecera del alumno ───────────────────────────────────────────────────────
$initials   = mb_strtoupper(mb_substr($student->firstname, 0, 1) . mb_substr($student->lastname, 0, 1));
$profileurl = new moodle_url('/user/view.php', ['id' => $userid]);

// La foto Sentinel (subida por el profesor) solo se muestra si quien visualiza
// el informe NO es el propio alumno. El alumno ve su avatar de Moodle.
$sentinel_photo_url = null;
if (!$iscurrentuser) {
    $sentinel_photo_rec = $DB->get_record('local_sentinel_student_photo', ['studentid' => $userid]);
    $sentinel_photo_url = $sentinel_photo_rec
        ? (new moodle_url('/local/sentinel/photo.php', ['action' => 'serve', 'studentid' => $userid]))->out(false)
        : null;
}

// Buscar tutor del alumno (rol 'tutor' en los cursos donde está matriculado).
$tutor_user = null;
if ($tutor_role) {
    $student_courses = enrol_get_users_courses($userid, true);
    foreach ($student_courses as $sc) {
        $cctx = context_course::instance($sc->id);
        $role_users = get_role_users($tutor_role->id, $cctx, false, 'u.*', 'u.lastname ASC', false, '', '', '', 'u.id != :self', ['self' => $userid]);
        if (!empty($role_users)) {
            $tutor_user = reset($role_users);
            break;
        }
    }
}

echo html_writer::start_tag('div', [
    'class'      => 'sentinel-report',
    'data-title' => get_string('report:title', 'local_sentinel'),
]);

// ── Barra de periodo + niveles + CSV ─────────────────────────────────────────
$baseurl_norm = new moodle_url('/local/sentinel/student_report.php',
    array_filter(['userid' => $userid, 'cutoff' => $cutoff, 'courseid' => $selected_courseid]));
$exporturl = new moodle_url('/local/sentinel/student_report.php',
    array_filter(['userid' => $userid, 'cutoff' => $cutoff, 'norm' => $norm, 'export' => 'csv', 'courseid' => $selected_courseid]));

// ── Selector de alumno ────────────────────────────────────────────────────────
if (!empty($selector_students) && !$iscurrentuser) {
    echo '<div class="sn-picker-bar">';
    echo '<label for="sn-picker-filter">Alumno:</label>';
    echo '<input type="text" id="sn-picker-filter" class="sn-picker-filter"'
       . ' placeholder="Filtrar por nombre..." autocomplete="off">';
    echo '<select id="sn-picker-select" class="sn-picker-select">';
    foreach ($selector_students as $s) {
        $sel = ((int)$s->id === $userid) ? ' selected' : '';
        echo '<option value="' . (int)$s->id . '"' . $sel . '>'
           . s(fullname($s)) . '</option>';
    }
    echo '</select>';
    echo '<a href="' . $exporturl->out(false) . '" class="sn-csv-btn" title="Exportar CSV">'
       . '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">'
       . '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/>'
       . '</svg> CSV</a>';
    echo '</div>';
    echo '<script>'
       . 'document.getElementById("sn-picker-filter").addEventListener("input",function(){'
       .   'var q=this.value.toLowerCase();'
       .   'var sel=document.getElementById("sn-picker-select");'
       .   'Array.from(sel.options).forEach(function(o){o.hidden=q&&!o.text.toLowerCase().includes(q);});'
       . '});'
       . 'document.getElementById("sn-picker-select").addEventListener("change",function(){'
       .   'if(this.value){'
       .     'window.location.href="' . (new moodle_url('/local/sentinel/student_report.php', ['cutoff' => $cutoff, 'norm' => $norm, 'courseid' => $selected_courseid]))->out(false) . '&userid="+this.value;'
       .   '}'
       . '});'
       . '</script>';
} else {
    // Sin selector de alumno: botón CSV suelto.
    echo '<div style="display:flex;justify-content:flex-end;margin-bottom:8px">';
    echo '<a href="' . $exporturl->out(false) . '" class="sn-csv-btn" title="Exportar CSV">'
       . '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">'
       . '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/>'
       . '</svg> CSV</a>';
    echo '</div>';
}

// DUA self-assessment data (global, per area).
$selfeval_areas = descriptor_calculator::get_student_selfeval_areas($userid);
$area_codes_early = array_keys(descriptor_calculator::AREAS);
$selfeval_annual = [];
foreach ($area_codes_early as $ac) {
    $selfeval_annual[] = $selfeval_areas[$ac] ?? null;
}

// Mini radar data for header.
$mini_radar_vals = [];
$mini_radar_colors = [];
$mini_radar_occ = [];
$mini_radar_sub = [];
foreach (descriptor_calculator::AREAS as $acode => $adef) {
    $mini_radar_vals[] = $areas[$acode]['partial_grade'] ?? 0;
    $mini_radar_colors[] = $adef['color'];
    $mini_radar_occ[] = $areas[$acode]['occasions'];
    $mini_radar_sub[] = count($areas[$acode]['courses']);
}
$mini_all_courses = [];
foreach ($areas as $ad) { foreach ($ad['courses'] as $c) { $mini_all_courses[$c] = true; } }
$mini_all_list = array_keys($mini_all_courses);
$mini_tc = count($mini_all_courses);
$mini_co = [];
foreach (descriptor_calculator::AREAS as $acode => $adef) {
    $co = array_fill_keys($mini_all_list, 0);
    foreach ($areas[$acode]['descriptors'] as $ddata) {
        foreach ($ddata['activities'] as $act) {
            $cn = $act['coursename'] ?? '';
            if (isset($co[$cn])) { $co[$cn]++; }
        }
    }
    $mini_co[] = array_values($co);
}
$mini_radar_json = json_encode([
    'v' => $mini_radar_vals, 'c' => $mini_radar_colors,
    'occ' => $mini_radar_occ, 'sub' => $mini_radar_sub,
    'tc' => $mini_tc, 'co' => $mini_co,
]);

// Tarjeta de cabecera.
$has_subject = !empty($visible_courses) && $ce_data && !empty($ce_data['ces']);
echo '<div class="sn-header-wrap">';
echo '<div class="sn-student-header">';

// ── Avatar ──
if ($sentinel_photo_url) {
    echo '<img src="' . $sentinel_photo_url . '" class="sn-avatar sn-avatar-photo"'
       . ' alt="' . s(fullname($student)) . '"'
       . ' onerror="this.style.display=\'none\';this.nextElementSibling.style.display=\'flex\'">';
    echo '<div class="sn-avatar" style="display:none">' . s($initials) . '</div>';
} else {
    echo '<div class="sn-avatar">' . s($initials) . '</div>';
}

// ── Nombre + tutor ──
echo '<div class="sn-identity">';
echo '<div class="sn-name">' . html_writer::link($profileurl, fullname($student)) . '</div>';
if ($tutor_user) {
    $msgurl = new moodle_url('/message/index.php', ['id' => $tutor_user->id]);
    echo '<div class="sn-meta" style="margin-top:2px">'
       . '<span style="color:#9ca3af">Tutor/a:</span> '
       . html_writer::link($msgurl, fullname($tutor_user), [
             'title' => 'Enviar mensaje a ' . fullname($tutor_user),
             'style' => 'color:#2563eb;font-weight:500;text-decoration:none',
         ])
       . '</div>';
}
echo '</div>';

// ── Mini diana de competencias clave (clicable) ──
echo '<div class="sn-mini-radar-wrap" onclick="sentinelMacro(\'global\');sentinelSubtab(\'cc\');sentinelToggleView(\'diana\');'
   . 'document.getElementById(\'sentinel-pane-diana\')&&document.getElementById(\'sentinel-pane-diana\').scrollIntoView({behavior:\'smooth\',block:\'start\'})"'
   . ' title="Ver diana de competencias clave" role="button" tabindex="0">';
echo '<canvas id="sn-mini-radar" width="72" height="72"></canvas>';
echo '</div>';

// ── Stats globales ──
echo '<div class="sn-header-stats">';
if ($media_global !== null) {
    $gcolor = descriptor_calculator::grade_colour($media_global);
    echo '<div class="sn-stat-block">';
    echo '<div class="sn-repr-wrap">';
    echo '<div class="sn-stat-num" style="color:' . $gcolor . ';cursor:help">' . number_format($media_global, 1) . '</div>';
    echo '<div class="sn-stat-label" style="cursor:help">MEDIA</div>';
    if ($lomloe_global !== null && $lomloe_global < $media_global - 0.05) {
        $lgcolor = descriptor_calculator::grade_colour($lomloe_global);
        echo '<div style="font-size:.72em;font-weight:700;color:' . $lgcolor . ';margin-top:2px;letter-spacing:.02em;cursor:help">'
           . 'LOMLOE: ' . number_format($lomloe_global, 1) . '</div>';
    }
    $n_areas_worked = count($worked_areas);
    $n_areas_total  = count($areas);
    echo '<div class="sn-repr-tip sn-repr-tip--down">';
    echo '<div class="sn-repr-tip-head" style="color:#2563eb">📊 Nota media global</div>';
    echo '<div class="sn-repr-tip-pct" style="color:' . $gcolor . '">' . number_format($media_global, 1) . '</div>';
    echo '<div>Media de las <b>' . $n_areas_worked . '</b> competencias clave en las que se ha evaluado '
       . 'al menos un descriptor del perfil de salida. En cada competencia clave, '
       . 'la nota se calcula solo con los descriptores <b>trabajados</b> (los no evaluados no penalizan).</div>';
    if ($lomloe_global !== null && $lomloe_global < $media_global - 0.05) {
        $lgcolor2 = descriptor_calculator::grade_colour($lomloe_global);
        echo '<div class="sn-repr-tip-rec" style="font-style:normal">'
           . '<b style="color:' . $lgcolor2 . '">LOMLOE: ' . number_format($lomloe_global, 1) . '</b> — '
           . 'Media de las ' . $n_areas_total . ' competencias clave, pero aquí los descriptores '
           . '<b>no evaluados computan como 0</b>. Es la nota oficial LOMLOE: '
           . 'refleja que las competencias no trabajadas aún no están adquiridas.</div>';
    }
    echo '</div>'; // .sn-repr-tip
    echo '</div>'; // .sn-repr-wrap
    echo '</div>';
    echo '<div class="sn-stat-divider"></div>';
}
echo '<div class="sn-stat-block">';
echo '<div class="sn-repr-wrap">';
echo '<div class="sn-stat-num" style="cursor:help">' . $summary['worked_descriptors'] . '<span style="font-size:.55em;color:#9ca3af">/' . $summary['total_descriptors'] . '</span></div>';
echo '<div class="sn-stat-label" style="cursor:help">' . get_string('report:descriptors_label', 'local_sentinel') . '</div>';
$wd = (int)$summary['worked_descriptors'];
$td = (int)$summary['total_descriptors'];
$dpct = $td > 0 ? round($wd / $td * 100) : 0;
echo '<div class="sn-repr-tip sn-repr-tip--down">';
echo '<div class="sn-repr-tip-head" style="color:#8b5cf6">🎯 Descriptores del perfil de salida</div>';
echo '<div class="sn-repr-tip-pct" style="color:#6d28d9">' . $wd . '<span style="font-size:.5em;color:#9ca3af">/' . $td . '</span></div>';
echo '<div>Se han trabajado <b>' . $wd . '</b> de los <b>' . $td . '</b> descriptores operativos '
   . 'del perfil de salida definidos en el RD 217/2022 (' . $dpct . '%).</div>';
echo '<div class="sn-repr-tip-rec">Cada descriptor se vincula a las competencias específicas de las materias. '
   . 'Un descriptor se considera trabajado cuando al menos una competencia específica asociada ha sido evaluada.</div>';
echo '</div>'; // .sn-repr-tip
echo '</div>'; // .sn-repr-wrap
echo '</div>';
echo '<div class="sn-stat-divider"></div>';
echo '<div class="sn-stat-block">';
echo '<div class="sn-repr-wrap">';
echo '<div class="sn-stat-num" style="cursor:help">' . $summary['total_occasions'] . '</div>';
echo '<div class="sn-stat-label" style="cursor:help">' . get_string('report:occasions_label', 'local_sentinel') . '</div>';
$occ_n = (int)$summary['total_occasions'];
$occ_c = (int)($summary['total_courses'] ?? 0);
echo '<div class="sn-repr-tip sn-repr-tip--down">';
echo '<div class="sn-repr-tip-head" style="color:#2563eb">📋 Ocasiones de evaluación</div>';
echo '<div class="sn-repr-tip-pct" style="color:#1e40af">' . $occ_n . '</div>';
echo '<div>Actividades evaluadas con rúbrica a lo largo de <b>' . $occ_c . '</b> '
   . ($occ_c === 1 ? 'materia' : 'materias') . '.</div>';
echo '<div class="sn-repr-tip-rec">Cada ocasión de evaluación es una actividad calificada mediante rúbrica '
   . 'cuyos criterios están vinculados a competencias específicas.</div>';
echo '</div>'; // .sn-repr-tip
echo '</div>'; // .sn-repr-wrap
echo '</div>';
$rg = $repr_global;
echo '<div class="sn-stat-divider"></div>';
echo '<div class="sn-stat-block">';
echo '<div class="sn-repr-wrap">';

// Badge.
echo '<span class="sn-repr-badge sn-repr-badge--' . $rg['level'] . '">';
echo '<span class="sn-repr-dot sn-repr-dot--' . $rg['level'] . '"></span>';
if ($rg['level'] !== 'grey') {
    echo number_format($rg['pct'], 0) . '% · ';
}
echo s($rg['label']);
echo '</span>';

// Floating tooltip card.
$rg_icons = ['red' => '⚠️', 'amber' => '⚡', 'green' => '✅', 'grey' => '○'];
$rg_recs = [
    'grey'  => get_string('sample:rec_grey',  'local_sentinel'),
    'red'   => get_string('sample:rec_red',   'local_sentinel', number_format($rg['pct'], 0)),
    'amber' => get_string('sample:rec_amber', 'local_sentinel', number_format($rg['pct'], 0)),
    'green' => get_string('sample:rec_green', 'local_sentinel', number_format($rg['pct'], 0)),
];
echo '<div class="sn-repr-tip">';
echo '<div class="sn-repr-tip-head sn-repr-tip-head--' . $rg['level'] . '">'
   . $rg_icons[$rg['level']] . ' ' . s($rg['label']) . '</div>';
if ($rg['level'] !== 'grey') {
    $pct_color = descriptor_calculator::grade_colour($rg['pct'] / 10);
    echo '<div class="sn-repr-tip-pct" style="color:' . $pct_color . '">'
       . number_format($rg['pct'], 0) . '%</div>';
    echo '<div>' . get_string('sample:tooltip_' . $rg['level'], 'local_sentinel', number_format($rg['pct'], 0)) . '</div>';
}
echo '<div class="sn-repr-tip-rec">' . $rg_recs[$rg['level']] . '</div>';
echo '</div>'; // .sn-repr-tip

echo '</div>'; // .sn-repr-wrap
echo '</div>';
echo '</div>'; // .sn-header-stats
echo '</div>'; // .sn-student-header
echo '</div>'; // .sn-header-wrap

echo '<script>'
   . '(function(){'
   . 'var c=document.getElementById("sn-mini-radar");'
   . 'if(!c)return;'
   . 'var ctx=c.getContext("2d");'
   . 'var W=c.width,H=c.height,cx=W/2,cy=H/2,R=30;'
   . 'var vals=' . json_encode($mini_radar_vals) . ';'
   . 'var colors=' . json_encode($mini_radar_colors) . ';'
   . 'var n=vals.length;'
   . 'if(n<3)return;'
   . 'function a(i){return Math.PI*2*i/n-Math.PI/2;}'
   . 'function xy(i,r){return[cx+r*Math.cos(a(i)),cy+r*Math.sin(a(i))];}'
   // Grid: polígonos concéntricos (octogonales).
   . 'ctx.strokeStyle="#e5e7eb";ctx.lineWidth=.5;'
   . '[.2,.4,.6,.8,1].forEach(function(s){'
   . 'ctx.beginPath();for(var i=0;i<n;i++){var p=xy(i,R*s);if(i===0)ctx.moveTo(p[0],p[1]);else ctx.lineTo(p[0],p[1]);}'
   . 'ctx.closePath();ctx.stroke();});'
   // Ejes.
   . 'for(var i=0;i<n;i++){var p=xy(i,R);ctx.beginPath();ctx.moveTo(cx,cy);ctx.lineTo(p[0],p[1]);ctx.stroke();}'
   // Polígono de datos.
   . 'var hasData=vals.some(function(v){return v>0;});'
   . 'if(hasData){'
   . 'ctx.fillStyle="rgba(37,99,235,.12)";ctx.strokeStyle="#2563eb";ctx.lineWidth=1.5;'
   . 'ctx.beginPath();'
   . 'for(var i=0;i<n;i++){var v=Math.max(0,Math.min(10,vals[i]));var p=xy(i,R*v/10);'
   . 'if(i===0)ctx.moveTo(p[0],p[1]);else ctx.lineTo(p[0],p[1]);}'
   . 'ctx.closePath();ctx.fill();ctx.stroke();'
   // Puntos con anillo blanco (estilo diana grande).
   . 'for(var i=0;i<n;i++){var v=Math.max(0,Math.min(10,vals[i]));if(v>0){'
   . 'var p=xy(i,R*v/10);'
   . 'ctx.fillStyle=colors[i];ctx.beginPath();ctx.arc(p[0],p[1],3.5,0,Math.PI*2);ctx.fill();'
   . 'ctx.fillStyle="#fff";ctx.beginPath();ctx.arc(p[0],p[1],1.5,0,Math.PI*2);ctx.fill();}}}'
   // DUA dotted polygon overlay on mini radar.
   . 'var sev=' . json_encode($selfeval_annual) . ';'
   . 'var hasSe=sev.some(function(v){return v!==null&&v>0;});'
   . 'if(hasSe){'
   . 'ctx.save();ctx.setLineDash([3,3]);ctx.strokeStyle="rgba(37,99,235,.35)";ctx.lineWidth=1.2;'
   . 'ctx.beginPath();'
   . 'for(var i=0;i<n;i++){var v=Math.max(0,Math.min(10,sev[i]||0));var p=xy(i,R*v/10);'
   . 'if(i===0)ctx.moveTo(p[0],p[1]);else ctx.lineTo(p[0],p[1]);}'
   . 'ctx.closePath();ctx.stroke();ctx.restore();}'
   . '})();'
   . '</script>';

// ── Determine stage + subject name for navigation ────────────────────────────
$student_stage = null;
$student_subject_name = null;
$student_applied = null;
if ($selected_courseid) {
    $student_applied = \local_sentinel\curriculum_manager::get_applied($selected_courseid);
    if ($student_applied) {
        $student_stage = $student_applied['stage'] ?? null;
        $student_subject_name = $student_applied['subject_name'] ?? null;
    }
}
if (!$student_stage) {
    foreach ($visible_courses as $vc) {
        if ($vc->id == $selected_courseid) {
            continue;
        }
        $vc_applied = \local_sentinel\curriculum_manager::get_applied($vc->id);
        if ($vc_applied && !empty($vc_applied['stage'])) {
            $student_stage = $vc_applied['stage'];
            break;
        }
    }
}
if (!$student_subject_name && $selected_courseid && isset($visible_courses[$selected_courseid])) {
    $student_subject_name = $visible_courses[$selected_courseid]->fullname;
}
$show_transversales = in_array($student_stage, ['ei', 'ep', 'eso']);
$objectives_data = $student_stage
    ? descriptor_calculator::get_objectives_data($areas, $student_stage)
    : [];

$selfeval_sparklines = !empty($objectives_data['objectives'])
    ? descriptor_calculator::get_selfeval_sparklines($userid, $objectives_data['objectives'])
    : [];

$descriptor_texts = [];
if (!empty($objectives_data['objectives'])) {
    $all_descs = $DB->get_records('local_sentinel_descriptor', null, '', 'code, description');
    foreach ($all_descs as $d) {
        $descriptor_texts[$d->code] = $d->description;
    }
}

if ($summary['total_occasions'] === 0) {
    echo $OUTPUT->notification(get_string('report:nodata', 'local_sentinel'), 'info');
}

// ────────────────────────────────────────────────────────────────────────────
// Pestañas: dos grupos — Competencias clave / Competencias específicas
// ────────────────────────────────────────────────────────────────────────────
// SVGs para pestañas — competencias clave (tonos fríos).
$key_diana_svg = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" style="flex-shrink:0">'
   . '<polygon points="12,2 19.2,5 22,12 19.2,19 12,22 4.8,19 2,12 4.8,5" stroke="#94a3b8" stroke-width=".8" fill="none"/>'
   . '<polygon points="12,5.5 16.5,7.5 18.5,12 16.5,16.5 12,18.5 7.5,16.5 5.5,12 7.5,7.5" stroke="#cbd5e1" stroke-width=".5" fill="none"/>'
   . '<line x1="12" y1="2" x2="12" y2="22" stroke="#e5e7eb" stroke-width=".4"/>'
   . '<line x1="2" y1="12" x2="22" y2="12" stroke="#e5e7eb" stroke-width=".4"/>'
   . '<line x1="4.8" y1="5" x2="19.2" y2="19" stroke="#e5e7eb" stroke-width=".4"/>'
   . '<line x1="19.2" y1="5" x2="4.8" y2="19" stroke="#e5e7eb" stroke-width=".4"/>'
   . '<circle cx="12" cy="2" r="2.2" fill="#2563eb"/>'
   . '<circle cx="19.2" cy="5" r="2.2" fill="#7c3aed"/>'
   . '<circle cx="22" cy="12" r="2.2" fill="#0891b2"/>'
   . '<circle cx="19.2" cy="19" r="2.2" fill="#0d9488"/>'
   . '<circle cx="12" cy="22" r="2.2" fill="#d97706"/>'
   . '<circle cx="4.8" cy="19" r="2.2" fill="#b45309"/>'
   . '<circle cx="2" cy="12" r="2.2" fill="#dc2626"/>'
   . '<circle cx="4.8" cy="5" r="2.2" fill="#9333ea"/>'
   . '</svg>';
$key_cards_svg = '<svg width="18" height="14" viewBox="0 0 27 20" style="flex-shrink:0">'
   . '<rect x="1" y="1" width="7" height="4.5" rx="1" fill="#2563eb"/>'
   . '<rect x="10" y="1" width="7" height="4.5" rx="1" fill="#7c3aed"/>'
   . '<rect x="19" y="1" width="7" height="4.5" rx="1" fill="#0891b2"/>'
   . '<rect x="1" y="7.5" width="7" height="4.5" rx="1" fill="#0d9488"/>'
   . '<rect x="10" y="7.5" width="7" height="4.5" rx="1" fill="#d97706"/>'
   . '<rect x="19" y="7.5" width="7" height="4.5" rx="1" fill="#b45309"/>'
   . '<rect x="1" y="14" width="7" height="4.5" rx="1" fill="#dc2626"/>'
   . '<rect x="10" y="14" width="7" height="4.5" rx="1" fill="#9333ea"/>'
   . '</svg>';
// SVGs para subtabs — iconos multicolor.
$icon_objetivos = '<svg width="18" height="18" viewBox="0 0 20 20" fill="none" style="flex-shrink:0">'
   . '<circle cx="10" cy="10" r="8.5" stroke="#ef4444" stroke-width="1.5" fill="none"/>'
   . '<circle cx="10" cy="10" r="5.5" stroke="#f59e0b" stroke-width="1.5" fill="none"/>'
   . '<circle cx="10" cy="10" r="2.5" fill="#22c55e"/>'
   . '</svg>';
$icon_cc = '<svg width="18" height="18" viewBox="0 0 20 20" fill="none" style="flex-shrink:0">'
   . '<line x1="10" y1="2" x2="3" y2="8" stroke="#e2e8f0" stroke-width=".7"/>'
   . '<line x1="10" y1="2" x2="17" y2="8" stroke="#e2e8f0" stroke-width=".7"/>'
   . '<line x1="3" y1="8" x2="5.5" y2="16" stroke="#e2e8f0" stroke-width=".7"/>'
   . '<line x1="17" y1="8" x2="14.5" y2="16" stroke="#e2e8f0" stroke-width=".7"/>'
   . '<line x1="5.5" y1="16" x2="14.5" y2="16" stroke="#e2e8f0" stroke-width=".7"/>'
   . '<circle cx="10" cy="2" r="2" fill="#2563eb"/>'
   . '<circle cx="17" cy="8" r="2" fill="#7c3aed"/>'
   . '<circle cx="14.5" cy="16" r="2" fill="#0891b2"/>'
   . '<circle cx="5.5" cy="16" r="2" fill="#0d9488"/>'
   . '<circle cx="3" cy="8" r="2" fill="#d97706"/>'
   . '</svg>';
$icon_transversales = '<svg width="18" height="18" viewBox="0 0 20 20" fill="none" style="flex-shrink:0">'
   . '<line x1="2" y1="5" x2="18" y2="5" stroke="#0891b2" stroke-width="1.8" stroke-linecap="round"/>'
   . '<line x1="2" y1="10" x2="18" y2="10" stroke="#8b5cf6" stroke-width="1.8" stroke-linecap="round"/>'
   . '<line x1="2" y1="15" x2="18" y2="15" stroke="#f59e0b" stroke-width="1.8" stroke-linecap="round"/>'
   . '<circle cx="7" cy="5" r="2" fill="#ef4444"/>'
   . '<circle cx="13" cy="10" r="2" fill="#22c55e"/>'
   . '<circle cx="10" cy="15" r="2" fill="#2563eb"/>'
   . '</svg>';
$icon_ce = '<svg width="18" height="18" viewBox="0 0 20 20" fill="none" style="flex-shrink:0">'
   . '<path d="M10 2l2.2 5.2h5.6l-4.3 3.5 1.7 5.6L10 13l-5.2 3.3 1.7-5.6L2.2 7.2h5.6z" stroke="#92400e" stroke-width=".7" fill="#fef3c7"/>'
   . '<circle cx="10" cy="2" r="2" fill="#92400e" stroke="#fff" stroke-width=".6"/>'
   . '<circle cx="17.5" cy="7.5" r="2" fill="#10b981" stroke="#fff" stroke-width=".6"/>'
   . '<circle cx="15" cy="16.5" r="2" fill="#ef4444" stroke="#fff" stroke-width=".6"/>'
   . '<circle cx="5" cy="16.5" r="2" fill="#8b5cf6" stroke="#fff" stroke-width=".6"/>'
   . '<circle cx="2.5" cy="7.5" r="2" fill="#06b6d4" stroke="#fff" stroke-width=".6"/>'
   . '</svg>';
$icon_contenidos = '<svg width="18" height="18" viewBox="0 0 20 20" fill="none" style="flex-shrink:0">'
   . '<rect x="3" y="2" width="14" height="16" rx="2" stroke="#d97706" stroke-width="1" fill="#fffbeb"/>'
   . '<line x1="6" y1="6" x2="14" y2="6" stroke="#f59e0b" stroke-width="1.5" stroke-linecap="round"/>'
   . '<line x1="6" y1="9.5" x2="12" y2="9.5" stroke="#10b981" stroke-width="1.5" stroke-linecap="round"/>'
   . '<line x1="6" y1="13" x2="13" y2="13" stroke="#8b5cf6" stroke-width="1.5" stroke-linecap="round"/>'
   . '</svg>';

// SVGs para pestañas — competencias específicas (tonos cálidos).
$spec_diana_svg = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" style="flex-shrink:0">'
   . '<circle cx="12" cy="12" r="10" stroke="#f59e0b" stroke-width=".8" fill="none"/>'
   . '<circle cx="12" cy="12" r="6.5" stroke="#fbbf24" stroke-width=".5" fill="none"/>'
   . '<circle cx="12" cy="12" r="3" stroke="#fcd34d" stroke-width=".5" fill="none"/>'
   . '<line x1="12" y1="2" x2="12" y2="22" stroke="#fde68a" stroke-width=".4"/>'
   . '<line x1="2" y1="12" x2="22" y2="12" stroke="#fde68a" stroke-width=".4"/>'
   . '<circle cx="12" cy="2" r="2" fill="#f59e0b"/>'
   . '<circle cx="22" cy="12" r="2" fill="#10b981"/>'
   . '<circle cx="12" cy="22" r="2" fill="#ef4444"/>'
   . '<circle cx="2" cy="12" r="2" fill="#8b5cf6"/>'
   . '</svg>';
$spec_cards_svg = '<svg width="18" height="14" viewBox="0 0 27 20" style="flex-shrink:0">'
   . '<rect x="1" y="1" width="7" height="4.5" rx="1" fill="#f59e0b"/>'
   . '<rect x="10" y="1" width="7" height="4.5" rx="1" fill="#10b981"/>'
   . '<rect x="19" y="1" width="7" height="4.5" rx="1" fill="#ef4444"/>'
   . '<rect x="1" y="7.5" width="7" height="4.5" rx="1" fill="#8b5cf6"/>'
   . '<rect x="10" y="7.5" width="7" height="4.5" rx="1" fill="#06b6d4"/>'
   . '<rect x="19" y="7.5" width="7" height="4.5" rx="1" fill="#ec4899"/>'
   . '</svg>';

// ── MACRO TABS ──────────────────────────────────────────────────────────────
$has_subject_tab = !empty($visible_courses) && $selected_courseid && isset($visible_courses[$selected_courseid]);

echo '<div class="sn-macro-tabs-wrap">';
echo '<div class="sn-macro-tabs" id="sn-macro-tabs">';
$global_active = !$selected_courseid || empty($visible_courses) ? ' active' : '';
echo '<button class="sn-macro-tab sn-macro-global' . $global_active . '" data-macro="global" type="button" onclick="sentinelMacro(\'global\')">'
   . '<span class="sn-tab-icon">&#127760;</span> Visión Global</button>';
// Mapa de iconos SVG por materia (16×16, con color propio).
$subject_icons = [
    'musica'              => '<svg width="16" height="16" viewBox="0 0 16 16"><path d="M12 1v9.5" stroke="#8b5cf6" stroke-width="1.2"/><path d="M5 3v9" stroke="#2563eb" stroke-width="1.2"/><line x1="5" y1="3" x2="12" y2="1" stroke="#f59e0b" stroke-width="1"/><circle cx="3.5" cy="12" r="2" fill="#2563eb"/><circle cx="10.5" cy="10.5" r="2" fill="#8b5cf6"/></svg>',
    'matematicas'         => '<svg width="16" height="16" viewBox="0 0 16 16"><text x="2" y="12.5" font-size="12" font-weight="800" font-family="serif" fill="#2563eb">π</text><circle cx="13" cy="3.5" r="1.5" fill="#ef4444"/><circle cx="13" cy="12" r="1.5" fill="#22c55e"/></svg>',
    'matematicas_a'       => '<svg width="16" height="16" viewBox="0 0 16 16"><text x="2" y="12.5" font-size="12" font-weight="800" font-family="serif" fill="#2563eb">π</text><circle cx="13" cy="3.5" r="1.5" fill="#ef4444"/><rect x="11.5" y="10.5" width="3" height="3" rx=".6" fill="#f59e0b"/></svg>',
    'matematicas_b'       => '<svg width="16" height="16" viewBox="0 0 16 16"><text x="2" y="12.5" font-size="12" font-weight="800" font-family="serif" fill="#2563eb">π</text><circle cx="13" cy="3.5" r="1.5" fill="#ef4444"/><polygon points="13,10 15,13 11,13" fill="#8b5cf6"/></svg>',
    'ingles'              => '<svg width="16" height="16" viewBox="0 0 16 16"><text x="0" y="12" font-size="11" font-weight="800" font-family="system-ui" fill="#0891b2">A</text><text x="8.5" y="12" font-size="9" font-weight="800" font-family="system-ui" fill="#f59e0b">a</text></svg>',
    'segunda_lengua'      => '<svg width="16" height="16" viewBox="0 0 16 16"><text x="0" y="12" font-size="11" font-weight="800" font-family="system-ui" fill="#0d9488">A</text><text x="8.5" y="12" font-size="9" font-weight="800" font-family="system-ui" fill="#8b5cf6">b</text></svg>',
    'lengua'              => '<svg width="16" height="16" viewBox="0 0 16 16"><path d="M3 2h8a1.5 1.5 0 0 1 1.5 1.5v9a1.5 1.5 0 0 1-1.5 1.5H3" stroke="#dc2626" stroke-width="1.4" fill="none"/><path d="M5.5 1v14" stroke="#dc2626" stroke-width="1" opacity=".5"/><line x1="7.5" y1="5" x2="10.5" y2="5" stroke="#2563eb" stroke-width=".9"/><line x1="7.5" y1="7.5" x2="10.5" y2="7.5" stroke="#22c55e" stroke-width=".9"/><line x1="7.5" y1="10" x2="9.5" y2="10" stroke="#f59e0b" stroke-width=".9"/></svg>',
    'biologia_y_geologia' => '<svg width="16" height="16" viewBox="0 0 16 16"><path d="M8 14c-1-1.5-4-3.5-4-6.5C4 4.5 5.8 2 8 2s4 2.5 4 5.5c0 3-3 5-4 6.5z" fill="#22c55e20"/><path d="M8 14c-1-1.5-4-3.5-4-6.5C4 4.5 5.8 2 8 2s4 2.5 4 5.5c0 3-3 5-4 6.5z" stroke="#16a34a" stroke-width="1.2" fill="none"/><path d="M6.5 7.5c1-1 3-1 3 .5s-2 2-3 1.5" stroke="#16a34a" stroke-width=".8" fill="none"/><circle cx="5" cy="12.5" r="1.3" fill="#a16207"/><circle cx="11.5" cy="3.5" r="1" fill="#0891b2"/></svg>',
    'geografia_e_historia'=> '<svg width="16" height="16" viewBox="0 0 16 16"><circle cx="8" cy="8" r="5.5" stroke="#ea580c" stroke-width="1.3" fill="none"/><ellipse cx="8" cy="8" rx="2.5" ry="5.5" stroke="#2563eb" stroke-width=".7" fill="none" opacity=".6"/><line x1="2.5" y1="8" x2="13.5" y2="8" stroke="#22c55e" stroke-width=".7" opacity=".6"/><line x1="3.5" y1="5.5" x2="12.5" y2="5.5" stroke="#ea580c" stroke-width=".5" opacity=".4"/><line x1="3.5" y1="10.5" x2="12.5" y2="10.5" stroke="#ea580c" stroke-width=".5" opacity=".4"/></svg>',
    'educacion_fisica'    => '<svg width="16" height="16" viewBox="0 0 16 16"><circle cx="8" cy="3" r="1.8" fill="#f97316"/><path d="M4 8h8" stroke="#2563eb" stroke-width="1.3" stroke-linecap="round"/><path d="M8 6v6" stroke="#f97316" stroke-width="1.3" stroke-linecap="round"/><path d="M5 14l3-2 3 2" stroke="#22c55e" stroke-width="1.3" stroke-linecap="round" fill="none"/></svg>',
    'tecnologia'          => '<svg width="16" height="16" viewBox="0 0 16 16"><rect x="2" y="3" width="12" height="8" rx="1.5" stroke="#6366f1" stroke-width="1.2" fill="none"/><line x1="6" y1="11" x2="6" y2="13.5" stroke="#6366f1" stroke-width="1"/><line x1="10" y1="11" x2="10" y2="13.5" stroke="#6366f1" stroke-width="1"/><line x1="5" y1="13.5" x2="11" y2="13.5" stroke="#6366f1" stroke-width="1.2"/><circle cx="6" cy="7" r="1.2" fill="#22c55e"/><circle cx="10" cy="7" r="1.2" fill="#f59e0b"/></svg>',
    'educacion_plastica'  => '<svg width="16" height="16" viewBox="0 0 16 16"><circle cx="4.5" cy="5" r="2.5" fill="#f472b6" opacity=".5"/><circle cx="10.5" cy="5.5" r="2" fill="#a78bfa" opacity=".5"/><circle cx="7" cy="10.5" r="2.5" fill="#34d399" opacity=".45"/><path d="M12.5 1.5l1.2 4.5-3.5-.8z" fill="#f59e0b" opacity=".7"/></svg>',
    'fisica_y_quimica'    => '<svg width="16" height="16" viewBox="0 0 16 16"><path d="M6 2v5l-3 5a1.5 1.5 0 0 0 1.3 2.2h7.4A1.5 1.5 0 0 0 13 12L10 7V2" stroke="#7c3aed" stroke-width="1.2" fill="none"/><line x1="5" y1="2" x2="11" y2="2" stroke="#7c3aed" stroke-width="1.2"/><circle cx="7" cy="11" r="1.2" fill="#22c55e" opacity=".7"/><circle cx="9.5" cy="10" r="1" fill="#f59e0b" opacity=".7"/><circle cx="8.5" cy="12.5" r=".7" fill="#ef4444" opacity=".6"/></svg>',
    'cultura_clasica'     => '<svg width="16" height="16" viewBox="0 0 16 16"><path d="M4 13V5l4-3 4 3v8" stroke="#a16207" stroke-width="1.2" fill="none"/><line x1="6" y1="13" x2="6" y2="8" stroke="#dc2626" stroke-width="1" opacity=".7"/><line x1="10" y1="13" x2="10" y2="8" stroke="#2563eb" stroke-width="1" opacity=".7"/><line x1="3" y1="13" x2="13" y2="13" stroke="#a16207" stroke-width="1.3"/></svg>',
    'economia'            => '<svg width="16" height="16" viewBox="0 0 16 16"><text x="3" y="12.5" font-size="12" font-weight="800" font-family="system-ui" fill="#059669">€</text><path d="M12 3l1 3-1 3" stroke="#f59e0b" stroke-width="1.2" fill="none" stroke-linecap="round"/><path d="M12 3l-1 3 1 3" stroke="#ef4444" stroke-width="1.2" fill="none" stroke-linecap="round"/></svg>',
    'filosofia'           => '<svg width="16" height="16" viewBox="0 0 16 16"><text x="2.5" y="12" font-size="11" font-weight="800" font-family="serif" fill="#7c3aed">φ</text><circle cx="13" cy="4" r="1.5" fill="#0891b2"/><circle cx="13" cy="11" r="1.5" fill="#f59e0b"/></svg>',
    '_default'            => '<svg width="16" height="16" viewBox="0 0 16 16"><rect x="3" y="2" width="10" height="12" rx="1.5" stroke="#6b7280" stroke-width="1.2" fill="none"/><line x1="5.5" y1="5" x2="10.5" y2="5" stroke="#2563eb" stroke-width=".9"/><line x1="5.5" y1="7.5" x2="10.5" y2="7.5" stroke="#22c55e" stroke-width=".9"/><line x1="5.5" y1="10" x2="9" y2="10" stroke="#f59e0b" stroke-width=".9"/></svg>',
];

foreach ($visible_courses as $vc) {
    $is_active = ($vc->id == $selected_courseid);
    $active_cls = $is_active ? ' active' : '';
    $vc_applied = \local_sentinel\curriculum_manager::get_applied($vc->id);
    $vc_subject_key = $vc_applied['subject'] ?? '';
    // Nombre: primero del currículo, luego limpiar el fullname quitando prefijo tipo "ESO1B ".
    if (!empty($vc_applied['subject_name'])) {
        $label = $vc_applied['subject_name'];
    } else {
        $label = preg_replace('/^[A-Z0-9_]+\s+/', '', $vc->fullname) ?: $vc->fullname;
    }
    // Icono SVG por materia.
    $icon_svg = $subject_icons[$vc_subject_key] ?? null;
    if (!$icon_svg) {
        $fn_lower = mb_strtolower($label);
        foreach ($subject_icons as $k => $v) {
            if ($k !== '_default' && strpos($fn_lower, str_replace('_', ' ', $k)) !== false) {
                $icon_svg = $v; break;
            }
        }
    }
    if (!$icon_svg) $icon_svg = $subject_icons['_default'];
    $icon_html = '<span class="sn-tab-icon">' . $icon_svg . '</span>';

    if ($is_active) {
        echo '<button class="sn-macro-tab sn-macro-subject' . $active_cls . '" data-macro="subject" type="button" onclick="sentinelMacro(\'subject\')">'
           . $icon_html . ' ' . s($label) . '</button>';
    } else {
        $switch_url = (new moodle_url('/local/sentinel/student_report.php', [
            'userid' => $userid, 'courseid' => $vc->id,
            'cutoff' => $cutoff, 'norm' => $norm,
        ]))->out(false);
        echo '<a class="sn-macro-tab sn-macro-subject" href="' . $switch_url . '" style="text-decoration:none">'
           . $icon_html . ' ' . s($label) . '</a>';
    }
}
echo '</div>';
echo '</div>';

// Indicar al JS la macro tab y subtab inicial (evitar localStorage stale).
$init_macro = ($selected_courseid && $has_subject_tab) ? 'subject' : 'global';
echo '<script>window._sentinelInitMacro="' . $init_macro . '";';
if ($init_macro === 'subject' && $inittab === 'contenidos') {
    echo 'window._sentinelInitSubtab="contenidos";';
}
echo '</script>';

// ── SUB-NAV: Visión Global ──
echo '<div class="sn-sub-nav sn-sub-nav--global" id="sn-subnav-global">';
echo '<div class="sn-subtabs-group">';
echo '<button class="sn-subtab active" data-subtab="cc" type="button" onclick="sentinelSubtab(\'cc\')">'
   . $icon_cc . ' Competencias clave</button>';
echo '<button class="sn-subtab" data-subtab="objetivos" type="button" onclick="sentinelSubtab(\'objetivos\')">'
   . $icon_objetivos . ' Objetivos de etapa</button>';
if ($show_transversales) {
    echo '<button class="sn-subtab" data-subtab="transversales" type="button" onclick="sentinelSubtab(\'transversales\')">'
       . $icon_transversales . ' Contenidos transversales</button>';
}
echo '</div>';
echo '<div class="sn-view-toggle" id="sn-toggle-cc">';
echo '<button class="sn-toggle-btn active" data-view="diana" type="button" onclick="sentinelToggleView(\'diana\')">'
   . $key_diana_svg . ' Diana</button>';
echo '<button class="sn-toggle-btn" data-view="cards" type="button" onclick="sentinelToggleView(\'cards\')">'
   . $key_cards_svg . ' Tarjetas</button>';
echo '</div>';
echo '</div>';

// ── SUB-NAV: Materia ──
if ($has_subject_tab) {
    echo '<div class="sn-sub-nav sn-sub-nav--subject" id="sn-subnav-subject" style="display:none">';
    echo '<div class="sn-subtabs-group">';
    echo '<button class="sn-subtab active" data-subtab="ce" type="button" onclick="sentinelSubtab(\'ce\')">'
       . $icon_ce . ' Competencias</button>';
    echo '<button class="sn-subtab" data-subtab="contenidos" type="button" onclick="sentinelSubtab(\'contenidos\')">'
       . $icon_contenidos . ' Contenidos</button>';
    echo '<button class="sn-subtab" data-subtab="calificaciones" type="button" onclick="sentinelSubtab(\'calificaciones\')">'
       . '<span class="sn-st-icon">📊</span> Calificaciones</button>';
    echo '<button class="sn-subtab" data-subtab="dua" type="button" onclick="sentinelSubtab(\'dua\')">'
       . '<span class="sn-st-icon">🧠</span> Panel DUA</button>';
    echo '</div>';
    echo '<div class="sn-view-toggle" id="sn-toggle-ce">';
    echo '<button class="sn-toggle-btn active" data-view="diana" type="button" onclick="sentinelToggleView(\'diana\')">'
       . $spec_diana_svg . ' Diana</button>';
    echo '<button class="sn-toggle-btn" data-view="cards" type="button" onclick="sentinelToggleView(\'cards\')">'
       . $spec_cards_svg . ' Tarjetas</button>';
    echo '</div>';
    echo '</div>';
}

// ── PANE 1: Cuadrícula de áreas ─────────────────────────────────────────────
// Pre-compute sparklines per competency area and per descriptor.
$area_sparklines = [];
$desc_sparklines = [];
foreach ($areas as $acode => $adata) {
    $area_events = [];
    foreach ($adata['descriptors'] as $dc => $ddata) {
        $d_pts = [];
        foreach ($ddata['activities'] as $act) {
            $t = (int)$act['gradetime'];
            $g = (float)$act['grade'];
            $d_pts[] = [$t, $g];
            $area_events[] = ['time' => $t, 'desc' => $dc, 'grade' => $g];
        }
        usort($d_pts, fn($a, $b) => $a[0] <=> $b[0]);
        $desc_sparklines[$acode][$dc] = $d_pts;
    }
    usort($area_events, fn($a, $b) => $a['time'] <=> $b['time']);
    $desc_latest = [];
    $a_pts = [];
    $a_group_start = null;
    foreach ($area_events as $ev) {
        $desc_latest[$ev['desc']] = $ev['grade'];
        $avg = round(array_sum($desc_latest) / count($desc_latest), 2);
        $t = (int)$ev['time'];
        if ($a_group_start !== null && ($t - $a_group_start) < 60 && !empty($a_pts)) {
            $a_pts[count($a_pts) - 1][1] = $avg;
        } else {
            $a_group_start = $t;
            $a_pts[] = [$t, $avg];
        }
    }
    $area_sparklines[$acode] = $a_pts;
}

echo '<div id="sentinel-pane-grid" class="sn-view-pane">';
echo '<div id="sentinel-l1" class="sentinel-panel active">';
echo '<div class="sentinel-area-grid">';

foreach ($areas as $acode => $adata) {
    $color = $adata['color'];

    echo '<div class="sentinel-area-card" data-area="' . $acode . '"'
       . ' onclick="sentinelShowArea(\'' . $acode . '\')"'
       . ' tabindex="0" role="button" aria-label="' . s($adata['name']) . '"'
       . ' style="border:1.5px solid ' . $color . '25;border-left:4px solid ' . $color . ';background:#fff;--sn-tint:' . $color . '0D">';


    // Fila superior: código como pill coloreado (izq) + nota (dcha)
    echo '<div class="sn-ck-top">';
    echo '<span class="sn-ck-code" style="background:' . $color . ';color:#fff;padding:2px 8px;border-radius:4px">' . s($acode) . '</span>';
    if ($adata['partial_grade'] !== null) {
        $gcolor = descriptor_calculator::grade_colour($adata['partial_grade']);
        echo '<span class="sn-ck-grade" style="color:' . $gcolor . '">'
           . number_format($adata['partial_grade'], 1)
           . sentinel_sparkline_svg($area_sparklines[$acode] ?? [], $gcolor)
           . '</span>';
    }
    echo '</div>';

    // Nota global LOMLOE — solo se muestra si difiere de la evaluada.
    $show_global = $adata['worked'] > 0
        && number_format($adata['grade'], 1) !== number_format($adata['partial_grade'] ?? 0, 1);
    if ($show_global) {
        $incomplete = $adata['worked'] < $adata['total'];
        $gk_col = $incomplete ? '#dc2626' : descriptor_calculator::grade_colour($adata['grade']);
        echo '<div class="sn-ck-global">';
        echo '<span style="font-weight:700;color:' . $gk_col . '">Global: ' . number_format($adata['grade'], 1) . '</span>'
           . ' <span style="color:#9ca3af">(' . $adata['worked'] . '/' . $adata['total'] . ' desc.)</span>';
        echo '</div>';
    }

    echo '<div class="sn-ck-name">' . s($adata['name']) . '</div>';

    // Barra de progreso
    if ($adata['partial_grade'] !== null) {
        $barcolor = descriptor_calculator::grade_colour($adata['partial_grade']);
        echo sentinel_bar($adata['partial_grade'], $barcolor, 100);
        echo '<div style="margin-top:5px">' . sentinel_level_badge($adata['partial_grade']) . '</div>';
    } else {
        echo '<div style="color:#9ca3af;font-style:italic;font-size:.75em;margin-bottom:4px">'
           . get_string('report:not_worked', 'local_sentinel') . '</div>';
    }

    // Stats
    $ncourses = count($adata['courses']);
    echo '<div class="sn-ck-meta">';
    echo '<span class="sn-ck-stat"><b>' . $adata['worked'] . '/' . $adata['total'] . '</b> ' . ($adata['worked'] === 1 ? 'descriptor' : 'descriptores') . '</span>';
    echo '<span class="sn-ck-stat"><b>' . $adata['occasions'] . '</b> ' . ($adata['occasions'] === 1 ? 'ocasión' : 'ocasiones') . '</span>';
    if ($ncourses > 0) {
        $total_vc = count($visible_courses);
        echo '<span class="sn-ck-stat"><b>' . $ncourses . '/' . $total_vc . '</b> ' . ($ncourses === 1 ? 'materia' : 'materias') . '</span>';
    }
    echo '</div>';

    // Coverage indicator.
    $cc_cov = descriptor_calculator::classify_coverage($adata['worked'], $adata['total']);
    echo '<div class="sn-card-repr sn-card-repr--' . $cc_cov['level'] . '">'
       . '<span class="sn-card-repr-dot sn-card-repr-dot--' . $cc_cov['level'] . '"></span>'
       . '<span class="sn-card-repr-txt">';
    if ($cc_cov['level'] !== 'grey') {
        echo number_format($cc_cov['pct'], 0) . '% · ';
    }
    echo s($cc_cov['label']) . '</span></div>';

    if (!empty($adata['courses'])) {
        $max_show = 3;
        $shown = array_slice($adata['courses'], 0, $max_show);
        $rest  = count($adata['courses']) - $max_show;
        $txt   = implode(' · ', $shown);
        if ($rest > 0) { $txt .= ' · +' . $rest . ' más'; }
        echo '<div class="sn-ck-courses" title="' . s(implode(', ', $adata['courses'])) . '">' . s($txt) . '</div>';
    }

    echo '</div>'; // .sentinel-area-card
}

echo '</div>'; // .sentinel-area-grid
echo '</div>'; // #sentinel-l1
echo '</div>'; // #sentinel-pane-grid

// ── PANE 2: Diana de evaluación ─────────────────────────────────────────────
{
    $radar_labels = [];
    $radar_annual = [];
    $area_colors_list = [];
    foreach (descriptor_calculator::AREAS as $acode => $adef) {
        $radar_labels[] = $acode;
        $radar_annual[] = $areas[$acode]['partial_grade'];
        $area_colors_list[] = $adef['color'];
    }

    $radar_periods = [];
    $period_palette = ['#7c9cb5', '#b58c6a', '#6a9e7e', '#b07a8a', '#8e85a8', '#8aab6e'];
    $pidx = 0;
    $pn = 0;
    foreach ($periods as $p) {
        $pn++;
        $pdata = [];
        foreach (descriptor_calculator::AREAS as $acode => $adef) {
            $pdata[] = $period_area_grades[$p->id][$acode] ?? null;
        }
        $has_data = false;
        foreach ($pdata as $v) { if ($v !== null) { $has_data = true; break; } }
        if (!$has_data) { continue; }
        $color = $period_palette[$pidx % count($period_palette)];
        $radar_periods[] = [
            'label' => $p->label ?: get_string('report:comm_nth', 'local_sentinel', $pn),
            'data'  => $pdata,
            'color' => $color,
        ];
        $pidx++;
    }

    $radar_occasions = [];
    $radar_subjects  = [];
    $radar_grades    = [];
    $radar_grade_colors = [];
    $radar_lomloe_grades = [];
    $radar_lomloe_colors = [];
    $radar_worked = [];
    $radar_total_desc = [];
    foreach (descriptor_calculator::AREAS as $acode => $adef) {
        $radar_occasions[] = $areas[$acode]['occasions'];
        $radar_subjects[]  = count($areas[$acode]['courses']);
        $pg = $areas[$acode]['partial_grade'];
        $radar_grades[] = $pg;
        $radar_grade_colors[] = $pg !== null ? descriptor_calculator::grade_colour($pg) : null;
        $lg = $areas[$acode]['grade'];
        $radar_lomloe_grades[] = $lg;
        $radar_lomloe_colors[] = descriptor_calculator::grade_colour($lg);
        $radar_worked[] = $areas[$acode]['worked'];
        $radar_total_desc[] = $adef['total'];
    }

    $all_courses = [];
    foreach ($areas as $ad) { foreach ($ad['courses'] as $c) { $all_courses[$c] = true; } }
    $all_course_list = array_keys($all_courses);
    $total_courses = count($all_courses);

    $radar_course_occ = [];
    foreach (descriptor_calculator::AREAS as $acode => $adef) {
        $co = array_fill_keys($all_course_list, 0);
        foreach ($areas[$acode]['descriptors'] as $ddata) {
            foreach ($ddata['activities'] as $act) {
                $cn = $act['coursename'] ?? '';
                if (isset($co[$cn])) { $co[$cn]++; }
            }
        }
        $radar_course_occ[] = array_values($co);
    }

    $area_codes = array_keys(descriptor_calculator::AREAS);

    $radar_data_full = json_encode([
        'labels'        => $radar_labels,
        'annual'        => $radar_annual,
        'periods'       => $radar_periods,
        'colors'        => $area_colors_list,
        'occasions'     => $radar_occasions,
        'subjects'      => $radar_subjects,
        'grades'        => $radar_grades,
        'gradeColors'   => $radar_grade_colors,
        'lomloeGrades'  => $radar_lomloe_grades,
        'lomloeColors'  => $radar_lomloe_colors,
        'worked'        => $radar_worked,
        'totalDesc'     => $radar_total_desc,
        'totalCourses'  => $total_courses,
        'courseOcc'      => $radar_course_occ,
        'codes'         => $area_codes,
        'selfeval'      => $selfeval_annual,
    ]);

    $diana_init_cls = ($inittab === 'contenidos') ? '' : ' active';
    echo '<div id="sentinel-pane-diana" class="sn-view-pane' . $diana_init_cls . '">';
    echo '<div class="sn-nordic-wrap hist-closed" id="sn-global-diana-wrap">';
    echo '<div class="sn-nordic-left" style="display:flex;justify-content:center;align-items:flex-start;gap:32px;flex-wrap:wrap;padding-top:16px">';
    echo '<div style="max-width:600px;width:100%;position:relative;flex:1 1 auto;min-width:300px">';
    echo '<button class="sn-drawer-btn" title="Histórico" onclick="'
       . 'window.sentinelToggleHist(this);'
       . '" style="position:absolute;top:0;right:0;z-index:16">'
       . '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>'
       . '</button>';
    echo '<canvas id="sentinel-radar" data-radar="' . s($radar_data_full) . '" style="width:100%"></canvas>';
    echo '<div style="text-align:center;margin-top:12px">';
    echo '<span style="display:inline-block;padding:4px 14px;border-radius:12px;'
       . 'background:#f3f4f6;color:#6b7280;font-size:.78em;font-weight:500;letter-spacing:.01em">';
    echo $summary['total_occasions'] . ' ocasiones de evaluación';
    if ($total_courses > 0) {
        $total_vc2 = count($visible_courses);
        echo ' &middot; ' . $total_courses . '/' . $total_vc2 . ' ' . ($total_courses === 1 ? 'materia' : 'materias');
    }
    echo '</span>';
    echo '</div>';
    echo '</div>';
    echo '<div id="sentinel-radar-legend" style="flex-shrink:0;align-self:center"></div>';
    echo '</div>'; // .sn-nordic-left

    // ── Columna derecha: panel de histórico (global) ──
    echo '<div class="sn-nordic-right" id="sn-global-hist-right">';
    echo '<div class="sn-nordic-hist">';
    echo '<div class="sn-nordic-hist-title">Histórico de comunicaciones</div>';
    echo '<div class="sn-nordic-hist-list" id="sn-global-hist-list">';
    echo '<div class="sn-nordic-hist-item sn-nordic-hist-annual active"'
       . ' data-cutoff="0" onclick="window.sentinelSwitchGlobalPeriod(0)">';
    echo '<span class="sn-nordic-hist-label">Curso completo</span>';
    echo '</div>';
    if (!empty($periods)) {
        foreach (array_reverse($periods) as $p) {
            $pdate = $p->cutoffdate ? userdate($p->cutoffdate, '%d/%m/%Y') : '';
            $plabel = $p->label ?? get_string('report:comm_nth', 'local_sentinel', $p->sortorder ?? '');
            echo '<div class="sn-nordic-hist-item"'
               . ' data-cutoff="' . (int)$p->cutoffdate . '"'
               . ' onclick="window.sentinelSwitchGlobalPeriod(' . (int)$p->cutoffdate . ')">';
            echo '<span class="sn-nordic-hist-label">' . s($plabel) . '</span>';
            if ($pdate) {
                echo '<br><span class="sn-nordic-hist-date">' . $pdate . '</span>';
            }
            echo '</div>';
        }
    }
    echo '</div>'; // .sn-nordic-hist-list
    echo '</div>'; // .sn-nordic-hist
    echo '</div>'; // .sn-nordic-right

    echo '</div>'; // .sn-nordic-wrap
    echo '<script>'
       . 'window.sentinelSwitchGlobalPeriod=function(cutoff){'
       . 'var ch=window._globalRadarChart;'
       . 'if(ch){'
       . 'ch.data.datasets.forEach(function(ds){'
       . 'if(ds.label==="Anual"||ds.label==="Autoevaluación (DUA)"){ds.hidden=false;return;}'
       . 'if(cutoff===0){ds.hidden=true;}' // Curso completo: hide all periods
       . 'else{ds.hidden=false;}' // Any period: show all period overlays
       . '});'
       . 'ch.update();'
       . '}'
       . 'document.querySelectorAll(".sn-nordic-hist-list").forEach(function(list){'
       . 'list.querySelectorAll(".sn-nordic-hist-item").forEach(function(el){'
       . 'el.classList.toggle("active",parseInt(el.dataset.cutoff||"0")===cutoff);'
       . '});});'
       . 'window._sentinelActiveCutoff=cutoff;'
       . 'if(window.sentinelSwitchCePeriod){window.sentinelSwitchCePeriod(cutoff);}'
       . 'if(window.sentinelSwitchCntPeriod){window.sentinelSwitchCntPeriod(cutoff);}'
       . '};'
       . '</script>';

    echo '</div>'; // #sentinel-pane-diana
}

// ── CSS compartido: layout flex + hist panel (CE y Contenidos) ──────────────
if (!empty($visible_courses)) {
    echo '<style>
.sn-nordic-wrap {
    display: flex; gap: 16px; padding: 16px;
    background: #fff; border-radius: 16px;
    box-shadow: 0 1px 4px rgba(0,0,0,.04);
}
.sn-nordic-left { flex: 1 1 auto; min-width: 0; position: relative; }
.sn-nordic-right {
    flex: 0 0 240px; max-width: 240px;
    display: flex; flex-direction: column; box-sizing: border-box;
    transition: flex-basis .25s ease, max-width .25s ease, opacity .25s ease, padding .25s ease;
    opacity: 1;
}
.sn-nordic-wrap.hist-closed .sn-nordic-right {
    flex-basis: 0; max-width: 0; opacity: 0; overflow: hidden; padding: 0;
}
.sn-nordic-diana-wrap {
    position: relative; width: 100%; max-width: 520px;
    margin: 0 auto; padding: 16px 24px 0;
}
.sn-drawer-btn {
    position: absolute; top: 10px; right: 10px; z-index: 16;
    display: flex; align-items: center; justify-content: center;
    width: 34px; height: 34px; border-radius: 10px;
    background: rgba(255,255,255,.85); border: 1px solid #E2E8F0; cursor: pointer;
    color: #64748B; box-shadow: 0 1px 6px rgba(0,0,0,.06);
    transition: all .15s; backdrop-filter: blur(4px);
}
.sn-drawer-btn:hover { background: #fff; color: #334155; box-shadow: 0 2px 10px rgba(0,0,0,.1); }
.sn-nordic-hist {
    background: #fff; border-radius: 14px; padding: 14px 12px;
    border: 1px solid #E2E8F0; box-shadow: 0 4px 16px rgba(0,0,0,.08);
    min-width: 0; width: 100%; box-sizing: border-box;
    overflow: hidden; max-height: 70vh;
}
.sn-nordic-hist-title {
    font-size: .66rem; font-weight: 700; color: #94A3B8;
    letter-spacing: .1em; text-transform: uppercase; margin: 0 0 8px;
    padding-bottom: 8px; border-bottom: 1px solid #F1F5F9;
}
.sn-nordic-hist-list {
    display: flex; flex-direction: column; gap: 4px;
    flex: 1; overflow-y: auto; max-height: 50vh;
    scrollbar-width: thin; scrollbar-color: #CBD5E1 transparent;
}
.sn-nordic-hist-item {
    padding: 7px 10px; border: 1px solid #F1F5F9; border-radius: 8px;
    font-size: .72rem; color: #64748B; cursor: pointer; line-height: 1.2;
    transition: all .15s; background: #FAFBFD;
    font-family: system-ui, -apple-system, sans-serif;
}
.sn-nordic-hist-item:hover { background: #EEF2FF; border-color: #C7D2FE; }
.sn-nordic-hist-item.active { background: #F0F5FF; border-color: #93C5FD; }
.sn-nordic-hist-annual { font-weight: 600; }
.sn-nordic-hist-annual .sn-nordic-hist-label { color: #1E40AF; }
.sn-nordic-hist-label {
    font-weight: 700; color: #334155; font-size: .74rem;
    white-space: nowrap;
}
.sn-nordic-hist-date { font-variant-numeric: tabular-nums; }
@media (max-width: 860px) {
    .sn-nordic-wrap { flex-direction: column; }
    .sn-nordic-right { flex: 0 0 auto; max-width: 100%; padding-left: 0; padding-top: 14px; }
    .sn-nordic-wrap.hist-closed .sn-nordic-right { padding-top: 0; }
}
</style>';
}

// ── PANE 3: Diana de materia ────────────────────────────────────────────────
if (!empty($visible_courses)) {
    echo '<div id="sentinel-pane-subject-diana" class="sn-view-pane">';

    if ($ce_data && !empty($ce_data['ces'])) {
        $ce_labels = [];
        $ce_grades = [];
        $ce_colors = [];
        $ce_names  = [];
        foreach ($ce_data['ces'] as $ce) {
            $ce_labels[] = $ce['name'];
            $ce_grades[] = $ce['grade'];
            $ce_colors[] = $ce['color'];
            $ce_names[]  = $ce['name'];
        }
        $ce_crit_worked = [];
        $ce_crit_total  = [];
        $ce_occasions   = [];
        $ce_days        = [];
        foreach ($ce_data['ces'] as $ce) {
            $ce_crit_worked[] = $ce['criteria_worked'];
            $ce_crit_total[]  = $ce['criteria_total'];
            $ce_occasions[]   = $ce['occasions'];
            $ce_days[]        = $ce['days'];
        }
        $ce_raw_acts = [];
        foreach ($ce_data['ces'] as $ceIdx => $ce) {
            foreach ($ce['criteria'] as $crIdx => $crit) {
                foreach ($crit['activities'] as $act) {
                    $ce_raw_acts[] = [
                        'ce' => $ceIdx,
                        'cr' => $crIdx,
                        'g'  => $act['grade'],
                        't'  => $act['gradetime'],
                    ];
                }
            }
        }
        // DUA selfeval per CE.
        $ce_cat_ids = array_column($ce_data['ces'], 'id');
        $se_per_ce = descriptor_calculator::get_selfeval_per_ce($userid, $selected_courseid, $ce_cat_ids);

        $subject_radar_json = json_encode([
            'labels' => $ce_labels,
            'grades' => $ce_grades,
            'colors' => $ce_colors,
            'names'  => $ce_names,
            'critWorked'   => $ce_crit_worked,
            'critTotal'    => $ce_crit_total,
            'occasions'    => $ce_occasions,
            'days'         => $ce_days,
            'subjectGrade' => $ce_data['subject_grade'],
            'courseName'   => $ce_data['course']->shortname,
            'rawActivities' => $ce_raw_acts,
            'selfeval'     => $se_per_ce,
            'repr' => $repr_course ? [
                'pct'   => $repr_course['pct'],
                'level' => $repr_course['level'],
                'label' => $repr_course['label'],
                'tooltip' => get_string('sample:tooltip_' . $repr_course['level'], 'local_sentinel', number_format($repr_course['pct'], 0)),
            ] : null,
        ]);

        // Layout flex 2-columnas con hist panel (colapsado por defecto en materia).
        echo '<div class="sn-nordic-wrap hist-closed">';
        echo '<div class="sn-nordic-left">';
        echo '<div class="sn-nordic-diana-wrap">';
        echo '<button class="sn-drawer-btn" title="Histórico" onclick="'
           . 'window.sentinelToggleHist(this);'
           . '">'
           . '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>'
           . '</button>';
        echo '<canvas id="sentinel-subject-radar" data-subjectradar="' . s($subject_radar_json) . '"'
           . ' style="width:100%"></canvas>';
        echo '</div>';
        if ($repr_course) {
            $rc = $repr_course;
            $rc_icons = ['red' => '⚠️', 'amber' => '⚡', 'green' => '✅', 'grey' => '○'];
            echo '<div style="text-align:center;margin-top:6px">';
            echo '<div class="sn-repr-wrap">';
            echo '<span class="sn-repr-badge sn-repr-badge--' . $rc['level'] . '">';
            echo '<span class="sn-repr-dot sn-repr-dot--' . $rc['level'] . '"></span>';
            if ($rc['level'] !== 'grey') {
                echo number_format($rc['pct'], 0) . '% · ';
            }
            echo s($rc['label']);
            echo '</span>';
            echo '<div class="sn-repr-tip">';
            echo '<div class="sn-repr-tip-head sn-repr-tip-head--' . $rc['level'] . '">'
               . $rc_icons[$rc['level']] . ' ' . s($rc['label']) . '</div>';
            if ($rc['level'] !== 'grey') {
                $rc_pct_col = descriptor_calculator::grade_colour($rc['pct'] / 10);
                echo '<div class="sn-repr-tip-pct" style="color:' . $rc_pct_col . '">'
                   . number_format($rc['pct'], 0) . '%</div>';
                echo '<div>' . get_string('sample:tooltip_' . $rc['level'], 'local_sentinel', number_format($rc['pct'], 0)) . '</div>';
            }
            echo '<div class="sn-repr-tip-rec">'
               . get_string('sample:rec_' . $rc['level'], 'local_sentinel', number_format($rc['pct'] ?? 0, 0))
               . '</div>';
            echo '</div>'; // .sn-repr-tip
            echo '</div>'; // .sn-repr-wrap
            echo '</div>';
        }
        echo '</div>'; // .sn-nordic-left

        // ── Columna derecha: panel de histórico (CE) ──
        $ce_hist_base = new moodle_url('/local/sentinel/student_report.php',
            array_filter(['userid' => $userid, 'norm' => $norm, 'courseid' => $selected_courseid]));
        echo '<div class="sn-nordic-right" id="sn-ce-hist-right">';
        echo '<div class="sn-nordic-hist">';
        echo '<div class="sn-nordic-hist-title">Histórico de comunicaciones</div>';
        echo '<div class="sn-nordic-hist-list">';
        echo '<div class="sn-nordic-hist-item sn-nordic-hist-annual active"'
           . ' data-cutoff="0" onclick="window.sentinelSwitchCePeriod(0)">';
        echo '<span class="sn-nordic-hist-label">Curso completo</span>';
        echo '</div>';
        if (!empty($periods)) {
            foreach (array_reverse($periods) as $p) {
                $pdate = $p->cutoffdate ? userdate($p->cutoffdate, '%d/%m/%Y') : '';
                $plabel = $p->label ?? get_string('report:comm_nth', 'local_sentinel', $p->sortorder ?? '');
                echo '<div class="sn-nordic-hist-item"'
                   . ' data-period="' . (int)$p->id . '"'
                   . ' data-cutoff="' . (int)$p->cutoffdate . '"'
                   . ' onclick="window.sentinelSwitchCePeriod(' . (int)$p->cutoffdate . ')">';
                echo '<span class="sn-nordic-hist-label">' . s($plabel) . '</span>';
                if ($pdate) {
                    echo '<br><span class="sn-nordic-hist-date">' . $pdate . '</span>';
                }
                echo '</div>';
            }
        }
        echo '</div>'; // .sn-nordic-hist-list
        echo '</div>'; // .sn-nordic-hist
        echo '</div>'; // .sn-nordic-right (CE)
        echo '</div>'; // .sn-nordic-wrap

        echo '<script>'
           . 'window.sentinelSwitchCePeriod=function(cutoff){'
           . 'var S=window._ceRadarState;if(!S)return;'
           . 'var raw=S.data.rawActivities||[];'
           . 'var filtered=cutoff>0?raw.filter(function(r){return r.t<=cutoff;}):raw;'
           . 'var ceMap={};'
           . 'filtered.forEach(function(r){'
           . 'if(!ceMap[r.ce])ceMap[r.ce]={};'
           . 'if(!ceMap[r.ce][r.cr])ceMap[r.ce][r.cr]=[];'
           . 'ceMap[r.ce][r.cr].push(r.g);'
           . '});'
           . 'var nCE=S.data.grades.length;'
           . 'for(var i=0;i<nCE;i++){'
           . 'if(!ceMap[i]){S.vals[i]=0;continue;}'
           . 'var ca=[];var crits=ceMap[i];'
           . 'for(var cr in crits){'
           . 'var gs=crits[cr];'
           . 'ca.push(gs.reduce(function(a,b){return a+b;},0)/gs.length);'
           . '}'
           . 'S.vals[i]=ca.length?Math.round(ca.reduce(function(a,b){return a+b;},0)/ca.length*10)/10:0;'
           . '}'
           . 'S.chart.data.datasets[0].data=S.vals.slice();'
           . 'S.chart.data.datasets[0].pointRadius=S.vals.map(function(v){return v>0?6:3;});'
           . 'S.chart.update();'
           . 'var els=S.cardsPlugin&&S.cardsPlugin._els;'
           . 'if(els){for(var i=0;i<nCE;i++){'
           . 'var card=els[i];if(!card)continue;'
           . 'var v=S.vals[i];'
           . 'var gc=v>0?"hsl("+Math.round((v/10)*120)+",65%,36%)":"#999";'
           . 'var gt=v>0?v.toFixed(1):"–";'
           . 'var gs=card.querySelector("div:first-child > span:nth-child(2)");'
           . 'if(gs){gs.textContent=gt;gs.style.color=gc;}'
           . '}}'
           . 'document.querySelectorAll(".sn-nordic-hist-list").forEach(function(list){'
           . 'list.querySelectorAll(".sn-nordic-hist-item").forEach(function(el){'
           . 'el.classList.toggle("active",parseInt(el.dataset.cutoff||"0")===cutoff);'
           . '});});'
           . 'window._sentinelActiveCutoff=cutoff;'
           . '};'
           . '</script>';

    } else {
        echo '<div style="text-align:center;padding:2rem;color:#9ca3af;font-style:italic">'
           . get_string('report:no_ce_data', 'local_sentinel') . '</div>';
    }
    echo '</div>'; // #sentinel-pane-subject-diana
}

// Pre-compute sparklines per CE and per criterion (needed by PANE 4 and CE L2 panels).
$ce_sparklines = [];
$crit_sparklines = [];
if ($ce_data && !empty($ce_data['ces'])) {
    foreach ($ce_data['ces'] as $ci => $ce_tmp) {
        $ce_events = [];
        foreach ($ce_tmp['criteria'] as $cri => $crit_tmp) {
            $crit_pts = [];
            foreach ($crit_tmp['activities'] as $act) {
                $t = (int)$act['gradetime'];
                $g = (float)$act['grade'];
                $crit_pts[] = [$t, $g];
                $ce_events[] = ['time' => $t, 'crit' => $cri, 'grade' => $g];
            }
            usort($crit_pts, fn($a, $b) => $a[0] <=> $b[0]);
            $crit_sparklines[$ci][$cri] = $crit_pts;
        }
        usort($ce_events, fn($a, $b) => $a['time'] <=> $b['time']);
        $crit_latest = [];
        $ce_pts = [];
        $ce_group_start = null;
        foreach ($ce_events as $ev) {
            $crit_latest[$ev['crit']] = $ev['grade'];
            $avg = round(array_sum($crit_latest) / count($crit_latest), 2);
            $t = (int)$ev['time'];
            if ($ce_group_start !== null && ($t - $ce_group_start) < 60 && !empty($ce_pts)) {
                $ce_pts[count($ce_pts) - 1][1] = $avg;
            } else {
                $ce_group_start = $t;
                $ce_pts[] = [$t, $avg];
            }
        }
        $ce_sparklines[$ci] = $ce_pts;
    }
}

// ── PANE 4: Competencias específicas (grid) ─────────────────────────────────
if (!empty($visible_courses)) {
    echo '<div id="sentinel-pane-subject-grid" class="sn-view-pane">';

    if ($ce_data && !empty($ce_data['ces'])) {
        echo '<div id="sentinel-ce-l1" class="sentinel-panel active">';
        echo '<div class="sn-ce-grid">';

        foreach ($ce_data['ces'] as $idx => $ce) {
            $color = $ce['color'];

            echo '<div class="sn-ce-card" data-ce-idx="' . $idx . '"'
               . ' onclick="sentinelShowCE(' . $idx . ')"'
               . ' tabindex="0" role="button" aria-label="' . s($ce['name']) . '"'
               . ' style="border:1.5px solid ' . $color . '25;border-left:4px solid ' . $color . ';--sn-tint:' . $color . '0D">';

            // Top row: CE pill + grade.
            echo '<div class="sn-ck-top">';
            if (preg_match('/^(CE\s*\d+)/i', $ce['name'], $m)) {
                $short_label = $m[1];
            } elseif (preg_match('/^(\d+)\.\s/', $ce['name'])) {
                $short_label = 'CE' . (int)$ce['name'];
            } else {
                $short_label = 'CE' . ($idx + 1);
            }
            echo '<span class="sn-ck-code" style="background:' . $color . ';color:#fff;padding:2px 8px;border-radius:4px">'
               . s($short_label) . '</span>';
            if ($ce['grade'] !== null) {
                $gc = descriptor_calculator::grade_colour($ce['grade']);
                echo '<span class="sn-ck-grade" style="color:' . $gc . '">'
                   . number_format($ce['grade'], 1)
                   . sentinel_sparkline_svg($ce_sparklines[$idx] ?? [], $gc)
                   . '</span>';
            }
            echo '</div>';

            // CE full name.
            echo '<div class="sn-ck-name">' . s($ce['name']) . '</div>';

            // Progress bar.
            if ($ce['grade'] !== null) {
                $barcolor = descriptor_calculator::grade_colour($ce['grade']);
                echo sentinel_bar($ce['grade'], $barcolor, 100);
                echo '<div style="margin-top:5px">' . sentinel_level_badge($ce['grade']) . '</div>';
            } else {
                echo '<div style="color:#9ca3af;font-style:italic;font-size:.75em;margin-bottom:4px">'
                   . get_string('report:not_worked', 'local_sentinel') . '</div>';
            }

            // Stats.
            $crit_word = $ce['criteria_total'] === 1
                ? get_string('report:ce_criterion', 'local_sentinel')
                : get_string('report:ce_criteria', 'local_sentinel');
            echo '<div class="sn-ck-meta">';
            echo '<span class="sn-ck-stat"><b>' . $ce['criteria_worked'] . '/' . $ce['criteria_total'] . '</b> ' . $crit_word . '</span>';
            if ($ce['occasions'] > 0) {
                echo '<span class="sn-ck-stat"><b>' . $ce['occasions'] . '</b> '
                   . ($ce['occasions'] === 1 ? 'ocasión' : 'ocasiones') . '</span>';
            }
            if ($ce['days'] > 0) {
                echo '<span class="sn-ck-stat"><b>' . $ce['days'] . '</b> '
                   . ($ce['days'] === 1 ? 'día' : 'días') . '</span>';
            }
            echo '</div>';

            // Coverage indicator.
            $ce_cov = descriptor_calculator::classify_coverage($ce['criteria_worked'], $ce['criteria_total']);
            echo '<div class="sn-card-repr sn-card-repr--' . $ce_cov['level'] . '">'
               . '<span class="sn-card-repr-dot sn-card-repr-dot--' . $ce_cov['level'] . '"></span>'
               . '<span class="sn-card-repr-txt">';
            if ($ce_cov['level'] !== 'grey') {
                echo number_format($ce_cov['pct'], 0) . '% · ';
            }
            echo s($ce_cov['label']) . '</span></div>';

            echo '</div>'; // .sn-ce-card
        }

        echo '</div>'; // .sn-ce-grid
        echo '</div>'; // #sentinel-ce-l1
    } else {
        echo '<div style="text-align:center;padding:2rem;color:#9ca3af;font-style:italic">'
           . get_string('report:no_ce_data', 'local_sentinel') . '</div>';
    }

    echo '</div>'; // #sentinel-pane-subject-grid
}

// ── CE L2: Criteria detail panels (outside pane, like LOMLOE L2) ───────────
if (!empty($visible_courses) && $ce_data && !empty($ce_data['ces'])) {
    $ce_course_name = $ce_data['course']->shortname;
    foreach ($ce_data['ces'] as $idx => $ce) {
        $color = $ce['color'];
        if (preg_match('/^(CE\s*\d+)/i', $ce['name'], $m)) {
            $short_label = $m[1];
        } elseif (preg_match('/^(\d+)\.\s/', $ce['name'])) {
            $short_label = 'CE' . (int)$ce['name'];
        } else {
            $short_label = 'CE' . ($idx + 1);
        }
        $ce_crit_word = $ce['criteria_total'] === 1
            ? get_string('report:ce_criterion', 'local_sentinel')
            : get_string('report:ce_criteria', 'local_sentinel');
        $ce_full_name = trim(preg_replace('/^(CE\s*\d+\.?\s*)/i', '', $ce['name']));
        if ($ce_full_name === '' || strcasecmp($ce_full_name, $short_label) === 0) {
            $ce_full_name = $ce['name'];
        }

        echo '<div id="sentinel-ce-l2-' . $idx . '" class="sentinel-panel"'
           . ' data-ce-idx="' . $idx . '"'
           . ' data-ce-label="' . s($short_label) . '"'
           . ' data-ce-name="' . s(shorten_text($ce_full_name, 50)) . '"'
           . ' data-ce-course="' . s($ce_course_name) . '">';

        echo '<a href="#" class="sn-back-link" onclick="sentinelCEBack(); return false">← Volver a Competencias Específicas</a>';
        echo '<h4 style="margin-bottom:.3rem;display:flex;align-items:center;gap:6px;flex-wrap:wrap">';
        echo '<span style="background:' . $color . ';color:#fff;padding:2px 8px;border-radius:4px;font-size:.85em">'
           . s($short_label) . '</span>';
        if ($ce['grade'] !== null) {
            $gc = descriptor_calculator::grade_colour($ce['grade']);
            echo '<span style="font-weight:700;color:' . $gc . ';font-size:.95em">'
               . number_format($ce['grade'], 1)
               . sentinel_sparkline_svg($ce_sparklines[$idx] ?? [], $gc)
               . '</span>';
            echo '<small class="text-muted">'
               . $ce['criteria_worked'] . '/' . $ce['criteria_total'] . ' '
               . $ce_crit_word . '</small>';
        }
        echo '</h4>';
        echo '<div style="font-size:.82em;color:#4b5563;line-height:1.4;margin-bottom:.6rem">'
           . s($ce_full_name) . '</div>';

        if (!empty($ce['criteria'])) {
            echo '<h5 style="font-size:.82em;text-transform:uppercase;letter-spacing:.05em;color:#9ca3af;margin:0 0 .5rem;font-weight:600">Criterios de evaluación</h5>';
            echo '<div class="sentinel-desc-grid">';
            foreach ($ce['criteria'] as $ci => $crit) {
                $worked = $crit['grade'] !== null;
                // Extract short code (e.g. "2.1.") from criterion name.
                $crit_code = '';
                $crit_rest = $crit['name'];
                if (preg_match('/^(\d+\.\d+\.?)\s*(.*)$/s', $crit['name'], $cm)) {
                    $crit_code = trim($cm[1]);
                    $crit_rest = trim($cm[2]);
                }
                if (!$worked) {
                    echo '<div class="sentinel-desc-card sentinel-desc-unworked"'
                       . ' style="border-left:3px solid ' . $color . '44">';
                    echo '<span class="sn-area-chip" style="background:' . $color . '18;color:' . $color . 'aa">'
                       . s($short_label) . '</span>';
                    if ($crit_code) {
                        echo '<div class="sn-desc-code" style="color:#bbb">' . s($crit_code) . '</div>';
                        echo '<div class="sn-desc-text-unworked">' . s(shorten_text($crit_rest, 90)) . '</div>';
                    } else {
                        echo '<div class="sn-desc-text-unworked">' . s(shorten_text($crit['name'], 90)) . '</div>';
                    }
                    echo '<div style="font-size:.75em;color:#aaa;font-style:italic;margin-top:3px">'
                       . get_string('report:not_worked', 'local_sentinel') . '</div>';
                    echo '</div>';
                } else {
                    $has_detail = !empty($crit['activities']);
                    echo '<div class="sentinel-desc-card"'
                       . ' style="border:1.5px solid ' . $color . '25;border-left:4px solid ' . $color . ';background:#fff;--sn-tint:' . $color . '0D"'
                       . ($has_detail ? ' onclick="sentinelShowCECrit(' . $idx . ',' . $ci . ')" tabindex="0" role="button"' : '')
                       . '>';
                    echo '<div style="font-size:.68em;font-weight:700;letter-spacing:.06em;color:' . $color . 'AA;margin-bottom:4px">'
                       . s($short_label) . '</div>';
                    if ($crit_code) {
                        echo '<span class="sn-desc-code" style="color:' . $color . '">' . s($crit_code) . '</span>';
                        echo '<div class="sn-desc-text">' . s(shorten_text($crit_rest, 100)) . '</div>';
                    } else {
                        echo '<div class="sn-desc-text">' . s(shorten_text($crit['name'], 100)) . '</div>';
                    }
                    echo '<div class="sn-dgrade-row">';
                    $crit_gc = descriptor_calculator::grade_colour($crit['grade']);
                    echo '<span class="sn-dgrade" style="color:' . $crit_gc . '">'
                       . number_format($crit['grade'], 1)
                       . sentinel_sparkline_svg($crit_sparklines[$idx][$ci] ?? [], $crit_gc)
                       . '</span>';
                    if ($crit['occasions'] > 0) {
                        echo '<span class="sn-docc">' . $crit['occasions'] . ' '
                           . ($crit['occasions'] === 1 ? 'ocasión' : 'ocasiones') . '</span>';
                    }
                    echo '</div>';
                    echo sentinel_level_badge($crit['grade']);
                    echo sentinel_bar($crit['grade'], descriptor_calculator::grade_colour($crit['grade']), 100);
                    if ($has_detail) {
                        echo '<div class="sn-d-footer"><a class="sn-d-arr">'
                           . get_string('report:view_detail', 'local_sentinel') . ' →</a></div>';
                    }
                    echo '</div>'; // .sentinel-desc-card
                }
            }
            echo '</div>'; // .sentinel-desc-grid
        } else {
            echo '<div style="text-align:center;padding:2rem;color:#9ca3af;font-style:italic">'
               . get_string('report:no_ce_data', 'local_sentinel') . '</div>';
        }
        echo '</div>'; // #sentinel-ce-l2-{idx}
    }
}

// ── CE L3: Activities table per criterion (outside L2, like LOMLOE L3) ─────
if (!empty($visible_courses) && $ce_data && !empty($ce_data['ces'])) {
    foreach ($ce_data['ces'] as $idx => $ce) {
        $color = $ce['color'];
        if (preg_match('/^(CE\s*\d+)/i', $ce['name'], $m)) {
            $short_label = $m[1];
        } elseif (preg_match('/^(\d+)\.\s/', $ce['name'])) {
            $short_label = 'CE' . (int)$ce['name'];
        } else {
            $short_label = 'CE' . ($idx + 1);
        }

        foreach ($ce['criteria'] as $ci => $crit) {
            if (empty($crit['activities'])) { continue; }

            echo '<div id="sentinel-ce-l3-' . $idx . '-' . $ci . '" class="sentinel-panel"'
               . ' data-ce-idx="' . $idx . '"'
               . ' data-crit-name="' . s(shorten_text($crit['name'], 50)) . '">';

            $ce_full = trim(preg_replace('/^(CE\s*\d+\.?\s*)/i', '', $ce['name']));
            if ($ce_full === '' || strcasecmp($ce_full, $short_label) === 0) { $ce_full = $ce['name']; }
            $back_ce_name = $short_label . ' – ' . shorten_text($ce_full, 40);
            echo '<a href="#" class="sn-back-link" onclick="sentinelCECritBack(' . $idx . '); return false">← Volver a ' . s($back_ce_name) . '</a>';
            echo '<div class="sn-l3-header" style="border-left-color:' . $color . '">';
            echo '<div class="sn-l3-badge-row">';
            echo '<span style="font-weight:800;font-size:1.05em;color:' . $color . '">' . s($short_label) . '</span>';
            echo '<span style="font-size:.8em;font-weight:600;color:' . $color . ';opacity:.8">' . s($crit['name']) . '</span>';
            if ($crit['grade'] !== null) {
                echo sentinel_badge($crit['grade']);
                echo sentinel_level_badge($crit['grade']);
            }
            echo '</div>';
            echo '</div>';

            echo '<div class="table-responsive"><table class="table sn-act-table">';
            echo '<thead><tr>';
            echo '<th>' . get_string('report:activity', 'local_sentinel') . '</th>';
            echo '<th>' . get_string('report:criterion', 'local_sentinel') . '</th>';
            echo '<th class="text-center">' . get_string('report:grade', 'local_sentinel') . '</th>';
            echo '<th>' . get_string('report:level', 'local_sentinel') . '</th>';
            echo '<th>' . get_string('report:date', 'local_sentinel') . '</th>';
            echo '<th></th>';
            echo '</tr></thead><tbody>';

            foreach ($crit['activities'] as $act) {
                $datestr = userdate($act['gradetime'], get_string('strftimedatefullshort', 'langconfig'));
                echo '<tr>';
                echo '<td>' . s($act['activityname']) . '</td>';
                echo '<td class="small text-muted">' . s(shorten_text($act['criteriadesc'], 80)) . '</td>';
                echo '<td class="text-center">' . sentinel_badge($act['grade']) . '</td>';
                echo '<td>' . s($act['leveldefinition']) . '</td>';
                echo '<td>' . $datestr . '</td>';

                $can_grade = false;
                if (!$iscurrentuser) {
                    $cctx = context_course::instance($selected_courseid);
                    $can_grade = has_capability('mod/assign:grade', $cctx);
                }
                if ($can_grade) {
                    $rubricurl = new moodle_url('/mod/assign/view.php', [
                        'id' => $act['cmid'], 'userid' => $userid, 'action' => 'grader',
                    ]);
                } else {
                    $rubricurl = new moodle_url('/mod/assign/view.php', [
                        'id' => $act['cmid'],
                    ]);
                }
                echo '<td>' . html_writer::link($rubricurl, get_string('report:viewrubric', 'local_sentinel'),
                    ['class' => 'btn btn-outline-secondary btn-sm', 'target' => '_blank']) . '</td>';

                echo '</tr>';
            }
            echo '</tbody></table></div>';
            echo '</div>'; // #sentinel-ce-l3-{idx}-{ci}
        }
    }
}

// ── Permission map: drill-down access per course ────────────────────────────
$full_readonly_access = $is_admin || $is_tutor_user;
$course_grade_map = [];
if (!$iscurrentuser) {
    foreach ($student_courses_all as $sc) {
        $cctx = context_course::instance($sc->id);
        $course_grade_map[$sc->id] = has_capability('mod/assign:grade', $cctx);
    }
}
$desc_has_visible_acts = [];
foreach ($areas as $acode => $adata) {
    foreach ($adata['descriptors'] as $dcode => $ddata) {
        $desc_has_visible_acts[$dcode] = false;
        if (!empty($ddata['activities'])) {
            foreach ($ddata['activities'] as $act) {
                if ($iscurrentuser || $full_readonly_access || ($course_grade_map[$act['courseid']] ?? false)) {
                    $desc_has_visible_acts[$dcode] = true;
                    break;
                }
            }
        }
    }
}

// ────────────────────────────────────────────────────────────────────────────
// NIVEL 2 — Descriptores de un área
// ────────────────────────────────────────────────────────────────────────────
foreach ($areas as $acode => $adata) {
    echo '<div id="sentinel-l2-' . $acode . '" class="sentinel-panel"'
       . ' data-area-code="' . s($acode) . '"'
       . ' data-area-name="' . s($adata['name']) . '">';

    echo '<a href="#" class="sn-back-link" onclick="sentinelBack(\'l1\'); return false">← Volver a Competencias Clave</a>';
    $areadef = descriptor_calculator::AREAS[$acode];
    // Cabecera del área.
    echo '<h4 style="margin-bottom:.4rem">';
    echo '<span style="background:' . $areadef['color'] . ';color:#fff;padding:2px 8px;border-radius:4px;font-size:.85em">' . s($acode) . '</span> ';
    echo s($areadef['name']);
    if ($adata['partial_grade'] !== null) {
        $pg_color = descriptor_calculator::grade_colour($adata['partial_grade']);
        echo '&nbsp;&nbsp;<span style="font-weight:700;color:' . $pg_color . '">'
           . number_format($adata['partial_grade'], 1)
           . sentinel_sparkline_svg($area_sparklines[$acode] ?? [], $pg_color)
           . '</span>';
        $l2_show_global = number_format($adata['grade'], 1) !== number_format($adata['partial_grade'], 1);
        echo '&nbsp;&nbsp;<small class="text-muted">'
           . $adata['worked'] . '/' . $adata['total'] . ' desc.';
        if ($l2_show_global) {
            $ck_color = descriptor_calculator::grade_colour($adata['grade']);
            echo '&nbsp;·&nbsp;Global:&nbsp;'
               . '<span style="color:' . $ck_color . ';font-weight:700">' . number_format($adata['grade'], 1) . '</span>';
        }
        echo '</small>';
    }
    echo '</h4>';

    echo '<div class="sentinel-desc-grid">';

    foreach ($adata['descriptors'] as $dcode => $ddata) {
        $worked = $ddata['grade'] !== null;
        $dcolor = $areadef['color'];

        if (!$worked) {
            echo '<div class="sentinel-desc-card sentinel-desc-unworked"'
               . ' style="border-left:3px solid ' . $dcolor . '44">';
            echo '<span class="sn-area-chip" style="background:' . $dcolor . '18;color:' . $dcolor . 'aa">'
               . s($acode) . '</span>';
            echo '<div class="sn-desc-code" style="color:#bbb">' . s($dcode) . '</div>';
            echo '<div style="font-size:.75em;color:#aaa;font-style:italic;margin-top:3px">'
               . get_string('report:not_worked', 'local_sentinel') . '</div>';
            echo '<div class="sn-desc-text-unworked">' . s(shorten_text($ddata['description'], 200)) . '</div>';
            echo '</div>';
        } else {
            echo '<div class="sentinel-desc-card"'
               . ' style="border:1.5px solid ' . $dcolor . '25;border-left:4px solid ' . $dcolor . ';background:#fff;--sn-tint:' . $dcolor . '0D"'
               . ' onclick="sentinelShowDescriptor(\'' . $dcode . '\')"'
               . ' tabindex="0" role="button" aria-label="' . s($dcode) . '">';
            echo '<div style="font-size:.68em;font-weight:700;letter-spacing:.06em;color:' . $dcolor . 'AA;margin-bottom:4px">'
               . s($acode) . '</div>';

            // ── Header: dos columnas ──
            echo '<div class="sn-dt">';
            // Izquierda: código + nota grande + ocasiones
            echo '<div class="sn-dt-l">';
            echo '<span class="sn-desc-code" style="color:' . $dcolor . '">' . s($dcode) . '</span>';
            echo '<div class="sn-dgrade-row">';
            $dgc = descriptor_calculator::grade_colour($ddata['grade']);
            echo '<span class="sn-dgrade" style="color:' . $dgc . '">'
               . number_format($ddata['grade'], 1)
               . sentinel_sparkline_svg($desc_sparklines[$acode][$dcode] ?? [], $dgc)
               . '</span>';
            echo '<span class="sn-docc">' . $ddata['occasions'] . ' ' . ($ddata['occasions'] === 1 ? 'ocasión' : 'ocasiones') . '</span>';
            echo '</div>';
            if (!empty($ddata['courses'])) {
                $dmax = 3;
                $dshown = array_slice($ddata['courses'], 0, $dmax);
                $drest  = count($ddata['courses']) - $dmax;
                $dtxt   = implode(' · ', $dshown);
                if ($drest > 0) { $dtxt .= ' · +' . $drest; }
                echo '<div class="sn-d-courses" title="' . s(implode(', ', $ddata['courses'])) . '">' . s($dtxt) . '</div>';
            }
            echo '</div>';
            // Derecha: historial por comunicación + vs clase + nivel
            echo '<div class="sn-dt-r">';
            if (!empty($periods) && !empty($period_grades)) {
                $n_p  = count($periods);
                $bw   = max(5, min(9, (int)(55 / $n_p)));
                $bgap = max(2, min(3, (int)(8 / $n_p)));
                $maxh = 22;
                $svgw = $n_p * ($bw + $bgap) - $bgap;
                $svgh = $maxh + 10;
                echo '<svg width="' . $svgw . '" height="' . $svgh . '" style="display:block" title="Evolución por comunicación">';
                $i = 0;
                foreach ($periods as $p) {
                    $pg = $period_grades[$p->id][$dcode] ?? null;
                    $bx = $i * ($bw + $bgap);
                    $plabel = $p->label ?: (($i + 1) . '.ª comunicación');
                    if ($pg !== null) {
                        $bh = max(3, (int)round($pg / 10 * $maxh));
                        $by = $maxh - $bh + 2;
                        $bc = descriptor_calculator::grade_colour($pg);
                        echo '<g><rect x="' . $bx . '" y="' . $by . '" width="' . $bw . '" height="' . $bh . '" rx="1.5" fill="' . $bc . '"/>'
                           . '<title>' . s($plabel) . ': ' . number_format($pg, 1) . '</title></g>';
                    } else {
                        echo '<g><rect x="' . $bx . '" y="' . ($maxh) . '" width="' . $bw . '" height="2" rx="1" fill="#e5e7eb"/>'
                           . '<title>' . s($plabel) . ': sin datos</title></g>';
                    }
                    $cx = $bx + $bw / 2;
                    echo '<text x="' . $cx . '" y="' . ($svgh) . '" text-anchor="middle" font-size="6.5" fill="#b0b5bc">' . ($i + 1) . '</text>';
                    $i++;
                }
                echo '</svg>';
            }
            // (Comparativa vs clase eliminada.)
            echo sentinel_level_badge($ddata['grade']);
            echo '</div>'; // .sn-dt-r
            echo '</div>'; // .sn-dt

            // Barra de progreso (ancho completo)
            echo sentinel_bar($ddata['grade'], descriptor_calculator::grade_colour($ddata['grade']), 100);

            // Coverage indicator.
            $d_student_acts = count(array_unique(array_column($ddata['activities'], 'cmid')));
            $d_group_total  = $desc_group_totals[$dcode] ?? 0;
            $d_cov = descriptor_calculator::classify_coverage($d_student_acts, $d_group_total);
            echo '<div class="sn-card-repr sn-card-repr--' . $d_cov['level'] . '" style="margin-top:5px">'
               . '<span class="sn-card-repr-dot sn-card-repr-dot--' . $d_cov['level'] . '"></span>'
               . '<span class="sn-card-repr-txt">';
            if ($d_cov['level'] !== 'grey') {
                echo number_format($d_cov['pct'], 0) . '% · ';
            }
            echo s($d_cov['label']);
            if ($d_group_total > 0) {
                echo ' (' . $d_student_acts . '/' . $d_group_total . ')';
            }
            echo '</span></div>';

            // Descripción
            echo '<div class="sn-desc-text" style="margin-top:5px">' . s(shorten_text($ddata['description'], 130)) . '</div>';

            // Footer: enlace al detalle (solo si el viewer puede ver actividades)
            if (!empty($ddata['courses']) && ($desc_has_visible_acts[$dcode] ?? false)) {
                echo '<div class="sn-d-footer">';
                echo '<a class="sn-d-arr" onclick="event.stopPropagation();sentinelShowDescriptor(\'' . $dcode . '\')">'
                   . get_string('report:subjects_detail', 'local_sentinel') . ' →</a>';
                echo '</div>';
            }

            echo '</div>'; // .sentinel-desc-card
        }
    }

    echo '</div>'; // .sentinel-desc-grid
    echo '</div>'; // #sentinel-l2-{acode}
}

// ────────────────────────────────────────────────────────────────────────────
// NIVEL 3 — Tabla de actividades por descriptor
// ────────────────────────────────────────────────────────────────────────────
foreach ($areas as $acode => $adata) {
    foreach ($adata['descriptors'] as $dcode => $ddata) {
        if (empty($ddata['activities'])) {
            continue;
        }

        echo '<div id="sentinel-l3-' . $dcode . '" class="sentinel-panel"'
           . ' data-area-code="' . s($acode) . '"'
           . ' data-desc-code="' . s($dcode) . '">';

        $back_area_name = $acode . ' – ' . shorten_text($adata['name'], 40);
        echo '<a href="#" class="sn-back-link" onclick="sentinelBack(\'l2-' . s($acode) . '\'); return false">← Volver a ' . s($back_area_name) . '</a>';
        $areadef = descriptor_calculator::AREAS[$acode];
        echo '<div class="sn-l3-header" style="border-left-color:' . $areadef['color'] . '">';
        echo '<div class="sn-l3-badge-row">';
        echo '<span style="font-weight:800;font-size:1.05em;color:' . $areadef['color'] . '">' . s($dcode) . '</span>';
        echo '<span style="font-size:.8em;font-weight:600;color:' . $areadef['color'] . ';opacity:.8">' . s($areadef['name']) . '</span>';
        echo '<span class="badge badge-light border text-muted" style="font-size:.72em">Descriptor del perfil de salida</span>';
        if ($ddata['grade'] !== null) {
            echo sentinel_badge($ddata['grade']);
            echo sentinel_level_badge($ddata['grade']);
        }
        echo '</div>';
        echo '<div class="sn-l3-desc">' . s($ddata['description']) . '</div>';

        // Coverage badge.
        $l3_student_acts = count(array_unique(array_column($ddata['activities'], 'cmid')));
        $l3_group_total  = $desc_group_totals[$dcode] ?? 0;
        $l3_cov = descriptor_calculator::classify_coverage($l3_student_acts, $l3_group_total);
        echo '<div class="sn-card-repr sn-card-repr--' . $l3_cov['level'] . '" style="margin-top:6px">'
           . '<span class="sn-card-repr-dot sn-card-repr-dot--' . $l3_cov['level'] . '"></span>'
           . '<span class="sn-card-repr-txt">';
        if ($l3_cov['level'] !== 'grey') {
            echo number_format($l3_cov['pct'], 0) . '% · ';
        }
        echo s($l3_cov['label']);
        if ($l3_group_total > 0) {
            echo ' (' . $l3_student_acts . '/' . $l3_group_total . ' actividades)';
        }
        echo '</span></div>';

        echo '</div>'; // .sn-l3-header

        echo '<div class="table-responsive">';
        echo '<table class="table sn-act-table">';
        echo '<thead><tr>';
        echo '<th>' . get_string('report:activity', 'local_sentinel') . '</th>';
        echo '<th>' . get_string('report:course', 'local_sentinel') . '</th>';
        echo '<th>' . get_string('report:criterion', 'local_sentinel') . '</th>';
        echo '<th class="text-center">' . get_string('report:grade', 'local_sentinel') . '</th>';
        echo '<th>' . get_string('report:level', 'local_sentinel') . '</th>';
        echo '<th>' . get_string('report:date', 'local_sentinel') . '</th>';
        echo '<th></th>';
        echo '</tr></thead>';
        echo '<tbody>';

        foreach ($ddata['activities'] as $act) {
            $act_cid = $act['courseid'];
            $can_grade_here = !$iscurrentuser && ($course_grade_map[$act_cid] ?? false);
            $can_see_act = $iscurrentuser || $full_readonly_access || $can_grade_here;
            if (!$can_see_act) {
                continue;
            }

            if ($can_grade_here) {
                $rubricurl = new moodle_url('/mod/assign/view.php', [
                    'id' => $act['cmid'], 'userid' => $userid, 'action' => 'grader',
                ]);
            } else {
                $rubricurl = new moodle_url('/mod/assign/view.php', [
                    'id' => $act['cmid'],
                ]);
            }
            $datestr = userdate($act['gradetime'], get_string('strftimedatefullshort', 'langconfig'));

            echo '<tr>';
            echo '<td>' . s($act['activityname']) . '</td>';
            echo '<td>' . s($act['coursename']) . '</td>';
            echo '<td class="small text-muted">' . s(shorten_text($act['criteriadesc'], 80)) . '</td>';
            echo '<td class="text-center">' . sentinel_badge($act['grade']) . '</td>';
            echo '<td>' . s($act['leveldefinition']) . '</td>';
            echo '<td>' . $datestr . '</td>';
            echo '<td>' . html_writer::link($rubricurl, get_string('report:viewrubric', 'local_sentinel'),
                ['class' => 'btn btn-outline-secondary btn-sm', 'target' => '_blank']) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table></div>';
        echo '</div>'; // #sentinel-l3-{dcode}
    }
}

// ── Pane: Objetivos de Etapa ─────────────────────────────────────────────────
echo '<div id="sentinel-pane-objetivos" class="sn-view-pane">';
if (!empty($objectives_data['objectives'])) {
    echo '<h4 style="margin-bottom:.3rem">' . s($objectives_data['label']) . '</h4>';
    echo '<div class="sn-obj-tooltip-text">'
       . get_string('objectives:tooltip', 'local_sentinel')
       . ' (' . s($objectives_data['source']) . ').</div>';

    $objs = $objectives_data['objectives'];
    $n_objs = count($objs);

    // ── A. Unified horizontal lollipop chart (all objectives at once) ────────
    $lp_row_h   = 28;
    $lp_pad_l   = 28;
    $lp_pad_r   = 12;
    $lp_pad_t   = 10;
    $lp_chart_w = 400;
    $lp_svg_w   = $lp_pad_l + $lp_chart_w + $lp_pad_r;
    $lp_svg_h   = $lp_pad_t + $n_objs * $lp_row_h + 10;

    // Build JSON data for tooltip JS (includes DUA selfeval if available).
    $obj_tip_data = [];
    foreach ($objs as $oi => $obj) {
        $se_pts = $selfeval_sparklines[$obj['code']] ?? [];
        $se_grade = !empty($se_pts) ? end($se_pts)[1] : null;
        $obj_tip_data[] = [
            'code'    => $obj['code'],
            'text'    => $obj['text'],
            'grade'   => $obj['grade'],
            'selfeval' => $se_grade,
        ];
    }

    echo '<div class="sn-obj-lollipop-wrap" id="sn-obj-lollipop-wrap">';
    echo '<div class="sn-obj-tip" id="sn-obj-tip">'
       . '<div class="sn-obj-tip-code" id="sn-obj-tip-code"></div>'
       . '<div class="sn-obj-tip-text" id="sn-obj-tip-text"></div>'
       . '<div class="sn-obj-tip-grade" id="sn-obj-tip-grade"></div>'
       . '</div>';
    echo '<svg id="sn-obj-lollipop-svg" viewBox="0 0 ' . $lp_svg_w . ' ' . $lp_svg_h
       . '" preserveAspectRatio="xMinYMin meet" style="max-height:' . ($lp_svg_h + 20) . 'px">';

    // X-axis grid: 0, 2, 4, 6, 8, 10.
    for ($tick = 0; $tick <= 10; $tick += 2) {
        $tx = $lp_pad_l + ($tick / 10) * $lp_chart_w;
        $dash = $tick === 0 ? '' : ' stroke-dasharray="3,3"';
        echo '<line x1="' . round($tx, 1) . '" y1="' . $lp_pad_t . '" x2="' . round($tx, 1)
           . '" y2="' . ($lp_svg_h - 4) . '" stroke="#e5e7eb"' . $dash . '/>';
        echo '<text x="' . round($tx, 1) . '" y="' . ($lp_svg_h) . '" text-anchor="middle" font-size="7.5" fill="#9ca3af">'
           . $tick . '</text>';
    }

    // One horizontal lollipop per objective.
    foreach ($objs as $oi => $obj) {
        $cy = $lp_pad_t + $oi * $lp_row_h + $lp_row_h / 2;
        $avg_col = $obj['grade'] !== null ? descriptor_calculator::grade_colour($obj['grade']) : '#d1d5db';
        $code_lc = strtolower($obj['code']);

        // Row label (letter).
        echo '<text x="' . ($lp_pad_l - 6) . '" y="' . ($cy + 4) . '" text-anchor="end"'
           . ' font-size="10" font-weight="700" fill="' . $avg_col . '">' . s($code_lc) . '</text>';

        if ($obj['grade'] !== null) {
            $end_x = $lp_pad_l + ($obj['grade'] / 10) * $lp_chart_w;
            // Stick.
            echo '<line x1="' . $lp_pad_l . '" y1="' . $cy . '" x2="' . round($end_x, 1)
               . '" y2="' . $cy . '" stroke="' . $avg_col . '" stroke-width="2.5" opacity=".35"/>';
            // Circle (interactive).
            echo '<circle class="sn-obj-lp-dot" data-idx="' . $oi . '" cx="' . round($end_x, 1) . '" cy="' . $cy
               . '" r="6" fill="' . $avg_col . '" stroke="#fff" stroke-width="1.8" style="cursor:pointer"/>';
        } else {
            // Grey stub for unworked objective.
            echo '<line x1="' . $lp_pad_l . '" y1="' . $cy . '" x2="' . ($lp_pad_l + 10)
               . '" y2="' . $cy . '" stroke="#d1d5db" stroke-width="2" stroke-dasharray="3,2"/>';
            echo '<circle class="sn-obj-lp-dot" data-idx="' . $oi . '" cx="' . ($lp_pad_l + 10) . '" cy="' . $cy
               . '" r="4.5" fill="#e5e7eb" stroke="#d1d5db" stroke-width="1" style="cursor:pointer"/>';
        }
    }

    echo '</svg>';
    echo '</div>'; // .sn-obj-lollipop-wrap

    // Collapse/expand toggle for detail cards.
    echo '<button class="sn-obj-collapse-btn" id="sn-obj-collapse-btn" onclick="'
       . 'var g=document.getElementById(\'sn-obj-grid\');'
       . 'var b=this;'
       . 'g.classList.toggle(\'sn-collapsed\');'
       . 'b.classList.toggle(\'sn-collapsed\');'
       . 'var open=!g.classList.contains(\'sn-collapsed\');'
       . 'b.querySelector(\'span\').textContent=open?\'Ocultar detalle\':\'Ver detalle\';'
       . '">'
       . '<svg width="10" height="10" viewBox="0 0 10 10" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">'
       . '<polyline points="2,3 5,7 8,3"/></svg>'
       . '<span>Ocultar detalle</span></button>';

    // ── B. Detail cards: full text + sparkline + descriptor pills ────────────
    echo '<div class="sn-obj-grid" id="sn-obj-grid">';
    foreach ($objs as $oi => $obj) {
        $avg_col = $obj['grade'] !== null ? descriptor_calculator::grade_colour($obj['grade']) : '#d1d5db';

        echo '<div class="sn-obj-card">';
        echo '<div class="sn-obj-header">';
        echo '<span class="sn-obj-code" style="background:' . $avg_col . '">' . s(strtolower($obj['code'])) . '</span>';
        echo '<div class="sn-obj-text">' . s($obj['text']) . '</div>';

        // Grade + sparkline.
        if ($obj['grade'] !== null) {
            $sp = $obj['sparkline'] ?? [];
            $se_sp = $selfeval_sparklines[$obj['code']] ?? [];
            echo '<span class="sn-obj-avg" style="color:' . $avg_col . '">'
               . number_format($obj['grade'], 1)
               . sentinel_sparkline_svg($sp, $avg_col, 56, 18, $se_sp)
               . '</span>';
        } else {
            echo '<span class="sn-obj-avg" style="color:#d1d5db">—</span>';
        }
        echo '</div>'; // .sn-obj-header

        // Descriptor pills.
        if (!empty($obj['descriptors'])) {
            echo '<div class="sn-obj-pills">';
            foreach ($obj['descriptors'] as $dc) {
                $area_code = '';
                foreach (descriptor_calculator::AREAS as $ac => $ad) {
                    if (strpos($dc, $ac) === 0) { $area_code = $ac; break; }
                }
                $pill_bg = $area_code ? descriptor_calculator::AREAS[$area_code]['color'] . '18' : '#f1f5f9';
                $pill_fg = $area_code ? descriptor_calculator::AREAS[$area_code]['color'] : '#475569';
                $pill_tip = isset($descriptor_texts[$dc])
                    ? '<span class="sn-pill-tip"><span class="sn-pill-tip-code" style="color:' . $pill_fg . '">' . s($dc) . '</span><span class="sn-pill-tip-desc">' . s($descriptor_texts[$dc]) . '</span></span>'
                    : '';
                echo '<span class="sn-obj-pill" style="background:' . $pill_bg . ';color:' . $pill_fg . '">'
                   . s($dc) . $pill_tip . '</span>';
            }
            echo '</div>';
        }

        // Footer.
        $cov = $obj['coverage'];
        echo '<div class="sn-obj-footer">';
        if ($obj['grade'] !== null) {
            echo sentinel_level_badge($obj['grade']);
        }
        echo '<div class="sn-card-repr sn-card-repr--' . $cov['level'] . '" style="margin:0">'
           . '<span class="sn-card-repr-dot sn-card-repr-dot--' . $cov['level'] . '"></span>'
           . '<span class="sn-card-repr-txt">' . $obj['worked'] . '/' . $obj['total'] . ' desc.</span></div>';
        echo '</div>';

        echo '</div>'; // .sn-obj-card
    }
    echo '</div>'; // .sn-obj-grid

    // ── Tooltip JS for unified lollipop ──────────────────────────────────────
    $obj_tip_json = json_encode($obj_tip_data, JSON_UNESCAPED_UNICODE);
    echo '<script>
(function(){
var data=' . $obj_tip_json . ';
var wrap=document.getElementById("sn-obj-lollipop-wrap");
var tip=document.getElementById("sn-obj-tip");
var tipCode=document.getElementById("sn-obj-tip-code");
var tipText=document.getElementById("sn-obj-tip-text");
var tipGrade=document.getElementById("sn-obj-tip-grade");
if(!wrap||!tip) return;
wrap.addEventListener("mouseover",function(e){
    var dot=e.target.closest(".sn-obj-lp-dot");
    if(!dot) return;
    var idx=parseInt(dot.dataset.idx,10);
    var d=data[idx]; if(!d) return;
    tipCode.textContent="Objetivo "+d.code;
    tipText.textContent=d.text;
    var gt=d.grade!==null?"Profesor: "+d.grade.toFixed(1):"Sin datos";
    if(d.selfeval!==null) gt+="  ·  Autoeval: "+d.selfeval.toFixed(1)+" (DUA)";
    tipGrade.textContent=gt;
    tip.style.display="block";
    var r=wrap.getBoundingClientRect();
    var cr=dot.getBoundingClientRect();
    tip.style.left=Math.min(cr.left-r.left+12, r.width-260)+"px";
    tip.style.top=(cr.top-r.top-tip.offsetHeight-8)+"px";
});
wrap.addEventListener("mouseout",function(e){
    if(e.target.closest(".sn-obj-lp-dot")) tip.style.display="none";
});
})();
</script>';

} else {
    echo '<div class="sn-placeholder-pane">';
    echo '<div class="sn-ph-icon">🎯</div>';
    echo '<h4>Objetivos de la etapa</h4>';
    echo '<p>' . get_string('objectives:nodata', 'local_sentinel') . '</p>';
    echo '</div>';
}
echo '</div>';

if ($show_transversales) {
    echo '<div id="sentinel-pane-transversales" class="sn-view-pane">';
    echo '<div class="sn-placeholder-pane">';
    echo '<div class="sn-ph-icon">🔗</div>';
    echo '<h4>Contenidos transversales</h4>';
    echo '<p>Contenidos que el Decreto 65/2022 establece que se trabajarán en todas las materias de la etapa.</p>';
    echo '</div></div>';
}

if ($has_subject_tab) {
    $cnt_init_cls = ($inittab === 'contenidos') ? ' active' : '';
    echo '<div id="sentinel-pane-contenidos" class="sn-view-pane' . $cnt_init_cls . '">';

    // ── Cargar datos de contenidos (bloques de saberes básicos) ──
    $cnt_blocks = [];
    $cnt_subject_label = $student_subject_name ?: '';
    if ($student_applied && !empty($student_applied['subject']) && !empty($student_applied['stage']) && !empty($student_applied['grade'])) {
        $cnt_stage = $student_applied['stage'];
        $cnt_grade = (int)$student_applied['grade'];
        $cnt_subject_key = $student_applied['subject'];
        $cnt_file = __DIR__ . '/data/curriculo/madrid/' . $cnt_stage . '/contenidos_' . $cnt_grade . $cnt_stage . '.json';
        if (file_exists($cnt_file)) {
            $cnt_all = json_decode(file_get_contents($cnt_file), true) ?: [];
            if (isset($cnt_all[$cnt_subject_key])) {
                $cnt_blocks = $cnt_all[$cnt_subject_key]['blocks'] ?? [];
            }
        }
    }

    // ── Leer calificaciones de competencias del alumno (SQL directo) ──
    $cnt_grades = [];
    $cnt_activity_avgs = [];
    $cnt_sub_activities = [];
    $cnt_raw_grades = [];
    $cnt_fw_info = null;
    if ($selected_courseid) {
        $cnt_fw_json = get_config('local_sentinel', "contenidos_fw_{$selected_courseid}");
        if ($cnt_fw_json) {
            $cnt_fw_info = json_decode($cnt_fw_json, true);
        }
        if ($cnt_fw_info && !empty($cnt_fw_info['framework_id'])) {
            $fwid = (int)$cnt_fw_info['framework_id'];
            $sql = "SELECT ucc.id, ucc.competencyid, ucc.grade, ucc.proficiency,
                           c.idnumber, c.shortname
                      FROM {competency_usercompcourse} ucc
                      JOIN {competency} c ON c.id = ucc.competencyid
                     WHERE ucc.userid = :uid AND ucc.courseid = :cid
                       AND c.competencyframeworkid = :fwid
                       AND ucc.grade IS NOT NULL AND ucc.grade > 0";
            try {
                $records = $DB->get_records_sql($sql, [
                    'uid' => $userid, 'cid' => $selected_courseid, 'fwid' => $fwid,
                ]);
                foreach ($records as $rec) {
                    // Extraer código: sentinel_contenidos_musica_1_A1.1 → A1.1
                    $parts = explode('_', $rec->idnumber);
                    $sub_code = end($parts);
                    $mapped = 0;
                    if ($rec->grade == 3) $mapped = 10;
                    else if ($rec->grade == 2) $mapped = 5;
                    $cnt_grades[$sub_code] = [
                        'grade' => $mapped,
                        'scale_val' => (int)$rec->grade,
                    ];
                }
            } catch (\Exception $e) {
                // Tabla no existe o framework borrado.
            }

            // ── Notas reales de actividades vinculadas a cada saber (via competency_modulecomp) ──
            try {
                $sql_act = "SELECT cmc.competencyid, c.idnumber AS comp_idnumber,
                                   gi.id AS giid, gg.finalgrade, gi.grademax, gi.grademin,
                                   gg.timemodified AS graded_at
                              FROM {competency_modulecomp} cmc
                              JOIN {competency} c ON c.id = cmc.competencyid
                                   AND c.competencyframeworkid = :fwid2
                              JOIN {course_modules} cm ON cm.id = cmc.cmid
                              JOIN {modules} m ON m.id = cm.module
                              JOIN {grade_items} gi ON gi.itemtype = 'mod'
                                   AND gi.itemmodule = m.name
                                   AND gi.iteminstance = cm.instance
                                   AND gi.courseid = :cid2
                              JOIN {grade_grades} gg ON gg.itemid = gi.id AND gg.userid = :uid2
                             WHERE gg.finalgrade IS NOT NULL";
                $act_records = $DB->get_records_sql($sql_act, [
                    'fwid2' => $fwid, 'cid2' => $selected_courseid, 'uid2' => $userid,
                ]);
                $item_grades_map = [];
                $sub_grades_map = [];
                foreach ($act_records as $ar) {
                    $parts = explode('_', $ar->comp_idnumber);
                    $sub_code = end($parts);
                    $dot_pos = strrpos($sub_code, '.');
                    $item_code = $dot_pos !== false ? substr($sub_code, 0, $dot_pos) : $sub_code;
                    $range = $ar->grademax - $ar->grademin;
                    $norm = $range > 0 ? (($ar->finalgrade - $ar->grademin) / $range) * 10 : 0;
                    $item_grades_map[$item_code][$ar->giid] = $norm;
                    $sub_grades_map[$sub_code][$ar->giid] = $norm;
                    $cnt_raw_grades[] = [
                        's' => $sub_code,
                        'i' => $item_code,
                        'g' => round($norm, 2),
                        'gi' => (int)$ar->giid,
                        't' => (int)$ar->graded_at,
                    ];
                }
                foreach ($item_grades_map as $ic => $grades) {
                    $cnt_activity_avgs[$ic] = round(array_sum($grades) / count($grades), 1);
                }
                foreach ($sub_grades_map as $sc => $grades) {
                    $cnt_sub_activities[$sc] = [
                        'avg' => round(array_sum($grades) / count($grades), 1),
                        'count' => count($grades),
                    ];
                }
            } catch (\Exception $e) {
                // Tabla no disponible.
            }
        }
    }

    // ── Colores por bloque: bg=pastel ultraclaro, fg=saturado para dots/etiquetas ──
    $block_colors = [
        'A' => ['bg' => '#F4F7FE', 'fg' => '#3B72C9', 'label' => 'Azul índigo'],
        'B' => ['bg' => '#FEF6F5', 'fg' => '#C0554E', 'label' => 'Terracota'],
        'C' => ['bg' => '#F3FAF5', 'fg' => '#3D8B5E', 'label' => 'Esmeralda'],
        'D' => ['bg' => '#F6F4FB', 'fg' => '#6E53A8', 'label' => 'Violeta'],
        'E' => ['bg' => '#FFFAF2', 'fg' => '#B8862B', 'label' => 'Dorado'],
        'F' => ['bg' => '#FDF4FA', 'fg' => '#A04E7E', 'label' => 'Magenta'],
        'T' => ['bg' => '#F2F6F9', 'fg' => '#4E7F97', 'label' => 'Transversales'],
    ];

    // Recopilar todos los items (saberes principales) de todos los bloques.
    $all_items = [];
    foreach ($cnt_blocks as $block) {
        $bid = $block['id'] ?? '?';
        foreach ($block['items'] ?? [] as $item) {
            $item['_block'] = $bid;
            $item['_block_name'] = $block['name'] ?? '';
            $all_items[] = $item;
        }
    }
    $n_items = count($all_items);

    // Calcular datos para cada item: si tiene algún sub calificado.
    $diana_items = [];
    foreach ($all_items as $idx => $item) {
        $code = $item['c'];
        $bid = $item['_block'];
        $bc = $block_colors[$bid] ?? ['bg' => '#f0f0f0', 'fg' => '#666', 'label' => $bid];
        $subs = $item['subs'] ?? [];
        $sub_grades = [];
        $sub_acts = [];
        $has_grade = false;
        foreach ($subs as $sub) {
            $sc = $sub['c'];
            if (isset($cnt_grades[$sc])) {
                $sub_grades[$sc] = $cnt_grades[$sc];
                $has_grade = true;
            }
            if (isset($cnt_sub_activities[$sc])) {
                $sub_acts[$sc] = $cnt_sub_activities[$sc];
                $has_grade = true;
            }
        }
        $diana_items[] = [
            'code'       => $code,
            'legal'      => $item['legal'] ?? '',
            'block'      => $bid,
            'block_name' => $item['_block_name'],
            'bg'         => $bc['bg'],
            'fg'         => $bc['fg'],
            'subs'       => $subs,
            'sub_grades' => $sub_grades,
            'sub_acts'   => $sub_acts,
            'has_grade'  => $has_grade,
            'idx'        => $idx,
        ];
    }

    // JSON para el JS.
    $diana_js_data = json_encode([
        'items' => $diana_items,
        'nItems' => $n_items,
        'nBlocks' => count($cnt_blocks),
        'blocks' => array_map(function($b) use ($block_colors) {
            $bid = $b['id'] ?? '?';
            $bc = $block_colors[$bid] ?? ['bg' => '#f0f0f0', 'fg' => '#666'];
            return [
                'id' => $bid,
                'name' => $b['name'] ?? '',
                'bg' => $bc['bg'],
                'fg' => $bc['fg'],
                'itemCount' => count($b['items'] ?? []),
            ];
        }, $cnt_blocks),
        'rawGrades' => $cnt_raw_grades,
    ]);

    if (empty($cnt_blocks)) {
        echo '<div class="sn-placeholder-pane">';
        echo '<div class="sn-ph-icon">📚</div>';
        echo '<h4>Contenidos de ' . s($cnt_subject_label) . '</h4>';
        echo '<p>No se han encontrado contenidos curriculares para esta materia. '
           . 'Configure el currículo desde "Saberes básicos" e inyecte las competencias.</p>';
        echo '</div>';
    } else {
        // ── CSS específico de la diana nórdica de contenidos ──
        echo '<style>
#sn-nordic-diana { width: 100%; display: block; }

.sn-nordic-legend {
    display: flex; gap: 12px 18px; justify-content: center; flex-wrap: wrap;
    margin: 14px 0; font-size: .78rem;
}
.sn-nordic-legend-item { display: flex; align-items: center; gap: 6px; }
.sn-nordic-legend-dot { width: 12px; height: 12px; border-radius: 3px; }

.sn-nordic-table { width: 100%; margin-top: 16px; border-collapse: collapse; font-size: .82rem; }
.sn-nordic-table th {
    text-align: left; padding: 8px 10px; font-weight: 700; font-size: .72rem;
    letter-spacing: .05em; text-transform: uppercase; color: #64748B;
    border-bottom: 2px solid #E2E8F0;
}
.sn-nordic-table td { padding: 7px 10px; border-bottom: 1px solid #F1F5F9; }
.sn-nordic-table tr:hover td { background: #F8FAFC; }
.sn-cnt-code-pill {
    display: inline-block; padding: 2px 8px; border-radius: 4px;
    font-weight: 700; font-size: .78rem;
}
.sn-cnt-status { font-weight: 600; font-size: .78rem; }
.sn-cnt-status--worked { color: #3D8B5E; }
.sn-cnt-status--process { color: #B8862B; }
.sn-cnt-status--pending { color: #94A3B8; }

.sn-nordic-tooltip {
    position: absolute; background: #fff; border: 1px solid #E2E8F0;
    border-radius: 14px; padding: 16px 20px; font-size: .8rem;
    box-shadow: 0 12px 36px rgba(0,0,0,.10), 0 2px 8px rgba(0,0,0,.04);
    pointer-events: none; z-index: 100; max-width: 340px; min-width: 200px;
    opacity: 0; transition: opacity .18s ease; line-height: 1.5;
    backdrop-filter: blur(6px);
}
.sn-nordic-tooltip.visible { opacity: 1; }
.sn-nordic-tooltip-header {
    display: flex; align-items: center; gap: 8px; margin-bottom: 8px;
    padding-bottom: 8px; border-bottom: 1px solid #F1F5F9;
}
.sn-nordic-tooltip-pill {
    display: inline-block; padding: 2px 10px; border-radius: 6px;
    font-weight: 700; font-size: .75rem; letter-spacing: .03em;
}
.sn-nordic-tooltip-block {
    font-size: .7rem; color: #64748B; font-weight: 500; letter-spacing: .03em;
}
.sn-nordic-tooltip-legal {
    color: #475569; font-size: .74rem; line-height: 1.5; margin-bottom: 10px;
}
.sn-nordic-tooltip-grade {
    font-weight: 600; font-size: .78rem; display: flex; align-items: center; gap: 6px;
}
.sn-nordic-tooltip-grade-dot {
    width: 8px; height: 8px; border-radius: 50%; display: inline-block;
}

/* Acordeones de bloques */
.sn-nordic-block-group { margin-top: 16px; }
.sn-nordic-block-header {
    display: flex; align-items: center; gap: 10px; cursor: pointer;
    padding: 10px 12px; border-radius: 10px; transition: background .15s;
    user-select: none;
}
.sn-nordic-block-header:hover { background: #F8FAFC; }
.sn-nordic-block-header-dot {
    width: 10px; height: 10px; border-radius: 3px; flex-shrink: 0;
}
.sn-nordic-block-header-label {
    font-weight: 600; font-size: .82rem; flex: 1;
}
.sn-nordic-block-header-arrow {
    font-size: .7rem; color: #94A3B8; transition: transform .2s;
}
.sn-nordic-block-header.collapsed .sn-nordic-block-header-arrow {
    transform: rotate(-90deg);
}
.sn-nordic-block-body { overflow: hidden; transition: max-height .25s ease; }
.sn-nordic-block-header.collapsed + .sn-nordic-block-body { max-height: 0 !important; }

.sn-toggle-all-btn {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 6px 14px; border-radius: 8px;
    border: 1px solid #E2E8F0; background: #FAFBFD;
    color: #64748B; font-size: .72rem; font-weight: 600;
    font-family: system-ui, -apple-system, sans-serif;
    cursor: pointer; transition: all .18s ease;
    letter-spacing: .03em;
}
.sn-toggle-all-btn:hover {
    background: #F1F5F9; color: #334155;
    border-color: #CBD5E1; box-shadow: 0 1px 4px rgba(0,0,0,.06);
}
.sn-toggle-all-btn svg { transition: transform .2s; }
.sn-toggle-all-btn:hover svg { transform: scale(1.1); }

.sn-table-tooltip {
    position: absolute; background: #fff; border: 1px solid #E2E8F0;
    border-radius: 14px; padding: 14px 18px; font-size: .8rem;
    box-shadow: 0 12px 36px rgba(0,0,0,.10), 0 2px 8px rgba(0,0,0,.04);
    pointer-events: none; z-index: 100; max-width: 360px; min-width: 200px;
    opacity: 0; transition: opacity .18s ease; line-height: 1.5;
}
.sn-table-tooltip.visible { opacity: 1; }
</style>';

        // ── HTML ──
        echo '<div class="sn-nordic-wrap hist-closed">';
        echo '<div class="sn-nordic-left">';

        echo '<div class="sn-nordic-diana-wrap">';
        echo '<canvas id="sn-nordic-diana" width="560" height="560"></canvas>';
        echo '<div class="sn-nordic-tooltip" id="sn-nordic-tooltip">'
           . '<div class="sn-nordic-tooltip-header">'
           . '<span class="sn-nordic-tooltip-pill" id="sn-tt-pill"></span>'
           . '<span class="sn-nordic-tooltip-block" id="sn-tt-block"></span>'
           . '</div>'
           . '<div class="sn-nordic-tooltip-legal" id="sn-tt-legal"></div>'
           . '<div class="sn-nordic-tooltip-grade" id="sn-tt-grade"></div>'
           . '</div>';

        // Toggle hist panel button (inside diana card, top-right).
        echo '<button class="sn-drawer-btn" id="sn-drawer-btn" title="Histórico" onclick="'
           . 'window.sentinelToggleHist(this);'
           . '">'
           . '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>'
           . '</button>';
        echo '</div>'; // .sn-nordic-diana-wrap

        // Leyenda de bloques (debajo del diagrama, antes de los acordeones).
        echo '<div class="sn-nordic-legend">';
        foreach ($cnt_blocks as $block) {
            $bid = $block['id'] ?? '?';
            $bc = $block_colors[$bid] ?? ['bg' => '#ccc', 'fg' => '#666'];
            echo '<div class="sn-nordic-legend-item">'
               . '<div class="sn-nordic-legend-dot" style="background:' . $bc['fg'] . '"></div>'
               . '<span style="color:' . $bc['fg'] . ';font-weight:500;font-size:.76rem">' . s($bid) . '. ' . s($block['name'] ?? '') . '</span>'
               . '</div>';
        }
        echo '</div>';

        echo '</div>'; // .sn-nordic-left

        // ── Columna derecha: panel de histórico ──
        echo '<div class="sn-nordic-right" id="sn-nordic-right">';
        echo '<div class="sn-nordic-hist">';
        echo '<div class="sn-nordic-hist-title">Histórico de comunicaciones</div>';
        echo '<div class="sn-nordic-hist-list">';

        // Ítem destacado: Curso completo.
        $annual_active = ($cutoff == 0) ? ' active' : '';
        echo '<div class="sn-nordic-hist-item sn-nordic-hist-annual' . $annual_active . '"'
           . ' data-cutoff="0" onclick="window.sentinelSwitchCntPeriod(0)">';
        echo '<span class="sn-nordic-hist-label">Curso completo</span>';
        echo '</div>';

        if (!empty($periods)) {
            foreach (array_reverse($periods) as $p) {
                $pdate = $p->cutoffdate ? userdate($p->cutoffdate, '%d/%m/%Y') : '';
                $plabel = $p->label ?? get_string('report:comm_nth', 'local_sentinel', $p->sortorder ?? '');
                $p_active = ($cutoff > 0 && $cutoff == (int)$p->cutoffdate) ? ' active' : '';
                echo '<div class="sn-nordic-hist-item' . $p_active . '"'
                   . ' data-period="' . (int)$p->id . '"'
                   . ' data-cutoff="' . (int)$p->cutoffdate . '"'
                   . ' onclick="window.sentinelSwitchCntPeriod(' . (int)$p->cutoffdate . ')">';
                echo '<span class="sn-nordic-hist-label">' . s($plabel) . '</span>';
                if ($pdate) {
                    echo '<br><span class="sn-nordic-hist-date">' . $pdate . '</span>';
                }
                echo '</div>';
            }
        }
        echo '</div>'; // .sn-nordic-hist-list
        echo '</div>'; // .sn-nordic-hist
        echo '</div>'; // .sn-nordic-right

        echo '</div>'; // .sn-nordic-wrap

        // Botón colapsar/extender todos los acordeones.
        echo '<div style="display:flex;justify-content:flex-end;margin-top:12px">';
        echo '<button class="sn-toggle-all-btn" onclick="'
           . 'var all=document.querySelectorAll(\'.sn-nordic-block-header\');'
           . 'var anyOpen=false;all.forEach(function(h){if(!h.classList.contains(\'collapsed\'))anyOpen=true;});'
           . 'all.forEach(function(h){if(anyOpen)h.classList.add(\'collapsed\');else h.classList.remove(\'collapsed\');});'
           . 'var svg=this.querySelector(\'svg\');'
           . 'if(anyOpen){this.querySelector(\'span\').textContent=\'Expandir todo\';svg.style.transform=\'rotate(180deg)\';}'
           . 'else{this.querySelector(\'span\').textContent=\'Colapsar todo\';svg.style.transform=\'\';}'
           . '">'
           . '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="transition:transform .25s ease"><polyline points="18 15 12 9 6 15"/></svg>'
           . '<span>Colapsar todo</span>'
           . '</button>';
        echo '</div>';

        // Acordeones por bloque con saberes.
        $items_by_block = [];
        foreach ($diana_items as $di) {
            $bid = $di['block'];
            if (!isset($items_by_block[$bid])) {
                $items_by_block[$bid] = ['name' => $di['block_name'], 'fg' => $di['fg'], 'bg' => $di['bg'], 'items' => []];
            }
            $items_by_block[$bid]['items'][] = $di;
        }
        foreach ($items_by_block as $bid => $bdata) {
            echo '<div class="sn-nordic-block-group">';
            echo '<div class="sn-nordic-block-header" onclick="this.classList.toggle(\'collapsed\')">';
            echo '<div class="sn-nordic-block-header-dot" style="background:' . $bdata['fg'] . '"></div>';
            echo '<span class="sn-nordic-block-header-label" style="color:' . $bdata['fg'] . '">'
               . s($bid) . '. ' . s($bdata['name']) . '</span>';
            echo '<span class="sn-nordic-block-header-arrow">&#9660;</span>';
            echo '</div>';
            echo '<div class="sn-nordic-block-body" style="max-height:2000px">';
            echo '<table class="sn-nordic-table">';
            echo '<thead><tr><th>Saber</th><th>Descripción</th><th>Nota</th><th>Estado</th><th>Subs</th></tr></thead>';
            echo '<tbody>';
            foreach ($bdata['items'] as $di) {
                $total_subs = count($di['subs']);
                $graded_subs = count($di['sub_grades']);
                $status_cls = 'pending';
                $status_text = 'Pendiente';
                $avg_grade = $cnt_activity_avgs[$di['code']] ?? null;
                if ($graded_subs > 0) {
                    $all_worked = true;
                    foreach ($di['sub_grades'] as $sg) {
                        if ($sg['scale_val'] < 3) { $all_worked = false; break; }
                    }
                    if ($all_worked && $graded_subs === $total_subs) {
                        $status_cls = 'worked';
                        $status_text = 'Trabajado';
                    } else {
                        $status_cls = 'process';
                        $status_text = 'En proceso';
                    }
                }
                $legal_esc = s($di['legal']);
                echo '<tr class="sn-table-row-tip" data-legal="' . $legal_esc . '" data-code="' . s($di['code']) . '" data-fg="' . $di['fg'] . '" data-bg="' . $di['bg'] . '" data-status="' . $status_text . '">';
                echo '<td class="sn-tip-zone" style="cursor:help"><span class="sn-cnt-code-pill" style="background:' . $di['bg'] . ';color:' . $di['fg'] . '">' . s($di['code']) . '</span></td>';
                echo '<td class="sn-tip-zone" style="font-size:.78rem;color:#475569;max-width:320px;cursor:help">' . s(mb_strimwidth($di['legal'], 0, 120, "\xe2\x80\xa6")) . '</td>';
                if ($avg_grade !== null) {
                    $pct = $avg_grade / 10;
                    $hue = round($pct * 120);
                    echo '<td><span style="display:inline-block;padding:2px 10px;border-radius:6px;font-weight:700;font-size:.78rem;'
                       . 'background:hsl(' . $hue . ',72%,94%);color:hsl(' . $hue . ',55%,38%)">'
                       . number_format($avg_grade, 1) . '</span></td>';
                } else {
                    echo '<td style="color:#CBD5E1;font-size:.75rem">—</td>';
                }
                echo '<td><span class="sn-cnt-status sn-cnt-status--' . $status_cls . '">' . $status_text . '</span></td>';
                echo '<td style="color:#94A3B8">' . $graded_subs . '/' . $total_subs . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
            echo '</div>'; // .sn-nordic-block-body
            echo '</div>'; // .sn-nordic-block-group
        }
        // Tooltip flotante para filas de la tabla (positioned relative to #sentinel-pane-contenidos).
        echo '<div class="sn-table-tooltip" id="sn-table-tooltip" style="position:fixed">'
           . '<div class="sn-nordic-tooltip-header">'
           . '<span class="sn-nordic-tooltip-pill" id="sn-tbl-pill"></span>'
           . '<span class="sn-nordic-tooltip-block" id="sn-tbl-status"></span>'
           . '</div>'
           . '<div class="sn-nordic-tooltip-legal" id="sn-tbl-legal"></div>'
           . '</div>';
        echo '<script>'
           . '(function(){'
           . 'var tip=document.getElementById("sn-table-tooltip");'
           . 'var pill=document.getElementById("sn-tbl-pill");'
           . 'var status=document.getElementById("sn-tbl-status");'
           . 'var legal=document.getElementById("sn-tbl-legal");'
           . 'document.querySelectorAll(".sn-tip-zone").forEach(function(cell){'
           . 'var row=cell.closest(".sn-table-row-tip");if(!row)return;'
           . 'cell.addEventListener("mouseenter",function(e){'
           . 'pill.textContent=row.dataset.code;pill.style.background=row.dataset.bg;pill.style.color=row.dataset.fg;'
           . 'status.textContent=row.dataset.status;'
           . 'legal.textContent=row.dataset.legal;'
           . 'tip.classList.add("visible");});'
           . 'cell.addEventListener("mousemove",function(e){'
           . 'var left=e.clientX+12;'
           . 'var top=e.clientY-10;'
           . 'if(left+300>window.innerWidth)left=e.clientX-310;'
           . 'tip.style.left=left+"px";tip.style.top=top+"px";});'
           . 'cell.addEventListener("mouseleave",function(){tip.classList.remove("visible");});'
           . '});})();'
           . '</script>';

        // ── JavaScript: renderizar la diana SVG ──
        echo '<script>'
           . 'window._sentinelNordicData=' . $diana_js_data . ';'
           . 'window.sentinelDrawNordicDiana=function(){'
           . 'if(window._nordicDrawn)return;'
           . 'var D=window._sentinelNordicData;'
           . 'var c=document.getElementById("sn-nordic-diana");'
           . 'if(!c||!D.nItems)return;'
           . 'var dpr=window.devicePixelRatio||2;'
           . 'var rect=c.parentElement.getBoundingClientRect();'
           . 'var W=Math.min(700,Math.floor(rect.width));'
           . 'if(W<10)return;'
           . 'c.width=W*dpr;c.height=W*dpr;'
           . 'c.style.width=W+"px";c.style.height=W+"px";'
           . 'var ctx=c.getContext("2d");ctx.scale(dpr,dpr);'
           . 'ctx.imageSmoothingEnabled=true;ctx.imageSmoothingQuality="high";'
           . 'var margin=56;'
           . 'var cx=W/2,cy=W/2,outerR=W/2-margin,innerR=outerR*0.38;'
           . 'var TAU=Math.PI*2;'

           // Ordenar bloques: A,B,C,D... con T al final.
           . 'D.blocks.sort(function(a,b){if(a.id==="T")return 1;if(b.id==="T")return -1;return a.id<b.id?-1:a.id>b.id?1:0;});'

           // Calcular ángulos por bloque (proporcional al nº de items).
           . 'var blockAngles=[];var startA=-Math.PI/2;'
           . 'D.blocks.forEach(function(b){'
           . 'var span=TAU*b.itemCount/D.nItems;'
           . 'blockAngles.push({id:b.id,start:startA,end:startA+span,bg:b.bg,fg:b.fg,name:b.name,n:b.itemCount});'
           . 'startA+=span;});'

           // Fondo blanco completo.
           . 'ctx.beginPath();ctx.arc(cx,cy,outerR,0,TAU);'
           . 'ctx.fillStyle="#fff";ctx.fill();'

           // Sectores de color solo en la corona (entre innerR y outerR).
           . 'blockAngles.forEach(function(ba){'
           . 'ctx.beginPath();ctx.arc(cx,cy,outerR,ba.start,ba.end);'
           . 'ctx.arc(cx,cy,innerR,ba.end,ba.start,true);ctx.closePath();'
           . 'ctx.fillStyle=ba.bg;ctx.fill();});'

           // Círculo blanco central (zona de ghosts).
           . 'ctx.beginPath();ctx.arc(cx,cy,innerR,0,TAU);'
           . 'ctx.fillStyle="#fff";ctx.fill();'
           . 'ctx.strokeStyle="#E2E8F0";ctx.lineWidth=1;ctx.stroke();'

           // Anillos concéntricos (escala 2,4,6,8,10 mapeados entre innerR y outerR).
           . 'var scaleR=function(v){return innerR+(outerR-innerR)*v/10;};'
           . '[2,4,6,8,10].forEach(function(v){'
           . 'var r=scaleR(v);ctx.beginPath();ctx.arc(cx,cy,r,0,TAU);'
           . 'ctx.strokeStyle="rgba(100,116,139,.12)";ctx.lineWidth=.5;ctx.stroke();});'

           // Números de escala en eje diagonal (45°).
           . 'ctx.fillStyle="#94A3B8";ctx.font="600 9px system-ui,sans-serif";ctx.textAlign="center";ctx.textBaseline="middle";'
           . '[2,4,6,8,10].forEach(function(v){'
           . 'var r=scaleR(v);var a=-Math.PI/4;'
           . 'var x=cx+r*Math.cos(a),y=cy+r*Math.sin(a);'
           . 'ctx.fillText(v,x,y);});'

           // Líneas divisorias entre bloques.
           . 'ctx.strokeStyle="#CBD5E1";ctx.lineWidth=1;'
           . 'blockAngles.forEach(function(ba){'
           . 'var x1=cx+innerR*Math.cos(ba.start),y1=cy+innerR*Math.sin(ba.start);'
           . 'var x2=cx+outerR*Math.cos(ba.start),y2=cy+outerR*Math.sin(ba.start);'
           . 'ctx.beginPath();ctx.moveTo(x1,y1);ctx.lineTo(x2,y2);ctx.stroke();});'

           // Líneas divisorias entre items (finas).
           . 'ctx.strokeStyle="rgba(100,116,139,.08)";ctx.lineWidth=.5;'
           . 'var itemA=-Math.PI/2;var itemStep=TAU/D.nItems;'
           . 'for(var i=0;i<D.nItems;i++){'
           . 'var a=itemA+i*itemStep;'
           . 'var x=cx+outerR*Math.cos(a),y=cy+outerR*Math.sin(a);'
           . 'ctx.beginPath();ctx.moveTo(cx+innerR*Math.cos(a),cy+innerR*Math.sin(a));ctx.lineTo(x,y);ctx.stroke();}'

           // Helper: lighten a hex color by mixing with white.
           . 'function hexToRgb(h){h=h.replace("#","");return[parseInt(h.substring(0,2),16),parseInt(h.substring(2,4),16),parseInt(h.substring(4,6),16)];}'
           . 'function rgbToHex(r,g,b){return"#"+[r,g,b].map(function(v){var s=Math.round(v).toString(16);return s.length<2?"0"+s:s;}).join("");}'
           . 'function lighten(hex,amt){var c=hexToRgb(hex);return rgbToHex(c[0]+(255-c[0])*amt,c[1]+(255-c[1])*amt,c[2]+(255-c[2])*amt);}'

           // Preparar posiciones de ghost por bloque: distribuir en el sector del centro.
           . 'var ghostPositions=[];'
           . 'var allSubs=[];'
           . 'D.items.forEach(function(it){it.subs.forEach(function(sub){allSubs.push({item:it,sub:sub});});});'

           // Agrupar subs por bloque para distribuirlos en su quesito del centro.
           . 'var subsByBlock={};'
           . 'allSubs.forEach(function(s){var b=s.item.block;if(!subsByBlock[b])subsByBlock[b]=[];subsByBlock[b].push(s);});'

           // Distribución polar uniforme en cada sector del centro.
           . 'var rMin=innerR*0.22,rMax=innerR*0.88;'
           . 'blockAngles.forEach(function(ba){'
           . 'var bSubs=subsByBlock[ba.id]||[];var nS=bSubs.length;if(!nS)return;'
           . 'var aSpan=ba.end-ba.start;'
           . 'var pad=aSpan*0.1;'
           . 'var aS=ba.start+pad,aE=ba.end-pad,uA=aE-aS;'

           // Calcular nº de filas óptimo basándose en la densidad del sector.
           . 'var arcAtMid=uA*(rMin+rMax)/2;'
           . 'var dotD=18;'
           . 'var cols=Math.max(1,Math.round(arcAtMid/dotD));'
           . 'var nRows=Math.max(1,Math.ceil(nS/cols));'
           . 'if(nRows>4)nRows=4;'
           . 'cols=Math.ceil(nS/nRows);'

           // Adaptar rango radial al nº de filas para evitar dispersión.
           . 'var rMid=(rMin+rMax)/2;'
           . 'var halfSp=(rMax-rMin)/2*Math.min(1,nRows/4);'
           . 'var uRMin=rMid-halfSp,uRMax=rMid+halfSp;'

           // Distribuir uniformemente: filas iguales, última con el resto.
           . 'var si=0;'
           . 'for(var ri=0;ri<nRows;ri++){'
           . 'var inRow=Math.min(cols,nS-si);'
           . 'var r=nRows===1?rMid:uRMin+(uRMax-uRMin)*ri/(nRows-1);'
           // Centrar los dots de esta fila en el arco.
           . 'var rowSpan=inRow>1?uA*(inRow-1)/Math.max(inRow,cols):0;'
           . 'var rowStart=aS+(uA-rowSpan)/2;'
           . 'for(var j=0;j<inRow;j++){'
           . 'var a=inRow===1?(aS+uA/2):rowStart+rowSpan*j/(inRow-1);'
           . 'var s=bSubs[si++];'
           . 'ghostPositions.push({x:cx+r*Math.cos(a),y:cy+r*Math.sin(a),sub:s.sub,item:s.item,a:a,r:r});'
           . '}}'
           . '});'

           // Dibujar ghosts y calificados.
           . 'ghostPositions.forEach(function(gp){'
           . 'var it=gp.item;var sub=gp.sub;var sc=sub.c;'
           . 'var sa=it.sub_acts?it.sub_acts[sc]:null;'
           . 'var sg=it.sub_grades[sc];'
           . 'var lightBg=lighten(it.fg,0.85);'
           . 'if(!sa&&!sg){'
           // Ghost: en su sector del centro.
           . 'ctx.beginPath();ctx.arc(gp.x,gp.y,7,0,TAU);'
           . 'ctx.fillStyle=lightBg;ctx.fill();'
           . 'ctx.strokeStyle=lighten(it.fg,0.55);ctx.lineWidth=.6;ctx.stroke();'
           . 'ctx.fillStyle=it.fg;ctx.globalAlpha=0.3;ctx.font="bold 5.5px system-ui,sans-serif";ctx.textAlign="center";ctx.textBaseline="middle";'
           . 'ctx.fillText(sc,gp.x,gp.y);ctx.globalAlpha=1;'
           . 'return;}'
           // Calificado: radio según nota real de actividades, tamaño según nº ocasiones.
           . 'var grade=sa?sa.avg:(sg?sg.grade:0);'
           . 'var nOcc=sa?sa.count:1;'
           . 'var r=scaleR(grade);'
           . 'var dotR=Math.min(14,Math.max(5,4+nOcc*2));'
           . 'var px=cx+r*Math.cos(gp.a),py=cy+r*Math.sin(gp.a);'
           . 'gp.px=px;gp.py=py;gp.dotR=dotR;gp.graded=true;gp.nOcc=nOcc;gp.actGrade=grade;'
           . 'ctx.beginPath();ctx.arc(px,py,dotR,0,TAU);'
           . 'ctx.fillStyle=it.fg;ctx.fill();'
           . 'ctx.beginPath();ctx.arc(px,py,Math.max(2,dotR*0.3),0,TAU);'
           . 'ctx.fillStyle="#fff";ctx.fill();'
           . '});'

           // Etiquetas de bloque: horizontales, sin rotación, posición inteligente.
           . 'var blockLabelPositions=[];'
           . 'var labelR=outerR+22;'
           . 'var wrap=c.parentElement;'
           . 'var cOffX=c.offsetLeft,cOffY=c.offsetTop;'
           . 'wrap.querySelectorAll(".sn-blk-label").forEach(function(el){el.remove();});'
           . 'blockAngles.forEach(function(ba){'
           . 'var midA=(ba.start+ba.end)/2;'
           . 'var cosA=Math.cos(midA),sinA=Math.sin(midA);'
           . 'var lx=cOffX+cx+labelR*cosA,ly=cOffY+cy+labelR*sinA;'
           . 'var el=document.createElement("span");el.className="sn-blk-label";'
           . 'el.textContent=ba.id;'
           . 'el.dataset.bid=ba.id;el.dataset.bname=ba.name;el.dataset.fg=ba.fg;el.dataset.bg=ba.bg;'
           . 'var tx=cosA>0.3?"4px":cosA<-0.3?"calc(-100% - 4px)":"-50%";'
           . 'var ty=sinA>0.3?"4px":sinA<-0.3?"calc(-100% - 4px)":"-50%";'
           . 'el.style.cssText="position:absolute;font-weight:700;font-size:.62rem;"'
           . '+"font-family:system-ui,-apple-system,sans-serif;"'
           . '+"color:"+ba.fg+";background:"+ba.bg+";"'
           . '+"padding:3px 7px;border-radius:5px;line-height:1;"'
           . '+"letter-spacing:.1em;text-transform:uppercase;white-space:nowrap;"'
           . '+"pointer-events:auto;cursor:help;"'
           . '+"left:"+lx+"px;top:"+ly+"px;"'
           . '+"transform:translate("+tx+","+ty+")";'
           . 'wrap.appendChild(el);'
           . 'blockLabelPositions.push({x:lx,y:ly,w:40,h:14,id:ba.id,name:ba.name});'
           . '});'

           // ── Tooltip interactivo (tarjeta sofisticada) ──
           . 'var tooltip=document.getElementById("sn-nordic-tooltip");'
           . 'var ttPill=document.getElementById("sn-tt-pill");'
           . 'var ttBlock=document.getElementById("sn-tt-block");'
           . 'var ttLegal=document.getElementById("sn-tt-legal");'
           . 'var ttGrade=document.getElementById("sn-tt-grade");'

           . 'c.addEventListener("mousemove",function(e){'
           . 'var br=c.getBoundingClientRect();'
           . 'var mx=(e.clientX-br.left)*(c.width/dpr/br.width);'
           . 'var my=(e.clientY-br.top)*(c.height/dpr/br.height);'
           . 'var found=null;'

           // Hit-test ghost/graded dots.
           . 'ghostPositions.forEach(function(gp){'
           . 'if(found)return;'
           . 'var it=gp.item;var sc=gp.sub.c;'
           . 'var sa=it.sub_acts?it.sub_acts[sc]:null;'
           . 'var sg=it.sub_grades[sc];'
           . 'if(!sa&&!sg){'
           . 'if(Math.hypot(mx-gp.x,my-gp.y)<9){found={code:sc,legal:gp.sub.d||it.legal,grade:null,nOcc:0,block:it.block_name,bg:it.bg,fg:it.fg};}'
           . '}else if(gp.graded){'
           . 'if(Math.hypot(mx-gp.px,my-gp.py)<(gp.dotR+2)){found={code:sc,legal:gp.sub.d||it.legal,grade:gp.actGrade,nOcc:gp.nOcc,block:it.block_name,bg:it.bg,fg:it.fg};}'
           . '}});'

           . 'if(found){'
           . 'ttPill.textContent=found.code;ttPill.style.background=found.bg;ttPill.style.color=found.fg;'
           . 'ttBlock.textContent="Bloque "+found.code.charAt(0)+" · "+found.block;'
           . 'ttLegal.textContent=found.legal;'
           . 'if(found.grade!==null){'
           . 'var pct=found.grade/10;var hue=Math.round(pct*120);'
           . 'var svColor="hsl("+hue+",55%,38%)";'
           . 'var occText=found.nOcc===1?"1 ocasión":found.nOcc+" ocasiones";'
           . 'ttGrade.innerHTML="<span class=\"sn-nordic-tooltip-grade-dot\" style=\"background:"+svColor+"\"></span>"+found.grade.toFixed(1)+" &mdash; "+occText;'
           . 'ttGrade.style.color=svColor;'
           . '}else{ttGrade.innerHTML="<span class=\"sn-nordic-tooltip-grade-dot\" style=\"background:#CBD5E1\"></span>Pendiente de trabajar";ttGrade.style.color="#94A3B8";}'
           . 'var tipLeft=cOffX+Math.min(e.clientX-br.left+14,br.width-220);'
           . 'var tipTop=cOffY+e.clientY-br.top-80;if(tipTop<cOffY)tipTop=cOffY+e.clientY-br.top+16;'
           . 'tooltip.style.left=tipLeft+"px";tooltip.style.top=tipTop+"px";'
           . 'tooltip.classList.add("visible");c.style.cursor="pointer";'
           . '}else{tooltip.classList.remove("visible");c.style.cursor="default";}});'
           . 'c.addEventListener("mouseleave",function(){tooltip.classList.remove("visible");});'

           // Tooltip para etiquetas de bloque (HTML labels alrededor del diagrama).
           . 'wrap.querySelectorAll(".sn-blk-label").forEach(function(lbl){'
           . 'lbl.addEventListener("mouseenter",function(){'
           . 'ttPill.textContent=lbl.dataset.bid;'
           . 'ttPill.style.background=lbl.dataset.bg;ttPill.style.color=lbl.dataset.fg;'
           . 'ttBlock.textContent="Bloque "+lbl.dataset.bid;'
           . 'ttLegal.textContent=lbl.dataset.bname;'
           . 'ttGrade.innerHTML="";'
           . 'var r=lbl.getBoundingClientRect(),wr=wrap.getBoundingClientRect();'
           . 'var tl=r.left-wr.left+r.width/2-60;'
           . 'var tt=r.bottom-wr.top+8;'
           . 'if(tl<4)tl=4;if(tl+200>wr.width)tl=wr.width-200;'
           . 'tooltip.style.left=tl+"px";tooltip.style.top=tt+"px";'
           . 'tooltip.classList.add("visible");});'
           . 'lbl.addEventListener("mouseleave",function(){tooltip.classList.remove("visible");});'
           . '});'

           . 'window._nordicDrawn=true;'
           . '};'

           // Dynamic period switching: filter grades by cutoff and redraw dots.
           . 'window.sentinelSwitchCntPeriod=function(cutoff){'
           . 'var D=window._sentinelNordicData;if(!D)return;'
           . 'var rg=D.rawGrades||[];'
           . 'var filtered=cutoff>0?rg.filter(function(g){return g.t<=cutoff;}):rg;'
           . 'var subMap={};'
           . 'filtered.forEach(function(g){'
           . 'if(!subMap[g.s])subMap[g.s]={};'
           . 'subMap[g.s][g.gi]=g.g;'
           . '});'
           . 'var subActs={};'
           . 'Object.keys(subMap).forEach(function(sc){'
           . 'var vals=[];for(var k in subMap[sc])vals.push(subMap[sc][k]);'
           . 'subActs[sc]={avg:Math.round(vals.reduce(function(a,b){return a+b;},0)/vals.length*10)/10,count:vals.length};'
           . '});'
           . 'D.items.forEach(function(it){'
           . 'var sa={};it.subs.forEach(function(sub){if(subActs[sub.c])sa[sub.c]=subActs[sub.c];});'
           . 'it.sub_acts=sa;'
           . '});'
           // Also compute per-item averages for table badges.
           . 'var itemMap={};'
           . 'filtered.forEach(function(g){'
           . 'if(!itemMap[g.i])itemMap[g.i]={};'
           . 'itemMap[g.i][g.gi]=g.g;'
           . '});'
           . 'var itemAvgs={};'
           . 'Object.keys(itemMap).forEach(function(ic){'
           . 'var vals=[];for(var k in itemMap[ic])vals.push(itemMap[ic][k]);'
           . 'itemAvgs[ic]=Math.round(vals.reduce(function(a,b){return a+b;},0)/vals.length*10)/10;'
           . '});'
           // Update table nota badges.
           . 'document.querySelectorAll("#sentinel-pane-contenidos .sn-table-row-tip").forEach(function(row){'
           . 'var code=row.dataset.code;var td=row.querySelectorAll("td")[2];if(!td)return;'
           . 'var avg=itemAvgs[code];'
           . 'if(avg!==undefined){'
           . 'var pct=avg/10;var hue=Math.round(pct*120);'
           . 'td.innerHTML="<span style=\"display:inline-block;padding:2px 10px;border-radius:6px;font-weight:700;font-size:.78rem;'
           . 'background:hsl("+hue+",72%,94%);color:hsl("+hue+",55%,38%)\">"+avg.toFixed(1)+"</span>";'
           . '}else{td.innerHTML="<span style=\"color:#CBD5E1;font-size:.75rem\">&mdash;</span>";}'
           . '});'
           . 'window._nordicDrawn=false;'
           . 'if(window.sentinelDrawNordicDiana)window.sentinelDrawNordicDiana();'
           . 'document.querySelectorAll(".sn-nordic-hist-list").forEach(function(list){'
           . 'list.querySelectorAll(".sn-nordic-hist-item").forEach(function(el){'
           . 'el.classList.toggle("active",parseInt(el.dataset.cutoff||"0")===cutoff);'
           . '});});'
           . 'window._sentinelActiveCutoff=cutoff;'
           . '};'
           . '</script>';
    }

    echo '</div>'; // #sentinel-pane-contenidos
}

// ── PANE DUA: Panel del Aprendiz Experto ────────────────────────────────────
if ($has_subject_tab) {
    // Teacher can edit DUA data?
    $dua_can_edit = false;
    if (!$iscurrentuser) {
        $dua_ctx = context_course::instance($selected_courseid, IGNORE_MISSING);
        $dua_can_edit = $dua_ctx && has_capability('local/sentinel:viewstudentreport', $dua_ctx);
    }

    echo '<div id="sentinel-pane-dua" class="sn-view-pane">';

    // ── Gather criteria data for strengths/challenges ──
    $dua_all_crit = [];
    if ($ce_data && !empty($ce_data['ces'])) {
        foreach ($ce_data['ces'] as $ceIdx => $ce) {
            $ce_short = 'CE' . ($ceIdx + 1);
            if (preg_match('/^(CE\s*\d+)/i', $ce['name'], $cm)) {
                $ce_short = $cm[1];
            }
            foreach ($ce['criteria'] as $ci => $crit) {
                $dua_all_crit[] = [
                    'ce_idx'     => $ceIdx,
                    'ce_short'   => $ce_short,
                    'ce_color'   => $ce['color'],
                    'crit_idx'   => $ci,
                    'name'       => $crit['name'],
                    'grade'      => $crit['grade'],
                    'activities' => $crit['activities'],
                ];
            }
        }
    }

    // Sort by grade to find strengths (top) and challenges (bottom).
    $dua_worked = array_filter($dua_all_crit, fn($c) => $c['grade'] !== null);
    usort($dua_worked, fn($a, $b) => $b['grade'] <=> $a['grade']);
    $dua_strengths  = array_slice($dua_worked, 0, min(2, count($dua_worked)));
    $dua_challenges = array_slice(array_reverse($dua_worked), 0, min(2, count($dua_worked)));
    // Avoid duplicates if ≤2 criteria.
    if (count($dua_worked) <= 2) { $dua_challenges = []; }

    // Build sparkline: chronological running average across ALL criteria activities.
    $dua_events = [];
    foreach ($dua_all_crit as $c) {
        foreach ($c['activities'] as $act) {
            if (!empty($act['gradetime'])) {
                $dua_events[] = [
                    'time'  => $act['gradetime'],
                    'grade' => $act['grade'],
                    'name'  => $act['activityname'] ?? '',
                    'crit'  => $c['name'],
                ];
            }
        }
    }
    usort($dua_events, fn($a, $b) => $a['time'] <=> $b['time']);
    // Group events into grading occasions (within 60s window) so that
    // multiple criteria graded at once produce a single sparkline point.
    $dua_sparkline_pts = [];
    $dua_running_sum = 0;
    $dua_running_n   = 0;
    $group_start = null;
    foreach ($dua_events as $ev) {
        $dua_running_n++;
        $dua_running_sum += $ev['grade'];
        $cur_time = (int)$ev['time'];
        $same_group = ($group_start !== null && ($cur_time - $group_start) < 60);
        if ($same_group && !empty($dua_sparkline_pts)) {
            $dua_sparkline_pts[count($dua_sparkline_pts) - 1]['avg'] = round($dua_running_sum / $dua_running_n, 2);
        } else {
            $group_start = $cur_time;
            $dua_sparkline_pts[] = [
                'time'  => $cur_time,
                'avg'   => round($dua_running_sum / $dua_running_n, 2),
                'name'  => $ev['name'],
                'crit'  => $ev['crit'],
                'grade' => $ev['grade'],
            ];
        }
    }

    // ── CSS ──
    echo '<style>
.sn-dua-wrap { max-width: 900px; margin: 0 auto; padding: 0 8px; }
.sn-dua-header {
    background: linear-gradient(135deg, #ede9fe 0%, #e0f2fe 50%, #ecfdf5 100%);
    border-radius: 16px; padding: 24px 28px; margin-bottom: 20px;
    border: 1px solid #c7d2fe;
}
.sn-dua-hello {
    font-size: 1.1em; font-weight: 700; color: #1e293b; margin-bottom: 4px;
}
.sn-dua-sub { font-size: .82em; color: #64748b; margin-bottom: 16px; }
.sn-dua-spark-wrap {
    position: relative; background: #fff; border-radius: 12px;
    padding: 14px 16px 10px; border: 1px solid #e2e8f0;
    box-shadow: 0 1px 4px rgba(0,0,0,.04);
}
.sn-dua-spark-wrap svg { display: block; width: 100%; height: auto; }
.sn-dua-spark-tip {
    display: none; position: absolute; z-index: 50; pointer-events: none;
    background: #fff; border: 1px solid #e2e5ea; border-radius: 10px;
    box-shadow: 0 6px 20px rgba(0,0,0,.12); padding: 10px 14px;
    min-width: 200px; max-width: 320px; font-size: .78rem; line-height: 1.45;
}
.sn-dua-spark-tip-date { font-weight: 700; color: #5b21b6; margin-bottom: 2px; }
.sn-dua-spark-tip-name { color: #4b5563; margin-bottom: 4px; }
.sn-dua-spark-tip-grade { font-weight: 800; font-variant-numeric: tabular-nums; }

.sn-dua-cards {
    display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 20px;
}
@media (max-width: 680px) { .sn-dua-cards { grid-template-columns: 1fr; } }
.sn-dua-col-head {
    display: flex; align-items: center; gap: 8px; margin-bottom: 10px;
    font-weight: 700; font-size: .92em;
}
.sn-dua-card {
    padding: 14px 16px; border-radius: 12px; border: 1px solid;
    transition: box-shadow .2s;
}
.sn-dua-card:hover { box-shadow: 0 2px 12px rgba(0,0,0,.06); }
.sn-dua-card--strength {
    background: #f0fdf4; border-color: #bbf7d0;
}
.sn-dua-card--challenge {
    background: #faf5ff; border-color: #e9d5ff;
}
.sn-dua-card-ce {
    display: inline-block; padding: 2px 8px; border-radius: 4px;
    font-size: .72rem; font-weight: 700; color: #fff; margin-bottom: 6px;
}
.sn-dua-card-name {
    font-size: .82em; font-weight: 600; color: #1e293b; margin-bottom: 4px; line-height: 1.4;
}
.sn-dua-card-context {
    font-size: .74em; color: #6b7280; margin-bottom: 6px;
}
.sn-dua-card-context a { color: #5b21b6; text-decoration: none; }
.sn-dua-card-context a:hover { text-decoration: underline; }
.sn-dua-card-grade {
    font-weight: 800; font-variant-numeric: tabular-nums; font-size: .95em;
}
.sn-dua-card-pauta {
    margin-top: 8px; padding: 8px 12px; border-radius: 8px;
    background: rgba(91,33,182,.06); border-left: 3px solid #7c3aed;
    font-size: .76em; color: #4b5563; line-height: 1.5; font-style: italic;
}
.sn-dua-card-pauta-label {
    font-weight: 700; font-style: normal; color: #5b21b6; margin-bottom: 2px;
}
.sn-dua-section-title {
    font-size: .78em; font-weight: 700; text-transform: uppercase;
    letter-spacing: .08em; color: #94a3b8; margin: 20px 0 12px;
}
.sn-dua-empty {
    text-align: center; padding: 40px 20px; color: #9ca3af;
    font-size: .88em;
}
/* Bitácora */
.sn-dua-bitacora { margin-bottom: 20px; }
.sn-dua-bitacora-form {
    display: flex; gap: 10px; align-items: flex-start; margin-bottom: 14px;
}
.sn-dua-textarea {
    flex: 1; min-height: 56px; padding: 10px 14px; border-radius: 10px;
    border: 1.5px solid #e2e8f0; font-family: inherit; font-size: .84em;
    resize: vertical; transition: border-color .2s; line-height: 1.5;
}
.sn-dua-textarea:focus { outline: none; border-color: #7c3aed; box-shadow: 0 0 0 3px #ede9fe; }
.sn-dua-btn {
    padding: 8px 18px; border-radius: 8px; border: none; cursor: pointer;
    font-weight: 600; font-size: .82em; transition: background .15s, transform .1s;
}
.sn-dua-btn:active { transform: scale(.96); }
.sn-dua-btn--primary { background: #7c3aed; color: #fff; }
.sn-dua-btn--primary:hover { background: #6d28d9; }
.sn-dua-btn--sm {
    padding: 5px 12px; font-size: .78em; border-radius: 6px;
    background: #ede9fe; color: #7c3aed; border: 1px solid #c4b5fd;
}
.sn-dua-btn--sm:hover { background: #ddd6fe; }
.sn-dua-btn--danger { background: none; color: #ef4444; border: none; padding: 2px 6px; font-size: .75em; }
.sn-dua-btn--danger:hover { color: #dc2626; }
.sn-dua-notes-list { display: flex; flex-direction: column; gap: 8px; }
.sn-dua-note {
    padding: 12px 16px; border-radius: 10px; background: #faf5ff;
    border: 1px solid #ede9fe; position: relative;
}
.sn-dua-note-meta {
    display: flex; justify-content: space-between; align-items: center;
    font-size: .72em; color: #8b5cf6; font-weight: 600; margin-bottom: 4px;
}
.sn-dua-note-content { font-size: .84em; color: #374151; line-height: 1.55; white-space: pre-wrap; }
.sn-dua-note-actions { display: flex; gap: 4px; }
/* Motivación */
.sn-dua-motiv { margin-bottom: 20px; }
.sn-dua-motiv-row { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
@media (max-width: 680px) { .sn-dua-motiv-row { grid-template-columns: 1fr; } }
.sn-dua-motiv-col {
    padding: 16px; border-radius: 12px; background: #fff;
    border: 1px solid #e5e7eb; box-shadow: 0 1px 4px rgba(0,0,0,.04);
}
.sn-dua-motiv-head {
    font-size: .82em; font-weight: 700; margin-bottom: 10px;
}
.sn-dua-chips { display: flex; flex-wrap: wrap; gap: 6px; min-height: 28px; margin-bottom: 10px; }
.sn-dua-chip {
    display: inline-flex; align-items: center; gap: 4px;
    padding: 4px 12px; border-radius: 16px; font-size: .76em; font-weight: 600;
    transition: background .15s;
}
.sn-dua-chip--interest { background: #ccfbf1; color: #0d9488; border: 1px solid #99f6e4; }
.sn-dua-chip--hook { background: #ede9fe; color: #7c3aed; border: 1px solid #c4b5fd; }
.sn-dua-chip-x {
    cursor: pointer; font-size: .85em; opacity: .5; margin-left: 2px;
    transition: opacity .15s;
}
.sn-dua-chip-x:hover { opacity: 1; }
.sn-dua-chip-add {
    display: flex; gap: 6px; align-items: center;
}
.sn-dua-chip-input {
    flex: 1; padding: 5px 10px; border-radius: 8px;
    border: 1.5px solid #e2e8f0; font-size: .8em; font-family: inherit;
}
.sn-dua-chip-input:focus { outline: none; border-color: #7c3aed; }
/* Adaptaciones — Nuevo diseño con histórico */
.sn-adapt { margin-bottom: 20px; }
.sn-adapt-add-btn {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 10px 20px; border-radius: 12px; border: 2px dashed #cbd5e1;
    background: #f8fafc; color: #475569; font-size: .88em; font-weight: 600;
    cursor: pointer; transition: all .2s; width: 100%; justify-content: center;
}
.sn-adapt-add-btn:hover { border-color: #7c3aed; color: #7c3aed; background: #faf5ff; }
/* Modal overlay */
.sn-adapt-modal-bg {
    display: none; position: fixed; inset: 0; z-index: 10000;
    background: rgba(0,0,0,.45); backdrop-filter: blur(3px);
    align-items: center; justify-content: center;
}
.sn-adapt-modal-bg.active { display: flex; }
.sn-adapt-modal {
    background: #fff; border-radius: 16px; width: 600px; max-width: 94vw;
    max-height: 85vh; overflow-y: auto; box-shadow: 0 20px 60px rgba(0,0,0,.2);
    animation: snAdaptSlideIn .25s ease-out;
}
@keyframes snAdaptSlideIn {
    from { opacity: 0; transform: translateY(20px) scale(.97); }
    to { opacity: 1; transform: translateY(0) scale(1); }
}
.sn-adapt-modal-head {
    display: flex; align-items: center; justify-content: space-between;
    padding: 18px 22px; border-bottom: 1px solid #e5e7eb;
}
.sn-adapt-modal-title { font-weight: 700; font-size: 1.05em; color: #1e293b; }
.sn-adapt-modal-close {
    background: none; border: none; font-size: 1.4em; color: #94a3b8;
    cursor: pointer; padding: 4px; line-height: 1; border-radius: 6px;
}
.sn-adapt-modal-close:hover { background: #f1f5f9; color: #475569; }
.sn-adapt-modal-body { padding: 20px 22px; }
/* Step 1: Type chooser */
.sn-adapt-types { display: flex; gap: 12px; }
.sn-adapt-type-card {
    flex: 1; padding: 20px 16px; border-radius: 14px; border: 2px solid #e5e7eb;
    background: #fff; cursor: pointer; text-align: center; transition: all .2s;
}
.sn-adapt-type-card:hover { border-color: #a78bfa; background: #faf5ff; transform: translateY(-2px); box-shadow: 0 4px 16px rgba(124,58,237,.12); }
.sn-adapt-type-icon { font-size: 2em; margin-bottom: 8px; }
.sn-adapt-type-label { font-weight: 700; font-size: .92em; color: #1e293b; margin-bottom: 4px; }
.sn-adapt-type-desc { font-size: .75em; color: #64748b; line-height: 1.4; }
/* Step 2: Forms */
.sn-adapt-form { display: none; }
.sn-adapt-form.active { display: block; }
.sn-adapt-field { margin-bottom: 14px; }
.sn-adapt-label { display: block; font-weight: 600; font-size: .82em; color: #475569; margin-bottom: 5px; }
.sn-adapt-select, .sn-adapt-input, .sn-adapt-textarea {
    width: 100%; padding: 9px 12px; border-radius: 10px; border: 1.5px solid #e2e8f0;
    font-size: .88em; font-family: inherit; background: #fff; color: #1e293b;
    box-sizing: border-box;
}
.sn-adapt-select:focus, .sn-adapt-input:focus, .sn-adapt-textarea:focus {
    outline: none; border-color: #7c3aed; box-shadow: 0 0 0 3px rgba(124,58,237,.12);
}
.sn-adapt-textarea { resize: vertical; min-height: 70px; }
.sn-adapt-side { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
@media (max-width: 600px) { .sn-adapt-side { grid-template-columns: 1fr; } }
.sn-adapt-side-box {
    padding: 12px 14px; border-radius: 10px; font-size: .82em; line-height: 1.5;
}
.sn-adapt-side-box--orig { background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0; }
.sn-adapt-side-box--edit { background: #fff; }
.sn-adapt-side-label { font-weight: 700; font-size: .72em; text-transform: uppercase; letter-spacing: .04em; color: #94a3b8; margin-bottom: 6px; }
.sn-adapt-dates { display: flex; gap: 12px; }
.sn-adapt-dates > div { flex: 1; }
.sn-adapt-catalog-group { display: flex; flex-direction: column; gap: 6px; margin-bottom: 10px; }
.sn-adapt-catalog-item {
    display: flex; align-items: center; gap: 8px; padding: 8px 12px;
    border-radius: 8px; cursor: pointer; transition: background .15s;
    font-size: .84em; color: #374151;
}
.sn-adapt-catalog-item:hover { background: #f1f5f9; }
.sn-adapt-catalog-item input[type=checkbox] { accent-color: #7c3aed; width: 16px; height: 16px; }
.sn-adapt-catalog-subtitle {
    font-weight: 700; font-size: .78em; color: #7c3aed; text-transform: uppercase;
    letter-spacing: .04em; margin: 10px 0 4px; padding-left: 2px;
}
.sn-adapt-modal-foot {
    display: flex; justify-content: flex-end; gap: 10px; padding: 14px 22px;
    border-top: 1px solid #f1f5f9;
}
.sn-adapt-btn-cancel {
    padding: 9px 18px; border-radius: 10px; border: 1.5px solid #e2e8f0;
    background: #fff; color: #64748b; font-weight: 600; font-size: .86em; cursor: pointer;
}
.sn-adapt-btn-cancel:hover { background: #f8fafc; }
.sn-adapt-btn-save {
    padding: 9px 22px; border-radius: 10px; border: none;
    background: #7c3aed; color: #fff; font-weight: 700; font-size: .86em; cursor: pointer;
    transition: background .15s;
}
.sn-adapt-btn-save:hover { background: #6d28d9; }
.sn-adapt-btn-save:disabled { opacity: .5; cursor: not-allowed; }
/* History cards */
.sn-adapt-history { display: flex; flex-direction: column; gap: 10px; margin-top: 14px; }
.sn-adapt-card {
    border-radius: 14px; padding: 14px 16px; background: #fff;
    border: 1.5px solid #e5e7eb; transition: all .2s; position: relative;
}
.sn-adapt-card:hover { box-shadow: 0 4px 14px rgba(0,0,0,.07); }
.sn-adapt-card--acis { border-left: 4px solid #f59e0b; }
.sn-adapt-card--access { border-left: 4px solid #3b82f6; }
.sn-adapt-card--inactive { opacity: .5; }
.sn-adapt-card-head { display: flex; align-items: center; gap: 8px; margin-bottom: 6px; flex-wrap: wrap; }
.sn-adapt-badge {
    display: inline-flex; align-items: center; gap: 3px;
    padding: 2px 10px; border-radius: 6px; font-size: .7em; font-weight: 700;
    text-transform: uppercase; letter-spacing: .03em;
}
.sn-adapt-badge--acis { background: #fef3c7; color: #92400e; }
.sn-adapt-badge--access { background: #dbeafe; color: #1e40af; }
.sn-adapt-badge--standard { background: #e0f2fe; color: #075985; }
.sn-adapt-badge--program { background: #fce7f3; color: #9d174d; }
.sn-adapt-badge--custom { background: #f3e8ff; color: #6b21a8; }
.sn-adapt-card-dates { font-size: .72em; color: #94a3b8; margin-left: auto; }
.sn-adapt-card-measure { font-size: .88em; color: #1e293b; font-weight: 500; }
.sn-adapt-card-criterion { font-size: .78em; color: #64748b; margin-top: 2px; }
.sn-adapt-card-expand {
    font-size: .78em; color: #7c3aed; cursor: pointer; margin-top: 6px;
    font-weight: 600; display: inline-block;
}
.sn-adapt-card-expand:hover { text-decoration: underline; }
.sn-adapt-card-detail {
    display: none; margin-top: 10px; padding-top: 10px; border-top: 1px solid #f1f5f9;
}
.sn-adapt-card-detail.open { display: block; }
.sn-adapt-card-actions {
    position: absolute; top: 12px; right: 14px; display: flex; gap: 4px;
}
.sn-adapt-card-actions button {
    background: none; border: none; cursor: pointer; font-size: .82em; color: #94a3b8;
    padding: 4px 6px; border-radius: 6px;
}
.sn-adapt-card-actions button:hover { background: #fee2e2; color: #dc2626; }
.sn-adapt-card-toggle {
    padding: 4px 6px; border-radius: 6px; cursor: pointer;
    background: none; border: none; font-size: .82em; color: #94a3b8;
}
.sn-adapt-card-toggle:hover { background: #f1f5f9; color: #475569; }
.sn-adapt-empty { text-align: center; padding: 24px; color: #94a3b8; font-size: .86em; }
</style>';

    echo '<div class="sn-dua-wrap">';

    // ══════════════════════════════════════════════════════════════════════════
    // BLOQUE 1: Header de cultura de crecimiento + sparkline
    // ══════════════════════════════════════════════════════════════════════════
    echo '<div class="sn-dua-header">';
    echo '<div class="sn-dua-hello">¡Hola, ' . s($student->firstname) . '! Revisa tu evolución y tus próximos pasos</div>';
    echo '<div class="sn-dua-sub">Panel DUA — ' . s($ce_data['course']->fullname) . '</div>';

    if (!empty($dua_sparkline_pts)) {
        $sp_w = 700; $sp_h = 80; $sp_pad = 20;
        $sp_n = count($dua_sparkline_pts);
        $sp_max = 10;
        $sp_points = [];
        foreach ($dua_sparkline_pts as $si => $sp) {
            $x = $sp_pad + ($si / max($sp_n - 1, 1)) * ($sp_w - 2 * $sp_pad);
            $y = $sp_h - $sp_pad - ($sp['avg'] / $sp_max) * ($sp_h - 2 * $sp_pad);
            $sp_points[] = round($x, 1) . ',' . round($y, 1);
        }

        echo '<div class="sn-dua-spark-wrap" id="sn-dua-spark-wrap">';
        echo '<div class="sn-dua-spark-tip" id="sn-dua-spark-tip">'
           . '<div class="sn-dua-spark-tip-date" id="sn-dua-tip-date"></div>'
           . '<div class="sn-dua-spark-tip-name" id="sn-dua-tip-name"></div>'
           . '<div class="sn-dua-spark-tip-grade" id="sn-dua-tip-grade"></div>'
           . '</div>';

        echo '<svg viewBox="0 0 ' . $sp_w . ' ' . $sp_h . '" preserveAspectRatio="xMidYMid meet">';
        // Grid lines.
        for ($tick = 0; $tick <= 10; $tick += 2) {
            $ty = $sp_h - $sp_pad - ($tick / $sp_max) * ($sp_h - 2 * $sp_pad);
            echo '<line x1="' . $sp_pad . '" y1="' . round($ty, 1) . '" x2="' . ($sp_w - $sp_pad)
               . '" y2="' . round($ty, 1) . '" stroke="#e5e7eb" stroke-dasharray="3,3"/>';
            echo '<text x="' . ($sp_pad - 6) . '" y="' . round($ty + 3, 1)
               . '" text-anchor="end" font-size="8" fill="#9ca3af">' . $tick . '</text>';
        }
        if ($sp_n > 1) {
            // Gradient area.
            echo '<defs><linearGradient id="dua-spark-grad" x1="0" y1="0" x2="0" y2="1">'
               . '<stop offset="0%" stop-color="#7c3aed" stop-opacity=".18"/>'
               . '<stop offset="100%" stop-color="#7c3aed" stop-opacity=".02"/>'
               . '</linearGradient></defs>';
            $area_pts = $sp_pad . ',' . ($sp_h - $sp_pad) . ' '
                      . implode(' ', $sp_points)
                      . ' ' . ($sp_w - $sp_pad) . ',' . ($sp_h - $sp_pad);
            echo '<polygon points="' . $area_pts . '" fill="url(#dua-spark-grad)"/>';
        }
        if ($sp_n === 1) {
            // Single point at the left edge (start of the timeline).
            $x = $sp_pad;
            $y = $sp_h - $sp_pad - ($dua_sparkline_pts[0]['avg'] / $sp_max) * ($sp_h - 2 * $sp_pad);
            echo '<circle class="sn-dua-sp-dot" data-idx="0" data-fixed="1" cx="' . round($x, 1) . '" cy="' . round($y, 1)
               . '" r="6" fill="#7c3aed" stroke="#fff" stroke-width="2.5" style="cursor:pointer"/>';
        } else {
            // Line.
            echo '<polyline points="' . implode(' ', $sp_points)
               . '" fill="none" stroke="#7c3aed" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>';
            // Interactive dots.
            foreach ($dua_sparkline_pts as $si => $sp) {
                $x = $sp_pad + ($si / max($sp_n - 1, 1)) * ($sp_w - 2 * $sp_pad);
                $y = $sp_h - $sp_pad - ($sp['avg'] / $sp_max) * ($sp_h - 2 * $sp_pad);
                echo '<circle class="sn-dua-sp-dot" data-idx="' . $si . '" cx="' . round($x, 1)
                   . '" cy="' . round($y, 1) . '" r="4" fill="#7c3aed" stroke="#fff" stroke-width="2"'
                   . ' style="cursor:pointer" opacity="0"/>';
            }
        }
        echo '</svg>';
        echo '</div>'; // .sn-dua-spark-wrap

        // Sparkline tooltip JS.
        $sp_json = json_encode(array_map(function($p) {
            return [
                'd' => userdate($p['time'], '%d/%m/%Y'),
                'n' => $p['name'],
                'c' => $p['crit'],
                'a' => $p['avg'],
                'g' => $p['grade'],
            ];
        }, $dua_sparkline_pts), JSON_UNESCAPED_UNICODE);
        echo '<script>
(function(){
var data=' . $sp_json . ';
var wrap=document.getElementById("sn-dua-spark-wrap");
var tip=document.getElementById("sn-dua-spark-tip");
if(!wrap||!tip)return;
wrap.addEventListener("mouseover",function(e){
    var dot=e.target.closest(".sn-dua-sp-dot");
    if(!dot)return;
    dot.setAttribute("opacity","1");
    var d=data[parseInt(dot.dataset.idx,10)];if(!d)return;
    document.getElementById("sn-dua-tip-date").textContent="📅 "+d.d;
    document.getElementById("sn-dua-tip-name").innerHTML="<strong>"+d.n+"</strong>";
    document.getElementById("sn-dua-tip-grade").textContent="Media acumulada: "+d.a.toFixed(1);
    tip.style.display="block";
    var r=wrap.getBoundingClientRect();
    var cr=dot.getBoundingClientRect();
    tip.style.left=Math.min(cr.left-r.left+12, r.width-280)+"px";
    tip.style.top=(cr.top-r.top-tip.offsetHeight-8)+"px";
});
wrap.addEventListener("mouseout",function(e){
    var dot=e.target.closest(".sn-dua-sp-dot");
    if(dot){if(!dot.dataset.fixed)dot.setAttribute("opacity","0");tip.style.display="none";}
});
})();
</script>';
    } else {
        echo '<div class="sn-dua-empty">Aún no hay actividades evaluadas en esta materia.</div>';
    }
    echo '</div>'; // .sn-dua-header

    // ══════════════════════════════════════════════════════════════════════════
    // BLOQUE 2: Tarjetas duales — Fortalezas y Retos
    // ══════════════════════════════════════════════════════════════════════════
    if (!empty($dua_strengths) || !empty($dua_challenges)) {
        echo '<div class="sn-dua-cards">';

        // Column: Fortalezas.
        echo '<div>';
        echo '<div class="sn-dua-col-head" style="color:#065f46">🌟 Mis Fortalezas</div>';
        if (empty($dua_strengths)) {
            echo '<div class="sn-dua-empty">Aún sin datos suficientes.</div>';
        }
        foreach ($dua_strengths as $s) {
            $gc = descriptor_calculator::grade_colour($s['grade']);
            echo '<div class="sn-dua-card sn-dua-card--strength">';
            echo '<span class="sn-dua-card-ce" style="background:' . $s['ce_color'] . '">'
               . s($s['ce_short']) . '</span>';
            echo '<div class="sn-dua-card-name">' . s($s['name']) . '</div>';
            if (!empty($s['activities'])) {
                $last_act = end($s['activities']);
                if (!empty($last_act['cmid'])) {
                    $act_url = new moodle_url('/mod/assign/view.php', ['id' => $last_act['cmid']]);
                    echo '<div class="sn-dua-card-context">📋 <a href="' . $act_url . '">'
                       . s($last_act['activityname']) . '</a></div>';
                } else {
                    echo '<div class="sn-dua-card-context">📋 ' . s($last_act['activityname']) . '</div>';
                }
            }
            echo '<div class="sn-dua-card-grade" style="color:' . $gc . '">'
               . number_format($s['grade'], 1) . ' / 10</div>';
            echo '</div>';
        }
        echo '</div>';

        // Column: Retos.
        echo '<div>';
        echo '<div class="sn-dua-col-head" style="color:#5b21b6">🎯 Mis Retos de Aprendizaje</div>';
        if (empty($dua_challenges)) {
            echo '<div class="sn-dua-empty">Aún sin datos suficientes.</div>';
        }
        foreach ($dua_challenges as $ch) {
            $gc = descriptor_calculator::grade_colour($ch['grade']);
            echo '<div class="sn-dua-card sn-dua-card--challenge">';
            echo '<span class="sn-dua-card-ce" style="background:' . $ch['ce_color'] . '">'
               . s($ch['ce_short']) . '</span>';
            echo '<div class="sn-dua-card-name">' . s($ch['name']) . '</div>';

            // Context: link to activity.
            if (!empty($ch['activities'])) {
                $last_act = end($ch['activities']);
                $act_url = new moodle_url('/mod/assign/view.php', ['id' => $last_act['cmid']]);
                echo '<div class="sn-dua-card-context">📋 <a href="' . $act_url . '">'
                   . s($last_act['activityname']) . '</a></div>';
            }
            echo '<div class="sn-dua-card-grade" style="color:' . $gc . '">'
               . number_format($ch['grade'], 1) . ' / 10</div>';

            // Pauta DUA: rubric level definition as guidance.
            if (!empty($ch['activities'])) {
                $last_act = end($ch['activities']);
                if (!empty($last_act['leveldefinition'])) {
                    echo '<div class="sn-dua-card-pauta">';
                    echo '<div class="sn-dua-card-pauta-label">💡 Pauta de mejora</div>';
                    echo s($last_act['leveldefinition']);
                    echo '</div>';
                }
            }
            echo '</div>';
        }
        echo '</div>';
        echo '</div>'; // .sn-dua-cards
    }

    // ══════════════════════════════════════════════════════════════════════════
    // BLOQUE 3: Bitácora pedagógica (solo profesores)
    // ══════════════════════════════════════════════════════════════════════════
    if ($dua_can_edit) {
        echo '<div class="sn-dua-section-title">📓 Bitácora pedagógica</div>';
        echo '<div class="sn-dua-bitacora" id="sn-dua-bitacora">';
        echo '<div class="sn-dua-bitacora-form">';
        echo '<textarea id="sn-dua-note-input" class="sn-dua-textarea" placeholder="Añade una observación pedagógica sobre ' . s($student->firstname) . '…" rows="2"></textarea>';
        echo '<button id="sn-dua-note-save" class="sn-dua-btn sn-dua-btn--primary" type="button">Guardar nota</button>';
        echo '</div>';
        echo '<div id="sn-dua-notes-list" class="sn-dua-notes-list">';
        echo '<div class="sn-dua-empty">Cargando notas…</div>';
        echo '</div>';
        echo '</div>';
    }

    // ══════════════════════════════════════════════════════════════════════════
    // BLOQUE 4: Claves de motivación (todos ven, solo profesor edita)
    // ══════════════════════════════════════════════════════════════════════════
    echo '<div class="sn-dua-section-title">💡 Claves de motivación</div>';
    echo '<div class="sn-dua-motiv" id="sn-dua-motiv">';
    echo '<div class="sn-dua-motiv-row">';
    echo '<div class="sn-dua-motiv-col">';
    echo '<div class="sn-dua-motiv-head" style="color:#0d9488">🌟 Intereses del alumno</div>';
    echo '<div class="sn-dua-chips" id="sn-dua-chips-interest"></div>';
    if ($dua_can_edit) {
        echo '<div class="sn-dua-chip-add">';
        echo '<input id="sn-dua-motiv-interest-input" class="sn-dua-chip-input" type="text" placeholder="Nuevo interés…" maxlength="100">';
        echo '<button class="sn-dua-btn sn-dua-btn--sm" type="button" onclick="snDuaMotivAdd(\'interest\')">+</button>';
        echo '</div>';
    }
    echo '</div>';
    echo '<div class="sn-dua-motiv-col">';
    echo '<div class="sn-dua-motiv-head" style="color:#7c3aed">🎣 Ganchos pedagógicos</div>';
    echo '<div class="sn-dua-chips" id="sn-dua-chips-hook"></div>';
    if ($dua_can_edit) {
        echo '<div class="sn-dua-chip-add">';
        echo '<input id="sn-dua-motiv-hook-input" class="sn-dua-chip-input" type="text" placeholder="Nuevo gancho…" maxlength="100">';
        echo '<button class="sn-dua-btn sn-dua-btn--sm" type="button" onclick="snDuaMotivAdd(\'hook\')">+</button>';
        echo '</div>';
    }
    echo '</div>';
    echo '</div>';
    echo '</div>';

    // ══════════════════════════════════════════════════════════════════════════
    // BLOQUE 5: Adaptaciones (todos ven histórico, solo profesor edita)
    // ══════════════════════════════════════════════════════════════════════════
    echo '<div class="sn-dua-section-title">♿ Adaptaciones</div>';
    echo '<div class="sn-adapt" id="sn-adapt">';
    if ($dua_can_edit) {
        echo '<button class="sn-adapt-add-btn" type="button" id="sn-adapt-add-btn">＋ Añadir adaptación</button>';
    }
    echo '<div class="sn-adapt-history" id="sn-adapt-history">';
    echo '<div class="sn-adapt-empty">Cargando adaptaciones…</div>';
    echo '</div>';
    echo '</div>';

    // ── Modal (solo se renderiza para profesores) ──
    if ($dua_can_edit) {
        echo '<div class="sn-adapt-modal-bg" id="sn-adapt-modal-bg">';
        echo '<div class="sn-adapt-modal">';
        echo '<div class="sn-adapt-modal-head">';
        echo '<span class="sn-adapt-modal-title" id="sn-adapt-modal-title">Añadir adaptación</span>';
        echo '<button class="sn-adapt-modal-close" type="button" id="sn-adapt-modal-close">✕</button>';
        echo '</div>';
        echo '<div class="sn-adapt-modal-body">';

        // Step 1: Type chooser
        echo '<div id="sn-adapt-step1">';
        echo '<div class="sn-adapt-types">';
        echo '<div class="sn-adapt-type-card" data-type="acis">';
        echo '<div class="sn-adapt-type-icon">📋</div>';
        echo '<div class="sn-adapt-type-label">ACIS / ACS</div>';
        echo '<div class="sn-adapt-type-desc">Adaptación Curricular Significativa: modifica los criterios de evaluación normativos</div>';
        echo '</div>';
        echo '<div class="sn-adapt-type-card" data-type="access">';
        echo '<div class="sn-adapt-type-icon">🔧</div>';
        echo '<div class="sn-adapt-type-label">Adaptación de Acceso</div>';
        echo '<div class="sn-adapt-type-desc">Medidas organizativas y de acceso: ajustes de formato, tiempo, apoyos o entorno</div>';
        echo '</div>';
        echo '</div>';
        echo '</div>';

        // Step 2a: ACIS form
        echo '<div class="sn-adapt-form" id="sn-adapt-form-acis">';
        echo '<div class="sn-adapt-field">';
        echo '<label class="sn-adapt-label">Criterio normativo</label>';
        echo '<select class="sn-adapt-select" id="sn-adapt-acis-criterion"><option value="">Selecciona un criterio…</option></select>';
        echo '</div>';
        echo '<div class="sn-adapt-field" id="sn-adapt-acis-compare" style="display:none">';
        echo '<div class="sn-adapt-side">';
        echo '<div class="sn-adapt-side-box sn-adapt-side-box--orig">';
        echo '<div class="sn-adapt-side-label">Criterio original</div>';
        echo '<div id="sn-adapt-acis-orig-text">—</div>';
        echo '</div>';
        echo '<div class="sn-adapt-side-box sn-adapt-side-box--edit">';
        echo '<div class="sn-adapt-side-label">Criterio adaptado</div>';
        echo '<textarea class="sn-adapt-textarea" id="sn-adapt-acis-adapted" placeholder="Redacta la adaptación específica…"></textarea>';
        echo '</div>';
        echo '</div>';
        echo '</div>';
        echo '<div class="sn-adapt-field">';
        echo '<div class="sn-adapt-dates">';
        echo '<div><label class="sn-adapt-label">Fecha de inicio</label>';
        echo '<input type="date" class="sn-adapt-input" id="sn-adapt-acis-start"></div>';
        echo '<div><label class="sn-adapt-label">Fecha de fin probable</label>';
        echo '<input type="date" class="sn-adapt-input" id="sn-adapt-acis-end"></div>';
        echo '</div>';
        echo '</div>';
        echo '</div>';

        // Step 2b: Access form
        echo '<div class="sn-adapt-form" id="sn-adapt-form-access">';
        echo '<div class="sn-adapt-field">';
        echo '<div class="sn-adapt-catalog-subtitle">Medidas estándar</div>';
        echo '<div class="sn-adapt-catalog-group" id="sn-adapt-cat-standard">';
        $standard_measures = [
            'Tiempo ampliado en pruebas',
            'Tipografía / tamaño de letra adaptado',
            'Formato oral / respuesta grabada',
            'Pausas estructuradas',
            'Lector de pantalla / síntesis de voz',
            'Intérprete de lengua de signos',
            'Ubicación preferente en el aula',
            'Uso de calculadora',
            'Material manipulativo',
        ];
        foreach ($standard_measures as $sm) {
            echo '<label class="sn-adapt-catalog-item"><input type="checkbox" value="' . s($sm) . '"> ' . s($sm) . '</label>';
        }
        echo '</div>';
        echo '<div class="sn-adapt-catalog-subtitle">Programas y recursos externos</div>';
        echo '<div class="sn-adapt-catalog-group" id="sn-adapt-cat-program">';
        $programs = [
            'SAI (Servicio de Apoyo Itinerante)',
            'SAED (Servicio de Apoyo Educativo Domiciliario)',
            'Aulas de Enlace',
            'PT (Pedagogía Terapéutica)',
            'AL (Audición y Lenguaje)',
            'Orientación Educativa',
        ];
        foreach ($programs as $pg) {
            echo '<label class="sn-adapt-catalog-item"><input type="checkbox" value="' . s($pg) . '"> ' . s($pg) . '</label>';
        }
        echo '</div>';
        echo '<div class="sn-adapt-catalog-subtitle">Medida personalizada</div>';
        echo '<div class="sn-adapt-field">';
        echo '<input type="text" class="sn-adapt-input" id="sn-adapt-access-custom" placeholder="Otra medida específica…" maxlength="255">';
        echo '</div>';
        echo '</div>';
        echo '<div class="sn-adapt-field">';
        echo '<div class="sn-adapt-dates">';
        echo '<div><label class="sn-adapt-label">Fecha de inicio</label>';
        echo '<input type="date" class="sn-adapt-input" id="sn-adapt-access-start"></div>';
        echo '<div><label class="sn-adapt-label">Fecha de fin probable</label>';
        echo '<input type="date" class="sn-adapt-input" id="sn-adapt-access-end"></div>';
        echo '</div>';
        echo '</div>';
        echo '</div>';

        echo '</div>'; // modal body
        echo '<div class="sn-adapt-modal-foot" id="sn-adapt-modal-foot" style="display:none">';
        echo '<button class="sn-adapt-btn-cancel" type="button" id="sn-adapt-back">← Volver</button>';
        echo '<button class="sn-adapt-btn-save" type="button" id="sn-adapt-save">Guardar</button>';
        echo '</div>';
        echo '</div>'; // modal
        echo '</div>'; // modal bg
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Footer
    // ══════════════════════════════════════════════════════════════════════════
    echo '<div style="text-align:center;margin:16px 0 8px">';
    echo '<span style="display:inline-block;padding:4px 14px;border-radius:12px;'
       . 'background:#f3f4f6;color:#6b7280;font-size:.78em;font-weight:500;letter-spacing:.01em">';
    echo 'Panel DUA — Diseño Universal para el Aprendizaje';
    echo '</span>';
    echo '</div>';

    echo '</div>'; // .sn-dua-wrap
    echo '</div>'; // #sentinel-pane-dua

}

// ── JS: DUA AJAX interactions (Bitácora, Motivación, Adaptaciones) ──
if ($has_subject_tab) {
    $dua_ajax_url = (new moodle_url('/local/sentinel/ajax.php'))->out(false);
    $can_edit_js = $dua_can_edit ? 'true' : 'false';
    echo '<script>
(function(){
"use strict";
var AJAX="' . $dua_ajax_url . '";
var SK="' . sesskey() . '";
var UID=' . (int)$userid . ';
var CID=' . (int)$selected_courseid . ';
var CAN_EDIT=' . $can_edit_js . ';

function post(params,cb){
    params.sesskey=SK;
    var fd=new FormData();
    for(var k in params)fd.append(k,params[k]);
    fetch(AJAX,{method:"POST",body:fd})
        .then(function(r){return r.json()})
        .then(cb)
        .catch(function(e){console.error(e)});
}

function esc(s){var d=document.createElement("div");d.textContent=s;return d.innerHTML;}
function fmtDate(ts){if(!ts)return"—";var d=new Date(ts*1000);return d.toLocaleDateString("es-ES",{day:"2-digit",month:"short",year:"numeric"});}
function dateToTs(v){if(!v)return 0;return Math.floor(new Date(v+"T00:00:00").getTime()/1000);}
function todayStr(){return new Date().toISOString().slice(0,10);}

// ── Bitácora (solo profesores) ──
var noteList=document.getElementById("sn-dua-notes-list");
var noteInput=document.getElementById("sn-dua-note-input");
var noteSave=document.getElementById("sn-dua-note-save");

function renderNotes(notes){
    if(!noteList)return;
    if(!notes.length){noteList.innerHTML="<div class=\"sn-dua-empty\">Sin observaciones todavía. Añade la primera.</div>";return;}
    noteList.innerHTML=notes.map(function(n){
        var acts=n.ismine?"<div class=\"sn-dua-note-actions\">"
            +"<button class=\"sn-dua-btn sn-dua-btn--danger\" onclick=\"snDuaNoteDelete("+n.id+")\" title=\"Eliminar\">✕</button>"
            +"</div>":"";
        return "<div class=\"sn-dua-note\" id=\"sn-dua-note-"+n.id+"\">"
            +"<div class=\"sn-dua-note-meta\"><span>"+esc(n.authorname)+" · "+esc(n.date)+"</span>"+acts+"</div>"
            +"<div class=\"sn-dua-note-content\">"+esc(n.content)+"</div>"
            +"</div>";
    }).join("");
}

function loadNotes(){
    if(!CAN_EDIT)return;
    post({action:"dua_notes_list",userid:UID,courseid:CID},function(r){
        if(r.success)renderNotes(r.notes);
    });
}

if(noteSave){
    noteSave.addEventListener("click",function(){
        var txt=noteInput.value.trim();
        if(!txt)return;
        noteSave.disabled=true;
        post({action:"dua_note_save",userid:UID,courseid:CID,content:txt},function(r){
            noteSave.disabled=false;
            if(r.success){noteInput.value="";loadNotes();}
        });
    });
}

window.snDuaNoteDelete=function(id){
    if(!confirm("¿Eliminar esta observación?"))return;
    post({action:"dua_note_delete",noteid:id},function(r){
        if(r.success)loadNotes();
    });
};

// ── Motivación ──
function renderChips(items){
    var intEl=document.getElementById("sn-dua-chips-interest");
    var hookEl=document.getElementById("sn-dua-chips-hook");
    if(!intEl||!hookEl)return;
    intEl.innerHTML="";hookEl.innerHTML="";
    items.forEach(function(m){
        var el=m.category==="interest"?intEl:hookEl;
        var cls=m.category==="interest"?"sn-dua-chip--interest":"sn-dua-chip--hook";
        var del=CAN_EDIT&&m.ismine?"<span class=\"sn-dua-chip-x\" onclick=\"snDuaMotivDel("+m.id+")\">✕</span>":"";
        el.innerHTML+="<span class=\"sn-dua-chip "+cls+"\">"+esc(m.label)+del+"</span>";
    });
    if(!intEl.innerHTML)intEl.innerHTML="<span style=\"color:#9ca3af;font-size:.78em\">Sin intereses registrados</span>";
    if(!hookEl.innerHTML)hookEl.innerHTML="<span style=\"color:#9ca3af;font-size:.78em\">Sin ganchos registrados</span>";
}

function loadMotiv(){
    post({action:"dua_motiv_list",userid:UID,courseid:CID},function(r){
        if(r.success)renderChips(r.items);
    });
}

window.snDuaMotivAdd=function(cat){
    if(!CAN_EDIT)return;
    var inp=document.getElementById("sn-dua-motiv-"+cat+"-input");
    var label=inp.value.trim();
    if(!label)return;
    post({action:"dua_motiv_save",userid:UID,courseid:CID,category:cat,label:label},function(r){
        if(r.success){inp.value="";loadMotiv();}
    });
};

window.snDuaMotivDel=function(id){
    if(!CAN_EDIT)return;
    post({action:"dua_motiv_delete",itemid:id},function(r){
        if(r.success)loadMotiv();
    });
};

// ── Adaptaciones: nuevo sistema con modal + histórico ──
var adaptHistory=document.getElementById("sn-adapt-history");
var adaptData={acis:[],access:[]};
var ceCriteria=[];

function subBadge(sub){
    if(sub==="standard")return"<span class=\"sn-adapt-badge sn-adapt-badge--standard\">Estándar</span>";
    if(sub==="program")return"<span class=\"sn-adapt-badge sn-adapt-badge--program\">Programa</span>";
    return"<span class=\"sn-adapt-badge sn-adapt-badge--custom\">Personalizada</span>";
}

function renderAdaptHistory(){
    if(!adaptHistory)return;
    var all=[];
    adaptData.acis.forEach(function(a){a._type="acis";all.push(a);});
    adaptData.access.forEach(function(a){a._type="access";all.push(a);});
    all.sort(function(a,b){return b.timecreated-a.timecreated;});
    if(!all.length){
        adaptHistory.innerHTML="<div class=\"sn-adapt-empty\">No hay adaptaciones registradas.</div>";
        return;
    }
    adaptHistory.innerHTML=all.map(function(a){
        if(a._type==="acis"){
            var ceCode="CE"+a.criterionid;
            for(var i=0;i<ceCriteria.length;i++){if(ceCriteria[i].id===a.criterionid){ceCode=ceCriteria[i].code;break;}}
            var dates=fmtDate(a.datestart)+" ➔ "+(a.dateend?fmtDate(a.dateend):"en curso");
            var acts=CAN_EDIT?"<div class=\"sn-adapt-card-actions\">"
                +"<button onclick=\"snAdaptDel(\'acis\',"+a.id+")\" title=\"Eliminar\">🗑</button>"
                +"</div>":"";
            return "<div class=\"sn-adapt-card sn-adapt-card--acis\">"
                +acts
                +"<div class=\"sn-adapt-card-head\">"
                +"<span class=\"sn-adapt-badge sn-adapt-badge--acis\">ACIS</span>"
                +"<span style=\"font-weight:700;font-size:.88em;color:#92400e\">"+esc(ceCode)+"</span>"
                +"<span class=\"sn-adapt-card-dates\">"+dates+"</span>"
                +"</div>"
                +"<div class=\"sn-adapt-card-measure\">"+esc(a.adapted_text.substring(0,120))+(a.adapted_text.length>120?"…":"")+"</div>"
                +"<span class=\"sn-adapt-card-expand\" onclick=\"this.nextElementSibling.classList.toggle(\'open\');this.textContent=this.nextElementSibling.classList.contains(\'open\')? \'▲ Ocultar detalle\':\'▼ Ver detalle\'\">▼ Ver detalle</span>"
                +"<div class=\"sn-adapt-card-detail\">"
                +"<div class=\"sn-adapt-side\">"
                +"<div class=\"sn-adapt-side-box sn-adapt-side-box--orig\"><div class=\"sn-adapt-side-label\">Criterio original</div>"+esc(a.original_text)+"</div>"
                +"<div class=\"sn-adapt-side-box sn-adapt-side-box--edit\" style=\"border:1.5px solid #fbbf24\"><div class=\"sn-adapt-side-label\">Criterio adaptado</div>"+esc(a.adapted_text)+"</div>"
                +"</div></div>"
                +"</div>";
        } else {
            var cls=a.active?"":"sn-adapt-card--inactive";
            var dates=fmtDate(a.datestart)+(a.dateend?" ➔ "+fmtDate(a.dateend):"");
            var toggleIcon=a.active?"⏸":"▶";
            var acts=CAN_EDIT?"<div class=\"sn-adapt-card-actions\">"
                +"<button class=\"sn-adapt-card-toggle\" onclick=\"snAdaptToggle("+a.id+")\" title=\""+(a.active?"Desactivar":"Activar")+"\">"+toggleIcon+"</button>"
                +"<button onclick=\"snAdaptDel(\'access\',"+a.id+")\" title=\"Eliminar\">🗑</button>"
                +"</div>":"";
            return "<div class=\"sn-adapt-card sn-adapt-card--access "+cls+"\">"
                +acts
                +"<div class=\"sn-adapt-card-head\">"
                +"<span class=\"sn-adapt-badge sn-adapt-badge--access\">Acceso</span>"
                +subBadge(a.subcategory||"custom")
                +(dates?"<span class=\"sn-adapt-card-dates\">"+dates+"</span>":"")
                +"</div>"
                +"<div class=\"sn-adapt-card-measure\">"+(a.active?"":"<s>")+esc(a.measure)+(a.active?"":"</s>")+"</div>"
                +"</div>";
        }
    }).join("");
}

function loadAdaptations(){
    var done=0;
    post({action:"dua_acis_list",userid:UID,courseid:CID},function(r){
        if(r.success)adaptData.acis=r.items;
        if(++done===2)renderAdaptHistory();
    });
    post({action:"dua_access_list",userid:UID,courseid:CID},function(r){
        if(r.success)adaptData.access=r.items;
        if(++done===2)renderAdaptHistory();
    });
}

// ── Modal logic (solo profesores) ──
if(CAN_EDIT){
    var modalBg=document.getElementById("sn-adapt-modal-bg");
    var step1=document.getElementById("sn-adapt-step1");
    var formAcis=document.getElementById("sn-adapt-form-acis");
    var formAccess=document.getElementById("sn-adapt-form-access");
    var foot=document.getElementById("sn-adapt-modal-foot");
    var modalTitle=document.getElementById("sn-adapt-modal-title");
    var currentType=null;

    function openModal(){
        step1.style.display="";formAcis.classList.remove("active");formAccess.classList.remove("active");
        foot.style.display="none";modalTitle.textContent="Añadir adaptación";currentType=null;
        modalBg.classList.add("active");
        if(!ceCriteria.length){
            post({action:"dua_ce_criteria",courseid:CID},function(r){
                if(r.success){
                    ceCriteria=r.criteria;
                    var sel=document.getElementById("sn-adapt-acis-criterion");
                    sel.innerHTML="<option value=\\"\\">Selecciona un criterio…</option>";
                    ceCriteria.forEach(function(c){
                        sel.innerHTML+="<option value=\\""+c.id+"\\">"+esc(c.code)+" — "+esc(c.name)+"</option>";
                    });
                }
            });
        }
    }

    function closeModal(){modalBg.classList.remove("active");}

    document.getElementById("sn-adapt-add-btn").addEventListener("click",openModal);
    document.getElementById("sn-adapt-modal-close").addEventListener("click",closeModal);
    modalBg.addEventListener("click",function(e){if(e.target===modalBg)closeModal();});

    // Type cards
    document.querySelectorAll(".sn-adapt-type-card").forEach(function(card){
        card.addEventListener("click",function(){
            currentType=this.dataset.type;
            step1.style.display="none";
            foot.style.display="flex";
            if(currentType==="acis"){
                formAcis.classList.add("active");
                modalTitle.textContent="Adaptación Curricular Significativa (ACIS)";
                document.getElementById("sn-adapt-acis-start").value=todayStr();
                document.getElementById("sn-adapt-acis-end").value="";
            } else {
                formAccess.classList.add("active");
                modalTitle.textContent="Adaptación de Acceso";
                document.getElementById("sn-adapt-access-start").value=todayStr();
                document.getElementById("sn-adapt-access-end").value="";
                document.querySelectorAll("#sn-adapt-cat-standard input,#sn-adapt-cat-program input").forEach(function(cb){cb.checked=false;});
                document.getElementById("sn-adapt-access-custom").value="";
            }
        });
    });

    // Back button
    document.getElementById("sn-adapt-back").addEventListener("click",function(){
        formAcis.classList.remove("active");formAccess.classList.remove("active");
        step1.style.display="";foot.style.display="none";
        modalTitle.textContent="Añadir adaptación";currentType=null;
    });

    // ACIS criterion selector → show side-by-side
    document.getElementById("sn-adapt-acis-criterion").addEventListener("change",function(){
        var compare=document.getElementById("sn-adapt-acis-compare");
        var sel=this.value;
        if(!sel){compare.style.display="none";return;}
        compare.style.display="";
        var name="";
        for(var i=0;i<ceCriteria.length;i++){if(ceCriteria[i].id==sel){name=ceCriteria[i].name;break;}}
        document.getElementById("sn-adapt-acis-orig-text").textContent=name;
        document.getElementById("sn-adapt-acis-adapted").value="";
    });

    // Save
    document.getElementById("sn-adapt-save").addEventListener("click",function(){
        var btn=this;btn.disabled=true;
        if(currentType==="acis"){
            var crit=document.getElementById("sn-adapt-acis-criterion").value;
            var adapted=document.getElementById("sn-adapt-acis-adapted").value.trim();
            var orig=document.getElementById("sn-adapt-acis-orig-text").textContent;
            if(!crit||!adapted){btn.disabled=false;return;}
            post({
                action:"dua_acis_save",userid:UID,courseid:CID,criterionid:crit,
                original_text:orig,adapted_text:adapted,
                datestart:dateToTs(document.getElementById("sn-adapt-acis-start").value),
                dateend:dateToTs(document.getElementById("sn-adapt-acis-end").value)
            },function(r){
                btn.disabled=false;
                if(r.success){closeModal();loadAdaptations();}
            });
        } else {
            var measures=[];
            document.querySelectorAll("#sn-adapt-cat-standard input:checked").forEach(function(cb){measures.push({m:cb.value,sub:"standard"});});
            document.querySelectorAll("#sn-adapt-cat-program input:checked").forEach(function(cb){measures.push({m:cb.value,sub:"program"});});
            var custom=document.getElementById("sn-adapt-access-custom").value.trim();
            if(custom)measures.push({m:custom,sub:"custom"});
            if(!measures.length){btn.disabled=false;return;}
            var saved=0;
            var ds=dateToTs(document.getElementById("sn-adapt-access-start").value);
            var de=dateToTs(document.getElementById("sn-adapt-access-end").value);
            measures.forEach(function(item){
                post({
                    action:"dua_access_save",userid:UID,courseid:CID,
                    measure:item.m,subcategory:item.sub,datestart:ds,dateend:de
                },function(r){
                    if(++saved===measures.length){btn.disabled=false;closeModal();loadAdaptations();}
                });
            });
        }
    });
}

window.snAdaptDel=function(type,id){
    if(!CAN_EDIT)return;
    if(!confirm("¿Eliminar esta adaptación?"))return;
    var action=type==="acis"?"dua_acis_delete":"dua_access_delete";
    post({action:action,itemid:id},function(r){if(r.success)loadAdaptations();});
};

window.snAdaptToggle=function(id){
    if(!CAN_EDIT)return;
    post({action:"dua_access_toggle",itemid:id},function(r){if(r.success)loadAdaptations();});
};

// ── Init ──
var duaPane=document.getElementById("sentinel-pane-dua");
var duaLoaded=false;
function initDua(){
    if(duaLoaded)return;
    duaLoaded=true;
    loadNotes();loadMotiv();loadAdaptations();
}
if(duaPane&&duaPane.classList.contains("active")){initDua();}
var duaBtn=document.querySelector("[data-subtab=\\"dua\\"]");
if(duaBtn){duaBtn.addEventListener("click",function(){setTimeout(initDua,50);});}
})();
</script>';
}

// ── PANE: Calificaciones (libro de calificaciones del curso) ────────────────
if ($has_subject_tab) {
    echo '<div id="sentinel-pane-calificaciones" class="sn-view-pane">';

    echo '<style>
.sn-cal-wrap { max-width:900px; margin:0 auto; padding:0 8px 16px; }
.sn-cal-hero {
    display:flex; align-items:center; gap:16px; padding:14px 18px;
    border-radius:8px; margin-bottom:14px;
    background:#fff; border:1.5px solid transparent;
    box-shadow:0 1px 3px rgba(0,0,0,.07);
    transition:transform .1s, box-shadow .15s;
}
.sn-cal-hero:hover { transform:translateY(-2px); box-shadow:0 6px 16px rgba(0,0,0,.11); }
.sn-cal-hero-grade {
    width:52px; height:52px; border-radius:50%;
    display:flex; align-items:center; justify-content:center;
    font-size:1.3em; font-weight:800; color:#fff;
    flex-shrink:0; box-shadow:0 2px 6px rgba(0,0,0,.1);
}
.sn-cal-hero-info { flex:1; min-width:0; }
.sn-cal-hero-title { font-weight:700; font-size:.92em; color:#334155; }
.sn-cal-hero-sub { font-size:.72em; color:#94a3b8; margin-top:2px; }
.sn-cal-cat {
    border-radius:8px; margin-bottom:8px; overflow:hidden;
    background:#fff; border:1.5px solid transparent;
    box-shadow:0 1px 3px rgba(0,0,0,.07);
    transition:transform .1s, box-shadow .15s;
}
.sn-cal-cat:hover { box-shadow:0 4px 12px rgba(0,0,0,.09); }
.sn-cal-cat-hdr {
    display:flex; align-items:center; gap:10px; padding:10px 14px;
    cursor:pointer; user-select:none; flex-wrap:wrap;
    transition:background .15s;
}
.sn-cal-cat-hdr:hover { background:var(--sn-cal-tint, #f8fafc); }
.sn-cal-cat-arrow {
    font-size:.6em; color:#94a3b8; transition:transform .2s;
    width:14px; text-align:center; flex-shrink:0;
}
.sn-cal-cat.collapsed .sn-cal-cat-arrow { transform:rotate(-90deg); }
.sn-cal-cat.collapsed .sn-cal-cat-body { display:none; }
.sn-cal-cat-label {
    display:flex; align-items:center; gap:8px; flex:1; min-width:0;
}
.sn-cal-cat-badge {
    display:inline-block; padding:2px 8px; border-radius:4px;
    font-size:.72em; font-weight:600; color:#fff; flex-shrink:0;
    white-space:nowrap;
}
.sn-cal-cat-name {
    font-weight:600; font-size:.84em; color:#334155;
    overflow:hidden; text-overflow:ellipsis; white-space:nowrap;
}
.sn-cal-pill {
    display:inline-flex; align-items:center; padding:2px 8px; border-radius:4px;
    font-size:.66em; font-weight:600; white-space:nowrap; letter-spacing:.02em;
}
.sn-cal-pill--weight { background:#f3f0ff; color:#7c3aed; }
.sn-cal-pill--agg { background:#ecfdf5; color:#059669; }
.sn-cal-cat-grade {
    font-weight:700; font-size:.95em; min-width:34px; text-align:right; flex-shrink:0;
    font-variant-numeric:tabular-nums;
}
.sn-cal-cat-body { padding:0 4px 6px; }
.sn-cal-item {
    display:flex; align-items:center; gap:10px; padding:7px 12px;
    border-radius:6px; margin:3px 4px;
    background:#fafbfc; border:1px solid transparent;
    transition:all .15s; flex-wrap:wrap;
}
.sn-cal-item:hover { background:var(--sn-cal-tint, #f0f4ff); border-color:#e2e8f0; transform:translateY(-1px); box-shadow:0 2px 6px rgba(0,0,0,.05); }
.sn-cal-item-dot {
    width:6px; height:6px; border-radius:50%; flex-shrink:0;
}
.sn-cal-item-name {
    flex:1; min-width:0; font-size:.8em; color:#475569;
    overflow:hidden; text-overflow:ellipsis; white-space:nowrap;
}
.sn-cal-item-name a { color:#475569; text-decoration:none; transition:color .15s; }
.sn-cal-item-name a:hover { color:#2563eb; }
.sn-cal-item-right {
    display:flex; align-items:center; gap:8px; flex-shrink:0;
}
.sn-cal-item-grade {
    font-weight:700; font-size:.82em; min-width:30px; text-align:right;
    font-variant-numeric:tabular-nums;
}
</style>';

    if ($selected_courseid) {
        require_once($CFG->libdir . '/gradelib.php');
        $grade_tree = new grade_tree($selected_courseid, false, true, null, true);

        // Name of the sentinel "Actividades" container category to hide from this view.
        $act_cat_name = get_string('activities_category', 'local_sentinel');

        // Compute course grade from visible categories only (excluding "Actividades").
        $course_final = null;
        $tree_items_raw = $grade_tree->top_element['children'] ?? [];
        $weighted_sum = 0.0;
        $weight_total = 0.0;
        $unweighted_sum = 0.0;
        $unweighted_count = 0;
        foreach ($tree_items_raw as $tw) {
            $twobj = $tw['object'];
            if ($twobj instanceof grade_category && $twobj->get_name() === $act_cat_name) {
                continue;
            }
            $tw_item = null;
            if ($twobj instanceof grade_category) {
                $tw_item = $twobj->load_grade_item();
            } elseif ($twobj instanceof grade_item && $twobj->itemtype !== 'course') {
                $tw_item = $twobj;
            }
            if (!$tw_item) { continue; }
            $tw_grade = $tw_item->get_grade($userid);
            if (!$tw_grade || $tw_grade->finalgrade === null) { continue; }
            $tw_val = (float)$tw_grade->finalgrade / max((float)$tw_item->grademax, 1) * 10;
            $tw_w = 0.0;
            if ($tw_item->aggregationcoef2 !== null && (float)$tw_item->aggregationcoef2 > 0) {
                $tw_w = (float)$tw_item->aggregationcoef2;
            } elseif ($tw_item->aggregationcoef !== null && (float)$tw_item->aggregationcoef > 0) {
                $tw_w = (float)$tw_item->aggregationcoef;
            }
            if ($tw_w > 0) {
                $weighted_sum += $tw_val * $tw_w;
                $weight_total += $tw_w;
            } else {
                $unweighted_sum += $tw_val;
                $unweighted_count++;
            }
        }
        if ($weight_total > 0) {
            $course_final = round($weighted_sum / $weight_total, 2);
        } elseif ($unweighted_count > 0) {
            $course_final = round($unweighted_sum / $unweighted_count, 2);
        }

        $course_label = $student_subject_name ?: ($visible_courses[$selected_courseid]->fullname ?? '');
        echo '<div class="sn-cal-wrap">';

        // Hero card.
        if ($course_final !== null) {
            $pct = $course_final / 10;
            $hue = round($pct * 120);
            $hero_color = 'hsl(' . $hue . ',55%,45%)';
            $hero_border = 'hsla(' . $hue . ',55%,45%,.15)';
            echo '<div class="sn-cal-hero" style="border-color:' . $hero_border . ';border-left:4px solid ' . $hero_color . ';--sn-cal-tint:hsl(' . $hue . ',55%,96%)">';
            echo '<div class="sn-cal-hero-grade" style="background:' . $hero_color . '">'
               . number_format($course_final, 1) . '</div>';
            echo '<div class="sn-cal-hero-info">';
            echo '<div class="sn-cal-hero-title">' . s($course_label) . '</div>';
            echo '<div class="sn-cal-hero-sub">Nota final del curso</div>';
            echo sentinel_bar($course_final, $hero_color, 100);
            if ($repr_course) {
                $rc_h = $repr_course;
                echo '<div style="margin-top:6px">';
                echo '<span class="sn-repr-badge sn-repr-badge--' . $rc_h['level'] . '" style="font-size:.68em">';
                echo '<span class="sn-repr-dot sn-repr-dot--' . $rc_h['level'] . '"></span>';
                if ($rc_h['level'] !== 'grey') {
                    echo number_format($rc_h['pct'], 0) . '% · ';
                }
                echo s($rc_h['label']);
                echo '</span>';
                echo '</div>';
            }
            echo '</div>';
        } else {
            echo '<div class="sn-cal-hero" style="border-color:#e2e8f025">';
            echo '<div class="sn-cal-hero-grade" style="background:#cbd5e1">–</div>';
            echo '<div class="sn-cal-hero-info">';
            echo '<div class="sn-cal-hero-title">' . s($course_label) . '</div>';
            echo '<div class="sn-cal-hero-sub">Sin calificación final</div>';
            echo '</div>';
        }
        echo '</div>';

        // Build CE number → color map from CE data so badges match the radar.
        $cal_ce_color_map = [];
        if ($ce_data && !empty($ce_data['ces'])) {
            foreach ($ce_data['ces'] as $ci => $ce) {
                if (preg_match('/^(?:CE\s*)?(\d+)/i', $ce['name'], $m)) {
                    $cal_ce_color_map[(int)$m[1]] = $ce['color'];
                } else {
                    $cal_ce_color_map[$ci + 1] = $ce['color'];
                }
            }
        }
        $cal_ce_palette = descriptor_calculator::CE_PALETTE;
        $cal_ce_idx = 0;

        $render_grade_tree = function($items, $depth = 0, $parent_color = null) use (
            &$render_grade_tree, $userid, $DB, $selected_courseid,
            &$cal_ce_color_map, &$cal_ce_palette, &$cal_ce_idx, $act_cat_name
        ) {
            foreach ($items as $item_wrapper) {
                $obj = $item_wrapper['object'];
                $children = $item_wrapper['children'] ?? [];

                if ($obj instanceof grade_category) {
                    $cat = $obj;
                    // Hide the "Actividades (Radar 360)" category and all its descendants.
                    if ($cat->get_name() === $act_cat_name) {
                        continue;
                    }
                    $cat_item = $cat->load_grade_item();
                    $cat_grade_obj = $cat_item ? $cat_item->get_grade($userid) : null;
                    $cat_final = ($cat_grade_obj && $cat_grade_obj->finalgrade !== null)
                        ? round((float)$cat_grade_obj->finalgrade / max((float)$cat_item->grademax, 1) * 10, 2)
                        : null;

                    $cat_name_raw = $cat->get_name();
                    $agg = (int)$cat->aggregation;
                    $agg_labels = [
                        0 => 'Media', 10 => 'Ponderada', 11 => 'Pond. simple',
                        12 => 'Extracréd.', 2 => 'Mediana', 4 => 'Mínima',
                        6 => 'Máxima', 8 => 'Moda', 13 => 'Suma',
                    ];
                    $agg_label = $agg_labels[$agg] ?? '';

                    $weight_pct = '';
                    if ($cat_item && $cat_item->aggregationcoef2 !== null && (float)$cat_item->aggregationcoef2 > 0) {
                        $weight_pct = round((float)$cat_item->aggregationcoef2 * 100, 1) . '%';
                    } elseif ($cat_item && $cat_item->aggregationcoef !== null && (float)$cat_item->aggregationcoef > 0) {
                        $weight_pct = round((float)$cat_item->aggregationcoef, 1) . '%';
                    }

                    // Extract short CE label if category name starts with "CE1.", "CE2." etc.
                    $ce_short = '';
                    $ce_fullname = $cat_name_raw;
                    if (preg_match('/^(CE\s*\d+)\.\s*/i', $cat_name_raw, $cem)) {
                        $ce_short = strtoupper($cem[1]);
                        $ce_fullname = substr($cat_name_raw, strlen($cem[0]));
                    }

                    // Match CE number to radar color, fallback to palette.
                    $ce_num = 0;
                    if ($ce_short && preg_match('/(\d+)/', $ce_short, $nm)) {
                        $ce_num = (int)$nm[1];
                    }
                    if ($ce_num && isset($cal_ce_color_map[$ce_num])) {
                        $badge_color = $cal_ce_color_map[$ce_num];
                    } elseif ($parent_color) {
                        $badge_color = $parent_color;
                    } else {
                        $badge_color = $cal_ce_palette[$cal_ce_idx % count($cal_ce_palette)];
                    }
                    if ($ce_short) { $cal_ce_idx++; }

                    // Subcategories use a lighter version of the parent CE color.
                    $is_child = $depth > 0 && $parent_color;
                    $border_color = $is_child ? ($badge_color . '60') : $badge_color;
                    $tint_bg = $badge_color . '0D';
                    $border_tint = $badge_color . '25';

                    echo '<div class="sn-cal-cat" style="border-color:' . $border_tint . ';border-left:4px solid ' . $border_color . ';--sn-cal-tint:' . $tint_bg . '">';
                    echo '<div class="sn-cal-cat-hdr" onclick="this.parentNode.classList.toggle(\'collapsed\')">';
                    echo '<span class="sn-cal-cat-arrow">▼</span>';
                    echo '<div class="sn-cal-cat-label">';
                    if ($ce_short) {
                        echo '<span class="sn-cal-cat-badge" style="background:' . $badge_color . '">'
                           . s($ce_short) . '</span>';
                        echo '<span class="sn-cal-cat-name">' . s($ce_fullname) . '</span>';
                    } else {
                        echo '<span class="sn-cal-cat-name">' . s($cat_name_raw) . '</span>';
                    }
                    echo '</div>';
                    if ($weight_pct) {
                        echo '<span class="sn-cal-pill sn-cal-pill--weight">' . $weight_pct . '</span>';
                    }
                    if ($agg_label) {
                        echo '<span class="sn-cal-pill sn-cal-pill--agg">' . $agg_label . '</span>';
                    }
                    if ($cat_final !== null) {
                        $grade_color = descriptor_calculator::grade_colour($cat_final);
                        echo '<span class="sn-cal-cat-grade" style="color:' . $grade_color . '">'
                           . number_format($cat_final, 1) . '</span>';
                    } else {
                        echo '<span class="sn-cal-cat-grade" style="color:#cbd5e1">–</span>';
                    }
                    echo '</div>';

                    // Category progress bar.
                    if ($cat_final !== null) {
                        echo '<div style="padding:0 14px 4px">' . sentinel_bar($cat_final, $grade_color, 100) . '</div>';
                    }

                    echo '<div class="sn-cal-cat-body">';
                    $render_grade_tree($children, $depth + 1, $ce_short ? $badge_color : ($parent_color ?: $badge_color));
                    echo '</div>';
                    echo '</div>';

                } elseif ($obj instanceof grade_item) {
                    $gi = $obj;
                    if ($gi->itemtype === 'course' || $gi->itemtype === 'category') {
                        continue;
                    }
                    $grade_obj = $gi->get_grade($userid);
                    $gi_final = ($grade_obj && $grade_obj->finalgrade !== null)
                        ? round((float)$grade_obj->finalgrade / max((float)$gi->grademax, 1) * 10, 2)
                        : null;

                    $gi_name = $gi->get_name(true);
                    $weight_pct = '';
                    if ($gi->aggregationcoef2 !== null && (float)$gi->aggregationcoef2 > 0) {
                        $weight_pct = round((float)$gi->aggregationcoef2 * 100, 1) . '%';
                    } elseif ($gi->aggregationcoef !== null && (float)$gi->aggregationcoef > 0) {
                        $weight_pct = round((float)$gi->aggregationcoef, 1) . '%';
                    }

                    // Build link to activity if possible.
                    $gi_link = null;
                    if ($gi->itemtype === 'mod' && $gi->itemmodule && $gi->iteminstance) {
                        $cm = get_coursemodule_from_instance($gi->itemmodule, $gi->iteminstance, $selected_courseid, false, IGNORE_MISSING);
                        if ($cm) {
                            $gi_link = (new moodle_url('/mod/' . $gi->itemmodule . '/view.php', ['id' => $cm->id]))->out(false);
                        }
                    }

                    $dot_color = '#cbd5e1';
                    $item_tint = '#f8fafc';
                    if ($gi_final !== null) {
                        $dot_color = descriptor_calculator::grade_colour($gi_final);
                        $gi_hue = (int)round(($gi_final / 10) * 120);
                        $item_tint = 'hsl(' . $gi_hue . ',55%,96%)';
                    }

                    echo '<div class="sn-cal-item" style="--sn-cal-tint:' . $item_tint . '">';
                    echo '<span class="sn-cal-item-dot" style="background:' . $dot_color . '"></span>';
                    echo '<span class="sn-cal-item-name">';
                    if ($gi_link) {
                        echo '<a href="' . $gi_link . '">' . s($gi_name) . '</a>';
                    } else {
                        echo s($gi_name);
                    }
                    echo '</span>';
                    if ($weight_pct) {
                        echo '<span class="sn-cal-pill sn-cal-pill--weight">' . $weight_pct . '</span>';
                    }
                    echo '<div class="sn-cal-item-right">';
                    if ($gi_final !== null) {
                        echo sentinel_bar($gi_final, $dot_color);
                        echo '<span class="sn-cal-item-grade" style="color:' . $dot_color . '">'
                           . number_format($gi_final, 1) . '</span>';
                    } else {
                        echo '<span class="sn-cal-item-grade" style="color:#cbd5e1">–</span>';
                    }
                    echo '</div>';
                    echo '</div>';
                }
            }
        };

        $tree_items = $grade_tree->top_element['children'] ?? [];
        if (!empty($tree_items)) {
            $render_grade_tree($tree_items);
        } else {
            echo '<div style="text-align:center;padding:2rem;color:#9ca3af;font-style:italic">'
               . 'No hay ítems de calificación en este curso.</div>';
        }

        echo '</div>'; // .sn-cal-wrap

    } else {
        echo '<div style="text-align:center;padding:2rem;color:#9ca3af;font-style:italic">'
           . 'Selecciona una materia para ver sus calificaciones.</div>';
    }

    echo '</div>'; // #sentinel-pane-calificaciones
}

echo '</div>'; // .sentinel-report

$PAGE->requires->js_call_amd('local_sentinel/descriptor_report', 'init');
echo $OUTPUT->footer();

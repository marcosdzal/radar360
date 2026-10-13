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

require_login();

$systemcontext = context_system::instance();
$student       = core_user::get_user($userid, '*', MUST_EXIST);

$iscurrentuser = ($USER->id === $userid);
if (!$iscurrentuser) {
    require_capability('local/sentinel:viewstudentreport', $systemcontext);
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

$current_period_label = get_string('report:finalgrade', 'local_sentinel')
    . ' (' . get_string('report:annual', 'local_sentinel') . ')';
if ($cutoff > 0 && !empty($periods)) {
    $n = 0;
    foreach ($periods as $p) {
        $n++;
        if ((int)$p->cutoffdate === $cutoff) {
            $current_period_label = $p->label ?: get_string('report:comm_nth', 'local_sentinel', $n);
            break;
        }
    }
}

// (Comparativa vs clase eliminada — puede dar falsa impresión de buen rendimiento.)

// ── Datos de competencias específicas (por materia) ──────────────────────────
$student_courses_all = enrol_get_users_courses($userid, true, 'id,fullname,shortname');
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
foreach ($student_courses_all as $sc) {
    if ($iscurrentuser || $is_admin || $is_tutor_user) {
        $visible_courses[$sc->id] = $sc;
    } else {
        $cctx = context_course::instance($sc->id);
        if (has_capability('mod/assign:grade', $cctx)) {
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

// ── Render ────────────────────────────────────────────────────────────────────
echo $OUTPUT->header();
?>
<style>
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
/* ── Contenedor sticky (blanco + amarillo juntos) ──────────────── */
.sn-header-wrap {
    position: sticky; top: 55px; z-index: 100;
    margin-bottom: 1.2rem;
}
/* ── Tira compacta de materia (pegada bajo el panel blanco) ─────── */
.sn-subject-summary {
    background: linear-gradient(135deg, #fffbeb 0%, #fef3c7 100%);
    border: 1px solid #fde68a; border-top: none;
    border-radius: 0 0 12px 12px;
    padding: .25rem 1.5rem;
    display: flex; align-items: center; gap: .6rem;
    box-shadow: 0 2px 4px rgba(0,0,0,.04);
}
.sn-subject-summary .sn-stat-num   { font-size: .82em; }
.sn-subject-summary .sn-stat-label { font-size: .48em; letter-spacing: .03em; }
.sn-subject-summary .sn-stat-divider { height: 18px; }
.sn-subj-name {
    font-size: .68em; font-weight: 600; color: #92400e;
    overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
    min-width: 0; flex-shrink: 1;
}
.sn-subj-select {
    font-size: .68em; font-weight: 600; color: #92400e;
    background: transparent; border: 1px solid #e5a94e; border-radius: 6px;
    padding: 1px 6px; cursor: pointer;
    flex: 1 1 auto; min-width: 80px;
}
.sn-subject-summary .sn-stats-row { flex-shrink: 0; }

/* ── Cabecera del alumno ──────────────────────────────────────────── */
.sn-student-header {
    background: #fff; border: 1px solid #e5e7eb; border-radius: 12px;
    padding: 1.1rem 1.5rem; margin-bottom: 0;
    display: flex; align-items: center; gap: 1rem;
    box-shadow: 0 1px 4px rgba(0,0,0,.06);
}
.sn-header-wrap--has-subject .sn-student-header {
    border-radius: 12px 12px 0 0; border-bottom: 1px solid #fde68a;
}
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
.sn-stats-row {
    display: flex; gap: 1.2rem; flex-shrink: 0;
    align-items: center; justify-content: flex-end;
    width: 340px;
}
.sn-stat-block { text-align: center; flex: 1 1 0; min-width: 0; }
.sn-stat-num  { font-size: 1.6em; font-weight: 800; color: #111; line-height: 1; }
.sn-stat-num.green { color: #15803d; }
.sn-stat-label { font-size: .68em; font-weight: 600; color: #9ca3af;
    letter-spacing: .06em; text-transform: uppercase; margin-top: 2px; }
.sn-stat-divider { width: 1px; background: #e5e7eb; height: 36px; }

/* ── Pestañas de periodo ──────────────────────────────────────────── */
.sn-period-tabs {
    display: flex; gap: 0; margin-bottom: 1.2rem;
    border-bottom: 2px solid #e5e7eb; flex-wrap: wrap;
}
.sn-period-tab {
    padding: 7px 18px; font-size: .82em; font-weight: 600;
    color: #6b7280; border-bottom: 2px solid transparent;
    margin-bottom: -2px; cursor: pointer; white-space: nowrap;
    text-decoration: none; transition: color .15s;
    display: flex; flex-direction: column; align-items: center; gap: 1px;
}
.sn-period-tab:hover  { color: #0d6efd; text-decoration: none; }
.sn-period-tab.active { color: #0d6efd; border-bottom-color: #0d6efd; background: none; }
.sn-period-tab .sn-tab-date { font-size: .75em; font-weight: 400; color: #9ca3af; }
.sn-period-tab.active .sn-tab-date { color: #93c5fd; }

/* ── Pestañas vista (dos grupos) ──────────────────────────────────── */
.sn-view-tabs {
    display: flex; gap: 0; justify-content: center;
    margin-bottom: 1rem;
    background: #fff; border-radius: 8px;
    border: 1px solid #e2e8f0;
    box-shadow: 0 1px 4px rgba(0,0,0,.06);
}
.sn-tab-group {
    flex: 1; display: flex; flex-direction: row; align-items: center;
    justify-content: center; gap: 8px;
    padding: 6px 10px; transition: background .25s, border-color .25s;
}
.sn-tab-group + .sn-tab-group { border-left: 1px solid #e2e8f0; }
.sn-tab-group-label {
    font-size: .72em; font-weight: 700; text-transform: uppercase;
    letter-spacing: .04em; color: #94a3b8;
    transition: color .25s; white-space: nowrap;
}
/* Grupo clave: siempre gris-azulado, más vivo cuando activo */
.sn-tab-group[data-group="key"] { background: #f6f8fb; }
.sn-tab-group[data-group="key"] .sn-tab-group-label { color: #8b9bb5; }
.sn-tab-group.sn-group-active-key { background: #e8edf5; }
.sn-tab-group.sn-group-active-key .sn-tab-group-label { color: #3b5998; font-weight: 800; }
/* Grupo específicas: siempre amarillo, más vivo cuando activo */
.sn-tab-group[data-group="specific"] { background: #fefbf3; }
.sn-tab-group[data-group="specific"] .sn-tab-group-label { color: #c4a44a; }
.sn-tab-group.sn-group-active-specific { background: #fdf0c8; }
.sn-tab-group.sn-group-active-specific .sn-tab-group-label { color: #92400e; font-weight: 800; }
.sn-tab-group-btns { display: flex; gap: 3px; }
.sn-view-tab {
    padding: 4px 12px; font-size: .8em; font-weight: 600;
    color: #64748b; cursor: pointer; white-space: nowrap;
    background: none; border: none; border-radius: 6px;
    transition: all .2s ease;
    display: inline-flex; align-items: center; gap: 4px;
}
.sn-view-tab svg { filter: grayscale(1) opacity(.45); transition: filter .2s; width: 16px; height: 16px; }
.sn-view-tab:hover svg { filter: grayscale(.5) opacity(.7); }
.sn-view-tab.active svg { filter: none; }
.sn-view-tab:hover { color: #334155; background: #e2e8f020; }
.sn-view-tab.active {
    color: #1e40af; background: #fff;
    box-shadow: 0 1px 3px rgba(0,0,0,.1), 0 0 0 1px rgba(0,0,0,.04);
}
.sn-view-pane { display: none; }
.sn-view-pane.active { display: block; }
#sentinel-pane-diana, #sentinel-pane-subject-diana {
    background: #fff; border-radius: 12px; padding: 1rem; margin: 0 -.5rem;
}

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

/* ── Leyenda niveles (barra compacta) ─────────────────────────────── */
.sn-level-legend {
    background: #f8fafc; border: 1px solid #e5e7eb; border-radius: 8px;
    padding: .4rem .8rem; margin-bottom: .8rem;
    font-size: .85em; color: #6b7280;
    display: flex; align-items: center; gap: .5rem; flex-wrap: wrap;
}
.sn-norm-select {
    font-size: 1em; padding: 2px 6px; border: 1px solid #d1d5db;
    border-radius: 5px; background: #fff; cursor: pointer;
    color: #374151; outline: none; transition: border-color .15s;
}
.sn-norm-select:focus { border-color: #0d6efd; }
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
.sn-period-label { font-size: .65em; color: #c4c8cc; }
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
echo '<div class="sn-level-legend">';
if (!empty($periods)) {
    $baseurl = new moodle_url('/local/sentinel/student_report.php',
        array_filter(['userid' => $userid, 'courseid' => $selected_courseid]));
    echo '<select class="sn-norm-select" onchange="window.location.href=this.value">';
    $n = 0;
    foreach ($periods as $p) {
        $n++;
        $taburl  = new moodle_url($baseurl, ['cutoff' => $p->cutoffdate, 'norm' => $norm]);
        $datestr = userdate($p->cutoffdate, get_string('strftimedatefullshort', 'langconfig'));
        $label   = get_string('report:comm_nth', 'local_sentinel', $n);
        $sel     = ($cutoff == $p->cutoffdate) ? ' selected' : '';
        echo '<option value="' . $taburl->out(false) . '"' . $sel . '>'
           . s($p->label ?: $label) . ' — ' . $datestr . '</option>';
    }
    $sel = ($cutoff == 0) ? ' selected' : '';
    $allurl = new moodle_url($baseurl, ['norm' => $norm]);
    echo '<option value="' . $allurl->out(false) . '"' . $sel . '>'
       . get_string('report:finalgrade', 'local_sentinel') . ' ('
       . get_string('report:annual', 'local_sentinel') . ')</option>';
    echo '</select>';
}
foreach ($tnorm['levels'] as $l) {
    echo '<span class="sentinel-level-badge snl-' . $l['key'] . '">'
       . $l['text'] . ' (' . $l['range'] . ')</span>';
}
echo '<a href="' . $exporturl->out(false) . '" class="sn-csv-btn" title="Exportar CSV">'
   . '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">'
   . '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/>'
   . '</svg> CSV</a>';
echo '</div>';

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
$wrap_cls = 'sn-header-wrap' . ($has_subject ? ' sn-header-wrap--has-subject' : '');
echo '<div class="' . $wrap_cls . '">';
echo '<div class="sn-student-header">';
if ($sentinel_photo_url) {
    echo '<img src="' . $sentinel_photo_url . '" class="sn-avatar sn-avatar-photo"'
       . ' alt="' . s(fullname($student)) . '"'
       . ' onerror="this.style.display=\'none\';this.nextElementSibling.style.display=\'flex\'">';
    echo '<div class="sn-avatar" style="display:none">' . s($initials) . '</div>';
} else {
    echo '<div class="sn-avatar">' . s($initials) . '</div>';
}
echo '<div>';
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

// Stats globales (badge + números, alineados a la derecha).
echo '<div style="margin-left:auto;text-align:right;position:relative;padding-top:14px;flex-shrink:0">';
echo '<span class="sn-header-tag sn-header-tag-key" style="position:absolute;top:-2px;right:0">'
   . get_string('report:tab_group_key', 'local_sentinel') . '</span>';
echo '<div class="sn-stats-row">';
if ($media_global !== null) {
    $gcolor = descriptor_calculator::grade_colour($media_global);
    echo '<div class="sn-stat-block">';
    echo '<div class="sn-stat-num" style="color:' . $gcolor . '">' . number_format($media_global, 1) . '</div>';
    echo '<div class="sn-stat-label">MEDIA (EVALUADO)</div>';
    if ($lomloe_global !== null && $lomloe_global < $media_global - 0.05) {
        $lgcolor = descriptor_calculator::grade_colour($lomloe_global);
        echo '<div style="font-size:.72em;font-weight:700;color:' . $lgcolor . ';margin-top:2px;letter-spacing:.02em">'
           . 'LOMLOE: ' . number_format($lomloe_global, 1) . '</div>';
    }
    echo '</div>';
    echo '<div class="sn-stat-divider"></div>';
}
echo '<div class="sn-stat-block">';
echo '<div class="sn-stat-num">' . $summary['worked_descriptors'] . '<span style="font-size:.55em;color:#9ca3af">/' . $summary['total_descriptors'] . '</span></div>';
echo '<div class="sn-stat-label">' . get_string('report:descriptors_label', 'local_sentinel') . '</div>';
echo '</div>';
echo '<div class="sn-stat-divider"></div>';
echo '<div class="sn-stat-block">';
echo '<div class="sn-stat-num">' . $summary['total_occasions'] . '</div>';
echo '<div class="sn-stat-label">' . get_string('report:occasions_label', 'local_sentinel') . '</div>';
echo '</div>';
echo '</div>'; // .sn-stats-row
echo '</div>'; // wrapper margin-left:auto
echo '</div>'; // .sn-student-header

// ── Tira compacta de competencias específicas (pegada al panel blanco) ─────
if (!empty($visible_courses) && $ce_data && !empty($ce_data['ces'])) {
    $ce_total_criteria = 0;
    $ce_total_worked   = 0;
    $ce_total_occ      = 0;
    $ce_days_set       = [];
    foreach ($ce_data['ces'] as $ce) {
        $ce_total_criteria += $ce['criteria_total'];
        $ce_total_worked   += $ce['criteria_worked'];
        $ce_total_occ      += $ce['occasions'];
        foreach ($ce['criteria'] as $cr) {
            foreach ($cr['activities'] as $act) {
                if (!empty($act['gradetime'])) {
                    $ce_days_set[date('Y-m-d', $act['gradetime'])] = true;
                }
            }
        }
    }
    $ce_total_days = count($ce_days_set);
    echo '<div class="sn-subject-summary">';
    echo '<span class="sn-header-tag sn-header-tag-specific">'
       . get_string('report:tab_group_specific', 'local_sentinel') . '</span>';
    if (count($visible_courses) > 1) {
        echo '<select class="sn-subj-select" onchange="sentinelChangeCourse(this.value)">';
        foreach ($visible_courses as $c) {
            $sel = ((int)$c->id === $selected_courseid) ? ' selected' : '';
            echo '<option value="' . (int)$c->id . '"' . $sel . '>'
               . s($c->fullname) . '</option>';
        }
        echo '</select>';
    } else {
        $vc = reset($visible_courses);
        echo '<span class="sn-subj-name">' . s($vc->fullname) . '</span>';
    }
    echo '<div class="sn-stats-row" style="margin-left:auto">';
    $sg = $ce_data['subject_grade'];
    if ($sg !== null) {
        $sgc = descriptor_calculator::grade_colour($sg);
        echo '<div class="sn-stat-block">';
        echo '<div class="sn-stat-num" style="color:' . $sgc . '">' . number_format($sg, 1) . '</div>';
    } else {
        echo '<div class="sn-stat-block">';
        echo '<div class="sn-stat-num" style="color:#ccc">—</div>';
    }
    echo '<div class="sn-stat-label">' . get_string('report:subject_grade', 'local_sentinel') . '</div>';
    echo '</div>';
    echo '<div class="sn-stat-divider"></div>';
    echo '<div class="sn-stat-block">';
    echo '<div class="sn-stat-num">' . $ce_total_worked . '<span style="font-size:.55em;color:#9ca3af">/' . $ce_total_criteria . '</span></div>';
    echo '<div class="sn-stat-label">CRITERIOS</div>';
    echo '</div>';
    echo '<div class="sn-stat-divider"></div>';
    echo '<div class="sn-stat-block">';
    echo '<div class="sn-stat-num">' . $ce_total_occ . '</div>';
    echo '<div class="sn-stat-label">OCASIONES</div>';
    echo '</div>';
    echo '</div>'; // .sn-stats-row
    echo '</div>'; // .sn-subject-summary
}
echo '</div>'; // .sn-header-wrap

// (Legend bar moved to top of .sentinel-report)

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

echo '<div class="sn-view-tabs">';

// ── Grupo 1: Competencias clave (fondo gris azulado) ──
echo '<div class="sn-tab-group" data-group="key" style="border-radius:8px 0 0 8px">';
echo '<div class="sn-tab-group-label">'
   . get_string('report:tab_group_key', 'local_sentinel') . '</div>';
echo '<div class="sn-tab-group-btns">';
echo '<button class="sn-view-tab active" data-tab="diana" onclick="sentinelViewTab(\'diana\')" type="button">'
   . $key_diana_svg . get_string('report:tab_diana', 'local_sentinel') . '</button>';
echo '<button class="sn-view-tab" data-tab="grid" onclick="sentinelViewTab(\'grid\')" type="button">'
   . $key_cards_svg . get_string('report:tab_grid', 'local_sentinel') . '</button>';
echo '</div></div>';

// ── Grupo 2: Competencias específicas (fondo ámbar claro) ──
if (!empty($visible_courses)) {
    echo '<div class="sn-tab-group" data-group="specific" style="border-radius:0 8px 8px 0">';
    echo '<div class="sn-tab-group-label">'
       . get_string('report:tab_group_specific', 'local_sentinel') . '</div>';
    echo '<div class="sn-tab-group-btns">';
    echo '<button class="sn-view-tab" data-tab="subject-diana" onclick="sentinelViewTab(\'subject-diana\')" type="button">'
       . $spec_diana_svg . get_string('report:tab_subject_diana', 'local_sentinel') . '</button>';
    echo '<button class="sn-view-tab" data-tab="subject-grid" onclick="sentinelViewTab(\'subject-grid\')" type="button">'
       . $spec_cards_svg . get_string('report:tab_subject_grid', 'local_sentinel') . '</button>';
    echo '</div></div>';
}

echo '</div>';

// ── PANE 1: Cuadrícula de áreas ─────────────────────────────────────────────
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
        echo '<span class="sn-ck-grade" style="color:' . $gcolor . '">' . number_format($adata['partial_grade'], 1) . '</span>';
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
    ]);

    echo '<div id="sentinel-pane-diana" class="sn-view-pane active">';
    echo '<div style="display:flex;justify-content:center;align-items:flex-start;gap:32px;flex-wrap:wrap;padding-top:16px">';
    echo '<div style="max-width:600px;width:100%;position:relative;flex:1 1 auto;min-width:300px">';
    echo '<canvas id="sentinel-radar" data-radar="' . s($radar_data_full) . '" style="width:100%;max-height:560px"></canvas>';
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
    echo '</div>';
    echo '</div>'; // #sentinel-pane-diana
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
        ]);
        echo '<div style="display:flex;justify-content:center;align-items:flex-start;gap:32px;flex-wrap:wrap;padding-top:16px">';
        echo '<div style="max-width:560px;width:100%;position:relative;flex:1 1 auto;min-width:280px">';
        echo '<canvas id="sentinel-subject-radar" data-subjectradar="' . s($subject_radar_json) . '"'
           . ' style="width:100%;max-height:520px"></canvas>';
        echo '</div>';
        echo '</div>';

        // (CE cards only in the "Tarjetas" pane, not here in diana.)
    } else {
        echo '<div style="text-align:center;padding:2rem;color:#9ca3af;font-style:italic">'
           . get_string('report:no_ce_data', 'local_sentinel') . '</div>';
    }
    echo '</div>'; // #sentinel-pane-subject-diana
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
                echo '<span class="sn-ck-grade" style="color:' . $gc . '">' . number_format($ce['grade'], 1) . '</span>';
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
            echo '<span style="font-weight:700;color:' . $gc . ';font-size:.95em">' . number_format($ce['grade'], 1) . '</span>';
            echo '<small class="text-muted">'
               . $ce['criteria_worked'] . '/' . $ce['criteria_total'] . ' '
               . $ce_crit_word . '</small>';
        }
        echo '</h4>';
        echo '<div style="font-size:.82em;color:#4b5563;line-height:1.4;margin-bottom:.6rem">'
           . s($ce_full_name) . '</div>';
        echo '<div style="margin-bottom:.9rem">'
           . '<span style="font-size:.78em;color:#6b7280;background:#f1f5f9;padding:3px 10px;border-radius:6px">'
           . s($current_period_label) . '</span></div>';

        if (!empty($ce['criteria'])) {
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
                    echo '<span class="sn-dgrade" style="color:' . descriptor_calculator::grade_colour($crit['grade']) . '">'
                       . number_format($crit['grade'], 1) . '</span>';
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
        echo '&nbsp;&nbsp;<span style="font-weight:700;color:' . $pg_color . '">' . number_format($adata['partial_grade'], 1) . '</span>';
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
    echo '<div style="margin-bottom:.9rem">'
       . '<span style="font-size:.78em;color:#6b7280;background:#f1f5f9;padding:3px 10px;border-radius:6px">'
       . s($current_period_label) . '</span></div>';

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
            echo '<span class="sn-dgrade" style="color:' . descriptor_calculator::grade_colour($ddata['grade']) . '">'
               . number_format($ddata['grade'], 1) . '</span>';
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

            // Descripción
            echo '<div class="sn-desc-text" style="margin-top:5px">' . s(shorten_text($ddata['description'], 130)) . '</div>';

            // Footer: enlace al detalle
            if (!empty($ddata['courses'])) {
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
            $rubricurl = new moodle_url('/mod/assign/view.php', [
                'id' => $act['cmid'], 'userid' => $userid, 'action' => 'grader',
            ]);
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

echo '</div>'; // .sentinel-report

$PAGE->requires->js_call_amd('local_sentinel/descriptor_report', 'init');
echo $OUTPUT->footer();

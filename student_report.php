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

$PAGE->set_context($systemcontext);
$PAGE->set_url(new moodle_url('/local/sentinel/student_report.php', ['userid' => $userid]));
$PAGE->set_title(get_string('report:title', 'local_sentinel') . ' — ' . fullname($student));
$PAGE->set_heading(get_string('report:title', 'local_sentinel'));
$PAGE->set_pagelayout('standard');

// ── Breadcrumb ────────────────────────────────────────────────────────────────
$courses_nav = enrol_get_users_courses($userid, true, 'id,shortname,fullname');
if (!empty($courses_nav)) {
    $first = reset($courses_nav);
    $PAGE->navbar->add($first->shortname, new moodle_url('/course/view.php', ['id' => $first->id]));
    if (count($courses_nav) > 1) {
        // If enrolled in more than one course, link to the gradebook of the first.
        $PAGE->navbar->add(
            get_string('grades', 'grades'),
            new moodle_url('/grade/report/index.php', ['id' => $first->id])
        );
    }
}
$PAGE->navbar->add(fullname($student), new moodle_url('/user/profile.php', ['id' => $userid]));
$PAGE->navbar->add(get_string('report:title', 'local_sentinel'));

// ── Datos principales ─────────────────────────────────────────────────────────
$cutoffdate  = $cutoff > 0 ? $cutoff : null;
$data        = descriptor_calculator::get_student_data($userid, null, $cutoffdate);
$areas       = $data['areas'];
$periods     = $data['periods'];
$summary     = $data['summary'];

// Media de clase por descriptor para el indicador "+X vs clase".
$class_avgs = descriptor_calculator::get_class_averages($userid, $cutoffdate ?: null);

// Pre-calcular notas por periodo para el historial de cada descriptor.
$period_grades = []; // [period_id][dcode] = nota
foreach ($periods as $p) {
    $pd = descriptor_calculator::get_student_data($userid, null, (int)$p->cutoffdate);
    foreach ($pd['areas'] as $adata) {
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
.sentinel-report { font-family: inherit; max-width: 1100px; margin: 0 auto; }
.sentinel-panel  { display: none; }
.sentinel-panel.active { display: block; }


/* ── Cabecera del alumno ──────────────────────────────────────────── */
.sn-student-header {
    background: #fff; border: 1px solid #e5e7eb; border-radius: 12px;
    padding: 1.1rem 1.5rem; margin-bottom: 1.4rem;
    display: flex; align-items: center; gap: 1rem;
    box-shadow: 0 1px 4px rgba(0,0,0,.06);
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
}
.sn-student-header .sn-name   { font-size: 1.15em; font-weight: 700; color: #111; }
.sn-student-header .sn-meta   { font-size: .82em; color: #6b7280; margin-top: 2px; }
.sn-stats-row {
    display: flex; gap: 2rem; margin-left: auto; flex-shrink: 0;
    align-items: center;
}
.sn-stat-block { text-align: center; }
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

/* ── Leyenda niveles ──────────────────────────────────────────────── */
.sn-level-legend {
    background: #f8fafc; border: 1px solid #e5e7eb; border-radius: 8px;
    padding: .55rem 1rem; margin-bottom: 1.2rem;
    font-size: .79em; color: #6b7280;
    display: flex; align-items: center; gap: .8rem; flex-wrap: wrap;
}
.sn-level-legend strong { color: #374151; }
.sn-norm-select {
    font-size: .8em; padding: 4px 8px; border: 1.5px solid #d1d5db;
    border-radius: 6px; background: #fff; cursor: pointer;
    color: #374151; outline: none; transition: border-color .15s;
}
.sn-norm-select:focus { border-color: #0d6efd; }
.sn-norm-sep { width: 1px; background: #e5e7eb; height: 20px; align-self: center; }

/* ── Badges de nivel ──────────────────────────────────────────────── */
.sentinel-level-badge {
    display: inline-block; font-size: .72em; font-weight: 700;
    padding: 2px 9px; border-radius: 20px; letter-spacing: .02em;
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
.sn-d-cmp  { font-size:.72em; font-weight:700; }
.sn-d-cmp.up   { color:#16a34a; }
.sn-d-cmp.down { color:#dc2626; }
.sn-d-cmp.eq   { color:#9ca3af; }
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

echo html_writer::start_tag('div', [
    'class'      => 'sentinel-report',
    'data-title' => get_string('report:title', 'local_sentinel'),
]);



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
       .     'window.location.href="' . (new moodle_url('/local/sentinel/student_report.php', ['cutoff' => $cutoff, 'norm' => $norm]))->out(false) . '&userid="+this.value;'
       .   '}'
       . '});'
       . '</script>';
}

// Tarjeta de cabecera.
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
$metaparts = [];
if ($summary['worked_descriptors'] > 0) {
    $metaparts[] = $summary['total_occasions'] . ' ocasiones de evaluación';
}
if (!empty($metaparts)) {
    echo '<div class="sn-meta">' . implode(' &nbsp;·&nbsp; ', $metaparts) . '</div>';
}
echo '</div>';

// Stats globales.
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
echo '</div>'; // .sn-student-header

// ── Pestañas / selector de periodo ───────────────────────────────────────────
if (!empty($periods)) {
    $baseurl  = new moodle_url('/local/sentinel/student_report.php', ['userid' => $userid]);
    $allurl   = new moodle_url($baseurl);
    $use_tabs = count($periods) <= 4;   // > 4 periodos → desplegable

    if ($use_tabs) {
        echo '<div class="sn-period-tabs">';
        $n = 0;
        foreach ($periods as $p) {
            $n++;
            $taburl  = new moodle_url($baseurl, ['cutoff' => $p->cutoffdate]);
            $isact   = ($cutoff == $p->cutoffdate) ? ' active' : '';
            $datestr = userdate($p->cutoffdate, get_string('strftimedatefullshort', 'langconfig'));
            $label   = get_string('report:comm_nth', 'local_sentinel', $n);
            echo '<a href="' . $taburl->out(false) . '" class="sn-period-tab' . $isact . '">'
               . s($p->label ?: $label)
               . '<span class="sn-tab-date">' . $datestr . '</span>'
               . '</a>';
        }
        $isact = ($cutoff == 0) ? ' active' : '';
        echo '<a href="' . $allurl->out(false) . '" class="sn-period-tab' . $isact . '">'
           . get_string('report:finalgrade', 'local_sentinel')
           . '<span class="sn-tab-date">' . get_string('report:annual', 'local_sentinel') . '</span>'
           . '</a>';
        echo '</div>';
    } else {
        // Muchos periodos → desplegable compacto
        echo '<div style="display:flex;align-items:center;gap:.6rem;margin-bottom:1.2rem;flex-wrap:wrap">';
        echo '<span style="font-size:.8em;font-weight:600;color:#6b7280">Comunicación:</span>';
        echo '<select class="sn-norm-select" onchange="window.location.href=this.value"'
           . ' style="font-size:.84em;padding:5px 10px">';
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
        echo '<option value="' . $allurl->out(false) . '"' . $sel . '>'
           . get_string('report:finalgrade', 'local_sentinel') . ' ('
           . get_string('report:annual', 'local_sentinel') . ')</option>';
        echo '</select>';
        echo '</div>';
    }
}

// ── Leyenda de niveles con selector de normativa ─────────────────────────────
$baseurl_norm = new moodle_url('/local/sentinel/student_report.php',
    ['userid' => $userid, 'cutoff' => $cutoff]);
$exporturl = new moodle_url('/local/sentinel/student_report.php', ['userid' => $userid, 'cutoff' => $cutoff, 'norm' => $norm, 'export' => 'csv']);
echo '<div class="sn-level-legend">';
echo '<select class="sn-norm-select" onchange="window.location.href=this.value">';
foreach ($norm_configs as $nk => $nc) {
    $nurl = new moodle_url($baseurl_norm, ['norm' => $nk]);
    $sel  = ($nk === $norm) ? ' selected' : '';
    echo '<option value="' . $nurl->out(false) . '"' . $sel . '>' . $nc['label'] . '</option>';
}
echo '</select>';
echo '<div class="sn-norm-sep"></div>';
foreach ($tnorm['levels'] as $l) {
    echo '<span class="sentinel-level-badge snl-' . $l['key'] . '">'
       . $l['text'] . ' (' . $l['range'] . ')</span>';
}
echo html_writer::link($exporturl, get_string('report:exportcsv', 'local_sentinel'),
    ['class' => 'btn btn-outline-secondary btn-sm', 'style' => 'margin-left:auto']);
echo '</div>';

if ($summary['total_occasions'] === 0) {
    echo $OUTPUT->notification(get_string('report:nodata', 'local_sentinel'), 'info');
}

// ────────────────────────────────────────────────────────────────────────────
// NIVEL 1 — Cuadrícula de áreas
// ────────────────────────────────────────────────────────────────────────────
echo '<div id="sentinel-l1" class="sentinel-panel active">';
echo '<div class="sn-section-title">' . get_string('report:section_areas', 'local_sentinel') . '</div>';
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

    // Nota global LOMLOE (denominador = total, incluye sin trabajar = 0)
    $incomplete = $adata['worked'] < $adata['total'];
    $gk_col = $incomplete ? '#dc2626' : descriptor_calculator::grade_colour($adata['grade']);
    echo '<div class="sn-ck-global">';
    if ($adata['worked'] > 0) {
        echo '<span style="font-weight:700;color:' . $gk_col . '">Global: ' . number_format($adata['grade'], 1) . '</span>';
        echo ' <span style="color:#9ca3af">' . $adata['worked'] . '/' . $adata['total'] . ' desc.</span>';
    } else {
        echo '<span style="color:#d1d5db">0/' . $adata['total'] . ' desc.</span>';
    }
    echo '</div>';

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
        echo '<span class="sn-ck-stat"><b>' . $ncourses . '</b> ' . ($ncourses === 1 ? 'materia' : 'materias') . '</span>';
    }
    echo '</div>';
    if (!empty($adata['courses'])) {
        echo '<div class="sn-ck-courses">' . s(implode(' · ', $adata['courses'])) . '</div>';
    }

    echo '</div>'; // .sentinel-area-card
}

echo '</div>'; // .sentinel-area-grid
echo '</div>'; // #sentinel-l1

// ────────────────────────────────────────────────────────────────────────────
// NIVEL 2 — Descriptores de un área
// ────────────────────────────────────────────────────────────────────────────
foreach ($areas as $acode => $adata) {
    echo '<div id="sentinel-l2-' . $acode . '" class="sentinel-panel"'
       . ' data-area-code="' . s($acode) . '"'
       . ' data-area-name="' . s($adata['name']) . '">';

    echo '<button class="sentinel-back" onclick="sentinelBack(\'l1\')" type="button">'
       . get_string('report:back', 'local_sentinel') . '</button>';

    $areadef = descriptor_calculator::AREAS[$acode];
    // Cabecera del área.
    echo '<h4 style="margin-bottom:.9rem">';
    echo '<span style="color:' . $areadef['color'] . ';font-weight:700">' . s($acode) . '</span> ';
    echo s($areadef['name']);
    if ($adata['partial_grade'] !== null) {
        echo '&nbsp;&nbsp;' . sentinel_badge($adata['partial_grade'], 'lg');
        $ck_color = descriptor_calculator::grade_colour($adata['grade']);
        echo '&nbsp;&nbsp;<small class="text-muted">'
           . $adata['worked'] . '/' . $adata['total'] . ' desc.&nbsp;·&nbsp;Global:&nbsp;'
           . '<span style="color:' . $ck_color . ';font-weight:700">' . number_format($adata['grade'], 1) . '</span>'
           . '</small>';
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
            echo '<span class="sn-dgrade" style="color:' . descriptor_calculator::grade_colour($ddata['grade']) . '">'
               . number_format($ddata['grade'], 1) . '</span>';
            echo '<span class="sn-docc">' . $ddata['occasions'] . ' ' . ($ddata['occasions'] === 1 ? 'ocasión' : 'ocasiones') . '</span>';
            echo '</div>';
            echo '</div>';
            // Derecha: historial por comunicación + vs clase + nivel
            echo '<div class="sn-dt-r">';
            if (!empty($periods) && !empty($period_grades)) {
                $n_p  = count($periods);
                $bw   = 10; $bgap = 3; $maxh = 22;
                $svgw = $n_p * ($bw + $bgap) - $bgap;
                $svgh = $maxh + 8;
                echo '<svg width="' . $svgw . '" height="' . $svgh . '" style="display:block;overflow:visible" title="Evolución por comunicación">';
                $i = 0;
                foreach ($periods as $p) {
                    $pg = $period_grades[$p->id][$dcode] ?? null;
                    $bx = $i * ($bw + $bgap);
                    $plabel = $p->label ?: (($i + 1) . '.ª comunicación');
                    if ($pg !== null) {
                        $bh = max(3, (int)round($pg / 10 * $maxh));
                        $by = $maxh - $bh + 2;
                        $bc = descriptor_calculator::grade_colour($pg);
                        echo '<g><rect x="' . $bx . '" y="' . $by . '" width="' . $bw . '" height="' . $bh . '" rx="2" fill="' . $bc . '"/>'
                           . '<title>' . s($plabel) . ': ' . number_format($pg, 1) . '</title></g>';
                    } else {
                        echo '<g><rect x="' . $bx . '" y="' . ($maxh + 2) . '" width="' . $bw . '" height="2" rx="1" fill="#e5e7eb"/>'
                           . '<title>' . s($plabel) . ': sin datos</title></g>';
                    }
                    $cx = $bx + $bw / 2;
                    echo '<text x="' . $cx . '" y="' . ($svgh - 1) . '" text-anchor="middle" font-size="7" fill="#9ca3af">' . ($i + 1) . '</text>';
                    $i++;
                }
                echo '</svg>';
            }
            if (isset($class_avgs[$dcode])) {
                $diff = round($ddata['grade'] - $class_avgs[$dcode], 1);
                $cls  = $diff > 0 ? 'up' : ($diff < 0 ? 'down' : 'eq');
                $sign = $diff > 0 ? '+' : '';
                echo '<div class="sn-d-cmp ' . $cls . '">'
                   . $sign . number_format($diff, 1) . ' vs clase</div>';
            }
            echo sentinel_level_badge($ddata['grade']);
            echo '</div>'; // .sn-dt-r
            echo '</div>'; // .sn-dt

            // Barra de progreso (ancho completo)
            echo sentinel_bar($ddata['grade'], descriptor_calculator::grade_colour($ddata['grade']), 100);

            // Descripción
            echo '<div class="sn-desc-text" style="margin-top:5px">' . s(shorten_text($ddata['description'], 130)) . '</div>';

            // Materias
            if (!empty($ddata['courses'])) {
                echo '<div class="sn-d-courses">' . s(implode(' · ', $ddata['courses'])) . '</div>';
            }

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

        echo '<button class="sentinel-back" onclick="sentinelBack(\'l2-' . $acode . '\')" type="button">'
           . get_string('report:back', 'local_sentinel') . '</button>';

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

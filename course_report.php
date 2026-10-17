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
 * Course-level Radar 360 summary: aggregated competency overview + student list.
 *
 * @package    local_sentinel
 * @copyright  2024 Marcos
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/local/sentinel/classes/descriptor_calculator.php');

use local_sentinel\descriptor_calculator;

$courseid = required_param('courseid', PARAM_INT);

$course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
$context = context_course::instance($courseid);
require_login($course);

$canview = has_capability('local/sentinel:viewstudentreport', $context)
        || has_capability('local/sentinel:viewstudentreport', context_system::instance());
if (!$canview) {
    require_capability('local/sentinel:viewstudentreport', $context);
}

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/sentinel/course_report.php', ['courseid' => $courseid]));
$PAGE->set_title(get_string('report:title', 'local_sentinel') . ' — ' . $course->fullname);
$PAGE->set_heading($course->fullname);
$PAGE->set_pagelayout('incourse');
$PAGE->set_secondary_active_tab('coursehome');
$PAGE->navbar->add(get_string('pluginname', 'local_sentinel'));

// ── Enrolled students with student role ──────────────────────────────────────
$role_student = $DB->get_record('role', ['shortname' => 'student']);
$students = [];
if ($role_student) {
    $sql = "SELECT DISTINCT u.id, u.firstname, u.lastname,
                   u.firstnamephonetic, u.lastnamephonetic,
                   u.alternatename, u.middlename, u.email, u.picture,
                   u.imagealt
            FROM {user} u
            JOIN {role_assignments} ra ON ra.userid = u.id
            JOIN {context} ctx ON ctx.id = ra.contextid
            WHERE ra.roleid = :roleid
              AND ctx.contextlevel = :ctxlevel
              AND ctx.instanceid = :courseid
              AND u.deleted = 0 AND u.suspended = 0
            ORDER BY u.lastname, u.firstname";
    $students = $DB->get_records_sql($sql, [
        'roleid'   => $role_student->id,
        'ctxlevel' => CONTEXT_COURSE,
        'courseid' => $courseid,
    ]);
}

// ── Compute per-student data for the course ──────────────────────────────────
$all_descriptors = $DB->get_records('local_sentinel_descriptor', null, 'sortorder ASC');
$area_keys = [];
foreach ($all_descriptors as $d) {
    $area_keys[$d->areacode] = $d->areacode;
}

$student_rows = [];
foreach ($students as $st) {
    $data = descriptor_calculator::get_student_data($st->id, $courseid);
    $areas = $data['areas'] ?? [];
    $summary = $data['summary'] ?? ['total_occasions' => 0, 'total_descriptors_worked' => 0];
    $grade = null;
    $worked = 0;
    $total  = 0;
    $occasions = $summary['total_occasions'];
    foreach ($areas as $ac => $ad) {
        $worked += $ad['worked'];
        $total  += $ad['total'];
    }
    if ($worked > 0) {
        $sum = 0;
        foreach ($areas as $ad) {
            if ($ad['worked'] > 0 && $ad['partial_grade'] !== null) {
                $sum += $ad['partial_grade'];
            }
        }
        $areas_with_data = array_filter($areas, fn($a) => $a['worked'] > 0);
        $grade = count($areas_with_data) > 0 ? $sum / count($areas_with_data) : null;
    }
    $repr = descriptor_calculator::get_representativity($st->id, $courseid);
    $student_rows[] = [
        'user'       => $st,
        'grade'      => $grade,
        'worked'     => $worked,
        'total'      => $total,
        'occasions'  => $occasions,
        'areas'      => $areas,
        'repr'       => $repr,
    ];
}

// ── Sentinel photo map (teacher-uploaded avatars, private) ──────────────────
$sentinel_photo_map = [];
$cfg_photos = get_config('local_sentinel', 'photoenabled');
if ($cfg_photos === false || (bool)$cfg_photos) {
    $sids = array_keys($students);
    if (!empty($sids)) {
        $photos_raw = $DB->get_records_list('local_sentinel_student_photo', 'studentid', $sids);
        foreach ($photos_raw as $rec) {
            $sentinel_photo_map[(int)$rec->studentid] = (new moodle_url(
                '/local/sentinel/photo.php',
                ['action' => 'serve', 'studentid' => $rec->studentid]
            ))->out(false);
        }
    }
}

// ── Aggregated area averages ─────────────────────────────────────────────────
$area_aggregates = [];
foreach ($area_keys as $ac) {
    $grades = [];
    foreach ($student_rows as $sr) {
        if (isset($sr['areas'][$ac]) && $sr['areas'][$ac]['worked'] > 0
            && $sr['areas'][$ac]['partial_grade'] !== null) {
            $grades[] = $sr['areas'][$ac]['partial_grade'];
        }
    }
    $area_aggregates[$ac] = [
        'count' => count($grades),
        'avg'   => count($grades) > 0 ? array_sum($grades) / count($grades) : null,
    ];
}

echo $OUTPUT->header();
?>
<style>
.cr-wrap { max-width: 1100px; margin: 0 auto; padding: 1rem; }
.cr-title-row {
    display: flex; align-items: center; gap: 12px; margin-bottom: 1.5rem;
}
.cr-title-row h2 { margin: 0; font-size: 1.4em; font-weight: 800; color: #1a1a2e; }
.cr-badge {
    font-size: .72em; font-weight: 700; padding: 3px 10px; border-radius: 20px;
    background: linear-gradient(135deg, #495057, #343a40); color: #fff;
    letter-spacing: .03em;
}

/* ── Radar summary card ──────────────────────────────────────── */
.cr-radar-card {
    background: #fff; border: 1px solid #e5e7eb; border-radius: 16px;
    padding: 1.5rem; margin-bottom: 1.5rem;
    box-shadow: 0 2px 8px rgba(0,0,0,.04);
}
.cr-radar-grid {
    display: grid; grid-template-columns: 320px 1fr; gap: 2rem; align-items: start;
}
@media (max-width: 720px) { .cr-radar-grid { grid-template-columns: 1fr; } }
.cr-radar-canvas-wrap { display: flex; justify-content: center; }
.cr-area-bars { display: flex; flex-direction: column; gap: 8px; }
.cr-bar-row { display: flex; align-items: center; gap: 10px; }
.cr-bar-label {
    width: 40px; font-size: .78em; font-weight: 700; text-align: center;
    padding: 2px 0; border-radius: 4px; color: #fff; flex-shrink: 0;
}
.cr-bar-track {
    flex: 1; height: 22px; background: #f1f3f5; border-radius: 6px;
    position: relative; overflow: hidden;
}
.cr-bar-fill {
    height: 100%; border-radius: 6px; transition: width .6s ease;
    position: relative;
}
.cr-bar-fill span {
    position: absolute; right: 6px; top: 50%; transform: translateY(-50%);
    font-size: .72em; font-weight: 700; color: #fff;
    text-shadow: 0 1px 2px rgba(0,0,0,.3);
}
.cr-bar-count {
    font-size: .72em; color: #9ca3af; width: 50px; text-align: right; flex-shrink: 0;
}

/* ── Students grid ───────────────────────────────────────────── */
.cr-students-title {
    font-size: 1.05em; font-weight: 700; color: #495057; margin-bottom: .8rem;
    display: flex; align-items: center; gap: 8px;
}
.cr-students-count {
    font-size: .78em; font-weight: 600; background: #e9ecef;
    color: #495057; padding: 2px 8px; border-radius: 10px;
}
.cr-student-grid {
    display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 10px;
}
.cr-student-card {
    display: flex; align-items: center; gap: 12px;
    background: #fff; border: 1px solid #e9ecef; border-radius: 12px;
    padding: 10px 14px; text-decoration: none; color: inherit;
    transition: all .2s; cursor: pointer;
}
.cr-student-card:hover {
    border-color: #93c5fd; box-shadow: 0 3px 12px rgba(37,99,235,.1);
    transform: translateY(-1px); text-decoration: none; color: inherit;
}
.cr-avatar {
    width: 40px; height: 40px; border-radius: 50%; flex-shrink: 0;
    display: flex; align-items: center; justify-content: center;
    font-weight: 700; font-size: .85em; color: #fff;
    overflow: hidden;
}
.cr-avatar img { width: 100%; height: 100%; object-fit: cover; }
.cr-st-info { flex: 1; min-width: 0; }
.cr-st-name {
    font-weight: 600; font-size: .88em; color: #1a1a2e;
    overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
}
.cr-st-meta { font-size: .72em; color: #9ca3af; margin-top: 1px; }
.cr-st-grade {
    font-size: 1.1em; font-weight: 800; flex-shrink: 0;
}
.cr-st-no-data { color: #dee2e6; font-size: .85em; font-weight: 600; }
.cr-repr {
    position: relative;
    display: flex; align-items: center; gap: 4px; margin-top: 2px;
}
.cr-repr-dot {
    width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0;
}
.cr-repr-dot--red   { background: #ef4444; }
.cr-repr-dot--amber { background: #f59e0b; }
.cr-repr-dot--green { background: #22c55e; }
.cr-repr-text {
    font-size: .68em; font-weight: 600;
}
.cr-repr-text--red   { color: #ef4444; }
.cr-repr-text--amber { color: #d97706; }
.cr-repr-text--green { color: #16a34a; }
.cr-repr-dot--grey   { background: #d1d5db; }
.cr-repr-text--grey  { color: #9ca3af; }
.cr-repr-tip {
    display: none; position: absolute; bottom: calc(100% + 8px); left: 50%;
    transform: translateX(-50%); z-index: 100;
    width: 300px; padding: 14px 16px; border-radius: 12px;
    background: #fff; border: 1px solid #e2e8f0;
    box-shadow: 0 8px 24px rgba(0,0,0,.12), 0 2px 6px rgba(0,0,0,.06);
    font-size: .82em; font-weight: 400; line-height: 1.5; color: #374151;
    pointer-events: none; white-space: normal; text-align: left;
}
.cr-repr-tip::after {
    content: ''; position: absolute; top: 100%; left: 50%;
    transform: translateX(-50%);
    border: 6px solid transparent; border-top-color: #fff;
}
.cr-repr:hover .cr-repr-tip { display: block; }
.cr-repr-tip-head {
    font-weight: 700; font-size: 1em; margin-bottom: 6px;
}
.cr-repr-tip-head--red   { color: #dc2626; }
.cr-repr-tip-head--amber { color: #d97706; }
.cr-repr-tip-head--green { color: #16a34a; }
.cr-repr-tip-head--grey  { color: #9ca3af; }
.cr-repr-tip-pct {
    font-size: 1.6em; font-weight: 800; line-height: 1; margin-bottom: 6px;
    font-variant-numeric: tabular-nums;
}
.cr-repr-tip-rec {
    margin-top: 8px; padding-top: 8px; border-top: 1px solid #f1f5f9;
    font-size: .88em; color: #6b7280; font-style: italic;
}
.cr-empty {
    text-align: center; padding: 3rem; color: #9ca3af;
    background: #f8f9fa; border-radius: 12px; border: 2px dashed #e5e7eb;
}
</style>

<div class="cr-wrap">
    <div class="cr-title-row">
        <h2><?php echo get_string('report:title', 'local_sentinel'); ?></h2>
        <span class="cr-badge"><?php echo s($course->shortname); ?></span>
    </div>

<?php if (empty($student_rows)): ?>
    <div class="cr-empty">
        <div style="font-size:2rem;margin-bottom:.5rem;opacity:.5">&#128100;</div>
        <strong>No hay alumnos matriculados en este curso.</strong>
    </div>
<?php else: ?>
    <!-- ── Radar / bars summary ── -->
    <div class="cr-radar-card">
        <div class="cr-radar-grid">
            <div class="cr-radar-canvas-wrap">
                <canvas id="cr-radar-canvas" width="300" height="300"></canvas>
            </div>
            <div class="cr-area-bars">
<?php
$area_colors = [
    'CCL' => '#2563eb', 'CP'  => '#7c3aed', 'STEM' => '#0891b2',
    'CD'  => '#0d9488', 'CPSAA'=>'#d97706', 'CC'   => '#b45309',
    'CE'  => '#dc2626', 'CCEC' => '#9333ea',
];
foreach ($area_aggregates as $ac => $agg):
    $color = $area_colors[$ac] ?? '#6b7280';
    $avg = $agg['avg'];
    $pct = $avg !== null ? ($avg / 10) * 100 : 0;
?>
                <div class="cr-bar-row">
                    <span class="cr-bar-label" style="background:<?php echo $color; ?>"><?php echo s($ac); ?></span>
                    <div class="cr-bar-track">
<?php if ($avg !== null): ?>
                        <div class="cr-bar-fill" style="width:<?php echo number_format($pct, 1); ?>%;background:<?php echo $color; ?>">
                            <span><?php echo number_format($avg, 1); ?></span>
                        </div>
<?php endif; ?>
                    </div>
                    <span class="cr-bar-count"><?php echo $agg['count']; ?> alum.</span>
                </div>
<?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- ── Students ── -->
    <div class="cr-students-title">
        Alumnos
        <span class="cr-students-count"><?php echo count($student_rows); ?></span>
    </div>
    <div class="cr-student-grid">
<?php
foreach ($student_rows as $sr):
    $st = $sr['user'];
    $url = new moodle_url('/local/sentinel/student_report.php', [
        'userid'   => $st->id,
        'courseid' => $courseid,
    ]);
    $initials = mb_strtoupper(mb_substr($st->firstname, 0, 1) . mb_substr($st->lastname, 0, 1));
    $hue = (crc32($st->id . $st->lastname) % 360 + 360) % 360;
    $avatar_bg = "hsl({$hue}, 45%, 55%)";
    $sentinel_pic = $sentinel_photo_map[(int)$st->id] ?? null;
    $grade_color = $sr['grade'] !== null ? descriptor_calculator::grade_colour($sr['grade']) : '#ccc';
    $repr = $sr['repr'];
    $repr_icons = ['red' => "\u{26A0}\u{FE0F}", 'amber' => "\u{26A1}", 'green' => "\u{2705}", 'grey' => "\u{25CB}"];
    $repr_recs = [
        'grey'  => get_string('sample:rec_grey',  'local_sentinel'),
        'red'   => get_string('sample:rec_red',   'local_sentinel', number_format($repr['pct'], 0)),
        'amber' => get_string('sample:rec_amber', 'local_sentinel', number_format($repr['pct'], 0)),
        'green' => get_string('sample:rec_green', 'local_sentinel', number_format($repr['pct'], 0)),
    ];
?>
        <a class="cr-student-card" href="<?php echo $url->out(); ?>">
            <div class="cr-avatar" style="background:<?php echo $avatar_bg; ?>">
<?php if ($sentinel_pic): ?>
                <img src="<?php echo s($sentinel_pic); ?>" alt="<?php echo s(fullname($st)); ?>">
<?php else: ?>
                <?php echo $initials; ?>
<?php endif; ?>
            </div>
            <div class="cr-st-info">
                <div class="cr-st-name"><?php echo s(fullname($st)); ?></div>
                <div class="cr-st-meta">
<?php if ($sr['occasions'] > 0): ?>
                    <?php echo $sr['worked']; ?>/<?php echo $sr['total']; ?> desc. &middot; <?php echo $sr['occasions']; ?> ocasiones
<?php else: ?>
                    Sin datos aún
<?php endif; ?>
                </div>
                <div class="cr-repr">
                    <span class="cr-repr-dot cr-repr-dot--<?php echo $repr['level']; ?>"></span>
                    <span class="cr-repr-text cr-repr-text--<?php echo $repr['level']; ?>">
<?php if ($repr['level'] !== 'grey'): ?>
                        <?php echo number_format($repr['pct'], 0); ?>% ·
<?php endif; ?>
                        <?php echo s($repr['label']); ?>
                    </span>
                    <div class="cr-repr-tip">
                        <div class="cr-repr-tip-head cr-repr-tip-head--<?php echo $repr['level']; ?>">
                            <?php echo $repr_icons[$repr['level']]; ?> <?php echo s($repr['label']); ?>
                        </div>
<?php if ($repr['level'] !== 'grey'): ?>
                        <div class="cr-repr-tip-pct" style="color:<?php echo descriptor_calculator::grade_colour($repr['pct'] / 10); ?>">
                            <?php echo number_format($repr['pct'], 0); ?>%
                        </div>
                        <div><?php echo get_string('sample:tooltip_' . $repr['level'], 'local_sentinel', number_format($repr['pct'], 0)); ?></div>
<?php endif; ?>
                        <div class="cr-repr-tip-rec"><?php echo $repr_recs[$repr['level']]; ?></div>
                    </div>
                </div>
            </div>
<?php if ($sr['grade'] !== null): ?>
            <span class="cr-st-grade" style="color:<?php echo $grade_color; ?>"><?php echo number_format($sr['grade'], 1); ?></span>
<?php else: ?>
            <span class="cr-st-no-data">&mdash;</span>
<?php endif; ?>
        </a>
<?php endforeach; ?>
    </div>
<?php endif; ?>
</div>

<script>
(function() {
    var canvas = document.getElementById('cr-radar-canvas');
    if (!canvas) return;
    var ctx = canvas.getContext('2d');
    var W = canvas.width, H = canvas.height;
    var cx = W / 2, cy = H / 2, R = Math.min(cx, cy) - 30;

    var areas = <?php echo json_encode(array_values(array_map(function($ac, $agg) use ($area_colors) {
        return ['code' => $ac, 'avg' => $agg['avg'] ?? 0, 'color' => $area_colors[$ac] ?? '#6b7280'];
    }, array_keys($area_aggregates), array_values($area_aggregates)))); ?>;

    var n = areas.length;
    if (n < 3) return;

    function angle(i) { return (Math.PI * 2 * i / n) - Math.PI / 2; }
    function xy(i, r) { var a = angle(i); return [cx + r * Math.cos(a), cy + r * Math.sin(a)]; }

    // Grid circles.
    ctx.strokeStyle = '#e5e7eb'; ctx.lineWidth = 0.5;
    [2, 4, 6, 8, 10].forEach(function(v) {
        var r = R * v / 10;
        ctx.beginPath(); ctx.arc(cx, cy, r, 0, Math.PI * 2); ctx.stroke();
    });

    // Grid lines.
    for (var i = 0; i < n; i++) {
        var p = xy(i, R);
        ctx.beginPath(); ctx.moveTo(cx, cy); ctx.lineTo(p[0], p[1]); ctx.stroke();
    }

    // Data polygon.
    ctx.fillStyle = 'rgba(37,99,235,.12)';
    ctx.strokeStyle = '#2563eb'; ctx.lineWidth = 2;
    ctx.beginPath();
    for (var i = 0; i < n; i++) {
        var val = Math.min(10, Math.max(0, areas[i].avg));
        var p = xy(i, R * val / 10);
        if (i === 0) ctx.moveTo(p[0], p[1]); else ctx.lineTo(p[0], p[1]);
    }
    ctx.closePath(); ctx.fill(); ctx.stroke();

    // Data points + labels.
    for (var i = 0; i < n; i++) {
        var val = Math.min(10, Math.max(0, areas[i].avg));
        var p = xy(i, R * val / 10);
        ctx.fillStyle = areas[i].color;
        ctx.beginPath(); ctx.arc(p[0], p[1], 5, 0, Math.PI * 2); ctx.fill();
        ctx.fillStyle = '#fff'; ctx.beginPath(); ctx.arc(p[0], p[1], 2.5, 0, Math.PI * 2); ctx.fill();

        // Label.
        var lp = xy(i, R + 18);
        ctx.fillStyle = areas[i].color;
        ctx.font = 'bold 11px system-ui, sans-serif';
        ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
        ctx.fillText(areas[i].code, lp[0], lp[1]);
    }

    // Scale labels.
    ctx.fillStyle = '#adb5bd'; ctx.font = '9px system-ui, sans-serif';
    ctx.textAlign = 'right'; ctx.textBaseline = 'middle';
    [2, 4, 6, 8, 10].forEach(function(v) {
        ctx.fillText(v, cx - 5, cy - R * v / 10);
    });
})();
</script>

<?php
echo $OUTPUT->footer();

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
 * Admin page: map gradebook CE categories to LOMLOE profile exit descriptors.
 *
 * @package    local_sentinel
 * @copyright  2024 Marcos
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->dirroot . '/local/sentinel/classes/descriptor_calculator.php');

use local_sentinel\descriptor_calculator;

require_login();
$systemcontext = context_system::instance();

$PAGE->set_context($systemcontext);
$PAGE->set_url(new moodle_url('/local/sentinel/admin/map_descriptors.php'));
$PAGE->set_title(get_string('mapdesc:title', 'local_sentinel'));
$PAGE->set_heading(get_string('mapdesc:title', 'local_sentinel'));
$PAGE->set_pagelayout('admin');

// Build a set of course IDs the current user can edit descriptors for.
$all_courses = $DB->get_records_select('course', 'id > 1', null, 'id', 'id');
$editable_courses = [];
foreach ($all_courses as $c) {
    $ctx = context_course::instance($c->id, IGNORE_MISSING);
    if ($ctx && has_capability('local/sentinel:managedescriptors', $ctx)) {
        $editable_courses[$c->id] = true;
    }
}
if (empty($editable_courses) && !is_siteadmin()) {
    throw new \required_capability_exception($systemcontext, 'local/sentinel:managedescriptors', 'nopermissions', '');
}

// ── Handle POST ───────────────────────────────────────────────────────────────
if (data_submitted() && confirm_sesskey()) {
    // ce_map is a 2D array (ce_map[catid][dcode] = '1').
    // optional_param_array can't handle nested arrays — read $_POST directly.
    $raw_map = [];
    if (!empty($_POST['ce_map']) && is_array($_POST['ce_map'])) {
        foreach ($_POST['ce_map'] as $rawcatid => $rawcodes) {
            $catid = (int)clean_param($rawcatid, PARAM_INT);
            if ($catid > 0 && is_array($rawcodes)) {
                $raw_map[$catid] = array_map(
                    fn($c) => clean_param($c, PARAM_ALPHANUMEXT),
                    array_keys($rawcodes)
                );
            }
        }
    }
    $normativa_raw = [];
    if (!empty($_POST['normativa_map']) && is_array($_POST['normativa_map'])) {
        foreach ($_POST['normativa_map'] as $rawcatid => $rawcodes) {
            $catid = (int)clean_param($rawcatid, PARAM_INT);
            if ($catid > 0 && is_array($rawcodes)) {
                foreach ($rawcodes as $code => $val) {
                    $normativa_raw[$catid][clean_param($code, PARAM_ALPHANUMEXT)] = true;
                }
            }
        }
    }
    $allcategories = optional_param_array('allcategories', [], PARAM_INT);

    // Pre-load course ID for each category to verify permissions.
    $cat_course_map = [];
    if (!empty($allcategories)) {
        $cat_records = $DB->get_records_list('grade_categories', 'id', $allcategories, '', 'id,courseid');
        foreach ($cat_records as $cr) {
            $cat_course_map[$cr->id] = $cr->courseid;
        }
    }

    foreach ($allcategories as $catid) {
        $catid = (int)$catid;
        $cat_courseid = $cat_course_map[$catid] ?? 0;
        if (!isset($editable_courses[$cat_courseid]) && !is_siteadmin()) {
            continue;
        }
        $checked = $raw_map[$catid] ?? [];

        $DB->delete_records('local_sentinel_cat_descriptor', ['grade_category_id' => $catid]);
        foreach ($checked as $dcode) {
            if ($dcode === '') { continue; }
            $source = !empty($normativa_raw[$catid][$dcode]) ? 'normativa' : 'manual';
            $DB->insert_record('local_sentinel_cat_descriptor', (object)[
                'grade_category_id' => $catid,
                'descriptorcode'    => $dcode,
                'source'            => $source,
            ]);
        }
    }

    redirect(
        new moodle_url('/local/sentinel/admin/map_descriptors.php'),
        get_string('mapdesc:saved', 'local_sentinel'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

// ── Load data ─────────────────────────────────────────────────────────────────

// All 34 descriptors grouped by area.
$alldescriptors    = $DB->get_records('local_sentinel_descriptor', null, 'sortorder ASC');
$descriptors_by_area = [];
foreach ($alldescriptors as $d) {
    $descriptors_by_area[$d->area][] = $d;
}
$area_codes = array_keys(descriptor_calculator::AREAS);

// CE-level grade categories: those whose parent is a root category (parent = 0).
// This gives depth-2 categories = Competencias Específicas.
// depth=3 categories are the criterio de evaluación level.
// depth=2 are CE (Competencia Específica), depth=1 is the course root.
$act_cat_name = get_string('activities_category', 'local_sentinel');
$sql_ce = "
    SELECT gc.id, gc.fullname AS ce_name, gc.courseid,
           gc.parent AS parent_id, pgc.fullname AS parent_name,
           c.shortname AS course_short, c.fullname AS course_full
    FROM {grade_categories} gc
    JOIN {grade_categories} pgc ON pgc.id = gc.parent
    JOIN {course} c ON c.id = gc.courseid
    WHERE gc.courseid > 1
      AND gc.depth = 3
      AND pgc.fullname <> :actname
    ORDER BY gc.courseid, gc.parent, gc.id
";
$sql_params = ['actname' => $act_cat_name];
$ce_cats = $DB->get_records_sql($sql_ce, $sql_params);

// Current mappings indexed by grade_category_id, with source info.
$current_map = [];
$source_map = [];
foreach ($DB->get_records('local_sentinel_cat_descriptor') as $r) {
    $current_map[$r->grade_category_id][$r->descriptorcode] = true;
    $source_map[$r->grade_category_id][$r->descriptorcode] = $r->source;
}

// ── Render ────────────────────────────────────────────────────────────────────
echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('mapdesc:title', 'local_sentinel'));
echo html_writer::tag('p', get_string('mapdesc:intro', 'local_sentinel'), ['class' => 'text-muted']);

echo '<div style="display:flex;gap:24px;margin-bottom:16px;font-size:.85em">'
   . '<span style="display:inline-flex;align-items:center;gap:8px">'
   . '<input type="checkbox" checked tabindex="-1" style="accent-color:#00897b;width:14px;height:14px;pointer-events:none"> '
   . get_string('mapdesc:legend_normativa', 'local_sentinel')
   . '</span>'
   . '<span style="display:inline-flex;align-items:center;gap:8px">'
   . '<input type="checkbox" checked tabindex="-1" style="width:14px;height:14px;pointer-events:none"> '
   . get_string('mapdesc:legend_manual', 'local_sentinel')
   . '</span>'
   . '</div>';

if (empty($ce_cats)) {
    echo $OUTPUT->notification(
        get_string('mapdesc:nocriteria', 'local_sentinel'),
        'warning'
    );
    echo $OUTPUT->footer();
    exit;
}

// Group by course → CE parent → criterio.
$by_course = [];
foreach ($ce_cats as $cat) {
    $ckey = $cat->courseid;
    if (!isset($by_course[$ckey])) {
        $by_course[$ckey] = [
            'label' => s($cat->course_short) . ' — ' . s($cat->course_full),
            'ces'   => [],
        ];
    }
    $pkey = $cat->parent_id;
    if (!isset($by_course[$ckey]['ces'][$pkey])) {
        $by_course[$ckey]['ces'][$pkey] = [
            'name'     => $cat->parent_name,
            'criterios'=> [],
        ];
    }
    $by_course[$ckey]['ces'][$pkey]['criterios'][$cat->id] = $cat;
}

?>
<style>
.sentinel-map-wrap { overflow-x: auto; }
.sentinel-map-table { font-size: .82em; min-width: 900px; }
.sentinel-map-table th, .sentinel-map-table td { vertical-align: middle; }
.sentinel-map-table .area-head { font-weight: 700; text-align: center; border-left: 2px solid #dee2e6; }
.sentinel-map-table .desc-head { text-align: center; font-size: .75em; color: #555; padding: 3px 4px; }
.sentinel-map-table .desc-cb   { display: block; margin: auto; width: 14px; height: 14px; cursor: pointer; }
.ce-row td { background: #f8f9fa; }
.ce-name-cell { max-width: 340px; }
.desc-normativa { background-color: #e0f2f1 !important; }
.desc-normativa input.desc-cb { accent-color: #00897b; }
</style>
<?php

echo html_writer::start_tag('form', [
    'method' => 'post',
    'action' => $PAGE->url->out(false),
    'class'  => 'mform',
]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);

foreach ($by_course as $courseid => $cdata) {
    $can_edit = isset($editable_courses[$courseid]) || is_siteadmin();
    $readonly_label = $can_edit ? '' : ' <span style="font-size:.75em;color:#9ca3af;font-weight:400">(solo lectura)</span>';
    echo html_writer::tag('h4', $cdata['label'] . $readonly_label, ['class' => 'mt-4 mb-2']);

    echo html_writer::start_tag('div', ['class' => 'sentinel-map-wrap']);
    echo html_writer::start_tag('table', ['class' => 'table table-sm table-bordered sentinel-map-table']);

    // ── Header row 1: area names ──────────────────────────────────────────────
    echo html_writer::start_tag('thead');
    echo html_writer::start_tag('tr');
    echo html_writer::tag('th', 'Competencia Específica', ['style' => 'width:35%;min-width:200px']);
    foreach ($area_codes as $acode) {
        $adef  = descriptor_calculator::AREAS[$acode];
        $total = $adef['total'];
        echo html_writer::tag('th',
            html_writer::tag('span', $acode, [
                'title' => $adef['name'],
                'style' => "color:{$adef['color']};font-weight:700",
            ]),
            ['colspan' => $total, 'class' => 'area-head']
        );
    }
    echo html_writer::end_tag('tr');

    // ── Header row 2: descriptor codes ───────────────────────────────────────
    echo html_writer::start_tag('tr', ['class' => 'table-light']);
    echo html_writer::tag('th', '');
    foreach ($area_codes as $acode) {
        if (empty($descriptors_by_area[$acode])) { continue; }
        foreach ($descriptors_by_area[$acode] as $d) {
            $adef = descriptor_calculator::AREAS[$acode];
            echo html_writer::tag('th',
                html_writer::tag('span', s($d->code), [
                    'title' => $d->description,
                    'style' => "color:{$adef['color']}",
                ]),
                ['class' => 'desc-head']
            );
        }
    }
    echo html_writer::end_tag('tr');
    echo html_writer::end_tag('thead');

    // ── Body: CE header rows + criterio rows ──────────────────────────────────
    echo html_writer::start_tag('tbody');
    $ncols = array_sum(array_column(descriptor_calculator::AREAS, 'total'));
    foreach ($cdata['ces'] as $ceid => $cedata) {
        // CE section header.
        echo html_writer::start_tag('tr', ['style' => 'background:#e8f0fe']);
        echo html_writer::tag('td',
            html_writer::tag('strong',
                html_writer::tag('span', '▸ ', ['style' => 'color:#1a56db']) . s($cedata['name'])
            ),
            ['colspan' => $ncols + 1, 'style' => 'font-size:.82em;padding:6px 10px;color:#1a56db']
        );
        echo html_writer::end_tag('tr');

        // One row per criterio.
        foreach ($cedata['criterios'] as $catid => $cat) {
            if ($can_edit) {
                echo html_writer::empty_tag('input', [
                    'type'  => 'hidden',
                    'name'  => 'allcategories[]',
                    'value' => $catid,
                ]);
            }
            echo html_writer::start_tag('tr', $can_edit ? [] : ['style' => 'opacity:.6']);
            echo html_writer::tag('td',
                html_writer::tag('span', s($cat->ce_name), ['class' => 'small']),
                ['class' => 'ce-name-cell', 'style' => 'padding-left:1.5rem']
            );
            foreach ($area_codes as $acode) {
                if (empty($descriptors_by_area[$acode])) { continue; }
                foreach ($descriptors_by_area[$acode] as $d) {
                    $checked = !empty($current_map[$catid][$d->code]);
                    $is_normativa = ($source_map[$catid][$d->code] ?? '') === 'normativa';
                    $attrs = [
                        'type'  => 'checkbox',
                        'class' => 'desc-cb',
                        'title' => $d->code . ': ' . $d->description,
                    ];
                    if ($can_edit) {
                        $attrs['name']  = "ce_map[{$catid}][{$d->code}]";
                        $attrs['value'] = '1';
                    } else {
                        $attrs['disabled'] = 'disabled';
                    }
                    if ($checked) { $attrs['checked'] = 'checked'; }
                    $td_class = 'text-center p-1';
                    $hidden = '';
                    if ($is_normativa) {
                        $td_class .= ' desc-normativa';
                        if ($can_edit) {
                            $hidden = html_writer::empty_tag('input', [
                                'type' => 'hidden',
                                'name' => "normativa_map[{$catid}][{$d->code}]",
                                'value' => '1',
                            ]);
                        }
                    }
                    echo html_writer::tag('td',
                        $hidden . html_writer::empty_tag('input', $attrs),
                        ['class' => $td_class]
                    );
                }
            }
            echo html_writer::end_tag('tr');
        }
    }
    echo html_writer::end_tag('tbody');
    echo html_writer::end_tag('table');
    echo html_writer::end_tag('div');
}

echo html_writer::tag('div',
    html_writer::empty_tag('input', [
        'type'  => 'submit',
        'value' => get_string('mapdesc:save', 'local_sentinel'),
        'class' => 'btn btn-primary',
    ]),
    ['class' => 'mt-3 mb-5']
);
echo html_writer::end_tag('form');
echo $OUTPUT->footer();

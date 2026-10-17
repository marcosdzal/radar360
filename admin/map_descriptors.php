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

$filtercourseid = optional_param('courseid', 0, PARAM_INT);

if ($filtercourseid > 0 && $filtercourseid != SITEID) {
    $filtercourse = $DB->get_record('course', ['id' => $filtercourseid], '*', MUST_EXIST);
    require_login($filtercourse);
    $coursecontext = context_course::instance($filtercourseid);
    if (!has_capability('local/sentinel:managedescriptors', $coursecontext)
        && !has_capability('local/sentinel:managedescriptors', context_system::instance())) {
        require_capability('local/sentinel:managedescriptors', $coursecontext);
    }
    $PAGE->set_context($coursecontext);
    $PAGE->set_url(new moodle_url('/local/sentinel/admin/map_descriptors.php', ['courseid' => $filtercourseid]));
    $PAGE->set_title(get_string('mapdesc:title', 'local_sentinel') . ' — ' . $filtercourse->fullname);
    $PAGE->set_heading($filtercourse->fullname);
    $PAGE->set_pagelayout('incourse');
    $PAGE->set_secondary_active_tab('coursehome');
} else {
    $filtercourseid = 0;
    require_login();
    $PAGE->set_context(context_system::instance());
    $PAGE->set_url(new moodle_url('/local/sentinel/admin/map_descriptors.php'));
    $PAGE->set_title(get_string('mapdesc:title', 'local_sentinel'));
    $PAGE->set_heading(get_string('mapdesc:title', 'local_sentinel'));
    $PAGE->set_pagelayout('admin');
}

$systemcontext = context_system::instance();

// Build a set of course IDs the current user can edit descriptors for.
if ($filtercourseid > 0) {
    $editable_courses = [$filtercourseid => true];
} else {
    $all_courses = $DB->get_records_select('course', 'id > 1', null, 'id', 'id');
    $editable_courses = [];
    foreach ($all_courses as $c) {
        $ctx = context_course::instance($c->id, IGNORE_MISSING);
        if ($ctx && has_capability('local/sentinel:managedescriptors', $ctx)) {
            $editable_courses[$c->id] = true;
        }
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

    $redirect_params = $filtercourseid > 0 ? ['courseid' => $filtercourseid] : [];
    redirect(
        new moodle_url('/local/sentinel/admin/map_descriptors.php', $redirect_params),
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
";
$sql_params = ['actname' => $act_cat_name];
if ($filtercourseid > 0) {
    $sql_ce .= " AND gc.courseid = :cid";
    $sql_params['cid'] = $filtercourseid;
}
$sql_ce .= " ORDER BY gc.courseid, gc.parent, gc.id";
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

echo '<div style="margin-bottom:12px;display:flex;gap:8px">'
   . '<button type="button" id="smap-expand-all" class="btn btn-sm btn-outline-secondary">&#9660; Expandir todo</button>'
   . '<button type="button" id="smap-collapse-all" class="btn btn-sm btn-outline-secondary">&#9650; Colapsar todo</button>'
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
#page-local-sentinel-admin-map_descriptors #region-main { max-width: none; }
#page-local-sentinel-admin-map_descriptors #page-content .container-fluid,
#page-local-sentinel-admin-map_descriptors #page-content .contentcontainer { max-width: none; }
.sentinel-map-wrap { overflow-x: auto; }
.sentinel-map-table { font-size: .82em; min-width: 900px; width: 100%; }
.sentinel-map-table th, .sentinel-map-table td { vertical-align: middle; }
.sentinel-map-table .area-head { font-weight: 700; text-align: center; border-left: 2px solid #cbd5e1; }
.sentinel-map-table .area-first { border-left: 2px solid #cbd5e1; }
.sentinel-map-table .desc-head { text-align: center; font-size: .75em; color: #555; padding: 3px 4px; }
.sentinel-map-table .desc-cb   { display: block; margin: auto; width: 14px; height: 14px; cursor: pointer; }
.ce-name-cell { min-width: 340px; white-space: normal; line-height: 1.35; padding: 6px 10px 6px 1.5rem !important; }
.desc-normativa { background-color: #e0f2f1 !important; }
.desc-normativa input.desc-cb { accent-color: #00897b; }
.smap-accordion{border:1px solid #e2e8f0;border-radius:8px;margin-bottom:12px;overflow:hidden}
.smap-accordion-header{display:flex;align-items:center;gap:10px;padding:12px 16px;background:#f8fafc;cursor:pointer;user-select:none;font-weight:600;font-size:.92em;color:#1e293b;border:none;width:100%;text-align:left;transition:background .15s}
.smap-accordion-header:hover{background:#f1f5f9}
.smap-accordion-header .smap-arrow{transition:transform .2s;font-size:.8em;color:#64748b;flex-shrink:0}
.smap-accordion-header .smap-arrow.open{transform:rotate(90deg)}
.smap-accordion-header .smap-readonly{font-size:.75em;color:#9ca3af;font-weight:400;margin-left:auto}
.smap-accordion-body{display:none;padding:0 16px 16px}
.smap-accordion-body.open{display:block}
/* CE sub-accordion */
.smap-ce-accordion{border:1px solid #e2e8f0;border-radius:6px;margin-bottom:8px;overflow:hidden}
.smap-ce-header{display:flex;align-items:center;gap:8px;padding:8px 12px;background:#e8f0fe;cursor:pointer;user-select:none;font-weight:600;font-size:.82em;color:#1a56db;border:none;width:100%;text-align:left;transition:background .15s}
.smap-ce-header:hover{background:#dbe6fd}
.smap-ce-header .smap-arrow{transition:transform .2s;font-size:.7em;color:#1a56db;flex-shrink:0}
.smap-ce-header .smap-arrow.open{transform:rotate(90deg)}
.smap-ce-header .smap-ce-badge{font-weight:400;color:#64748b;margin-left:6px;font-size:.95em}
.smap-ce-body{display:none;padding:0}
.smap-ce-body.open{display:block}
.smap-ce-body .sentinel-map-table{margin-bottom:0;border-top:none}
.smap-ce-body .sentinel-map-table thead{position:sticky;top:0;z-index:2}
#smap-scroll-top{position:fixed;bottom:24px;right:24px;width:42px;height:42px;border-radius:50%;background:#7c3aed;color:#fff;border:none;font-size:1.2em;cursor:pointer;box-shadow:0 2px 8px rgba(0,0,0,.2);display:none;align-items:center;justify-content:center;z-index:100;transition:opacity .2s}
#smap-scroll-top:hover{background:#6d28d9}
</style>
<?php

echo html_writer::start_tag('form', [
    'method' => 'post',
    'action' => $PAGE->url->out(false),
    'class'  => 'mform',
]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);

$ncols = array_sum(array_column(descriptor_calculator::AREAS, 'total'));

// Pre-compute light background per area (12% opacity).
$area_bg = [];
foreach (descriptor_calculator::AREAS as $acode => $adef) {
    $hex = ltrim($adef['color'], '#');
    $r = hexdec(substr($hex, 0, 2));
    $g = hexdec(substr($hex, 2, 2));
    $b = hexdec(substr($hex, 4, 2));
    $area_bg[$acode] = "rgba({$r},{$g},{$b},.10)";
}

$course_idx = 0;

foreach ($by_course as $courseid => $cdata) {
    $can_edit = isset($editable_courses[$courseid]) || is_siteadmin();
    $readonly_html = $can_edit ? '' : '<span class="smap-readonly">(solo lectura)</span>';
    $ce_count = count($cdata['ces']);
    $crit_count = array_sum(array_map(function($ce) { return count($ce['criterios']); }, $cdata['ces']));
    $badge = '<span style="font-weight:400;font-size:.82em;color:#64748b;margin-left:6px">'
           . $ce_count . ' CE, ' . $crit_count . ' criterios</span>';

    echo '<div class="smap-accordion">';
    echo '<button type="button" class="smap-accordion-header" data-target="smap-body-' . $course_idx . '">';
    echo '<span class="smap-arrow">&#9654;</span> ';
    echo s($cdata['label']) . $badge . $readonly_html;
    echo '</button>';
    echo '<div class="smap-accordion-body" id="smap-body-' . $course_idx . '">';

    $ce_idx = 0;
    foreach ($cdata['ces'] as $ceid => $cedata) {
        $ce_crit_count = count($cedata['criterios']);
        $ce_badge = '<span class="smap-ce-badge">' . $ce_crit_count . ' criterio' . ($ce_crit_count !== 1 ? 's' : '') . '</span>';
        $ce_id = 'smap-ce-' . $course_idx . '-' . $ce_idx;

        echo '<div class="smap-ce-accordion">';
        echo '<button type="button" class="smap-ce-header" data-target="' . $ce_id . '">';
        echo '<span class="smap-arrow">&#9654;</span> ';
        echo s($cedata['name']) . $ce_badge;
        echo '</button>';
        echo '<div class="smap-ce-body" id="' . $ce_id . '">';

        echo html_writer::start_tag('div', ['class' => 'sentinel-map-wrap']);
        echo html_writer::start_tag('table', ['class' => 'table table-sm table-bordered sentinel-map-table']);

        // Header row 1: area names.
        echo html_writer::start_tag('thead');
        echo html_writer::start_tag('tr');
        echo html_writer::tag('th', 'Criterio de evaluación', ['style' => 'min-width:340px']);
        foreach ($area_codes as $acode) {
            $adef  = descriptor_calculator::AREAS[$acode];
            $total = $adef['total'];
            $bg = $area_bg[$acode];
            echo html_writer::tag('th',
                html_writer::tag('span', $acode, [
                    'title' => $adef['name'],
                    'style' => "color:{$adef['color']};font-weight:700",
                ]),
                ['colspan' => $total, 'class' => 'area-head', 'style' => "background:{$bg}"]
            );
        }
        echo html_writer::end_tag('tr');

        // Header row 2: descriptor codes.
        echo html_writer::start_tag('tr');
        echo html_writer::tag('th', '');
        foreach ($area_codes as $acode) {
            if (empty($descriptors_by_area[$acode])) { continue; }
            $first_in_area = true;
            $bg = $area_bg[$acode];
            foreach ($descriptors_by_area[$acode] as $d) {
                $adef = descriptor_calculator::AREAS[$acode];
                $th_class = 'desc-head' . ($first_in_area ? ' area-first' : '');
                echo html_writer::tag('th',
                    html_writer::tag('span', s($d->code), [
                        'title' => $d->description,
                        'style' => "color:{$adef['color']}",
                    ]),
                    ['class' => $th_class, 'style' => "background:{$bg}"]
                );
                $first_in_area = false;
            }
        }
        echo html_writer::end_tag('tr');
        echo html_writer::end_tag('thead');

        // Criterio rows.
        echo html_writer::start_tag('tbody');
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
                ['class' => 'ce-name-cell']
            );
            foreach ($area_codes as $acode) {
                if (empty($descriptors_by_area[$acode])) { continue; }
                $first_in_area = true;
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
                    if ($first_in_area) { $td_class .= ' area-first'; }
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
                    $first_in_area = false;
                }
            }
            echo html_writer::end_tag('tr');
        }
        echo html_writer::end_tag('tbody');
        echo html_writer::end_tag('table');
        echo html_writer::end_tag('div'); // sentinel-map-wrap

        echo '</div>'; // smap-ce-body
        echo '</div>'; // smap-ce-accordion
        $ce_idx++;
    }

    echo '</div>'; // smap-accordion-body
    echo '</div>'; // smap-accordion
    $course_idx++;
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

echo '<button id="smap-scroll-top" title="Volver arriba">&#9650;</button>';
?>
<script>
(function(){
    function toggleAccordion(btn) {
        var body = document.getElementById(btn.getAttribute('data-target'));
        var arrow = btn.querySelector('.smap-arrow');
        if (body) {
            body.classList.toggle('open');
            arrow.classList.toggle('open');
        }
    }
    // Course accordion toggle.
    document.querySelectorAll('.smap-accordion-header').forEach(function(btn) {
        btn.addEventListener('click', function() { toggleAccordion(btn); });
    });
    // CE sub-accordion toggle.
    document.querySelectorAll('.smap-ce-header').forEach(function(btn) {
        btn.addEventListener('click', function() { toggleAccordion(btn); });
    });
    // Expand all / Collapse all.
    function setAll(open) {
        document.querySelectorAll('.smap-accordion-body, .smap-ce-body').forEach(function(b) {
            if (open) { b.classList.add('open'); } else { b.classList.remove('open'); }
        });
        document.querySelectorAll('.smap-accordion-header .smap-arrow, .smap-ce-header .smap-arrow').forEach(function(a) {
            if (open) { a.classList.add('open'); } else { a.classList.remove('open'); }
        });
    }
    document.getElementById('smap-expand-all').addEventListener('click', function() { setAll(true); });
    document.getElementById('smap-collapse-all').addEventListener('click', function() { setAll(false); });
    // Scroll-to-top button.
    var scrollBtn = document.getElementById('smap-scroll-top');
    window.addEventListener('scroll', function() {
        scrollBtn.style.display = window.scrollY > 300 ? 'flex' : 'none';
    });
    scrollBtn.addEventListener('click', function() {
        window.scrollTo({ top: 0, behavior: 'smooth' });
    });
})();
</script>
<?php
echo $OUTPUT->footer();

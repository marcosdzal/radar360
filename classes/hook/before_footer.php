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
 * Hook listener: injects the Sentinel rubric editor/viewer JS.
 *
 * - edit.php    → interactive selector (init)
 * - preview.php → read-only column   (initView)
 * - grading pages (context_module with a rubric definition) → read-only column (initView)
 *
 * @package    local_sentinel
 * @copyright  2024 Marcos
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class before_footer {

    /**
     * @param \core\hook\output\before_footer_html_generation $hook
     */
    public static function inject_rubric_editor_js(
        \core\hook\output\before_footer_html_generation $hook
    ): void {
        global $PAGE, $DB;

        $path    = $PAGE->url->get_path();
        // get_config returns false when the key has never been saved (fresh install).
        // Default all features to enabled in that case.
        $cfgval  = static function(string $key): bool {
            $v = get_config('local_sentinel', $key);
            return $v === false ? true : (bool)$v;
        };
        $cfg     = (object)[
            'photos'        => $cfgval('photoenabled'),
            'rubric'        => $cfgval('rubricenabled'),
            'rubriccolors'  => $cfgval('rubriccolorsenabled'),
            'graderclick'   => $cfgval('graderclickenabled'),
            'graderabbrev'  => $cfgval('graderabbrevtoggle'),
            'drawer'        => $cfgval('drawerpersist'),
        ];

        // ── Global: keep left nav drawer fixed regardless of scroll ──────────
        // Do NOT reparent the drawer on gradebook report URLs: moving #nav-drawer to
        // document.body breaks Boost's course index / drawer JS (left menu stays on
        // skeleton; grader table may never unhide).
        if ($cfg->drawer && !str_contains($path, '/grade/report/')) {
            self::inject_drawer_persist($hook);
        }

        // ── Gradebook setup/settings: inject Sentinel descriptors button ────────
        if (str_ends_with($path, '/grade/edit/tree/index.php') ||
            str_ends_with($path, '/grade/edit/settings/index.php')) {
            if (has_capability('local/sentinel:managedescriptors', \context_system::instance())) {
                $url  = (new \moodle_url('/local/sentinel/admin/map_descriptors.php'))->out(false);
                $label = get_string('mapdesc:navlink', 'local_sentinel');
                $js_url   = json_encode($url);
                $js_label = json_encode($label);
                $js = <<<JS
(function() {
    'use strict';
    var btn = document.createElement('a');
    btn.href = {$js_url};
    btn.textContent = {$js_label};
    btn.style.cssText = [
        'display:inline-flex;align-items:center;gap:6px',
        'padding:7px 16px',
        'background:#1a56db;color:#fff',
        'border-radius:6px;font-size:.85em;font-weight:600',
        'text-decoration:none',
        'box-shadow:0 1px 4px rgba(0,0,0,0.18)',
        'transition:background 0.15s',
        'margin-left:8px',
        'white-space:nowrap',
    ].join(';');
    btn.onmouseenter = function() { btn.style.background = '#1e429f'; };
    btn.onmouseleave = function() { btn.style.background = '#1a56db'; };

    var inject = function() {
        // Inyectar junto al botón "Guardar cambios" o al final del h2/h1 de la página.
        var header = document.querySelector('.page-context-header') ||
                     document.querySelector('[data-region="page-header"]') ||
                     document.querySelector('#page-header');
        if (!header) { return; }
        var existing = document.getElementById('sentinel-map-btn');
        if (existing) { return; }
        btn.id = 'sentinel-map-btn';
        // Insertar después del h1 de la cabecera.
        var h1 = header.querySelector('h1') || header.querySelector('.h1');
        if (h1 && h1.parentNode) {
            h1.parentNode.insertBefore(btn, h1.nextSibling);
        } else {
            header.appendChild(btn);
        }
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', inject);
    } else {
        inject();
    }
})();
JS;
                $PAGE->requires->js_amd_inline($js);
            }
            return;
        }

        // ── Grader report: abbreviation toggle + student photos + click→grading
        if (str_ends_with($path, '/grade/report/grader/index.php')) {
            if ($cfg->graderabbrev) {
                self::inject_grader_abbrev_toggle($hook);
            }
            $courseid = optional_param('id', 0, PARAM_INT);
            if ($courseid && $cfg->photos) {
                $enrolled  = get_enrolled_users(\context_course::instance($courseid), '', 0, 'u.id');
                $uids      = array_keys($enrolled);
                // Una sola consulta para todas las fotos; reindexar por studentid.
                $photos_raw = $DB->get_records_list('local_sentinel_student_photo', 'studentid', $uids);
                $photos = [];
                foreach ($photos_raw as $rec) {
                    $photos[$rec->studentid] = $rec;
                }
                $photo_map = [];
                foreach ($uids as $uid) {
                    $photo_map[$uid] = isset($photos[$uid])
                        ? (new \moodle_url('/local/sentinel/photo.php', ['action' => 'serve', 'studentid' => $uid]))->out(false)
                        : null;
                }
                $canmanage_photos = has_capability('local/sentinel:managephotos', \context_system::instance());
                $PAGE->requires->js_call_amd('local_sentinel/student_photo', 'initGraderReport', [
                    $photo_map,
                    $canmanage_photos ? (new \moodle_url('/local/sentinel/photo.php'))->out(false) : null,
                    $canmanage_photos ? sesskey() : null,
                ]);

                // Mapa grade_item_id → cmid para actividades assign.
                // Incluye tanto el ítem de la actividad como los criterios de Sentinel.
                $assign_items = $DB->get_records_sql(
                    'SELECT gi.id AS itemid, cm.id AS cmid
                       FROM {grade_items} gi
                       JOIN {course_modules} cm ON cm.instance = gi.iteminstance
                       JOIN {modules} m ON m.id = cm.module AND m.name = :mod
                      WHERE gi.courseid = :cid
                        AND gi.itemtype = :itype
                        AND gi.itemmodule = :imod
                        AND cm.course = :cid2',
                    ['cid' => $courseid, 'cid2' => $courseid, 'itype' => 'mod', 'imod' => 'assign', 'mod' => 'assign']
                );
                $item_to_cmid = [];
                foreach ($assign_items as $row) {
                    $item_to_cmid[(int)$row->itemid] = (int)$row->cmid;
                }

                // Añadir los ítems de criterio de Sentinel (tipo manual, idnumber sentinel_crit_X).
                // Hay que resolver: grade_item → rubric_criteria → definition → area → context → cm.
                $sentinel_crit_items = $DB->get_records_sql(
                    'SELECT gi.id AS itemid, cm.id AS cmid
                       FROM {grade_items} gi
                       JOIN {local_sentinel_map} sm ON sm.grade_item_id = gi.id
                       JOIN {gradingform_rubric_criteria} rc ON rc.id = sm.rubric_row_id
                       JOIN {grading_definitions} gd ON gd.id = rc.definitionid
                       JOIN {grading_areas} ga ON ga.id = gd.areaid
                       JOIN {context} ctx ON ctx.id = ga.contextid
                       JOIN {course_modules} cm ON cm.id = ctx.instanceid
                      WHERE gi.courseid = :cid
                        AND gi.itemtype = :itype
                        AND sm.grade_category_id > 0',
                    ['cid' => $courseid, 'itype' => 'manual']
                );
                foreach ($sentinel_crit_items as $row) {
                    $item_to_cmid[(int)$row->itemid] = (int)$row->cmid;
                }

                if ($cfg->graderclick) {
                    $grader_url = (new \moodle_url('/mod/assign/view.php'))->out(false);
                    self::inject_grader_report_click_to_grade($hook, $item_to_cmid, $grader_url, $courseid);
                }
            }
            // Communication button: available to any teacher who can view grades.
            if ($courseid && has_capability('moodle/grade:viewall', \context_course::instance($courseid))) {
                // Count existing periods for this course to propose the next ordinal label.
                $period_count = $DB->count_records_select(
                    'local_sentinel_period',
                    'courseid = :cid OR courseid = 0',
                    ['cid' => $courseid]
                );
                $ordinals = ['1ª', '2ª', '3ª', '4ª', '5ª', '6ª'];
                $default_label = ($ordinals[$period_count] ?? (($period_count + 1) . 'ª')) . ' Evaluación';
                self::inject_grader_communication_button(
                    $hook,
                    $courseid,
                    (new \moodle_url('/local/sentinel/ajax.php'))->out(false),
                    sesskey(),
                    $default_label
                );
            }
            return;
        }

        // ── User profile pages: inject student photo widget ─────────────────────
        // /user/view.php (course context) and /user/profile.php (global profile).
        if (str_ends_with($path, '/user/view.php') || str_ends_with($path, '/user/profile.php')) {
            $userid = optional_param('id', 0, PARAM_INT);
            if ($userid && $cfg->photos) { self::inject_profile_photo($userid); }
            return;
        }

        // ── Single-student report pages donde el userid viene en el parámetro 'id' ──
        if (str_ends_with($path, '/report/outline/user.php') ||
            str_ends_with($path, '/report/log/user.php')     ||
            str_ends_with($path, '/report/stats/user.php')   ||
            str_ends_with($path, '/mod/forum/user.php')) {
            $userid = optional_param('id', 0, PARAM_INT);
            if ($userid && $cfg->photos) { self::inject_profile_photo($userid); }
            return;
        }

        // ── Single-student report pages donde el userid viene en el parámetro 'user' ──
        if (str_ends_with($path, '/report/competency/index.php') ||
            str_ends_with($path, '/course/user.php')) {
            $userid = optional_param('user', 0, PARAM_INT);
            if ($userid && $cfg->photos) { self::inject_profile_photo($userid); }
            return;
        }

        // ── Grade report pages that show one student's avatar at the top ────────
        if (str_ends_with($path, '/grade/report/user/index.php') ||
            str_ends_with($path, '/grade/report/overview/index.php') ||
            str_ends_with($path, '/grade/report/singleview/index.php')) {
            $userid = optional_param('userid', 0, PARAM_INT);
            if ($userid && $cfg->photos) { self::inject_profile_photo($userid); }
            return;
        }

        // ── Participants page: inject student photo thumbnails ─────────────────
        if (str_ends_with($path, '/user/index.php')) {
            if ($cfg->photos) {
                // Build photo map from ALL stored photos (not filtered by course enrollment,
                // because get_enrolled_users on the site course returns a different set than
                // what the participants page actually shows).
                $all_photos = $DB->get_records('local_sentinel_student_photo', null, '', 'studentid, studentid');
                $photo_map = [];
                foreach ($all_photos as $rec) {
                    $photo_map[(int)$rec->studentid] = (new \moodle_url('/local/sentinel/photo.php', [
                        'action' => 'serve', 'studentid' => $rec->studentid,
                    ]))->out(false);
                }
                $canmanage_photos = has_capability('local/sentinel:managephotos', \context_system::instance());
                $upload_url = $canmanage_photos ? (new \moodle_url('/local/sentinel/photo.php'))->out(false) : null;
                $PAGE->requires->js_call_amd('local_sentinel/student_photo', 'initParticipants', [
                    $photo_map, $upload_url, $canmanage_photos ? sesskey() : null,
                ]);
            }
            return;
        }

        // ── Assignment grading page: inject student photo widget + grade badge ──
        if (str_contains($path, '/mod/assign/view.php')) {
            if ($cfg->photos) {
                $cmid = optional_param('id', 0, PARAM_INT);
                if ($cmid) {
                    $cm = get_coursemodule_from_id('assign', $cmid);
                    if ($cm) {
                        $enrolled  = get_enrolled_users(\context_course::instance($cm->course), '', 0, 'u.id');
                        $photo_map = [];
                        foreach ($enrolled as $u) {
                            $rec = $DB->get_record('local_sentinel_student_photo', ['studentid' => $u->id]);
                            $photo_map[$u->id] = $rec
                                ? (new \moodle_url('/local/sentinel/photo.php', ['action' => 'serve', 'studentid' => $u->id]))->out(false)
                                : null;
                        }
                        $canmanage_photos = has_capability('local/sentinel:managephotos', \context_system::instance());
                        $upload_url = $canmanage_photos ? (new \moodle_url('/local/sentinel/photo.php'))->out(false) : null;
                        $PAGE->requires->js_call_amd('local_sentinel/student_photo', 'initGrading', [
                            $photo_map, $upload_url, $canmanage_photos ? sesskey() : null,
                        ]);
                    }
                }
            }
            // Grade badge is independent of photos: always inject on the grading page.
            self::inject_grader_grade_badge($hook);
            self::inject_grader_popout_fix($hook);
            if ($cfg->rubriccolors) {
                self::inject_rubric_colors($hook);
            }
            // Fall through — the grading page may also have a rubric.
        }

        if (!$cfg->rubric) {
            return;
        }

        $is_edit    = str_ends_with($path, '/grade/grading/form/rubric/edit.php');
        $is_preview = str_ends_with($path, '/grade/grading/form/rubric/preview.php');

        if ($is_edit || $is_preview) {
            // areaid is always a URL param on these dedicated pages.
            $areaid = optional_param('areaid', 0, PARAM_INT);
            if (!$areaid) {
                return;
            }
            $area = $DB->get_record('grading_areas', ['id' => $areaid], 'id, contextid');
            if (!$area) {
                return;
            }
        } else {
            // Grading pages: rubric is embedded in a module page.
            // Only worth checking module-level contexts.
            if (!($PAGE->context instanceof \context_module)) {
                return;
            }
            // Look for a rubric grading definition in this context.
            $area = $DB->get_record_sql(
                'SELECT ga.id, ga.contextid
                   FROM {grading_areas} ga
                   JOIN {grading_definitions} gd ON gd.areaid = ga.id
                  WHERE ga.contextid = :ctxid
                    AND gd.method    = :method',
                ['ctxid' => $PAGE->context->id, 'method' => 'rubric']
            );
            if (!$area) {
                return;
            }
        }

        // Resolve courseid from the grading area context.
        try {
            $context  = \context::instance_by_id($area->contextid);
            $courseid = $context->get_course_context()->instanceid;
        } catch (\Exception $e) {
            return;
        }

        // Load grade categories with parent + sortorder for a depth-first tree walk.
        // gi.sortorder orders siblings correctly; parent field gives the tree structure.
        $cat_rows = $DB->get_records_sql(
            'SELECT gc.id, gc.fullname, gc.parent, gi.sortorder
               FROM {grade_categories} gc
               JOIN {grade_items} gi
                 ON gi.itemtype     = :itype
                AND gi.iteminstance = gc.id
                AND gi.courseid     = :cid2
              WHERE gc.courseid = :cid
                AND gc.depth > 1
              ORDER BY gi.sortorder ASC',
            ['cid' => $courseid, 'cid2' => $courseid, 'itype' => 'category']
        );

        // Group children by parent id (SQL order = correct sibling order within each level).
        $sel_ids  = [];
        $children = [];
        foreach ($cat_rows as $row) {
            $sel_ids[(int)$row->id] = true;
        }
        foreach ($cat_rows as $row) {
            $children[(int)$row->parent][] = $row;
        }

        // Depth-first traversal from nodes whose parent is the course-level category (outside our set).
        $cats = [];
        $traverse = function(int $pid) use (&$traverse, &$children, &$cats): void {
            foreach ($children[$pid] ?? [] as $row) {
                $cats[] = ['id' => (int)$row->id, 'name' => $row->fullname];
                $traverse((int)$row->id);
            }
        };
        $seen_roots = [];
        foreach ($cat_rows as $row) {
            $pid = (int)$row->parent;
            if (!isset($sel_ids[$pid]) && !isset($seen_roots[$pid])) {
                $seen_roots[$pid] = true;
                $traverse($pid);
            }
        }
        if (empty($cats)) { // Fallback: flat sortorder.
            foreach ($cat_rows as $row) {
                $cats[] = ['id' => (int)$row->id, 'name' => $row->fullname];
            }
        }

        // Load existing criterion → category mappings.
        $mappings = [];
        $rows = $DB->get_records('local_sentinel_map', [], '', 'rubric_row_id, grade_category_id');
        foreach ($rows as $row) {
            $mappings[(int)$row->rubric_row_id] = (int)$row->grade_category_id;
        }

        $rubric_strings = [
            'label'     => get_string('rubriceditor:label',      'local_sentinel'),
            'tooltip'   => get_string('rubriceditor:tooltip',    'local_sentinel'),
            'unassigned'=> get_string('rubriceditor:unassigned', 'local_sentinel'),
            'pending'   => get_string('rubriceditor:pending',    'local_sentinel'),
            'neterror'  => get_string('rubriceditor:neterror',   'local_sentinel'),
            'header'    => get_string('rubriceditor:header',     'local_sentinel'),
        ];

        if ($is_edit) {
            $PAGE->requires->js_call_amd('local_sentinel/rubric_editor', 'init', [
                $cats,
                $mappings,
                (new \moodle_url('/local/sentinel/ajax.php'))->out(false),
                sesskey(),
                $rubric_strings,
            ]);
        } else {
            // Preview and grading: read-only column.
            $PAGE->requires->js_call_amd('local_sentinel/rubric_editor', 'initView', [
                $cats,
                $mappings,
                $rubric_strings,
            ]);
        }
    }

    /**
     * En el informe del calificador, al hacer clic en una celda de nota de
     * una actividad tipo "assign", navega a la página de calificación de ese
     * alumno en lugar de abrir el editor inline.
     *
     * @param array  $item_to_cmid  [grade_item_id => cmid]
     * @param string $grader_url    URL base de /mod/assign/view.php
     */
    private static function inject_grader_report_click_to_grade(
        \core\hook\output\before_footer_html_generation $hook,
        array $item_to_cmid,
        string $grader_url,
        int $courseid = 0
    ): void {
        global $PAGE, $DB;

        $map_json    = json_encode($item_to_cmid);
        $url_json    = json_encode($grader_url);
        $ajax_url    = json_encode((new \moodle_url('/local/sentinel/ajax.php'))->out(false));
        $sesskey     = json_encode(sesskey());
        $str_loading = json_encode(get_string('grader:loading', 'local_sentinel'));
        $str_nodata  = json_encode(get_string('grader:nodata',  'local_sentinel'));

        $js = <<<JS
(function() {
    'use strict';

    // Keep this block minimal and failure-proof: if it errors, it can prevent
    // Moodle's own grader JS from unhiding the table.
    try {
        var itemToCmid = {$map_json} || {};
        var graderUrl  = {$url_json} || '';
        var ajaxUrl    = {$ajax_url} || '';
        var sesskey    = {$sesskey} || '';

        var findTable = function() {
            return document.querySelector('#user-grades') ||
                   document.querySelector('table.gradebook-table');
        };

        var init = function() {
            var table = findTable();
            if (!table || !table.addEventListener) { return; }

            // Moodle grader cells typically use id="u{userid}i{itemid}".
            // We rely on that because data-* attributes vary by template.
            var parseCell = function(el) {
                if (!el || !el.closest) { return null; }
                var td = el.closest('td');
                if (!td || !td.id) { return null; }
                var m = /^u(\\d+)i(\\d+)$/.exec(td.id);
                if (!m) { return null; }
                return { userid: parseInt(m[1], 10), itemid: parseInt(m[2], 10) };
            };

            // ── Tooltip (opt-in): hold Shift while hovering a clickable grade cell ──
            var tooltip = null;
            var timer = null;
            var cache = Object.create(null); // key: "userid_itemid" -> rows

            var hideTooltip = function() {
                if (tooltip && tooltip.parentNode) {
                    tooltip.parentNode.removeChild(tooltip);
                }
                tooltip = null;
                if (timer) { clearTimeout(timer); timer = null; }
            };

            var showTooltip = function(rows, x, y) {
                hideTooltip();
                if (!rows || !rows.length) { return; }
                tooltip = document.createElement('div');
                tooltip.style.cssText = [
                    'position:fixed',
                    'z-index:9999',
                    'background:#fff',
                    'border:1px solid #ccc',
                    'border-radius:6px',
                    'box-shadow:0 4px 16px rgba(0,0,0,0.18)',
                    'padding:8px 12px',
                    'max-width:360px',
                    'font-size:12px',
                    'line-height:1.35',
                    'pointer-events:none'
                ].join(';');

                for (var i = 0; i < rows.length; i++) {
                    var row = rows[i];
                    var line = document.createElement('div');
                    line.style.cssText = 'margin-bottom:4px;border-bottom:1px solid #f0f0f0;padding-bottom:4px;';
                    var crit = document.createElement('span');
                    crit.style.cssText = 'font-weight:700;color:#333;';
                    crit.textContent = (row.criterion || '') + ': ';
                    var lvl = document.createElement('span');
                    lvl.style.cssText = 'color:#1a7f3c;';
                    var score = (row.score !== null && row.score !== undefined && row.score !== '') ? (' (' + row.score + ' pts)') : '';
                    lvl.textContent = (row.level || '') + score;
                    line.appendChild(crit);
                    line.appendChild(lvl);
                    tooltip.appendChild(line);
                }

                document.body.appendChild(tooltip);
                // Position with basic viewport clamping.
                var tw = tooltip.offsetWidth || 360;
                var th = tooltip.offsetHeight || 120;
                var left = x + 12;
                var top = y + 12;
                if (left + tw > window.innerWidth - 8) { left = x - tw - 12; }
                if (top + th > window.innerHeight - 8) { top = y - th - 12; }
                tooltip.style.left = Math.max(4, left) + 'px';
                tooltip.style.top  = Math.max(4, top)  + 'px';
            };

            var fetchRows = function(userid, itemid, cb) {
                var key = userid + '_' + itemid;
                if (cache[key]) { cb(cache[key]); return; }
                if (!ajaxUrl || !sesskey) { cb([]); return; }
                var fd = new FormData();
                fd.append('action', 'rubric_summary');
                fd.append('userid', String(userid));
                fd.append('itemid', String(itemid));
                fd.append('sesskey', String(sesskey));
                fetch(ajaxUrl, {method: 'POST', body: fd})
                    .then(function(r) { return r.json(); })
                    .then(function(data) {
                        var rows = (data && data.success && data.rows) ? data.rows : [];
                        cache[key] = rows;
                        cb(rows);
                    })
                    .catch(function() { cb([]); });
            };

            // Mark clickable grade cells (assign and sentinel-mapped items).
            try {
                table.querySelectorAll('td[id^="u"]').forEach(function(td) {
                    var m = /^u(\\d+)i(\\d+)$/.exec(td.id);
                    if (!m) { return; }
                    var itemid = parseInt(m[2], 10);
                    if (itemToCmid[itemid]) {
                        td.classList.add('sentinel-clickable-grade');
                        td.style.cursor = 'pointer';
                    }
                });
                if (!document.getElementById('sentinel-clickable-grade-style')) {
                    var style = document.createElement('style');
                    style.id = 'sentinel-clickable-grade-style';
                    style.textContent =
                        '.sentinel-clickable-grade:hover{outline:2px solid rgba(15,108,191,.25);outline-offset:-2px;background:rgba(15,108,191,.04);}';
                    document.head.appendChild(style);
                }
            } catch (ex) {}

            table.addEventListener('click', function(e) {
                var target = e && e.target ? e.target : null;
                var parsed = parseCell(target);
                if (!parsed) { return; }
                var cmid = itemToCmid[parsed.itemid];
                if (!cmid) { return; } // Not an assign/sentinel-mapped item.
                if (!parsed.userid) { return; }
                e.preventDefault();
                e.stopImmediatePropagation();
                window.location.href = graderUrl + '?id=' + cmid + '&action=grader&userid=' + parsed.userid;
            }, true);

            table.addEventListener('mouseover', function(e) {
                var ev = e || window.event;
                var parsed = parseCell(ev.target);
                if (!parsed) { return; }
                if (!itemToCmid[parsed.itemid]) { return; }
                if (timer) { clearTimeout(timer); }

                // Immediate feedback so we know the handler is running.
                showTooltip([{criterion: 'Sentinel', level: {$str_loading}, score: null}], ev.clientX || 0, ev.clientY || 0);

                timer = setTimeout(function() {
                    fetchRows(parsed.userid, parsed.itemid, function(rows) {
                        if (!rows || !rows.length) {
                            showTooltip([{criterion: 'Sentinel', level: {$str_nodata}, score: null}], ev.clientX || 0, ev.clientY || 0);
                            return;
                        }
                        showTooltip(rows, ev.clientX || 0, ev.clientY || 0);
                    });
                }, 450);
            }, true);

            table.addEventListener('mousemove', function(e) {
                if (!tooltip) { return; }
                var ev = e || window.event;
                if (!ev) { return; }
                // Reposition near cursor.
                var tw = tooltip.offsetWidth || 360;
                var th = tooltip.offsetHeight || 120;
                var left = (ev.clientX || 0) + 12;
                var top = (ev.clientY || 0) + 12;
                if (left + tw > window.innerWidth - 8) { left = (ev.clientX || 0) - tw - 12; }
                if (top + th > window.innerHeight - 8) { top = (ev.clientY || 0) - th - 12; }
                tooltip.style.left = Math.max(4, left) + 'px';
                tooltip.style.top  = Math.max(4, top)  + 'px';
            }, true);

            table.addEventListener('mouseout', function() { hideTooltip(); }, true);
        };

        // Wait until the grader table exists (and core JS has had a chance to init).
        var tries = 0;
        var timer = setInterval(function() {
            tries++;
            if (findTable() || tries > 80) {
                clearInterval(timer);
                init();
            }
        }, 100);

    } catch (ex) {
        // Swallow errors.
    }

})();
JS;

        $PAGE->requires->js_amd_inline($js);
    }

    /**
     * Injects a toggle button into the grader report to switch between
     * full category names and abbreviated numeric prefixes (e.g. "1.1.").
     */
    private static function inject_grader_abbrev_toggle(
        \core\hook\output\before_footer_html_generation $hook
    ): void {
        global $PAGE;

        $js = <<<'JS'
(function() {
    'use strict';

    const STORAGE_KEY = 'sentinel_grader_abbrev';

    // Truncate to 20 chars + ellipsis.
    const shorten = (text) => text.length > 20 ? text.slice(0, 20) + '…' : null;

    const applyMode = (abbreviated) => {
        document.querySelectorAll('span[data-sentinel-full], a[data-sentinel-full]').forEach(el => {
            el.textContent = abbreviated
                ? el.dataset.sentinelShort
                : el.dataset.sentinelFull;
        });
        const btn = document.getElementById('sentinel-abbrev-btn');
        if (btn) {
            btn.innerHTML = abbreviated ? SENTINEL_STR_ABBREV_FULL : SENTINEL_STR_ABBREV_SHORT;
            btn.style.background = abbreviated ? '#0f6cbf' : '#1a7f3c';
        }
        localStorage.setItem(STORAGE_KEY, abbreviated ? '1' : '0');
        window.dispatchEvent(new Event('sentinel-abbrev-changed'));
    };

    const init = () => {
        // Use TreeWalker to find text nodes inside <th> regardless of nesting depth.
        let tagged = 0;
        const toProcess = [];

        document.querySelectorAll('th').forEach(th => {
            const walker = document.createTreeWalker(th, NodeFilter.SHOW_TEXT);
            let node;
            while ((node = walker.nextNode())) {
                const text = node.textContent.trim();
                if (text.length > 20) toProcess.push(node);
            }
        });

        toProcess.forEach(textNode => {
            const text = textNode.textContent.trim();
            const short = shorten(text);
            if (!short) return;
            const span = document.createElement('span');
            span.dataset.sentinelFull  = text;
            span.dataset.sentinelShort = short;
            span.textContent = textNode.textContent;
            textNode.parentNode.replaceChild(span, textNode);
            tagged++;
        });

        if (tagged === 0) return;

        // Build the button — fixed position, draggable.
        const btn = document.createElement('button');
        btn.id = 'sentinel-abbrev-btn';
        btn.style.cssText = [
            'position:fixed;z-index:1060;',
            'padding:5px 10px;border:none;border-radius:4px;',
            'color:#fff;font-size:0.75em;font-weight:600;',
            'white-space:nowrap;line-height:1.4;',
            'box-shadow:0 2px 6px rgba(0,0,0,0.3);',
            'transition:filter 0.15s;',
            'user-select:none;',
        ].join('');

        // Restore saved position or use default.
        const POS_KEY = 'sentinel_abbrev_btn_pos';
        const savedPos = JSON.parse(localStorage.getItem(POS_KEY) || 'null');
        btn.style.top   = (savedPos ? savedPos.top  : 68)  + 'px';
        btn.style.right = 'auto';
        btn.style.left  = (savedPos ? savedPos.left : Math.max(0, window.innerWidth - 220)) + 'px';

        btn.onmouseenter = () => { btn.style.filter = 'brightness(1.15)'; };
        btn.onmouseleave = () => { btn.style.filter = ''; };
        document.body.appendChild(btn);

        // ── Drag logic ────────────────────────────────────────────────────
        let dragging = false, didDrag = false, dragOX, dragOY, startX, startY;
        btn.addEventListener('mousedown', e => {
            dragging = true;
            didDrag  = false;
            startX = e.clientX; startY = e.clientY;
            dragOX = e.clientX - btn.getBoundingClientRect().left;
            dragOY = e.clientY - btn.getBoundingClientRect().top;
            btn.style.transition = 'none';
            e.preventDefault();
        });
        document.addEventListener('mousemove', e => {
            if (!dragging) { return; }
            if (!didDrag && Math.hypot(e.clientX - startX, e.clientY - startY) < 4) { return; }
            didDrag = true;
            const left = Math.max(0, Math.min(window.innerWidth  - btn.offsetWidth,  e.clientX - dragOX));
            const top  = Math.max(0, Math.min(window.innerHeight - btn.offsetHeight, e.clientY - dragOY));
            btn.style.left = left + 'px';
            btn.style.top  = top  + 'px';
        });
        document.addEventListener('mouseup', e => {
            if (!dragging) { return; }
            dragging = false;
            btn.style.transition = '';
            if (didDrag) {
                // Moved — save position, suppress the click.
                localStorage.setItem(POS_KEY, JSON.stringify({
                    left: btn.getBoundingClientRect().left,
                    top:  btn.getBoundingClientRect().top,
                }));
            }
        });
        // Suppress click if we dragged.
        btn.addEventListener('click', e => {
            if (didDrag) { e.stopImmediatePropagation(); e.preventDefault(); didDrag = false; }
        }, true);

        // Default is abbreviated. Only show full names if user explicitly chose '0'.
        const stored = localStorage.getItem(STORAGE_KEY) !== '0';
        applyMode(stored);

        btn.addEventListener('click', () => {
            applyMode(localStorage.getItem(STORAGE_KEY) !== '1');
        });
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
JS;

        $js = str_replace(
            ['SENTINEL_STR_ABBREV_FULL', 'SENTINEL_STR_ABBREV_SHORT'],
            [
                json_encode(get_string('grader:abbrevfull',  'local_sentinel')),
                json_encode(get_string('grader:abbrevshort', 'local_sentinel')),
            ],
            $js
        );
        $PAGE->requires->js_amd_inline($js);
    }

    /**
     * Shows a live rubric-score badge next to the student name while grading.
     * Reads the .level.checked cells directly — no DOM-scraping of gradebook fields.
     * Updates in real time as the teacher clicks levels (before saving).
     * Resets and recalculates when switching students.
     */
    private static function inject_grader_grade_badge(
        \core\hook\output\before_footer_html_generation $hook
    ): void {
        global $PAGE;

        $js = <<<'JS'
(function() {
    'use strict';

    function parseScore(text) {
        var m = (text || '').match(/(-?\d+(?:[.,]\d+)?)/);
        return m ? parseFloat(m[1].replace(',', '.')) : 0;
    }

    function getRubricTotal() {
        var total = 0, maxTotal = 0, anyChecked = false, hasRows = false;
        document.querySelectorAll('.gradingform_rubric tr.criterion').forEach(function(row) {
            hasRows = true;
            var levels = row.querySelectorAll('.levels .level');
            var rowMax = -Infinity;
            levels.forEach(function(lv) {
                var s = parseScore((lv.querySelector('.score') || {}).textContent);
                if (s > rowMax) { rowMax = s; }
            });
            if (rowMax > -Infinity && rowMax >= 0) { maxTotal += rowMax; }

            var checked = row.querySelector('.levels .level.checked');
            if (checked) {
                anyChecked = true;
                total += parseScore((checked.querySelector('.score') || {}).textContent);
            }
        });
        // Return data even when nothing selected so badge is always visible once rubric loads.
        return hasRows ? {total: total, max: maxTotal, anyChecked: anyChecked} : null;
    }

    function findHeader() {
        return document.querySelector('[data-region="user-info"] h4') ||
               document.querySelector('[data-region="user-info"] h2') ||
               document.querySelector('[data-region="user-info"] h3') ||
               document.querySelector('[data-region="user-info"] .h4') ||
               null;
    }

    // Single card with two lines: grade (big) on top, raw pts (small) below.
    var _card = null, _lineGrade = null, _linePts = null;
    var _dragging = false, _dragOX = 0, _dragOY = 0;

    function ensureCard() {
        if (!_card) {
            _card = document.createElement('div');
            _card.className  = 'sentinel-rubric-score-card';
            _card.title      = 'Arrastra para mover · Doble clic para restablecer posición';
            _card.style.cssText = [
                'position:fixed', 'z-index:1055', 'cursor:grab',
                'border-radius:14px', 'padding:6px 18px 5px',
                'text-align:center', 'line-height:1.25',
                'box-shadow:0 2px 8px rgba(0,0,0,0.35)',
                'transition:background 0.3s',
                'min-width:80px', 'user-select:none',
            ].join(';');

            _lineGrade = document.createElement('div');
            _lineGrade.style.cssText = 'color:#fff;font-weight:800;font-size:1.35em;letter-spacing:0.01em;';

            _linePts = document.createElement('div');
            _linePts.style.cssText = 'color:rgba(255,255,255,0.82);font-weight:500;font-size:0.72em;margin-top:1px;';

            _card.appendChild(_lineGrade);
            _card.appendChild(_linePts);
            document.body.appendChild(_card);

            // ── Drag support (mouse + touch) ──────────────────────────────────
            function startDrag(cx, cy) {
                var r = _card.getBoundingClientRect();
                _dragOX = cx - r.left;
                _dragOY = cy - r.top;
                _dragging = true;
                _card.style.cursor = 'grabbing';
            }
            function moveDrag(cx, cy) {
                if (!_dragging) { return; }
                var x = Math.max(0, Math.min(window.innerWidth  - _card.offsetWidth,  cx - _dragOX));
                var y = Math.max(0, Math.min(window.innerHeight - _card.offsetHeight, cy - _dragOY));
                _card.style.left  = x + 'px';
                _card.style.right = 'auto';
                _card.style.top   = y + 'px';
            }
            function endDrag() {
                if (!_dragging) { return; }
                _dragging = false;
                _card.style.cursor = 'grab';
                _card._pinned = true;
                try { localStorage.setItem('sentinel_badge_xy', _card.style.left + '|' + _card.style.top); } catch(e) {}
            }

            _card.addEventListener('mousedown', function(e) { startDrag(e.clientX, e.clientY); e.preventDefault(); });
            document.addEventListener('mousemove', function(e) { moveDrag(e.clientX, e.clientY); });
            document.addEventListener('mouseup',   function()  { endDrag(); });

            _card.addEventListener('touchstart', function(e) {
                startDrag(e.touches[0].clientX, e.touches[0].clientY);
            }, {passive: true});
            document.addEventListener('touchmove', function(e) {
                if (_dragging) { moveDrag(e.touches[0].clientX, e.touches[0].clientY); e.preventDefault(); }
            }, {passive: false});
            document.addEventListener('touchend', function() { endDrag(); });

            // Double-click resets to automatic position.
            _card.addEventListener('dblclick', function() {
                _card._pinned = false;
                try { localStorage.removeItem('sentinel_badge_xy'); } catch(e) {}
                positionBadge(_card);
            });

            // Restore saved position from a previous session.
            try {
                var xy = (localStorage.getItem('sentinel_badge_xy') || '').split('|');
                if (xy.length === 2 && xy[0] && xy[1]) {
                    _card.style.left  = xy[0];
                    _card.style.right = 'auto';
                    _card.style.top   = xy[1];
                    _card._pinned = true;
                }
            } catch(e) {}
        }
        return _card;
    }

    function findInfoCol(h) {
        var el = h ? h.parentElement : null;
        while (el && el !== document.body) {
            if (/\bcol(-\w+)?-\d+\b/.test(el.className || '')) { return el; }
            el = el.parentElement;
        }
        return null;
    }

    function positionBadge(card) {
        if (card._pinned) { card.style.display = 'block'; return; }
        var h = findHeader();
        if (!h) { card.style.display = 'none'; return; }
        var col  = findInfoCol(h);
        var rect = (col || h).getBoundingClientRect();
        card.style.top     = Math.round(rect.top + 6) + 'px';
        card.style.right   = Math.round(window.innerWidth - rect.right + 6) + 'px';
        card.style.left    = 'auto';
        card.style.display = 'block';
    }

    function updateBadge() {
        var score = getRubricTotal();
        if (!score) {
            if (_card) { _card.style.display = 'none'; }
            return;
        }

        var card = ensureCard();
        if (!score.anyChecked) {
            _lineGrade.textContent  = '—';
            _linePts.textContent    = '— / ' + score.max + ' pts';
            card.style.background   = '#6c757d';
        } else {
            var pct    = score.max > 0 ? score.total / score.max : 0;
            var nota10 = (pct * 10).toFixed(2);
            var hue    = Math.round(pct * 120);   // 0=red … 120=green
            _lineGrade.textContent  = nota10;
            _linePts.textContent    = score.total + ' / ' + score.max + ' pts';
            card.style.background   = 'hsl(' + hue + ',65%,36%)';
        }
        positionBadge(card);
    }

    function attachRubricListener(rubric) {
        if (rubric._snBadgeBound) { return; }
        rubric._snBadgeBound = true;
        rubric.addEventListener('click', function() {
            setTimeout(updateBadge, 80);
        });
    }

    // Reposition on scroll / resize only when not manually pinned.
    window.addEventListener('scroll', function() { if (_card && _card.style.display !== 'none' && !_card._pinned) { positionBadge(_card); } }, {passive: true});
    window.addEventListener('resize', function() { if (_card && _card.style.display !== 'none' && !_card._pinned) { positionBadge(_card); } }, {passive: true});

    function setup() {
        updateBadge();
        var rubric = document.querySelector('.gradingform_rubric');
        if (rubric) { attachRubricListener(rubric); }
    }

    function waitAndSetup() {
        if (document.querySelector('.gradingform_rubric')) {
            setup();
        } else {
            var mo = new MutationObserver(function(_, obs) {
                if (document.querySelector('.gradingform_rubric')) {
                    obs.disconnect();
                    setup();
                }
            });
            mo.observe(document.body, {childList: true, subtree: true});
            setTimeout(function() { mo.disconnect(); }, 60000);
        }
    }

    // Re-run each time the teacher switches students.
    require(['jquery'], function($) {
        $(document).on('finish-loading-user', function() {
            setTimeout(waitAndSetup, 250);
        });
    });

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', waitAndSetup);
    } else {
        waitAndSetup();
    }
})();
JS;

        $PAGE->requires->js_amd_inline($js);
    }

    /**
     * Cuando el profesor expande la rúbrica con el botón popout, Moodle la
     * posiciona fixed al 20% por todos los lados.  Sobreescribimos ese CSS para
     * que ocupe toda la pantalla excepto la cabecera del alumno (user-info).
     */
    private static function inject_grader_popout_fix(
        \core\hook\output\before_footer_html_generation $hook
    ): void {
        global $PAGE;

        $js = <<<'JS'
(function() {
    'use strict';

    // ── Persistir estado popout + posición de scroll entre alumnos ────────
    require(['jquery'], function($) {
        var savedPopout = false;
        var savedScroll = 0;

        // Justo antes de cambiar de alumno, guardar estado.
        $(document).on('start-loading-user', function() {
            var popoutEl = document.querySelector('[data-region="grade-panel"] .fitem.popout .felement');
            savedPopout = !!document.querySelector('[data-region="grade-panel"] .fitem.popout');
            savedScroll = popoutEl ? popoutEl.scrollTop : 0;
        });

        // Cuando el nuevo panel está completamente cargado, restaurar.
        $(document).on('finish-loading-user', function() {
            if (!savedPopout) { return; }
            // Esperar a que _addPopoutButtons haya insertado el botón.
            var tries = 0;
            var restore = function() {
                var btn = document.querySelector('[data-region="grade-panel"] [data-region="popout-button"]');
                if (btn) {
                    btn.click();   // activa la clase .popout
                    // Restaurar scroll tras el render.
                    setTimeout(function() {
                        var felement = document.querySelector('[data-region="grade-panel"] .fitem.popout .felement');
                        if (felement) { felement.scrollTop = savedScroll; }
                    }, 80);
                    return;
                }
                if (++tries < 20) { setTimeout(restore, 100); }
            };
            setTimeout(restore, 150);
        });
    });

    // El contenedor padre de user-info es la fila .row dentro de
    // [data-region="grading-navigation"], que abarca los tres bloques:
    // info de tarea (izq), nombre alumno (centro) y selector de usuario (der).
    // Medimos ese contenedor para no cortar el texto de la derecha.
    var getHeaderBottom = function() {
        var nav = document.querySelector('[data-region="grading-navigation"]');
        if (nav) { return nav.getBoundingClientRect().bottom + 4; }
        var userInfo = document.querySelector('[data-region="user-info"]');
        if (userInfo) { return userInfo.getBoundingClientRect().bottom + 4; }
        return 80;
    };

    var getActionsHeight = function() {
        var bar = document.querySelector('[data-region="grade-actions-panel"]') ||
                  document.querySelector('.sticky-footer') ||
                  document.querySelector('[data-region="grade-actions"]');
        if (bar) { return window.innerHeight - bar.getBoundingClientRect().top + 4; }
        return 60; // fallback conservador
    };

    var getOrCreateStyle = function() {
        var s = document.getElementById('sentinel-popout-style');
        if (!s) {
            s = document.createElement('style');
            s.id = 'sentinel-popout-style';
            document.head.appendChild(s);
        }
        return s;
    };

    var applyStyles = function() {
        var headerH  = getHeaderBottom();
        var actionsH = getActionsHeight();
        var s = getOrCreateStyle();
        s.textContent =
            // ── Popout: entre cabecera y barra de botones de guardar ──
            '.path-mod-assign [data-region="grade-panel"] .fitem.popout {' +
            '  top:'    + headerH  + 'px !important;' +
            '  left:0 !important;' +
            '  right:0 !important;' +
            '  bottom:' + actionsH + 'px !important;' +
            '  border-radius:0 !important;' +
            '}' +
            '.path-mod-assign #page-content [data-region="grade-panel"]' +
            ' .mform:not(.unresponsive) .fcontainer .fitem.popout .felement {' +
            '  height:calc(100% - 54px) !important;' +
            '}' +
            '';
    };

    var run = function() {
        applyStyles();
        // Re-aplicar si cambia el alumno o se redimensiona la ventana.
        new MutationObserver(applyStyles)
            .observe(document.querySelector('[data-region="grading-navigation"]') || document.body,
                     {childList: true, subtree: true, attributes: true});
        window.addEventListener('resize', applyStyles);
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', run);
    } else {
        run();
    }
})();
JS;

        $PAGE->requires->js_amd_inline($js);
    }

    /**
     * Colorea las celdas de la rúbrica por posición de nivel, usando una paleta
     * de 5 colores base (celeste→verde→amarillo→salmón→rosa) con interpolación
     * dinámica en JS según el número real de niveles de cada criterio.
     * Con 5 niveles usa exactamente los 5 colores; con menos elimina los intermedios;
     * con más interpola tonos entre los puntos fijos.
     */
    private static function inject_rubric_colors(
        \core\hook\output\before_footer_html_generation $hook
    ): void {
        global $PAGE;

        // CSS estructural únicamente — los colores los aplica JS con interpolación dinámica.
        $css = '';

        // ── Columna CE (vista previa / fuera del panel assign): sin tope 180px ──
        $css .= '.gradingform_rubric .sentinel-view-th,.gradingform_rubric .sentinel-view-cell {' .
                'vertical-align:top;box-sizing:border-box;white-space:normal;' .
                'min-width:10rem;max-width:min(55%,40rem);' .
                "}\n";

        // ── Rúbrica ocupa todo el ancho (anula max-width:150px de Moodle) ─────
        $css .= '.gradingform_rubric .criterion .levels .level { max-width:none !important; }' . "\n";
        $css .= '.gradingform_rubric,.gradingform_rubric .criteria { width:100% !important; }' . "\n";
        $css .= '.gradingform_rubric .criterion .levels,' .
                '.gradingform_rubric .criterion .levels table,' .
                '.gradingform_rubric .criterion .levels table tbody { width:100% !important; }' . "\n";

        // ── Sin corte de palabras forzado ─────────────────────────────────────
        $css .= '.gradingform_rubric .criterion .levels .level {' .
                    'word-break:normal;hyphens:none;overflow-wrap:anywhere;' .
                "}\n";

        // ── Formato A: base blanco, sin bordes de Moodle ─────────────────────
        $css .= '.gradingform_rubric .criterion .levels .level {' .
                    'background-color:#fff;transition:background-color 0.15s;' .
                    'cursor:pointer;border-left:none !important;border-right:none !important;' .
                "}\n";

        // ── Formato B: AMD radio buttons — base ──────────────────────────────
        $rbase = '[data-region="grade"] [role="radiogroup"]';
        $css .= "{$rbase} .form-check { padding:2px 0; }\n";
        $css .= "{$rbase} .form-check label {" .
                    'display:block;border-radius:4px;padding:4px 8px;cursor:pointer;' .
                    'background-color:#fff;border:none;' .
                    'transition:background-color 0.15s;margin-bottom:2px;' .
                "}\n";

        // ── Panel calificación assign: CE ancha, niveles compactos (especificidad > reglas globales) ──
        $gpp = '.path-mod-assign [data-region="grade-panel"] ';
        $css .= $gpp . '.mform .fitem.has-popout .felement,' .
                $gpp . '.mform .fitem .felement[data-fieldtype="grading"] {' .
                'max-width:100% !important;width:100% !important;min-width:0 !important;box-sizing:border-box;' .
                "}\n";
        $css .= $gpp . '.gradingform_rubric {' .
                'max-width:none !important;width:100% !important;box-sizing:border-box;' .
                "}\n";
        $css .= $gpp . '.gradingform_rubric table.criteria {' .
                'width:100% !important;max-width:none !important;box-sizing:border-box;table-layout:auto !important;' .
                "}\n";
        $css .= $gpp . '.gradingform_rubric .criterion .description {' .
                'min-width:6rem !important;max-width:min(22%,16rem) !important;' .
                'vertical-align:top;word-break:normal;overflow-wrap:break-word;' .
                "}\n";
        $css .= $gpp . '.gradingform_rubric .criterion .levels {' .
                'width:1% !important;max-width:none !important;vertical-align:top;' .
                "}\n";
        $css .= $gpp . '.gradingform_rubric .criterion .levels,' .
                $gpp . '.gradingform_rubric .criterion .levels table,' .
                $gpp . '.gradingform_rubric .criterion .levels table tbody {' .
                'width:auto !important;max-width:100% !important;' .
                "}\n";
        $css .= $gpp . '.gradingform_rubric .criterion .levels table {' .
                'table-layout:auto !important;' .
                "}\n";
        $css .= $gpp . '.gradingform_rubric .criterion .levels .level {' .
                'max-width:11rem !important;min-width:5rem !important;width:9.25rem !important;' .
                'vertical-align:top;box-sizing:border-box !important;' .
                "}\n";
        $css .= $gpp . '.gradingform_rubric .criterion .levels .level .definition,' .
                $gpp . '.gradingform_rubric .criterion .levels .level .score {' .
                'white-space:normal !important;' .
                "}\n";
        $css .= $gpp . '.gradingform_rubric .criterion .remark {' .
                'min-width:8rem !important;width:1% !important;max-width:18% !important;vertical-align:top;' .
                "}\n";
        $css .= $gpp . '.gradingform_rubric .criterion .remark textarea {' .
                'width:100% !important;min-height:4.5rem;box-sizing:border-box;' .
                "}\n";
        $css .= $gpp . '.gradingform_rubric .sentinel-view-th,' .
                $gpp . '.gradingform_rubric .sentinel-view-cell {' .
                'min-width:min(42%,28rem) !important;max-width:none !important;width:40% !important;' .
                'white-space:normal !important;word-break:normal;overflow-wrap:break-word;' .
                'vertical-align:top;box-sizing:border-box;' .
                "}\n";

        // JS: paleta dinámica de 5 colores con interpolación por número de niveles.
        $js = <<<'JS'
(function(){
    var s = document.createElement('style');
    s.id  = 'sentinel-rubric-colors';
    s.textContent = CSS_PLACEHOLDER;
    document.head.appendChild(s);

    // Paleta de 5 colores base: [hover_suave, seleccionado_pastel]
    // Índice 0 = rojo (mínima puntuación), índice 4 = verde (máxima puntuación).
    // Al asignar a celdas se invierte el índice porque Moodle las renderiza de mayor a menor.
    var PALETTE = [
        ['#ffe8e8', '#ffbbbb'],
        ['#fff0e0', '#ffccaa'],
        ['#fffbcc', '#fff0aa'],
        ['#eef7cc', '#cceeaa'],
        ['#ddf5dd', '#aae8aa'],
    ];

    function hexToRgb(h) {
        h = h.replace('#', '');
        return [parseInt(h.slice(0,2),16), parseInt(h.slice(2,4),16), parseInt(h.slice(4,6),16)];
    }
    function rgbToHex(r, g, b) {
        return '#' + [r,g,b].map(function(v) {
            return ('0' + Math.round(v).toString(16)).slice(-2);
        }).join('');
    }
    function lerpColor(c1, c2, t) {
        var a = hexToRgb(c1), b = hexToRgb(c2);
        return rgbToHex(a[0]+(b[0]-a[0])*t, a[1]+(b[1]-a[1])*t, a[2]+(b[2]-a[2])*t);
    }

    // Devuelve n pares [hov,sel] interpolados.
    function getPalette(n) {
        if (n <= 0) { return []; }
        if (n === 1) { return [PALETTE[0]]; }
        var result = [], m = PALETTE.length - 1;
        for (var i = 0; i < n; i++) {
            var t   = i / (n - 1);
            var pos = t * m;
            var lo  = Math.floor(pos);
            var hi  = Math.min(Math.ceil(pos), m);
            var f   = pos - lo;
            result.push((lo === hi || f === 0) ? PALETTE[lo] : [
                lerpColor(PALETTE[lo][0], PALETTE[hi][0], f),
                lerpColor(PALETTE[lo][1], PALETTE[hi][1], f)
            ]);
        }
        return result;
    }

    function applyColors() {
        // Formato A: tabla clasica
        document.querySelectorAll('.gradingform_rubric .criterion').forEach(function(crit) {
            var levels = crit.querySelectorAll('.levels .level');
            if (!levels.length) { return; }
            var pal = getPalette(levels.length);
            levels.forEach(function(cell, i) {
                // Moodle renders levels highest→lowest (left→right), so reverse index:
                // i=0 (max score) → pal[last] = green; i=last (0 pts) → pal[0] = red.
                var c = pal[pal.length - 1 - i] || pal[0];
                var isChecked = cell.classList.contains('checked');
                if (isChecked) {
                    cell.style.setProperty('background-color', c[1], 'important');
                    cell.style.fontWeight = 'bold';
                } else {
                    cell.style.removeProperty('background-color');
                    cell.style.fontWeight = '';
                }
                cell._snHov = c[0]; cell._snSel = c[1];
                if (!cell._snBound) {
                    cell._snBound = true;
                    cell.addEventListener('mouseenter', function() {
                        if (!this.classList.contains('checked')) {
                            this.style.setProperty('background-color', this._snHov, 'important');
                        }
                    });
                    cell.addEventListener('mouseleave', function() {
                        if (this.classList.contains('checked')) {
                            this.style.setProperty('background-color', this._snSel, 'important');
                        } else {
                            this.style.removeProperty('background-color');
                        }
                    });
                    cell.addEventListener('click', function() {
                        setTimeout(applyColors, 60);
                    });
                }
            });
        });

        // Formato B: AMD radio buttons
        document.querySelectorAll('[data-region="grade"] [role="radiogroup"]').forEach(function(group) {
            var checks = group.querySelectorAll('.form-check');
            if (!checks.length) { return; }
            var pal = getPalette(checks.length);
            checks.forEach(function(fc, i) {
                var c = pal[pal.length - 1 - i] || pal[0];
                var label = fc.querySelector('label');
                var input = fc.querySelector('input');
                if (!label) { return; }
                var isChecked = !!(input && input.checked);
                if (isChecked) {
                    label.style.setProperty('background-color', c[1], 'important');
                    label.style.fontWeight = 'bold';
                } else {
                    label.style.removeProperty('background-color');
                    label.style.fontWeight = '';
                }
                fc._snHov = c[0]; fc._snSel = c[1];
                if (!fc._snBound) {
                    fc._snBound = true;
                    fc.addEventListener('mouseenter', function() {
                        var lbl = this.querySelector('label'), inp = this.querySelector('input');
                        if (lbl && !(inp && inp.checked)) {
                            lbl.style.setProperty('background-color', this._snHov, 'important');
                        }
                    });
                    fc.addEventListener('mouseleave', function() {
                        var lbl = this.querySelector('label'), inp = this.querySelector('input');
                        if (!lbl) { return; }
                        if (inp && inp.checked) {
                            lbl.style.setProperty('background-color', this._snSel, 'important');
                        } else {
                            lbl.style.removeProperty('background-color');
                        }
                    });
                    fc.addEventListener('click', function() {
                        setTimeout(applyColors, 60);
                    });
                }
            });
        });
    }

    // Wait until .gradingform_rubric .criterion exists in the DOM, then call cb().
    // Uses MutationObserver so it works regardless of how long Moodle takes to render.
    function waitForRubric(cb) {
        if (document.querySelector('.gradingform_rubric .criterion')) {
            cb();
            return;
        }
        var mo = new MutationObserver(function(_, obs) {
            if (document.querySelector('.gradingform_rubric .criterion')) {
                obs.disconnect();
                cb();
            }
        });
        mo.observe(document.body, {childList: true, subtree: true});
        // Safety net: disconnect after 60s to avoid memory leaks.
        setTimeout(function() { mo.disconnect(); }, 60000);
    }

    var run = function() {
        waitForRubric(applyColors);
        require(['jquery'], function($) {
            $(document).on('finish-loading-user', function() {
                // After student switch the rubric is rebuilt — wait for it again.
                setTimeout(function() { waitForRubric(applyColors); }, 100);
            });
        });
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', run);
    } else {
        run();
    }
})();
JS;

        $js = str_replace('CSS_PLACEHOLDER', json_encode($css), $js);
        $PAGE->requires->js_amd_inline($js);
    }

    /**
     * Inyecta la foto Sentinel y el badge de nota final para un alumno concreto.
     * Centraliza la lógica común de todos los bloques de página individual.
     */
    private static function inject_profile_photo(int $userid): void {
        global $PAGE, $DB, $USER;

        // Students viewing their own profile see no Sentinel photo widget at all.
        $canmanage = ($USER->id !== $userid)
            && has_capability('local/sentinel:managephotos', \context_system::instance());
        if ($USER->id === $userid && !$canmanage) {
            return;
        }

        $photo_record = $DB->get_record('local_sentinel_student_photo', ['studentid' => $userid]);
        $photo_url = $photo_record
            ? (new \moodle_url('/local/sentinel/photo.php', ['action' => 'serve', 'studentid' => $userid]))->out(false)
            : null;

        $upload_url = $canmanage ? (new \moodle_url('/local/sentinel/photo.php'))->out(false) : null;
        $grade_badge = self::get_grade_badge($userid, $PAGE->course->id);

        $photo_strings = [
            'delphoto'    => get_string('photo:delete',       'local_sentinel'),
            'addphoto'    => get_string('photo:add',          'local_sentinel'),
            'gradetooltip'=> get_string('photo:gradetooltip', 'local_sentinel'),
        ];
        $PAGE->requires->js_call_amd('local_sentinel/student_photo', 'initUserProfile', [
            $userid, $photo_url, $upload_url, sesskey(), $grade_badge, $photo_strings,
        ]);
    }

    /**
     * Devuelve ['value' => float, 'url' => string] con la nota final del curso
     * para el alumno indicado, o null si no existe o no hay nota.
     */
    private static function get_grade_badge(int $userid, int $courseid): ?array {
        global $DB;
        if (!$userid || !$courseid || $courseid == SITEID) {
            return null;
        }
        // Consulta directa: nota final del curso (grade_item de tipo 'course').
        $row = $DB->get_record_sql(
            'SELECT gg.finalgrade, gi.grademax
               FROM {grade_items} gi
               JOIN {grade_grades} gg ON gg.itemid = gi.id AND gg.userid = :userid
              WHERE gi.courseid = :courseid
                AND gi.itemtype = :itype',
            ['userid' => $userid, 'courseid' => $courseid, 'itype' => 'course']
        );
        if (!$row || $row->finalgrade === null) {
            return null;
        }
        $grademax = (float)($row->grademax ?: 100);
        $value10  = round(((float)$row->finalgrade / $grademax) * 10, 1);
        return [
            'value' => $value10,
            'url'   => (new \moodle_url('/grade/report/user/index.php', [
                'id'     => $courseid,
                'userid' => $userid,
            ]))->out(false),
        ];
    }

    /**
     * Injects a draggable "Comunicación" button on the grader report (same style as
     * the abbreviation toggle) that lets the teacher create a new communication period.
     * Students will see it as a new tab in their Sentinel competency report.
     */
    private static function inject_grader_communication_button(
        \core\hook\output\before_footer_html_generation $hook,
        int $courseid,
        string $ajax_url,
        string $sesskey,
        string $default_label
    ): void {
        global $PAGE;

        $js_ajax    = json_encode($ajax_url);
        $js_sesskey = json_encode($sesskey);
        $js_cid     = json_encode($courseid);
        $js_label   = json_encode($default_label);

        $js = <<<JS
(function() {
    'use strict';

    var ajaxUrl      = {$js_ajax};
    var sesskey      = {$js_sesskey};
    var courseid     = {$js_cid};
    var defaultLabel = {$js_label};

    // Pastel violet palette (distinct from the blue/green of the abbrev button).
    var COLOR_DEFAULT = '#8b5cf6'; // violet-500
    var COLOR_HOVER   = '#7c3aed'; // violet-600
    var COLOR_DONE    = '#16a34a'; // green (same as abbrev success)
    var COLOR_BTN_SEND = '#7c3aed';
    var COLOR_BTN_SEND_HOVER = '#6d28d9';

    var POS_KEY = 'sentinel_comm_btn_pos';

    function toast(msg, ok) {
        var t = document.createElement('div');
        t.textContent = msg;
        t.style.cssText = [
            'position:fixed', 'bottom:24px', 'left:50%', 'transform:translateX(-50%)',
            'z-index:99998', 'padding:10px 22px', 'border-radius:8px',
            'font-size:.85em', 'font-weight:600', 'color:#fff',
            'box-shadow:0 4px 16px rgba(0,0,0,.3)',
            'background:' + (ok ? '#16a34a' : '#dc2626'),
            'white-space:nowrap', 'pointer-events:none'
        ].join(';');
        document.body.appendChild(t);
        setTimeout(function() { t.parentNode && t.parentNode.removeChild(t); }, 4500);
    }

    function init() {
        // ── Panel ──────────────────────────────────────────────────────────────
        var panel = document.createElement('div');
        panel.id = 'sn-comm-panel';
        panel.style.cssText = [
            'display:none', 'position:fixed', 'z-index:1062',
            'background:#fff', 'border-radius:10px',
            'box-shadow:0 8px 32px rgba(0,0,0,.18)',
            'padding:16px 18px 14px', 'width:260px',
            'border:1px solid #ede9fe'
        ].join(';');

        var ptitle = document.createElement('p');
        ptitle.textContent = 'Comunicar notas';
        ptitle.style.cssText = 'margin:0 0 12px;font-size:.8em;font-weight:700;color:#4c1d95;letter-spacing:.03em;text-transform:uppercase;';

        var mkLbl = function(txt) {
            var l = document.createElement('label');
            l.textContent = txt;
            l.style.cssText = 'display:block;font-size:.75em;font-weight:600;color:#6b7280;margin-bottom:3px;';
            return l;
        };
        var mkInput = function(type, val) {
            var i = document.createElement('input');
            i.type = type;
            i.value = val;
            i.style.cssText = 'width:100%;box-sizing:border-box;padding:6px 9px;border:1px solid #ddd6fe;border-radius:6px;font-size:.82em;margin-bottom:10px;';
            i.onfocus = function() { i.style.borderColor = '#8b5cf6'; i.style.outline = '2px solid #ede9fe'; };
            i.onblur  = function() { i.style.borderColor = '#ddd6fe'; i.style.outline = 'none'; };
            return i;
        };

        var today    = new Date();
        var pad      = function(n) { return n < 10 ? '0' + n : String(n); };
        var todayStr = today.getFullYear() + '-' + pad(today.getMonth() + 1) + '-' + pad(today.getDate());

        var inputName = mkInput('text', defaultLabel);
        var inputDate = mkInput('date', todayStr);

        var btnSend = document.createElement('button');
        btnSend.type = 'button';
        btnSend.textContent = 'Cerrar comunicación';
        btnSend.style.cssText = [
            'width:100%', 'padding:8px', 'margin-top:2px',
            'background:' + COLOR_BTN_SEND, 'color:#fff', 'border:none',
            'border-radius:6px', 'font-size:.82em', 'font-weight:600',
            'cursor:pointer', 'transition:background .12s'
        ].join(';');
        btnSend.onmouseenter = function() { if (!btnSend.disabled) { btnSend.style.background = COLOR_BTN_SEND_HOVER; } };
        btnSend.onmouseleave = function() { if (!btnSend.disabled) { btnSend.style.background = COLOR_BTN_SEND; } };

        btnSend.addEventListener('click', function() {
            var label  = inputName.value.trim();
            var dateVal = inputDate.value;
            if (!label || !dateVal) { toast('Rellena el nombre y la fecha.', false); return; }

            var cutoff = Math.floor(new Date(dateVal + 'T23:59:59').getTime() / 1000);
            btnSend.disabled = true;
            btnSend.textContent = 'Guardando…';

            var fd = new FormData();
            fd.append('action',     'create_period');
            fd.append('sesskey',    sesskey);
            fd.append('courseid',   String(courseid));
            fd.append('label',      label);
            fd.append('cutoffdate', String(cutoff));

            fetch(ajaxUrl, {method: 'POST', body: fd})
                .then(function(r) { return r.json(); })
                .then(function(d) {
                    if (d.success) {
                        panel.style.display = 'none';
                        btn.textContent = '✓ ' + label;
                        btn.style.background = COLOR_DONE;
                        btn.style.pointerEvents = 'none';
                        toast('Comunicación "' + label + '" registrada. Ya aparece en el informe Sentinel de cada alumno.', true);
                    } else {
                        toast('Error: ' + (d.error || 'desconocido'), false);
                        btnSend.disabled = false;
                        btnSend.textContent = 'Cerrar comunicación';
                    }
                })
                .catch(function(err) {
                    toast('Error de red: ' + err.message, false);
                    btnSend.disabled = false;
                    btnSend.textContent = 'Cerrar comunicación';
                });
        });

        panel.appendChild(ptitle);
        panel.appendChild(mkLbl('Nombre de la evaluación'));
        panel.appendChild(inputName);
        panel.appendChild(mkLbl('Fecha de cierre'));
        panel.appendChild(inputDate);
        panel.appendChild(btnSend);
        document.body.appendChild(panel);

        // ── Main button — identical structure to sentinel-abbrev-btn ───────────
        var btn = document.createElement('button');
        btn.id   = 'sn-comm-btn';
        btn.type = 'button';
        btn.textContent = 'Comunicación';
        btn.style.cssText = [
            'position:fixed;z-index:1060;',
            'padding:5px 10px;border:none;border-radius:4px;',
            'color:#fff;font-size:0.75em;font-weight:600;',
            'white-space:nowrap;line-height:1.4;',
            'box-shadow:0 2px 6px rgba(0,0,0,0.3);',
            'transition:filter .15s;',
            'user-select:none;',
            'background:' + COLOR_DEFAULT + ';',
            'cursor:pointer;'
        ].join('');

        // Restore saved position or default (40px below abbrev button default).
        var savedPos = null;
        try { savedPos = JSON.parse(localStorage.getItem(POS_KEY) || 'null'); } catch(e) {}
        btn.style.top  = (savedPos ? savedPos.top  : 110) + 'px';
        btn.style.left = (savedPos ? savedPos.left : Math.max(0, window.innerWidth - 220)) + 'px';
        btn.style.right = 'auto';

        btn.onmouseenter = function() { btn.style.filter = 'brightness(1.15)'; };
        btn.onmouseleave = function() { btn.style.filter = ''; };

        // ── Drag (same logic as abbrev button) ─────────────────────────────────
        var dragging = false, didDrag = false, dragOX, dragOY, startX, startY;
        btn.addEventListener('mousedown', function(e) {
            dragging = true;
            didDrag  = false;
            startX = e.clientX; startY = e.clientY;
            dragOX = e.clientX - btn.getBoundingClientRect().left;
            dragOY = e.clientY - btn.getBoundingClientRect().top;
            btn.style.transition = 'none';
            e.preventDefault();
        });
        document.addEventListener('mousemove', function(e) {
            if (!dragging) { return; }
            if (!didDrag && Math.sqrt(Math.pow(e.clientX - startX, 2) + Math.pow(e.clientY - startY, 2)) < 4) { return; }
            didDrag = true;
            var left = Math.max(0, Math.min(window.innerWidth  - btn.offsetWidth,  e.clientX - dragOX));
            var top  = Math.max(0, Math.min(window.innerHeight - btn.offsetHeight, e.clientY - dragOY));
            btn.style.left = left + 'px';
            btn.style.top  = top  + 'px';
        });
        document.addEventListener('mouseup', function() {
            if (!dragging) { return; }
            dragging = false;
            btn.style.transition = '';
            if (didDrag) {
                try {
                    localStorage.setItem(POS_KEY, JSON.stringify({
                        left: btn.getBoundingClientRect().left,
                        top:  btn.getBoundingClientRect().top,
                    }));
                } catch(e) {}
            }
        });
        // Suppress click after drag (same as abbrev button).
        btn.addEventListener('click', function(e) {
            if (didDrag) { e.stopImmediatePropagation(); e.preventDefault(); didDrag = false; return; }
            // Toggle panel.
            if (panel.style.display !== 'none') {
                panel.style.display = 'none';
                return;
            }
            var rect = btn.getBoundingClientRect();
            // Position panel below the button, right-aligned.
            var panelLeft = rect.right - 260;
            if (panelLeft < 8) { panelLeft = 8; }
            panel.style.top   = (rect.bottom + 8) + 'px';
            panel.style.left  = panelLeft + 'px';
            panel.style.right = 'auto';
            panel.style.display = 'block';
            inputName.focus();
            inputName.select();
        }, true);

        // Close panel on outside click.
        document.addEventListener('click', function(e) {
            if (!btn.contains(e.target) && !panel.contains(e.target)) {
                panel.style.display = 'none';
            }
        });

        document.body.appendChild(btn);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
JS;

        $PAGE->requires->js_amd_inline($js);
    }

    /**
     * Keeps the left navigation drawer in its user-chosen state (open/closed)
     * regardless of scroll or theme-driven auto-hide behaviour.
     */
    private static function inject_drawer_persist(
        \core\hook\output\before_footer_html_generation $hook
    ): void {
        global $PAGE;

        $js = <<<'JS'
(function() {
    'use strict';

    var STORAGE_KEY = 'sentinel_drawer_open';

    var getDrawer = function() {
        return document.getElementById('nav-drawer') ||
               document.querySelector('[data-region="left-nav-drawer"]') ||
               document.querySelector('.drawer.drawer-left');
    };

    var isOpen = function(drawer) {
        return !drawer.classList.contains('closed') &&
               drawer.getAttribute('aria-hidden') !== 'true';
    };

    var applyOpen = function(drawer, open) {
        if (open) {
            drawer.classList.remove('closed');
            drawer.removeAttribute('aria-hidden');
        } else {
            drawer.classList.add('closed');
        }
    };

    var init = function() {
        var drawer = getDrawer();
        if (!drawer) { return; }

        // Move the drawer to be a direct child of <body> so that no ancestor
        // with overflow/transform can affect its fixed positioning.
        if (drawer.parentNode !== document.body) {
            document.body.appendChild(drawer);
        }

        // Apply fixed positioning directly as inline style (highest specificity).
        drawer.style.setProperty('position', 'fixed', 'important');
        drawer.style.setProperty('top',      '0',     'important');
        drawer.style.setProperty('left',     '0',     'important');
        drawer.style.setProperty('height',   '100vh', 'important');
        drawer.style.setProperty('z-index',  '1050',  'important');
        drawer.style.setProperty('overflow-y', 'auto',   'important');
        drawer.style.setProperty('overflow-x', 'hidden', 'important');

        // Restore saved preference.
        var saved = localStorage.getItem(STORAGE_KEY);
        if (saved !== null) {
            applyOpen(drawer, saved === '1');
        } else {
            localStorage.setItem(STORAGE_KEY, isOpen(drawer) ? '1' : '0');
        }

        // Track user toggle clicks.
        document.addEventListener('click', function(e) {
            var btn = e.target.closest(
                '[data-action="toggle-drawer"],' +
                '[data-toggler="drawers"],' +
                '[aria-controls="nav-drawer"],' +
                '.btn-drawer-toggle,' +
                '.drawertoggle'
            );
            if (!btn) { return; }
            setTimeout(function() {
                localStorage.setItem(STORAGE_KEY, isOpen(drawer) ? '1' : '0');
            }, 80);
        });
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
JS;

        $PAGE->requires->js_amd_inline($js);
    }
}

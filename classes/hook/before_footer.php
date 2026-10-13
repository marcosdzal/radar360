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
        global $PAGE, $DB, $COURSE;

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

        // ── Gradebook setup/settings: inject Sentinel buttons ────────
        if (str_ends_with($path, '/grade/edit/tree/index.php') ||
            str_ends_with($path, '/grade/edit/settings/index.php')) {
            // Curriculum selector button (green, first).
            $gradebook_courseid = optional_param('id', 0, PARAM_INT);
            if ($gradebook_courseid && has_capability('local/sentinel:managedescriptors',
                    \context_course::instance($gradebook_courseid))) {
                self::inject_curriculum_selector($hook, $gradebook_courseid);

            }

            // Descriptors mapping button (blue, second).
            if ($gradebook_courseid && has_capability('local/sentinel:managedescriptors',
                    \context_course::instance($gradebook_courseid))) {
                $url  = (new \moodle_url('/local/sentinel/admin/map_descriptors.php'))->out(false);
                $js_url   = json_encode($url);
                $js = <<<JS
(function() {
    'use strict';
    var btn = document.createElement('a');
    btn.href = {$js_url};
    btn.innerHTML = '<span style="display:flex;flex-direction:column;line-height:1.3">'
        + '<span style="font-weight:700">Criterios CE ↔ Descriptores operativos</span>'
        + '<span style="font-size:.85em;font-weight:400;opacity:.7">Vinculación con el perfil de salida (LOMLOE)</span>'
        + '</span>';
    btn.style.cssText = [
        'display:inline-flex;align-items:center;gap:6px',
        'padding:6px 14px',
        'background:#eff6ff;color:#1e40af',
        'border:1px solid #bfdbfe;border-radius:8px',
        'font-size:.8em;font-weight:600',
        'text-decoration:none',
        'box-shadow:0 1px 3px rgba(0,0,0,.06)',
        'transition:all 0.2s',
    ].join(';');
    btn.title = 'Establece qué descriptores operativos del perfil de salida trabaja cada criterio de evaluación, conectando las competencias específicas de la materia con las competencias clave de la LOMLOE';
    btn.onmouseenter = function() {
        btn.style.background = '#dbeafe';
        btn.style.borderColor = '#93c5fd';
        btn.style.transform = 'translateY(-1px)';
        btn.style.boxShadow = '0 3px 8px rgba(0,0,0,.1)';
    };
    btn.onmouseleave = function() {
        btn.style.background = '#eff6ff';
        btn.style.borderColor = '#bfdbfe';
        btn.style.transform = '';
        btn.style.boxShadow = '0 1px 3px rgba(0,0,0,.06)';
    };

    var inject = function() {
        var header = document.querySelector('.page-context-header') ||
                     document.querySelector('[data-region="page-header"]') ||
                     document.querySelector('#page-header');
        if (!header) { return; }
        var existing = document.getElementById('sentinel-map-btn');
        if (existing) { return; }
        btn.id = 'sentinel-map-btn';
        var h1 = header.querySelector('h1') || header.querySelector('.h1');
        if (h1) {
            var wrap = document.getElementById('sentinel-btn-wrap');
            if (!wrap) {
                wrap = document.createElement('div');
                wrap.id = 'sentinel-btn-wrap';
                wrap.style.cssText = 'display:flex;flex-wrap:wrap;gap:8px;margin-top:8px';
                h1.parentNode.insertBefore(wrap, h1.nextSibling);
            }
            wrap.appendChild(btn);
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

            // Colapsar categorías por defecto (solo totales) si el usuario no tiene preferencia.
            if ($courseid) {
                $prefkey = 'grade_report_grader_collapsed_categories' . $courseid;
                $existing = get_user_preferences($prefkey);
                if ($existing === null) {
                    $cats = $DB->get_records('grade_categories', ['courseid' => $courseid], '', 'id');
                    if ($cats) {
                        $catids = array_map('intval', array_keys($cats));
                        set_user_preference($prefkey, json_encode([
                            'aggregatesonly' => $catids,
                            'gradesonly' => [],
                        ]));
                    }
                }
            }
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

                // Mini diana data per student.
                $radar_map = [];
                foreach ($uids as $uid) {
                    $rd = self::get_mini_radar((int)$uid);
                    if ($rd) { $radar_map[(string)$uid] = $rd; }
                }

                $PAGE->requires->js_call_amd('local_sentinel/student_photo', 'initGraderReport', [
                    $photo_map,
                    $canmanage_photos ? (new \moodle_url('/local/sentinel/photo.php'))->out(false) : null,
                    $canmanage_photos ? sesskey() : null,
                    $radar_map,
                    (new \moodle_url('/local/sentinel/student_report.php', ['courseid' => $COURSE->id]))->out(false),
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
            // Communication button: available to users with the communicate capability.
            if ($courseid && has_capability('local/sentinel:communicate', \context_course::instance($courseid))) {
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
                $part_radar_map = [];
                foreach (array_keys($photo_map) as $puid) {
                    $rd = self::get_mini_radar((int)$puid);
                    if ($rd) { $part_radar_map[(string)$puid] = $rd; }
                }
                $PAGE->requires->js_call_amd('local_sentinel/student_photo', 'initParticipants', [
                    $photo_map, $upload_url, $canmanage_photos ? sesskey() : null, $part_radar_map,
                    (new \moodle_url('/local/sentinel/student_report.php', ['courseid' => $COURSE->id]))->out(false),
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
                        $grading_radar_map = [];
                        foreach ($enrolled as $u) {
                            $rd = self::get_mini_radar((int)$u->id);
                            if ($rd) { $grading_radar_map[(string)$u->id] = $rd; }
                        }
                        $PAGE->requires->js_call_amd('local_sentinel/student_photo', 'initGrading', [
                            $photo_map, $upload_url, $canmanage_photos ? sesskey() : null, $grading_radar_map,
                            (new \moodle_url('/local/sentinel/student_report.php', ['courseid' => $COURSE->id]))->out(false),
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

        // ── Activity edit & grade item edit: restructure category dropdown ──────
        if (str_ends_with($path, '/course/modedit.php') ||
            str_ends_with($path, '/grade/edit/tree/item.php') ||
            str_ends_with($path, '/grade/edit/tree/category.php')) {
            self::inject_category_dropdown_filter($hook);
        }

        // ── Diana de evaluación (radar chart) on student_report.php ─────────
        if (str_ends_with($path, '/local/sentinel/student_report.php')) {
            self::inject_radar_chart();
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
        $act_cat_name = get_string('activities_category', 'local_sentinel');
        $act_parent = $DB->get_record_select('grade_categories',
            'courseid = :cid AND fullname = :name',
            ['cid' => $courseid, 'name' => $act_cat_name], 'id');
        $act_parent_id = $act_parent ? (int)$act_parent->id : 0;

        $cat_rows = $DB->get_records_sql(
            'SELECT gc.id, gc.fullname, gc.parent, gc.depth, gi.sortorder
               FROM {grade_categories} gc
               JOIN {grade_items} gi
                 ON gi.itemtype     = :itype
                AND gi.iteminstance = gc.id
                AND gi.courseid     = :cid2
              WHERE gc.courseid = :cid
                AND gc.depth > 1
                AND gc.fullname <> :actname
                AND gc.parent <> :actparent
              ORDER BY gi.sortorder ASC',
            ['cid' => $courseid, 'cid2' => $courseid, 'itype' => 'category',
             'actname' => $act_cat_name, 'actparent' => $act_parent_id]
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
                $cats[] = ['id' => (int)$row->id, 'name' => $row->fullname, 'depth' => (int)$row->depth];
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
        if (empty($cats)) {
            foreach ($cat_rows as $row) {
                $cats[] = ['id' => (int)$row->id, 'name' => $row->fullname, 'depth' => (int)$row->depth];
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
                showTooltip([{criterion: 'Radar 360', level: {$str_loading}, score: null}], ev.clientX || 0, ev.clientY || 0);

                timer = setTimeout(function() {
                    fetchRows(parsed.userid, parsed.itemid, function(rows) {
                        if (!rows || !rows.length) {
                            showTooltip([{criterion: 'Radar 360', level: {$str_nodata}, score: null}], ev.clientX || 0, ev.clientY || 0);
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
            // For "Total ..." headers, strip the long description even in full mode.
            const totalMatch = text.match(/^((?:∑\s*)?Total\s+\S+)/);
            span.dataset.sentinelFull  = totalMatch ? totalMatch[1] : text;
            span.dataset.sentinelShort = totalMatch ? totalMatch[1] : short;
            span.title = text;
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

    // ── Cabecera: agrupar imágenes a la izq y texto a la der ───────────
    var layoutHeader = function() {
        var anchor = document.querySelector('[data-region="user-info"] h4 a') ||
                     document.querySelector('[data-region="user-info"] h4');
        if (!anchor || anchor.querySelector('.sentinel-user-text')) { return; }

        var imgBox  = document.createElement('span');
        imgBox.className = 'sentinel-imgs';
        var textBox = document.createElement('span');
        textBox.className = 'sentinel-user-text';

        var nodes = Array.prototype.slice.call(anchor.childNodes);
        for (var i = 0; i < nodes.length; i++) {
            var n = nodes[i];
            var isImg = (n.nodeType === 1 && (n.tagName === 'IMG' || n.tagName === 'CANVAS'));
            var isText = (n.nodeType === 3 && n.textContent.trim());
            var isEm   = (n.nodeType === 1 && n.tagName === 'EM');
            if (isImg) {
                imgBox.appendChild(n);
            } else if (isText || isEm) {
                textBox.appendChild(n);
            }
        }
        if (imgBox.childNodes.length)  { anchor.appendChild(imgBox); }
        if (textBox.childNodes.length) { anchor.appendChild(textBox); }
    };

    var layoutRun = function() {
        setTimeout(layoutHeader, 600);
        var nav = document.querySelector('[data-region="grading-navigation"]');
        if (nav) {
            new MutationObserver(function() {
                setTimeout(layoutHeader, 500);
            }).observe(nav, {childList: true, subtree: true});
        }
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', layoutRun);
    } else {
        layoutRun();
    }

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
            '  height:calc(100% - 38px) !important;' +
            '  overflow:auto !important;' +
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
                'min-width:8rem !important;max-width:min(30%,22rem) !important;' .
                'vertical-align:top;word-break:normal;overflow-wrap:break-word;' .
                'font-size:.88em;line-height:1.45;' .
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
                'max-width:13rem !important;min-width:5rem !important;width:auto !important;' .
                'vertical-align:top;box-sizing:border-box !important;' .
                'font-size:.85em;line-height:1.4;' .
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

        // ── Cabecera del grader: flexbox imágenes | texto ──
        $ui = '.path-mod-assign [data-region="user-info"] ';
        $css .= $ui . '{ overflow:visible !important;min-height:60px; }' . "\n";
        $css .= $ui . 'h4 { margin:0;padding:0; }' . "\n";
        $css .= $ui . 'h4 a, ' . $ui . 'h4 {' .
                'display:flex !important;align-items:flex-start;gap:10px;' .
                "}\n";
        $css .= $ui . '.sentinel-imgs {' .
                'display:flex;align-items:center;gap:6px;flex-shrink:0;' .
                "}\n";
        $css .= $ui . '.sentinel-user-text {' .
                'display:flex;flex-direction:column;justify-content:flex-start;' .
                'align-items:flex-start;text-align:left;' .
                'line-height:1.15;gap:0;min-width:0;' .
                "}\n";
        $css .= $ui . '.sentinel-user-text em.nowrap {' .
                'white-space:normal !important;font-style:normal;' .
                'margin:0 !important;padding:0 !important;' .
                "}\n";
        $css .= $ui . '.sentinel-user-text em.nowrap small {' .
                'font-size:.78em;color:#666;margin:0 !important;padding:0 !important;' .
                "}\n";
        $css .= '.path-mod-assign [data-region="grading-navigation"] {' .
                'overflow:visible !important;' .
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
     * Returns mini radar data for one student, or null if no occasions.
     */
    private static function get_mini_radar(int $userid): ?array {
        $area_colors = array_values(array_column(\local_sentinel\descriptor_calculator::AREAS, 'color'));
        try {
            $sdata = \local_sentinel\descriptor_calculator::get_student_data($userid);
            if ($sdata['summary']['total_occasions'] < 1) { return null; }
            $vals = []; $mocc = []; $msub = []; $mco = [];
            $sa = $sdata['areas'];
            $all_c = [];
            foreach ($sa as $ad) { foreach ($ad['courses'] as $c) { $all_c[$c] = true; } }
            $all_cl = array_keys($all_c);
            $mtc = count($all_c);
            foreach (\local_sentinel\descriptor_calculator::AREAS as $ac => $af) {
                $vals[] = $sa[$ac]['partial_grade'] ?? 0;
                $mocc[] = $sa[$ac]['occasions'];
                $msub[] = count($sa[$ac]['courses']);
                $co = array_fill_keys($all_cl, 0);
                foreach ($sa[$ac]['descriptors'] as $dd) {
                    foreach ($dd['activities'] as $act) {
                        $cn = $act['coursename'] ?? '';
                        if (isset($co[$cn])) { $co[$cn]++; }
                    }
                }
                $mco[] = array_values($co);
            }
            return ['v' => $vals, 'c' => $area_colors,
                'occ' => $mocc, 'sub' => $msub, 'tc' => $mtc, 'co' => $mco];
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Inyecta la foto Sentinel y el badge de nota final para un alumno concreto.
     * Centraliza la lógica común de todos los bloques de página individual.
     */
    private static function inject_profile_photo(int $userid): void {
        global $PAGE, $DB, $USER, $COURSE;

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
        $mini_radar = self::get_mini_radar($userid);
        $rparams = ['userid' => $userid];
        if (!empty($COURSE->id) && $COURSE->id != SITEID) {
            $rparams['courseid'] = $COURSE->id;
        }
        $report_url = (new \moodle_url('/local/sentinel/student_report.php', $rparams))->out(false);
        $PAGE->requires->js_call_amd('local_sentinel/student_photo', 'initUserProfile', [
            $userid, $photo_url, $upload_url, sesskey(), $grade_badge, $photo_strings, $mini_radar, $report_url,
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
            'max'   => 10,
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
                        toast('Comunicación "' + label + '" registrada. Ya aparece en el informe Radar 360 de cada alumno.', true);
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

    private static function inject_curriculum_selector(
        \core\hook\output\before_footer_html_generation $hook,
        int $courseid
    ): void {
        global $PAGE;

        $ajaxurl = (new \moodle_url('/local/sentinel/ajax.php'))->out(false);
        $sesskey = sesskey();
        $applied = \local_sentinel\curriculum_manager::get_applied($courseid);
        $locked  = \local_sentinel\curriculum_manager::course_has_grades($courseid);

        if ($applied && !empty($applied['community'])) {
            $catalog = \local_sentinel\curriculum_manager::get_catalog();
            foreach ($catalog['communities'] ?? [] as $c) {
                if ($c['id'] === $applied['community']) {
                    $applied['community_name'] = $c['name'];
                    break;
                }
            }
        }

        $js_ajaxurl = json_encode($ajaxurl);
        $js_sesskey = json_encode($sesskey);
        $js_courseid = (int)$courseid;
        $js_applied = json_encode($applied);
        $js_locked  = $locked ? 'true' : 'false';

        $js = <<<JS
(function() {
    'use strict';
    var AJAX = {$js_ajaxurl}, SK = {$js_sesskey}, CID = {$js_courseid};
    var applied = {$js_applied}, locked = {$js_locked};

    // ── Botón ──
    var stageAbbr = {'eso':'ESO','ei':'EI','ep':'EP','bach':'Bach','bachillerato':'Bach'};
    var btn = document.createElement('a');
    btn.href = '#';
    btn.id = 'sentinel-curriculum-btn';

    if (applied) {
        var stg = stageAbbr[applied.stage] || applied.stage.toUpperCase();
        var dec = (applied.decree || '').replace(/^Decreto\s+/, 'D ').replace(/^Real Decreto\s+/, 'RD ').replace(/,.*$/, '');
        btn.innerHTML = '<span style="font-weight:800;letter-spacing:.03em">' + stg + applied.grade + '</span>'
            + '<span style="width:1px;height:14px;background:#86efac;display:inline-block;vertical-align:middle;margin:0 2px"></span>'
            + '<span style="font-weight:700">' + applied.subject_name + '</span>'
            + '<span style="opacity:.65;font-weight:400;font-size:.85em">' + dec + '</span>';
    } else {
        btn.textContent = 'Radar 360 – Currículo';
    }

    btn.title = applied
        ? stg + applied.grade + ' ' + applied.subject_name + ' — regulado por ' + (applied.decree || 'normativa autonómica') + '. Pulsa para modificar.'
        : 'Configura la normativa curricular para generar automáticamente las competencias específicas y criterios de evaluación en el libro de calificaciones';

    var appliedBg = '#ecfdf5', appliedColor = '#065f46', appliedBorder = '#a7f3d0';
    var unsetBg = '#f5f3ff', unsetColor = '#5b21b6', unsetBorder = '#c4b5fd';
    btn.style.cssText = [
        'display:inline-flex;align-items:center;gap:8px',
        'padding:6px 14px',
        'background:' + (applied ? appliedBg : unsetBg),
        'color:' + (applied ? appliedColor : unsetColor),
        'border:1px solid ' + (applied ? appliedBorder : unsetBorder),
        'border-radius:8px;font-size:.8em;font-weight:600',
        'text-decoration:none',
        'box-shadow:0 1px 3px rgba(0,0,0,.06)',
        'transition:all 0.2s',
        'white-space:nowrap',
    ].join(';');
    btn.onmouseenter = function() {
        btn.style.background = applied ? '#d1fae5' : '#ede9fe';
        btn.style.borderColor = applied ? '#6ee7b7' : '#a78bfa';
        btn.style.transform = 'translateY(-1px)';
        btn.style.boxShadow = '0 3px 8px rgba(0,0,0,.1)';
    };
    btn.onmouseleave = function() {
        btn.style.background = applied ? appliedBg : unsetBg;
        btn.style.borderColor = applied ? appliedBorder : unsetBorder;
        btn.style.transform = '';
        btn.style.boxShadow = '0 1px 3px rgba(0,0,0,.06)';
    };

    // ── Modal ──
    var overlay = document.createElement('div');
    overlay.id = 'sentinel-curriculum-modal';
    overlay.style.cssText = 'display:none;position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,.5);justify-content:center;align-items:center';

    var modal = document.createElement('div');
    modal.style.cssText = 'background:#fff;border-radius:12px;padding:28px 32px;max-width:520px;width:90%;box-shadow:0 8px 32px rgba(0,0,0,.25);font-family:system-ui;color:#1e293b';

    var title = document.createElement('h3');
    title.style.cssText = 'margin:0 0 6px;font-size:1.15em';
    title.textContent = 'Configurar currículo del curso';

    var subtitle = document.createElement('p');
    subtitle.style.cssText = 'margin:0 0 18px;font-size:.85em;color:#64748b';
    subtitle.textContent = 'Selecciona la normativa y materia para poblar automáticamente el libro de calificaciones con las competencias específicas y criterios de evaluación.';

    var form = document.createElement('div');

    function mkSelect(id, label) {
        var wrap = document.createElement('div');
        wrap.style.cssText = 'margin-bottom:14px';
        var lbl = document.createElement('label');
        lbl.textContent = label;
        lbl.style.cssText = 'display:block;font-weight:600;font-size:.85em;margin-bottom:4px;color:#334155';
        lbl.htmlFor = id;
        var sel = document.createElement('select');
        sel.id = id;
        sel.style.cssText = 'width:100%;padding:8px 10px;border:1px solid #cbd5e1;border-radius:6px;font-size:.9em;background:#f8fafc';
        sel.innerHTML = '<option value="">— Selecciona —</option>';
        wrap.appendChild(lbl);
        wrap.appendChild(sel);
        form.appendChild(wrap);
        return sel;
    }

    var communityInfo = document.createElement('div');
    communityInfo.id = 'sc-community-info';
    communityInfo.style.cssText = 'margin-bottom:14px;padding:10px 12px;background:#f1f5f9;border:1px solid #e2e8f0;border-radius:6px;color:#64748b;font-size:.85em;display:none';
    form.appendChild(communityInfo);

    var selCommunity = mkSelect('sc-community', 'Comunidad Autónoma');
    var selStage     = mkSelect('sc-stage', 'Etapa');
    var selGrade     = mkSelect('sc-grade', 'Curso / Ciclo');
    var selSubject   = mkSelect('sc-subject', 'Materia / Área');

    var applyBtn = document.createElement('button');
    applyBtn.textContent = 'Aplicar currículo';
    applyBtn.disabled = true;
    applyBtn.style.cssText = 'width:100%;padding:10px;background:#7c3aed;color:#fff;border:none;border-radius:6px;font-size:.95em;font-weight:600;cursor:pointer;margin-top:6px;transition:background .15s';
    applyBtn.onmouseenter = function() { if (!applyBtn.disabled) applyBtn.style.background = '#6d28d9'; };
    applyBtn.onmouseleave = function() { if (!applyBtn.disabled) applyBtn.style.background = '#7c3aed'; };

    var statusMsg = document.createElement('p');
    statusMsg.style.cssText = 'margin:12px 0 0;font-size:.85em;text-align:center;display:none';

    var closeBtn = document.createElement('button');
    closeBtn.textContent = '✕';
    closeBtn.style.cssText = 'position:absolute;top:12px;right:16px;background:none;border:none;font-size:1.3em;cursor:pointer;color:#94a3b8';

    modal.style.position = 'relative';
    modal.appendChild(closeBtn);
    modal.appendChild(title);
    modal.appendChild(subtitle);
    modal.appendChild(form);
    modal.appendChild(applyBtn);
    modal.appendChild(statusMsg);
    overlay.appendChild(modal);
    document.body.appendChild(overlay);

    var catalog = null;

    function showMsg(text, color) {
        statusMsg.textContent = text;
        statusMsg.style.color = color || '#1e293b';
        statusMsg.style.display = 'block';
    }

    function openModal() {
        if (locked) {
            alert('Este curso ya tiene calificaciones. No se puede cambiar la estructura curricular.');
            return;
        }
        overlay.style.display = 'flex';
        if (!catalog) {
            loadCatalog();
        }
    }

    function closeModal() {
        overlay.style.display = 'none';
    }

    btn.addEventListener('click', function(e) { e.preventDefault(); openModal(); });
    closeBtn.addEventListener('click', closeModal);
    overlay.addEventListener('click', function(e) { if (e.target === overlay) closeModal(); });

    function loadCatalog() {
        fetch(AJAX + '?action=curriculum_catalog&courseid=' + CID + '&sesskey=' + SK)
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (!data.success) { showMsg('Error: ' + (data.error || 'desconocido'), '#dc2626'); return; }
                catalog = data.catalog;

                if (data.community && catalog.communities && catalog.communities.length === 1) {
                    var c = catalog.communities[0];
                    communityInfo.innerHTML = '<strong style="color:#334155">' + c.name + '</strong>'
                        + '<br><span style="font-size:.9em;color:#64748b">Desarrollo curricular LOMLOE</span>';
                    communityInfo.style.display = 'block';
                    selCommunity.parentNode.style.display = 'none';
                    selCommunity.innerHTML = '<option value="' + c.id + '" selected>' + c.name + '</option>';
                    selCommunity.value = c.id;
                    selCommunity.dispatchEvent(new Event('change'));
                } else if (!data.community) {
                    communityInfo.innerHTML = '<span style="color:#b45309">⚠ No se ha configurado la comunidad autónoma del centro.</span><br>'
                        + '<span style="font-size:.9em">Un administrador debe establecerla en </span>'
                        + '<a href="' + M.cfg.wwwroot + '/admin/settings.php?section=local_sentinel" style="font-size:.9em">Administración del sitio → Radar 360</a>'
                        + '<span style="font-size:.9em"> antes de poder aplicar el currículo.</span>';
                    communityInfo.style.display = 'block';
                    communityInfo.style.borderColor = '#fbbf24';
                    communityInfo.style.background = '#fffbeb';
                    selCommunity.parentNode.style.display = 'none';
                    selStage.parentNode.style.display = 'none';
                    selGrade.parentNode.style.display = 'none';
                    selSubject.parentNode.style.display = 'none';
                    applyBtn.style.display = 'none';
                } else {
                    communityInfo.style.display = 'none';
                    selCommunity.parentNode.style.display = '';
                    selCommunity.innerHTML = '<option value="">— Selecciona —</option>';
                    (catalog.communities || []).forEach(function(c) {
                        var o = document.createElement('option');
                        o.value = c.id;
                        o.textContent = c.name;
                        selCommunity.appendChild(o);
                    });
                }
            })
            .catch(function() { showMsg('Error de red al cargar el catálogo', '#dc2626'); });
    }

    selCommunity.addEventListener('change', function() {
        selStage.innerHTML = '<option value="">— Selecciona —</option>';
        selGrade.innerHTML = '<option value="">— Selecciona —</option>';
        selSubject.innerHTML = '<option value="">— Selecciona —</option>';
        applyBtn.disabled = true;
        var cid = selCommunity.value;
        if (!cid || !catalog) return;
        var community = catalog.communities.find(function(c) { return c.id === cid; });
        if (!community) return;
        (community.stages || []).forEach(function(s) {
            var o = document.createElement('option');
            o.value = s.id;
            o.textContent = s.name + (s.decree ? ' (' + s.decree + ')' : '');
            selStage.appendChild(o);
        });
    });

    selStage.addEventListener('change', function() {
        selGrade.innerHTML = '<option value="">— Selecciona —</option>';
        selSubject.innerHTML = '<option value="">— Selecciona —</option>';
        applyBtn.disabled = true;
        var cid = selCommunity.value, sid = selStage.value;
        if (!cid || !sid || !catalog) return;
        var community = catalog.communities.find(function(c) { return c.id === cid; });
        var stage = (community.stages || []).find(function(s) { return s.id === sid; });
        if (!stage) return;
        (stage.grades || []).forEach(function(g) {
            var o = document.createElement('option');
            o.value = g;
            o.textContent = g + '°';
            selGrade.appendChild(o);
        });
    });

    selGrade.addEventListener('change', function() {
        selSubject.innerHTML = '<option value="">— Selecciona —</option>';
        applyBtn.disabled = true;
        var cid = selCommunity.value, sid = selStage.value, gid = parseInt(selGrade.value);
        if (!cid || !sid || !gid || !catalog) return;
        var community = catalog.communities.find(function(c) { return c.id === cid; });
        var stage = (community.stages || []).find(function(s) { return s.id === sid; });
        if (!stage) return;
        (stage.subjects || []).filter(function(sub) {
            return sub.grades.indexOf(gid) !== -1;
        }).forEach(function(sub) {
            var o = document.createElement('option');
            o.value = sub.id;
            o.textContent = sub.name;
            selSubject.appendChild(o);
        });
    });

    selSubject.addEventListener('change', function() {
        applyBtn.disabled = !selSubject.value;
    });

    applyBtn.addEventListener('click', function() {
        applyBtn.disabled = true;
        applyBtn.textContent = 'Aplicando…';
        statusMsg.style.display = 'none';

        var params = new URLSearchParams();
        params.append('action', 'apply_curriculum');
        params.append('sesskey', SK);
        params.append('courseid', CID);
        params.append('community', selCommunity.value);
        params.append('stage', selStage.value);
        params.append('subject', selSubject.value);
        params.append('grade', selGrade.value);

        fetch(AJAX, { method: 'POST', body: params })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success) {
                    showMsg('✓ Creadas ' + data.categories + ' competencias y ' + data.criterios + ' criterios de evaluación.', '#059669');
                    applyBtn.textContent = '✓ Aplicado';
                    setTimeout(function() { location.reload(); }, 1500);
                } else {
                    showMsg('Error: ' + (data.error || 'desconocido'), '#dc2626');
                    applyBtn.textContent = 'Aplicar currículo';
                    applyBtn.disabled = false;
                }
            })
            .catch(function() {
                showMsg('Error de red', '#dc2626');
                applyBtn.textContent = 'Aplicar currículo';
                applyBtn.disabled = false;
            });
    });

    // ── Inyectar botón ──
    var inject = function() {
        var header = document.querySelector('.page-context-header') ||
                     document.querySelector('[data-region="page-header"]') ||
                     document.querySelector('#page-header');
        if (!header) return;
        var h1 = header.querySelector('h1') || header.querySelector('.h1');
        if (h1) {
            var wrap = document.getElementById('sentinel-btn-wrap');
            if (!wrap) {
                wrap = document.createElement('div');
                wrap.id = 'sentinel-btn-wrap';
                wrap.style.cssText = 'display:flex;flex-wrap:wrap;gap:8px;margin-top:8px';
                h1.parentNode.insertBefore(wrap, h1.nextSibling);
            }
            wrap.appendChild(btn);
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

    /**
     * Restructure the native Moodle grade category dropdown on activity edit
     * and grade item edit pages: CE categories become optgroup headers,
     * only subcategories (criterios) are selectable, and "Actividades (Radar 360)"
     * is hidden.
     */
    private static function inject_radar_chart(): void {
        global $PAGE;
        $js = <<<'JS'
(function() {
    "use strict";
    var canvas = document.getElementById("sentinel-radar");
    if (!canvas || !canvas.dataset.radar) return;
    var d = JSON.parse(canvas.dataset.radar);

    function draw() {
        var datasets = [];
        var dashPatterns = [[6, 3], [2, 3], [8, 4, 2, 4], [4, 4], [10, 3], [3, 6]];
        (d.periods || []).forEach(function(p, pi) {
            datasets.push({
                label: p.label,
                data: p.data.map(function(v) { return v !== null ? v : 0; }),
                borderColor: p.color,
                backgroundColor: p.color + "10",
                borderWidth: 2.5,
                borderDash: dashPatterns[pi % dashPatterns.length],
                pointRadius: 2,
                pointBackgroundColor: p.color,
                pointStyle: "line",
                fill: false,
                hidden: true
            });
        });
        var occ = d.occasions || [];
        var sub = d.subjects || [];
        var maxOcc = Math.max.apply(null, occ.length ? occ : [1]);
        var ptRadii = occ.map(function(o) { return 4 + Math.round((o / maxOcc) * 6); });

        var annualReal = d.annual.map(function(v) { return v !== null ? v : 0; });
        datasets.push({
            label: "Anual",
            data: annualReal.map(function() { return 0; }),
            borderColor: "#94a3b8",
            backgroundColor: "#94a3b815",
            borderWidth: 2.5,
            pointRadius: ptRadii,
            pointHoverRadius: ptRadii.map(function(r) { return r + 2; }),
            pointBackgroundColor: d.colors,
            pointBorderColor: "#fff",
            pointBorderWidth: 2,
            pointStyle: "circle",
            fill: true
        });

        var courseOcc = d.courseOcc || [];

        function getAnimatedPoint(chart, dsIdx, ptIdx) {
            var meta = chart.getDatasetMeta(dsIdx);
            if (meta && meta.data && meta.data[ptIdx]) {
                var el = meta.data[ptIdx];
                return { x: el.x, y: el.y };
            }
            return { x: chart.scales.r.xCenter, y: chart.scales.r.yCenter };
        }

        var sectorsPlugin = {
            id: "sentinelSectors",
            beforeDatasetsDraw: function(chart) {
                var sc = chart.scales.r;
                if (!sc) return;
                var annualIdx = datasets.length - 1;
                if (!chart.isDatasetVisible(annualIdx)) return;
                var ctx = chart.ctx;
                var n = datasets[annualIdx].data.length;
                var mxO = Math.max.apply(null, occ.length ? occ : [1]);
                for (var i = 0; i < n; i++) {
                    var prevI = (i - 1 + n) % n;
                    var nextI = (i + 1) % n;
                    var pPrev = getAnimatedPoint(chart, annualIdx, prevI);
                    var pCurr = getAnimatedPoint(chart, annualIdx, i);
                    var pNext = getAnimatedPoint(chart, annualIdx, nextI);
                    var midL = { x: (pPrev.x + pCurr.x) / 2, y: (pPrev.y + pCurr.y) / 2 };
                    var midR = { x: (pCurr.x + pNext.x) / 2, y: (pCurr.y + pNext.y) / 2 };
                    ctx.save();
                    ctx.beginPath();
                    ctx.moveTo(sc.xCenter, sc.yCenter);
                    ctx.lineTo(midL.x, midL.y);
                    ctx.lineTo(pCurr.x, pCurr.y);
                    ctx.lineTo(midR.x, midR.y);
                    ctx.closePath();
                    ctx.fillStyle = d.colors[i] || "#666";
                    ctx.globalAlpha = 0.05 + 0.10 * ((occ[i] || 0) / mxO);
                    ctx.fill();
                    ctx.strokeStyle = "#fff";
                    ctx.globalAlpha = 0.5;
                    ctx.lineWidth = 1;
                    ctx.stroke();
                    ctx.restore();
                }
            }
        };

        var ringsPlugin = {
            id: "sentinelRings",
            afterDatasetsDraw: function(chart) {
                var sc = chart.scales.r;
                if (!sc) return;
                var annualIdx = datasets.length - 1;
                if (!chart.isDatasetVisible(annualIdx)) return;
                var ctx = chart.ctx;
                var tc = d.totalCourses || 0;
                if (!tc) return;
                var n = datasets[annualIdx].data.length;
                for (var i = 0; i < n; i++) {
                    var pt = getAnimatedPoint(chart, annualIdx, i);
                    var cx = pt.x;
                    var cy = pt.y;
                    var color = d.colors[i] || "#666";
                    var outerR = (ptRadii[i] || 6) + 4;
                    var innerR = outerR - 3;
                    var segAngle = (Math.PI * 2) / tc;
                    var gap = tc > 6 ? 0.04 : 0.08;
                    var co = (courseOcc[i] || []).slice().sort(function(a, b) { return b - a; });
                    var maxCO = 1;
                    for (var m = 0; m < co.length; m++) { if (co[m] > maxCO) maxCO = co[m]; }
                    ctx.save();
                    for (var s = 0; s < tc; s++) {
                        var a0 = -Math.PI / 2 + s * segAngle + gap;
                        var a1 = -Math.PI / 2 + (s + 1) * segAngle - gap;
                        ctx.beginPath();
                        ctx.arc(cx, cy, outerR, a0, a1);
                        ctx.arc(cx, cy, innerR, a1, a0, true);
                        ctx.closePath();
                        var v = co[s] || 0;
                        if (v > 0) {
                            ctx.fillStyle = color;
                            ctx.globalAlpha = 0.3 + 0.65 * (v / maxCO);
                        } else {
                            ctx.fillStyle = "#d1d5db";
                            ctx.globalAlpha = 0.3;
                        }
                        ctx.fill();
                    }
                    ctx.globalAlpha = 1;
                    ctx.restore();
                }
            }
        };

        var grades = d.grades || [];
        var gradeColors = d.gradeColors || [];

        var lg = d.lomloeGrades || [];
        var lgc = d.lomloeColors || [];
        var wk = d.worked || [];
        var td = d.totalDesc || [];

        var cardsPlugin = {
            id: "sentinelCards",
            _els: null,
            _overlay: null,
            afterDraw: function(chart) {
                var r = chart.scales.r;
                if (!r) return;
                var n = d.labels.length;
                var tc = d.totalCourses || 0;
                var annualIdx = datasets.length - 1;
                var visible = chart.isDatasetVisible(annualIdx);

                if (!this._overlay) {
                    this._overlay = document.createElement("div");
                    this._overlay.style.cssText = "position:absolute;top:0;left:0;width:100%;height:100%;pointer-events:none;overflow:visible;z-index:10;";
                    canvas.parentNode.appendChild(this._overlay);
                    this._els = [];
                    for (var i = 0; i < n; i++) {
                        var color = d.colors[i] || "#666";
                        var label = d.labels[i];
                        var grade = grades[i];
                        var gc = gradeColors[i];
                        var gradeText = (grade !== null && grade !== undefined) ? grade.toFixed(1) : "–";
                        var lomloeVal = (lg[i] !== null && lg[i] !== undefined) ? lg[i] : null;
                        var showLomloe = lomloeVal !== null && grade !== null && lomloeVal.toFixed(1) !== grade.toFixed(1);

                        var card = document.createElement("div");
                        card.style.cssText = "position:absolute;pointer-events:auto;background:transparent;border-radius:10px;padding:6px 10px;cursor:pointer;transition:background .2s;transform:translate(-50%,-50%);font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;line-height:1.3;text-align:left;white-space:nowrap;";
                        (function(c) {
                            card.addEventListener("mouseenter", function() { this.style.background = c + "20"; });
                            card.addEventListener("mouseleave", function() { this.style.background = "transparent"; });
                        })(color);
                        (function(areaCode) {
                            card.addEventListener("click", function() { if (window.sentinelShowArea) window.sentinelShowArea(areaCode); });
                        })(d.codes ? d.codes[i] : label);

                        var row1 = '<div style="display:flex;align-items:baseline;gap:6px">';
                        row1 += '<span style="background:' + color + ';color:#fff;font-weight:700;font-size:11.5px;padding:2px 8px;border-radius:5px;letter-spacing:.02em">' + label + '</span>';
                        row1 += '<span style="font-weight:800;font-size:15px;color:' + (gc || "#333") + '">' + gradeText + '</span>';
                        row1 += '</div>';

                        var info = '<div style="font-size:9px;color:#8b919a;margin-top:3px;line-height:1.5">';
                        if (showLomloe) {
                            var lc2 = lgc[i] || "#6b7280";
                            info += '<div><span style="font-weight:700;color:' + lc2 + '">Global: ' + lomloeVal.toFixed(1) + '</span> <span style="color:#b0b5be">(' + (wk[i] || 0) + '/' + (td[i] || 0) + ' desc.)</span></div>';
                        }
                        info += '<div>' + (td[i] || 0) + ' descriptores</div>';
                        if (occ[i]) {
                            var occMatLine = occ[i] + (occ[i] === 1 ? ' ocasión' : ' ocasiones');
                            if (tc) occMatLine += ' – ' + sub[i] + '/' + tc + (sub[i] === 1 ? ' materia' : ' materias');
                            info += '<div>' + occMatLine + '</div>';
                        }
                        info += '</div>';

                        card.innerHTML = row1 + info;
                        this._overlay.appendChild(card);
                        this._els.push(card);
                    }
                }

                this._overlay.style.display = visible ? "" : "none";
                if (!visible) return;

                for (var i = 0; i < n; i++) {
                    var pos = r.getPointLabelPosition(i);
                    this._els[i].style.left = pos.left + "px";
                    this._els[i].style.top = pos.top + "px";
                }
            }
        };

        var chartInst = new Chart(canvas, {
            type: "radar",
            data: { labels: d.labels.map(function() { return ""; }), datasets: datasets },
            plugins: [sectorsPlugin, ringsPlugin, cardsPlugin],
            options: {
                responsive: true,
                maintainAspectRatio: true,
                devicePixelRatio: Math.max(window.devicePixelRatio || 1, 2),
                layout: { padding: { top: 52, bottom: 52, left: 52, right: 52 } },
                scales: {
                    r: {
                        min: 0, max: 10,
                        ticks: { stepSize: 2, backdropColor: "transparent", font: { size: 10 }, color: "#d1d5db" },
                        pointLabels: { display: true, font: { size: 1 }, color: "transparent", padding: 56 },
                        grid: { color: "#e5e7eb" },
                        angleLines: { color: "#e5e7eb" }
                    }
                },
                animation: {
                    duration: 900,
                    easing: "easeOutCubic"
                },
                plugins: {
                    legend: { display: false },
                    tooltip: { enabled: false
                    }
                }
            }
        });

        var ch = Chart.getChart(canvas);
        var legBox = document.getElementById("sentinel-radar-legend");
        if (ch && legBox && ch.data.datasets.length > 1) {
            legBox.style.cssText = "display:flex;flex-direction:column;gap:8px;padding:12px 16px;background:#f8fafc;border-radius:10px;border:1px solid #e2e8f0;font:500 .76em/1.3 -apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif";
            ch.data.datasets.forEach(function(ds, di) {
                var row = document.createElement("div");
                row.style.cssText = "display:flex;align-items:center;gap:8px;cursor:pointer;transition:opacity .2s;user-select:none;padding:3px 0";
                row.style.opacity = ds.hidden ? ".4" : "1";
                var indicator = document.createElement("span");
                if (ds.borderDash && ds.borderDash.length) {
                    indicator.style.cssText = "width:24px;height:0;border-top:2.5px dashed " + ds.borderColor + ";display:inline-block;flex-shrink:0";
                } else {
                    indicator.style.cssText = "width:10px;height:10px;background:" + ds.borderColor + ";border-radius:50%;display:inline-block;flex-shrink:0;box-shadow:0 0 0 2px #fff,0 0 0 3px " + ds.borderColor + "25";
                }
                var lbl = document.createElement("span");
                lbl.style.cssText = "color:#374151;white-space:nowrap";
                lbl.textContent = ds.label;
                row.appendChild(indicator);
                row.appendChild(lbl);
                row.addEventListener("click", function() {
                    var meta = ch.getDatasetMeta(di);
                    meta.hidden = !meta.hidden;
                    row.style.opacity = meta.hidden ? ".4" : "1";
                    ch.update();
                });
                legBox.appendChild(row);
            });
        }

        var codes = d.codes || [];
        if (codes.length) {
            canvas.style.cursor = "pointer";
            canvas.addEventListener("click", function(e) {
                if (!window.sentinelShowArea) return;
                var ch = Chart.getChart(canvas);
                if (!ch) return;
                var pts = ch.getElementsAtEventForMode(e, "nearest", { intersect: true }, false);
                if (pts.length) {
                    var idx = pts[0].index;
                    if (codes[idx]) { window.sentinelShowArea(codes[idx]); return; }
                }
                var r = ch.scales.r;
                if (!r) return;
                var rect = canvas.getBoundingClientRect();
                var cx = e.clientX - rect.left;
                var cy = e.clientY - rect.top;
                for (var i = 0; i < codes.length; i++) {
                    var lp = r.getPointLabelPosition(i);
                    var dx = cx - lp.left;
                    var dy = cy - lp.top;
                    if (Math.abs(dx) < 50 && Math.abs(dy) < 35) {
                        window.sentinelShowArea(codes[i]);
                        return;
                    }
                }
            });
        }
        // Animate from center: update data to real values after creation.
        setTimeout(function() {
            var ds = chartInst.data.datasets;
            ds[ds.length - 1].data = annualReal;
            chartInst.update();
        }, 80);

        canvas._chartInstance = chartInst;
    }

    function drawMini() {
        var canvas = document.getElementById("sentinel-mini-radar");
        if (!canvas || !canvas.dataset.miniradar) return;
        var m = JSON.parse(canvas.dataset.miniradar);
        var w = canvas.width, h = canvas.height;
        var cx = w / 2, cy = h / 2;
        var maxR = Math.min(cx, cy) - 8;
        var n = m.v.length;
        var ctx = canvas.getContext("2d");
        ctx.clearRect(0, 0, w, h);

        var angle = function(i) { return (Math.PI * 2 * i / n) - Math.PI / 2; };
        var pointXY = function(i, val) {
            var r = (val / 10) * maxR;
            return { x: cx + Math.cos(angle(i)) * r, y: cy + Math.sin(angle(i)) * r };
        };

        // Grid polygons.
        for (var g = 2; g <= 10; g += 2) {
            ctx.strokeStyle = (g === 10) ? "#b0b5be" : "#e5e7eb";
            ctx.lineWidth = (g === 10) ? 1 : 0.5;
            ctx.beginPath();
            for (var gi = 0; gi < n; gi++) {
                var gp = pointXY(gi, g);
                if (gi === 0) { ctx.moveTo(gp.x, gp.y); } else { ctx.lineTo(gp.x, gp.y); }
            }
            ctx.closePath();
            ctx.stroke();
        }
        // Angle lines.
        ctx.strokeStyle = "#e5e7eb";
        ctx.lineWidth = 0.5;
        for (var i = 0; i < n; i++) {
            var ep = pointXY(i, 10);
            ctx.beginPath(); ctx.moveTo(cx, cy); ctx.lineTo(ep.x, ep.y); ctx.stroke();
        }

        // Colored sector fill.
        var mOccS = m.occ || [];
        var maxOS = 1;
        for (var si = 0; si < mOccS.length; si++) { if (mOccS[si] > maxOS) maxOS = mOccS[si]; }
        for (var i = 0; i < n; i++) {
            var prevI = (i - 1 + n) % n;
            var nextI = (i + 1) % n;
            var pPrev = pointXY(prevI, m.v[prevI] || 0);
            var pCurr = pointXY(i, m.v[i] || 0);
            var pNext = pointXY(nextI, m.v[nextI] || 0);
            var midL = { x: (pPrev.x + pCurr.x) / 2, y: (pPrev.y + pCurr.y) / 2 };
            var midR = { x: (pCurr.x + pNext.x) / 2, y: (pCurr.y + pNext.y) / 2 };
            ctx.beginPath();
            ctx.moveTo(cx, cy);
            ctx.lineTo(midL.x, midL.y);
            ctx.lineTo(pCurr.x, pCurr.y);
            ctx.lineTo(midR.x, midR.y);
            ctx.closePath();
            ctx.fillStyle = m.c[i] || "#666";
            ctx.globalAlpha = 0.05 + 0.10 * ((mOccS[i] || 0) / maxOS);
            ctx.fill();
            ctx.strokeStyle = "#fff";
            ctx.globalAlpha = 0.5;
            ctx.lineWidth = 0.5;
            ctx.stroke();
        }
        ctx.globalAlpha = 1;

        // Data polygon border.
        ctx.beginPath();
        for (var i = 0; i < n; i++) {
            var p = pointXY(i, m.v[i] || 0);
            if (i === 0) { ctx.moveTo(p.x, p.y); } else { ctx.lineTo(p.x, p.y); }
        }
        ctx.closePath();
        ctx.strokeStyle = "#94a3b8";
        ctx.lineWidth = 1;
        ctx.stroke();

        // Points + donut rings.
        var mOcc = m.occ || [];
        var maxO = 1;
        for (var i = 0; i < mOcc.length; i++) { if (mOcc[i] > maxO) maxO = mOcc[i]; }
        var tc = m.tc || 0;

        for (var i = 0; i < n; i++) {
            var p = pointXY(i, m.v[i] || 0);
            var pr = 1.5 + Math.round(((mOcc[i] || 0) / maxO) * 2.5);
            var color = m.c[i] || "#666";

            if (tc > 0) {
                var outerR = pr + 2.5;
                var innerR = outerR - 1.5;
                var segAngle = (Math.PI * 2) / tc;
                var gap = tc > 6 ? 0.04 : 0.08;
                var co = (m.co[i] || []).slice().sort(function(a, b) { return b - a; });
                var mxC = 1;
                for (var j = 0; j < co.length; j++) { if (co[j] > mxC) mxC = co[j]; }
                for (var s = 0; s < tc; s++) {
                    var a0 = -Math.PI / 2 + s * segAngle + gap;
                    var a1 = -Math.PI / 2 + (s + 1) * segAngle - gap;
                    ctx.beginPath();
                    ctx.arc(p.x, p.y, outerR, a0, a1);
                    ctx.arc(p.x, p.y, innerR, a1, a0, true);
                    ctx.closePath();
                    var v = co[s] || 0;
                    if (v > 0) {
                        ctx.fillStyle = color;
                        ctx.globalAlpha = 0.3 + 0.65 * (v / mxC);
                    } else {
                        ctx.fillStyle = "#d1d5db";
                        ctx.globalAlpha = 0.3;
                    }
                    ctx.fill();
                }
                ctx.globalAlpha = 1;
            }

            ctx.beginPath();
            ctx.arc(p.x, p.y, pr, 0, Math.PI * 2);
            ctx.fillStyle = color;
            ctx.fill();
            ctx.strokeStyle = "#fff";
            ctx.lineWidth = 0.5;
            ctx.stroke();
        }
    }

    window.sentinelRedrawRadar = function() {
        var rc = document.getElementById("sentinel-radar");
        if (rc && rc._chartInstance) {
            rc._chartInstance.resize();
        } else {
            draw();
        }
    };

    function drawSubjectRadar() {
        var canvas = document.getElementById("sentinel-subject-radar");
        if (!canvas || !canvas.dataset.subjectradar) return;
        if (canvas._chartInstance) { canvas._chartInstance.resize(); return; }
        var s = JSON.parse(canvas.dataset.subjectradar);
        var n = s.labels.length;
        if (!n) return;

        var shortLabels = s.labels.map(function(l, idx) {
            var m = l.match(/^(CE\s*\d+)/i);
            if (m) return m[1];
            var m2 = l.match(/^(\d+)\.\s/);
            if (m2) return "CE" + m2[1];
            return "CE" + (idx + 1);
        });

        var vals = s.grades.map(function(v) { return v !== null ? v : 0; });

        var subjectSectorsPlugin = {
            id: "subjectSectors",
            beforeDatasetsDraw: function(chart) {
                var sc = chart.scales.r;
                if (!sc) return;
                var ctx = chart.ctx;
                for (var i = 0; i < n; i++) {
                    var prevI = (i - 1 + n) % n;
                    var nextI = (i + 1) % n;
                    var angPrev = sc.getIndexAngle(prevI) - Math.PI / 2;
                    var angCurr = sc.getIndexAngle(i) - Math.PI / 2;
                    var angNext = sc.getIndexAngle(nextI) - Math.PI / 2;
                    var dPrev = sc.getDistanceFromCenterForValue(vals[prevI]);
                    var dCurr = sc.getDistanceFromCenterForValue(vals[i]);
                    var dNext = sc.getDistanceFromCenterForValue(vals[nextI]);
                    var pPrev = { x: sc.xCenter + Math.cos(angPrev) * dPrev, y: sc.yCenter + Math.sin(angPrev) * dPrev };
                    var pCurr = { x: sc.xCenter + Math.cos(angCurr) * dCurr, y: sc.yCenter + Math.sin(angCurr) * dCurr };
                    var pNext = { x: sc.xCenter + Math.cos(angNext) * dNext, y: sc.yCenter + Math.sin(angNext) * dNext };
                    var midL = { x: (pPrev.x + pCurr.x) / 2, y: (pPrev.y + pCurr.y) / 2 };
                    var midR = { x: (pCurr.x + pNext.x) / 2, y: (pCurr.y + pNext.y) / 2 };
                    ctx.save();
                    ctx.beginPath();
                    ctx.moveTo(sc.xCenter, sc.yCenter);
                    ctx.lineTo(midL.x, midL.y);
                    ctx.lineTo(pCurr.x, pCurr.y);
                    ctx.lineTo(midR.x, midR.y);
                    ctx.closePath();
                    ctx.fillStyle = s.colors[i] || "#f59e0b";
                    ctx.globalAlpha = 0.12;
                    ctx.fill();
                    ctx.strokeStyle = "#fffbeb";
                    ctx.globalAlpha = 0.6;
                    ctx.lineWidth = 1;
                    ctx.stroke();
                    ctx.restore();
                }
            }
        };

        var cw = s.critWorked || [];
        var ct = s.critTotal  || [];
        var oc = s.occasions  || [];
        var dy = s.days       || [];

        var subjectCardsPlugin = {
            id: "subjectCards",
            _els: null,
            _overlay: null,
            afterDraw: function(chart) {
                var r = chart.scales.r;
                if (!r) return;

                if (!this._overlay) {
                    this._overlay = document.createElement("div");
                    this._overlay.style.cssText = "position:absolute;top:0;left:0;width:100%;height:100%;pointer-events:none;overflow:visible;z-index:10;";
                    canvas.parentNode.appendChild(this._overlay);
                    this._els = [];
                    for (var i = 0; i < n; i++) {
                        var color = s.colors[i] || "#f59e0b";
                        var grade = s.grades[i];
                        var gc = grade !== null ? "hsl(" + Math.round((grade / 10) * 120) + ",65%,36%)" : "#999";
                        var gradeText = grade !== null ? grade.toFixed(1) : "–";
                        var card = document.createElement("div");
                        card.style.cssText = "position:absolute;pointer-events:auto;background:transparent;border-radius:10px;padding:6px 10px;cursor:pointer;transition:background .2s;transform:translate(-50%,-50%);font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;line-height:1.3;text-align:left;white-space:nowrap;";
                        (function(c) {
                            card.addEventListener("mouseenter", function() { this.style.background = c + "20"; });
                            card.addEventListener("mouseleave", function() { this.style.background = "transparent"; });
                        })(color);
                        (function(idx) {
                            card.addEventListener("click", function() { if (window.sentinelShowCE) window.sentinelShowCE(idx); });
                        })(i);

                        var row1 = '<div style="display:flex;align-items:baseline;gap:6px">';
                        row1 += '<span style="background:' + color + ';color:#fff;font-weight:700;font-size:11.5px;padding:2px 8px;border-radius:5px;letter-spacing:.02em">' + shortLabels[i] + '</span>';
                        row1 += '<span style="font-weight:800;font-size:15px;color:' + gc + '">' + gradeText + '</span>';
                        row1 += '</div>';

                        var info = '<div style="font-size:9px;color:#8b919a;margin-top:3px;line-height:1.5">';
                        var critW = cw[i] || 0;
                        var critT = ct[i] || 0;
                        var partial = critW < critT;
                        if (grade !== null && partial) {
                            info += '<div><span style="font-weight:700;color:' + gc + '">Global: ' + gradeText + '</span> <span style="color:#b0b5be">(' + critW + '/' + critT + ' crit.)</span></div>';
                        }
                        info += '<div>' + critT + (critT === 1 ? ' criterio' : ' criterios') + '</div>';
                        if (oc[i]) {
                            var occLine = oc[i] + (oc[i] === 1 ? ' ocasión' : ' ocasiones');
                            if (dy[i]) occLine += ' / ' + dy[i] + (dy[i] === 1 ? ' día' : ' días');
                            info += '<div>' + occLine + '</div>';
                        }
                        info += '</div>';

                        card.innerHTML = row1 + info;
                        this._overlay.appendChild(card);
                        this._els.push(card);
                    }
                }

                for (var i = 0; i < n; i++) {
                    var pos = r.getPointLabelPosition(i);
                    this._els[i].style.left = pos.left + "px";
                    this._els[i].style.top = pos.top + "px";
                }
            }
        };

        var chartInst = new Chart(canvas, {
            type: "radar",
            data: {
                labels: shortLabels.map(function() { return ""; }),
                datasets: [{
                    label: s.courseName || "Materia",
                    data: vals.map(function() { return 0; }),
                    borderColor: "#f59e0b",
                    backgroundColor: "#f59e0b15",
                    borderWidth: 2.5,
                    pointRadius: vals.map(function(v) { return v > 0 ? 6 : 3; }),
                    pointHoverRadius: vals.map(function(v) { return v > 0 ? 8 : 5; }),
                    pointBackgroundColor: s.colors,
                    pointBorderColor: "#fff",
                    pointBorderWidth: 2,
                    fill: true
                }]
            },
            plugins: [subjectSectorsPlugin, subjectCardsPlugin],
            options: {
                responsive: true,
                maintainAspectRatio: true,
                devicePixelRatio: Math.max(window.devicePixelRatio || 1, 2),
                layout: { padding: { top: 48, bottom: 48, left: 48, right: 48 } },
                scales: {
                    r: {
                        min: 0, max: 10,
                        ticks: { stepSize: 2, backdropColor: "transparent", font: { size: 10 }, color: "#d4a574" },
                        pointLabels: { display: true, font: { size: 1 }, color: "transparent", padding: 52 },
                        grid: { color: "#fde68a" },
                        angleLines: { color: "#fde68a" }
                    }
                },
                animation: {
                    duration: 900,
                    easing: "easeOutCubic"
                },
                plugins: {
                    legend: { display: false },
                    tooltip: { enabled: false }
                }
            }
        });
        // Animate from center outward.
        setTimeout(function() {
            chartInst.data.datasets[0].data = vals;
            chartInst.update();
        }, 80);
        canvas._chartInstance = chartInst;
    }

    window.sentinelDrawSubjectRadar = function() {
        drawSubjectRadar();
    };

    function drawAll() { draw(); drawMini(); drawSubjectRadar(); }

    if (window.Chart) { drawAll(); return; }
    var _d = window.define; window.define = undefined;
    var s = document.createElement("script");
    s.src = M.cfg.wwwroot + "/local/sentinel/js/chart.umd.min.js";
    s.onload = function() { window.define = _d; drawAll(); };
    s.onerror = function() { window.define = _d; };
    document.head.appendChild(s);
})();
JS;
        $PAGE->requires->js_amd_inline($js);
    }

    private static function inject_category_dropdown_filter(
        \core\hook\output\before_footer_html_generation $hook
    ): void {
        global $PAGE, $DB;

        $courseid = optional_param('course', 0, PARAM_INT);
        if (!$courseid) {
            $courseid = optional_param('courseid', 0, PARAM_INT);
        }
        if (!$courseid) {
            $id = optional_param('id', 0, PARAM_INT);
            if ($id) {
                $cm = $DB->get_record('course_modules', ['id' => $id], 'course');
                if ($cm) {
                    $courseid = (int)$cm->course;
                }
            }
        }
        if (!$courseid) {
            $update = optional_param('update', 0, PARAM_INT);
            if ($update) {
                $cm = $DB->get_record('course_modules', ['id' => $update], 'course');
                if ($cm) {
                    $courseid = (int)$cm->course;
                }
            }
        }
        if (!$courseid) {
            return;
        }

        $act_name = get_string('activities_category', 'local_sentinel');
        $depth_rows = $DB->get_records_sql(
            'SELECT gc.id, gc.depth, gc.fullname, gc.parent
               FROM {grade_categories} gc
              WHERE gc.courseid = :cid AND gc.depth >= 2',
            ['cid' => $courseid]
        );
        if (empty($depth_rows)) {
            return;
        }

        // Find the "Actividades (Sentinel)" category id to tag its children.
        $act_cat_id = null;
        foreach ($depth_rows as $r) {
            if ($r->fullname === $act_name) {
                $act_cat_id = (int)$r->id;
                break;
            }
        }

        $cat_info = [];
        foreach ($depth_rows as $r) {
            $is_act = ($r->fullname === $act_name);
            $is_act_child = ($act_cat_id && (int)$r->parent === $act_cat_id);
            $cat_info[(int)$r->id] = [
                'depth'    => (int)$r->depth,
                'hide'     => $is_act,
                'noweight' => $is_act_child,
            ];
        }
        $js_catinfo = json_encode($cat_info);

        $js = <<<JS
(function() {
    'use strict';
    var catInfo = {$js_catinfo};

    function restructureSelect(sel) {
        if (sel.dataset.sentinelProcessed) return;
        sel.dataset.sentinelProcessed = '1';

        var options = Array.from(sel.options);
        var newSelect = document.createElement('select');
        newSelect.id = sel.id;
        newSelect.name = sel.name;
        newSelect.className = sel.className;
        newSelect.style.cssText = sel.style.cssText;

        var currentGroup = null;
        var selectedValue = sel.value;

        options.forEach(function(opt) {
            var catId = parseInt(opt.value, 10);
            var info = catInfo[catId];

            if (info && info.hide) return;

            if (info && info.depth === 2) {
                currentGroup = document.createElement('optgroup');
                currentGroup.label = opt.textContent.trim();
                newSelect.appendChild(currentGroup);
            } else {
                var newOpt = document.createElement('option');
                newOpt.value = opt.value;
                var text = opt.textContent;
                if (info && info.noweight) {
                    text = text.trim() + ' (sin peso — no vinculado al perfil de salida)';
                }
                newOpt.textContent = text;
                if (opt.value === selectedValue) newOpt.selected = true;
                (currentGroup || newSelect).appendChild(newOpt);
            }
        });

        sel.parentNode.replaceChild(newSelect, sel);
    }

    function run() {
        var selectors = ['#id_gradecat', '#id_parentcategory', 'select[name="parentcategory"]'];
        selectors.forEach(function(q) {
            var sel = document.querySelector(q);
            if (sel) restructureSelect(sel);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', run);
    } else {
        run();
    }
})();
JS;
        $PAGE->requires->js_amd_inline($js);
    }
}

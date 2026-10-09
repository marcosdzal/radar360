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
 * AJAX endpoint: save a rubric_row → grade_category mapping and manage grade items.
 *
 * POST params:
 *   sesskey           – Moodle session key (CSRF)
 *   rubric_row_id     – int
 *   grade_category_id – int (0 = remove mapping / hide grade item)
 *
 * @package    local_sentinel
 * @copyright  2024 Marcos
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/gradelib.php');

require_login();
require_sesskey();

header('Content-Type: application/json');

$action = optional_param('action', 'map', PARAM_ALPHANUMEXT);

// ── Acción: resumen de rúbrica para tooltip del informe del calificador ───────
if ($action === 'rubric_summary') {
    $userid = required_param('userid', PARAM_INT);
    $itemid = required_param('itemid', PARAM_INT); // grade_item.id

    // Encontrar el assign y su contexto a partir del grade_item.
    // Puede ser: (a) ítem de la actividad assign, o (b) ítem de criterio Sentinel (manual).
    $gi = $DB->get_record('grade_items', ['id' => $itemid]);
    if (!$gi) {
        echo json_encode(['success' => false, 'rows' => []]);
        exit;
    }

    if ($gi->itemtype === 'mod' && $gi->itemmodule === 'assign') {
        // Caso (a): ítem directo de la tarea.
        $cm = get_coursemodule_from_instance('assign', $gi->iteminstance, $gi->courseid);
    } else {
        // Caso (b): ítem de criterio Sentinel — resolver el assign a través de la tabla de mapa.
        $map = $DB->get_record('local_sentinel_map', ['grade_item_id' => $itemid]);
        if (!$map) {
            echo json_encode(['success' => true, 'rows' => []]);
            exit;
        }
        $crit = $DB->get_record('gradingform_rubric_criteria', ['id' => $map->rubric_row_id], 'definitionid');
        $def  = $DB->get_record('grading_definitions', ['id' => $crit->definitionid], 'areaid');
        $area = $DB->get_record('grading_areas', ['id' => $def->areaid], 'contextid');
        $ctx  = context::instance_by_id($area->contextid);
        if (!($ctx instanceof context_module)) {
            echo json_encode(['success' => true, 'rows' => []]);
            exit;
        }
        $cm = get_coursemodule_from_id('assign', $ctx->instanceid);
    }

    if (!$cm) {
        echo json_encode(['success' => false, 'rows' => []]);
        exit;
    }
    $context = context_module::instance($cm->id);

    // Only users who can grade the assignment may query rubric details.
    if (!has_capability('mod/assign:grade', $context)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'rows' => []]);
        exit;
    }

    // Buscar la instancia de calificación más reciente para este alumno.
    $instance = $DB->get_record_sql(
        'SELECT gi.id
           FROM {grading_instances} gi
           JOIN {grading_definitions} gd ON gd.id = gi.definitionid
           JOIN {grading_areas} ga ON ga.id = gd.areaid
           JOIN {assign_grades} ag ON ag.id = gi.itemid
          WHERE ga.contextid = :ctxid
            AND ag.userid    = :uid
            AND gd.method    = :method
          ORDER BY gi.timemodified DESC',
        ['ctxid' => $context->id, 'uid' => $userid, 'method' => 'rubric'],
        IGNORE_MULTIPLE
    );

    if (!$instance) {
        echo json_encode(['success' => true, 'rows' => []]);
        exit;
    }

    // Obtener criterios y nivel seleccionado para cada uno.
    $rows = $DB->get_records_sql(
        'SELECT c.description, l.definition, l.score, f.remark
           FROM {gradingform_rubric_fillings} f
           JOIN {gradingform_rubric_criteria} c ON c.id = f.criterionid
           LEFT JOIN {gradingform_rubric_levels} l ON l.id = f.levelid
          WHERE f.instanceid = :iid
          ORDER BY c.sortorder ASC',
        ['iid' => $instance->id]
    );

    $result = [];
    foreach ($rows as $row) {
        $result[] = [
            'criterion' => shorten_text(strip_tags($row->description), 60),
            'level'     => $row->definition ? shorten_text(strip_tags($row->definition), 80) : '—',
            'score'     => $row->score !== null ? (float)$row->score : null,
        ];
    }

    echo json_encode(['success' => true, 'rows' => $result]);
    exit;
}

// ── Acción: crear período de comunicación para un curso ───────────────────────
if ($action === 'create_period') {
    $courseid   = required_param('courseid',   PARAM_INT);
    $label      = required_param('label',      PARAM_TEXT);
    $cutoffdate = required_param('cutoffdate', PARAM_INT);

    if (!$DB->record_exists('course', ['id' => $courseid])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Curso no encontrado']);
        exit;
    }

    if (!has_capability('moodle/grade:viewall', context_course::instance($courseid))) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Sin permiso para crear comunicaciones']);
        exit;
    }

    $label = trim(substr($label, 0, 100));
    if (!$label || $cutoffdate <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Datos inválidos']);
        exit;
    }

    // Evitar duplicados: mismo curso y misma fecha de corte.
    if ($DB->record_exists('local_sentinel_period', ['courseid' => $courseid, 'cutoffdate' => $cutoffdate])) {
        http_response_code(409);
        echo json_encode(['success' => false, 'error' => 'Ya existe un período con esa fecha para este curso']);
        exit;
    }

    $max = (int)$DB->get_field_sql('SELECT COALESCE(MAX(sortorder),0) FROM {local_sentinel_period}');
    $id = $DB->insert_record('local_sentinel_period', (object)[
        'courseid'   => $courseid,
        'sortorder'  => $max + 1,
        'label'      => $label,
        'cutoffdate' => $cutoffdate,
    ]);

    echo json_encode(['success' => true, 'id' => (int)$id]);
    exit;
}

// ── Acción por defecto: guardar mapeo rubric_row → grade_category ─────────────
$rubric_row_id     = required_param('rubric_row_id',     PARAM_INT);
$grade_category_id = required_param('grade_category_id', PARAM_INT);

// ── Validate inputs ──────────────────────────────────────────────────────────

$criterion = $DB->get_record('gradingform_rubric_criteria', ['id' => $rubric_row_id]);
if (!$criterion) {
    echo json_encode(['success' => false, 'error' => 'invalid_criterion']);
    exit;
}

if ($grade_category_id !== 0 && !$DB->record_exists('grade_categories', ['id' => $grade_category_id])) {
    echo json_encode(['success' => false, 'error' => 'invalid_category']);
    exit;
}

// ── Resolve course and existing mapping ──────────────────────────────────────

$definition = $DB->get_record('grading_definitions', ['id' => $criterion->definitionid], 'id, areaid');
$area       = $DB->get_record('grading_areas',       ['id' => $definition->areaid],      'id, contextid');
$context    = context::instance_by_id($area->contextid);
$courseid   = $context->get_course_context()->instanceid;

// Only users who can manage grades in this course may create/update mappings.
if (!has_capability('moodle/grade:manage', context_course::instance($courseid))) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Forbidden']);
    exit;
}

$existing = $DB->get_record('local_sentinel_map', ['rubric_row_id' => $rubric_row_id]);

// ── Helper: find or create the grade item for this criterion ─────────────────

/**
 * Return the grade_item for a criterion, creating it if necessary.
 * Uses idnumber 'sentinel_crit_{id}' as a stable identifier.
 */
function sentinel_get_or_create_grade_item(
    int $rubric_row_id,
    object $criterion,
    int $grade_category_id,
    int $courseid
): grade_item {
    global $DB;

    $idnumber = 'sentinel_crit_' . $rubric_row_id;

    // Look up by stable idnumber first (guards against stale grade_item_id in map).
    $gi = grade_item::fetch(['idnumber' => $idnumber, 'courseid' => $courseid]);

    if ($gi) {
        // Move to new category and make visible.
        $gi->categoryid = $grade_category_id;
        $gi->grademax   = 10.0; // Normalización a base 10.
        $gi->hidden     = 0;
        $gi->update('local_sentinel');
        return $gi;
    }

    $gi             = new grade_item();
    $gi->courseid   = $courseid;
    $gi->categoryid = $grade_category_id;
    $gi->itemtype   = 'manual';
    $gi->itemname   = substr(strip_tags($criterion->description), 0, 255)
                      ?: 'Criterio ' . $rubric_row_id;
    $gi->grademax   = 10.0; // Siempre base 10 (normalización obligatoria).
    $gi->grademin   = 0;
    $gi->idnumber   = $idnumber;
    $gi->insert('local_sentinel');

    return $gi;
}

// ── Helper: hide/show the main activity grade item ───────────────────────────

/**
 * Hides the main activity grade item when Sentinel has at least one active
 * criterion mapping for this rubric, and restores it when all are removed.
 */
function sentinel_sync_activity_visibility(object $criterion, object $area, int $courseid): void {
    global $DB;

    // Gather all criterion IDs for this rubric definition.
    $crit_ids = $DB->get_fieldset_select(
        'gradingform_rubric_criteria', 'id', 'definitionid = :did',
        ['did' => $criterion->definitionid]
    );
    if (!$crit_ids) {
        return;
    }

    // Count how many criteria are still actively mapped after this operation.
    [$insql, $inparams] = $DB->get_in_or_equal($crit_ids, SQL_PARAMS_NAMED, 'crit');
    $inparams['cat'] = 0;
    $active = $DB->count_records_select(
        'local_sentinel_map',
        "rubric_row_id $insql AND grade_category_id > :cat",
        $inparams
    );

    // Find the main activity grade item via the module context.
    $context = context::instance_by_id($area->contextid);
    if (!($context instanceof context_module)) {
        return;
    }
    $cm = get_coursemodule_from_id('', $context->instanceid);
    if (!$cm) {
        return;
    }
    $activity_gi = grade_item::fetch([
        'itemtype'     => 'mod',
        'itemmodule'   => $cm->modname,
        'iteminstance' => $cm->instance,
        'courseid'     => $courseid,
    ]);
    if (!$activity_gi) {
        return;
    }

    // Find the course root category (depth=1, no parent).
    $root_cat = $DB->get_record_select(
        'grade_categories', 'courseid = :cid AND depth = 1', ['cid' => $courseid], 'id'
    );
    if (!$root_cat) {
        return;
    }

    // Find or create the "Actividades (Sentinel)" container category at root level.
    $sentinel_cat_name = 'Actividades (Sentinel)';
    $sentinel_cat = $DB->get_record_select(
        'grade_categories',
        "courseid = :cid AND depth = 2 AND fullname = :name",
        ['cid' => $courseid, 'name' => $sentinel_cat_name],
        'id'
    );
    if (!$sentinel_cat) {
        $now    = time();
        $cat_id = $DB->insert_record('grade_categories', (object)[
            'courseid'     => $courseid,
            'parent'       => $root_cat->id,
            'depth'        => 2,
            'path'         => null,
            'fullname'     => $sentinel_cat_name,
            'aggregation'  => GRADE_AGGREGATE_SUM,
            'keephigh'     => 0,
            'droplow'      => 0,
            'aggregateonlygraded' => 1,
            'aggregateoutcomes'   => 0,
            'timecreated'  => $now,
            'timemodified' => $now,
            'hidden'       => 0,
        ]);
        // Fix the path field (Moodle format: /parent_id/cat_id/).
        $DB->set_field('grade_categories', 'path',
            '/' . $root_cat->id . '/' . $cat_id . '/',
            ['id' => $cat_id]
        );
        // Create the associated grade_item for this category.
        $cat_gi             = new grade_item();
        $cat_gi->courseid   = $courseid;
        $cat_gi->categoryid = $root_cat->id;
        $cat_gi->itemtype   = 'category';
        $cat_gi->iteminstance = $cat_id;
        $cat_gi->itemname   = $sentinel_cat_name;
        $cat_gi->aggregationcoef  = 0;
        $cat_gi->aggregationcoef2 = 0;
        $cat_gi->grademax   = 100;
        $cat_gi->grademin   = 0;
        $cat_gi->insert('local_sentinel');

        $sentinel_cat = (object)['id' => $cat_id];
    }

    // Find or create "Nota de Rúbrica" subcategory inside "Actividades (Sentinel)".
    $rubric_cat_name = 'Nota de Rúbrica';
    $rubric_cat = $DB->get_record_select(
        'grade_categories',
        "courseid = :cid AND depth = 3 AND parent = :parent AND fullname = :name",
        ['cid' => $courseid, 'parent' => $sentinel_cat->id, 'name' => $rubric_cat_name],
        'id'
    );
    if (!$rubric_cat) {
        $now       = time();
        $rubric_id = $DB->insert_record('grade_categories', (object)[
            'courseid'     => $courseid,
            'parent'       => $sentinel_cat->id,
            'depth'        => 3,
            'path'         => null,
            'fullname'     => $rubric_cat_name,
            'aggregation'  => GRADE_AGGREGATE_SUM,
            'keephigh'     => 0,
            'droplow'      => 0,
            'aggregateonlygraded' => 1,
            'aggregateoutcomes'   => 0,
            'timecreated'  => $now,
            'timemodified' => $now,
            'hidden'       => 0,
        ]);
        $DB->set_field('grade_categories', 'path',
            '/' . $root_cat->id . '/' . $sentinel_cat->id . '/' . $rubric_id . '/',
            ['id' => $rubric_id]
        );
        $rub_gi               = new grade_item();
        $rub_gi->courseid     = $courseid;
        $rub_gi->categoryid   = $sentinel_cat->id;
        $rub_gi->itemtype     = 'category';
        $rub_gi->iteminstance = $rubric_id;
        $rub_gi->itemname     = $rubric_cat_name;
        $rub_gi->aggregationcoef  = 0;
        $rub_gi->aggregationcoef2 = 0;
        $rub_gi->grademax     = 100;
        $rub_gi->grademin     = 0;
        $rub_gi->insert('local_sentinel');

        $rubric_cat = (object)['id' => $rubric_id];
    }

    $config_key = 'orig_cat_' . $activity_gi->id;

    if ($active > 0) {
        // Sentinel is active: move grade item into "Nota de Rúbrica", weight 0.
        if ((int)$activity_gi->categoryid !== (int)$rubric_cat->id) {
            // Persist original values so we can restore them later.
            set_config($config_key, $activity_gi->categoryid, 'local_sentinel');
            set_config($config_key . '_coef',  $activity_gi->aggregationcoef,  'local_sentinel');
            set_config($config_key . '_coef2', $activity_gi->aggregationcoef2, 'local_sentinel');
        }
        $activity_gi->categoryid       = $rubric_cat->id;
        $activity_gi->hidden           = 0;
        $activity_gi->aggregationcoef  = 0;
        $activity_gi->aggregationcoef2 = 0;
        $activity_gi->update('local_sentinel');
    } else {
        // All mappings removed: restore original category and weight.
        $orig = get_config('local_sentinel', $config_key);
        if ($orig) {
            $activity_gi->categoryid = (int)$orig;
            $activity_gi->hidden     = 0;
            $coef  = get_config('local_sentinel', $config_key . '_coef');
            $coef2 = get_config('local_sentinel', $config_key . '_coef2');
            if ($coef  !== false) { $activity_gi->aggregationcoef  = (float)$coef; }
            if ($coef2 !== false) { $activity_gi->aggregationcoef2 = (float)$coef2; }
            $activity_gi->update('local_sentinel');
            unset_config($config_key,          'local_sentinel');
            unset_config($config_key . '_coef',  'local_sentinel');
            unset_config($config_key . '_coef2', 'local_sentinel');
        }
    }
}

// ── Main logic ───────────────────────────────────────────────────────────────

if ($grade_category_id === 0) {
    // REMOVE MAPPING: hide the grade item, keep the DB record.
    if ($existing && $existing->grade_item_id) {
        $gi = grade_item::fetch(['id' => $existing->grade_item_id]);
        if ($gi) {
            $gi->hidden = 1;
            $gi->update('local_sentinel');
        }
    }
    if ($existing) {
        $existing->grade_category_id = 0;
        $DB->update_record('local_sentinel_map', $existing);
    }

} else {
    // ASSIGN TO CATEGORY: create/update grade item and save mapping.
    $gi = sentinel_get_or_create_grade_item($rubric_row_id, $criterion, $grade_category_id, $courseid);

    if ($existing) {
        $existing->grade_category_id = $grade_category_id;
        $existing->grade_item_id     = $gi->id;
        $DB->update_record('local_sentinel_map', $existing);
    } else {
        $DB->insert_record('local_sentinel_map', (object)[
            'rubric_row_id'     => $rubric_row_id,
            'grade_category_id' => $grade_category_id,
            'grade_item_id'     => $gi->id,
        ]);
    }
}

// Hide or restore the main activity grade item depending on active mappings.
sentinel_sync_activity_visibility($criterion, $area, $courseid);

echo json_encode(['success' => true]);

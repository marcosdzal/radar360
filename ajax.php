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
        if (!$crit) { echo json_encode(['success' => true, 'rows' => []]); exit; }
        $def  = $DB->get_record('grading_definitions', ['id' => $crit->definitionid], 'areaid');
        if (!$def) { echo json_encode(['success' => true, 'rows' => []]); exit; }
        $area = $DB->get_record('grading_areas', ['id' => $def->areaid], 'contextid');
        if (!$area) { echo json_encode(['success' => true, 'rows' => []]); exit; }
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

    if (!has_capability('local/sentinel:communicate', context_course::instance($courseid))) {
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

    $max = (int)$DB->get_field_sql(
        'SELECT COALESCE(MAX(sortorder),0) FROM {local_sentinel_period} WHERE courseid = :cid OR courseid = 0',
        ['cid' => $courseid]
    );
    $id = $DB->insert_record('local_sentinel_period', (object)[
        'courseid'   => $courseid,
        'sortorder'  => $max + 1,
        'label'      => $label,
        'cutoffdate' => $cutoffdate,
    ]);

    echo json_encode(['success' => true, 'id' => (int)$id]);
    exit;
}

// ── Acción: catálogo de currículos disponibles ──────────────────────────────────
if ($action === 'curriculum_catalog') {
    $courseid = required_param('courseid', PARAM_INT);
    if (!has_capability('local/sentinel:managedescriptors', context_course::instance($courseid))) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Sin permisos para gestionar el currículo.']);
        exit;
    }

    $catalog = \local_sentinel\curriculum_manager::get_catalog();
    $applied = \local_sentinel\curriculum_manager::get_applied($courseid);
    $locked  = \local_sentinel\curriculum_manager::course_has_grades($courseid);

    $community_id = get_config('local_sentinel', 'community');
    $community_info = null;
    if ($community_id && !empty($catalog['communities'])) {
        foreach ($catalog['communities'] as $c) {
            if ($c['id'] === $community_id) {
                $community_info = $c;
                break;
            }
        }
        if ($community_info) {
            $catalog['communities'] = [$community_info];
        }
    }

    echo json_encode([
        'success'   => true,
        'catalog'   => $catalog,
        'applied'   => $applied,
        'locked'    => $locked,
        'community' => $community_id ?: null,
    ]);
    exit;
}

// ── Acción: aplicar currículo a un curso ────────────────────────────────────────
if ($action === 'apply_curriculum') {
    $courseid  = required_param('courseid',  PARAM_INT);
    $community = required_param('community', PARAM_ALPHANUMEXT);
    $stage     = required_param('stage',     PARAM_ALPHANUMEXT);
    $subject   = required_param('subject',   PARAM_ALPHANUMEXT);
    $grade     = required_param('grade',     PARAM_INT);

    if (!has_capability('local/sentinel:managedescriptors', context_course::instance($courseid))) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Sin permisos para aplicar el currículo.']);
        exit;
    }

    $result = \local_sentinel\curriculum_manager::apply($courseid, $community, $stage, $subject, $grade);
    if (!$result['success']) {
        http_response_code(400);
    }
    echo json_encode($result);
    exit;
}

// ── Acción: eliminar currículo aplicado ───────────────────────────────────────
if ($action === 'remove_curriculum') {
    $courseid = required_param('courseid', PARAM_INT);

    if (!has_capability('local/sentinel:managedescriptors', context_course::instance($courseid))) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Sin permisos.']);
        exit;
    }

    if (\local_sentinel\curriculum_manager::course_has_grades($courseid)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'No se puede eliminar: el curso ya tiene calificaciones.']);
        exit;
    }

    \local_sentinel\curriculum_manager::remove_applied($courseid);
    echo json_encode(['success' => true]);
    exit;
}

// ── Acción: guardar borrador de desgrane de contenidos ──────────────────────
if ($action === 'save_desgrane') {
    $courseid = required_param('courseid', PARAM_INT);
    $subject  = required_param('subject', PARAM_ALPHANUMEXT);

    $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
    $ctx = context_course::instance($courseid);
    require_capability('local/sentinel:managedescriptors', $ctx);

    $data = file_get_contents('php://input');
    $json = json_decode($data, true);
    if (!isset($json['blocks'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Datos inválidos.']);
        exit;
    }

    set_config("desgrane_{$courseid}_{$subject}", json_encode($json['blocks']), 'local_sentinel');
    echo json_encode(['success' => true]);
    exit;
}

// ── Acción: inyectar contenidos en el módulo de competencias de Moodle ─────
if ($action === 'inject_desgrane') {
    $courseid = required_param('courseid', PARAM_INT);
    $subject  = required_param('subject', PARAM_ALPHANUMEXT);

    $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
    $ctx = context_course::instance($courseid);
    require_capability('local/sentinel:managedescriptors', $ctx);

    $data = file_get_contents('php://input');
    $json = json_decode($data, true);
    if (!isset($json['blocks'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Datos inválidos.']);
        exit;
    }

    $result = \local_sentinel\curriculum_manager::inject_contenidos($courseid, $subject, $json['blocks']);
    if (!$result['success']) {
        http_response_code(400);
    }
    echo json_encode($result);
    exit;
}

// ── Acción: cargar borrador de desgrane de contenidos ───────────────────────
if ($action === 'load_desgrane') {
    $courseid = required_param('courseid', PARAM_INT);
    $subject  = required_param('subject', PARAM_ALPHANUMEXT);

    $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
    $ctx = context_course::instance($courseid);
    require_capability('local/sentinel:managedescriptors', $ctx);

    $saved = get_config('local_sentinel', "desgrane_{$courseid}_{$subject}");
    if ($saved) {
        echo json_encode(['success' => true, 'blocks' => json_decode($saved, true)]);
    } else {
        echo json_encode(['success' => true, 'blocks' => null]);
    }
    exit;
}

// ── Acción: configurar autoevaluación del alumnado en una actividad ──────────
if ($action === 'selfeval_config') {
    $cmid     = required_param('cmid', PARAM_INT);
    $required = optional_param('required', -1, PARAM_INT);

    $cm = get_coursemodule_from_id('assign', $cmid);
    if (!$cm) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Actividad no encontrada']);
        exit;
    }
    $ctx = context_module::instance($cmid);

    if (!has_capability('mod/assign:grade', $ctx)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Sin permisos']);
        exit;
    }

    if ($required === -1) {
        $rec = $DB->get_record('local_sentinel_selfeval_config', ['cmid' => $cmid]);
        echo json_encode([
            'success'  => true,
            'required' => $rec ? (int)$rec->required : 0,
        ]);
        exit;
    }

    $required = $required ? 1 : 0;
    $existing = $DB->get_record('local_sentinel_selfeval_config', ['cmid' => $cmid]);
    if ($existing) {
        $existing->required     = $required;
        $existing->timemodified = time();
        $DB->update_record('local_sentinel_selfeval_config', $existing);
    } else {
        $DB->insert_record('local_sentinel_selfeval_config', (object)[
            'cmid'         => $cmid,
            'required'     => $required,
            'timemodified' => time(),
        ]);
    }

    echo json_encode(['success' => true, 'required' => $required]);
    exit;
}

// ── Acción: obtener rúbrica para autoevaluación del alumno ──────────────────
if ($action === 'selfeval_rubric') {
    $cmid = required_param('cmid', PARAM_INT);
    $cm = get_coursemodule_from_id('assign', $cmid);
    if (!$cm) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Actividad no encontrada']);
        exit;
    }
    $ctx = context_module::instance($cmid);
    require_capability('mod/assign:view', $ctx);

    $userid = $USER->id;

    $def = $DB->get_record_sql(
        "SELECT gd.id
           FROM {grading_areas} ga
           JOIN {grading_definitions} gd ON gd.areaid = ga.id
          WHERE ga.contextid = :ctxid AND gd.method = 'rubric'",
        ['ctxid' => $ctx->id]
    );
    if (!$def) {
        echo json_encode(['success' => false, 'error' => 'Sin rúbrica']);
        exit;
    }

    $criteria = $DB->get_records('gradingform_rubric_criteria',
        ['definitionid' => $def->id], 'sortorder ASC');
    $result = [];
    foreach ($criteria as $crit) {
        $levels = $DB->get_records('gradingform_rubric_levels',
            ['criterionid' => $crit->id], 'score ASC');
        $lvl_arr = [];
        foreach ($levels as $lv) {
            $lvl_arr[] = [
                'id'         => (int)$lv->id,
                'definition' => strip_tags($lv->definition),
                'score'      => (float)$lv->score,
            ];
        }
        $existing = $DB->get_record('local_sentinel_selfeval',
            ['userid' => $userid, 'cmid' => $cmid, 'criterionid' => $crit->id]);
        $result[] = [
            'id'          => (int)$crit->id,
            'description' => strip_tags($crit->description),
            'levels'      => $lvl_arr,
            'selected'    => $existing ? (int)$existing->levelid : null,
        ];
    }

    $cfg_rec = $DB->get_record('local_sentinel_selfeval_config', ['cmid' => $cmid]);
    echo json_encode([
        'success'  => true,
        'criteria' => $result,
        'required' => $cfg_rec ? (int)$cfg_rec->required : 0,
    ]);
    exit;
}

// ── Acción: guardar una selección de autoevaluación ─────────────────────────
if ($action === 'selfeval_save') {
    $cmid        = required_param('cmid', PARAM_INT);
    $criterionid = required_param('criterionid', PARAM_INT);
    $levelid     = required_param('levelid', PARAM_INT);

    $cm = get_coursemodule_from_id('assign', $cmid);
    if (!$cm) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Actividad no encontrada']);
        exit;
    }
    $ctx = context_module::instance($cmid);
    require_capability('mod/assign:view', $ctx);

    $userid = $USER->id;

    $level = $DB->get_record('gradingform_rubric_levels', ['id' => $levelid]);
    if (!$level || (int)$level->criterionid !== $criterionid) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Nivel inválido']);
        exit;
    }

    $existing = $DB->get_record('local_sentinel_selfeval',
        ['userid' => $userid, 'cmid' => $cmid, 'criterionid' => $criterionid]);
    $now = time();
    if ($existing) {
        $existing->levelid      = $levelid;
        $existing->score        = (float)$level->score;
        $existing->timemodified = $now;
        $DB->update_record('local_sentinel_selfeval', $existing);
    } else {
        $DB->insert_record('local_sentinel_selfeval', (object)[
            'userid'      => $userid,
            'cmid'        => $cmid,
            'criterionid' => $criterionid,
            'levelid'     => $levelid,
            'score'       => (float)$level->score,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
    }

    echo json_encode(['success' => true, 'score' => (float)$level->score]);
    exit;
}

// ── Acción por defecto: guardar mapeo rubric_row → grade_category ─────────────
$rubric_row_id     = required_param('rubric_row_id',     PARAM_INT);
$grade_category_id = required_param('grade_category_id', PARAM_INT);

// ── Validate inputs ──────────────────────────────────────────────────────────

$criterion = $DB->get_record('gradingform_rubric_criteria', ['id' => $rubric_row_id]);
if (!$criterion) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'invalid_criterion']);
    exit;
}

if ($grade_category_id !== 0) {
    $target_cat = $DB->get_record('grade_categories', ['id' => $grade_category_id], 'id, depth');
    if (!$target_cat) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'invalid_category']);
        exit;
    }
    if ((int)$target_cat->depth < 3) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Solo se puede asignar a criterios de evaluación (subcategorías), no a competencias específicas.']);
        exit;
    }
}

// ── Resolve course and existing mapping ──────────────────────────────────────

$definition = $DB->get_record('grading_definitions', ['id' => $criterion->definitionid], 'id, areaid');
if (!$definition) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'grading_definition_not_found']);
    exit;
}
$area = $DB->get_record('grading_areas', ['id' => $definition->areaid], 'id, contextid');
if (!$area) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'grading_area_not_found']);
    exit;
}
$context = context::instance_by_id($area->contextid);
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
 * Manages the main activity grade item based on rubric row mapping state.
 *
 * ALL rows mapped   → activity grade item weight 0 (avoid double counting).
 * Partial / none    → activity grade item keeps original weight.
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

    $total_criteria = count($crit_ids);

    // Count how many criteria are still actively mapped after this operation.
    [$insql, $inparams] = $DB->get_in_or_equal($crit_ids, SQL_PARAMS_NAMED, 'crit');
    $inparams['cat'] = 0;
    $active = $DB->count_records_select(
        'local_sentinel_map',
        "rubric_row_id $insql AND grade_category_id > :cat",
        $inparams
    );

    $all_mapped = ($active === $total_criteria && $total_criteria > 0);

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
    $sentinel_cat_name = get_string('activities_category', 'local_sentinel');
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
            'hidden'       => 1,
        ]);
        // Fix the path field (Moodle format: /parent_id/cat_id/).
        $DB->set_field('grade_categories', 'path',
            '/' . $root_cat->id . '/' . $cat_id . '/',
            ['id' => $cat_id]
        );
        // Create the associated grade_item for this category (weight 0, hidden, excluded from course total).
        $cat_gi                   = new grade_item();
        $cat_gi->courseid         = $courseid;
        $cat_gi->categoryid       = $root_cat->id;
        $cat_gi->itemtype         = 'category';
        $cat_gi->iteminstance     = $cat_id;
        $cat_gi->itemname         = $sentinel_cat_name;
        $cat_gi->aggregationcoef  = 0;
        $cat_gi->aggregationcoef2 = 0;
        $cat_gi->weightoverride   = 1;
        $cat_gi->hidden           = 1;
        $cat_gi->grademax         = 100;
        $cat_gi->grademin         = 0;
        $cat_gi->insert('local_sentinel');

        $sentinel_cat = (object)['id' => $cat_id];
    } else {
        // Ensure existing category has weight 0 locked (Moodle auto-redistributes in Natural).
        $cat_gi = grade_item::fetch(['itemtype' => 'category', 'iteminstance' => $sentinel_cat->id]);
        if ($cat_gi && ((float)$cat_gi->aggregationcoef2 != 0.0 || !$cat_gi->weightoverride)) {
            $cat_gi->aggregationcoef2 = 0;
            $cat_gi->weightoverride   = 1;
            $cat_gi->update('local_sentinel');
        }
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
        $rub_gi                   = new grade_item();
        $rub_gi->courseid         = $courseid;
        $rub_gi->categoryid       = $sentinel_cat->id;
        $rub_gi->itemtype         = 'category';
        $rub_gi->iteminstance     = $rubric_id;
        $rub_gi->itemname         = $rubric_cat_name;
        $rub_gi->aggregationcoef  = 0;
        $rub_gi->aggregationcoef2 = 0;
        $rub_gi->weightoverride   = 1;
        $rub_gi->grademax         = 100;
        $rub_gi->grademin         = 0;
        $rub_gi->insert('local_sentinel');

        $rubric_cat = (object)['id' => $rubric_id];
    } else {
        // Ensure existing "Nota de Rúbrica" has weight 0 locked.
        $rub_gi = grade_item::fetch(['itemtype' => 'category', 'iteminstance' => $rubric_cat->id]);
        if ($rub_gi && ((float)$rub_gi->aggregationcoef2 != 0.0 || !$rub_gi->weightoverride)) {
            $rub_gi->aggregationcoef2 = 0;
            $rub_gi->weightoverride   = 1;
            $rub_gi->update('local_sentinel');
        }
    }

    $config_key = 'orig_cat_' . $activity_gi->id;

    if ($all_mapped) {
        // ALL rows mapped: move grade item into "Nota de Rúbrica", weight 0.
        if ((int)$activity_gi->categoryid !== (int)$rubric_cat->id) {
            set_config($config_key, $activity_gi->categoryid, 'local_sentinel');
            set_config($config_key . '_coef',  $activity_gi->aggregationcoef,  'local_sentinel');
            set_config($config_key . '_coef2', $activity_gi->aggregationcoef2, 'local_sentinel');
        }
        $activity_gi->categoryid       = $rubric_cat->id;
        $activity_gi->hidden           = 0;
        $activity_gi->aggregationcoef  = 0;
        $activity_gi->aggregationcoef2 = 0;
        $activity_gi->weightoverride   = 1;
        $activity_gi->update('local_sentinel');
    } else {
        // Partial or no mapping: restore original category and weight.
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

    // Recompute progressive weights for each target category that has Sentinel items.
    if ($active > 0) {
        $mapped_cats = $DB->get_fieldset_select(
            'local_sentinel_map',
            'DISTINCT grade_category_id',
            "rubric_row_id $insql AND grade_category_id > :cat2",
            array_merge($inparams, ['cat2' => 0])
        );
        foreach ($mapped_cats as $catid) {
            \local_sentinel\observer::recompute_progressive_weights((int)$catid);
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

// ── DUA: Bitácora (logbook notes) ────────────────────────────────────────────
if ($action === 'dua_notes_list') {
    $userid   = required_param('userid', PARAM_INT);
    $courseid = required_param('courseid', PARAM_INT);
    $context  = context_course::instance($courseid);
    require_capability('local/sentinel:viewstudentreport', $context);
    $notes = $DB->get_records('local_sentinel_dua_note',
        ['userid' => $userid, 'courseid' => $courseid], 'timecreated DESC');
    $result = [];
    foreach ($notes as $n) {
        $author = core_user::get_user($n->authorid, 'id,firstname,lastname');
        $result[] = [
            'id'         => (int)$n->id,
            'content'    => $n->content,
            'authorid'   => (int)$n->authorid,
            'authorname' => $author ? fullname($author) : '?',
            'ismine'     => ((int)$n->authorid === (int)$USER->id),
            'timecreated' => (int)$n->timecreated,
            'date'       => userdate($n->timecreated, '%d/%m/%Y %H:%M'),
        ];
    }
    echo json_encode(['success' => true, 'notes' => $result]);
    exit;
}

if ($action === 'dua_note_save') {
    $userid   = required_param('userid', PARAM_INT);
    $courseid = required_param('courseid', PARAM_INT);
    $content  = required_param('content', PARAM_RAW);
    $noteid   = optional_param('noteid', 0, PARAM_INT);
    $context  = context_course::instance($courseid);
    require_capability('local/sentinel:viewstudentreport', $context);
    $content = clean_text($content);
    $now = time();
    if ($noteid > 0) {
        $existing = $DB->get_record('local_sentinel_dua_note', ['id' => $noteid], '*', MUST_EXIST);
        if ((int)$existing->authorid !== (int)$USER->id) {
            echo json_encode(['success' => false, 'error' => 'Not the author']);
            exit;
        }
        $existing->content = $content;
        $existing->timemodified = $now;
        $DB->update_record('local_sentinel_dua_note', $existing);
        echo json_encode(['success' => true, 'id' => (int)$noteid]);
    } else {
        $rec = new stdClass();
        $rec->userid = $userid;
        $rec->courseid = $courseid;
        $rec->authorid = (int)$USER->id;
        $rec->content = $content;
        $rec->timecreated = $now;
        $rec->timemodified = $now;
        $id = $DB->insert_record('local_sentinel_dua_note', $rec);
        echo json_encode(['success' => true, 'id' => (int)$id]);
    }
    exit;
}

if ($action === 'dua_note_delete') {
    $noteid = required_param('noteid', PARAM_INT);
    $existing = $DB->get_record('local_sentinel_dua_note', ['id' => $noteid], '*', MUST_EXIST);
    $context  = context_course::instance($existing->courseid);
    require_capability('local/sentinel:viewstudentreport', $context);
    if ((int)$existing->authorid !== (int)$USER->id) {
        echo json_encode(['success' => false, 'error' => 'Not the author']);
        exit;
    }
    $DB->delete_records('local_sentinel_dua_note', ['id' => $noteid]);
    echo json_encode(['success' => true]);
    exit;
}

// ── DUA: Motivación (interests/hooks chips) ──────────────────────────────────
if ($action === 'dua_motiv_list') {
    $userid   = required_param('userid', PARAM_INT);
    $courseid = required_param('courseid', PARAM_INT);
    $context  = context_course::instance($courseid);
    require_login($courseid);
    $items = $DB->get_records('local_sentinel_dua_motiv',
        ['userid' => $userid, 'courseid' => $courseid], 'category ASC, timecreated ASC');
    $result = [];
    foreach ($items as $m) {
        $result[] = [
            'id'       => (int)$m->id,
            'category' => $m->category,
            'label'    => $m->label,
            'ismine'   => ((int)$m->authorid === (int)$USER->id),
        ];
    }
    echo json_encode(['success' => true, 'items' => $result]);
    exit;
}

if ($action === 'dua_motiv_save') {
    $userid   = required_param('userid', PARAM_INT);
    $courseid = required_param('courseid', PARAM_INT);
    $category = required_param('category', PARAM_ALPHA);
    $label    = required_param('label', PARAM_TEXT);
    $context  = context_course::instance($courseid);
    require_capability('local/sentinel:viewstudentreport', $context);
    if (!in_array($category, ['interest', 'hook'])) {
        echo json_encode(['success' => false, 'error' => 'Invalid category']);
        exit;
    }
    $now = time();
    $rec = new stdClass();
    $rec->userid = $userid;
    $rec->courseid = $courseid;
    $rec->authorid = (int)$USER->id;
    $rec->category = $category;
    $rec->label = $label;
    $rec->timecreated = $now;
    $rec->timemodified = $now;
    $id = $DB->insert_record('local_sentinel_dua_motiv', $rec);
    echo json_encode(['success' => true, 'id' => (int)$id]);
    exit;
}

if ($action === 'dua_motiv_delete') {
    $itemid = required_param('itemid', PARAM_INT);
    $existing = $DB->get_record('local_sentinel_dua_motiv', ['id' => $itemid], '*', MUST_EXIST);
    $context  = context_course::instance($existing->courseid);
    require_capability('local/sentinel:viewstudentreport', $context);
    $DB->delete_records('local_sentinel_dua_motiv', ['id' => $itemid]);
    echo json_encode(['success' => true]);
    exit;
}

// ── DUA: Adaptaciones de Acceso (AA) ─────────────────────────────────────────
if ($action === 'dua_access_list') {
    $userid   = required_param('userid', PARAM_INT);
    $courseid = required_param('courseid', PARAM_INT);
    $context  = context_course::instance($courseid);
    require_login($courseid);
    $items = $DB->get_records('local_sentinel_dua_access',
        ['userid' => $userid, 'courseid' => $courseid], 'timecreated DESC');
    $result = [];
    foreach ($items as $a) {
        $result[] = [
            'id'          => (int)$a->id,
            'measure'     => $a->measure,
            'catalog'     => (int)$a->catalog,
            'subcategory' => $a->subcategory ?? 'custom',
            'active'      => (int)$a->active,
            'datestart'   => (int)($a->datestart ?? 0),
            'dateend'     => (int)($a->dateend ?? 0),
            'timecreated' => (int)$a->timecreated,
        ];
    }
    echo json_encode(['success' => true, 'items' => $result]);
    exit;
}

if ($action === 'dua_access_save') {
    $userid      = required_param('userid', PARAM_INT);
    $courseid    = required_param('courseid', PARAM_INT);
    $measure     = required_param('measure', PARAM_TEXT);
    $subcategory = optional_param('subcategory', 'custom', PARAM_ALPHA);
    $datestart   = optional_param('datestart', 0, PARAM_INT);
    $dateend     = optional_param('dateend', 0, PARAM_INT);
    $context     = context_course::instance($courseid);
    require_capability('local/sentinel:viewstudentreport', $context);
    if (!in_array($subcategory, ['standard', 'program', 'custom'])) {
        $subcategory = 'custom';
    }
    $now = time();
    $rec = new stdClass();
    $rec->userid = $userid;
    $rec->courseid = $courseid;
    $rec->measure = $measure;
    $rec->catalog = ($subcategory !== 'custom') ? 1 : 0;
    $rec->subcategory = $subcategory;
    $rec->active = 1;
    $rec->authorid = (int)$USER->id;
    $rec->datestart = $datestart ?: $now;
    $rec->dateend = $dateend ?: 0;
    $rec->timecreated = $now;
    $rec->timemodified = $now;
    $id = $DB->insert_record('local_sentinel_dua_access', $rec);
    echo json_encode(['success' => true, 'id' => (int)$id]);
    exit;
}

if ($action === 'dua_access_toggle') {
    $itemid = required_param('itemid', PARAM_INT);
    $existing = $DB->get_record('local_sentinel_dua_access', ['id' => $itemid], '*', MUST_EXIST);
    $context  = context_course::instance($existing->courseid);
    require_capability('local/sentinel:viewstudentreport', $context);
    $existing->active = $existing->active ? 0 : 1;
    $existing->timemodified = time();
    $DB->update_record('local_sentinel_dua_access', $existing);
    echo json_encode(['success' => true, 'active' => (int)$existing->active]);
    exit;
}

if ($action === 'dua_access_delete') {
    $itemid = required_param('itemid', PARAM_INT);
    $existing = $DB->get_record('local_sentinel_dua_access', ['id' => $itemid], '*', MUST_EXIST);
    $context  = context_course::instance($existing->courseid);
    require_capability('local/sentinel:viewstudentreport', $context);
    $DB->delete_records('local_sentinel_dua_access', ['id' => $itemid]);
    echo json_encode(['success' => true]);
    exit;
}

// ── DUA: ACIS / Adaptaciones Curriculares Significativas ──────────────────────
if ($action === 'dua_acis_list') {
    $userid   = required_param('userid', PARAM_INT);
    $courseid = required_param('courseid', PARAM_INT);
    $context  = context_course::instance($courseid);
    require_login($courseid);
    $items = $DB->get_records('local_sentinel_dua_acis',
        ['userid' => $userid, 'courseid' => $courseid], 'timecreated DESC');
    $result = [];
    foreach ($items as $a) {
        $result[] = [
            'id'            => (int)$a->id,
            'criterionid'   => (int)$a->criterionid,
            'original_text' => $a->original_text,
            'adapted_text'  => $a->adapted_text,
            'datestart'     => (int)$a->datestart,
            'dateend'       => (int)($a->dateend ?? 0),
            'timecreated'   => (int)$a->timecreated,
        ];
    }
    echo json_encode(['success' => true, 'items' => $result]);
    exit;
}

if ($action === 'dua_acis_save') {
    $userid       = required_param('userid', PARAM_INT);
    $courseid     = required_param('courseid', PARAM_INT);
    $criterionid  = required_param('criterionid', PARAM_INT);
    $original     = required_param('original_text', PARAM_RAW);
    $adapted      = required_param('adapted_text', PARAM_RAW);
    $datestart    = optional_param('datestart', 0, PARAM_INT);
    $dateend      = optional_param('dateend', 0, PARAM_INT);
    $context      = context_course::instance($courseid);
    require_capability('local/sentinel:viewstudentreport', $context);
    $now = time();
    $rec = new stdClass();
    $rec->userid = $userid;
    $rec->courseid = $courseid;
    $rec->criterionid = $criterionid;
    $rec->original_text = clean_param($original, PARAM_CLEANHTML);
    $rec->adapted_text = clean_param($adapted, PARAM_CLEANHTML);
    $rec->authorid = (int)$USER->id;
    $rec->datestart = $datestart ?: $now;
    $rec->dateend = $dateend ?: 0;
    $rec->timecreated = $now;
    $rec->timemodified = $now;
    $id = $DB->insert_record('local_sentinel_dua_acis', $rec);
    echo json_encode(['success' => true, 'id' => (int)$id]);
    exit;
}

if ($action === 'dua_acis_delete') {
    $itemid = required_param('itemid', PARAM_INT);
    $existing = $DB->get_record('local_sentinel_dua_acis', ['id' => $itemid], '*', MUST_EXIST);
    $context  = context_course::instance($existing->courseid);
    require_capability('local/sentinel:viewstudentreport', $context);
    $DB->delete_records('local_sentinel_dua_acis', ['id' => $itemid]);
    echo json_encode(['success' => true]);
    exit;
}

if ($action === 'dua_ce_criteria') {
    $courseid = required_param('courseid', PARAM_INT);
    $context  = context_course::instance($courseid);
    require_capability('local/sentinel:viewstudentreport', $context);
    require_once($CFG->libdir . '/grade/grade_category.php');
    $course_cat = grade_category::fetch_course_category($courseid);
    $tree = grade_category::fetch_all(['courseid' => $courseid]);
    $criteria = [];
    foreach ($tree as $cat) {
        $name = $cat->get_name();
        if (preg_match('/^(CE\s*\d+)/i', $name, $m)) {
            $criteria[] = [
                'id'   => (int)$cat->id,
                'code' => strtoupper(str_replace(' ', '', $m[1])),
                'name' => $name,
            ];
        }
    }
    usort($criteria, function($a, $b) {
        return strnatcasecmp($a['code'], $b['code']);
    });
    echo json_encode(['success' => true, 'criteria' => $criteria]);
    exit;
}

// Recompute progressive weights for the target category.
if ($grade_category_id > 0) {
    \local_sentinel\observer::recompute_progressive_weights($grade_category_id);
}

// Hide or restore the main activity grade item depending on active mappings.
sentinel_sync_activity_visibility($criterion, $area, $courseid);

echo json_encode(['success' => true]);
exit;

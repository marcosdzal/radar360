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
 * Course curriculum configuration page.
 *
 * @package    local_sentinel
 * @copyright  2024 Marcos
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

$courseid = required_param('courseid', PARAM_INT);
$course   = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);

require_login($course);

$context = context_course::instance($courseid);
if (!has_capability('local/sentinel:managedescriptors', $context)
    && !has_capability('local/sentinel:managedescriptors', context_system::instance())) {
    require_capability('local/sentinel:managedescriptors', $context);
}

$PAGE->set_url(new moodle_url('/local/sentinel/curriculum_config.php', ['courseid' => $courseid]));
$PAGE->set_context($context);
$PAGE->set_title(get_string('curriculum:title', 'local_sentinel') . ' — ' . $course->fullname);
$PAGE->set_heading($course->fullname);
$PAGE->set_pagelayout('incourse');
$PAGE->set_secondary_active_tab('coursehome');

$PAGE->navbar->add(get_string('pluginname', 'local_sentinel'));
$PAGE->navbar->add(get_string('curriculum:title', 'local_sentinel'));

$applied = \local_sentinel\curriculum_manager::get_applied($courseid);
$locked  = \local_sentinel\curriculum_manager::course_has_grades($courseid);
$ajaxurl = (new moodle_url('/local/sentinel/ajax.php'))->out(false);
$sesskey = sesskey();

// Fill in category/criterio counts from the gradebook if not stored.
if ($applied && empty($applied['categories'])) {
    $root = grade_category::fetch_course_category($courseid);
    if ($root) {
        $ce_cats = $DB->count_records('grade_categories', ['courseid' => $courseid, 'parent' => $root->id, 'depth' => 2]);
        $act_name = get_string('activities_category', 'local_sentinel');
        $act = $DB->get_record_select('grade_categories',
            "courseid = :cid AND parent = :pid AND fullname = :name",
            ['cid' => $courseid, 'pid' => $root->id, 'name' => $act_name]);
        if ($act) {
            $ce_cats--;
        }
        $crit_cats = $DB->count_records_select('grade_categories',
            "courseid = :cid AND depth = 3", ['cid' => $courseid]);
        if ($act) {
            $sub_act = $DB->count_records('grade_categories', ['courseid' => $courseid, 'parent' => $act->id]);
            $crit_cats -= $sub_act;
        }
        $applied['categories'] = max(0, $ce_cats);
        $applied['criterios'] = max(0, $crit_cats);
    }
}

echo $OUTPUT->header();
?>
<style>
#cc-app{max-width:600px;margin:0 auto;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',system-ui,sans-serif}
#cc-app .cc-card{background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:24px 28px;box-shadow:0 1px 4px rgba(0,0,0,.06);margin-bottom:1.5rem}
#cc-app h3{margin:0 0 6px;font-size:1.1rem;font-weight:700;color:#1e293b}
#cc-app .cc-sub{margin:0 0 20px;font-size:.85rem;color:#64748b;line-height:1.5}
#cc-app .cc-current{padding:14px 16px;border-radius:8px;margin-bottom:20px;font-size:.88rem;line-height:1.6}
#cc-app .cc-current.applied{background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46}
#cc-app .cc-current.none{background:#f5f3ff;border:1px solid #c4b5fd;color:#5b21b6}
#cc-app .cc-current strong{font-weight:700}
#cc-app .cc-field{margin-bottom:14px}
#cc-app .cc-field label{display:block;font-weight:600;font-size:.85rem;margin-bottom:4px;color:#334155}
#cc-app .cc-field select{width:100%;padding:8px 10px;border:1px solid #cbd5e1;border-radius:6px;font-size:.88rem;background:#f8fafc}
#cc-app .cc-info{padding:8px 10px;background:#f1f5f9;border:1px solid #e2e8f0;border-radius:6px;color:#334155;font-size:.88rem;margin-bottom:0;display:none}
#cc-app .cc-info strong{color:#334155}
#cc-app .cc-warn{background:#fffbeb;border-color:#fbbf24;color:#b45309}
#cc-app .cc-locked{background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:14px 16px;color:#991b1b;font-size:.88rem;margin-bottom:16px}
#cc-app .cc-btn{width:100%;padding:10px;background:#7c3aed;color:#fff;border:none;border-radius:6px;font-size:.92rem;font-weight:600;cursor:pointer;transition:background .15s;margin-top:6px}
#cc-app .cc-btn:hover:not(:disabled){background:#6d28d9}
#cc-app .cc-btn:disabled{opacity:.5;cursor:not-allowed}
#cc-app .cc-msg{margin:12px 0 0;font-size:.85rem;text-align:center;display:none}
#cc-app .cc-remove{margin-top:12px;padding:8px;background:transparent;border:1px solid #fecaca;border-radius:6px;color:#dc2626;font-size:.82rem;font-weight:500;cursor:pointer;width:100%;transition:all .15s}
#cc-app .cc-remove:hover{background:#fef2f2}
</style>

<div id="cc-app">
  <div class="cc-card">
    <h3>Configurar curr&iacute;culo del curso</h3>
    <p class="cc-sub">Selecciona la normativa y materia para poblar autom&aacute;ticamente el libro de
    calificaciones con las competencias espec&iacute;ficas y criterios de evaluaci&oacute;n.</p>

    <div id="cc-locked" class="cc-locked" style="display:none"></div>
    <div id="cc-form">
      <div class="cc-field" id="cc-wrap-community">
        <label for="cc-community">Comunidad Aut&oacute;noma</label>
        <div id="cc-community-info" class="cc-info"></div>
        <select id="cc-community"><option value="">— Selecciona —</option></select>
      </div>
      <div class="cc-field" id="cc-wrap-stage">
        <label for="cc-stage">Etapa</label>
        <div id="cc-stage-info" class="cc-info" style="display:none"></div>
        <select id="cc-stage"><option value="">— Selecciona —</option></select>
      </div>
      <div class="cc-field" id="cc-wrap-grade">
        <label for="cc-grade">Curso / Ciclo</label>
        <div id="cc-grade-info" class="cc-info" style="display:none"></div>
        <select id="cc-grade"><option value="">— Selecciona —</option></select>
      </div>
      <div class="cc-field" id="cc-wrap-subject">
        <label for="cc-subject">Materia / &Aacute;rea</label>
        <div id="cc-subject-info" class="cc-info" style="display:none"></div>
        <select id="cc-subject"><option value="">— Selecciona —</option></select>
      </div>
    </div>
    <div id="cc-current"></div>
    <button class="cc-btn" id="cc-apply" disabled>Aplicar curr&iacute;culo</button>
    <p class="cc-msg" id="cc-msg"></p>
    <button class="cc-remove" id="cc-remove" style="display:none">Eliminar curr&iacute;culo aplicado</button>
  </div>
</div>

<script>
(function(){
'use strict';
var AJAX = <?php echo json_encode($ajaxurl); ?>;
var SK = <?php echo json_encode($sesskey); ?>;
var CID = <?php echo (int)$courseid; ?>;
var applied = <?php echo json_encode($applied); ?>;
var locked = <?php echo $locked ? 'true' : 'false'; ?>;

var curEl = document.getElementById('cc-current');
var lockedEl = document.getElementById('cc-locked');
var infoEl = document.getElementById('cc-community-info');
var formEl = document.getElementById('cc-form');
var applyBtn = document.getElementById('cc-apply');
var msgEl = document.getElementById('cc-msg');
var removeBtn = document.getElementById('cc-remove');
var selCommunity = document.getElementById('cc-community');
var selStage = document.getElementById('cc-stage');
var selGrade = document.getElementById('cc-grade');
var selSubject = document.getElementById('cc-subject');

var stageAbbr = {eso:'ESO', ei:'EI', ep:'EP', bach:'Bach', bachillerato:'Bach'};
var catalog = null;

if (applied) {
    var stg = stageAbbr[applied.stage] || applied.stage.toUpperCase();
    var dec = (applied.decree || '').replace(/^Decreto\s+/, 'D ').replace(/^Real Decreto\s+/, 'RD ').replace(/,.*$/, '');
    curEl.className = 'cc-current applied';
    curEl.innerHTML = '<strong>Currículo aplicado: ' + stg + applied.grade + ' — ' + (applied.subject_name || applied.subject) + '</strong>'
        + '<br><span style="font-size:.85em;opacity:.8">' + dec + '</span>'
        + '<br><span style="font-size:.85em">' + (applied.categories || '?') + ' competencias específicas, '
        + (applied.criterios || '?') + ' criterios de evaluación</span>';
    removeBtn.style.display = '';
    applyBtn.style.display = 'none';
} else {
    curEl.style.display = 'none';
}

if (locked) {
    lockedEl.style.display = '';
    lockedEl.textContent = 'Este curso ya tiene calificaciones. No se puede cambiar la estructura curricular.';
    formEl.style.display = 'none';
    applyBtn.style.display = 'none';
    removeBtn.style.display = 'none';
}

function showMsg(text, color) {
    msgEl.textContent = text;
    msgEl.style.color = color || '#1e293b';
    msgEl.style.display = 'block';
}

function loadCatalog() {
    fetch(AJAX + '?action=curriculum_catalog&courseid=' + CID + '&sesskey=' + SK)
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (!data.success) { showMsg('Error: ' + (data.error || 'desconocido'), '#dc2626'); return; }
            catalog = data.catalog;

            if (data.community && catalog.communities && catalog.communities.length === 1) {
                var c = catalog.communities[0];
                infoEl.innerHTML = '<strong>' + c.name + '</strong> <span style="font-size:.9em;color:#64748b">— Desarrollo curricular LOMLOE</span>';
                infoEl.style.display = 'block';
                selCommunity.style.display = 'none';
                selCommunity.innerHTML = '<option value="' + c.id + '" selected>' + c.name + '</option>';
                selCommunity.value = c.id;
                selCommunity.dispatchEvent(new Event('change'));
            } else if (!data.community) {
                infoEl.className = 'cc-info cc-warn';
                infoEl.innerHTML = '<span style="color:#b45309">⚠ No se ha configurado la comunidad autónoma del centro.</span><br>'
                    + '<span style="font-size:.9em">Un administrador debe establecerla en </span>'
                    + '<a href="' + M.cfg.wwwroot + '/admin/settings.php?section=local_sentinel" style="font-size:.9em">Administración del sitio → Radar 360</a>';
                infoEl.style.display = 'block';
                formEl.style.display = 'none';
                applyBtn.style.display = 'none';
            } else {
                infoEl.style.display = 'none';
                selCommunity.innerHTML = '<option value="">— Selecciona —</option>';
                (catalog.communities || []).forEach(function(c) {
                    var o = document.createElement('option');
                    o.value = c.id; o.textContent = c.name;
                    selCommunity.appendChild(o);
                });
            }
        })
        .catch(function() { showMsg('Error de red al cargar el catálogo', '#dc2626'); });
}

var stageInfoEl = document.getElementById('cc-stage-info');
var gradeInfoEl = document.getElementById('cc-grade-info');
var subjectInfoEl = document.getElementById('cc-subject-info');

selCommunity.addEventListener('change', function() {
    selStage.innerHTML = '<option value="">— Selecciona —</option>';
    selStage.style.display = '';
    stageInfoEl.style.display = 'none';
    selGrade.innerHTML = '<option value="">— Selecciona —</option>';
    selGrade.style.display = '';
    gradeInfoEl.style.display = 'none';
    selSubject.innerHTML = '<option value="">— Selecciona —</option>';
    selSubject.style.display = '';
    subjectInfoEl.style.display = 'none';
    applyBtn.disabled = true;
    var cid = selCommunity.value;
    if (!cid || !catalog) return;
    var community = catalog.communities.find(function(c) { return c.id === cid; });
    if (!community) return;
    var stages = community.stages || [];
    if (stages.length === 1 || applied) {
        var s = applied
            ? stages.find(function(st) { return st.id === applied.stage; }) || stages[0]
            : stages[0];
        var label = s.name + (s.decree ? ' (' + s.decree + ')' : '');
        stageInfoEl.innerHTML = '<strong>' + label + '</strong>';
        stageInfoEl.style.display = 'block';
        selStage.innerHTML = '<option value="' + s.id + '" selected>' + label + '</option>';
        selStage.style.display = 'none';
        selStage.value = s.id;
        selStage.dispatchEvent(new Event('change'));
    } else {
        stages.forEach(function(s) {
            var o = document.createElement('option');
            o.value = s.id;
            o.textContent = s.name + (s.decree ? ' (' + s.decree + ')' : '');
            selStage.appendChild(o);
        });
    }
});

selStage.addEventListener('change', function() {
    selGrade.innerHTML = '<option value="">— Selecciona —</option>';
    selGrade.style.display = '';
    gradeInfoEl.style.display = 'none';
    selSubject.innerHTML = '<option value="">— Selecciona —</option>';
    selSubject.style.display = '';
    subjectInfoEl.style.display = 'none';
    applyBtn.disabled = true;
    var cid = selCommunity.value, sid = selStage.value;
    if (!cid || !sid || !catalog) return;
    var community = catalog.communities.find(function(c) { return c.id === cid; });
    var stage = (community.stages || []).find(function(s) { return s.id === sid; });
    if (!stage) return;
    (stage.grades || []).forEach(function(g) {
        var o = document.createElement('option');
        o.value = g; o.textContent = g + 'º';
        selGrade.appendChild(o);
    });
    if (applied) {
        gradeInfoEl.innerHTML = '<strong>' + applied.grade + 'º</strong>';
        gradeInfoEl.style.display = 'block';
        selGrade.value = applied.grade;
        selGrade.style.display = 'none';
        selGrade.dispatchEvent(new Event('change'));
    }
});

selGrade.addEventListener('change', function() {
    selSubject.innerHTML = '<option value="">— Selecciona —</option>';
    selSubject.style.display = '';
    subjectInfoEl.style.display = 'none';
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
        o.value = sub.id; o.textContent = sub.name;
        selSubject.appendChild(o);
    });
    if (applied) {
        var subName = applied.subject_name || applied.subject;
        subjectInfoEl.innerHTML = '<strong>' + subName + '</strong>';
        subjectInfoEl.style.display = 'block';
        selSubject.value = applied.subject;
        selSubject.style.display = 'none';
        applyBtn.disabled = true;
    }
});

selSubject.addEventListener('change', function() {
    applyBtn.disabled = !selSubject.value;
});

applyBtn.addEventListener('click', function() {
    applyBtn.disabled = true;
    applyBtn.textContent = 'Aplicando…';
    msgEl.style.display = 'none';

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

removeBtn.addEventListener('click', function() {
    if (!confirm('¿Eliminar el currículo aplicado? Se borrarán las competencias específicas y criterios creados.')) return;
    removeBtn.disabled = true;
    removeBtn.textContent = 'Eliminando…';

    var params = new URLSearchParams();
    params.append('action', 'remove_curriculum');
    params.append('sesskey', SK);
    params.append('courseid', CID);

    fetch(AJAX, { method: 'POST', body: params })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                showMsg('✓ Currículo eliminado.', '#059669');
                setTimeout(function() { location.reload(); }, 1000);
            } else {
                showMsg('Error: ' + (data.error || 'desconocido'), '#dc2626');
                removeBtn.disabled = false;
                removeBtn.textContent = 'Eliminar currículo aplicado';
            }
        })
        .catch(function() {
            showMsg('Error de red', '#dc2626');
            removeBtn.disabled = false;
            removeBtn.textContent = 'Eliminar currículo aplicado';
        });
});

if (!locked) loadCatalog();
})();
</script>
<?php
echo $OUTPUT->footer();

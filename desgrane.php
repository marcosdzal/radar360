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
 * Desgrane de contenidos – permite al profesorado configurar qué sub-contenidos
 * del Decreto 65/2022 se trabajan en su curso/materia.
 *
 * URL: /local/sentinel/desgrane.php?courseid=NNN
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

$PAGE->set_url(new moodle_url('/local/sentinel/desgrane.php', ['courseid' => $courseid]));
$PAGE->set_context($context);
$PAGE->set_title(get_string('desgrane:title', 'local_sentinel') . ' — ' . $course->fullname);
$PAGE->set_heading($course->fullname);
$PAGE->set_pagelayout('incourse');
$PAGE->set_secondary_active_tab('coursehome');

$PAGE->navbar->add(get_string('pluginname', 'local_sentinel'));
$PAGE->navbar->add(get_string('desgrane:title', 'local_sentinel'));

// ── Determine subject from applied curriculum ──────────────────────────────
$applied = \local_sentinel\curriculum_manager::get_applied($courseid);
$applied_subject = $applied['subject'] ?? null;
$applied_grade   = $applied['grade'] ?? 1;
$applied_stage   = $applied['stage'] ?? 'eso';
$applied_community = $applied['community'] ?? 'madrid';

// ── Load contenidos data ────────────────────────────────────────────────────
$datadir  = __DIR__ . '/data/curriculo/' . $applied_community . '/' . $applied_stage;
$datafile = $datadir . '/contenidos_' . (int)$applied_grade . $applied_stage . '.json';
$contenidos = '{}';
if (file_exists($datafile)) {
    $raw = file_get_contents($datafile);
    $raw = str_replace(["\x02", "\x03"], ' ', $raw);
    $contenidos = $raw;
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('desgrane:title', 'local_sentinel'));

if (!$applied) {
    $config_url = new moodle_url('/local/sentinel/curriculum_config.php', ['courseid' => $courseid]);
    echo $OUTPUT->notification(
        'Para utilizar el desgrane de contenidos, primero debe ' .
        '<a href="' . $config_url->out() . '">configurar el currículo del curso</a>.',
        'warning'
    );
    echo $OUTPUT->footer();
    exit;
}

// Check that the contenidos JSON exists and contains the applied subject.
$contenidos_decoded = json_decode($contenidos, true) ?: [];
if (empty($contenidos_decoded) || !isset($contenidos_decoded[$applied_subject])) {
    $subject_name = $applied['subject_name'] ?? $applied_subject;
    echo $OUTPUT->notification(
        'No hay contenidos de desgrane disponibles para la materia <strong>' . s($subject_name) . '</strong> ' .
        '(' . (int)$applied_grade . '.º ' . strtoupper($applied_stage) . '). ' .
        'Los contenidos se irán añadiendo progresivamente.',
        'warning'
    );
    echo $OUTPUT->footer();
    exit;
}
?>
<style>
#desgrane-app{max-width:920px;margin:0 auto;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',system-ui,sans-serif}
#desgrane-app .stats{font-size:.78rem;color:#64748b;margin-bottom:.8rem;display:flex;gap:16px;flex-wrap:wrap}
#desgrane-app .stats strong{color:#1e293b;font-weight:600}
#desgrane-app .legend{font-size:.78rem;color:#64748b;line-height:1.5;margin-bottom:1rem;padding:10px 14px;background:#fff;border:1px solid #e2e8f0;border-radius:8px}
#desgrane-app .legend b{color:#1e293b;font-weight:600}
#desgrane-app .legend .lt{display:inline-flex;align-items:center;gap:5px;margin-right:12px}
#desgrane-app .legend .dot{width:10px;height:10px;border-radius:50%;flex-shrink:0}
#desgrane-app .legend .dot-t{border:2px dashed #64748b;width:8px;height:8px;background:#f1f5f9}
#desgrane-app .blk-section{margin-bottom:1.5rem}
#desgrane-app .blk-section.transversal{border:2px dashed #64748b;border-radius:10px;padding:10px;margin-top:.5rem}
#desgrane-app .blk-title{font-weight:700;font-size:.95rem;padding:8px 14px;border-radius:8px;margin-bottom:0;display:flex;align-items:center;gap:8px;cursor:pointer;user-select:none;transition:opacity .15s}
#desgrane-app .blk-title:hover{opacity:.85}
#desgrane-app .blk-title .blk-arrow{font-size:.7em;transition:transform .2s;flex-shrink:0}
#desgrane-app .blk-title .blk-arrow.open{transform:rotate(90deg)}
#desgrane-app .blk-items{display:none;padding-top:10px}
#desgrane-app .blk-items.open{display:block}
#desgrane-app .blk-letter{font-size:.85rem;font-weight:700;width:26px;height:26px;border-radius:50%;display:flex;align-items:center;justify-content:center;color:#fff;flex-shrink:0}
#desgrane-app .card{background:#fff;border:1px solid #e2e8f0;border-radius:8px;margin-bottom:8px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.05)}
#desgrane-app .card-h{display:flex;align-items:flex-start;gap:10px;padding:10px 14px;cursor:pointer;user-select:none;transition:background .15s}
#desgrane-app .card-h:hover{background:#f8fafc}
#desgrane-app .badge{font-weight:700;font-size:.76rem;padding:2px 8px;border-radius:5px;flex-shrink:0;margin-top:1px;letter-spacing:.02em}
#desgrane-app .tog{color:#64748b;font-size:.82rem;flex-shrink:0;margin-top:2px;transition:transform .2s}
#desgrane-app .tog.open{transform:rotate(90deg)}
#desgrane-app .card-txt{flex:1;min-width:0}
#desgrane-app .card-txt .t{font-weight:600;font-size:.84rem;line-height:1.4}
#desgrane-app .card-txt .cnt{font-size:.72rem;color:#64748b;margin-top:2px}
#desgrane-app .card-body{display:none;border-top:1px solid #e2e8f0}
#desgrane-app .card-body.open{display:block}
#desgrane-app .legal{background:#f1f5f9;border-left:3px solid #cbd5e1;padding:10px 14px;margin:10px 14px 8px;border-radius:0 6px 6px 0;font-size:.8rem;line-height:1.55;color:#64748b;font-style:italic}
#desgrane-app .legal .ll{font-style:normal;font-weight:600;font-size:.7rem;text-transform:uppercase;letter-spacing:.04em;color:#7c3aed;margin-bottom:4px}
#desgrane-app .dh{padding:6px 14px;font-size:.72rem;font-weight:600;text-transform:uppercase;letter-spacing:.04em;color:#64748b;display:flex;align-items:center;justify-content:space-between}
#desgrane-app .slist{padding:0 14px 10px;display:flex;flex-direction:column;gap:4px}
#desgrane-app .si{display:flex;align-items:center;gap:8px;padding:6px 10px;border-radius:6px;background:#fff;border:1px solid #e2e8f0;font-size:.82rem;transition:background .15s,border-color .15s,transform .15s,box-shadow .15s}
#desgrane-app .si:hover{background:#f8fafc;border-color:#64748b}
#desgrane-app .si[draggable="true"]{cursor:grab}
#desgrane-app .si.dragging{opacity:.4;transform:scale(.97);box-shadow:0 2px 8px rgba(0,0,0,.12)}
#desgrane-app .si.drag-over{border-color:#7c3aed;background:#f5f3ff;box-shadow:0 0 0 2px rgba(124,58,237,.2)}
#desgrane-app .grip{display:flex;flex-direction:column;gap:1px;flex-shrink:0;color:#cbd5e1;cursor:grab;padding:2px;font-size:.7rem;line-height:1;letter-spacing:2px;user-select:none;touch-action:none}
#desgrane-app .sc{font-weight:700;font-size:.74rem;color:#64748b;flex-shrink:0;min-width:42px}
#desgrane-app .st{flex:1;min-width:0;line-height:1.4}
#desgrane-app .st[contenteditable]{outline:none;cursor:text;border-radius:3px;padding:1px 3px}
#desgrane-app .st[contenteditable]:focus{background:#f5f3ff}
#desgrane-app .sa{display:flex;gap:4px;flex-shrink:0;opacity:0;transition:opacity .15s}
#desgrane-app .si:hover .sa{opacity:1}
#desgrane-app .sb{width:24px;height:24px;border:none;border-radius:4px;cursor:pointer;font-size:.72rem;display:flex;align-items:center;justify-content:center;transition:background .15s}
#desgrane-app .sb.del{background:#fef2f2;color:#dc2626}
#desgrane-app .sb.del:hover{background:#dc2626;color:#fff}
#desgrane-app .add{display:flex;align-items:center;gap:6px;padding:6px 10px;margin:0 14px 10px;border:1px dashed #e2e8f0;border-radius:6px;background:transparent;color:#64748b;font-size:.8rem;cursor:pointer;transition:all .15s;width:calc(100% - 28px)}
#desgrane-app .add:hover{border-color:#16a34a;color:#16a34a;background:#f0fdf4}
#desgrane-app .toolbar{background:#f8fafc;border-top:1px solid #e2e8f0;padding:10px 0;display:flex;gap:10px;justify-content:flex-end;margin-top:1rem}
#desgrane-app .toolbar button{font-weight:600;font-size:.82rem;padding:8px 18px;border-radius:6px;border:none;cursor:pointer;transition:all .15s}
#desgrane-app .btn-p{background:#7c3aed;color:#fff}
#desgrane-app .btn-p:hover{filter:brightness(1.1)}
#desgrane-app .btn-s{background:#fff;color:#1e293b;border:1px solid #e2e8f0!important}
#desgrane-app .btn-s:hover{background:#f8fafc}
#desgrane-app .empty{text-align:center;padding:3rem 1rem;color:#64748b;font-size:.9rem}
@media(max-width:500px){
  #desgrane-app .card-h{padding:8px 10px}
  #desgrane-app .legal{margin:8px 10px 6px}
  #desgrane-app .slist{padding:0 10px 8px}
  #desgrane-app .sa{opacity:1}
  #desgrane-app .blk-title .blk-arrow{font-size:.6em}
}
</style>

<div id="desgrane-app">
  <div class="stats" id="dsg-stats"></div>
  <div class="legend" id="dsg-legend">
    <span class="lt"><span class="dot" style="background:#2563eb"></span> Bloques propios de la materia</span>
    <span class="lt"><span class="dot dot-t"></span> Contenidos transversales</span>
    <br>Los contenidos transversales son aquellos que el Decreto 65/2022 establece que
    <b>se trabajar&aacute;n en todas las materias</b>. Se incluyen como propuesta; cada docente
    puede editarlos o eliminarlos seg&uacute;n los trabaje en su materia.
  </div>
  <div id="dsg-content"></div>
  <div class="toolbar">
    <button class="btn-s" id="dsg-reset">Restaurar propuesta original</button>
    <button class="btn-s" id="dsg-save">Guardar borrador</button>
    <button class="btn-p" id="dsg-inject">Inyectar en competencias</button>
  </div>
</div>

<script>
(function(){
'use strict';
var COLORS = {
  a:{c:'#2563eb',bg:'#eff6ff'}, b:{c:'#7c3aed',bg:'#f5f3ff'}, c:{c:'#16a34a',bg:'#f0fdf4'},
  d:{c:'#d97706',bg:'#fffbeb'}, e:{c:'#dc2626',bg:'#fef2f2'}, f:{c:'#0891b2',bg:'#ecfeff'},
  g:{c:'#6d28d9',bg:'#ede9fe'}, t:{c:'#64748b',bg:'#f1f5f9'}
};
var KEYS = ['a','b','c','d','e','f','g'];
function bvar(i, id) {
  if (id === 'T') return COLORS.t;
  return COLORS[KEYS[i % 7]];
}
function dc(o) { return JSON.parse(JSON.stringify(o)); }
function esc(s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }

var ALL_DATA = <?php echo $contenidos; ?>;
var ORIG = dc(ALL_DATA);
var APPLIED_SUBJECT = <?php echo json_encode($applied_subject); ?>;
var cur = APPLIED_SUBJECT;
var statsEl = document.getElementById('dsg-stats');
var legendEl = document.getElementById('dsg-legend');
var contentEl = document.getElementById('dsg-content');

var COURSEID = <?php echo (int)$courseid; ?>;
var AJAX_URL = M.cfg.wwwroot + '/local/sentinel/ajax.php';
var SESSKEY  = M.cfg.sesskey;

function notify(msg, type) {
  if (typeof M !== 'undefined' && M.core && M.core.notification) {
    M.core.notification.addNotification({message: msg, type: type || 'info'});
  }
}

// Load saved draft on init.
if (cur && ALL_DATA[cur]) {
  fetch(AJAX_URL + '?action=load_desgrane&courseid=' + COURSEID + '&subject=' + cur + '&sesskey=' + SESSKEY)
    .then(function(r) { return r.json(); })
    .then(function(d) {
      if (d.success && d.blocks) {
        ALL_DATA[cur].blocks = d.blocks;
      }
      render();
    })
    .catch(function() { render(); });
} else {
  render();
}

// Restore original proposal.
document.getElementById('dsg-reset').addEventListener('click', function() {
  if (!cur) return;
  ALL_DATA[cur] = dc(ORIG[cur]);
  render();
  notify('Propuesta original restaurada. Guarda el borrador para confirmar.', 'info');
});

// Save draft via AJAX.
document.getElementById('dsg-save').addEventListener('click', function() {
  if (!cur || !ALL_DATA[cur]) return;
  var btn = this;
  btn.disabled = true;
  btn.textContent = 'Guardando…';
  fetch(AJAX_URL + '?action=save_desgrane&courseid=' + COURSEID + '&subject=' + cur + '&sesskey=' + SESSKEY, {
    method: 'POST',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({ blocks: ALL_DATA[cur].blocks })
  })
  .then(function(r) { return r.json(); })
  .then(function(d) {
    btn.disabled = false;
    btn.textContent = 'Guardar borrador';
    if (d.success) {
      var n = 0;
      ALL_DATA[cur].blocks.forEach(function(b) { b.items.forEach(function(i) { n += i.subs.length; }); });
      notify('Borrador guardado: ' + n + ' sub-contenidos para ' + ALL_DATA[cur].name, 'success');
    } else {
      notify('Error al guardar: ' + (d.error || 'desconocido'), 'error');
    }
  })
  .catch(function() {
    btn.disabled = false;
    btn.textContent = 'Guardar borrador';
    notify('Error de red al guardar el borrador.', 'error');
  });
});

// Inject into Moodle competency module.
document.getElementById('dsg-inject').addEventListener('click', function() {
  if (!cur || !ALL_DATA[cur]) return;
  var btn = this;
  var n = 0;
  ALL_DATA[cur].blocks.forEach(function(b) { b.items.forEach(function(i) { n += i.subs.length; }); });
  if (!confirm('Se van a crear ' + n + ' competencias en el módulo nativo de Moodle para llevar el seguimiento de contenidos trabajados.\n\nSi ya existía un marco anterior para esta materia, se reemplazará.\n\n¿Continuar?')) return;
  btn.disabled = true;
  btn.textContent = 'Inyectando…';
  fetch(AJAX_URL + '?action=inject_desgrane&courseid=' + COURSEID + '&subject=' + cur + '&sesskey=' + SESSKEY, {
    method: 'POST',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({ blocks: ALL_DATA[cur].blocks })
  })
  .then(function(r) { return r.json(); })
  .then(function(d) {
    btn.disabled = false;
    btn.textContent = 'Inyectar en competencias';
    if (d.success) {
      notify('Inyectados ' + d.total + ' contenidos en el marco de competencias. ' + d.linked + ' sub-contenidos vinculados al curso.', 'success');
    } else {
      notify('Error: ' + (d.error || 'desconocido'), 'error');
    }
  })
  .catch(function() {
    btn.disabled = false;
    btn.textContent = 'Inyectar en competencias';
    notify('Error de red al inyectar contenidos.', 'error');
  });
});

function countSubs(subj) {
  var n = 0, items = 0;
  subj.blocks.forEach(function(b) { b.items.forEach(function(it) { items++; n += it.subs.length; }); });
  return { subs: n, items: items, blocks: subj.blocks.length };
}

function render() {
  contentEl.innerHTML = '';
  if (!cur || !ALL_DATA[cur]) {
    contentEl.innerHTML = '<p class="empty">No se encontraron contenidos para esta materia.</p>';
    statsEl.innerHTML = '';
    return;
  }
  var subj = ALL_DATA[cur];
  var c = countSubs(subj);
  statsEl.innerHTML = '<strong>' + esc(subj.name) + '</strong> ' + subj.grade +
    ' &mdash; ' + c.blocks + ' bloques, ' + c.items + ' contenidos, <strong>' + c.subs + ' sub-contenidos</strong>';

  subj.blocks.forEach(function(block, bi) {
    var bv = bvar(bi, block.id);
    var sec = document.createElement('div');
    sec.className = 'blk-section';
    if (block.id === 'T') sec.classList.add('transversal');

    var itemCount = 0;
    block.items.forEach(function(it) { itemCount += it.subs.length; });

    var title = document.createElement('div');
    title.className = 'blk-title';
    title.style.background = bv.bg;
    title.style.color = bv.c;
    title.innerHTML = '<span class="blk-arrow">&#9654;</span>' +
      '<span class="blk-letter" style="background:' + bv.c + '">' + block.id + '</span>' +
      esc(block.name) +
      '<span style="font-weight:400;font-size:.78em;margin-left:auto;color:#64748b">' +
      block.items.length + ' contenidos, ' + itemCount + ' sub-contenidos</span>';
    sec.appendChild(title);

    var blkBody = document.createElement('div');
    blkBody.className = 'blk-items';
    title.addEventListener('click', function() {
      blkBody.classList.toggle('open');
      title.querySelector('.blk-arrow').classList.toggle('open');
    });

    block.items.forEach(function(item) {
      var card = document.createElement('div'); card.className = 'card';
      var h = document.createElement('div'); h.className = 'card-h';
      var tl = item.legal.length > 100 ? item.legal.substring(0, 100) + '…' : item.legal;
      h.innerHTML = '<span class="tog">▶</span>' +
        '<span class="badge" style="background:' + bv.bg + ';color:' + bv.c + '">' + esc(item.c) + '</span>' +
        '<div class="card-txt"><div class="t">' + esc(tl) + '</div>' +
        '<div class="cnt">' + item.subs.length + ' sub-contenidos</div></div>';

      var body = document.createElement('div'); body.className = 'card-body';
      h.addEventListener('click', function() {
        body.classList.toggle('open');
        h.querySelector('.tog').classList.toggle('open');
      });

      var lg = document.createElement('div'); lg.className = 'legal';
      lg.innerHTML = '<div class="ll">Texto normativo (Decreto 65/2022)</div>' + esc(item.legal);
      body.appendChild(lg);

      var dh = document.createElement('div'); dh.className = 'dh';
      dh.innerHTML = '<span>Propuesta de desglose</span><span style="font-weight:400;text-transform:none">' +
        item.subs.length + ' elementos</span>';
      body.appendChild(dh);

      var list = document.createElement('div'); list.className = 'slist';
      var dragIdx = null;
      var rSubs = function() {
        list.innerHTML = '';
        item.subs.forEach(function(sub, si) {
          var el = document.createElement('div'); el.className = 'si'; el.draggable = true;
          el.dataset.idx = si;

          var grip = document.createElement('span'); grip.className = 'grip'; grip.textContent = '⠁⠁';
          var code = document.createElement('span'); code.className = 'sc'; code.textContent = sub.c;
          var txt = document.createElement('span'); txt.className = 'st'; txt.contentEditable = 'true'; txt.textContent = sub.d;
          txt.addEventListener('blur', function() { sub.d = txt.textContent.trim(); });
          txt.addEventListener('dragstart', function(e) { e.preventDefault(); e.stopPropagation(); });
          var acts = document.createElement('span'); acts.className = 'sa';
          var del = document.createElement('button'); del.className = 'sb del'; del.textContent = '✕'; del.title = 'Eliminar';
          del.addEventListener('click', function() {
            item.subs.splice(si, 1);
            renumber();
            rSubs();
            h.querySelector('.cnt').textContent = item.subs.length + ' sub-contenidos';
            dh.querySelector('span:last-child').textContent = item.subs.length + ' elementos';
            updateStats();
          });
          acts.appendChild(del);

          el.addEventListener('dragstart', function(e) {
            dragIdx = si;
            el.classList.add('dragging');
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('text/plain', si);
          });
          el.addEventListener('dragend', function() {
            el.classList.remove('dragging');
            dragIdx = null;
            list.querySelectorAll('.si').forEach(function(n) { n.classList.remove('drag-over'); });
          });
          el.addEventListener('dragover', function(e) {
            e.preventDefault();
            e.dataTransfer.dropEffect = 'move';
            list.querySelectorAll('.si').forEach(function(n) { n.classList.remove('drag-over'); });
            if (parseInt(el.dataset.idx) !== dragIdx) el.classList.add('drag-over');
          });
          el.addEventListener('drop', function(e) {
            e.preventDefault();
            var from = dragIdx;
            var to = parseInt(el.dataset.idx);
            if (from === null || from === to) return;
            var moved = item.subs.splice(from, 1)[0];
            item.subs.splice(to, 0, moved);
            renumber();
            rSubs();
          });

          el.appendChild(grip); el.appendChild(code); el.appendChild(txt); el.appendChild(acts);
          list.appendChild(el);
        });
      };
      function renumber() {
        item.subs.forEach(function(s, i) { s.c = item.c + '.' + (i + 1); });
      }
      rSubs();
      body.appendChild(list);

      var ab = document.createElement('button'); ab.className = 'add';
      ab.textContent = '+ Añadir sub-contenido';
      ab.addEventListener('click', function() {
        item.subs.push({ c: item.c + '.' + (item.subs.length + 1), d: 'Nuevo contenido' });
        rSubs();
        h.querySelector('.cnt').textContent = item.subs.length + ' sub-contenidos';
        dh.querySelector('span:last-child').textContent = item.subs.length + ' elementos';
        updateStats();
        var last = list.querySelector('.si:last-child .st');
        if (last) { last.focus(); document.execCommand('selectAll'); }
      });
      body.appendChild(ab);
      card.appendChild(h); card.appendChild(body); blkBody.appendChild(card);
    });
    sec.appendChild(blkBody);
    contentEl.appendChild(sec);
  });
}

function updateStats() {
  if (!cur || !ALL_DATA[cur]) return;
  var subj = ALL_DATA[cur];
  var c = countSubs(subj);
  statsEl.innerHTML = '<strong>' + esc(subj.name) + '</strong> ' + subj.grade +
    ' &mdash; ' + c.blocks + ' bloques, ' + c.items + ' contenidos, <strong>' + c.subs + ' sub-contenidos</strong>';
}
})();
</script>

<?php
echo $OUTPUT->footer();

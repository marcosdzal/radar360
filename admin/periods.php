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
 * Gestión de periodos de comunicación a familias.
 *
 * @package    local_sentinel
 * @copyright  2024 Marcos
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');

require_login();

$sysctx = context_system::instance();
$can_communicate = has_capability('local/sentinel:communicate', $sysctx);
if (!$can_communicate) {
    $courses = enrol_get_all_users_courses($USER->id, true);
    foreach ($courses as $c) {
        if (has_capability('local/sentinel:communicate', context_course::instance($c->id))) {
            $can_communicate = true;
            break;
        }
    }
}
if (!$can_communicate) {
    require_capability('local/sentinel:communicate', $sysctx);
}

$is_admin = has_capability('moodle/site:config', $sysctx);
$tutor_role = $DB->get_record('role', ['shortname' => 'tutor']);
$is_tutor = false;
if ($tutor_role) {
    $tutor_assignments = $DB->record_exists('role_assignments', [
        'userid' => $USER->id,
        'roleid' => $tutor_role->id,
    ]);
    $is_tutor = !empty($tutor_assignments);
}
$can_manage_periods = $is_admin || $is_tutor;

$PAGE->set_context($sysctx);
$PAGE->set_url(new moodle_url('/local/sentinel/admin/periods.php'));
$PAGE->set_title(get_string('periods:title', 'local_sentinel'));
$PAGE->set_heading(get_string('periods:title', 'local_sentinel'));
$PAGE->set_pagelayout('admin');

// ── Acciones POST ─────────────────────────────────────────────────────────────
$action = optional_param('action', '', PARAM_ALPHA);

if ($action === 'add' && confirm_sesskey() && $can_manage_periods) {
    $label      = required_param('label', PARAM_TEXT);
    $cutoffdate = required_param('cutoffdate', PARAM_INT);
    $courseid   = optional_param('courseid', 0, PARAM_INT);

    if ($cutoffdate > 0) {
        $max = (int)$DB->get_field_sql('SELECT COALESCE(MAX(sortorder),0) FROM {local_sentinel_period}');
        $DB->insert_record('local_sentinel_period', (object)[
            'courseid'   => $courseid,
            'sortorder'  => $max + 1,
            'label'      => trim($label),
            'cutoffdate' => $cutoffdate,
        ]);
        redirect(new moodle_url('/local/sentinel/admin/periods.php'), get_string('periods:saved', 'local_sentinel'), 2);
    }
}

if ($action === 'delete' && confirm_sesskey() && $can_manage_periods) {
    $id = required_param('id', PARAM_INT);
    $DB->delete_records('local_sentinel_period', ['id' => $id]);
    redirect(new moodle_url('/local/sentinel/admin/periods.php'), get_string('periods:deleted', 'local_sentinel'), 2);
}

// ── Render ────────────────────────────────────────────────────────────────────
echo $OUTPUT->header();
echo html_writer::tag('p', get_string('periods:intro', 'local_sentinel'), ['class' => 'text-muted']);

// Lista de periodos existentes.
$periods = $DB->get_records('local_sentinel_period', null, 'sortorder ASC');
?>
<style>
.sn-periods-table { width: 100%; border-collapse: collapse; margin-bottom: 2rem; }
.sn-periods-table th {
    font-size: .8em; font-weight: 700; color: #6b7280; text-transform: uppercase;
    letter-spacing: .06em; padding: 8px 12px; border-bottom: 2px solid #e5e7eb;
    text-align: left;
}
.sn-periods-table td { padding: 9px 12px; border-bottom: 1px solid #f3f4f6; font-size: .92em; }
.sn-periods-table tr:hover td { background: #f8fafc; }
.sn-add-form {
    background: #f8fafc; border: 1px solid #e5e7eb; border-radius: 10px;
    padding: 1.2rem 1.5rem; max-width: 560px;
}
.sn-add-form h5 { margin-bottom: 1rem; font-size: .95em; font-weight: 700; color: #374151; }
.sn-form-row { display: flex; gap: .75rem; flex-wrap: wrap; align-items: flex-end; }
.sn-form-group { display: flex; flex-direction: column; gap: .25rem; }
.sn-form-group label { font-size: .8em; font-weight: 600; color: #6b7280; }
.sn-form-group input { border: 1px solid #d1d5db; border-radius: 6px; padding: 6px 10px; font-size: .88em; }
.sn-form-group input:focus { outline: none; border-color: #0d6efd; box-shadow: 0 0 0 2px rgba(13,110,253,.15); }
</style>
<?php

if (empty($periods)) {
    echo html_writer::tag('p', 'Aún no hay ninguna comunicación configurada.', ['class' => 'text-muted font-italic']);
} else {
    echo '<table class="sn-periods-table">';
    echo '<thead><tr>'
       . '<th>#</th><th>Etiqueta</th><th>Fecha de corte</th>';
    if ($can_manage_periods) {
        echo '<th></th>';
    }
    echo '</tr></thead><tbody>';
    foreach ($periods as $p) {
        $datestr = userdate($p->cutoffdate, '%d/%m/%Y');
        echo '<tr>';
        echo '<td>' . (int)$p->sortorder . '</td>';
        echo '<td><strong>' . s($p->label) . '</strong></td>';
        echo '<td>' . $datestr . '</td>';
        if ($can_manage_periods) {
            $delurl = new moodle_url('/local/sentinel/admin/periods.php', [
                'action'  => 'delete',
                'id'      => $p->id,
                'sesskey' => sesskey(),
            ]);
            echo '<td>';
            echo html_writer::link($delurl->out(false), 'Eliminar',
                ['class' => 'btn btn-sm btn-outline-danger',
                 'onclick' => 'return confirm("¿Eliminar esta comunicación?")']);
            echo '</td>';
        }
        echo '</tr>';
    }
    echo '</tbody></table>';
}

if ($can_manage_periods) {
    $addurl = new moodle_url('/local/sentinel/admin/periods.php');
    echo '<div class="sn-add-form">';
    echo '<h5>Añadir comunicación</h5>';
    echo '<form method="post" action="' . $addurl->out(false) . '">';
    echo '<input type="hidden" name="action" value="add">';
    echo '<input type="hidden" name="sesskey" value="' . sesskey() . '">';
    echo '<input type="hidden" name="courseid" value="0">';
    echo '<div class="sn-form-row">';
    echo '<div class="sn-form-group">';
    echo '<label for="p-label">Etiqueta</label>';
    echo '<input type="text" id="p-label" name="label" placeholder="Ej: 1.ª evaluación" required style="width:200px">';
    echo '</div>';
    echo '<div class="sn-form-group">';
    echo '<label for="p-date">Fecha de corte</label>';
    echo '<input type="date" id="p-date" name="cutoffdate_ui" required style="width:160px">';
    echo '<input type="hidden" id="p-date-ts" name="cutoffdate" value="">';
    echo '</div>';
    echo '<div class="sn-form-group">';
    echo '<button type="submit" class="btn btn-primary btn-sm" style="margin-top:1px">Añadir</button>';
    echo '</div>';
    echo '</div>';
    echo '</form>';
    echo '</div>';
    ?>
    <script>
    document.getElementById('p-date').addEventListener('change', function () {
        var d = new Date(this.value + 'T23:59:59');
        document.getElementById('p-date-ts').value = Math.floor(d.getTime() / 1000);
    });
    </script>
    <?php
}
echo $OUTPUT->footer();

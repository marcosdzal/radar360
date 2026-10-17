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
 * Sample representativity threshold configuration.
 *
 * @package    local_sentinel
 * @copyright  2024 Marcos
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');

require_login();
require_capability('moodle/site:config', context_system::instance());

$PAGE->set_context(context_system::instance());
$PAGE->set_url(new moodle_url('/local/sentinel/admin/sample_config.php'));
$PAGE->set_title(get_string('sample:config_title', 'local_sentinel'));
$PAGE->set_heading(get_string('sample:config_title', 'local_sentinel'));
$PAGE->set_pagelayout('admin');

// ── Save ─────────────────────────────────────────────────────────────────────
if (optional_param('action', '', PARAM_ALPHA) === 'save' && confirm_sesskey()) {
    $red   = required_param('threshold_red', PARAM_INT);
    $green = required_param('threshold_green', PARAM_INT);
    $red   = max(0, min(100, $red));
    $green = max(0, min(100, $green));
    if ($green <= $red) {
        $green = $red + 1;
    }
    set_config('sample_threshold_red', $red, 'local_sentinel');
    set_config('sample_threshold_green', $green, 'local_sentinel');
    redirect(
        new moodle_url('/local/sentinel/admin/sample_config.php'),
        get_string('changessaved'),
        2
    );
}

// ── Current values ───────────────────────────────────────────────────────────
$threshold_red   = (int)(get_config('local_sentinel', 'sample_threshold_red') ?: 50);
$threshold_green = (int)(get_config('local_sentinel', 'sample_threshold_green') ?: 70);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('sample:config_title', 'local_sentinel'));
echo html_writer::tag('p', get_string('sample:config_intro', 'local_sentinel'), ['class' => 'text-muted']);
?>
<style>
.sn-sample-form {
    background: #f8fafc; border: 1px solid #e5e7eb; border-radius: 12px;
    padding: 1.5rem 2rem; max-width: 520px;
}
.sn-sample-form h5 { margin: 0 0 1.2rem; font-size: .95em; font-weight: 700; color: #374151; }
.sn-sf-row { display: flex; align-items: center; gap: 1rem; margin-bottom: 1rem; }
.sn-sf-dot {
    width: 14px; height: 14px; border-radius: 50%; flex-shrink: 0;
}
.sn-sf-dot--red   { background: #ef4444; }
.sn-sf-dot--amber { background: #f59e0b; }
.sn-sf-dot--green { background: #22c55e; }
.sn-sf-label { font-size: .88em; font-weight: 600; color: #374151; flex: 1; }
.sn-sf-input {
    width: 72px; border: 1px solid #d1d5db; border-radius: 6px;
    padding: 5px 8px; font-size: .88em; text-align: center;
}
.sn-sf-input:focus { outline: none; border-color: #0d6efd; box-shadow: 0 0 0 2px rgba(13,110,253,.15); }
.sn-sf-unit { font-size: .82em; color: #9ca3af; }
.sn-sf-preview {
    margin-top: 1.2rem; padding-top: 1rem; border-top: 1px solid #e5e7eb;
    font-size: .84em; color: #6b7280;
}
.sn-sf-bar {
    display: flex; height: 22px; border-radius: 6px; overflow: hidden; margin-top: .5rem;
}
.sn-sf-bar div { transition: width .3s; display: flex; align-items: center; justify-content: center;
    font-size: .72em; font-weight: 700; color: #fff; }
</style>

<div class="sn-sample-form">
    <h5>Umbrales del semáforo</h5>
    <form method="post" action="<?php echo (new moodle_url('/local/sentinel/admin/sample_config.php'))->out(false); ?>">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">

        <div class="sn-sf-row">
            <span class="sn-sf-dot sn-sf-dot--red"></span>
            <span class="sn-sf-label"><?php echo get_string('sample:threshold_red', 'local_sentinel'); ?></span>
            <span class="sn-sf-unit">&lt;</span>
            <input class="sn-sf-input" type="number" name="threshold_red" id="tr" min="1" max="99"
                   value="<?php echo $threshold_red; ?>">
            <span class="sn-sf-unit">%</span>
        </div>
        <div class="sn-sf-row">
            <span class="sn-sf-dot sn-sf-dot--amber"></span>
            <span class="sn-sf-label">Zona ámbar</span>
            <span class="sn-sf-unit" id="amber-range"><?php echo $threshold_red; ?>% – <?php echo $threshold_green - 1; ?>%</span>
        </div>
        <div class="sn-sf-row">
            <span class="sn-sf-dot sn-sf-dot--green"></span>
            <span class="sn-sf-label"><?php echo get_string('sample:threshold_green', 'local_sentinel'); ?></span>
            <span class="sn-sf-unit">≥</span>
            <input class="sn-sf-input" type="number" name="threshold_green" id="tg" min="2" max="100"
                   value="<?php echo $threshold_green; ?>">
            <span class="sn-sf-unit">%</span>
        </div>

        <div class="sn-sf-preview">
            Vista previa:
            <div class="sn-sf-bar" id="preview-bar">
                <div style="background:#ef4444;width:<?php echo $threshold_red; ?>%">&lt;<?php echo $threshold_red; ?>%</div>
                <div style="background:#f59e0b;width:<?php echo $threshold_green - $threshold_red; ?>%"><?php echo $threshold_red; ?>–<?php echo $threshold_green - 1; ?>%</div>
                <div style="background:#22c55e;width:<?php echo 100 - $threshold_green; ?>%">≥<?php echo $threshold_green; ?>%</div>
            </div>
        </div>

        <div style="margin-top:1.2rem">
            <button type="submit" class="btn btn-primary btn-sm">Guardar</button>
        </div>
    </form>
</div>

<script>
(function() {
    var tr = document.getElementById('tr');
    var tg = document.getElementById('tg');
    function update() {
        var r = parseInt(tr.value) || 50;
        var g = parseInt(tg.value) || 70;
        if (g <= r) g = r + 1;
        document.getElementById('amber-range').textContent = r + '% – ' + (g - 1) + '%';
        var bar = document.getElementById('preview-bar');
        bar.children[0].style.width = r + '%';
        bar.children[0].textContent = '<' + r + '%';
        bar.children[1].style.width = (g - r) + '%';
        bar.children[1].textContent = r + '–' + (g - 1) + '%';
        bar.children[2].style.width = (100 - g) + '%';
        bar.children[2].textContent = '≥' + g + '%';
    }
    tr.addEventListener('input', update);
    tg.addEventListener('input', update);
})();
</script>
<?php
echo $OUTPUT->footer();

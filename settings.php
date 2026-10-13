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
 * Admin settings for local_sentinel.
 *
 * @package    local_sentinel
 * @copyright  2024 Marcos
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage(
        'local_sentinel',
        get_string('pluginname', 'local_sentinel')
    );

    // ── Fotos de alumnos ─────────────────────────────────────────────────────
    $settings->add(new admin_setting_heading(
        'local_sentinel/heading_photos',
        get_string('settings:heading_photos', 'local_sentinel'),
        ''
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_sentinel/photoenabled',
        get_string('settings:photoenabled',     'local_sentinel'),
        get_string('settings:photoenabled_desc','local_sentinel'),
        1
    ));

    // ── Rúbrica ──────────────────────────────────────────────────────────────
    $settings->add(new admin_setting_heading(
        'local_sentinel/heading_rubric',
        get_string('settings:heading_rubric', 'local_sentinel'),
        ''
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_sentinel/rubricenabled',
        get_string('settings:rubricenabled',     'local_sentinel'),
        get_string('settings:rubricenabled_desc','local_sentinel'),
        1
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_sentinel/rubriccolorsenabled',
        get_string('settings:rubriccolorsenabled',     'local_sentinel'),
        get_string('settings:rubriccolorsenabled_desc','local_sentinel'),
        1
    ));

    // ── Informe del calificador ───────────────────────────────────────────────
    $settings->add(new admin_setting_heading(
        'local_sentinel/heading_grader',
        get_string('settings:heading_grader', 'local_sentinel'),
        ''
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_sentinel/graderclickenabled',
        get_string('settings:graderclickenabled',     'local_sentinel'),
        get_string('settings:graderclickenabled_desc','local_sentinel'),
        1
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_sentinel/graderabbrevtoggle',
        get_string('settings:graderabbrevtoggle',     'local_sentinel'),
        get_string('settings:graderabbrevtoggle_desc','local_sentinel'),
        1
    ));

    // ── Interfaz general ──────────────────────────────────────────────────────
    $settings->add(new admin_setting_heading(
        'local_sentinel/heading_ui',
        get_string('settings:heading_ui', 'local_sentinel'),
        ''
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_sentinel/drawerpersist',
        get_string('settings:drawerpersist',     'local_sentinel'),
        get_string('settings:drawerpersist_desc','local_sentinel'),
        1
    ));

    // ── Currículo (LOMLOE) ─────────────────────────────────────────────────────
    $settings->add(new admin_setting_heading(
        'local_sentinel/heading_curriculum',
        get_string('settings:heading_curriculum', 'local_sentinel'),
        ''
    ));

    $community_choices = ['' => get_string('settings:community_none', 'local_sentinel')];
    $catalog = \local_sentinel\curriculum_manager::get_catalog();
    if (!empty($catalog['communities'])) {
        foreach ($catalog['communities'] as $c) {
            $community_choices[$c['id']] = $c['name'];
        }
    }

    $settings->add(new admin_setting_configselect(
        'local_sentinel/community',
        get_string('settings:community', 'local_sentinel'),
        get_string('settings:community_desc', 'local_sentinel'),
        '',
        $community_choices
    ));

    // ── Perfil de salida (LOMLOE) ─────────────────────────────────────────────
    $settings->add(new admin_setting_heading(
        'local_sentinel/heading_descriptors',
        get_string('settings:heading_descriptors', 'local_sentinel'),
        ''
    ));

    $settings->add(new admin_setting_description(
        'local_sentinel/mapdescriptors_link',
        get_string('settings:mapdescriptors', 'local_sentinel'),
        html_writer::link(
            new moodle_url('/local/sentinel/admin/map_descriptors.php'),
            get_string('settings:managedescriptors_link', 'local_sentinel'),
            ['class' => 'btn btn-secondary btn-sm']
        )
    ));

    $settings->add(new admin_setting_description(
        'local_sentinel/periods_link',
        get_string('periods:title', 'local_sentinel'),
        html_writer::link(
            new moodle_url('/local/sentinel/admin/periods.php'),
            'Gestionar comunicaciones',
            ['class' => 'btn btn-secondary btn-sm']
        )
    ));

    $ADMIN->add('localplugins', $settings);
}

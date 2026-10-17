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
 * CLI: retroactively sync existing rubric criterion scores to Sentinel grade items.
 *
 * Run:  php local/sentinel/cli/sync_grades.php
 *
 * @package    local_sentinel
 * @copyright  2024 Marcos
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->libdir . '/gradelib.php');

cli_heading('Sentinel – Sincronización retroactiva de notas por criterio');

// Load all active mappings that have a grade item.
$mappings = $DB->get_records_select(
    'local_sentinel_map',
    'grade_category_id > 0 AND grade_item_id > 0'
);

if (!$mappings) {
    cli_writeln('No hay mapeos activos. Nada que sincronizar.');
    exit(0);
}

cli_writeln('Mapeos activos encontrados: ' . count($mappings));

$synced  = 0;
$skipped = 0;
$errors  = 0;
$touched_categories = [];

foreach ($mappings as $map) {
    $criterion_id  = (int)$map->rubric_row_id;
    $grade_item_id = (int)$map->grade_item_id;

    // Find the LATEST grading instance per student for this criterion.
    $fillings = $DB->get_records_sql(
        'SELECT f.levelid, gi.itemid AS assign_grade_id
           FROM {gradingform_rubric_fillings} f
           JOIN {grading_instances} gi
             ON gi.id = f.instanceid
            AND gi.timemodified = (
                SELECT MAX(gi2.timemodified)
                  FROM {grading_instances} gi2
                  JOIN {gradingform_rubric_fillings} f2 ON f2.instanceid = gi2.id
                 WHERE f2.criterionid = f.criterionid
                   AND gi2.itemid = gi.itemid
            )
          WHERE f.criterionid = :cid
            AND f.levelid > 0',
        ['cid' => $criterion_id]
    );

    if (!$fillings) {
        cli_writeln("  Criterio $criterion_id: sin notas registradas, omitido.");
        $skipped++;
        continue;
    }

    $gradeitem = grade_item::fetch(['id' => $grade_item_id]);
    if (!$gradeitem) {
        cli_writeln("  Criterio $criterion_id: grade_item $grade_item_id no encontrado, omitido.");
        $skipped++;
        continue;
    }

    foreach ($fillings as $filling) {
        // Get the score for the selected level.
        $score = $DB->get_field('gradingform_rubric_levels', 'score', ['id' => $filling->levelid]);
        if ($score === false) {
            continue;
        }

        // Resolve the student userid from assign_grades.
        $assign_grade = $DB->get_record('assign_grades', ['id' => $filling->assign_grade_id], 'id, userid');
        if (!$assign_grade) {
            continue;
        }

        try {
            $max_score = (float)$DB->get_field_sql(
                'SELECT COALESCE(MAX(score), 1) FROM {gradingform_rubric_levels} WHERE criterionid = :cid',
                ['cid' => $criterion_id]
            );
            $normalized = ($max_score > 0) ? round((float)$score / $max_score * 10, 4) : 0.0;
            $gradeitem->update_final_grade($assign_grade->userid, $normalized, 'local_sentinel');
            $touched_categories[(int)$map->grade_category_id] = true;
            $synced++;
            cli_writeln("  ✔ Criterio $criterion_id → alumno {$assign_grade->userid}: {$score} pts → {$normalized}/10");
        } catch (Exception $e) {
            cli_writeln("  ✘ Error criterio $criterion_id alumno {$assign_grade->userid}: " . $e->getMessage());
            $errors++;
        }
    }
}

// Recompute progressive weights for each affected category.
foreach (array_keys($touched_categories) as $catid) {
    \local_sentinel\observer::recompute_progressive_weights($catid);
    cli_writeln("  Pesos progresivos recalculados para categoría $catid");
}

cli_writeln('');
cli_writeln("Resultado: $synced notas sincronizadas, $skipped criterios omitidos, $errors errores.");
exit($errors > 0 ? 1 : 0);

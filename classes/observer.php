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

namespace local_sentinel;

/**
 * Event observer: propagates rubric criterion scores to their Sentinel grade items.
 *
 * @package    local_sentinel
 * @copyright  2024 Marcos
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class observer {

    /**
     * Fired when an assignment submission is graded.
     * Reads the rubric criterion fillings and updates the per-criterion grade items.
     *
     * @param \mod_assign\event\submission_graded $event
     */
    public static function submission_graded(\mod_assign\event\submission_graded $event): void {
        global $DB, $CFG;

        require_once($CFG->libdir . '/gradelib.php');

        $contextid = $event->contextid;
        $userid    = $event->relateduserid; // student being graded (userid is the grader)
        $gradeid   = $event->objectid; // assign_grades.id

        // Find the rubric grading instance tied to this specific grade record.
        $instance = $DB->get_record_sql(
            'SELECT gi.id
               FROM {grading_instances} gi
               JOIN {grading_definitions} gd ON gd.id  = gi.definitionid
               JOIN {grading_areas}       ga ON ga.id  = gd.areaid
              WHERE ga.contextid = :ctxid
                AND gi.itemid    = :itemid
                AND gd.method    = :method
              ORDER BY gi.timemodified DESC',
            ['ctxid' => $contextid, 'itemid' => $gradeid, 'method' => 'rubric'],
            IGNORE_MULTIPLE
        );
        if (!$instance) {
            return;
        }

        // Get the criterion fillings for this grading instance.
        $fillings = $DB->get_records('gradingform_rubric_fillings', ['instanceid' => $instance->id]);
        if (!$fillings) {
            return;
        }

        foreach ($fillings as $filling) {
            if (!$filling->levelid) {
                continue;
            }

            // Get the score of the selected rubric level.
            $score = $DB->get_field('gradingform_rubric_levels', 'score', ['id' => $filling->levelid]);
            if ($score === false) {
                continue;
            }

            // Find the Sentinel grade item for this criterion.
            $map = $DB->get_record('local_sentinel_map', ['rubric_row_id' => $filling->criterionid]);
            if (!$map || empty($map->grade_item_id) || empty($map->grade_category_id)) {
                continue; // Criterion not mapped or grade item hidden/removed.
            }

            // Sync criterion name → grade item name in case it was renamed.
            $crit_desc = $DB->get_field('gradingform_rubric_criteria', 'description', ['id' => $filling->criterionid]);
            if ($crit_desc !== false) {
                $gradeitem = \grade_item::fetch(['id' => $map->grade_item_id]);
                if ($gradeitem) {
                    $new_name = \core_text::substr(strip_tags($crit_desc), 0, 255);
                    if ($gradeitem->itemname !== $new_name) {
                        $gradeitem->itemname = $new_name;
                        $gradeitem->update('local_sentinel');
                    }
                }
            }

            // Update the student's grade on the criterion grade item.
            $gradeitem = \grade_item::fetch(['id' => $map->grade_item_id]);
            if (!$gradeitem) {
                continue;
            }

            // Normalizar la puntuación a base 10: (puntos_obtenidos / puntos_máximos) × 10.
            $max_score = (float)$DB->get_field_sql(
                'SELECT COALESCE(MAX(score), 1) FROM {gradingform_rubric_levels} WHERE criterionid = :cid',
                ['cid' => $filling->criterionid]
            );
            $normalized = ($max_score > 0) ? round((float)$score / $max_score * 10, 4) : 0.0;

            // Asegurar que el grademax del ítem es 10 (por si fue creado antes de esta versión).
            if ((float)$gradeitem->grademax !== 10.0) {
                $gradeitem->grademax = 10.0;
                $gradeitem->update('local_sentinel');
                $gradeitem = \grade_item::fetch(['id' => $map->grade_item_id]);
            }

            // Clear any manual override so the programmatic update is not blocked.
            $gradegrade = \grade_grade::fetch(['itemid' => $gradeitem->id, 'userid' => $userid]);
            if ($gradegrade && $gradegrade->overridden) {
                $gradegrade->overridden = 0;
                $gradegrade->update('local_sentinel');
            }

            $gradeitem->update_final_grade($userid, $normalized, 'local_sentinel');
        }
    }

    /**
     * Fired when a course module is updated (e.g. teacher changes assignment settings).
     * If Sentinel has active criterion mappings for this activity's rubric, override
     * any grade category change and keep the activity grade item at root with weight 0.
     *
     * @param \core\event\course_module_updated $event
     */
    public static function course_module_updated(\core\event\course_module_updated $event): void {
        global $DB, $CFG;

        require_once($CFG->libdir . '/gradelib.php');

        $context = \context_module::instance($event->objectid);

        // Find the rubric grading area for this module context.
        $area = $DB->get_record('grading_areas', ['contextid' => $context->id, 'activemethod' => 'rubric']);
        if (!$area) {
            return;
        }

        $definition = $DB->get_record('grading_definitions', ['areaid' => $area->id, 'method' => 'rubric']);
        if (!$definition) {
            return;
        }

        // Check if any criteria are actively mapped by Sentinel.
        $crit_ids = $DB->get_fieldset_select(
            'gradingform_rubric_criteria', 'id', 'definitionid = :did', ['did' => $definition->id]
        );
        if (!$crit_ids) {
            return;
        }

        [$insql, $inparams] = $DB->get_in_or_equal($crit_ids, SQL_PARAMS_NAMED, 'crit');
        $inparams['cat'] = 0;
        $active = $DB->count_records_select(
            'local_sentinel_map',
            "rubric_row_id $insql AND grade_category_id > :cat",
            $inparams
        );

        if ($active === 0) {
            return; // No active Sentinel mappings — nothing to enforce.
        }

        // Sentinel is active: ensure the activity grade item stays at root with weight 0.
        $courseid = $event->courseid;

        $cm = get_coursemodule_from_id('', $event->objectid);
        if (!$cm) {
            return;
        }

        $activity_gi = \grade_item::fetch([
            'itemtype'     => 'mod',
            'itemmodule'   => $cm->modname,
            'iteminstance' => $cm->instance,
            'courseid'     => $courseid,
        ]);
        if (!$activity_gi) {
            return;
        }

        $root_cat = $DB->get_record_select(
            'grade_categories', 'courseid = :cid AND depth = 1', ['cid' => $courseid], 'id'
        );
        if (!$root_cat) {
            return;
        }

        // Find the "Nota de Rúbrica" subcategory inside "Actividades (Sentinel)".
        $sentinel_cat = $DB->get_record_select(
            'grade_categories',
            "courseid = :cid AND depth = 2 AND fullname = :name",
            ['cid' => $courseid, 'name' => 'Actividades (Sentinel)'],
            'id'
        );
        $rubric_cat = $sentinel_cat ? $DB->get_record_select(
            'grade_categories',
            "courseid = :cid AND depth = 3 AND parent = :parent AND fullname = :name",
            ['cid' => $courseid, 'parent' => $sentinel_cat->id, 'name' => 'Nota de Rúbrica'],
            'id'
        ) : null;
        // Fall back to root if categories don't exist yet (ajax.php will create them on next mapping save).
        $target_cat_id = $rubric_cat ? (int)$rubric_cat->id : (int)$root_cat->id;

        // Only update if something has drifted from what Sentinel requires.
        if ((int)$activity_gi->categoryid === $target_cat_id
            && (float)$activity_gi->aggregationcoef  == 0.0
            && (float)$activity_gi->aggregationcoef2 == 0.0) {
            return;
        }

        $activity_gi->categoryid       = $target_cat_id;
        $activity_gi->aggregationcoef  = 0;
        $activity_gi->aggregationcoef2 = 0;
        $activity_gi->hidden           = 0;
        $activity_gi->update('local_sentinel');

        // Sync rubric criterion names → Sentinel grade item names.
        $criteria = $DB->get_records('gradingform_rubric_criteria', ['definitionid' => $definition->id], '', 'id, description');
        foreach ($criteria as $criterion) {
            $map = $DB->get_record('local_sentinel_map', ['rubric_row_id' => $criterion->id]);
            if (!$map || empty($map->grade_item_id)) {
                continue;
            }
            $gi = \grade_item::fetch(['id' => $map->grade_item_id]);
            if (!$gi) {
                continue;
            }
            $new_name = \core_text::substr(strip_tags($criterion->description), 0, 255);
            if ($gi->itemname !== $new_name) {
                $gi->itemname = $new_name;
                $gi->update('local_sentinel');
            }
        }
    }
}

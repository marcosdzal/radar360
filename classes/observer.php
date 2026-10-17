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
        $userid    = $event->relateduserid;
        $gradeid   = $event->objectid;

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

        $fillings = $DB->get_records('gradingform_rubric_fillings', ['instanceid' => $instance->id]);
        if (!$fillings) {
            return;
        }

        $touched_categories = [];

        foreach ($fillings as $filling) {
            if (!$filling->levelid) {
                continue;
            }

            $score = $DB->get_field('gradingform_rubric_levels', 'score', ['id' => $filling->levelid]);
            if ($score === false) {
                continue;
            }

            $map = $DB->get_record('local_sentinel_map', ['rubric_row_id' => $filling->criterionid]);
            if (!$map || empty($map->grade_item_id) || empty($map->grade_category_id)) {
                continue;
            }

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

            $gradeitem = \grade_item::fetch(['id' => $map->grade_item_id]);
            if (!$gradeitem) {
                continue;
            }

            $max_score = (float)$DB->get_field_sql(
                'SELECT COALESCE(MAX(score), 1) FROM {gradingform_rubric_levels} WHERE criterionid = :cid',
                ['cid' => $filling->criterionid]
            );
            $normalized = ($max_score > 0) ? round((float)$score / $max_score * 10, 4) : 0.0;

            if ((float)$gradeitem->grademax !== 10.0) {
                $gradeitem->grademax = 10.0;
                $gradeitem->update('local_sentinel');
                $gradeitem = \grade_item::fetch(['id' => $map->grade_item_id]);
            }

            $gradegrade = \grade_grade::fetch(['itemid' => $gradeitem->id, 'userid' => $userid]);
            if ($gradegrade && $gradegrade->overridden) {
                $gradegrade->overridden = 0;
                $gradegrade->update('local_sentinel');
            }

            $gradeitem->update_final_grade($userid, $normalized, 'local_sentinel');
            $touched_categories[(int)$map->grade_category_id] = true;
        }

        // Recompute progressive weights for each affected target category.
        foreach (array_keys($touched_categories) as $catid) {
            self::recompute_progressive_weights($catid);
        }

        // Re-enforce weight 0 on Sentinel container categories.
        self::enforce_sentinel_category_weights($event->courseid);
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

        $total_criteria = count($crit_ids);
        $all_mapped = ($active === $total_criteria && $total_criteria > 0);

        if (!$all_mapped) {
            return; // Not all rows mapped — activity grade keeps its weight.
        }

        // ALL rows mapped: ensure the activity grade item stays at weight 0.
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
            ['cid' => $courseid, 'name' => get_string('activities_category', 'local_sentinel')],
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
        $activity_gi->weightoverride   = 1;
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

        // Re-enforce Sentinel category weights.
        self::enforce_sentinel_category_weights($courseid);
    }

    /**
     * Re-enforce weight 0 on "Actividades (Radar 360)" and "Nota de Rúbrica" categories.
     */
    public static function enforce_sentinel_category_weights(int $courseid): void {
        global $DB;

        $sentinel_cat_name = get_string('activities_category', 'local_sentinel');
        $sentinel_cat = $DB->get_record_select(
            'grade_categories',
            "courseid = :cid AND depth = 2 AND fullname = :name",
            ['cid' => $courseid, 'name' => $sentinel_cat_name],
            'id'
        );
        if (!$sentinel_cat) {
            return;
        }

        // Force weight 0 on "Actividades (Radar 360)".
        $cat_gi = \grade_item::fetch(['itemtype' => 'category', 'iteminstance' => $sentinel_cat->id]);
        if ($cat_gi && ((float)$cat_gi->aggregationcoef2 != 0.0 || !$cat_gi->weightoverride)) {
            $cat_gi->aggregationcoef2 = 0;
            $cat_gi->weightoverride   = 1;
            $cat_gi->update('local_sentinel');
        }

        // Force weight 0 on "Nota de Rúbrica".
        $rubric_cat = $DB->get_record_select(
            'grade_categories',
            "courseid = :cid AND parent = :parent AND fullname = :name",
            ['cid' => $courseid, 'parent' => $sentinel_cat->id, 'name' => 'Nota de Rúbrica'],
            'id'
        );
        if ($rubric_cat) {
            $rub_gi = \grade_item::fetch(['itemtype' => 'category', 'iteminstance' => $rubric_cat->id]);
            if ($rub_gi && ((float)$rub_gi->aggregationcoef2 != 0.0 || !$rub_gi->weightoverride)) {
                $rub_gi->aggregationcoef2 = 0;
                $rub_gi->weightoverride   = 1;
                $rub_gi->update('local_sentinel');
            }
        }
    }

    public static function recompute_progressive_weights(int $categoryid): void {
        global $DB, $CFG;
        require_once($CFG->libdir . '/gradelib.php');

        $cat = $DB->get_record('grade_categories', ['id' => $categoryid]);
        if (!$cat) {
            return;
        }
        if ((int)$cat->aggregation !== GRADE_AGGREGATE_WEIGHTED_MEAN) {
            $DB->set_field('grade_categories', 'aggregation', GRADE_AGGREGATE_WEIGHTED_MEAN, ['id' => $categoryid]);
        }

        $items = $DB->get_records_select(
            'grade_items',
            "categoryid = :catid AND itemtype = 'manual' AND idnumber LIKE 'sentinel_crit_%' AND hidden = 0",
            ['catid' => $categoryid],
            'timecreated ASC'
        );
        if (!$items) {
            return;
        }

        $pos = 1;
        foreach ($items as $item) {
            $new_weight = (float)$pos;
            $stored_key = 'progweight_' . $item->id;
            $stored     = get_config('local_sentinel', $stored_key);

            if ($stored !== false && abs((float)$stored - (float)$item->aggregationcoef) > 0.0001) {
                $pos++;
                continue; // Teacher manually changed — leave alone.
            }

            if ((float)$item->aggregationcoef != $new_weight) {
                $DB->set_field('grade_items', 'aggregationcoef', $new_weight, ['id' => $item->id]);
            }
            set_config($stored_key, $new_weight, 'local_sentinel');
            $pos++;
        }
    }
}

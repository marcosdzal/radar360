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
 * Competency descriptor grade calculator for local_sentinel.
 *
 * @package    local_sentinel
 * @copyright  2024 Marcos
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_sentinel;

defined('MOODLE_INTERNAL') || die();

class descriptor_calculator {

    const CE_PALETTE = [
        '#f59e0b', '#10b981', '#ef4444', '#8b5cf6',
        '#06b6d4', '#ec4899', '#84cc16', '#f97316',
        '#6366f1', '#14b8a6', '#e11d48', '#0ea5e9',
    ];

    /** LOMLOE competency area definitions (RD 217/2022). */
    const AREAS = [
        'CCL'   => ['name' => 'Comunicación lingüística',                           'color' => '#2563eb', 'total' => 5],
        'CP'    => ['name' => 'Competencia plurilingüe',                            'color' => '#7c3aed', 'total' => 3],
        'STEM'  => ['name' => 'Competencia STEM',                                   'color' => '#0891b2', 'total' => 5],
        'CD'    => ['name' => 'Competencia digital',                                'color' => '#0d9488', 'total' => 5],
        'CPSAA' => ['name' => 'Personal, social y aprender a aprender',             'color' => '#d97706', 'total' => 5],
        'CC'    => ['name' => 'Competencia ciudadana',                              'color' => '#b45309', 'total' => 4],
        'CE'    => ['name' => 'Competencia emprendedora',                           'color' => '#dc2626', 'total' => 3],
        'CCEC'  => ['name' => 'Conciencia y expresión culturales',                  'color' => '#9333ea', 'total' => 4],
    ];

    /**
     * Returns the full competency report data for a student.
     *
     * @param int      $userid     Student user ID.
     * @param int|null $courseid   Filter to a specific course (null = all courses).
     * @param int|null $cutoffdate Unix timestamp; only grade events on or before this date (null = all).
     * @return array {areas, periods, summary}
     */
    public static function get_student_data(int $userid, ?int $courseid = null, ?int $cutoffdate = null): array {
        global $DB;

        // ── 1. Load all descriptors ───────────────────────────────────────────
        $alldescriptors = $DB->get_records('local_sentinel_descriptor', null, 'sortorder ASC');

        // ── 2. Fetch all graded rubric occasions for this student ─────────────
        $params = ['userid' => $userid];
        $coursesql   = '';
        $cutoffsql   = '';

        if ($courseid) {
            $coursesql = ' AND c.id = :courseid';
            $params['courseid'] = $courseid;
        }
        if ($cutoffdate) {
            $cutoffsql = ' AND ag.timemodified <= :cutoffdate';
            $params['cutoffdate'] = $cutoffdate;
        }

        $sql = "
            SELECT
                CONCAT(rf.id, '_', cd.descriptorcode) AS row_key,
                ag.id          AS grade_id,
                c.id           AS courseid,
                c.shortname    AS coursename,
                cm.id          AS cmid,
                a.name         AS activityname,
                rc.id          AS criteriaid,
                rc.description AS criteriadesc,
                rf.levelid,
                rl.score       AS levelscore,
                maxrl.maxscore,
                rl.definition  AS leveldefinition,
                cd.descriptorcode,
                ag.timemodified AS gradetime
            FROM {assign_grades} ag
            JOIN {assign} a         ON a.id = ag.assignment
            JOIN {course} c         ON c.id = a.course
            JOIN {course_modules} cm ON cm.course = c.id AND cm.instance = a.id
                                    AND cm.deletioninprogress = 0
            JOIN {modules} m        ON m.id = cm.module AND m.name = 'assign'
            JOIN {context} ctx      ON ctx.contextlevel = 70 AND ctx.instanceid = cm.id
            JOIN {grading_areas} ga  ON ga.contextid = ctx.id AND ga.component = 'mod_assign'
            JOIN {grading_definitions} gd ON gd.areaid = ga.id
                                          AND gd.method = 'rubric'
                                          AND gd.status = 20
            JOIN {gradingform_rubric_criteria} rc ON rc.definitionid = gd.id
            -- Path A: criterion name → manual grade item name (legacy workflow).
            LEFT JOIN (
                SELECT DISTINCT gi2.itemname, gi2.courseid, gi2.categoryid
                FROM {grade_items} gi2
                WHERE gi2.itemtype = 'manual'
            ) gi_match ON gi_match.itemname = rc.description
                       AND gi_match.courseid = c.id
            -- Path B: assign mod grade item → its category (simple workflow, no manual items needed).
            LEFT JOIN {grade_items} gi_mod ON gi_mod.iteminstance = a.id
                                          AND gi_mod.itemtype = 'mod'
                                          AND gi_mod.itemmodule = 'assign'
                                          AND gi_mod.courseid = c.id
            -- Prefer path A category; fall back to path B when no manual item matches.
            JOIN {grade_categories} gc_crit ON gc_crit.id = COALESCE(gi_match.categoryid, gi_mod.categoryid)
            -- Map category → descriptor code.
            JOIN {local_sentinel_cat_descriptor} cd ON cd.grade_category_id = gc_crit.id
            JOIN {grading_instances} gi ON gi.definitionid = gd.id
                                       AND gi.itemid = ag.id
                                       AND gi.status = 1
            JOIN {gradingform_rubric_fillings} rf ON rf.instanceid = gi.id
                                                  AND rf.criterionid = rc.id
            JOIN {gradingform_rubric_levels} rl ON rl.id = rf.levelid
            JOIN (
                SELECT criterionid, MAX(score) AS maxscore
                FROM {gradingform_rubric_levels}
                GROUP BY criterionid
            ) maxrl ON maxrl.criterionid = rc.id
            WHERE ag.userid = :userid
              AND ag.attemptnumber = (
                  SELECT MAX(ag2.attemptnumber)
                  FROM {assign_grades} ag2
                  WHERE ag2.assignment = ag.assignment AND ag2.userid = ag.userid
              )
            $coursesql
            $cutoffsql
            ORDER BY cd.descriptorcode, c.id, cm.id
        ";

        $rows = $DB->get_records_sql($sql, $params);

        // ── 3. Group occasions by descriptor ──────────────────────────────────
        // desc_occasions[code] = [{normalised 0-10 grade, courseid, coursename, cmid, activityname, criteriadesc, leveldefinition, gradetime}]
        $desc_occasions = [];
        foreach ($rows as $r) {
            $code = $r->descriptorcode;
            if (!isset($desc_occasions[$code])) {
                $desc_occasions[$code] = [];
            }
            $normalised = ($r->maxscore > 0) ? round(($r->levelscore / $r->maxscore) * 10, 2) : 0;
            $desc_occasions[$code][] = [
                'grade'          => $normalised,
                'courseid'       => (int)$r->courseid,
                'coursename'     => $r->coursename,
                'cmid'           => (int)$r->cmid,
                'activityname'   => $r->activityname,
                'criteriadesc'   => $r->criteriadesc,
                'leveldefinition'=> $r->leveldefinition,
                'gradetime'      => (int)$r->gradetime,
            ];
        }

        // ── 4. Build area → descriptor structure ─────────────────────────────
        $areas = [];
        foreach (self::AREAS as $areacode => $areadef) {
            $area_entry = [
                'name'        => $areadef['name'],
                'color'       => $areadef['color'],
                'total'       => $areadef['total'],
                'grade'       => null,   // CK grade 0-10
                'worked'      => 0,
                'occasions'   => 0,
                'courses'     => [],
                'descriptors' => [],
            ];

            $gradesum = 0; // sum of descriptor grades (0 for unworked)

            foreach ($alldescriptors as $desc) {
                if ($desc->area !== $areacode) {
                    continue;
                }

                $occasions = $desc_occasions[$desc->code] ?? [];
                $worked    = !empty($occasions);

                if ($worked) {
                    $grades       = array_column($occasions, 'grade');
                    $desc_grade   = round(array_sum($grades) / count($grades), 2);
                    $courses_here = array_unique(array_column($occasions, 'coursename'));

                    $area_entry['worked']++;
                    $area_entry['occasions'] += count($occasions);
                    foreach ($courses_here as $cn) {
                        $area_entry['courses'][$cn] = true;
                    }
                    $gradesum += $desc_grade;
                } else {
                    $desc_grade = null;
                    $courses_here = [];
                }

                $area_entry['descriptors'][$desc->code] = [
                    'code'        => $desc->code,
                    'description' => $desc->description,
                    'grade'       => $desc_grade,     // null = not worked
                    'occasions'   => count($occasions),
                    'courses'     => array_values(array_unique($courses_here)),
                    'activities'  => $occasions,
                ];
            }

            // CK grade = sum / total (unworked = 0). Official LOMLOE formula.
            $area_entry['grade'] = round($gradesum / $areadef['total'], 2);

            // Partial grade = sum / worked only. Reflects what the student was actually assessed on.
            // null when nothing has been worked yet.
            $area_entry['partial_grade'] = $area_entry['worked'] > 0
                ? round($gradesum / $area_entry['worked'], 2)
                : null;

            $area_entry['courses'] = array_keys($area_entry['courses']);

            $areas[$areacode] = $area_entry;
        }

        // ── 5. Periods ────────────────────────────────────────────────────────
        // Include periods created for any course the student is enrolled in,
        // plus site-wide periods (courseid=0).
        $student_courses    = enrol_get_users_courses($userid, true);
        $student_courseids  = array_keys($student_courses);
        $periods = self::get_periods($student_courseids);

        // ── 6. Summary ────────────────────────────────────────────────────────
        $total_worked = 0;
        $total_occ    = 0;
        $all_courses  = [];
        foreach ($areas as $a) {
            $total_worked += $a['worked'];
            $total_occ    += $a['occasions'];
            foreach ($a['courses'] as $cn) {
                $all_courses[$cn] = true;
            }
        }

        $summary = [
            'total_descriptors' => 34,
            'worked_descriptors'=> $total_worked,
            'total_occasions'   => $total_occ,
            'total_courses'     => count($all_courses),
        ];

        return compact('areas', 'periods', 'summary');
    }

    /**
     * Returns descriptor grade averages across all classmates of $userid.
     * Used to render the "+X vs clase" comparison on descriptor cards.
     *
     * @return array [descriptorcode => float avg_grade]
     */
    public static function get_class_averages(int $userid, ?int $cutoffdate = null): array {
        $courses = enrol_get_users_courses($userid, true);
        if (empty($courses)) {
            return [];
        }
        $all_students = [];
        foreach ($courses as $course) {
            $ctx = \context_course::instance($course->id);
            foreach (get_enrolled_users($ctx, 'mod/assign:submit') as $u) {
                $all_students[$u->id] = true;
            }
        }
        if (empty($all_students)) {
            return [];
        }

        $totals = [];
        $counts = [];
        foreach (array_keys($all_students) as $sid) {
            $sdata = self::get_student_data((int)$sid, null, $cutoffdate);
            foreach ($sdata['areas'] as $adata) {
                foreach ($adata['descriptors'] as $dcode => $ddata) {
                    if ($ddata['grade'] !== null) {
                        $totals[$dcode] = ($totals[$dcode] ?? 0.0) + $ddata['grade'];
                        $counts[$dcode] = ($counts[$dcode] ?? 0) + 1;
                    }
                }
            }
        }

        $avgs = [];
        foreach ($totals as $dcode => $total) {
            $avgs[$dcode] = round($total / $counts[$dcode], 1);
        }
        return $avgs;
    }

    /**
     * Returns configured communication periods, site-wide (courseid=0) plus specific courses.
     */
    public static function get_periods(array $courseids = []): array {
        global $DB;

        list($insql, $inparams) = $DB->get_in_or_equal(
            array_merge([0], $courseids),
            SQL_PARAMS_NAMED,
            'cid'
        );

        $sql = "SELECT * FROM {local_sentinel_period} WHERE courseid $insql ORDER BY sortorder ASC";
        return array_values($DB->get_records_sql($sql, $inparams));
    }

    /**
     * Returns CE (competencia específica) data for a student in a specific course.
     */
    public static function get_student_ce_data(int $userid, int $courseid): array {
        global $DB;

        $course = $DB->get_record('course', ['id' => $courseid], 'id, fullname, shortname', MUST_EXIST);

        $items = $DB->get_records_sql(
            "SELECT gi.id, gi.itemtype, gi.iteminstance, gi.categoryid, gi.grademax,
                    gi.itemname, gi.sortorder,
                    gg.finalgrade, gg.timemodified
             FROM {grade_items} gi
             LEFT JOIN {grade_grades} gg ON gg.itemid = gi.id AND gg.userid = :userid
             WHERE gi.courseid = :courseid",
            ['userid' => $userid, 'courseid' => $courseid]
        );

        $course_item = null;
        $cat_items = [];
        $child_items = [];
        foreach ($items as $it) {
            if ($it->itemtype === 'course') {
                $course_item = $it;
            } elseif ($it->itemtype === 'category') {
                $cat_items[(int)$it->iteminstance] = $it;
            } else {
                $child_items[(int)$it->categoryid][] = $it;
            }
        }

        $subject_grade = null;
        if ($course_item && $course_item->finalgrade !== null) {
            $max = (float)$course_item->grademax ?: 100;
            $subject_grade = round(((float)$course_item->finalgrade / $max) * 10, 2);
        }

        $activities_cat_name = get_string('activities_category', 'local_sentinel');
        $all_depth2 = $DB->get_records_sql(
            "SELECT gc.*, gi.sortorder AS gi_sortorder
               FROM {grade_categories} gc
               JOIN {grade_items} gi ON gi.itemtype = 'category' AND gi.iteminstance = gc.id AND gi.courseid = gc.courseid
              WHERE gc.courseid = ? AND gc.depth = ?
              ORDER BY gi.sortorder ASC",
            [$courseid, 2]
        );
        $ce_cats = array_filter($all_depth2, fn($c) => $c->fullname !== $activities_cat_name);

        // Fetch depth-3 subcategories (criteria of each CE).
        $all_depth3 = $DB->get_records_sql(
            "SELECT gc.id, gc.fullname, gc.parent, gc.depth,
                    gi.sortorder AS gi_sortorder
               FROM {grade_categories} gc
               JOIN {grade_items} gi ON gi.itemtype = 'category' AND gi.iteminstance = gc.id
                                     AND gi.courseid = gc.courseid
              WHERE gc.courseid = ? AND gc.depth = ?
              ORDER BY gi.sortorder ASC",
            [$courseid, 3]
        );
        $subcats_by_parent = [];
        foreach ($all_depth3 as $sc) {
            $subcats_by_parent[(int)$sc->parent][] = $sc;
        }

        $ces = [];
        $color_idx = 0;

        foreach ($ce_cats as $cat) {
            $catid = (int)$cat->id;

            $ce_grade = null;
            if (isset($cat_items[$catid])) {
                $ci = $cat_items[$catid];
                if ($ci->finalgrade !== null) {
                    $max = (float)$ci->grademax ?: 10;
                    $ce_grade = round(((float)$ci->finalgrade / $max) * 10, 2);
                }
            }

            // Criteria = depth-3 subcategories under this CE.
            // Fall back to direct child items if no subcategories.
            $subcats = $subcats_by_parent[$catid] ?? [];
            $use_subcats = !empty($subcats);

            $criteria = [];
            $worked = 0;

            if ($use_subcats) {
                foreach ($subcats as $sc) {
                    $scid = (int)$sc->id;
                    $crit_grade = null;
                    if (isset($cat_items[$scid])) {
                        $sci = $cat_items[$scid];
                        if ($sci->finalgrade !== null) {
                            $max = (float)$sci->grademax ?: 10;
                            $crit_grade = round(((float)$sci->finalgrade / $max) * 10, 2);
                            $worked++;
                        }
                    }

                    // Activities: look for sentinel_map entries for items inside this subcategory.
                    $activities = [];
                    $sc_items = $child_items[$scid] ?? [];
                    foreach ($sc_items as $gi) {
                        $map = $DB->get_record('local_sentinel_map', ['grade_item_id' => $gi->id]);
                        if (!$map || $gi->finalgrade === null) { continue; }
                        $fillings = $DB->get_records_sql(
                            "SELECT rf.id, rl.score, rl.definition AS leveldefinition,
                                    maxrl.maxscore,
                                    a.name AS activityname, c.shortname AS coursename,
                                    cm.id AS cmid, ag.timemodified AS gradetime,
                                    rc.description AS criteriadesc
                             FROM {gradingform_rubric_fillings} rf
                             JOIN {grading_instances} gri ON gri.id = rf.instanceid AND gri.status = 1
                             JOIN {assign_grades} ag ON ag.id = gri.itemid AND ag.userid = :userid
                             JOIN {assign} a ON a.id = ag.assignment
                             JOIN {course} c ON c.id = a.course
                             JOIN {course_modules} cm ON cm.course = c.id AND cm.instance = a.id
                                                      AND cm.deletioninprogress = 0
                             JOIN {modules} m ON m.id = cm.module AND m.name = 'assign'
                             JOIN {gradingform_rubric_criteria} rc ON rc.id = rf.criterionid
                             JOIN {gradingform_rubric_levels} rl ON rl.id = rf.levelid
                             JOIN (
                                 SELECT criterionid, MAX(score) AS maxscore
                                 FROM {gradingform_rubric_levels}
                                 GROUP BY criterionid
                             ) maxrl ON maxrl.criterionid = rf.criterionid
                             WHERE rf.criterionid = :critid
                               AND ag.attemptnumber = (
                                   SELECT MAX(ag2.attemptnumber)
                                   FROM {assign_grades} ag2
                                   WHERE ag2.assignment = ag.assignment AND ag2.userid = ag.userid
                               )",
                            ['userid' => $userid, 'critid' => $map->rubric_row_id]
                        );
                        foreach ($fillings as $f) {
                            $norm = ($f->maxscore > 0) ? round(($f->score / $f->maxscore) * 10, 2) : 0;
                            $activities[] = [
                                'grade'           => $norm,
                                'activityname'    => $f->activityname,
                                'coursename'      => $f->coursename,
                                'cmid'            => (int)$f->cmid,
                                'criteriadesc'    => $f->criteriadesc,
                                'leveldefinition' => $f->leveldefinition,
                                'gradetime'       => (int)$f->gradetime,
                            ];
                        }
                    }

                    $criteria[] = [
                        'name'          => $sc->fullname,
                        'grade'         => $crit_grade,
                        'grade_item_id' => isset($cat_items[$scid]) ? (int)$cat_items[$scid]->id : 0,
                        'occasions'     => count($activities),
                        'activities'    => $activities,
                    ];
                }
            } else {
                // Fallback: direct child items under the CE category.
                $crit_list = $child_items[$catid] ?? [];
                usort($crit_list, fn($a, $b) => $a->sortorder <=> $b->sortorder);
                foreach ($crit_list as $gi) {
                    $crit_grade = null;
                    if ($gi->finalgrade !== null) {
                        $max = (float)$gi->grademax ?: 10;
                        $crit_grade = round(((float)$gi->finalgrade / $max) * 10, 2);
                        $worked++;
                    }
                    $criteria[] = [
                        'name'          => $gi->itemname,
                        'grade'         => $crit_grade,
                        'grade_item_id' => (int)$gi->id,
                        'occasions'     => 0,
                        'activities'    => [],
                    ];
                }
            }

            $color = self::CE_PALETTE[$color_idx % count(self::CE_PALETTE)];
            $color_idx++;

            $total_occ = 0;
            $all_days = [];
            foreach ($criteria as $cr) {
                $total_occ += $cr['occasions'];
                foreach ($cr['activities'] as $act) {
                    if (!empty($act['gradetime'])) {
                        $all_days[date('Y-m-d', $act['gradetime'])] = true;
                    }
                }
            }

            $ces[] = [
                'id'             => $catid,
                'name'           => $cat->fullname,
                'color'          => $color,
                'grade'          => $ce_grade,
                'criteria_total' => count($criteria),
                'criteria_worked'=> $worked,
                'occasions'      => $total_occ,
                'days'           => count($all_days),
                'criteria'       => $criteria,
            ];
        }

        return [
            'course'        => $course,
            'subject_grade' => $subject_grade,
            'ces'           => $ces,
        ];
    }

    /**
     * Returns an HSL colour string for a grade between 0 and 10.
     * 0 = red (hue 0), 10 = green (hue 120).
     */
    public static function grade_colour(float $grade): string {
        $hue = (int)round(($grade / 10) * 120);
        return "hsl({$hue}, 65%, 36%)";
    }

    /**
     * Returns a performance level label for a grade, using community thresholds.
     * Default thresholds: <5 Iniciación, <7 En proceso, <9 Adquirida, >=9 Avanzada.
     */
    public static function performance_level(float $grade): string {
        if ($grade < 5)  { return get_string('report:level_iniciacion', 'local_sentinel'); }
        if ($grade < 7)  { return get_string('report:level_enproceso',  'local_sentinel'); }
        if ($grade < 9)  { return get_string('report:level_adquirida',  'local_sentinel'); }
        return get_string('report:level_avanzada', 'local_sentinel');
    }

    /**
     * Computes sample representativity for a student in a course.
     *
     * Numerator:   distinct assignments where the student has a rubric grade.
     * Denominator: distinct assignments where ANY enrolled student has a rubric grade.
     *
     * @return array {pct: float 0-100, evaluated: int, total: int, level: 'red'|'amber'|'green', label: string}
     */
    public static function get_representativity(int $userid, int $courseid): array {
        global $DB;

        // Assignments in this course that use a published rubric and have at least
        // one student graded (i.e., "active" for the group).
        $sql_total = "
            SELECT COUNT(DISTINCT a.id)
              FROM {assign} a
              JOIN {course_modules} cm ON cm.instance = a.id AND cm.course = a.course
                                       AND cm.deletioninprogress = 0
              JOIN {modules} m ON m.id = cm.module AND m.name = 'assign'
              JOIN {context} ctx ON ctx.contextlevel = 70 AND ctx.instanceid = cm.id
              JOIN {grading_areas} ga ON ga.contextid = ctx.id AND ga.component = 'mod_assign'
              JOIN {grading_definitions} gd ON gd.areaid = ga.id AND gd.method = 'rubric' AND gd.status = 20
              JOIN {assign_grades} ag ON ag.assignment = a.id
              JOIN {grading_instances} gi ON gi.definitionid = gd.id AND gi.itemid = ag.id AND gi.status = 1
             WHERE a.course = :courseid
        ";
        $total = (int)$DB->count_records_sql($sql_total, ['courseid' => $courseid]);

        // Of those, how many has THIS student been graded on.
        $sql_student = "
            SELECT COUNT(DISTINCT a.id)
              FROM {assign} a
              JOIN {course_modules} cm ON cm.instance = a.id AND cm.course = a.course
                                       AND cm.deletioninprogress = 0
              JOIN {modules} m ON m.id = cm.module AND m.name = 'assign'
              JOIN {context} ctx ON ctx.contextlevel = 70 AND ctx.instanceid = cm.id
              JOIN {grading_areas} ga ON ga.contextid = ctx.id AND ga.component = 'mod_assign'
              JOIN {grading_definitions} gd ON gd.areaid = ga.id AND gd.method = 'rubric' AND gd.status = 20
              JOIN {assign_grades} ag ON ag.assignment = a.id AND ag.userid = :userid
              JOIN {grading_instances} gi ON gi.definitionid = gd.id AND gi.itemid = ag.id AND gi.status = 1
             WHERE a.course = :courseid
        ";
        $evaluated = (int)$DB->count_records_sql($sql_student, ['userid' => $userid, 'courseid' => $courseid]);

        $pct = $total > 0 ? round(($evaluated / $total) * 100, 1) : 0;

        if ($total === 0) {
            return ['pct' => 0, 'evaluated' => 0, 'total' => 0, 'level' => 'grey',
                    'label' => get_string('sample:nodata', 'local_sentinel')];
        }

        // Thresholds from plugin config (defaults: red < 50, amber < 70).
        $threshold_red   = (float)(get_config('local_sentinel', 'sample_threshold_red') ?: 50);
        $threshold_green = (float)(get_config('local_sentinel', 'sample_threshold_green') ?: 70);

        if ($pct < $threshold_red) {
            $level = 'red';
            $label = get_string('sample:insufficient', 'local_sentinel');
        } elseif ($pct < $threshold_green) {
            $level = 'amber';
            $label = get_string('sample:fair', 'local_sentinel');
        } else {
            $level = 'green';
            $label = get_string('sample:solid', 'local_sentinel');
        }

        return compact('pct', 'evaluated', 'total', 'level', 'label');
    }

    /**
     * Computes sample representativity across ALL courses for a student (global view).
     *
     * @return array {pct: float 0-100, evaluated: int, total: int, level: 'red'|'amber'|'green', label: string}
     */
    public static function get_representativity_global(int $userid): array {
        $courses = enrol_get_users_courses($userid, true);
        $total_eval = 0;
        $total_acts = 0;
        foreach ($courses as $c) {
            $r = self::get_representativity($userid, $c->id);
            $total_eval += $r['evaluated'];
            $total_acts += $r['total'];
        }
        if ($total_acts === 0) {
            return ['pct' => 0, 'evaluated' => 0, 'total' => 0, 'level' => 'grey',
                    'label' => get_string('sample:nodata', 'local_sentinel')];
        }

        $pct = round(($total_eval / $total_acts) * 100, 1);

        $threshold_red   = (float)(get_config('local_sentinel', 'sample_threshold_red') ?: 50);
        $threshold_green = (float)(get_config('local_sentinel', 'sample_threshold_green') ?: 70);

        if ($pct < $threshold_red) {
            $level = 'red';
            $label = get_string('sample:insufficient', 'local_sentinel');
        } elseif ($pct < $threshold_green) {
            $level = 'amber';
            $label = get_string('sample:fair', 'local_sentinel');
        } else {
            $level = 'green';
            $label = get_string('sample:solid', 'local_sentinel');
        }

        return ['pct' => $pct, 'evaluated' => $total_eval, 'total' => $total_acts, 'level' => $level, 'label' => $label];
    }

    public static function classify_coverage(int $worked, int $total): array {
        if ($total === 0) {
            return ['pct' => 0, 'level' => 'grey',
                    'label' => get_string('sample:nodata', 'local_sentinel')];
        }
        $pct = round(($worked / $total) * 100, 1);
        $threshold_red   = (float)(get_config('local_sentinel', 'sample_threshold_red') ?: 50);
        $threshold_green = (float)(get_config('local_sentinel', 'sample_threshold_green') ?: 70);

        if ($pct < $threshold_red) {
            $level = 'red';
            $label = get_string('sample:insufficient', 'local_sentinel');
        } elseif ($pct < $threshold_green) {
            $level = 'amber';
            $label = get_string('sample:fair', 'local_sentinel');
        } else {
            $level = 'green';
            $label = get_string('sample:solid', 'local_sentinel');
        }
        return compact('pct', 'level', 'label');
    }

    public static function get_descriptor_group_totals(array $courseids): array {
        global $DB;
        if (empty($courseids)) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'cid');
        $sql = "
            SELECT cd.descriptorcode, COUNT(DISTINCT cm.id) AS total_acts
              FROM {assign} a
              JOIN {course_modules} cm ON cm.instance = a.id AND cm.course = a.course
                                       AND cm.deletioninprogress = 0
              JOIN {modules} m ON m.id = cm.module AND m.name = 'assign'
              JOIN {context} ctx ON ctx.contextlevel = 70 AND ctx.instanceid = cm.id
              JOIN {grading_areas} ga ON ga.contextid = ctx.id AND ga.component = 'mod_assign'
              JOIN {grading_definitions} gd ON gd.areaid = ga.id AND gd.method = 'rubric' AND gd.status = 20
              JOIN {gradingform_rubric_criteria} rc ON rc.definitionid = gd.id
              LEFT JOIN (
                  SELECT DISTINCT gi2.itemname, gi2.courseid, gi2.categoryid
                  FROM {grade_items} gi2
                  WHERE gi2.itemtype = 'manual'
              ) gi_match ON gi_match.itemname = rc.description AND gi_match.courseid = a.course
              LEFT JOIN {grade_items} gi_mod ON gi_mod.iteminstance = a.id
                                             AND gi_mod.itemtype = 'mod'
                                             AND gi_mod.itemmodule = 'assign'
                                             AND gi_mod.courseid = a.course
              JOIN {grade_categories} gc_crit ON gc_crit.id = COALESCE(gi_match.categoryid, gi_mod.categoryid)
              JOIN {local_sentinel_cat_descriptor} cd ON cd.grade_category_id = gc_crit.id
              JOIN {assign_grades} ag ON ag.assignment = a.id
              JOIN {grading_instances} gi ON gi.definitionid = gd.id AND gi.itemid = ag.id AND gi.status = 1
             WHERE a.course $insql
             GROUP BY cd.descriptorcode
        ";
        $rows = $DB->get_records_sql($sql, $params);
        $result = [];
        foreach ($rows as $r) {
            $result[$r->descriptorcode] = (int)$r->total_acts;
        }
        return $result;
    }

    public static function get_objectives_data(array $areas, string $stage): array {
        $file = __DIR__ . '/../data/objetivos_etapa.json';
        if (!file_exists($file)) {
            return [];
        }
        $all = json_decode(file_get_contents($file), true) ?: [];
        if (empty($all[$stage]['objectives'])) {
            return [];
        }

        $desc_grades = [];
        $desc_activities = [];
        foreach ($areas as $adata) {
            foreach ($adata['descriptors'] as $dcode => $ddata) {
                if ($ddata['grade'] !== null) {
                    $desc_grades[$dcode] = $ddata['grade'];
                }
                if (!empty($ddata['activities'])) {
                    $desc_activities[$dcode] = $ddata['activities'];
                }
            }
        }

        $objectives = [];
        foreach ($all[$stage]['objectives'] as $obj) {
            $linked = $obj['descriptors'];
            $worked_grades = [];
            foreach ($linked as $dc) {
                if (isset($desc_grades[$dc])) {
                    $worked_grades[] = $desc_grades[$dc];
                }
            }
            $worked = count($worked_grades);
            $total  = count($linked);
            $grade  = $worked > 0 ? round(array_sum($worked_grades) / $worked, 2) : null;
            $cov    = self::classify_coverage($worked, $total);

            $sparkline = self::build_objective_sparkline($linked, $desc_activities);

            $objectives[] = [
                'code'        => $obj['code'],
                'text'        => $obj['text'],
                'grade'       => $grade,
                'worked'      => $worked,
                'total'       => $total,
                'descriptors' => $linked,
                'coverage'    => $cov,
                'sparkline'   => $sparkline,
            ];
        }

        return [
            'label'      => $all[$stage]['label'],
            'source'     => $all[$stage]['source'],
            'objectives' => $objectives,
        ];
    }

    /**
     * Builds a sparkline time-series for an objective: running average across
     * linked descriptors as grading events arrive chronologically.
     *
     * @param array $linked    Descriptor codes linked to this objective.
     * @param array $desc_activities  Map of descriptor code → occasions array.
     * @return array  List of [timestamp, grade] points ordered by time.
     */
    /**
     * Returns self-assessment (DUA) grades aggregated per competency area.
     *
     * @param int      $userid
     * @param int|null $courseid   Filter to one course (null = all).
     * @return array  Keyed by area code => float grade (0-10), or empty if no selfeval data.
     */
    public static function get_student_selfeval_areas(int $userid, ?int $courseid = null): array {
        global $DB;

        $params = ['userid' => $userid];
        $coursesql = '';
        if ($courseid) {
            $coursesql = ' AND cm.course = :courseid';
            $params['courseid'] = $courseid;
        }

        $sql = "
            SELECT se.id, se.criterionid, se.score, se.cmid,
                   cd.descriptorcode
              FROM {local_sentinel_selfeval} se
              JOIN {local_sentinel_ce_descriptor} cd ON cd.criteriaid = se.criterionid
              JOIN {course_modules} cm ON cm.id = se.cmid
             WHERE se.userid = :userid
                   {$coursesql}
        ";

        $rows = $DB->get_records_sql($sql, $params);
        if (empty($rows)) {
            return [];
        }

        $area_scores = [];
        foreach ($rows as $row) {
            $dc = $row->descriptorcode;
            $area = '';
            foreach (self::AREAS as $ac => $ad) {
                if (strpos($dc, $ac) === 0) {
                    $area = $ac;
                    break;
                }
            }
            if (!$area) {
                continue;
            }
            if (!isset($area_scores[$area])) {
                $area_scores[$area] = [];
            }
            $area_scores[$area][] = (float)$row->score;
        }

        $result = [];
        foreach ($area_scores as $area => $scores) {
            $result[$area] = round(array_sum($scores) / count($scores), 2);
        }

        return $result;
    }

    /**
     * Returns self-assessment sparkline data per objective.
     *
     * @param int   $userid
     * @param array $objectives  The objectives array from get_objectives_data().
     * @return array Keyed by objective code => list of [timestamp, avg_grade] points.
     */
    public static function get_selfeval_sparklines(int $userid, array $objectives): array {
        global $DB;

        $rows = $DB->get_records_sql("
            SELECT se.id, se.criterionid, se.score, se.timecreated,
                   cd.descriptorcode
              FROM {local_sentinel_selfeval} se
              JOIN {local_sentinel_ce_descriptor} cd ON cd.criteriaid = se.criterionid
             WHERE se.userid = :userid
          ORDER BY se.timecreated ASC
        ", ['userid' => $userid]);

        if (empty($rows)) {
            return [];
        }

        $desc_events = [];
        foreach ($rows as $row) {
            $dc = $row->descriptorcode;
            $desc_events[$dc][] = [
                'time'  => (int)$row->timecreated,
                'score' => (float)$row->score,
            ];
        }

        $result = [];
        foreach ($objectives as $obj) {
            $linked = $obj['descriptors'] ?? [];
            $events = [];
            foreach ($linked as $dc) {
                if (empty($desc_events[$dc])) {
                    continue;
                }
                foreach ($desc_events[$dc] as $ev) {
                    $events[] = [
                        'time'  => $ev['time'],
                        'desc'  => $dc,
                        'score' => $ev['score'],
                    ];
                }
            }
            if (empty($events)) {
                continue;
            }
            usort($events, fn($a, $b) => $a['time'] <=> $b['time']);
            $desc_latest = [];
            $points = [];
            foreach ($events as $ev) {
                $desc_latest[$ev['desc']] = $ev['score'];
                $avg = round(array_sum($desc_latest) / count($desc_latest), 2);
                $points[] = [$ev['time'], $avg];
            }
            $result[$obj['code']] = $points;
        }

        return $result;
    }

    /**
     * Returns self-assessment grades per CE category for a specific course.
     *
     * @param int $userid
     * @param int $courseid
     * @param array $ce_cat_ids  Ordered list of CE category IDs (from get_student_ce_data).
     * @return array  Indexed same as $ce_cat_ids => float grade or null.
     */
    public static function get_selfeval_per_ce(int $userid, int $courseid, array $ce_cat_ids): array {
        global $DB;

        if (empty($ce_cat_ids)) {
            return array_fill(0, count($ce_cat_ids), null);
        }

        $sql = "
            SELECT se.id, se.criterionid, se.score, se.cmid,
                   sm.grade_category_id
              FROM {local_sentinel_selfeval} se
              JOIN {local_sentinel_map} sm ON sm.rubric_row_id = se.criterionid
              JOIN {course_modules} cm ON cm.id = se.cmid AND cm.course = :courseid
             WHERE se.userid = :userid
        ";
        $rows = $DB->get_records_sql($sql, ['userid' => $userid, 'courseid' => $courseid]);

        if (empty($rows)) {
            return array_fill(0, count($ce_cat_ids), null);
        }

        // Group scores by parent CE category. The map links criterion → depth-3 category;
        // depth-3's parent is the CE (depth-2) category.
        $cat_parents = $DB->get_records_sql(
            "SELECT gc.id, gc.parent FROM {grade_categories} gc WHERE gc.courseid = ?",
            [$courseid]
        );
        $parent_map = [];
        foreach ($cat_parents as $cp) {
            $parent_map[(int)$cp->id] = (int)$cp->parent;
        }

        $ce_scores = [];
        foreach ($rows as $row) {
            $cat_id = (int)$row->grade_category_id;
            $ce_id = $parent_map[$cat_id] ?? $cat_id;
            $ce_scores[$ce_id][] = (float)$row->score;
        }

        $result = [];
        foreach ($ce_cat_ids as $cid) {
            if (!empty($ce_scores[$cid])) {
                $result[] = round(array_sum($ce_scores[$cid]) / count($ce_scores[$cid]), 2);
            } else {
                $result[] = null;
            }
        }

        return $result;
    }

    private static function build_objective_sparkline(array $linked, array $desc_activities): array {
        $events = [];
        foreach ($linked as $dc) {
            if (empty($desc_activities[$dc])) {
                continue;
            }
            foreach ($desc_activities[$dc] as $occ) {
                $events[] = [
                    'time'  => (int)$occ['gradetime'],
                    'desc'  => $dc,
                    'grade' => (float)$occ['grade'],
                ];
            }
        }
        if (empty($events)) {
            return [];
        }

        usort($events, fn($a, $b) => $a['time'] <=> $b['time']);

        $desc_latest = [];
        $points = [];
        foreach ($events as $ev) {
            $desc_latest[$ev['desc']] = $ev['grade'];
            $avg = round(array_sum($desc_latest) / count($desc_latest), 2);
            $points[] = [$ev['time'], $avg];
        }

        return $points;
    }
}

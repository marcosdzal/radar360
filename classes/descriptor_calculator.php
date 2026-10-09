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
        foreach ($areas as $a) {
            $total_worked += $a['worked'];
            $total_occ    += $a['occasions'];
        }

        $summary = [
            'total_descriptors' => 34,
            'worked_descriptors'=> $total_worked,
            'total_occasions'   => $total_occ,
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
}

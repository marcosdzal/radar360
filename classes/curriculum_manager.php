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

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/gradelib.php');
require_once($CFG->dirroot . '/grade/lib.php');

class curriculum_manager {

    private static function data_path(): string {
        global $CFG;
        return $CFG->dirroot . '/local/sentinel/data/curriculo';
    }

    public static function get_catalog(): array {
        $file = self::data_path() . '/catalog.json';
        if (!file_exists($file)) {
            return [];
        }
        $catalog = json_decode(file_get_contents($file), true) ?: [];
        $basepath = self::data_path();

        // Filter: only show subjects/grades that have actual data files.
        if (!isset($catalog['communities'])) {
            return $catalog;
        }
        foreach ($catalog['communities'] as &$community) {
            $cid = $community['id'];
            if (!isset($community['stages'])) {
                continue;
            }
            foreach ($community['stages'] as &$stage) {
                $sid = $stage['id'];
                if (!isset($stage['subjects'])) {
                    continue;
                }
                foreach ($stage['subjects'] as &$subject) {
                    $subid = $subject['id'];
                    $subject['grades'] = array_values(array_filter(
                        $subject['grades'],
                        function (int $g) use ($basepath, $cid, $sid, $subid): bool {
                            return file_exists("{$basepath}/{$cid}/{$sid}/{$subid}_{$g}.json");
                        }
                    ));
                }
                unset($subject);
                $stage['subjects'] = array_values(array_filter(
                    $stage['subjects'],
                    function (array $sub): bool { return !empty($sub['grades']); }
                ));
            }
            unset($stage);
        }
        unset($community);

        return $catalog;
    }

    public static function get_curriculum(string $community, string $stage, string $subject, int $grade): ?array {
        $file = self::data_path() . "/{$community}/{$stage}/{$subject}_{$grade}.json";
        if (!file_exists($file)) {
            return null;
        }
        return json_decode(file_get_contents($file), true) ?: null;
    }

    public static function get_applied(int $courseid): ?array {
        $json = get_config('local_sentinel', "curriculum_{$courseid}");
        if (!$json) {
            return null;
        }
        return json_decode($json, true) ?: null;
    }

    public static function course_has_grades(int $courseid): bool {
        global $DB;
        return $DB->record_exists_sql(
            'SELECT 1 FROM {grade_grades} gg
               JOIN {grade_items} gi ON gi.id = gg.itemid
              WHERE gi.courseid = :cid AND gg.finalgrade IS NOT NULL',
            ['cid' => $courseid]
        );
    }

    public static function remove_applied(int $courseid): void {
        global $DB;

        $root = \grade_category::fetch_course_category($courseid);
        if (!$root) {
            return;
        }

        $ce_cats = $DB->get_records('grade_categories', [
            'courseid' => $courseid,
            'parent'   => $root->id,
        ]);
        foreach ($ce_cats as $cat) {
            if (preg_match('/^CE\d+\.\s/', $cat->fullname)) {
                $sub_cats = $DB->get_records('grade_categories', [
                    'courseid' => $courseid,
                    'parent'   => $cat->id,
                ]);
                $sub_ids = array_keys($sub_cats);
                if ($sub_ids) {
                    $DB->delete_records_list('local_sentinel_cat_descriptor', 'grade_category_id', $sub_ids);
                }
                foreach ($sub_cats as $sub) {
                    $gc = \grade_category::fetch(['id' => $sub->id, 'courseid' => $courseid]);
                    if ($gc) {
                        $gc->delete('full');
                    }
                }
                $gc = \grade_category::fetch(['id' => $cat->id, 'courseid' => $courseid]);
                if ($gc) {
                    $gc->delete('full');
                }
            }
        }

        unset_config("curriculum_{$courseid}", 'local_sentinel');
    }

    public static function apply(int $courseid, string $community, string $stage, string $subject, int $grade): array {
        global $DB;

        if (self::course_has_grades($courseid)) {
            return ['success' => false, 'error' => 'El curso ya tiene calificaciones. No se puede cambiar la estructura curricular.'];
        }

        $curriculum = self::get_curriculum($community, $stage, $subject, $grade);
        if (!$curriculum) {
            return ['success' => false, 'error' => 'No se encontró el currículo solicitado.'];
        }

        $course = $DB->get_record('course', ['id' => $courseid]);
        if (!$course) {
            return ['success' => false, 'error' => 'Curso no encontrado.'];
        }

        // Remove previously applied curriculum categories (if any).
        self::remove_applied($courseid);

        // Get or create the course root category.
        $root = \grade_category::fetch_course_category($courseid);

        $ce_count = 0;
        $crit_count = 0;

        foreach ($curriculum['competencias'] as $sortorder => $ce) {
            $ce_label = $ce['code'] . '. ' . ($ce['description'] ?? $ce['name']);
            if (!empty($ce['descriptors'])) {
                $ce_label .= ' [' . implode(', ', $ce['descriptors']) . ']';
            }
            $ce_cat = new \grade_category();
            $ce_cat->courseid = $courseid;
            $ce_cat->fullname = $ce_label;
            $ce_cat->parent = $root->id;
            $ce_cat->sortorder = ($sortorder + 1) * 100;
            $ce_cat->aggregation = GRADE_AGGREGATE_WEIGHTED_MEAN2;
            $ce_cat->insert();
            $ce_count++;

            foreach ($ce['criterios'] as $csort => $criterio) {
                $crit_cat = new \grade_category();
                $crit_cat->courseid = $courseid;
                $crit_cat->fullname = $criterio['code'] . '. ' . $criterio['description'];
                $crit_cat->parent = $ce_cat->id;
                $crit_cat->sortorder = ($csort + 1) * 10;
                $crit_cat->aggregation = GRADE_AGGREGATE_WEIGHTED_MEAN2;
                $crit_cat->insert();
                $crit_count++;

                if (!empty($ce['descriptors'])) {
                    foreach (array_unique($ce['descriptors']) as $dcode) {
                        $DB->insert_record('local_sentinel_cat_descriptor', (object)[
                            'grade_category_id' => $crit_cat->id,
                            'descriptorcode'    => $dcode,
                            'source'            => 'normativa',
                        ]);
                    }
                }
            }
        }

        // Create "Actividades (Sentinel)" container (weight 0) with "Nota de Rúbrica" inside.
        $act_name = get_string('activities_category', 'local_sentinel');
        $existing_act = $DB->get_record_select('grade_categories',
            "courseid = :cid AND parent = :pid AND fullname = :name",
            ['cid' => $courseid, 'pid' => $root->id, 'name' => $act_name]
        );
        if (!$existing_act) {
            $act_cat = new \grade_category();
            $act_cat->courseid = $courseid;
            $act_cat->fullname = $act_name;
            $act_cat->parent = $root->id;
            $act_cat->sortorder = 9999;
            $act_cat->aggregation = GRADE_AGGREGATE_SUM;
            $act_cat->insert();

            $act_gi = $act_cat->get_grade_item();
            if ($act_gi) {
                $act_gi->aggregationcoef = 0;
                $act_gi->aggregationcoef2 = 0;
                $act_gi->update();
            }

            $rub_name = get_string('rubric_category', 'local_sentinel');
            $rub_cat = new \grade_category();
            $rub_cat->courseid = $courseid;
            $rub_cat->fullname = $rub_name;
            $rub_cat->parent = $act_cat->id;
            $rub_cat->sortorder = 1;
            $rub_cat->aggregation = GRADE_AGGREGATE_SUM;
            $rub_cat->insert();
        }

        // Store which curriculum was applied.
        set_config("curriculum_{$courseid}", json_encode([
            'community'    => $community,
            'stage'        => $stage,
            'subject'      => $subject,
            'grade'        => $grade,
            'decree'       => $curriculum['decree'] ?? '',
            'subject_name' => $curriculum['subject_name'] ?? $subject,
            'categories'   => $ce_count,
            'criterios'    => $crit_count,
            'timeapplied'  => time(),
        ]), 'local_sentinel');

        return [
            'success'    => true,
            'categories' => $ce_count,
            'criterios'  => $crit_count,
        ];
    }

    /**
     * Inject desgrane contents into Moodle's competency framework.
     *
     * Creates a competency framework for the subject, with blocks as top-level
     * competencies, items as children, and sub-contenidos as leaf competencies.
     * Links leaf competencies to the course.
     */
    public static function inject_contenidos(int $courseid, string $subject, array $blocks): array {
        global $DB;

        if (!\core_competency\api::is_enabled()) {
            return ['success' => false, 'error' => 'El módulo de competencias no está habilitado en este sitio.'];
        }

        $applied = self::get_applied($courseid);
        if (!$applied) {
            return ['success' => false, 'error' => 'No hay currículo aplicado a este curso.'];
        }

        $subject_name = $applied['subject_name'] ?? $subject;
        $grade = (int)($applied['grade'] ?? 1);
        $stage = strtoupper($applied['stage'] ?? 'ESO');
        $decree = $applied['decree'] ?? '';

        $context = \context_system::instance();
        $fw_idnumber = 'sentinel_contenidos_' . $subject . '_' . $grade;

        // Find or create the scale "No trabajado, En proceso, Trabajado".
        $scale_name = 'Sentinel – Contenidos';
        $scale = $DB->get_record('scale', ['name' => $scale_name]);
        if (!$scale) {
            $scale = new \stdClass();
            $scale->name = $scale_name;
            $scale->scale = 'No trabajado,En proceso,Trabajado';
            $scale->description = 'Escala para el seguimiento de contenidos del currículo (Sentinel).';
            $scale->descriptionformat = FORMAT_HTML;
            $scale->courseid = 0;
            $scale->userid = 0;
            $scale->timemodified = time();
            $scale->id = $DB->insert_record('scale', $scale);
        }
        // Scale config: [{"scaleid":N}, {"id":1,"scaledefault":1,"proficient":0}, {"id":2,...}, {"id":3,"proficient":1}]
        $scaleconfig = json_encode([
            ['scaleid' => (int)$scale->id],
            ['id' => 1, 'scaledefault' => 1, 'proficient' => 0],
            ['id' => 2, 'scaledefault' => 0, 'proficient' => 0],
            ['id' => 3, 'scaledefault' => 0, 'proficient' => 1],
        ]);

        // Delete existing framework if re-injecting.
        $existing = $DB->get_record('competency_framework', ['idnumber' => $fw_idnumber]);
        if ($existing) {
            \core_competency\api::delete_framework($existing->id);
        }

        // Create framework.
        $fw_data = new \stdClass();
        $fw_data->shortname = $stage . $grade . ' ' . $subject_name . ' – Contenidos';
        $fw_data->idnumber = $fw_idnumber;
        $fw_data->description = 'Contenidos de ' . $subject_name . ' (' . $stage . $grade . '). ' . $decree;
        $fw_data->descriptionformat = FORMAT_HTML;
        $fw_data->contextid = $context->id;
        $fw_data->scaleid = (int)$scale->id;
        $fw_data->scaleconfiguration = $scaleconfig;
        $fw_data->visible = 1;
        $fw_data->taxonomies = 'competency,competency,competency';
        $framework = \core_competency\api::create_framework($fw_data);
        $fwid = $framework->get('id');

        $total_competencies = 0;
        $leaf_ids = [];

        foreach ($blocks as $bi => $block) {
            $block_id = $block['id'] ?? chr(65 + $bi);
            $block_name = $block['name'] ?? 'Bloque ' . $block_id;

            // Create block-level competency.
            $bc = new \stdClass();
            $bc->shortname = $block_id . '. ' . $block_name;
            $bc->idnumber = $fw_idnumber . '_' . $block_id;
            $bc->competencyframeworkid = $fwid;
            $bc->parentid = 0;
            $block_comp = \core_competency\api::create_competency($bc);
            $total_competencies++;

            foreach ($block['items'] ?? [] as $ii => $item) {
                $item_code = $item['c'] ?? $block_id . ($ii + 1);
                $item_legal = $item['legal'] ?? '';

                // Create item-level competency.
                $ic = new \stdClass();
                $ic->shortname = $item_code . '. ' . (mb_strlen($item_legal) > 100 ? mb_substr($item_legal, 0, 100) . '…' : $item_legal);
                $ic->idnumber = $fw_idnumber . '_' . $item_code;
                $ic->description = $item_legal;
                $ic->descriptionformat = FORMAT_HTML;
                $ic->competencyframeworkid = $fwid;
                $ic->parentid = $block_comp->get('id');
                $item_comp = \core_competency\api::create_competency($ic);
                $total_competencies++;

                foreach ($item['subs'] ?? [] as $si => $sub) {
                    $sub_code = $sub['c'] ?? $item_code . '.' . ($si + 1);
                    $sub_desc = $sub['d'] ?? '';

                    $sc = new \stdClass();
                    $sc->shortname = $sub_code . '. ' . $sub_desc;
                    $sc->idnumber = $fw_idnumber . '_' . $sub_code;
                    $sc->competencyframeworkid = $fwid;
                    $sc->parentid = $item_comp->get('id');
                    $sub_comp = \core_competency\api::create_competency($sc);
                    $leaf_ids[] = $sub_comp->get('id');
                    $total_competencies++;
                }
            }
        }

        // Link leaf competencies to the course.
        $linked = 0;
        foreach ($leaf_ids as $cid) {
            try {
                \core_competency\api::add_competency_to_course($courseid, $cid);
                $linked++;
            } catch (\Exception $e) {
                // Skip if already linked.
            }
        }

        // Store injection info.
        set_config("contenidos_fw_{$courseid}", json_encode([
            'framework_id' => $fwid,
            'subject' => $subject,
            'total' => $total_competencies,
            'linked' => $linked,
            'timeinjected' => time(),
        ]), 'local_sentinel');

        return [
            'success' => true,
            'framework_id' => $fwid,
            'total' => $total_competencies,
            'linked' => $linked,
        ];
    }
}

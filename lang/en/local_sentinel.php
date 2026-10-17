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

defined('MOODLE_INTERNAL') || die();

$string['pluginname']           = 'Radar 360';
$string['gradecategory']        = 'Grade category';
$string['rubric_row_id']        = 'Rubric row';
// Capabilities.
$string['sentinel:managephotos'] = 'Manage student photos (Radar 360)';

// Grade book categories created by the plugin.
$string['activities_category'] = 'Activities (Radar 360)';
$string['rubric_category']     = 'Rubric Grade';

// Rubric editor.
$string['rubriceditor:label']     = '📌 Assessment criterion (Radar 360):';
$string['rubriceditor:tooltip']   = 'Assign this rubric row to an assessment criterion in the gradebook';
$string['rubriceditor:unassigned']= '— Unassigned —';
$string['rubriceditor:pending']   = '💾 Will be saved on publish';
$string['rubriceditor:neterror']  = '❌ Network error';
$string['rubriceditor:header']    = 'SC (Radar 360)';

// Student photo widget.
$string['photo:delete']      = 'Delete photo';
$string['photo:add']         = 'Add photo';
$string['photo:gradetooltip']= 'Final course grade';
$string['photo:toggle']      = 'Show / hide photos';

// Grader report.
$string['grader:loading']    = 'Loading…';
$string['grader:nodata']     = 'No rubric data';
$string['grader:abbrevfull'] = '↔️ Full names';
$string['grader:abbrevshort']= '✂️ Abbreviate';

// Admin settings.
$string['settings:heading_photos']            = 'Student photos';
$string['settings:photoenabled']              = 'Enable student photos';
$string['settings:photoenabled_desc']         = 'Allows teachers to upload identification photos for each student and display them on grading, participants, and profile pages.';
$string['settings:heading_rubric']            = 'Rubric';
$string['settings:rubricenabled']             = 'Enable competency column in rubric';
$string['settings:rubricenabled_desc']        = 'Shows an extra column in the rubric with the specific competency linked to each criterion.';
$string['settings:rubriccolorsenabled']       = 'Enable rubric cell colours';
$string['settings:rubriccolorsenabled_desc']  = 'Colours rubric cells from red (lowest score) to green (highest score) according to the selected level.';
$string['settings:heading_grader']            = 'Grader report';
$string['settings:graderclickenabled']        = 'Enable direct click-to-grade';
$string['settings:graderclickenabled_desc']   = 'In the grader report, clicking a grade cell for an assignment with a rubric navigates directly to that student\'s grading page.';
$string['settings:graderabbrevtoggle']        = 'Enable column abbreviation button';
$string['settings:graderabbrevtoggle_desc']   = 'Shows a floating button in the grader report to toggle between full and abbreviated column header names.';
$string['settings:heading_ui']                = 'Interface';
$string['settings:drawerpersist']             = 'Keep nav drawer state';
$string['settings:drawerpersist_desc']        = 'Remembers whether the left navigation drawer was open or closed and restores it when navigating between pages.';

// New capabilities.
$string['sentinel:viewstudentreport'] = 'View student competency profile report (Radar 360)';
$string['sentinel:managedescriptors'] = 'Manage rubric criterion → descriptor mapping (Radar 360)';
$string['sentinel:communicate']       = 'Create and manage family communication periods (Radar 360)';

// Competency profile report.
$string['report:title']              = 'Radar 360º';
$string['report:profilelink']        = 'Competency report';
$string['report:student']            = 'Alumno';
$string['report:period']             = 'Periodo';
$string['report:allperiods']         = 'Todo el año';
$string['report:area_grade']         = 'Nota CC';
$string['report:descriptors_worked'] = '{$a->worked}/{$a->total} descriptores trabajados';
$string['report:occasions']          = '{$a} ocasiones';
$string['report:courses']            = 'Materias';
$string['report:descriptor']         = 'Descriptor';
$string['report:grade']              = 'Nota';
$string['report:not_worked']         = 'Pendiente de trabajar';
$string['report:back']               = '← Volver';
$string['report:activity']           = 'Actividad';
$string['report:course']             = 'Materia';
$string['report:criterion']          = 'Criterio evaluado';
$string['report:level']              = 'Nivel alcanzado';
$string['report:date']               = 'Fecha';
$string['report:viewrubric']         = 'Ver rúbrica';
$string['report:nodata']             = 'Este alumno aún no tiene ninguna actividad evaluada con rúbrica vinculada a descriptores del perfil de salida.';
$string['report:level_iniciacion']   = 'Iniciación';
$string['report:level_enproceso']    = 'En proceso';
$string['report:level_adquirida']    = 'Adquirida';
$string['report:level_avanzada']     = 'Avanzada';
$string['report:exportcsv']          = 'Exportar CSV';
$string['report:noaccess']           = 'No tienes permiso para ver este informe.';
$string['report:global_avg']         = 'Media global';
$string['report:descriptors_label']  = 'Descriptores';
$string['report:occasions_label']    = 'Ocasiones';
$string['report:subjects_detail']    = 'Ver desglose por materias';
$string['report:section_areas']      = 'Nota por competencia clave';
$string['report:pending']            = 'Pendiente de trabajar';
$string['report:finalgrade']         = 'Calificación final';
$string['report:annual']             = 'Anual';
$string['report:evolution']          = 'Evolución';
$string['report:comm_nth']           = '{$a}.ª comunicación';
$string['report:level_legend_title'] = 'Niveles de desempeño';

// View tabs.
$string['report:tab_group_key']     = 'Key competencies';
$string['report:tab_group_specific'] = 'Specific competencies';
$string['report:tab_diana']         = 'Radar';
$string['report:tab_grid']          = 'Cards';
$string['report:tab_subject_diana'] = 'Radar';
$string['report:tab_subject_grid']  = 'Cards';
$string['report:subject_grade']   = 'Subject grade';
$string['report:select_course']   = 'Select subject';
$string['report:ce_criteria']     = 'criteria';
$string['report:ce_criterion']    = 'criterion';
$string['report:no_ce_data']      = 'No specific competencies configured in this subject.';
$string['report:view_detail']     = 'View detail';

// Admin: CE → descriptor mapping.
$string['mapdesc:title']             = 'Criteria mapping';
$string['mapdesc:navlink']           = 'Criteria mapping';
$string['mapdesc:intro']             = 'The links between assessment criteria and operational descriptors from the exit profile are pre-configured according to the current curriculum regulations. You may adjust these assignments if needed to match your subject\'s teaching plan.';
$string['mapdesc:save']              = 'Guardar cambios';
$string['mapdesc:saved']             = 'Asignaciones guardadas correctamente.';
$string['mapdesc:nocriteria']        = 'No se encontraron criterios de evaluación en el libro de calificaciones.';
$string['mapdesc:course']            = 'Materia';
$string['mapdesc:activity']          = 'Actividad';
$string['mapdesc:criterion']         = 'Criterio de evaluación';
$string['mapdesc:descriptors']       = 'Descriptores vinculados';
$string['mapdesc:legend_normativa']  = 'Linked by curriculum decree';
$string['mapdesc:legend_manual']     = 'Linked manually';

// Admin: communication periods.
$string['periods:title']             = 'Comunicaciones a familias';
$string['periods:intro']             = 'Define las fechas de comunicación de calificaciones a las familias. El informe de perfil de salida permite filtrar por estos periodos.';
$string['periods:saved']             = 'Comunicación añadida correctamente.';
$string['periods:deleted']           = 'Comunicación eliminada.';
$string['periods:navlink']           = 'Comunicaciones';

// Curriculum.
$string['settings:heading_curriculum']    = 'Curriculum (LOMLOE)';
$string['settings:community']             = 'Autonomous Community';
$string['settings:community_desc']        = 'Autonomous community of the school. All courses will use this community\'s regulations.';
$string['settings:community_none']        = '— Not configured —';

// Settings (new entries).
$string['settings:heading_descriptors']   = 'Profile exit (LOMLOE)';
$string['settings:mapdescriptors']        = 'Assign descriptors to criteria';
$string['settings:managedescriptors_link']= 'Go to the assignment page';

// Privacy API.
$string['privacy:metadata:local_sentinel_student_photo']              = 'Radar 360 stores custom student photographs uploaded by teachers.';
$string['privacy:metadata:local_sentinel_student_photo:studentid']    = 'The ID of the student the photograph belongs to.';
$string['privacy:metadata:local_sentinel_student_photo:uploaderid']   = 'The ID of the teacher who uploaded the photograph.';
$string['privacy:metadata:local_sentinel_student_photo:timemodified'] = 'The date and time the photograph was last modified.';
$string['privacy:metadata:core_files']                                = 'Radar 360 stores the image file in the Moodle file system.';
$string['privacy:studentphoto']                                       = 'Student photograph';
$string['privacy:uploadedphotos']                                     = 'Photographs uploaded as teacher';

// Curriculum configuration.
$string['curriculum:title']   = 'Curricular framework';
$string['curriculum:navlink'] = 'Curricular framework';

// Desgrane de contenidos.
$string['desgrane:title']    = 'Core knowledge';
$string['desgrane:navlink']  = 'Core knowledge';

// Sample representativity.
$string['sample:insufficient']      = 'Muestra insuficiente';
$string['sample:fair']              = 'Representatividad justa';
$string['sample:solid']             = 'Muestra sólida';
$string['sample:tooltip_red']       = 'El alumno solo ha sido evaluado en el {$a}% de las actividades del grupo. Muestra insuficiente para calificación objetiva.';
$string['sample:tooltip_amber']     = 'Evaluado en el {$a}% de las actividades del grupo. Representatividad justa — conviene completar evidencias.';
$string['sample:tooltip_green']     = 'Evaluado en el {$a}% de las actividades del grupo. Muestra sólida.';
$string['sample:config_title']      = 'Muestra suficiente';
$string['sample:config_navlink']    = 'Muestra suficiente';
$string['sample:config_intro']      = 'Configura los umbrales del semáforo de representatividad de la muestra evaluada.';
$string['sample:threshold_red']     = 'Umbral alerta roja (%)';
$string['sample:threshold_red_desc']= 'Por debajo de este valor la muestra se considera insuficiente.';
$string['sample:threshold_green']     = 'Umbral zona verde (%)';
$string['sample:threshold_green_desc']= 'A partir de este valor la muestra se considera sólida. Entre ambos umbrales se muestra alerta ámbar.';
$string['sample:badge']             = '{$a}% muestra';
$string['sample:nodata']            = 'Sin evidencias';
$string['sample:rec_grey']          = 'There are no assessed activities linked to evaluation criteria.';
$string['sample:rec_red']           = 'A recovery mechanism should be applied or sample representativity should be weighed at the assessment board.';
$string['sample:rec_amber']         = 'It is advisable to complete further assessments before the final evaluation to ensure a representative grade.';
$string['sample:rec_green']         = 'The grade is representative of the student\'s competency level.';

// Stage objectives.
$string['objectives:tooltip']       = 'Automatically computed from the triangulation of the Exit Profile Operational Descriptors';
$string['objectives:nodata']        = 'Unable to determine the student\'s educational stage. Configure the curricular framework for at least one subject to see the objectives.';

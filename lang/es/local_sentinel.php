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

$string['pluginname']          = 'Radar 360';
$string['gradecategory']       = 'Categoría de calificación';
$string['rubric_row_id']       = 'Fila de rúbrica';
// Capacidades.
$string['sentinel:managephotos'] = 'Gestionar fotos de alumnos (Radar 360)';

// Categorías del libro de calificaciones creadas por el plugin.
$string['activities_category'] = 'Actividades (Radar 360)';
$string['rubric_category']     = 'Nota de Rúbrica';

// Editor de rúbrica.
$string['rubriceditor:label']     = '📌 Criterio de evaluación (Radar 360):';
$string['rubriceditor:tooltip']   = 'Asignar esta fila de rúbrica a un criterio de evaluación del libro de calificaciones';
$string['rubriceditor:unassigned']= '— Sin asignar —';
$string['rubriceditor:pending']   = '💾 Se guardará al publicar';
$string['rubriceditor:neterror']  = '❌ Error de red';
$string['rubriceditor:header']    = 'CE (Radar 360)';

// Widget de foto de alumno.
$string['photo:delete']      = 'Eliminar foto';
$string['photo:add']         = 'Añadir foto';
$string['photo:gradetooltip']= 'Calificación final del curso';
$string['photo:toggle']      = 'Mostrar / ocultar fotos';

// Informe del calificador.
$string['grader:loading']    = 'Cargando…';
$string['grader:nodata']     = 'Sin datos de rúbrica';
$string['grader:abbrevfull'] = '↔️ Nombres completos';
$string['grader:abbrevshort']= '✂️ Abreviar';

// Ajustes de administración.
$string['settings:heading_photos']            = 'Fotos de alumnos';
$string['settings:photoenabled']              = 'Activar fotos de alumnos';
$string['settings:photoenabled_desc']         = 'Permite al profesorado subir fotos identificativas para cada alumno y mostrarlas en las páginas de calificación, participantes y perfil.';
$string['settings:heading_rubric']            = 'Rúbrica';
$string['settings:rubricenabled']             = 'Activar columna de competencia en rúbrica';
$string['settings:rubricenabled_desc']        = 'Muestra en la rúbrica una columna adicional con la competencia específica asociada a cada criterio.';
$string['settings:rubriccolorsenabled']       = 'Activar colores en celdas de rúbrica';
$string['settings:rubriccolorsenabled_desc']  = 'Colorea las celdas de la rúbrica de rojo (menos puntos) a verde (más puntos) según el nivel seleccionado.';
$string['settings:heading_grader']            = 'Informe del calificador';
$string['settings:graderclickenabled']        = 'Activar clic directo al calificador';
$string['settings:graderclickenabled_desc']   = 'En el informe del calificador, al hacer clic en una celda de nota de una tarea con rúbrica navega directamente a la página de calificación de ese alumno.';
$string['settings:graderabbrevtoggle']        = 'Activar botón de abreviación de columnas';
$string['settings:graderabbrevtoggle_desc']   = 'Muestra un botón flotante en el informe del calificador para alternar entre nombres completos y abreviados en las cabeceras de columna.';
$string['settings:heading_ui']                = 'Interfaz';
$string['settings:drawerpersist']             = 'Mantener estado del menú lateral';
$string['settings:drawerpersist_desc']        = 'Recuerda si el menú de navegación lateral estaba abierto o cerrado y lo restaura al cambiar de página.';

// Capacidades nuevas.
$string['sentinel:viewstudentreport'] = 'Ver informe de perfil de salida de un alumno (Radar 360)';
$string['sentinel:managedescriptors'] = 'Gestionar asignación CE → descriptores (Radar 360)';
$string['sentinel:communicate']       = 'Crear y gestionar comunicaciones a familias (Radar 360)';

// Informe de perfil de salida.
$string['report:title']              = 'Perfil de salida';
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

// Pestañas de vista.
$string['report:tab_group_key']     = 'Competencias clave';
$string['report:tab_group_specific'] = 'Competencias específicas';
$string['report:tab_diana']         = 'Diana';
$string['report:tab_grid']          = 'Tarjetas';
$string['report:tab_subject_diana'] = 'Diana';
$string['report:tab_subject_grid']  = 'Tarjetas';
$string['report:subject_grade']   = 'Nota de la materia';
$string['report:select_course']   = 'Selecciona materia';
$string['report:ce_criteria']     = 'criterios';
$string['report:ce_criterion']    = 'criterio';
$string['report:no_ce_data']      = 'No hay competencias específicas configuradas en esta materia.';
$string['report:view_detail']     = 'Ver detalle';

// Administración: mapeo criterio de evaluación → descriptores.
$string['mapdesc:title']             = 'Vinculación de criterios de evaluación a descriptores operativos';
$string['mapdesc:navlink']           = 'Vinculación criterios ev. / descriptores';
$string['mapdesc:intro']             = 'Las vinculaciones entre criterios de evaluación y descriptores operativos del perfil de salida aparecen preconfiguradas de acuerdo con la normativa curricular vigente. Si lo considera necesario, puede ajustar estas asignaciones para adaptarlas a la programación didáctica de su materia.';
$string['mapdesc:save']              = 'Guardar cambios';
$string['mapdesc:saved']             = 'Asignaciones guardadas correctamente.';
$string['mapdesc:nocriteria']        = 'No se encontraron criterios de evaluación en el libro de calificaciones. Crea subcategorías de nivel 3 (criterio) dentro de las competencias específicas en el libro de calificaciones de cada materia.';
$string['mapdesc:course']            = 'Materia';
$string['mapdesc:activity']          = 'Actividad';
$string['mapdesc:criterion']         = 'Criterio de evaluación';
$string['mapdesc:descriptors']       = 'Descriptores vinculados';
$string['mapdesc:legend_normativa']  = 'Vinculado por normativa';
$string['mapdesc:legend_manual']     = 'Vinculado manualmente';

// Administración: periodos de comunicación.
$string['periods:title']             = 'Comunicaciones a familias';
$string['periods:intro']             = 'Define las fechas de comunicación de calificaciones a las familias. El informe de perfil de salida permite filtrar por estos periodos.';
$string['periods:saved']             = 'Comunicación añadida correctamente.';
$string['periods:deleted']           = 'Comunicación eliminada.';
$string['periods:navlink']           = 'Radar 360 – Comunicaciones';

// Currículo.
$string['settings:heading_curriculum']    = 'Currículo (LOMLOE)';
$string['settings:community']             = 'Comunidad Autónoma';
$string['settings:community_desc']        = 'Comunidad autónoma del centro educativo. Todos los cursos usarán la normativa de esta comunidad.';
$string['settings:community_none']        = '— Sin configurar —';

// Ajustes (nuevas entradas).
$string['settings:heading_descriptors']   = 'Perfil de salida (LOMLOE)';
$string['settings:mapdescriptors']        = 'Asignar descriptores a criterios';
$string['settings:managedescriptors_link']= 'Ir a la página de asignación';

// Privacy API.
$string['privacy:metadata:local_sentinel_student_photo']              = 'Radar 360 almacena fotografías personalizadas de alumnos subidas por el profesorado.';
$string['privacy:metadata:local_sentinel_student_photo:studentid']    = 'Identificador del alumno al que pertenece la fotografía.';
$string['privacy:metadata:local_sentinel_student_photo:uploaderid']   = 'Identificador del profesor que subió la fotografía.';
$string['privacy:metadata:local_sentinel_student_photo:timemodified'] = 'Fecha y hora de la última modificación de la fotografía.';
$string['privacy:metadata:core_files']                                = 'Radar 360 almacena el fichero de imagen en el sistema de ficheros de Moodle.';
$string['privacy:studentphoto']                                       = 'Fotografía del alumno';
$string['privacy:uploadedphotos']                                     = 'Fotografías subidas como profesor';

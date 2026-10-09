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
 * Sentinel upgrade steps.
 *
 * @package    local_sentinel
 * @copyright  2024 Marcos
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

function xmldb_local_sentinel_upgrade($oldversion) {
    global $DB;
    $dbman = $DB->get_manager();

    if ($oldversion < 2024010201) {
        // Add grade_item_id column to local_sentinel_map.
        $table = new xmldb_table('local_sentinel_map');
        $field = new xmldb_field('grade_item_id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2024010201, 'local', 'sentinel');
    }

    if ($oldversion < 2024010203) {
        // Create local_sentinel_student_photo table.
        $table = new xmldb_table('local_sentinel_student_photo');

        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $table->add_field('studentid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('uploaderid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2024010203, 'local', 'sentinel');
    }

    if ($oldversion < 2024010204) {
        // Ensure grade_item_id column exists in local_sentinel_map.
        $table = new xmldb_table('local_sentinel_map');
        $field = new xmldb_field('grade_item_id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2024010204, 'local', 'sentinel');
    }

    if ($oldversion < 2024010205) {
        // Add unique index on local_sentinel_map.rubric_row_id (enforces 1:1 mapping).
        $table = new xmldb_table('local_sentinel_map');

        $idx_unique = new xmldb_index('uq_rubric_row_id', XMLDB_INDEX_UNIQUE, ['rubric_row_id']);
        if (!$dbman->index_exists($table, $idx_unique)) {
            $dbman->add_index($table, $idx_unique);
        }

        // Add regular index on grade_item_id (used in JOINs for grader report).
        $idx_gi = new xmldb_index('ix_grade_item_id', XMLDB_INDEX_NOTUNIQUE, ['grade_item_id']);
        if (!$dbman->index_exists($table, $idx_gi)) {
            $dbman->add_index($table, $idx_gi);
        }

        // Add unique index on local_sentinel_student_photo.studentid (one photo per student).
        $photo_table = new xmldb_table('local_sentinel_student_photo');

        $idx_student = new xmldb_index('uq_studentid', XMLDB_INDEX_UNIQUE, ['studentid']);
        if (!$dbman->index_exists($photo_table, $idx_student)) {
            $dbman->add_index($photo_table, $idx_student);
        }

        // Add regular index on uploaderid (used by Privacy API deletion queries).
        $idx_uploader = new xmldb_index('ix_uploaderid', XMLDB_INDEX_NOTUNIQUE, ['uploaderid']);
        if (!$dbman->index_exists($photo_table, $idx_uploader)) {
            $dbman->add_index($photo_table, $idx_uploader);
        }

        upgrade_plugin_savepoint(true, 2024010205, 'local', 'sentinel');
    }

    if ($oldversion < 2024010206) {
        // Create local_sentinel_descriptor table.
        $table = new xmldb_table('local_sentinel_descriptor');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $table->add_field('code', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL);
        $table->add_field('area', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL);
        $table->add_field('sortorder', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('description', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL);
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('uq_code', XMLDB_KEY_UNIQUE, ['code']);
        $table->add_index('ix_area', XMLDB_INDEX_NOTUNIQUE, ['area']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Create local_sentinel_ce_descriptor table.
        $table = new xmldb_table('local_sentinel_ce_descriptor');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $table->add_field('criteriaid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('descriptorcode', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('uq_criteria_descriptor', XMLDB_KEY_UNIQUE, ['criteriaid', 'descriptorcode']);
        $table->add_index('ix_criteriaid', XMLDB_INDEX_NOTUNIQUE, ['criteriaid']);
        $table->add_index('ix_descriptorcode', XMLDB_INDEX_NOTUNIQUE, ['descriptorcode']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Create local_sentinel_period table.
        $table = new xmldb_table('local_sentinel_period');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('sortorder', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('label', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL);
        $table->add_field('cutoffdate', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_index('ix_courseid', XMLDB_INDEX_NOTUNIQUE, ['courseid']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Seed the 34 LOMLOE profile exit descriptors (RD 217/2022).
        sentinel_seed_descriptors($DB);

        upgrade_plugin_savepoint(true, 2024010206, 'local', 'sentinel');
    }

    if ($oldversion < 2024010207) {
        // Create local_sentinel_cat_descriptor table:
        // maps gradebook CE category → LOMLOE descriptor codes.
        $table = new xmldb_table('local_sentinel_cat_descriptor');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $table->add_field('grade_category_id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('descriptorcode', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL);
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('uq_cat_descriptor', XMLDB_KEY_UNIQUE, ['grade_category_id', 'descriptorcode']);
        $table->add_index('ix_grade_category_id', XMLDB_INDEX_NOTUNIQUE, ['grade_category_id']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2024010207, 'local', 'sentinel');
    }

    return true;
}

/**
 * Seeds the 34 LOMLOE profile exit descriptors. Safe to call multiple times.
 */
function sentinel_seed_descriptors($DB): void {
    $descriptors = [
        ['code' => 'CCL1',   'area' => 'CCL',   'sortorder' => 1,  'description' => 'Se expresa de forma oral, escrita, signada o multimodal con coherencia, corrección y adecuación a los diferentes contextos sociales y académicos, y participa en interacciones comunicativas con actitud cooperativa y respetuosa tanto para intercambiar información, crear conocimiento y argumentar sus opiniones como para establecer y cuidar sus relaciones interpersonales.'],
        ['code' => 'CCL2',   'area' => 'CCL',   'sortorder' => 2,  'description' => 'Comprende, interpreta y valora con actitud crítica textos orales, escritos, signados o multimodales de los distintos ámbitos, con especial atención a los medios de comunicación, con la finalidad de construir conocimiento, formarse su propia opinión y ampliar sus competencias comunicativas.'],
        ['code' => 'CCL3',   'area' => 'CCL',   'sortorder' => 3,  'description' => 'Localiza, selecciona y contrasta de manera progresivamente autónoma información procedente de diferentes fuentes, evaluando su fiabilidad y pertinencia en función de los objetivos de lectura y evitando los riesgos de manipulación y desinformación, y la integra y transforma en conocimiento para comunicarla adoptando un punto de vista creativo, crítico y personal a la par que respetuoso con la propiedad intelectual.'],
        ['code' => 'CCL4',   'area' => 'CCL',   'sortorder' => 4,  'description' => 'Lee con autonomía obras relevantes de la literatura propia y universal de todos los tiempos y de la literatura juvenil, con la finalidad de construir su propia identidad lectora, ampliar sus competencias comunicativas y cultivar su madurez socioemocional.'],
        ['code' => 'CCL5',   'area' => 'CCL',   'sortorder' => 5,  'description' => 'Pone sus prácticas comunicativas al servicio de la convivencia democrática, la resolución dialogada de los conflictos y la igualdad de derechos de todas las personas, detectando los usos discriminatorios, los abusos de poder a través del lenguaje y los elementos de la comunicación que pueden resultar falaces o manipuladores.'],
        ['code' => 'CP1',    'area' => 'CP',    'sortorder' => 6,  'description' => 'Usa una o más lenguas, además de la lengua o lenguas familiares, para responder a sus necesidades comunicativas con espontaneidad y autonomía, tanto en situaciones de aprendizaje como en el ámbito personal, social y profesional.'],
        ['code' => 'CP2',    'area' => 'CP',    'sortorder' => 7,  'description' => 'A partir de sus experiencias, desarrolla estrategias que le permitan ampliar y enriquecer de forma sistemática su repertorio lingüístico individual con el fin de comunicarse de manera eficaz.'],
        ['code' => 'CP3',    'area' => 'CP',    'sortorder' => 8,  'description' => 'Conoce y respeta la diversidad lingüística y cultural presente en la sociedad, integrándola en su desarrollo personal como factor de diálogo, para fomentar la cohesión social.'],
        ['code' => 'STEM1',  'area' => 'STEM',  'sortorder' => 9,  'description' => 'Selecciona y emplea métodos inductivos y deductivos propios del razonamiento matemático en situaciones propias de la modalidad elegida y emplea estrategias variadas para la resolución de problemas analizando críticamente las soluciones y reformulando el procedimiento, si fuera necesario.'],
        ['code' => 'STEM2',  'area' => 'STEM',  'sortorder' => 10, 'description' => 'Utiliza el pensamiento científico para entender y explicar los fenómenos que ocurren a su alrededor, confiando en el conocimiento como motor de desarrollo, planteándose hipótesis y contrastándolas o comprobándolas mediante la observación, la experimentación y la investigación, utilizando herramientas e instrumentos adecuados, apreciando la importancia de la precisión y la veracidad y mostrando una actitud crítica acerca del alcance y limitaciones de la ciencia.'],
        ['code' => 'STEM3',  'area' => 'STEM',  'sortorder' => 11, 'description' => 'Plantea y desarrolla proyectos diseñando, fabricando y evaluando diferentes prototipos o modelos para generar o utilizar productos que den solución a una necesidad o problema de forma creativa y eficiente, realizando ajustes sucesivos para alcanzar los objetivos marcados, adquiriendo experiencia y desarrollando una actitud resolutiva ante los retos propuestos.'],
        ['code' => 'STEM4',  'area' => 'STEM',  'sortorder' => 12, 'description' => 'Interpreta y transmite los elementos más relevantes de investigaciones de forma clara y precisa, en diferentes formatos (gráficas, tablas, diagramas, fórmulas, esquemas, símbolos...) y aprovechando la cultura digital con ética y responsabilidad.'],
        ['code' => 'STEM5',  'area' => 'STEM',  'sortorder' => 13, 'description' => 'Emprende acciones fundamentadas científicamente para promover la salud física, mental y social, y preservar el medio ambiente y los seres vivos; y aplica principios de ética y seguridad en la realización de proyectos para transformar su entorno próximo de forma sostenible, valorando su impacto global y practicando el consumo responsable.'],
        ['code' => 'CD1',    'area' => 'CD',    'sortorder' => 14, 'description' => 'Realiza búsquedas en internet atendiendo a criterios de validez, calidad, actualidad y fiabilidad, seleccionando los resultados de manera crítica y archivándolos, para recuperarlos, referenciarlos y reutilizarlos respetando la propiedad intelectual.'],
        ['code' => 'CD2',    'area' => 'CD',    'sortorder' => 15, 'description' => 'Gestiona y utiliza su entorno personal digital de aprendizaje para construir conocimiento y crear contenidos digitales, mediante estrategias de tratamiento de la información y el uso de diferentes herramientas digitales, seleccionando y configurando la más adecuada en función de la tarea y de sus necesidades de aprendizaje permanente.'],
        ['code' => 'CD3',    'area' => 'CD',    'sortorder' => 16, 'description' => 'Se comunica, participa, colabora e interactúa compartiendo contenidos, datos e información mediante herramientas o plataformas virtuales, y gestiona de manera responsable sus acciones, presencia y visibilidad en la red, para ejercer una ciudadanía digital activa, cívica y reflexiva.'],
        ['code' => 'CD4',    'area' => 'CD',    'sortorder' => 17, 'description' => 'Identifica los riesgos y adopta medidas preventivas al usar las tecnologías digitales para proteger los dispositivos, los datos personales, la salud y el medioambiente, y para tomar conciencia de la importancia de unas prácticas digitales responsables, seguras, saludables y sostenibles.'],
        ['code' => 'CD5',    'area' => 'CD',    'sortorder' => 18, 'description' => 'Desarrolla aplicaciones informáticas sencillas y soluciones tecnológicas creativas y sostenibles para resolver problemas concretos o responder a retos propuestos, mostrando interés y curiosidad por la evolución de las tecnologías digitales y por su desarrollo sostenible y uso ético.'],
        ['code' => 'CPSAA1', 'area' => 'CPSAA', 'sortorder' => 19, 'description' => 'Regula y expresa sus emociones, fortaleciendo el optimismo, la resiliencia, la autoeficacia y la búsqueda de propósito y motivación hacia el aprendizaje, para gestionar los retos y cambios y armonizarlos con sus propios objetivos.'],
        ['code' => 'CPSAA2', 'area' => 'CPSAA', 'sortorder' => 20, 'description' => 'Comprende los riesgos para la salud relacionados con factores sociales, consolida estilos de vida saludable a nivel físico y mental, reconoce conductas contrarias a la convivencia y aplica estrategias para abordarlas.'],
        ['code' => 'CPSAA3', 'area' => 'CPSAA', 'sortorder' => 21, 'description' => 'Comprende proactivamente las perspectivas y las experiencias de las demás personas y las incorpora a su aprendizaje, para participar en el trabajo en grupo, distribuyendo y aceptando tareas y responsabilidades de manera equitativa y empleando estrategias cooperativas.'],
        ['code' => 'CPSAA4', 'area' => 'CPSAA', 'sortorder' => 22, 'description' => 'Realiza autoevaluaciones sobre su proceso de aprendizaje, buscando fuentes fiables para validar, sustentar y contrastar la información y para obtener conclusiones relevantes.'],
        ['code' => 'CPSAA5', 'area' => 'CPSAA', 'sortorder' => 23, 'description' => 'Planifica a largo plazo evaluando los propósitos y los procesos de la construcción del conocimiento, relacionando los diferentes campos del mismo para desarrollar procesos autorregulados de aprendizaje que le permitan transmitir ese conocimiento, proponer ideas creativas y resolver problemas con autonomía.'],
        ['code' => 'CC1',    'area' => 'CC',    'sortorder' => 24, 'description' => 'Analiza y comprende ideas relativas a la dimensión social y ciudadana de su propia identidad, así como a los hechos culturales, históricos y normativos que la determinan, demostrando respeto por las normas, empatía, equidad y espíritu constructivo en la interacción con los demás en cualquier contexto.'],
        ['code' => 'CC2',    'area' => 'CC',    'sortorder' => 25, 'description' => 'Analiza y acepta con mente abierta y visión crítica los valores, creencias, culturas y formas de vida distintas a la propia en un marco de respeto a los derechos humanos y de los animales y de los valores democráticos, para fomentar la convivencia pacífica y el respeto a la diversidad.'],
        ['code' => 'CC3',    'area' => 'CC',    'sortorder' => 26, 'description' => 'Adopta un juicio propio y argumentado ante problemas éticos y filosóficos fundamentales y de actualidad, afrontando con autonomía e iniciativa dilemas morales complejos y contrastando los valores propios con los universales y los de los demás.'],
        ['code' => 'CC4',    'area' => 'CC',    'sortorder' => 27, 'description' => 'Comprende las relaciones sistémicas de interdependencia, ecodependencia e interconexión entre actuaciones locales y globales, y adopta, de forma consciente y motivada, un estilo de vida sostenible y ecosocialmente responsable.'],
        ['code' => 'CE1',    'area' => 'CE',    'sortorder' => 28, 'description' => 'Analiza necesidades y oportunidades y afronta retos con sentido crítico, haciendo balance del conocimiento y experiencias propias, recopilando y analizando información relevante e integrando conocimientos y destrezas de distintas áreas o disciplinas para generar ideas creativas y de valor, replantear preguntas y resolver problemas con autonomía y creatividad en distintos contextos.'],
        ['code' => 'CE2',    'area' => 'CE',    'sortorder' => 29, 'description' => 'Evalúa las fortalezas y debilidades propias, haciendo uso de estrategias de autoconocimiento y autoeficacia, y comprende los elementos fundamentales de la economía y las finanzas, aplicando conocimientos económicos y financieros a actividades y situaciones concretas.'],
        ['code' => 'CE3',    'area' => 'CE',    'sortorder' => 30, 'description' => 'Desarrolla un proyecto, identificando, planificando, gestionando y valorando los recursos y los pasos necesarios para llevarlo a cabo con criterios de calidad, y evaluando su impacto potencial, para definir una propuesta de valor diferenciada y ajustada a los destinatarios a quienes se dirige.'],
        ['code' => 'CCEC1',  'area' => 'CCEC',  'sortorder' => 31, 'description' => 'Reflexiona, promueve y valora críticamente el patrimonio cultural y artístico de cualquier época, contrastando sus singularidades y partiendo del reconocimiento de su riqueza y diversidad.'],
        ['code' => 'CCEC2',  'area' => 'CCEC',  'sortorder' => 32, 'description' => 'Disfruta, reconoce y analiza con autonomía las especificidades e intencionalidades de las manifestaciones artísticas y culturales más destacadas del patrimonio, distinguiendo los medios y soportes, así como los lenguajes y elementos técnicos que las caracterizan.'],
        ['code' => 'CCEC3',  'area' => 'CCEC',  'sortorder' => 33, 'description' => 'Expresa ideas, opiniones, sentimientos y emociones por medio de producciones culturales y artísticas, integrando su propio punto de vista creativo, ético y estético y empleando distintos soportes y técnicas.'],
        ['code' => 'CCEC4',  'area' => 'CCEC',  'sortorder' => 34, 'description' => 'Conoce, selecciona y utiliza con creatividad diversos medios y soportes, así como técnicas plásticas, visuales, audiovisuales, sonoras o corporales, para la creación de productos artísticos y culturales, tanto de forma individual como colaborativa, con una actitud abierta y respetuosa hacia las aportaciones ajenas y adoptando un enfoque propio.'],
    ];
    foreach ($descriptors as $d) {
        if (!$DB->record_exists('local_sentinel_descriptor', ['code' => $d['code']])) {
            $DB->insert_record('local_sentinel_descriptor', (object)$d);
        }
    }
}

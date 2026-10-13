<?php
// CLI script to extract LOMLOE curriculum data from corrigiendo.es
// Usage:  php cli/extract_curriculo.php [options]
//   --community=madrid      Only extract for one community
//   --subject=matematicas   Only extract one subject slug
//   --force                 Overwrite existing JSON files
//   --dry-run               Show what would be fetched without writing
//   --delay=1.0             Seconds between HTTP requests (default 1.0)

define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../config.php');

// ── Configuration ──────────────────────────────────────────────────
$BASE_URL  = 'https://corrigiendo.es/lomloe';
$DATA_DIR  = $CFG->dirroot . '/local/sentinel/data/curriculo';

$COMMUNITIES = [
    'andalucia'           => 'Andalucía',
    'aragon'              => 'Aragón',
    'asturias'            => 'Asturias',
    'baleares'            => 'Islas Baleares',
    'canarias'            => 'Canarias',
    'cantabria'           => 'Cantabria',
    'castilla-la-mancha'  => 'Castilla-La Mancha',
    'castilla-y-leon'     => 'Castilla y León',
    'cataluna'            => 'Cataluña',
    'ceuta'               => 'Ceuta',
    'comunidad-valenciana'=> 'Comunidad Valenciana',
    'extremadura'         => 'Extremadura',
    'galicia'             => 'Galicia',
    'la-rioja'            => 'La Rioja',
    'madrid'              => 'Comunidad de Madrid',
    'melilla'             => 'Melilla',
    'murcia'              => 'Región de Murcia',
    'navarra'             => 'Navarra',
    'pais-vasco'          => 'País Vasco',
];

// Subject slug (corrigiendo.es) => [internal_id, display_name]
// For each subject, we define the internal ID used in filenames and the Spanish display name.
$SUBJECT_MAP = [
    // ── ESO troncales ──
    'matematicas'                          => ['matematicas', 'Matemáticas'],
    'lengua-castellana'                    => ['lengua_castellana', 'Lengua Castellana y Literatura'],
    'ingles'                               => ['ingles', 'Inglés'],
    'geografia-e-historia'                 => ['geografia_e_historia', 'Geografía e Historia'],
    'biologia-y-geologia'                  => ['biologia_y_geologia', 'Biología y Geología'],
    'fisica-y-quimica'                     => ['fisica_y_quimica', 'Física y Química'],
    'educacion-fisica'                     => ['educacion_fisica', 'Educación Física'],
    'educacion-plastica-visual-y-audiovisual' => ['educacion_plastica', 'Educación Plástica, Visual y Audiovisual'],
    'musica'                               => ['musica', 'Música'],
    'tecnologia-y-digitalizacion'          => ['tecnologia_y_digitalizacion', 'Tecnología y Digitalización'],
    'tecnologia'                           => ['tecnologia', 'Tecnología'],
    // ── ESO optativas comunes ──
    'cultura-clasica'                      => ['cultura_clasica', 'Cultura Clásica'],
    'digitalizacion'                       => ['digitalizacion', 'Digitalización'],
    'economia'                             => ['economia', 'Economía'],
    'expresion-artistica'                  => ['expresion_artistica', 'Expresión Artística'],
    'filosofia'                            => ['filosofia', 'Filosofía'],
    'formacion-y-orientacion-personal-y-profesional' => ['fopp', 'Formación y Orientación Personal y Profesional'],
    'latin'                                => ['latin', 'Latín'],
    'segunda-lengua-extranjera-frances'    => ['segunda_lengua_frances', 'Segunda Lengua Extranjera: Francés'],
    'segunda-lengua-extranjera-aleman'     => ['segunda_lengua_aleman', 'Segunda Lengua Extranjera: Alemán'],
    'lengua-extranjera-frances'            => ['lengua_extranjera_frances', 'Lengua Extranjera: Francés'],
    'lengua-extranjera-aleman'             => ['lengua_extranjera_aleman', 'Lengua Extranjera: Alemán'],
    'oratoria-y-escritura'                 => ['oratoria', 'Oratoria y Escritura'],
    'iniciacion-a-la-filosofia'            => ['iniciacion_filosofia', 'Iniciación a la Filosofía'],
    'cultura-cientifica'                   => ['cultura_cientifica', 'Cultura Científica'],
    'artes-escenicas'                      => ['artes_escenicas', 'Artes Escénicas'],
    'educacion-para-la-ciudadania-y-los-derechos-humanos' => ['ciudadania', 'Educación para la Ciudadanía y los Derechos Humanos'],
    'educacion-fisica-y-vida-activa'       => ['educacion_fisica_vida_activa', 'Educación Física y Vida Activa'],
    'ciencias-generales'                   => ['ciencias_generales', 'Ciencias Generales'],
    // ── Bachillerato ──
    'fisica'                               => ['fisica', 'Física'],
    'quimica'                              => ['quimica', 'Química'],
    'biologia'                             => ['biologia', 'Biología'],
    'dibujo-tecnico-1'                     => ['dibujo_tecnico_1', 'Dibujo Técnico I'],
    'dibujo-tecnico-2'                     => ['dibujo_tecnico_2', 'Dibujo Técnico II'],
    'latin-1'                              => ['latin_1', 'Latín I'],
    'latin-2'                              => ['latin_2', 'Latín II'],
    'griego-1'                             => ['griego_1', 'Griego I'],
    'griego-2'                             => ['griego_2', 'Griego II'],
    'historia-de-espana'                   => ['historia_de_espana', 'Historia de España'],
    'historia-del-arte'                    => ['historia_del_arte', 'Historia del Arte'],
    'literatura-universal'                 => ['literatura_universal', 'Literatura Universal'],
    'matematicas'                          => ['matematicas', 'Matemáticas'],
    'lengua-extranjera-frances-1'          => ['lengua_extranjera_frances_1', 'Lengua Extranjera: Francés I'],
    'lengua-extranjera-frances-2'          => ['lengua_extranjera_frances_2', 'Lengua Extranjera: Francés II'],
    'lengua-extranjera-aleman-1'           => ['lengua_extranjera_aleman_1', 'Lengua Extranjera: Alemán I'],
    'lengua-extranjera-aleman-2'           => ['lengua_extranjera_aleman_2', 'Lengua Extranjera: Alemán II'],
    'tecnologia-e-ingenieria-1'            => ['tecnologia_ingenieria_1', 'Tecnología e Ingeniería I'],
    'tecnologia-e-ingenieria-2'            => ['tecnologia_ingenieria_2', 'Tecnología e Ingeniería II'],
    'informatica-1'                        => ['informatica_1', 'Informática I'],
    'informatica-2'                        => ['informatica_2', 'Informática II'],
    'oratoria'                             => ['oratoria_bach', 'Oratoria'],
    'psicologia'                           => ['psicologia', 'Psicología'],
    'cultura-audiovisual'                  => ['cultura_audiovisual', 'Cultura Audiovisual'],
    'fundamentos-de-administracion-y-gestion' => ['fund_admin_gestion', 'Fundamentos de Administración y Gestión'],
    'imagen-y-sonido'                      => ['imagen_y_sonido', 'Imagen y Sonido'],
    'lenguaje-y-practica-musical'          => ['lenguaje_practica_musical', 'Lenguaje y Práctica Musical'],
    'anatomia-aplicada'                    => ['anatomia_aplicada', 'Anatomía Aplicada'],
    'ciencias-de-la-tierra-y-del-medioambiente' => ['ciencias_tierra', 'Ciencias de la Tierra y del Medioambiente'],
    'analisis-musical-1'                   => ['analisis_musical_1', 'Análisis Musical I'],
    'analisis-musical-2'                   => ['analisis_musical_2', 'Análisis Musical II'],
    'artes-escenicas-1'                    => ['artes_escenicas_1', 'Artes Escénicas I'],
    'artes-escenicas-2'                    => ['artes_escenicas_2', 'Artes Escénicas II'],
    'coro-y-tecnica-vocal-1'               => ['coro_tecnica_vocal_1', 'Coro y Técnica Vocal I'],
    'coro-y-tecnica-vocal-2'               => ['coro_tecnica_vocal_2', 'Coro y Técnica Vocal II'],
    'dibujo-artistico-1'                   => ['dibujo_artistico_1', 'Dibujo Artístico I'],
    'dibujo-artistico-2'                   => ['dibujo_artistico_2', 'Dibujo Artístico II'],
    'dibujo-tecnico-aplicado-a-las-artes-plasticas-y-al-diseno-1' => ['dibujo_tecnico_artes_1', 'Dibujo Técnico Aplicado a las Artes Plásticas y al Diseño I'],
    'dibujo-tecnico-aplicado-a-las-artes-plasticas-y-al-diseno-2' => ['dibujo_tecnico_artes_2', 'Dibujo Técnico Aplicado a las Artes Plásticas y al Diseño II'],
    'diseno'                               => ['diseno', 'Diseño'],
    'fundamentos-artisticos'               => ['fundamentos_artisticos', 'Fundamentos Artísticos'],
    'literatura-dramatica'                 => ['literatura_dramatica', 'Literatura Dramática'],
    'movimientos-culturales-y-artisticos'  => ['movimientos_culturales', 'Movimientos Culturales y Artísticos'],
    'proyectos-artisticos'                 => ['proyectos_artisticos', 'Proyectos Artísticos'],
    'tecnicas-de-expresion-grafico-plastica'=> ['tecnicas_expresion_grafica', 'Técnicas de Expresión Gráfico-Plástica'],
    'volumen'                              => ['volumen', 'Volumen'],
    'union-europea'                        => ['union_europea', 'Unión Europea'],
    'segunda-lengua-extranjera'            => ['segunda_lengua', 'Segunda Lengua Extranjera'],
    'lengua-extranjera'                    => ['lengua_extranjera', 'Lengua Extranjera'],
];

// All subject-grade combinations discovered from corrigiendo.es sitemap.
// Format: 'subject-slug/grade-stage'
// Excluding region-specific subjects (lenguas-propias-de-aragon, cultura-y-patrimonio-de-aragon, ambito-*).
$VALID_COMBOS = [
    // Matemáticas
    'matematicas/1-eso', 'matematicas/2-eso', 'matematicas/3-eso', 'matematicas/4-eso',
    'matematicas/1-bachillerato', 'matematicas/2-bachillerato',
    // Lengua Castellana
    'lengua-castellana/1-eso', 'lengua-castellana/2-eso', 'lengua-castellana/3-eso', 'lengua-castellana/4-eso',
    'lengua-castellana/1-bachillerato', 'lengua-castellana/2-bachillerato',
    // Inglés
    'ingles/1-eso', 'ingles/2-eso', 'ingles/3-eso', 'ingles/4-eso',
    'ingles/1-bachillerato', 'ingles/2-bachillerato',
    // Geografía e Historia
    'geografia-e-historia/1-eso', 'geografia-e-historia/2-eso', 'geografia-e-historia/3-eso', 'geografia-e-historia/4-eso',
    'geografia-e-historia/1-bachillerato', 'geografia-e-historia/2-bachillerato',
    // Biología y Geología
    'biologia-y-geologia/1-eso', 'biologia-y-geologia/2-eso', 'biologia-y-geologia/3-eso', 'biologia-y-geologia/4-eso',
    'biologia-y-geologia/1-bachillerato', 'biologia-y-geologia/2-bachillerato',
    // Física y Química
    'fisica-y-quimica/1-eso', 'fisica-y-quimica/2-eso', 'fisica-y-quimica/3-eso', 'fisica-y-quimica/4-eso',
    'fisica-y-quimica/1-bachillerato', 'fisica-y-quimica/2-bachillerato',
    // Educación Física
    'educacion-fisica/1-eso', 'educacion-fisica/2-eso', 'educacion-fisica/3-eso', 'educacion-fisica/4-eso',
    'educacion-fisica/1-bachillerato',
    'educacion-fisica-y-vida-activa/1-bachillerato', 'educacion-fisica-y-vida-activa/2-bachillerato',
    // Educación Plástica, Visual y Audiovisual
    'educacion-plastica-visual-y-audiovisual/1-eso', 'educacion-plastica-visual-y-audiovisual/2-eso',
    'educacion-plastica-visual-y-audiovisual/3-eso',
    // Música
    'musica/1-eso', 'musica/2-eso', 'musica/3-eso', 'musica/4-eso',
    // Tecnología y Digitalización
    'tecnologia-y-digitalizacion/1-eso', 'tecnologia-y-digitalizacion/2-eso',
    'tecnologia-y-digitalizacion/3-eso', 'tecnologia-y-digitalizacion/4-eso',
    // Tecnología
    'tecnologia/1-eso', 'tecnologia/2-eso', 'tecnologia/4-eso',
    // Economía
    'economia/1-eso', 'economia/2-eso', 'economia/3-eso', 'economia/4-eso',
    'economia/1-bachillerato', 'economia/2-bachillerato',
    // Filosofía
    'filosofia/1-eso', 'filosofia/2-eso', 'filosofia/4-eso',
    'filosofia/1-bachillerato', 'filosofia/2-bachillerato',
    // Cultura Clásica
    'cultura-clasica/3-eso',
    // Digitalización
    'digitalizacion/1-eso', 'digitalizacion/2-eso', 'digitalizacion/3-eso', 'digitalizacion/4-eso',
    // Expresión Artística
    'expresion-artistica/4-eso',
    // FOPP
    'formacion-y-orientacion-personal-y-profesional/1-eso', 'formacion-y-orientacion-personal-y-profesional/4-eso',
    // Latín (ESO)
    'latin/4-eso',
    // Segunda Lengua Extranjera
    'segunda-lengua-extranjera-frances/1-eso', 'segunda-lengua-extranjera-frances/2-eso',
    'segunda-lengua-extranjera-frances/3-eso', 'segunda-lengua-extranjera-frances/4-eso',
    'segunda-lengua-extranjera-aleman/1-eso', 'segunda-lengua-extranjera-aleman/2-eso',
    'segunda-lengua-extranjera-aleman/3-eso', 'segunda-lengua-extranjera-aleman/4-eso',
    // Lengua Extranjera (ESO)
    'lengua-extranjera-frances/1-eso', 'lengua-extranjera-frances/2-eso',
    'lengua-extranjera-frances/3-eso', 'lengua-extranjera-frances/4-eso',
    'lengua-extranjera-aleman/1-eso', 'lengua-extranjera-aleman/2-eso',
    'lengua-extranjera-aleman/3-eso', 'lengua-extranjera-aleman/4-eso',
    // Oratoria
    'oratoria-y-escritura/1-eso', 'oratoria-y-escritura/2-eso', 'oratoria-y-escritura/3-eso',
    // Otros ESO
    'iniciacion-a-la-filosofia/1-eso',
    'cultura-cientifica/1-eso', 'cultura-cientifica/2-eso', 'cultura-cientifica/3-eso', 'cultura-cientifica/4-eso',
    'artes-escenicas/1-eso', 'artes-escenicas/2-eso', 'artes-escenicas/3-eso', 'artes-escenicas/4-eso',
    'educacion-para-la-ciudadania-y-los-derechos-humanos/1-bachillerato',
    // ── Bachillerato ──
    'fisica/2-bachillerato',
    'quimica/2-bachillerato',
    'biologia/2-bachillerato',
    'dibujo-tecnico-1/1-bachillerato', 'dibujo-tecnico-2/2-bachillerato',
    'latin-1/1-bachillerato', 'latin-2/2-bachillerato',
    'griego-1/1-bachillerato', 'griego-2/2-bachillerato',
    'historia-de-espana/2-bachillerato',
    'historia-del-arte/2-bachillerato',
    'literatura-universal/1-bachillerato',
    'lengua-extranjera-frances-1/1-bachillerato', 'lengua-extranjera-frances-2/2-bachillerato',
    'lengua-extranjera-aleman-1/1-bachillerato', 'lengua-extranjera-aleman-2/2-bachillerato',
    'tecnologia-e-ingenieria-1/1-bachillerato', 'tecnologia-e-ingenieria-2/2-bachillerato',
    'informatica-1/1-bachillerato', 'informatica-2/2-bachillerato',
    'oratoria/1-bachillerato', 'oratoria/2-bachillerato',
    'psicologia/2-bachillerato',
    'cultura-audiovisual/1-bachillerato',
    'fundamentos-de-administracion-y-gestion/1-bachillerato',
    'imagen-y-sonido/1-bachillerato', 'imagen-y-sonido/2-bachillerato',
    'lenguaje-y-practica-musical/1-bachillerato',
    'anatomia-aplicada/1-bachillerato', 'anatomia-aplicada/2-bachillerato',
    'ciencias-de-la-tierra-y-del-medioambiente/2-bachillerato',
    'ciencias-generales/2-bachillerato',
    'analisis-musical-1/1-bachillerato', 'analisis-musical-2/2-bachillerato',
    'artes-escenicas-1/1-bachillerato', 'artes-escenicas-2/2-bachillerato',
    'coro-y-tecnica-vocal-1/1-bachillerato', 'coro-y-tecnica-vocal-2/2-bachillerato',
    'dibujo-artistico-1/1-bachillerato', 'dibujo-artistico-2/2-bachillerato',
    'dibujo-tecnico-aplicado-a-las-artes-plasticas-y-al-diseno-1/1-bachillerato',
    'dibujo-tecnico-aplicado-a-las-artes-plasticas-y-al-diseno-2/2-bachillerato',
    'diseno/2-bachillerato',
    'fundamentos-artisticos/2-bachillerato',
    'literatura-dramatica/2-bachillerato',
    'movimientos-culturales-y-artisticos/2-bachillerato',
    'proyectos-artisticos/1-bachillerato',
    'tecnicas-de-expresion-grafico-plastica/2-bachillerato',
    'volumen/1-bachillerato',
    'union-europea/1-bachillerato', 'union-europea/2-bachillerato',
    'historia-de-la-musica-y-de-la-danza/2-bachillerato',
];

// ── CLI argument parsing ───────────────────────────────────────────
$options = getopt('', ['community:', 'subject:', 'force', 'dry-run', 'delay:']);
$filter_community = $options['community'] ?? null;
$filter_subject   = $options['subject'] ?? null;
$force            = isset($options['force']);
$dry_run          = isset($options['dry-run']);
$delay            = (float)($options['delay'] ?? 1.0);

// ── Helper: resolve subject mapping ────────────────────────────────
function resolve_subject(string $slug): array {
    global $SUBJECT_MAP;
    if (isset($SUBJECT_MAP[$slug])) {
        return $SUBJECT_MAP[$slug];
    }
    $id = str_replace('-', '_', $slug);
    $name = ucwords(str_replace('-', ' ', $slug));
    return [$id, $name];
}

// ── Helper: HTTP fetch with cURL ───────────────────────────────────
function fetch_url(string $url): ?string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Sentinel-LOMLOE-Extractor/1.0)',
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200 || $body === false) {
        return null;
    }
    return $body;
}

// ── Parser: extract CEs and criterios, grouped by track ───────────
// Returns array of tracks: [ ['track' => 'Matemáticas', 'competencias' => [...]], ... ]
// Pages may have multiple tracks (e.g., Matemáticas / Matemáticas A / Matemáticas B).
function parse_criterios_page(string $html): ?array {
    // Strategy: use regex on raw HTML to split into tracks by h3 headers,
    // then use DOMDocument to parse each track's sections.
    // This avoids issues with DOMDocument node ordering.

    // Find the "listado" section that contains all CEs
    $listado_pos = 0;
    if (preg_match('/id="listado[^"]*"/', $html, $m, PREG_OFFSET_CAPTURE)) {
        $listado_pos = $m[0][1];
    }
    $content = substr($html, $listado_pos);

    // Find all h3 track header positions and section positions
    preg_match_all('/<h3\s+class="text-xl[^"]*font-bold[^"]*"[^>]*>\s*(?:<[^>]+>\s*)*([^<]+)/s', $content, $h3_matches, PREG_OFFSET_CAPTURE);
    preg_match_all('/<section\s+id="ce-[^"]*"/s', $content, $sec_matches, PREG_OFFSET_CAPTURE);

    if (empty($sec_matches[0])) {
        return null;
    }

    // If no h3 headers found, treat entire page as single track
    if (empty($h3_matches[0])) {
        return parse_single_track($html);
    }

    // Build track boundaries: each h3 starts a track, ends at the next h3
    $track_boundaries = [];
    foreach ($h3_matches[1] as $i => $match) {
        $name = trim($match[0]);
        $offset = $h3_matches[0][$i][1];
        $track_boundaries[] = ['name' => $name, 'offset' => $offset];
    }

    // Assign sections to tracks based on position
    $tracks = [];
    for ($t = 0; $t < count($track_boundaries); $t++) {
        $track_start = $track_boundaries[$t]['offset'];
        $track_end = ($t + 1 < count($track_boundaries)) ? $track_boundaries[$t + 1]['offset'] : strlen($content);
        $track_html = substr($content, $track_start, $track_end - $track_start);

        // Parse this track chunk
        $competencias = parse_html_chunk($track_html);
        if ($competencias) {
            $tracks[] = [
                'track' => $track_boundaries[$t]['name'],
                'competencias' => $competencias,
            ];
        }
    }

    return !empty($tracks) ? $tracks : null;
}

function parse_single_track(string $html): ?array {
    $competencias = parse_html_chunk($html);
    return $competencias ? [['track' => '', 'competencias' => $competencias]] : null;
}

function parse_html_chunk(string $html_chunk): ?array {
    libxml_use_internal_errors(true);
    $doc = new DOMDocument('1.0', 'UTF-8');
    $doc->loadHTML('<?xml encoding="UTF-8"><div>' . $html_chunk . '</div>', LIBXML_NOERROR | LIBXML_NOWARNING);
    $errors = libxml_get_errors();
    libxml_clear_errors();

    $xpath = new DOMXPath($doc);
    $sections = $xpath->query('//section[starts-with(@id, "ce-")]');
    if ($sections->length === 0) {
        return null;
    }

    $competencias = [];
    foreach ($sections as $section) {
        $ce = parse_one_section($xpath, $section);
        if ($ce) {
            $competencias[] = $ce;
        }
    }
    return !empty($competencias) ? $competencias : null;
}

function parse_one_section(DOMXPath $xpath, DOMNode $section): ?array {
    // Extract CE code from the <p> with "Competencia específica CE.X.N"
    $ce_label_nodes = $xpath->query('.//p[contains(@class, "text-xs") and contains(@class, "uppercase")]', $section);
    if ($ce_label_nodes->length === 0) {
        return null;
    }
    $ce_label = trim($ce_label_nodes->item(0)->textContent);

    // Extract CE number: "Competencia específica CE.M.1" or "OBJ1" → "1"
    if (preg_match('/(?:CE|OBJ)\.?\w*\.?(\d+)/', $ce_label, $m)) {
        $ce_num = (int) $m[1];
        $ce_code = 'CE' . $ce_num;
    } else {
        return null;
    }

    // Extract CE description from <h4>
    $h4_nodes = $xpath->query('.//h4[contains(@class, "font-bold")]', $section);
    $ce_description = '';
    if ($h4_nodes->length > 0) {
        $ce_description = trim($h4_nodes->item(0)->textContent);
    }

    $ce_name = generate_short_name($ce_description);

    // Extract criterios from <article> elements
    $articles = $xpath->query('.//article', $section);
    $criterios = [];

    foreach ($articles as $article) {
        $code_spans = $xpath->query('.//span[contains(@class, "font-bold") and contains(@class, "text-sm")]', $article);
        if ($code_spans->length === 0) {
            $all_spans = $xpath->query('.//span', $article);
            $crit_code = null;
            foreach ($all_spans as $span) {
                $text = trim($span->textContent);
                if (preg_match('/^\d+\.\d+$/', $text)) {
                    $crit_code = $text;
                    break;
                }
            }
            if (!$crit_code) {
                continue;
            }
        } else {
            $crit_code = trim($code_spans->item(0)->textContent);
        }

        // Description: first <p> inside the flex-1 div (may use font-semibold+text-slate-900 or just text-slate-800)
        $desc_nodes = $xpath->query('.//div[contains(@class, "flex-1")]/p[1]', $article);
        if ($desc_nodes->length === 0) {
            $desc_nodes = $xpath->query('.//p[contains(@class, "text-slate-900") or contains(@class, "text-slate-800")]', $article);
        }
        $crit_desc = '';
        if ($desc_nodes->length > 0) {
            $crit_desc = trim($desc_nodes->item(0)->textContent);
        }

        if ($crit_code && $crit_desc) {
            $criterios[] = [
                'code'        => $crit_code,
                'description' => $crit_desc,
            ];
        }
    }

    if (empty($criterios)) {
        return null;
    }

    return [
        'code'        => $ce_code,
        'name'        => $ce_name,
        'description' => $ce_description,
        'criterios'   => $criterios,
    ];
}

// ── Helper: generate a short name from a CE description ────────────
function generate_short_name(string $desc): string {
    $desc = trim($desc);
    if (empty($desc)) {
        return '';
    }

    // Try splitting at ", para " or ", de forma " or ", reconociendo "
    $splits = [', para ', ', de forma ', ', reconociendo ', ', interconectando ',
               ', evaluando ', ', interrelacionando ', ', organizando ', ', aplicando '];
    foreach ($splits as $split) {
        $pos = mb_strpos($desc, $split);
        if ($pos !== false && $pos > 15 && $pos < 120) {
            $short = mb_substr($desc, 0, $pos);
            break;
        }
    }

    if (!isset($short)) {
        // Take first sentence or first 80 chars
        $dot = mb_strpos($desc, '. ');
        if ($dot !== false && $dot < 120) {
            $short = mb_substr($desc, 0, $dot);
        } else {
            $short = mb_substr($desc, 0, 80);
            $space = mb_strrpos($short, ' ');
            if ($space > 40) {
                $short = mb_substr($short, 0, $space);
            }
        }
    }

    // Capitalize first letter, remove trailing dots
    $short = rtrim($short, '. ');
    return mb_strtoupper(mb_substr($short, 0, 1)) . mb_substr($short, 1);
}

// ── Helper: derive a file suffix from a track name ─────────────────
function track_name_to_suffix(string $track_name, string $base_subject): string {
    $track = trim($track_name);

    // Specific known patterns
    if (preg_match('/\b([AB])\s*$/u', $track, $m)) {
        return strtolower($m[1]);
    }
    if (preg_match('/Aplicadas?\s+a\s+las\s+Ciencias\s+Sociales\s*(I{1,2})?/iu', $track, $m)) {
        return 'ccss';
    }
    if (preg_match('/Generales?/iu', $track)) {
        return 'generales';
    }
    if (preg_match('/Tecnolog[ií]a\s+(?:y\s+)?Digital/iu', $track)) {
        return 'td';
    }
    if (preg_match('/Acad[eé]micas?/iu', $track)) {
        return 'academicas';
    }
    if (preg_match('/Aplicadas?(?:\s|$)/iu', $track)) {
        return 'aplicadas';
    }
    // "Matemáticas I" or "Matemáticas II" (science track)
    if (preg_match('/\b(I{1,2})\s*$/u', $track, $m)) {
        return 'ciencias';
    }

    // Generic: transliterate accents and clean up
    $clean = transliterate_to_ascii(mb_strtolower($track));
    $base_clean = transliterate_to_ascii(mb_strtolower(trim($base_subject)));
    $remainder = trim(str_replace($base_clean, '', $clean));
    $remainder = preg_replace('/[^a-z0-9]+/', '_', $remainder);
    $remainder = trim($remainder, '_');
    return $remainder ?: 'alt';
}

function transliterate_to_ascii(string $str): string {
    $map = ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ñ'=>'n','ü'=>'u',
            'Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U','Ñ'=>'N','Ü'=>'U'];
    return strtr($str, $map);
}

// ── Helper: extract decree from page text ──────────────────────────
function extract_decree(string $html): string {
    // Look for patterns like "Decreto 65/2022" or "Orden ECD/1172/2022"
    if (preg_match('/((?:Decreto|Orden|Real Decreto)\s+[A-Z\/]*\s*\d+\/\d{4}(?:,\s*de\s+\d+\s+de\s+\w+)?)/', $html, $m)) {
        return $m[1];
    }
    return '';
}

// ── Helper: extract subject name from title ────────────────────────
function extract_subject_name_from_title(string $html): string {
    if (preg_match('/<title>Criterios de Evaluación de (.+?)\s+\d/', $html, $m)) {
        return html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
    }
    return '';
}

// ── Main extraction loop ───────────────────────────────────────────
$stats = ['fetched' => 0, 'saved' => 0, 'skipped' => 0, 'errors' => 0, 'not_found' => 0];
$total_combos = count($VALID_COMBOS);
$communities_to_process = $filter_community ? [$filter_community => $COMMUNITIES[$filter_community] ?? $filter_community] : $COMMUNITIES;
$total = count($communities_to_process) * $total_combos;

echo "\n=== Sentinel LOMLOE Curriculum Extractor ===\n\n";
echo "Combinations: {$total_combos} subjects × " . count($communities_to_process) . " communities = {$total} max requests\n";
echo "Data directory: {$DATA_DIR}\n";
echo "Delay between requests: {$delay}s\n";
if ($force) echo "Mode: FORCE (overwrite existing files)\n";
if ($dry_run) echo "Mode: DRY RUN (no files written)\n";
echo str_repeat('-', 70) . "\n";

$count = 0;

foreach ($communities_to_process as $community_slug => $community_name) {
    echo "\n== {$community_name} ({$community_slug}) ==\n";

    foreach ($VALID_COMBOS as $combo) {
        $count++;
        // Parse combo: "subject-slug/grade-stage"
        $parts = explode('/', $combo);
        $subject_slug = $parts[0];
        $grade_stage  = $parts[1];

        // Filter by subject if specified
        if ($filter_subject && $subject_slug !== $filter_subject) {
            continue;
        }

        // Parse grade and stage from "1-eso" or "2-bachillerato"
        if (!preg_match('/^(\d+)-(.+)$/', $grade_stage, $gs)) {
            continue;
        }
        $grade = (int) $gs[1];
        $stage = $gs[2];

        // Resolve internal ID and display name
        [$subject_id, $subject_display] = resolve_subject($subject_slug);

        // Output file path
        $dir  = "{$DATA_DIR}/{$community_slug}/{$stage}";
        $file = "{$dir}/{$subject_id}_{$grade}.json";

        // Skip existing files unless --force
        if (file_exists($file) && !$force) {
            $stats['skipped']++;
            continue;
        }

        // Progress indicator
        $pct = round($count / $total * 100);
        echo "  [{$pct}%] {$subject_slug} {$grade}º {$stage} ... ";

        if ($dry_run) {
            echo "DRY RUN\n";
            continue;
        }

        // Fetch the page
        $url = "{$BASE_URL}/{$subject_slug}/{$grade}-{$stage}/{$community_slug}/criterios-evaluacion";
        $html = fetch_url($url);

        if ($html === null) {
            echo "404/error\n";
            $stats['not_found']++;
            usleep((int)($delay * 500000)); // shorter delay for 404s
            continue;
        }

        $stats['fetched']++;

        // Parse — returns array of tracks
        $tracks = parse_criterios_page($html);
        if ($tracks === null) {
            echo "PARSE ERROR\n";
            $stats['errors']++;
            usleep((int)($delay * 1000000));
            continue;
        }

        // Extract metadata
        $decree = extract_decree($html);
        $title_name = extract_subject_name_from_title($html);
        if ($title_name) {
            $subject_display = $title_name;
        }

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        // Save each track as a separate file.
        $track_labels = [];
        $multi_track = count($tracks) > 1;
        foreach ($tracks as $ti => $track) {
            $track_name = trim($track['track']);
            $competencias = $track['competencias'];

            // Determine subject ID suffix for multi-track subjects
            $track_subject_id = $subject_id;
            $track_subject_display = $subject_display;
            $track_file = $file;

            if ($multi_track) {
                // ALL tracks get a suffix when there are multiple
                $suffix = track_name_to_suffix($track_name, $subject_display);
                if ($suffix) {
                    $track_subject_id = $subject_id . '_' . $suffix;
                    $track_subject_display = $track_name;
                    $track_file = "{$dir}/{$track_subject_id}_{$grade}.json";
                }
            }

            // Skip if this track file already exists
            if (file_exists($track_file) && !$force && $track_file !== $file) {
                continue;
            }

            $data = [
                'community'    => $community_slug,
                'stage'        => $stage,
                'decree'       => $decree,
                'subject'      => $track_subject_id,
                'subject_name' => $track_subject_display,
                'grade'        => $grade,
                'competencias' => $competencias,
            ];

            $ce_count   = count($competencias);
            $crit_count = array_sum(array_map(function ($ce) { return count($ce['criterios']); }, $competencias));

            $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            file_put_contents($track_file, $json . "\n");
            $stats['saved']++;

            $track_labels[] = ($track_name ?: 'base') . ":{$ce_count}CE/{$crit_count}crit";
        }

        echo "OK (" . implode(', ', $track_labels) . ")\n";

        // Rate limiting
        usleep((int)($delay * 1000000));
    }
}

// ── Generate catalog.json ──────────────────────────────────────────
echo "\n" . str_repeat('-', 70) . "\n";
echo "Generating catalog.json ...\n";

if (!$dry_run) {
    $catalog = build_catalog($DATA_DIR, $COMMUNITIES);
    $catalog_json = json_encode($catalog, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    file_put_contents("{$DATA_DIR}/catalog.json", $catalog_json . "\n");
    echo "Catalog saved with " . count($catalog['communities']) . " communities.\n";
}

// ── Summary ────────────────────────────────────────────────────────
echo "\n" . str_repeat('=', 70) . "\n";
echo "DONE!\n";
echo "  Fetched:   {$stats['fetched']}\n";
echo "  Saved:     {$stats['saved']}\n";
echo "  Skipped:   {$stats['skipped']} (already exist)\n";
echo "  Not found: {$stats['not_found']} (404)\n";
echo "  Errors:    {$stats['errors']}\n";
echo str_repeat('=', 70) . "\n";

// ── Catalog builder ────────────────────────────────────────────────
function build_catalog(string $data_dir, array $communities): array {
    $stage_names = [
        'eso'          => 'Educación Secundaria Obligatoria',
        'bachillerato' => 'Bachillerato',
    ];

    $catalog_communities = [];

    foreach ($communities as $cid => $cname) {
        $community_dir = "{$data_dir}/{$cid}";
        if (!is_dir($community_dir)) {
            continue;
        }

        $stages = [];
        foreach (['eso', 'bachillerato'] as $stage_id) {
            $stage_dir = "{$community_dir}/{$stage_id}";
            if (!is_dir($stage_dir)) {
                continue;
            }

            // Scan JSON files in this stage directory
            $files = glob("{$stage_dir}/*.json");
            if (empty($files)) {
                continue;
            }

            $subjects = [];
            $all_grades = [];
            $decree = '';

            foreach ($files as $f) {
                $json = json_decode(file_get_contents($f), true);
                if (!$json) {
                    continue;
                }

                $sub_id = $json['subject'];
                $sub_name = $json['subject_name'] ?? $sub_id;
                $g = $json['grade'];

                if (!$decree && !empty($json['decree'])) {
                    $decree = $json['decree'];
                }

                if (!isset($subjects[$sub_id])) {
                    $subjects[$sub_id] = [
                        'id'     => $sub_id,
                        'name'   => $sub_name,
                        'grades' => [],
                    ];
                }
                $subjects[$sub_id]['grades'][] = $g;
                $all_grades[] = $g;
            }

            // Sort grades and subjects
            foreach ($subjects as &$sub) {
                sort($sub['grades']);
                $sub['grades'] = array_values(array_unique($sub['grades']));
            }
            unset($sub);

            // Sort subjects by name
            usort($subjects, function ($a, $b) { return strcmp($a['name'], $b['name']); });
            $subjects = array_values($subjects);

            $all_grades = array_values(array_unique($all_grades));
            sort($all_grades);

            $stages[] = [
                'id'       => $stage_id,
                'name'     => $stage_names[$stage_id] ?? $stage_id,
                'decree'   => $decree,
                'grades'   => $all_grades,
                'subjects' => $subjects,
            ];
        }

        if (!empty($stages)) {
            $catalog_communities[] = [
                'id'     => $cid,
                'name'   => $cname,
                'stages' => $stages,
            ];
        }
    }

    // Sort communities by name
    usort($catalog_communities, function ($a, $b) { return strcmp($a['name'], $b['name']); });

    return ['communities' => $catalog_communities];
}

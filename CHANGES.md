# Adaptaciones sobre Moodle 4.5 — Plugin local_sentinel

Documento técnico que describe exactamente qué se ha añadido o modificado respecto a una instalación estándar de Moodle 4.5.

## Resumen ejecutivo

El plugin **no toca ni una sola línea del núcleo de Moodle**. Todo funciona mediante los mecanismos de extensión oficiales (hooks, AMD, XMLDB). Esto es una buena práctica de desarrollo Moodle y garantiza que el plugin puede instalarse y desinstalarse limpiamente sin afectar a la plataforma.

Lo que se añade:

- **6 tablas nuevas** (`mdl_local_sentinel_*`) — todas documentadas campo a campo con su propósito
- **~30 ficheros PHP/JS** en `local/sentinel/` — ninguno fuera de esa carpeta
- **Lectura de 14 tablas** del núcleo para calcular notas (sin modificarlas)
- **Modificación indirecta** de `mdl_grade_items` al mapear criterios (reversible y documentada)
- **Historial de las 7 versiones** del plugin con sus migraciones

---

## 1. Principio de diseño: cero modificaciones al núcleo

**No se ha modificado ningún fichero del núcleo de Moodle.**

El plugin se integra exclusivamente mediante los mecanismos de extensión oficiales de la plataforma:

- **Plugin local** (`local/sentinel/`) — el tipo de plugin más genérico de Moodle, sin restricciones de funcionalidad
- **Sistema de hooks** (`$CFG->hooks`) — permite inyectar HTML en puntos concretos del ciclo de vida de la página sin tocar plantillas
- **AMD (Asynchronous Module Definition)** — sistema de módulos JavaScript de Moodle para cargar JS bajo demanda
- **XMLDB** — sistema de definición de esquema de BD de Moodle para crear y migrar tablas de forma portátil

Esto significa que el plugin puede desinstalarse limpiamente y Moodle queda exactamente como estaba.

---

## 2. Tablas nuevas en la base de datos

Se añaden **6 tablas nuevas**, todas con el prefijo `mdl_local_sentinel_*`. Las tablas del núcleo de Moodle no se modifican.

### `mdl_local_sentinel_descriptor`
Almacena los 34 descriptores del perfil de salida del bachillerato según el RD 217/2022 (LOMLOE).

| Campo | Tipo | Descripción |
|-------|------|-------------|
| `id` | INT PK | Identificador |
| `code` | CHAR(10) UNIQUE | Código del descriptor (ej. `CCL1`, `STEM3`) |
| `area` | CHAR(10) | Área competencial (ej. `CCL`, `STEM`) |
| `sortorder` | INT | Orden de presentación |
| `description` | TEXT | Texto completo del descriptor según el RD |

Se **precarga con los 34 descriptores oficiales** durante la instalación del plugin.

---

### `mdl_local_sentinel_cat_descriptor`
Vincula una **categoría del libro de calificaciones** de Moodle con un **descriptor del perfil de salida**. Es la tabla de mapeo principal que define qué criterios de evaluación trabajan qué descriptor.

| Campo | Tipo | Descripción |
|-------|------|-------------|
| `id` | INT PK | Identificador |
| `grade_category_id` | INT | FK → `mdl_grade_categories.id` |
| `descriptorcode` | CHAR(10) | FK → `mdl_local_sentinel_descriptor.code` |

Índice único en `(grade_category_id, descriptorcode)`.

---

### `mdl_local_sentinel_map`
Mapea cada **criterio de rúbrica** (`mdl_gradingform_rubric_criteria`) al **ítem de calificación manual** de Sentinel que lo representa en el libro de calificaciones.

| Campo | Tipo | Descripción |
|-------|------|-------------|
| `id` | INT PK | Identificador |
| `rubric_row_id` | INT UNIQUE | FK → `mdl_gradingform_rubric_criteria.id` |
| `grade_category_id` | INT | FK → `mdl_grade_categories.id` (destino) |
| `grade_item_id` | INT | FK → `mdl_grade_items.id` (ítem creado por Sentinel) |

---

### `mdl_local_sentinel_period`
Define los **períodos de comunicación de notas** (ej. "1ª Evaluación", "2ª Evaluación"). Cada período tiene una fecha de corte que permite calcular la nota del alumno en ese momento.

| Campo | Tipo | Descripción |
|-------|------|-------------|
| `id` | INT PK | Identificador |
| `courseid` | INT | `0` = global (todos los cursos); otro valor = específico del curso |
| `sortorder` | INT | Orden de presentación |
| `label` | CHAR(100) | Nombre del período (ej. "3ª Evaluación") |
| `cutoffdate` | INT | Timestamp Unix de cierre. Solo se cuentan calificaciones anteriores a esta fecha |

---

### `mdl_local_sentinel_student_photo`
Almacena las **fotografías de alumnos** subidas por el profesorado para identificación visual en el informe.

| Campo | Tipo | Descripción |
|-------|------|-------------|
| `id` | INT PK | Identificador |
| `studentid` | INT UNIQUE | FK → `mdl_user.id` (un alumno, una foto) |
| `uploaderid` | INT | FK → `mdl_user.id` (profesor que la subió) |
| `timemodified` | INT | Timestamp de la última actualización |

El contenido binario de la imagen se almacena en el **filestore de Moodle** (`mdl_files`) usando la API de ficheros estándar, referenciada por `component='local_sentinel'`, `filearea='student_photo'`.

---

### `mdl_local_sentinel_ce_descriptor` *(tabla auxiliar, uso futuro)*
Tabla de mapeo directo criterio de rúbrica → descriptor. Se creó durante el desarrollo inicial como alternativa al mapeo por categoría; actualmente el flujo principal usa `local_sentinel_cat_descriptor`.

| Campo | Tipo | Descripción |
|-------|------|-------------|
| `id` | INT PK | Identificador |
| `criteriaid` | INT | FK → `mdl_gradingform_rubric_criteria.id` |
| `descriptorcode` | CHAR(10) | FK → `mdl_local_sentinel_descriptor.code` |
| `timecreated` | INT | Timestamp de creación |

---

## 3. Ficheros PHP añadidos

### Raíz del plugin

| Fichero | Propósito |
|---------|-----------|
| `version.php` | Metadatos del plugin: versión `2024010207`, requiere Moodle 4.5+ |
| `lib.php` | Callbacks opcionales de Moodle (navegación, menús) |
| `settings.php` | Página de configuración del plugin en el panel de administración |
| `ajax.php` | Endpoint AJAX propio: mapeo de rúbricas, resumen de rúbrica para tooltip, creación de períodos |
| `photo.php` | Servicio HTTP para servir/subir fotografías de alumnos |
| `student_report.php` | Página principal del informe de competencias del alumno |

### `db/`

| Fichero | Propósito |
|---------|-----------|
| `install.xml` | Esquema XMLDB con las 6 tablas nuevas |
| `install.php` | Siembra los 34 descriptores LOMLOE en la primera instalación |
| `upgrade.php` | Migraciones incrementales (versiones 2024010201 → 2024010207) |
| `access.php` | Definición de capacidades Moodle (`local/sentinel:viewreport`, etc.) |
| `hooks.php` | Registro del listener del hook `before_footer_html_generation` |
| `events.php` | Registro de observadores de eventos Moodle |

### `classes/`

| Fichero | Propósito |
|---------|-----------|
| `descriptor_calculator.php` | Núcleo del plugin. Calcula la nota por descriptor cruzando rúbricas, categorías, ocasiones y fecha de corte mediante una consulta SQL multi-JOIN sobre las tablas de calificaciones de Moodle |
| `hook/before_footer.php` | Listener del hook de pie de página. Detecta en qué página está el usuario y decide qué inyectar: editor de rúbricas, informe del calificador (tooltip, toggle abreviación, botón de comunicación) o fotos en la lista de participantes |
| `observer.php` | Observador de eventos Moodle (actualmente reservado) |
| `privacy/provider.php` | Implementación de la API de privacidad de Moodle (GDPR): exportación y eliminación de datos de usuario |

### `admin/`

| Fichero | Propósito |
|---------|-----------|
| `map_descriptors.php` | Interfaz de administración para mapear categorías del calificador a descriptores LOMLOE |
| `periods.php` | Interfaz para crear y gestionar los períodos de comunicación globales |

### `cli/`

| Fichero | Propósito |
|---------|-----------|
| `sync_grades.php` | Script CLI para sincronización manual de calificaciones |

---

## 4. Módulos JavaScript añadidos (AMD)

Los ficheros en `amd/src/` son los **fuentes**; los de `amd/build/` son versiones minificadas generadas automáticamente por Moodle/Grunt y necesarias para el funcionamiento.

| Módulo | Propósito |
|--------|-----------|
| `amd/src/rubric_editor.js` | Interfaz de mapeo de criterios de rúbrica en el módulo de calificación. Añade selectores desplegables junto a cada criterio de la rúbrica para asignarlo a una categoría Sentinel |
| `amd/src/descriptor_report.js` | Lógica interactiva del informe de alumnos: pestañas de período, drill-down de descriptor, comparativa con la clase |
| `amd/src/student_photo.js` | Inyección de miniaturas de foto en la página `/user/index.php` (lista de participantes del curso) |

---

## 5. Otros recursos añadidos

| Fichero/Carpeta | Propósito |
|-----------------|-----------|
| `data/descriptores_perfil_salida.json` | Los 34 descriptores LOMLOE en formato JSON (usado como referencia y fuente de siembra) |
| `lang/es/local_sentinel.php` | Cadenas de texto en español |
| `lang/en/local_sentinel.php` | Cadenas de texto en inglés |
| `pix/icon.svg` | Icono del plugin para el panel de administración de Moodle |
| `image.png` | Imagen de cabecera del informe |

---

## 6. Puntos de integración con Moodle (sin modificar núcleo)

### Hook `before_footer_html_generation`
El plugin se engancha al evento de generación del pie de página de cada request HTTP. El listener (`before_footer.php`) inspecciona la URL actual y el contexto del usuario, e inyecta el HTML/JS pertinente:

- **`/mod/assign/view.php`** (vista de tarea con rúbrica) → inyecta el editor de mapeo de criterios
- **`/grade/report/grader/index.php`** (informe del calificador) → inyecta tooltip de rúbrica, toggle de abreviación de nombres, botón de comunicación
- **`/user/index.php`** (lista de participantes) → inyecta miniaturas de foto de alumnos

### Tablas de Moodle leídas (sin modificar)
El plugin realiza consultas de solo lectura sobre las siguientes tablas del núcleo para calcular las notas por descriptor:

| Tabla Moodle | Uso |
|--------------|-----|
| `mdl_assign_grades` | Notas de las tareas por alumno e intento |
| `mdl_assign` | Metadatos de la tarea (nombre, curso) |
| `mdl_course`, `mdl_course_modules` | Información del curso y módulo |
| `mdl_context` | Contexto del módulo para permisos |
| `mdl_grading_areas`, `mdl_grading_definitions` | Definición de la rúbrica activa |
| `mdl_gradingform_rubric_criteria` | Criterios de la rúbrica |
| `mdl_gradingform_rubric_fillings` | Nivel seleccionado por el evaluador |
| `mdl_gradingform_rubric_levels` | Niveles y puntuaciones de cada criterio |
| `mdl_grading_instances` | Instancia de calificación (enlaza rúbrica y nota) |
| `mdl_grade_categories` | Categorías del libro de calificaciones |
| `mdl_grade_items` | Ítems de calificación (actividades y manuales) |
| `mdl_user` | Datos del alumno |

### Tabla de Moodle modificada indirectamente
Al mapear un criterio de rúbrica, el plugin **crea ítems de calificación manuales** en `mdl_grade_items` (con idnumber `sentinel_crit_{id}`) y puede **mover ítems existentes de categoría** en `mdl_grade_items` para reorganizar el libro de calificaciones. Estos cambios son reversibles: al desactivar el mapeo, el plugin restaura la categoría y peso originales (guardados en `mdl_config` con clave `local_sentinel`).

---

## 7. Resumen de versiones del plugin

| Versión | Cambios principales |
|---------|---------------------|
| 2024010200 | Versión inicial: tablas `map`, editor de rúbricas |
| 2024010201 | Añade columna `grade_item_id` a `local_sentinel_map` |
| 2024010203 | Añade tabla `student_photo` |
| 2024010205 | Añade índices únicos en `map` y `student_photo` |
| 2024010206 | Añade tablas `descriptor`, `ce_descriptor`, `period`; siembra los 34 descriptores LOMLOE |
| 2024010207 | Añade tabla `cat_descriptor` (mapeo categoría → descriptor) |

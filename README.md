# local_sentinel — Plugin Moodle para evaluación por competencias

[![Moodle 4.5+](https://img.shields.io/badge/Moodle-4.5%2B-orange)](https://moodle.org)
[![PHP 8.1+](https://img.shields.io/badge/PHP-8.1%2B-blue)](https://php.net)
[![Licencia GPLv3](https://img.shields.io/badge/licencia-GPLv3-green)](LICENSE)
[![Estado: Alpha](https://img.shields.io/badge/estado-Alpha-yellow)]()

**Sentinel** es un plugin local para Moodle que conecta las rúbricas de evaluación con el libro de calificaciones por competencias. Permite al profesorado asignar cada criterio de una rúbrica a una competencia específica del currículo, sincronizar las puntuaciones automáticamente y visualizar toda la información de forma integrada en la interfaz habitual de Moodle, sin necesidad de herramientas externas.

Desarrollado como Trabajo de Fin de Grado (TFG) y propuesto para su despliegue en **EducaMadrid**.

---

## Índice

1. [Características](#características)
2. [Requisitos del sistema](#requisitos-del-sistema)
3. [Instalación](#instalación)
4. [Configuración](#configuración)
5. [Guía de uso](#guía-de-uso)
6. [Arquitectura técnica](#arquitectura-técnica)
7. [Seguridad](#seguridad)
8. [Privacidad y RGPD](#privacidad-y-rgpd)
9. [Compatibilidad de base de datos](#compatibilidad-de-base-de-datos)
10. [Desarrollo](#desarrollo)
11. [Licencia](#licencia)

---

## Características

### Evaluación por competencias con rúbricas

- **Asignación criterio–competencia**: en la página de edición de la rúbrica aparece un selector desplegable en cada criterio para asociarlo a una categoría del libro de calificaciones (competencia específica). Los cambios se guardan en tiempo real vía AJAX.
- **Columna CE en la rúbrica**: en la vista de calificación y en la previsualización, se añade automáticamente una columna extra con la competencia asignada a cada criterio, facilitando la comprensión al profesorado.
- **Soporte para borradores**: si se añaden criterios nuevos antes de guardar la rúbrica, las asignaciones se conservan en `localStorage` y se aplican automáticamente en el siguiente guardado.
- **Coloreado semafórico de niveles**: las celdas de la rúbrica se colorean de rojo (puntuación mínima) a verde (puntuación máxima), facilitando la lectura visual de la calificación durante la evaluación.
- **Orden de competencias igual al libro de calificaciones**: el desplegable sigue el mismo orden visual que el libro de calificaciones (recorrido en profundidad del árbol de categorías), evitando confusiones entre la herramienta y el gradebook.

### Sincronización automática de notas

- Un **observador de eventos** (`grade_item_created`, `grade_item_updated`) detecta cuándo se crea o actualiza una calificación de tipo rúbrica y registra o actualiza la nota en la categoría de competencia correspondiente del libro de calificaciones.
- Una **tarea CLI** (`cli/sync_grades.php`) permite resincronizar manualmente todos los alumnos de un curso, útil para correcciones masivas o tras importar datos.
- La sincronización toma siempre el **nivel más reciente** de cada criterio (usando `MAX(timemodified)`) para evitar duplicidades por múltiples intentos de evaluación.

### Fotos de alumnos

- El profesorado puede **subir, sustituir y eliminar** una fotografía identificativa para cada alumno. Las fotos se almacenan en el sistema de ficheros de Moodle (`local_sentinel`) con validación MIME en servidor (`finfo`), limitadas a 5 MB y a formatos de imagen.
- Las fotos aparecen de forma integrada en:
  - **Página de calificación de tarea** (`mod/assign`): miniatura junto al nombre del alumno en el panel de evaluación.
  - **Informe del calificador**: columna de fotos con opción de mostrar/ocultar mediante botón flotante persistente.
  - **Lista de participantes**: miniatura junto a cada nombre.
  - **Perfil de usuario y páginas de informe individual**: foto ampliada con vista previa al pasar el cursor.
- La foto ampliable se abre en un **lightbox** inline sin dependencias externas (clic sobre la imagen).
- Se muestra un **badge de nota final del curso** junto a la foto en el perfil del alumno y en la página de calificación.

### Informe del calificador mejorado

- **Clic directo al calificador**: al hacer clic en una celda de nota de una tarea con rúbrica, navega directamente a la página de calificación de ese alumno (evita el flujo habitual de varios clics).
- **Tooltip con resumen de rúbrica**: al mantener el cursor sobre una celda, muestra los criterios y el nivel seleccionado de la última evaluación (consulta AJAX bajo demanda, con caché local).
- **Botón de abreviación de columnas**: botón flotante y arrastrable que alterna entre nombres completos y nombres abreviados en las cabeceras del informe, con persistencia en `localStorage`.

### Interfaz general

- **Menú lateral persistente**: recuerda el estado abierto/cerrado del cajón de navegación izquierdo entre páginas.
- **Popout de rúbrica mejorado**: cuando el profesorado expande la rúbrica a pantalla completa, el panel ocupa toda la altura útil (desde el encabezado del alumno hasta la barra de botones de guardar).

---

## Requisitos del sistema

| Componente | Versión mínima |
|---|---|
| Moodle | 4.5 |
| PHP | 8.1 |
| MariaDB | 10.6 |
| MySQL | 8.0 |
| PostgreSQL | 14 |
| Extensión PHP `fileinfo` | Cualquier versión |

El plugin utiliza el **sistema de hooks de Moodle 4.5** (`core\hook\output\before_footer_html_generation`). No es compatible con versiones anteriores a Moodle 4.5.

---

## Instalación

### Método 1 — Copia manual

1. Copiar la carpeta `sentinel` al directorio `local/` de la instalación de Moodle:

   ```
   /path/to/moodle/local/sentinel/
   ```

2. Acceder como administrador a **Administración del sitio → Notificaciones**.

3. Moodle detectará el plugin y ejecutará automáticamente el script de instalación (`db/install.xml` + `db/upgrade.php`), creando las tablas necesarias.

4. Una vez instalado, ir a **Administración del sitio → Plugins → Plugins locales → Sentinel** para revisar y activar las características deseadas.

### Método 2 — CLI

```bash
php admin/cli/upgrade.php
```

### Verificar la instalación

Tras la instalación deben existir dos tablas en la base de datos:

- `mdl_local_sentinel_map` — mapeo criterio de rúbrica ↔ categoría de competencia.
- `mdl_local_sentinel_student_photo` — registros de fotos de alumnos.

Y un área de ficheros `local_sentinel` en `moodledata/filedir/`.

---

## Configuración

Los ajustes de Sentinel están en **Administración del sitio → Plugins → Plugins locales → Sentinel**.

| Ajuste | Descripción | Por defecto |
|---|---|---|
| **Fotos de alumnos** | Activa todo el sistema de fotos identificativas | Sí |
| **Columna de competencia en rúbrica** | Muestra la columna CE en la vista de rúbrica | Sí |
| **Colores en celdas de rúbrica** | Colorea las celdas de rojo a verde según puntuación | Sí |
| **Clic directo al calificador** | Clic en celda del grader report abre la página de calificación | Sí |
| **Botón de abreviación de columnas** | Botón flotante para abreviar cabeceras en el grader report | Sí |
| **Mantener estado del menú lateral** | Recuerda si el menú estaba abierto o cerrado | Sí |

### Capacidades (roles)

| Capacidad | Descripción | Roles por defecto |
|---|---|---|
| `local/sentinel:managephotos` | Subir, sustituir y eliminar fotos de alumnos | Manager, Profesor con edición |

Los profesores sin permiso de edición y los alumnos tienen acceso `PREVENT` a esta capacidad. Los administradores pueden cambiar estos permisos en **Administración del sitio → Usuarios → Permisos → Definir roles**.

---

## Guía de uso

### Para el profesorado

#### Asociar criterios de rúbrica a competencias

1. Acceder a la tarea de Moodle con la rúbrica y editar su definición (**Acciones → Definir rúbrica** o el lápiz en la tarea).
2. En cada fila de criterio aparece un desplegable **Competencia Específica (Sentinel)** con las categorías del libro de calificaciones del curso.
3. Seleccionar la competencia. El cambio se guarda automáticamente (sin recargar la página). El icono ✅ confirma el guardado correcto.
4. Si se añaden criterios nuevos, seleccionar la competencia antes de guardar la rúbrica: la asignación se aplica automáticamente al publicar.

#### Calificar con rúbrica

- Al evaluar, cada celda de la rúbrica muestra la competencia asociada en la columna derecha (CE Sentinel).
- Las celdas se colorean: verde = puntuación máxima, rojo = puntuación mínima.
- Al guardar la calificación, Sentinel actualiza automáticamente las notas en las categorías de competencia del libro de calificaciones.

#### Subir fotos de alumnos

- En la página de calificación de tarea, pasar el cursor sobre el círculo de foto junto al nombre del alumno y hacer clic para subir una imagen.
- En la lista de participantes, el mismo widget aparece junto a cada alumno.
- Para eliminar, hacer clic en la × roja que aparece en la esquina del círculo de foto.

#### Informe del calificador

- Al pasar el cursor sobre una celda de nota de una tarea con rúbrica, aparece un tooltip con el resumen de criterios y niveles.
- Al hacer clic, se navega directamente a la página de calificación de ese alumno.
- El botón flotante **✂️ Abreviar** / **↔️ Nombres completos** permite compactar las cabeceras para ver más columnas.

### Para el administrador

- Activar o desactivar cada característica desde **Administración del sitio → Plugins → Plugins locales → Sentinel**.
- Resincronizar manualmente las notas de un curso con la tarea CLI:

  ```bash
  php local/sentinel/cli/sync_grades.php --courseid=<ID_DEL_CURSO>
  ```

---

## Arquitectura técnica

```
local/sentinel/
├── amd/
│   ├── src/
│   │   ├── rubric_editor.js     # Editor/vista de rúbrica (AMD source)
│   │   └── student_photo.js     # Widget de fotos (AMD source)
│   └── build/
│       ├── rubric_editor.min.js # AMD minificado (producción)
│       └── student_photo.min.js
├── classes/
│   ├── hook/
│   │   └── before_footer.php    # Inyección de JS según página (hook Moodle 4.5)
│   ├── observer.php             # Listener de grade_item_created/updated
│   └── privacy/
│       └── provider.php         # Privacy API (RGPD)
├── cli/
│   └── sync_grades.php          # Resincronización manual de notas
├── db/
│   ├── access.php               # Capacidades del plugin
│   ├── events.php               # Registro de observadores de eventos
│   ├── hooks.php                # Registro del hook before_footer
│   ├── install.xml              # Esquema inicial de BD (XMLDB)
│   └── upgrade.php              # Pasos de actualización incremental
├── lang/
│   ├── en/local_sentinel.php    # Cadenas en inglés
│   └── es/local_sentinel.php    # Cadenas en español
├── pix/
│   └── icon.svg                 # Icono del plugin
├── ajax.php                     # Endpoint AJAX (map, rubric_summary)
├── photo.php                    # Endpoint de fotos (serve, upload, delete)
├── settings.php                 # Página de ajustes de administración
└── version.php                  # Metadatos del plugin
```

### Flujo de datos

```
Profesor evalúa con rúbrica
         │
         ▼
   gradingform_rubric
         │ grade_item_created / grade_item_updated
         ▼
   classes/observer.php
         │ busca mapping en local_sentinel_map
         ▼
   grade_grades (libro de calificaciones)
         │
         ▼
   Nota visible en categoría de competencia
```

### Módulos AMD

Los módulos AMD se cargan a través del hook `before_footer_html_generation`, que comprueba la URL actual y decide qué funcionalidad inyectar. Esto garantiza que el JS solo se carga en las páginas que lo necesitan.

---

## Seguridad

### Control de acceso

- **Endpoint `ajax.php`**: comprueba `require_login()` y `sesskey()` en todas las acciones. La acción `map` verifica `moodle/grade:manage` en el contexto del curso. La acción `rubric_summary` verifica `mod/assign:grade` en el contexto del módulo.
- **Endpoint `photo.php`**:
  - `serve`: verifica que el solicitante comparte al menos un curso con el alumno y tiene capacidad `mod/assign:grade` en ese curso.
  - `upload` / `delete`: requiere la capacidad `local/sentinel:managephotos` a nivel de contexto de sistema.
- Todas las operaciones de escritura validan el `sesskey` de Moodle para prevenir CSRF.

### Validación de ficheros

- Los ficheros de foto se validan en servidor con `finfo(FILEINFO_MIME_TYPE)`: solo se aceptan `image/jpeg`, `image/png`, `image/gif` y `image/webp`.
- Tamaño máximo: **5 MB** por foto.
- Las fotos se almacenan en el sistema de ficheros de Moodle (`get_file_storage()`), nunca en rutas accesibles directamente desde el servidor web.

### Prevención de inyección

- Todo el SQL usa parámetros con placeholder (API de `$DB`): no hay concatenación de cadenas en consultas.
- Las cadenas de texto mostradas en HTML se escapan mediante `json_encode` al pasarlas a JavaScript o con `s()` en PHP.

---

## Privacidad y RGPD

Sentinel implementa la **Privacy API de Moodle** completa (`\core_privacy\local\metadata\provider`, `\core_privacy\local\request\plugin\provider`, `\core_privacy\local\request\core_userlist_provider`).

### Datos almacenados

| Dato | Tabla | Descripción |
|---|---|---|
| `studentid` | `local_sentinel_student_photo` | ID del alumno fotografiado |
| `uploaderid` | `local_sentinel_student_photo` | ID del profesor que subió la foto |
| `timemodified` | `local_sentinel_student_photo` | Fecha de última modificación |
| Fichero de imagen | `core_files` (filearea `studentphoto`) | Imagen en el filestore de Moodle |

### Operaciones de datos

- **Exportación**: al solicitar la exportación de datos de un usuario, Sentinel exporta su foto (si la tiene) y las fotos que subió como profesor.
- **Eliminación de alumno**: elimina la foto del alumno del filestore. Si el alumno también fue subidor de otras fotos (en rol de profesor), su `uploaderid` se anonimiza a `0` para no eliminar fotos de otros alumnos.
- **Eliminación de profesor**: anonimiza las referencias como subidor (`uploaderid → 0`).
- **Listado de usuarios en contexto**: identifica tanto a alumnos fotografiados como a profesores subidores.

---

## Compatibilidad de base de datos

El plugin está diseñado para ser compatible con **MySQL, MariaDB y PostgreSQL**:

- Toda la DDL usa la **API XMLDB** de Moodle: no hay SQL nativo de creación de tablas.
- Todo el SQL en `get_records_sql` y similares usa **sintaxis ANSI estándar**: JOINs explícitos, `COALESCE`, subconsultas con `MAX()`.
- No se usa ninguna función específica de MySQL (`IF`, `IFNULL`, `GROUP_CONCAT`, identificadores con backtick).
- Los índices y claves únicas se añaden también vía XMLDB (`xmldb_index`), garantizando la sintaxis correcta en cada motor.

### Índices definidos

| Tabla | Índice | Tipo | Propósito |
|---|---|---|---|
| `local_sentinel_map` | `uq_rubric_row_id` | UNIQUE | Garantiza mapeo 1:1 criterio ↔ categoría |
| `local_sentinel_map` | `ix_grade_item_id` | INDEX | Acelera JOINs en el grader report |
| `local_sentinel_student_photo` | `uq_studentid` | UNIQUE | Una foto por alumno |
| `local_sentinel_student_photo` | `ix_uploaderid` | INDEX | Acelera consultas de la Privacy API |

---

## Desarrollo

### Requisitos de desarrollo

- XAMPP / LAMP / LEMP con Moodle 4.5+
- Node.js y npm (para compilar AMD con Grunt)
- PHP_CodeSniffer con el estándar Moodle

### Compilar módulos AMD

```bash
# Desde la raíz de Moodle
npm install
npx grunt amd --root=local/sentinel
```

### Ejecutar el linter PHP

```bash
composer require moodlehq/moodle-cs
vendor/bin/phpcs --standard=moodle local/sentinel/
```

### Resincronizar notas manualmente

```bash
php local/sentinel/cli/sync_grades.php --courseid=5
```

### Historial de versiones

| Versión | Fecha | Cambios principales |
|---|---|---|
| 0.6.0 | 2024-01 | Admin settings, icon, índices BD, Privacy API completa, i18n |
| 0.5.1 | 2024-01 | Colores semafóricos, árbol de competencias, fotos en grader report |
| 0.4.0 | 2024-01 | Sistema de fotos de alumnos, badge de nota final |
| 0.3.0 | 2024-01 | Click-to-grade, tooltip de rúbrica, popout mejorado |
| 0.2.0 | 2024-01 | Columna CE en vista de rúbrica, ordenación por gradebook |
| 0.1.0 | 2024-01 | Versión inicial: editor de rúbrica + sincronización de notas |

---

## Licencia

Este plugin se distribuye bajo la licencia **GNU General Public License v3.0 o posterior**.

```
Copyright (C) 2024  Marcos Domínguez Alonso

This program is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 3 of the License, or
(at your option) any later version.
```

Consulta el fichero [LICENSE](https://www.gnu.org/licenses/gpl-3.0.html) para más detalles.

---

*Desarrollado como TFG y propuesto para despliegue en EducaMadrid.*

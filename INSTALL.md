# Guía de instalación — Plugin local_sentinel

Instrucciones para desplegar el entorno completo (Moodle 4.5 + plugin Sentinel) en un servidor nuevo.

---

## Requisitos

| Componente | Versión mínima |
|------------|----------------|
| PHP | 8.1 |
| MariaDB / MySQL | 10.6 / 8.0 |
| Moodle | 4.5 |
| Servidor web | Apache 2.4 o nginx |

En local: **XAMPP** (Windows) con MariaDB en puerto 3307.

---

## 1. Instalar Moodle 4.5

1. Descarga Moodle 4.5 desde [moodle.org/downloads](https://moodle.org/downloads/)
2. Descomprime en la carpeta web (`htdocs/moodle` en XAMPP, `/var/www/html/moodle` en Linux)
3. Crea la base de datos:
   ```sql
   CREATE DATABASE moodle45 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   CREATE USER 'moodle'@'localhost' IDENTIFIED BY 'tu_password';
   GRANT ALL ON moodle45.* TO 'moodle'@'localhost';
   ```
4. Crea la carpeta `moodledata` **fuera** de la carpeta web:
   ```
   C:\xampp\moodledata\          (Windows)
   /var/moodledata/              (Linux)
   ```
5. Accede a `http://localhost/moodle` y sigue el instalador web

---

## 2. Instalar el plugin Sentinel

### Opción A — Desde este repositorio (recomendada)

```bash
cd /ruta/a/moodle/local/
git clone https://github.com/marcosdzal/local_sentinel.git sentinel
```

### Opción B — Copia manual

Copia la carpeta `sentinel` dentro de `moodle/local/` de modo que quede:
```
moodle/
└── local/
    └── sentinel/
        ├── version.php
        ├── db/
        └── ...
```

### Activar el plugin

Accede como administrador a Moodle → **Administración del sitio → Notificaciones** → el instalador detecta el plugin y ejecuta las migraciones de BD automáticamente.

---

## 3. Restaurar los datos de prueba (entorno de demo)

> Solo necesario si quieres tener los mismos datos que el entorno de desarrollo.

### Si restauras la BD completa (recomendado para demo exacta)

```bash
# Linux/Mac
mysql -u root -p moodle45 < backup/moodle45_dump.sql

# Windows (HeidiSQL)
# Herramientas → Importar archivo SQL → selecciona backup/moodle45_dump.sql
```

⚠️ Esto sobreescribe toda la BD. Hazlo sobre una instalación de Moodle **recién creada**, antes del paso de instalación web (el dump ya incluye las tablas de Moodle).

### Después de restaurar

1. Edita `moodle/config.php` con las credenciales de tu BD local
2. Actualiza la URL del sitio en Moodle:
   ```sql
   UPDATE mdl_config SET value='http://TU_SERVIDOR/moodle' WHERE name='wwwroot';
   ```
   O desde la línea de comandos:
   ```bash
   php admin/cli/cfg.php --name=wwwroot --set=http://TU_SERVIDOR/moodle
   ```
3. Borra las cachés:
   ```bash
   php admin/cli/purge_caches.php
   ```

---

## 4. Configuración del plugin Sentinel

Una vez instalado, en Moodle:

1. **Administración del sitio → Plugins → Plugins locales → Sentinel**
2. Configura las preferencias generales

### Mapear rúbricas a descriptores

1. En cada curso, ve a **Calificaciones → Sentinel → Mapear rúbrica**
2. Asocia cada criterio de rúbrica al descriptor del perfil de salida correspondiente
3. Los descriptores se cargan desde `data/descriptores_perfil_salida.json`

### Crear períodos de comunicación

- **Global** (para todos los cursos): Administración del sitio → Sentinel → Períodos
- **Por curso**: desde el botón "Comunicar" en el informe del calificador

---

## 5. Credenciales del entorno de demo

| Usuario | Contraseña | Rol |
|---------|------------|-----|
| `admin` | (la que pusiste al instalar) | Administrador |
| `bach` | `Alumno1234!` | Estudiante |
| `debussy` | `Alumno1234!` | Estudiante |

---

## 6. Despliegue en la nube

Para desplegar en un VPS o servidor del centro:

1. Instala LAMP/LEMP (Linux + Apache/nginx + MariaDB + PHP 8.1+)
2. Sigue los pasos 1–4 anteriores
3. Configura el dominio en `config.php` (`$CFG->wwwroot`)
4. Ajusta permisos:
   ```bash
   chown -R www-data:www-data /var/www/html/moodle
   chown -R www-data:www-data /var/moodledata
   ```
5. Configura HTTPS (Let's Encrypt recomendado)

---

## Estructura del plugin

```
local/sentinel/
├── admin/              Páginas de administración (mapeo, períodos)
├── amd/                Módulos JavaScript (AMD)
│   ├── src/            Fuentes JS
│   └── build/          JS compilados (generados por grunt)
├── backup/             Dumps de BD para demo
├── classes/            Clases PHP
│   ├── hook/           Inyección de UI en Moodle
│   └── descriptor_calculator.php
├── cli/                Scripts de línea de comandos
├── data/               JSON con descriptores LOMLOE
├── db/                 Definición de BD e instalación
├── lang/               Cadenas de texto (es/en)
├── ajax.php            Endpoint AJAX
├── photo.php           Servicio de fotos de alumnos
├── student_report.php  Informe de alumno por descriptores
└── version.php         Versión del plugin
```

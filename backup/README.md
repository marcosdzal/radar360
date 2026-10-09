# Backup de base de datos

Este directorio contiene el volcado SQL de la base de datos Moodle con los datos de prueba del plugin Sentinel.

## Archivo

- `moodle45_dump.sql` — dump completo de la BD `moodle45` (generado desde HeidiSQL)

## Cómo regenerar el dump

1. Abre HeidiSQL y conéctate a la BD
2. Menú `Herramientas` → `Exportar base de datos como SQL`
3. Selecciona `moodle45` y guarda aquí como `moodle45_dump.sql`
4. Haz commit y push

## Cómo restaurar

Ver `../INSTALL.md` para instrucciones completas de instalación.

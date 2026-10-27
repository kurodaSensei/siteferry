# Siteferry

Exporta, importa y hace buscar/reemplazar en la base de datos de WordPress, seguro para datos serializados y JSON.

- **Herramientas → Siteferry** en el admin.
- WP-CLI: `wp siteferry export|replace|import|pull|push|undo|connection` (ver `wp help siteferry`).
- Pull/push entre sitios: en el sitio remoto activa los permisos y copia su información de conexión
  (`wp siteferry connection --allow-pull=on --allow-pull-files=on`); en este, `wp siteferry pull <url> <clave>`.
- Archivos: `--media --themes --plugins --mu-plugins` (y `--delete`, `--files-only`). Solo viaja lo que cambió;
  temas y plugins se cambian por carpeta completa. Permisos separados: pull de archivos y push de código.
- Sitio completo: `wp siteferry export --media --themes --plugins --output=sitio.sfy` arma un paquete `.sfy`
  (BD + archivos) que se importa en otro sitio con `wp siteferry import sitio.sfy` o desde la interfaz. Importar no
  borra media ni plugins/temas que el paquete no trae; dentro de cada plugin o tema recibido, el código queda igual al de origen.
- Deshacer: la última operación que modificó el sitio (reemplazo, importación, pull o push recibido) se revierte con
  la tarjeta «Deshacer» o `wp siteferry undo`: vuelve el backup de la BD y los archivos reemplazados (lo nuevo se aparta a `undone/`).
  Mientras existan los backups (7 días).
- Zip instalable: `bin/build.sh` → `dist/siteferry-<versión>.zip`, desde el último commit y sin `vendor/` ni tests.
- En nginx, protege la carpeta de trabajo: `location ~* /uploads/siteferry- { deny all; }`.

## Desarrollo

Todo corre en Docker con una imagen de PHP 8.3 + Composer (`php-dev` en los ejemplos):

```bash
docker run --rm -v "$PWD":/app -w /app php-dev composer install
docker run --rm -v "$PWD":/app -w /app php-dev vendor/bin/phpcs
```

Las pruebas de integración necesitan un MySQL/MariaDB desechable:

```bash
docker network create siteferry-test
docker run -d --rm --name siteferry-mysql --network siteferry-test -e MYSQL_ROOT_PASSWORD=root mysql:8.0
docker run --rm --network siteferry-test -e SITEFERRY_TEST_DB_HOST=siteferry-mysql -v "$PWD":/app -w /app php-dev vendor/bin/phpunit
```

Sin `SITEFERRY_TEST_DB_HOST` solo corren las unitarias. Antes de cerrar una fase, repetir con `mariadb:10.6`.

`tests/fixtures/mysqldump-wp.sql` es un dump real de `mysqldump` 8.0 generado desde `tests/fixtures/seed-wp.sql` (con vista, trigger y llave foránea).

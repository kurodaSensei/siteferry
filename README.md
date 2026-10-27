# Siteferry

[![CI](https://github.com/kurodaSensei/siteferry/actions/workflows/ci.yml/badge.svg)](https://github.com/kurodaSensei/siteferry/actions/workflows/ci.yml)

[Español](README.es.md)

Siteferry is a WordPress migration plugin. It exports, imports and runs search-replace on the database without breaking serialized data or JSON. It also pulls and pushes databases and files between two sites over a signed connection, packs a whole site into one `.sfy` file, and can undo the last operation.

It works from **Tools → Siteferry** in the admin and from WP-CLI (`wp siteferry`). It is pure PHP 8.1 and has no runtime dependencies.

## Features

- **Serialized-safe search-replace.** Serialized PHP is processed as text and every string length is recalculated. Nothing is ever unserialized, so no objects are instantiated, and unknown classes, floats and references come out exactly as they went in. JSON values, including escaped slashes, are replaced as well. Regular expressions (PCRE) and case-sensitive matching are optional.
- **Export** the database to `.sql.gz`, with or without replacements for the destination.
- **Import** a dump from mysqldump, phpMyAdmin or Siteferry. The table prefix and the source URL are detected automatically. If a dump holds several WordPress installs, Siteferry asks which prefix to use instead of guessing. Views, triggers and procedures are skipped and reported in the log.
- **Pull and push** the database between two sites. Files can travel too: media, themes, plugins and mu-plugins. Only files that changed are sent, and themes and plugins are swapped as whole folders at the end.
- **Full-site packages.** One `.sfy` file holds the database and the selected folders. You import it from the admin or with `wp siteferry import`.
- **Undo.** The last operation that changed the site can be reverted: a search-replace, an import, a pull, or a push this site received. The pre-operation database backup is restored, and files that were replaced come back. New files are moved aside to `undone/` instead of being deleted. Backups are kept for 7 days.
- **Resumable jobs.** Work runs in short steps, one per request, with the cursor stored on the server. A timeout, a reload or a dropped connection does not lose progress: you resume where it stopped.

## Requirements

- WordPress 6.0 or later. Multisite is not supported yet.
- PHP 8.1 or later, with the `mysqli` extension that WordPress uses.
- MySQL 8.0 or MariaDB 10.6, the versions the test suite runs on. Older versions may work but are untested.

## Installation

1. Build the installable zip from a checkout: `bin/build.sh` writes `dist/siteferry-<version>.zip`. It contains the last commit, without tests or dev tooling.
2. In WordPress, go to **Plugins → Add New → Upload Plugin** and upload the zip, then activate it.
3. On nginx, deny direct access to the working folder (Apache already gets an `.htaccess`):

   ```nginx
   location ~* /uploads/siteferry- { deny all; }
   ```

## Usage

### In the admin

**Tools → Siteferry** offers Export, Search and replace, Import, Pull and Push. You add search/replace pairs, choose the tables, and click **Start**. Progress, the log and download links appear on the same page. The **Undo** card shows what the last operation would restore.

### WP-CLI

```bash
# Export with a replacement, or a full-site package
wp siteferry export https://example.com https://example.test --output=site.sql.gz
wp siteferry export --media --themes --plugins --output=site.sfy

# Search-replace on this site (a backup is taken first)
wp siteferry replace https://old.example https://new.example

# Import a dump or a package (the source URL is replaced automatically)
wp siteferry import site.sql.gz

# Undo the last operation that changed this site
wp siteferry undo
```

Run `wp help siteferry <command>` for every option: `--tables`, `--regex`, `--case-sensitive`, `--guids`, `--no-backup`, `--no-auto-url`, `--source-prefix` and the rest.

### Connecting two sites

1. On the site that will be read from or written to, enable only the permissions you need. All of them are off by default.

   ```bash
   wp siteferry connection --allow-pull=on --allow-pull-files=on
   ```

   This prints the site's **connection info**: its URL and a 40-character key. Treat the key like a password. `--regenerate-key` invalidates it.

2. On the other site, pull or push with that URL and key:

   ```bash
   wp siteferry pull https://production.example <key> --media
   wp siteferry push https://staging.example <key> --themes --plugins --files-only
   ```

There are four separate permissions:

| Permission | What the other site can do |
|---|---|
| **pull** | read the database |
| **file pull** | read media, themes and plugins |
| **push** | replace the database |
| **code push** | replace themes and plugins |

`--delete` removes files on the destination that no longer exist on the source. Deleted files are moved to a 7-day backup instead.

## How it keeps a site safe

**Live tables are only touched once, at the end.** Imported and transferred data first lands in temporary tables: `_sfr_` for raw dumps and `_sft_` for new tables. Replacements run there. At the end, a single `RENAME TABLE` swaps them in. If anything fails before that, the site is unchanged, and **Cancel** removes the temporary data. A backup of the destination is taken first by default.

**Plugin state survives a database swap.** Siteferry's own `siteferry_*` options are never exported and are kept on the destination when its database is replaced. `blog_public` is kept as well, so a staging site stays hidden from search engines. The `siteferry_preserved_options` filter adds more options to keep.

**Communication between sites is signed and replay-proof.**
- Every request and response is signed with HMAC-SHA256 over the whole body. The body includes a timestamp (±5 minutes are tolerated) and a single-use nonce.
- A response carries its request's nonce, so it cannot be replayed as the answer to another request.
- Binary file packages are covered by their signed SHA-256.

**http is refused outside development.** Plain http is accepted only for development hosts: `localhost`, `.test`, `.local`, `.localhost`, `.internal`, and private IPs. Everywhere else the database would cross the network unencrypted, so https is required.

**A remote site cannot run arbitrary SQL.** Statements it sends may only create, fill or drop one temporary `_sft_` table. Literals and comments are checked before anything runs.

**Incoming files are checked before they are written.**
- Paths are normalized and confined to their root.
- Executables and server config files (`.php`, `.phtml`, `.htaccess`, `.user.ini`, …) are rejected inside media.
- Siteferry never overwrites itself.
- Caches, VCS folders and other plugins' backup folders are excluded.

**Interference from other plugins is avoided.** During migration steps, a small mu-plugin switches off the other plugins and the theme. It only acts on Siteferry's own REST routes, and only with a valid key or signature. Siteferry installs it and removes it again when deactivated.

**The working folder is protected.** Dumps and backups live in `wp-content/uploads/siteferry-<random>/` with `index.php` and `.htaccess` guards. Files are only downloaded through authenticated REST requests. Anything older than 7 days is removed by a daily cleanup.

## Hooks

| Hook | Type | Arguments |
|---|---|---|
| `siteferry_job_started` | action | `array $job` |
| `siteferry_job_completed` | action | `array $job` |
| `siteferry_job_failed` | action | `array $job` |
| `siteferry_preserved_options` | filter | `string[] $options` (default: `blog_public`) |

## Limitations

- Multisite networks are not supported yet.
- A `.sql.gz` upload is decompressed in a single request. For dumps of several GB on hosts with short request limits, upload the uncompressed `.sql`.
- Progress logs, step names and WP-CLI output are in English. The admin interface is translatable, and it ships with Spanish (`es_ES`).

## Development

You need PHP 8.1+ with `mysqli`, and Composer. Without them installed locally, Docker works with the official images.

```bash
composer install
composer lint   # PHPCS with WordPress Coding Standards
composer test   # PHPUnit (unit tests only, unless a test database is configured)
```

The integration tests create and drop throwaway databases. Point them at a disposable MySQL or MariaDB server through `SITEFERRY_TEST_DB_HOST`. `SITEFERRY_TEST_DB_USER` and `SITEFERRY_TEST_DB_PASS` are optional and default to `root`/`root`.

```bash
docker network create siteferry-dev
docker run -d --rm --name siteferry-db --network siteferry-dev -e MYSQL_ROOT_PASSWORD=root mysql:8.0
docker run --rm -v "$PWD":/app -w /app composer:2 composer install
docker run --rm --network siteferry-dev -e SITEFERRY_TEST_DB_HOST=siteferry-db -v "$PWD":/app -w /app \
  php:8.3-cli sh -c 'docker-php-ext-install mysqli >/dev/null && vendor/bin/phpunit'
```

Give the database server about 20 seconds to start before running the tests. Repeat the last command against `mariadb:10.6` (`-e MARIADB_ROOT_PASSWORD=root`) before you send a change.

The `tests/fixtures/mysqldump-wp.sql` fixture is a real mysqldump 8.0 dump of `tests/fixtures/seed-wp.sql`, which contains a view, a trigger and a foreign key.

Translations live in `languages/`. To refresh them, run WP-CLI from the plugin folder:

```bash
wp i18n make-pot . languages/siteferry.pot --domain=siteferry --exclude=vendor,tests,dist,bin,languages
wp i18n make-mo languages/siteferry-es_ES.po languages
wp i18n make-json languages/siteferry-es_ES.po languages --no-purge
```

### Project layout

```text
siteferry.php        Plugin header and autoloader
src/Engine/          Search-replace, SQL reading/writing, table copy and swap (no WordPress dependency)
src/Files/           File scanning, diffs, packages and path guards
src/Job/             Job planner, step runner and storage
src/Remote/          Signed client/server protocol between sites
src/Http/            REST routes for the admin UI and for other sites
src/Admin/, assets/  Tools → Siteferry page and its script
src/Cli/             WP-CLI command
mu-plugin/           Compatibility mu-plugin used during migration steps
tests/               PHPUnit unit and integration tests
```

## License

[GPL-2.0-or-later](LICENSE). Siteferry is an independent project, written from scratch, and is not affiliated with any other migration plugin.

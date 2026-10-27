# Changelog

All notable changes to Siteferry. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and versions follow [Semantic Versioning](https://semver.org/).

## [0.5.0] - 2026-10-27

### Added

- Spanish (`es_ES`) translation. The admin interface is translatable, and the script uses `wp.i18n`.
- `uninstall.php`: deleting the plugin removes its options, temporary tables and working folder.
- GitHub Actions CI: PHPCS, and PHPUnit on PHP 8.1 and 8.3 with MySQL 8.0 and MariaDB 10.6.
- WordPress.org `readme.txt`, the GPL-2.0-or-later license file, and READMEs in English and Spanish.

### Changed

- Source code, comments, WP-CLI help and log messages are in English.
- Admin assets are now `assets/siteferry.js` and `assets/siteferry.css`.

### Fixed

- Repeating an import step after a cut-off request failed with "Duplicate entry", or silently duplicated rows in tables without a primary key. The load now resumes where it stopped.
- The same problem in copy, pull, export and backup steps, which could duplicate rows or dump statements. Also fixed: a repeated finalize step failed after the tables had already been swapped.

## [0.4.0] - 2026-10-27

- First public release.

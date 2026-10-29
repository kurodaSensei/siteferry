# Changelog

All notable changes to Siteferry. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and versions follow [Semantic Versioning](https://semver.org/).

## [0.6.0] - 2026-10-28

### Added

- New admin screens: a home screen with the operations and this site's state, a pull/push wizard (connect, choose, review, transfer), a progress screen with per-folder bars and a measured speed, a result screen with what changed, undo and the backup, and a connection screen with a masked key and permission switches.
- The step loop keeps running while you move between Siteferry screens, with a floating button back to the operation.
- File review for pulls and pushes: the comparison shows new, changed and destination-only files before anything moves, and you can bring only new files. WP-CLI: `--only-new`.
- A pull or push pauses instead of failing when the other site stops answering, and resumes from where it stopped. WP-CLI retries a paused job 5 times, every 30 seconds.
- While files transfer, the progress shows files and megabytes for the folder in transit, and each folder logs a line when it finishes.
- IBM Plex Sans is bundled (SIL OFL 1.1): the admin page loads nothing from other servers.
- Spanish (`es_ES`) translation. The admin interface is translatable, and the script uses `wp.i18n`.
- `uninstall.php`: deleting the plugin removes its options, temporary tables and working folder.
- GitHub Actions CI: PHPCS, and PHPUnit on PHP 8.1 and 8.3 with MySQL 8.0 and MariaDB 10.6.
- WordPress.org `readme.txt`, the GPL-2.0-or-later license file, READMEs in English and Spanish, and the Harbor brand (logo, icon and banners).

### Changed

- Source code, comments, WP-CLI help and log messages are in English.
- Admin assets are now `assets/siteferry.js` and `assets/siteferry.css`.
- Confirmations and messages use the admin page's own dialog instead of the browser's alert and confirm boxes.

### Fixed

- When the two sites' clocks differ by more than 5 minutes (for example after the computer slept), the request is signed again once with the other site's time instead of failing.
- A pull now finishes in the request that swaps the tables, so the folder swaps no longer fail with "cookie check failed" once this site's users are replaced. A lost session gets a notice that says to log in again and resume.
- A push to a site whose web server limits the request size (HTTP 413, such as nginx's `client_max_body_size`) sends smaller requests instead of failing. If even the smallest is refused, it pauses and says which limit to raise.
- A PHP notice printed on the other site (with `display_errors` on) no longer breaks a pull or push with "Invalid response from the remote site (HTTP 200)". That error now also shows the start of what the other site answered.
- An incoming push no longer replaces a paused job or one waiting for its review on the receiving site.
- A pushed chunk cut off on the receiving site after part of it was applied is completed instead of applied twice.
- Importing a file larger than one upload chunk failed on the second chunk.
- Repeating an import step after a cut-off request failed with "Duplicate entry", or silently duplicated rows in tables without a primary key. The load now resumes where it stopped.
- The same problem in copy, pull, export and backup steps, which could duplicate rows or dump statements. Also fixed: a repeated finalize step failed after the tables had already been swapped.

## [0.4.0] - 2026-10-27

- First public release.

=== Siteferry ===
Contributors: kurodasensei
Tags: migration, search replace, database, backup, wp-cli
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.6.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Export, import and search-replace the database safely across serialized data, and move databases and files between your sites.

== Description ==

Siteferry moves WordPress sites. It exports, imports and runs search-replace on the database without breaking serialized data or JSON. It also pulls and pushes databases and files between two of your sites over a signed connection, packs a whole site into one file, and can undo the last operation.

Use it from **Tools → Siteferry** or from WP-CLI with `wp siteferry`.

= Features =

* **Serialized-safe search-replace.** Serialized PHP is processed as text and every string length is recalculated. Nothing is unserialized, so no objects are instantiated and unknown classes stay intact. JSON values are replaced too. Regular expressions and case-sensitive matching are optional.
* **Export** the database to a `.sql.gz` file, with optional replacements for the destination site.
* **Import** a dump from mysqldump, phpMyAdmin or Siteferry. The table prefix and the source URL are detected automatically.
* **Pull and push** the database between two sites, in a guided wizard. Media, themes, plugins and mu-plugins can travel too, and only files that changed are sent.
* **Review before transferring.** See how many files are new, changed or only on the destination, then bring them all or only the new ones.
* **Full-site packages.** One `.sfy` file holds the database and the folders you choose, ready to import on another site.
* **Undo.** Revert the last search-replace, import, pull or received push. The database backup and the replaced files are restored. Backups are kept for 7 days.
* **Resumable jobs.** Work runs in short steps, so timeouts and reloads do not lose progress. A pull or push pauses when the other site stops answering, and you can move to other Siteferry screens while it runs.
* **WP-CLI.** Commands: `export`, `replace`, `import`, `pull`, `push`, `undo` and `connection`.

= Safety =

* Imported and transferred data goes into temporary tables first. The live tables are swapped in with a single `RENAME TABLE` at the end. If something fails before that, your site is unchanged.
* A backup of the destination database is taken first by default.
* Siteferry's own settings and `blog_public` are kept on the destination when its database is replaced.

= Connections to other sites =

Siteferry only talks to sites that you connect it to yourself. To connect, you paste the other site's URL and its connection key. It sends no data to the plugin author or to any third-party service.

* All permissions are off by default: pull, file pull, push and code push. You enable only what each site needs.
* Every request and response between two sites is signed with HMAC-SHA256. Each one carries a timestamp and a single-use nonce, so it cannot be replayed.
* Plain http is only accepted for development hosts, such as `localhost`, `.test` or private IPs. Everywhere else, https is required.
* A remote site can only create, fill and drop Siteferry's own temporary tables. Executable files are rejected inside media.

== Installation ==

1. Upload the `siteferry` folder to `/wp-content/plugins/`, or install the zip from **Plugins → Add New → Upload Plugin**.
2. Activate the plugin.
3. Go to **Tools → Siteferry**.
4. On nginx, deny direct access to the working folder with `location ~* /uploads/siteferry- { deny all; }`. On Apache, Siteferry protects it with an `.htaccess` file.

To pull from or push to another site, install Siteferry on both sites. On the site you want to read from or write to, enable the permissions you need on the **Connection** screen. Then paste its connection info on the other site.

== Frequently Asked Questions ==

= Why does Siteferry add a file to mu-plugins? =

During a migration step, other plugins and the theme could interfere with the database swap or slow it down. So Siteferry copies a small must-use plugin, `siteferry-compat.php`, that switches them off. It only does this for Siteferry's own REST requests that carry a valid key or signature; every other request is left alone. The file is removed when you deactivate Siteferry.

= Will search-replace break serialized data? =

No. Serialized values are rewritten as text and every string length is recalculated. They are never unserialized, so objects and unknown classes come out exactly as they went in.

= What happens if a migration fails halfway? =

Your live tables are only replaced in the final step. Before that, the work happens in temporary tables, and **Cancel** removes them. Jobs can also be resumed after a timeout or a page reload.

= Can I undo a migration? =

Yes. Use the **Undo** card in **Tools → Siteferry** or run `wp siteferry undo`. It reverts the last operation that changed the site, as long as its backup is still there (7 days).

= What does deleting the plugin remove? =

Deleting Siteferry from **Plugins** removes its settings and connection key, any temporary tables, and its working folder with dumps, backups and undo points. Deactivating it only removes the must-use plugin and the daily cleanup.

= Does it support multisite? =

Not yet.

= Is the interface translated? =

The admin interface is translatable and ships with a Spanish (es_ES) translation. Progress logs and WP-CLI output are in English.

= Does the admin page load fonts or scripts from other servers? =

No. The admin page uses IBM Plex Sans, bundled in `assets/fonts` under the SIL Open Font License 1.1 (see `assets/fonts/OFL.txt`), and WordPress's own scripts.

== Screenshots ==

1. Tools → Siteferry: the home screen with the operations, this site and the last operation.
2. A pull, step 2: the route between the two sites and what to transfer.
3. The file comparison to review before anything is transferred.
4. A pull in progress: phases, a bar per folder and the measured speed.
5. A finished pull: what changed, undo and the backup.
6. This site's connection info and what other sites may do.

== Changelog ==

= 0.6.0 =
* New: redesigned admin screens, with a pull/push wizard, a review of the file comparison, a progress screen you can leave while it runs, and a result screen with undo and the backup.
* New: bring only new files, and review the comparison before anything is transferred.
* New: a pull or push pauses when the other site stops answering and resumes from where it stopped.
* New: Spanish (es_ES) translation. The admin interface is translatable.
* New: deleting the plugin removes its options, temporary tables and working folder.
* Fix: a clock difference between the two sites no longer makes a pull fail.
* Fix: a pull no longer stops with "cookie check failed" after the tables are swapped.
* Fix: a push no longer fails with HTTP 413 when the other site's web server limits the request size; it sends smaller requests.
* Fix: a PHP notice on the other site no longer breaks a pull or push with "Invalid response (HTTP 200)".
* Fix: importing files larger than one upload chunk, and pushed chunks cut off on the receiving site.
* Fix: repeated or cut-off steps no longer duplicate rows or fail with "Duplicate entry".

= 0.4.0 =
* First public release.

<?php
/**
 * Admin page.
 *
 * @package Siteferry
 */

namespace Siteferry\Admin;

use Siteferry\Engine\Schema;
use Siteferry\Plugin;
use Siteferry\Support\Settings;
use Siteferry\Support\UndoPoint;

/**
 * Tools → Siteferry.
 */
final class Page {

	private const SLUG = 'siteferry';

	/**
	 * Registers the menu entry.
	 */
	public static function register(): void {
		$hook = add_management_page( 'Siteferry', 'Siteferry', 'manage_options', self::SLUG, array( self::class, 'render' ) );
		if ( $hook ) {
			add_action( 'load-' . $hook, array( self::class, 'load' ) );
		}
	}

	/**
	 * On page load: keep the mu-plugin current and enqueue assets.
	 */
	public static function load(): void {
		Plugin::install_compat();
		// File mtime in the version, so the browser never keeps a stale script.
		$version = static fn( string $file ) => SITEFERRY_VERSION . '.' . filemtime( SITEFERRY_PATH . $file );
		wp_enqueue_style( 'siteferry-admin', plugins_url( 'assets/siteferry.css', SITEFERRY_FILE ), array(), $version( 'assets/siteferry.css' ) );
		wp_enqueue_script( 'siteferry-admin', plugins_url( 'assets/siteferry.js', SITEFERRY_FILE ), array( 'wp-api-fetch', 'wp-i18n' ), $version( 'assets/siteferry.js' ), true );
		wp_set_script_translations( 'siteferry-admin', 'siteferry', SITEFERRY_PATH . 'languages' );
		wp_localize_script(
			'siteferry-admin',
			'siteferryData',
			array(
				'root'      => esc_url_raw( rest_url( 'siteferry/v1/' ) ),
				'nonce'     => wp_create_nonce( 'wp_rest' ),
				'compatKey' => (string) get_option( 'siteferry_compat_key' ),
				'home'      => home_url(),
				'maxChunk'  => max( 256 * 1024, min( 8 * 1024 * 1024, wp_max_upload_size() - 64 * 1024 ) ),
			)
		);
	}

	/**
	 * Renders the page. The form is plain HTML; the script only submits it and shows progress.
	 */
	public static function render(): void {
		global $wpdb;
		$tables   = ( new Schema( Plugin::db() ) )->stats( $wpdb->prefix );
		$home     = home_url();
		$settings = Settings::get() + array( 'connection' => Settings::connection() );
		$undo     = UndoPoint::get();
		?>
		<div class="wrap siteferry">
			<h1>Siteferry</h1>

			<form id="siteferry-form" class="siteferry-card">
				<fieldset class="siteferry-actions">
					<legend class="screen-reader-text"><?php esc_html_e( 'Action', 'siteferry' ); ?></legend>
					<label class="siteferry-action">
						<input type="radio" name="intent" value="export" checked>
						<strong><?php esc_html_e( 'Export', 'siteferry' ); ?></strong>
						<span><?php esc_html_e( 'Download the database as a .sql.gz, with optional replacements for the destination site.', 'siteferry' ); ?></span>
					</label>
					<label class="siteferry-action">
						<input type="radio" name="intent" value="replace">
						<strong><?php esc_html_e( 'Search and replace', 'siteferry' ); ?></strong>
						<span><?php esc_html_e( 'Change text on this site, including inside serialized data and JSON.', 'siteferry' ); ?></span>
					</label>
					<label class="siteferry-action">
						<input type="radio" name="intent" value="import">
						<strong><?php esc_html_e( 'Import', 'siteferry' ); ?></strong>
						<span><?php esc_html_e( 'Replace this site’s database with one from a .sql or .sql.gz file.', 'siteferry' ); ?></span>
					</label>
					<label class="siteferry-action">
						<input type="radio" name="intent" value="pull">
						<strong><?php esc_html_e( 'Pull', 'siteferry' ); ?></strong>
						<span><?php esc_html_e( 'Bring another site’s database here and replace this one.', 'siteferry' ); ?></span>
					</label>
					<label class="siteferry-action">
						<input type="radio" name="intent" value="push">
						<strong><?php esc_html_e( 'Push', 'siteferry' ); ?></strong>
						<span><?php esc_html_e( 'Send this site’s database to another site and replace the one there.', 'siteferry' ); ?></span>
					</label>
				</fieldset>

				<div class="siteferry-section" data-show="pull push">
					<label for="siteferry-connection"><strong><?php esc_html_e( 'Remote site', 'siteferry' ); ?></strong></label>
					<textarea id="siteferry-connection" name="connection" class="large-text code" rows="2" placeholder="https://other-site.com&#10;key…"></textarea>
					<p class="description"><?php esc_html_e( 'Copy the connection info from Tools → Siteferry on the other site.', 'siteferry' ); ?></p>
					<p><button type="button" class="button" id="siteferry-connect"><?php esc_html_e( 'Connect', 'siteferry' ); ?></button> <span id="siteferry-remote-info" class="description" aria-live="polite"></span></p>
				</div>

				<fieldset class="siteferry-section siteferry-options" data-show="pull push">
					<legend><strong><?php esc_html_e( 'What to transfer', 'siteferry' ); ?></strong></legend>
					<label><input type="checkbox" name="database" checked> <?php esc_html_e( 'Database', 'siteferry' ); ?></label>
					<label><input type="checkbox" name="files" value="uploads"> <?php esc_html_e( 'Media (uploads)', 'siteferry' ); ?></label>
					<label><input type="checkbox" name="files" value="themes"> <?php esc_html_e( 'Themes', 'siteferry' ); ?></label>
					<label><input type="checkbox" name="files" value="plugins"> <?php esc_html_e( 'Plugins', 'siteferry' ); ?></label>
					<label><input type="checkbox" name="files" value="mu-plugins"> <?php esc_html_e( 'Must-use plugins (mu-plugins)', 'siteferry' ); ?></label>
					<label><input type="checkbox" name="delete_files"> <?php esc_html_e( 'Delete files on the destination that don’t exist on the source (kept in a backup for 7 days)', 'siteferry' ); ?></label>
					<span class="description"><?php esc_html_e( 'Only what changed is transferred. Themes and plugins are swapped as whole folders at the end, and Siteferry never replaces itself. For a push, the other site must allow “code push” for themes and plugins.', 'siteferry' ); ?></span>
				</fieldset>

				<fieldset class="siteferry-section siteferry-options" data-show="export">
					<legend><strong><?php esc_html_e( 'Include files (full-site .sfy package)', 'siteferry' ); ?></strong></legend>
					<label><input type="checkbox" name="export_files" value="uploads"> <?php esc_html_e( 'Media (uploads)', 'siteferry' ); ?></label>
					<label><input type="checkbox" name="export_files" value="themes"> <?php esc_html_e( 'Themes', 'siteferry' ); ?></label>
					<label><input type="checkbox" name="export_files" value="plugins"> <?php esc_html_e( 'Plugins', 'siteferry' ); ?></label>
					<label><input type="checkbox" name="export_files" value="mu-plugins"> <?php esc_html_e( 'Must-use plugins (mu-plugins)', 'siteferry' ); ?></label>
					<span class="description"><?php esc_html_e( 'Without files you get the database (.sql.gz). With files, a .sfy package with everything, which you import on another site with Siteferry.', 'siteferry' ); ?></span>
				</fieldset>

				<div class="siteferry-section" data-show="import">
					<label for="siteferry-file"><strong><?php esc_html_e( 'File', 'siteferry' ); ?></strong></label>
					<input type="file" id="siteferry-file" accept=".sql,.gz,.sfy">
					<p class="description"><?php esc_html_e( 'A mysqldump or phpMyAdmin dump, a .sql.gz export, or a Siteferry .sfy site package. The table prefix and the source site URL are detected automatically.', 'siteferry' ); ?></p>
					<p>
						<label for="siteferry-source-prefix"><?php esc_html_e( 'Table prefix in the file (optional)', 'siteferry' ); ?></label>
						<input type="text" id="siteferry-source-prefix" name="source_prefix" class="regular-text code" placeholder="wp_" pattern="[A-Za-z0-9_]*">
						<span class="description"><?php esc_html_e( 'Only needed if the file holds several WordPress installs; the plugin will tell you.', 'siteferry' ); ?></span>
					</p>
				</div>

				<div class="siteferry-section">
					<h2><?php esc_html_e( 'Replacements', 'siteferry' ); ?></h2>
					<table class="widefat siteferry-pairs">
						<thead><tr><th><?php esc_html_e( 'Find', 'siteferry' ); ?></th><th><?php esc_html_e( 'Replace with', 'siteferry' ); ?></th><th><span class="screen-reader-text"><?php esc_html_e( 'Remove', 'siteferry' ); ?></span></th></tr></thead>
						<tbody>
							<tr>
								<td><input type="text" class="large-text" name="find" value="<?php echo esc_attr( $home ); ?>" aria-label="<?php esc_attr_e( 'Find', 'siteferry' ); ?>"></td>
								<td><input type="text" class="large-text" name="replace" placeholder="https://destination.test" aria-label="<?php esc_attr_e( 'Replace with', 'siteferry' ); ?>"></td>
								<td><button type="button" class="button-link siteferry-remove" aria-label="<?php esc_attr_e( 'Remove pair', 'siteferry' ); ?>">✕</button></td>
							</tr>
						</tbody>
					</table>
					<p><button type="button" class="button" id="siteferry-add-pair"><?php esc_html_e( 'Add pair', 'siteferry' ); ?></button></p>
					<p class="siteferry-options">
						<label data-show="import pull push"><input type="checkbox" name="auto_url" checked> <?php esc_html_e( 'Automatically replace the source site URL with the destination URL', 'siteferry' ); ?></label>
						<label><input type="checkbox" name="case_sensitive"> <?php esc_html_e( 'Case sensitive', 'siteferry' ); ?></label>
						<label><input type="checkbox" name="regex"> <?php esc_html_e( 'Regular expressions (PCRE with delimiters, e.g. #https?://old\.test#)', 'siteferry' ); ?></label>
						<label><input type="checkbox" name="replace_guids"> <?php esc_html_e( 'Also replace in guid (usually you should not)', 'siteferry' ); ?></label>
					</p>
				</div>

				<details class="siteferry-section" data-hide="import">
					<summary><strong id="siteferry-tables-title"><?php echo esc_html( sprintf( /* translators: %d: number of tables */ __( 'Tables (%d)', 'siteferry' ), count( $tables ) ) ); ?></strong></summary>
					<p><button type="button" class="button-link" id="siteferry-toggle-tables"><?php esc_html_e( 'Select / deselect all', 'siteferry' ); ?></button></p>
					<ul class="siteferry-tables" id="siteferry-table-list">
						<?php foreach ( $tables as $name => $info ) : ?>
							<li><label><input type="checkbox" name="tables" value="<?php echo esc_attr( $name ); ?>" checked> <code><?php echo esc_html( $name ); ?></code> <span class="description"><?php echo esc_html( size_format( $info['bytes'] ) . ' · ~' . number_format_i18n( $info['rows'] ) ); ?></span></label></li>
						<?php endforeach; ?>
					</ul>
				</details>

				<p class="siteferry-section" data-hide="export">
					<label><input type="checkbox" name="backup" checked> <?php esc_html_e( 'Back up the destination database first (kept for 7 days)', 'siteferry' ); ?></label>
				</p>

				<p><button type="submit" class="button button-primary button-hero" id="siteferry-run"><?php esc_html_e( 'Start', 'siteferry' ); ?></button></p>
			</form>

			<section id="siteferry-progress" class="siteferry-card" hidden aria-live="polite">
				<h2 id="siteferry-status"></h2>
				<progress id="siteferry-bar" max="100" value="0"></progress>
				<p id="siteferry-step" class="description"></p>
				<div id="siteferry-message"></div>
				<p id="siteferry-files"></p>
				<p>
					<button type="button" class="button button-primary" id="siteferry-resume" hidden><?php esc_html_e( 'Resume', 'siteferry' ); ?></button>
					<button type="button" class="button" id="siteferry-cancel" hidden><?php esc_html_e( 'Cancel', 'siteferry' ); ?></button>
					<button type="button" class="button" id="siteferry-new" hidden><?php esc_html_e( 'New operation', 'siteferry' ); ?></button>
				</p>
				<details><summary><?php esc_html_e( 'Log', 'siteferry' ); ?></summary><pre id="siteferry-log"></pre></details>
			</section>

			<section id="siteferry-undo" class="siteferry-card" <?php echo $undo ? '' : 'hidden'; ?>>
				<h2><?php esc_html_e( 'Undo the last operation', 'siteferry' ); ?></h2>
				<p id="siteferry-undo-info"><?php echo esc_html( self::undo_text( $undo ) ); ?></p>
				<p><button type="button" class="button" id="siteferry-undo-run"><?php esc_html_e( 'Undo', 'siteferry' ); ?></button></p>
			</section>

			<section id="siteferry-settings" class="siteferry-card">
				<h2><?php esc_html_e( 'This site’s connection', 'siteferry' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Paste this on the other site to pull from or push to this one. Anyone with the key can read or replace this database, depending on the permissions below: treat it like a password.', 'siteferry' ); ?></p>
				<textarea id="siteferry-own-connection" class="large-text code" rows="2" readonly><?php echo esc_textarea( $settings['connection'] ); ?></textarea>
				<p>
					<button type="button" class="button" id="siteferry-copy-connection"><?php esc_html_e( 'Copy', 'siteferry' ); ?></button>
					<button type="button" class="button-link button-link-delete" id="siteferry-regenerate"><?php esc_html_e( 'Regenerate key', 'siteferry' ); ?></button>
				</p>
				<fieldset class="siteferry-options">
					<legend class="screen-reader-text"><?php esc_html_e( 'Permissions', 'siteferry' ); ?></legend>
					<label><input type="checkbox" name="allow_pull" <?php checked( $settings['allow_pull'] ); ?>> <?php esc_html_e( 'Allow pull: other sites can read this database', 'siteferry' ); ?></label>
					<label><input type="checkbox" name="allow_pull_files" <?php checked( $settings['allow_pull_files'] ); ?>> <?php esc_html_e( 'Allow file pull: other sites can read media, themes and plugins (including their code)', 'siteferry' ); ?></label>
					<label><input type="checkbox" name="allow_push" <?php checked( $settings['allow_push'] ); ?>> <?php esc_html_e( 'Allow push: other sites can replace this database', 'siteferry' ); ?></label>
					<label><input type="checkbox" name="allow_push_code" <?php checked( $settings['allow_push_code'] ); ?>> <?php esc_html_e( 'Allow code push: other sites can replace this site’s themes and plugins', 'siteferry' ); ?></label>
					<label><input type="checkbox" name="verify_ssl" <?php checked( $settings['verify_ssl'] ); ?>> <?php esc_html_e( 'Verify the HTTPS certificate when connecting to other sites (turn off only for local development)', 'siteferry' ); ?></label>
				</fieldset>
				<p id="siteferry-settings-status" class="description" aria-live="polite"></p>
			</section>
		</div>
		<?php
	}

	/**
	 * Describes an undo point (the same text the script builds when it refreshes it).
	 *
	 * @param array|null $undo Undo point.
	 */
	private static function undo_text( ?array $undo ): string {
		if ( ! $undo ) {
			return '';
		}
		$parts = array();
		if ( $undo['db_backup'] ) {
			$parts[] = __( 'database', 'siteferry' );
		}
		if ( $undo['roots'] ) {
			$parts[] = __( 'files', 'siteferry' ) . ' (' . implode( ', ', $undo['roots'] ) . ')';
		}
		/* translators: 1: operation, 2: date, 3: what gets restored */
		return sprintf( __( '%1$s · %2$s · restores: %3$s.', 'siteferry' ), $undo['label'], wp_date( 'Y-m-d H:i', (int) $undo['time'] ), wp_sprintf( '%l', $parts ) );
	}
}

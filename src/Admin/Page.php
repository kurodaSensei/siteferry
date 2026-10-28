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
		$tabs     = array(
			'pull'    => __( 'Pull', 'siteferry' ),
			'push'    => __( 'Push', 'siteferry' ),
			'export'  => __( 'Export', 'siteferry' ),
			'import'  => __( 'Import', 'siteferry' ),
			'replace' => __( 'Search and replace', 'siteferry' ),
		);
		?>
		<div class="wrap siteferry">
			<header class="sf-header">
				<?php self::mark( 36 ); ?>
				<h1><?php echo esc_html( 'Siteferry' ); ?></h1>
				<span class="sf-pill"><?php echo esc_html( SITEFERRY_VERSION ); ?></span>
				<span class="sf-grow"></span>
				<span class="sf-accepts" id="siteferry-accepts" data-on="<?php echo array_filter( array_intersect_key( $settings, array_flip( array( 'allow_pull', 'allow_pull_files', 'allow_push', 'allow_push_code' ) ) ) ) ? '1' : '0'; ?>"><?php echo esc_html( self::accepts_text( $settings ) ); ?></span>
			</header>

			<div class="sf-tabs" role="tablist" aria-label="<?php esc_attr_e( 'Siteferry operations', 'siteferry' ); ?>">
				<?php foreach ( $tabs as $key => $label ) : ?>
					<button type="button" role="tab" class="sf-tab" id="siteferry-tab-<?php echo esc_attr( $key ); ?>" data-tab="<?php echo esc_attr( $key ); ?>" aria-selected="<?php echo 'pull' === $key ? 'true' : 'false'; ?>" tabindex="<?php echo 'pull' === $key ? '0' : '-1'; ?>"><?php echo esc_html( $label ); ?></button>
				<?php endforeach; ?>
				<button type="button" role="tab" class="sf-tab" id="siteferry-tab-undo" data-tab="undo" aria-selected="false" tabindex="-1"><?php esc_html_e( 'Undo', 'siteferry' ); ?> <span class="sf-badge" id="siteferry-undo-badge" <?php echo $undo ? '' : 'hidden'; ?>>1</span></button>
				<span class="sf-grow" aria-hidden="true"></span>
				<button type="button" role="tab" class="sf-tab" id="siteferry-tab-connection" data-tab="connection" aria-selected="false" tabindex="-1"><?php esc_html_e( 'Connection', 'siteferry' ); ?></button>
			</div>

			<form id="siteferry-form" class="sf-layout sf-panel" role="tabpanel" aria-labelledby="siteferry-tab-pull">
				<input type="hidden" name="intent" value="pull">
				<div class="sf-main">
					<section class="sf-card" data-show="pull push">
						<h2 class="sf-step"><?php esc_html_e( 'Connect to the other site', 'siteferry' ); ?></h2>
						<label for="siteferry-connection" class="sf-help"><?php esc_html_e( 'Paste the connection info from Siteferry → Connection on the other site.', 'siteferry' ); ?></label>
						<div class="sf-row">
							<textarea id="siteferry-connection" name="connection" class="code" rows="2" placeholder="https://other-site.com&#10;key…"></textarea>
							<button type="button" class="button" id="siteferry-connect"><?php esc_html_e( 'Connect', 'siteferry' ); ?></button>
						</div>
						<p id="siteferry-remote-info" class="sf-chip" aria-live="polite" hidden></p>
					</section>

					<section class="sf-card" data-show="pull push">
						<h2 class="sf-step"><?php esc_html_e( 'What to transfer', 'siteferry' ); ?></h2>
						<div class="sf-toggles">
							<?php self::toggle( 'database', '', __( 'Database', 'siteferry' ), __( 'All tables you select', 'siteferry' ), true ); ?>
							<?php self::toggle( 'files', 'uploads', __( 'Media', 'siteferry' ), __( 'uploads', 'siteferry' ) ); ?>
							<?php self::toggle( 'files', 'themes', __( 'Themes', 'siteferry' ), __( 'whole folders', 'siteferry' ) ); ?>
							<?php self::toggle( 'files', 'plugins', __( 'Plugins', 'siteferry' ), __( 'whole folders', 'siteferry' ) ); ?>
							<?php self::toggle( 'files', 'mu-plugins', __( 'Must-use plugins', 'siteferry' ), __( 'mu-plugins', 'siteferry' ) ); ?>
						</div>
						<div class="sf-options" data-files-only>
							<label><input type="checkbox" name="review" checked> <?php esc_html_e( 'Show the file comparison and let me choose before anything is transferred', 'siteferry' ); ?></label>
							<label><input type="checkbox" name="only_new"> <?php esc_html_e( 'Only new files: leave alone the files that exist on the destination with other content', 'siteferry' ); ?></label>
							<label><input type="checkbox" name="delete_files"> <?php esc_html_e( 'Delete files on the destination that don’t exist on the source (kept in a backup for 7 days)', 'siteferry' ); ?></label>
						</div>
						<p class="sf-help"><?php esc_html_e( 'Only what changed is transferred. Themes and plugins are swapped as whole folders at the end, and Siteferry never replaces itself. For a push, the other site must allow “code push” for themes and plugins.', 'siteferry' ); ?></p>
					</section>

					<section class="sf-card" data-show="export">
						<h2 class="sf-step"><?php esc_html_e( 'Include files (full-site .sfy package)', 'siteferry' ); ?></h2>
						<div class="sf-toggles">
							<?php self::toggle( 'export_files', 'uploads', __( 'Media', 'siteferry' ), __( 'uploads', 'siteferry' ) ); ?>
							<?php self::toggle( 'export_files', 'themes', __( 'Themes', 'siteferry' ), __( 'whole folders', 'siteferry' ) ); ?>
							<?php self::toggle( 'export_files', 'plugins', __( 'Plugins', 'siteferry' ), __( 'whole folders', 'siteferry' ) ); ?>
							<?php self::toggle( 'export_files', 'mu-plugins', __( 'Must-use plugins', 'siteferry' ), __( 'mu-plugins', 'siteferry' ) ); ?>
						</div>
						<p class="sf-help"><?php esc_html_e( 'Without files you get the database (.sql.gz). With files, a .sfy package with everything, which you import on another site with Siteferry.', 'siteferry' ); ?></p>
					</section>

					<section class="sf-card" data-show="import">
						<h2 class="sf-step"><?php esc_html_e( 'Choose the file', 'siteferry' ); ?></h2>
						<label for="siteferry-file" class="sf-drop">
							<span class="sf-drop-title"><?php esc_html_e( 'Drop a .sql, .sql.gz or .sfy file here, or click to choose one', 'siteferry' ); ?></span>
							<span class="sf-help" id="siteferry-file-name"><?php esc_html_e( 'A mysqldump or phpMyAdmin dump, a .sql.gz export, or a Siteferry .sfy site package. The table prefix and the source site URL are detected automatically.', 'siteferry' ); ?></span>
							<input type="file" id="siteferry-file" accept=".sql,.gz,.sfy">
						</label>
						<p class="sf-row sf-inline">
							<label for="siteferry-source-prefix"><?php esc_html_e( 'Table prefix in the file (optional)', 'siteferry' ); ?></label>
							<input type="text" id="siteferry-source-prefix" name="source_prefix" class="regular-text code" placeholder="wp_" pattern="[A-Za-z0-9_]*">
						</p>
						<p class="sf-help"><?php esc_html_e( 'Only needed if the file holds several WordPress installs; the plugin will tell you.', 'siteferry' ); ?></p>
					</section>

					<section class="sf-card">
						<h2 class="sf-step"><?php esc_html_e( 'Replacements', 'siteferry' ); ?></h2>
						<table class="widefat siteferry-pairs">
							<thead><tr><th><?php esc_html_e( 'Find', 'siteferry' ); ?></th><th><?php esc_html_e( 'Replace with', 'siteferry' ); ?></th><th><span class="screen-reader-text"><?php esc_html_e( 'Remove', 'siteferry' ); ?></span></th></tr></thead>
							<tbody>
								<tr>
									<td><input type="text" class="large-text" name="find" value="<?php echo esc_attr( $home ); ?>" aria-label="<?php esc_attr_e( 'Find', 'siteferry' ); ?>"></td>
									<td><input type="text" class="large-text" name="replace" placeholder="https://destination.test" aria-label="<?php esc_attr_e( 'Replace with', 'siteferry' ); ?>"></td>
									<td><button type="button" class="sf-icon-button siteferry-remove" aria-label="<?php esc_attr_e( 'Remove pair', 'siteferry' ); ?>"><span class="dashicons dashicons-no-alt" aria-hidden="true"></span></button></td>
								</tr>
							</tbody>
						</table>
						<p class="sf-row sf-links">
							<button type="button" class="button" id="siteferry-add-pair"><span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span> <?php esc_html_e( 'Add pair', 'siteferry' ); ?></button>
						</p>
						<div class="sf-options">
							<label data-show="import pull push"><input type="checkbox" name="auto_url" checked> <?php esc_html_e( 'Automatically replace the source site URL with the destination URL', 'siteferry' ); ?></label>
						</div>
						<details class="sf-more">
							<summary><?php esc_html_e( 'Advanced options', 'siteferry' ); ?></summary>
							<div class="sf-options">
								<label><input type="checkbox" name="case_sensitive"> <?php esc_html_e( 'Case sensitive', 'siteferry' ); ?></label>
								<label><input type="checkbox" name="regex"> <?php esc_html_e( 'Regular expressions (PCRE with delimiters, e.g. #https?://old\.test#)', 'siteferry' ); ?></label>
								<label><input type="checkbox" name="replace_guids"> <?php esc_html_e( 'Also replace in guid (usually you should not)', 'siteferry' ); ?></label>
							</div>
						</details>
						<details class="sf-more" data-hide="import">
							<summary id="siteferry-tables-title"><?php echo esc_html( sprintf( /* translators: %d: number of tables */ __( 'Tables (%d)', 'siteferry' ), count( $tables ) ) ); ?></summary>
							<p class="sf-row">
								<label for="siteferry-table-filter" class="screen-reader-text"><?php esc_html_e( 'Filter tables', 'siteferry' ); ?></label>
								<input type="search" id="siteferry-table-filter" class="sf-filter" placeholder="<?php esc_attr_e( 'Filter tables…', 'siteferry' ); ?>">
								<button type="button" class="button-link" id="siteferry-toggle-tables"><?php esc_html_e( 'Select / deselect all', 'siteferry' ); ?></button>
							</p>
							<ul class="siteferry-tables" id="siteferry-table-list">
								<?php foreach ( $tables as $name => $info ) : ?>
									<li><label><input type="checkbox" name="tables" value="<?php echo esc_attr( $name ); ?>" checked> <code><?php echo esc_html( $name ); ?></code> <span class="description"><?php echo esc_html( size_format( $info['bytes'] ) . ' · ~' . number_format_i18n( $info['rows'] ) ); ?></span></label></li>
								<?php endforeach; ?>
							</ul>
						</details>
					</section>
				</div>

				<aside class="sf-card sf-summary">
					<h2><?php esc_html_e( 'Summary', 'siteferry' ); ?></h2>
					<p id="siteferry-summary" aria-live="polite"></p>
					<ul class="sf-notes" data-hide="export">
						<li><?php esc_html_e( 'Live tables change only at the very end', 'siteferry' ); ?></li>
						<li><?php esc_html_e( 'Undo stays available for 7 days', 'siteferry' ); ?></li>
					</ul>
					<label data-hide="export"><input type="checkbox" name="backup" checked> <?php esc_html_e( 'Back up the destination database first (kept for 7 days)', 'siteferry' ); ?></label>
					<button type="submit" class="button button-primary sf-start" id="siteferry-run"><?php esc_html_e( 'Start', 'siteferry' ); ?></button>
				</aside>
			</form>

			<section id="siteferry-progress" class="sf-card sf-progress" hidden aria-live="polite">
				<div class="sf-progress-head">
					<h2 id="siteferry-status"></h2>
					<span class="sf-grow"></span>
					<span class="sf-percent" id="siteferry-percent">0%</span>
				</div>
				<div class="sf-bar" role="progressbar" aria-labelledby="siteferry-status" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" id="siteferry-bar"><div class="sf-bar-fill"></div></div>
				<p id="siteferry-step" class="sf-help"></p>
				<ol class="sf-phases" id="siteferry-phases"></ol>
				<div id="siteferry-review" class="sf-review" hidden>
					<h3><?php esc_html_e( 'Review the file comparison', 'siteferry' ); ?></h3>
					<table class="widefat sf-review-table">
						<thead><tr><th><?php esc_html_e( 'Folder', 'siteferry' ); ?></th><th><?php esc_html_e( 'New', 'siteferry' ); ?></th><th><?php esc_html_e( 'Changed', 'siteferry' ); ?></th><th><?php esc_html_e( 'Only on the destination', 'siteferry' ); ?></th></tr></thead>
						<tbody id="siteferry-review-rows"></tbody>
					</table>
					<fieldset class="sf-options">
						<legend class="screen-reader-text"><?php esc_html_e( 'What to transfer', 'siteferry' ); ?></legend>
						<label><input type="radio" name="review_mode" value="all" checked> <?php esc_html_e( 'New and changed files', 'siteferry' ); ?></label>
						<label><input type="radio" name="review_mode" value="new"> <?php esc_html_e( 'Only new files', 'siteferry' ); ?></label>
						<label><input type="checkbox" id="siteferry-review-delete"> <?php esc_html_e( 'Also delete the files that only the destination has (kept in a backup for 7 days)', 'siteferry' ); ?></label>
					</fieldset>
					<p><button type="button" class="button button-primary" id="siteferry-review-go"><?php esc_html_e( 'Continue', 'siteferry' ); ?></button></p>
				</div>
				<div id="siteferry-message"></div>
				<div id="siteferry-files" class="sf-downloads"></div>
				<div class="sf-row">
					<button type="button" class="button button-primary" id="siteferry-resume" hidden><?php esc_html_e( 'Resume', 'siteferry' ); ?></button>
					<button type="button" class="button sf-danger" id="siteferry-cancel" hidden><?php esc_html_e( 'Cancel', 'siteferry' ); ?></button>
					<button type="button" class="button" id="siteferry-new" hidden><?php esc_html_e( 'New operation', 'siteferry' ); ?></button>
					<span class="sf-grow"></span>
					<details class="sf-log"><summary><?php esc_html_e( 'Log', 'siteferry' ); ?></summary><pre id="siteferry-log"></pre></details>
				</div>
			</section>

			<section id="siteferry-undo" class="sf-card sf-panel" role="tabpanel" aria-labelledby="siteferry-tab-undo" hidden>
				<h2><?php esc_html_e( 'Undo the last operation', 'siteferry' ); ?></h2>
				<p id="siteferry-undo-info"><?php echo esc_html( $undo ? self::undo_text( $undo ) : __( 'There is nothing to undo.', 'siteferry' ) ); ?></p>
				<p><button type="button" class="button button-primary" id="siteferry-undo-run" <?php echo $undo ? '' : 'disabled'; ?>><?php esc_html_e( 'Undo', 'siteferry' ); ?></button></p>
			</section>

			<section id="siteferry-settings" class="sf-card sf-panel" role="tabpanel" aria-labelledby="siteferry-tab-connection" hidden>
				<h2><?php esc_html_e( 'This site’s connection', 'siteferry' ); ?></h2>
				<p class="sf-help"><?php esc_html_e( 'Paste this on the other site to pull from or push to this one. Anyone with the key can read or replace this database, depending on the permissions below: treat it like a password.', 'siteferry' ); ?></p>
				<textarea id="siteferry-own-connection" class="large-text code" rows="2" readonly><?php echo esc_textarea( $settings['connection'] ); ?></textarea>
				<p class="sf-row">
					<button type="button" class="button" id="siteferry-copy-connection"><?php esc_html_e( 'Copy', 'siteferry' ); ?></button>
					<button type="button" class="button-link button-link-delete" id="siteferry-regenerate"><?php esc_html_e( 'Regenerate key', 'siteferry' ); ?></button>
				</p>
				<fieldset class="sf-options">
					<legend class="screen-reader-text"><?php esc_html_e( 'Permissions', 'siteferry' ); ?></legend>
					<label><input type="checkbox" name="allow_pull" <?php checked( $settings['allow_pull'] ); ?>> <?php esc_html_e( 'Allow pull: other sites can read this database', 'siteferry' ); ?></label>
					<label><input type="checkbox" name="allow_pull_files" <?php checked( $settings['allow_pull_files'] ); ?>> <?php esc_html_e( 'Allow file pull: other sites can read media, themes and plugins (including their code)', 'siteferry' ); ?></label>
					<label><input type="checkbox" name="allow_push" <?php checked( $settings['allow_push'] ); ?>> <?php esc_html_e( 'Allow push: other sites can replace this database', 'siteferry' ); ?></label>
					<label><input type="checkbox" name="allow_push_code" <?php checked( $settings['allow_push_code'] ); ?>> <?php esc_html_e( 'Allow code push: other sites can replace this site’s themes and plugins', 'siteferry' ); ?></label>
					<label><input type="checkbox" name="verify_ssl" <?php checked( $settings['verify_ssl'] ); ?>> <?php esc_html_e( 'Verify the HTTPS certificate when connecting to other sites (turn off only for local development)', 'siteferry' ); ?></label>
				</fieldset>
				<p id="siteferry-settings-status" class="sf-help" aria-live="polite"></p>
			</section>
		</div>
		<?php
	}

	/**
	 * A selectable card for one thing to transfer.
	 *
	 * @param string $name    Checkbox name.
	 * @param string $value   Checkbox value ('' for a plain on/off box).
	 * @param string $label   Title.
	 * @param string $hint    Line under the title.
	 * @param bool   $checked Initially selected.
	 */
	private static function toggle( string $name, string $value, string $label, string $hint, bool $checked = false ): void {
		?>
		<label class="sf-toggle">
			<input type="checkbox" name="<?php echo esc_attr( $name ); ?>" <?php echo '' === $value ? '' : 'value="' . esc_attr( $value ) . '"'; ?> <?php checked( $checked ); ?>>
			<span class="sf-toggle-title"><?php echo esc_html( $label ); ?></span>
			<span class="sf-toggle-hint"><?php echo esc_html( $hint ); ?></span>
		</label>
		<?php
	}

	/**
	 * The Siteferry mark (decorative).
	 *
	 * @param int $size Size in px.
	 */
	private static function mark( int $size ): void {
		?>
		<svg class="sf-mark" width="<?php echo (int) $size; ?>" height="<?php echo (int) $size; ?>" viewBox="0 0 64 64" aria-hidden="true" focusable="false">
			<rect x="18" y="13" width="28" height="7" rx="3.5" fill="#12A39A"/>
			<rect x="14" y="24" width="36" height="7" rx="3.5" fill="#12A39A" opacity="0.65"/>
			<path d="M8 36h48l-6.5 12h-35z" fill="#0E2A3B"/>
			<path d="M6 55c5 0 5-3 10-3s5 3 10 3 5-3 10-3 5 3 10 3 5-3 10-3" fill="none" stroke="#12A39A" stroke-width="3" stroke-linecap="round"/>
		</svg>
		<?php
	}

	/**
	 * What other sites may do with this one, in a few words.
	 *
	 * @param array $settings Connection settings.
	 */
	private static function accepts_text( array $settings ): string {
		$names = array(
			'allow_pull'       => __( 'pull', 'siteferry' ),
			'allow_pull_files' => __( 'file pull', 'siteferry' ),
			'allow_push'       => __( 'push', 'siteferry' ),
			'allow_push_code'  => __( 'code push', 'siteferry' ),
		);
		$on    = array_values( array_intersect_key( $names, array_filter( $settings ) ) );
		/* translators: %s: list of allowed operations */
		return $on ? sprintf( __( 'This site accepts: %s', 'siteferry' ), wp_sprintf( '%l', $on ) ) : __( 'This site accepts no connections', 'siteferry' );
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

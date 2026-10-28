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
				'prefix'    => $GLOBALS['wpdb']->prefix,
				'maxChunk'  => max( 256 * 1024, min( 8 * 1024 * 1024, wp_max_upload_size() - 64 * 1024 ) ),
			)
		);
	}

	/**
	 * Renders the page. The forms are plain HTML; the script switches screens, submits and shows progress.
	 */
	public static function render(): void {
		global $wpdb;
		$tables   = ( new Schema( Plugin::db() ) )->stats( $wpdb->prefix );
		$home     = home_url();
		$host     = (string) preg_replace( '#^https?://#', '', untrailingslashit( $home ) );
		$settings = Settings::get() + array( 'connection' => Settings::connection() );
		$undo     = UndoPoint::get();
		$tabs     = array(
			'home'    => __( 'Home', 'siteferry' ),
			'pull'    => __( 'Pull', 'siteferry' ),
			'push'    => __( 'Push', 'siteferry' ),
			'export'  => __( 'Export', 'siteferry' ),
			'import'  => __( 'Import', 'siteferry' ),
			'replace' => __( 'Search and replace', 'siteferry' ),
		);
		?>
		<div class="wrap siteferry">
			<header class="sf-header">
				<?php self::mark( 40 ); ?>
				<div class="sf-brand">
					<h1><?php echo esc_html( 'Siteferry' ); ?></h1>
					<span class="sf-tagline"><?php esc_html_e( 'Move WordPress sites safely', 'siteferry' ); ?></span>
				</div>
				<span class="sf-pill"><?php echo esc_html( SITEFERRY_VERSION ); ?></span>
				<span class="sf-grow"></span>
				<span class="sf-accepts" id="siteferry-accepts" data-on="<?php echo array_filter( array_intersect_key( $settings, array_flip( array( 'allow_pull', 'allow_pull_files', 'allow_push', 'allow_push_code' ) ) ) ) ? '1' : '0'; ?>"><?php echo esc_html( self::accepts_text( $settings ) ); ?></span>
			</header>

			<div class="sf-tabs" role="tablist" aria-label="<?php esc_attr_e( 'Siteferry operations', 'siteferry' ); ?>">
				<?php foreach ( $tabs as $key => $label ) : ?>
					<button type="button" role="tab" class="sf-tab" id="siteferry-tab-<?php echo esc_attr( $key ); ?>" data-tab="<?php echo esc_attr( $key ); ?>" aria-selected="<?php echo 'home' === $key ? 'true' : 'false'; ?>" tabindex="<?php echo 'home' === $key ? '0' : '-1'; ?>"><?php echo esc_html( $label ); ?></button>
				<?php endforeach; ?>
				<span class="sf-grow" aria-hidden="true"></span>
				<button type="button" role="tab" class="sf-tab" id="siteferry-tab-connection" data-tab="connection" aria-selected="false" tabindex="-1"><?php esc_html_e( 'Connection', 'siteferry' ); ?></button>
			</div>

			<section id="siteferry-home" class="sf-panel sf-home" role="tabpanel" aria-labelledby="siteferry-tab-home">
				<div class="sf-home-main">
					<h2 class="sf-title"><?php esc_html_e( 'What do you want to move?', 'siteferry' ); ?></h2>
					<div class="sf-hero-actions">
						<button type="button" class="sf-action is-dark" data-go="pull">
							<?php self::flow( __( 'Another site', 'siteferry' ), __( 'This site', 'siteferry' ), false ); ?>
							<span class="sf-action-title"><?php esc_html_e( 'Bring a site here', 'siteferry' ); ?></span>
							<span class="sf-action-text"><?php esc_html_e( 'Copy the database and files from staging or production into this WordPress.', 'siteferry' ); ?></span>
							<span class="sf-action-cta"><?php esc_html_e( 'Start a pull', 'siteferry' ); ?> →</span>
						</button>
						<button type="button" class="sf-action" data-go="push">
							<?php self::flow( __( 'This site', 'siteferry' ), __( 'Another site', 'siteferry' ), true ); ?>
							<span class="sf-action-title"><?php esc_html_e( 'Send this site', 'siteferry' ); ?></span>
							<span class="sf-action-text"><?php esc_html_e( 'Publish your local changes on another site that accepts push.', 'siteferry' ); ?></span>
							<span class="sf-action-cta"><?php esc_html_e( 'Start a push', 'siteferry' ); ?> →</span>
						</button>
					</div>
					<div class="sf-more-actions">
						<?php self::shortcut( 'export', 'M12 3v12m0 0-4-4m4 4 4-4M4 17v3h16v-3', __( 'Export', 'siteferry' ), __( 'Download the database, or a .sfy package with everything.', 'siteferry' ) ); ?>
						<?php self::shortcut( 'import', 'M12 15V3m0 0L8 7m4-4 4 4M4 17v3h16v-3', __( 'Import', 'siteferry' ), __( 'Replace this site with a .sql, .sql.gz or .sfy file.', 'siteferry' ) ); ?>
						<?php self::shortcut( 'replace', 'M10.5 4.5a6 6 0 1 0 0 12 6 6 0 0 0 0-12zM15 15l5 5', __( 'Search and replace', 'siteferry' ), __( 'Change text in the database, serialized data included.', 'siteferry' ) ); ?>
					</div>
				</div>
				<aside class="sf-home-side">
					<section class="sf-card sf-site" aria-labelledby="siteferry-site-title">
						<h2 class="sf-eyebrow" id="siteferry-site-title"><?php esc_html_e( 'This site', 'siteferry' ); ?></h2>
						<p class="sf-site-name"><?php echo esc_html( $host ); ?></p>
						<p class="sf-help">
							<?php
							/* translators: 1: WordPress version, 2: PHP version, 3: table prefix */
							echo esc_html( sprintf( __( 'WordPress %1$s · PHP %2$s · prefix %3$s', 'siteferry' ), get_bloginfo( 'version' ), PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION, $wpdb->prefix ) );
							?>
						</p>
						<ul class="sf-chips" id="siteferry-perm-chips">
							<?php foreach ( self::permissions() as $key => $perm ) : ?>
								<li class="sf-chip-perm is-<?php echo esc_attr( $perm['risk'] ); ?>" data-perm="<?php echo esc_attr( $key ); ?>" <?php echo $settings[ $key ] ? '' : 'hidden'; ?>><?php echo esc_html( $perm['short'] ); ?></li>
							<?php endforeach; ?>
						</ul>
						<button type="button" class="button-link sf-link" data-go="connection"><?php esc_html_e( 'See the connection info', 'siteferry' ); ?></button>
					</section>
					<section class="sf-card sf-last" aria-labelledby="siteferry-last-title">
						<h2 class="sf-eyebrow" id="siteferry-last-title"><?php esc_html_e( 'Last operation', 'siteferry' ); ?></h2>
						<p id="siteferry-undo-info" class="sf-last-text"><?php echo esc_html( $undo ? self::undo_text( $undo ) : __( 'There is nothing to undo.', 'siteferry' ) ); ?></p>
						<p class="sf-row"><button type="button" class="button" id="siteferry-undo-run" <?php echo $undo ? '' : 'disabled'; ?>><?php esc_html_e( 'Undo', 'siteferry' ); ?></button></p>
						<p class="sf-help"><?php esc_html_e( 'Undo stays available for 7 days', 'siteferry' ); ?></p>
					</section>
				</aside>
			</section>

			<ol class="sf-stepper" id="siteferry-stepper" aria-label="<?php esc_attr_e( 'Steps', 'siteferry' ); ?>" hidden>
				<li data-step="connect"><span class="sf-stepper-dot" aria-hidden="true"></span><?php esc_html_e( 'Connect', 'siteferry' ); ?></li>
				<li data-step="choose"><span class="sf-stepper-dot" aria-hidden="true"></span><?php esc_html_e( 'Choose', 'siteferry' ); ?></li>
				<li data-step="review"><span class="sf-stepper-dot" aria-hidden="true"></span><?php esc_html_e( 'Review', 'siteferry' ); ?></li>
				<li data-step="transfer"><span class="sf-stepper-dot" aria-hidden="true"></span><?php esc_html_e( 'Transfer', 'siteferry' ); ?></li>
			</ol>

			<form id="siteferry-form" class="sf-panel" role="tabpanel" aria-labelledby="siteferry-tab-pull" hidden>
				<input type="hidden" name="intent" value="pull">

				<section class="sf-card sf-connect" data-show="pull push" data-wizard-step="connect">
					<h2 class="sf-card-title"><?php esc_html_e( 'Connect to the other site', 'siteferry' ); ?></h2>
					<label for="siteferry-connection" class="sf-help"><?php esc_html_e( 'Paste the connection info from Siteferry → Connection on the other site.', 'siteferry' ); ?></label>
					<textarea id="siteferry-connection" name="connection" class="code" rows="3" placeholder="https://other-site.com&#10;key…"></textarea>
					<p class="sf-row"><button type="button" class="button button-primary" id="siteferry-connect"><?php esc_html_e( 'Connect', 'siteferry' ); ?></button></p>
					<p id="siteferry-remote-info" class="sf-chip" aria-live="polite" hidden></p>
				</section>

				<div class="sf-route" data-show="pull push" data-wizard-step="choose">
					<div class="sf-route-end">
						<span class="sf-eyebrow"><?php esc_html_e( 'From', 'siteferry' ); ?></span>
						<strong id="siteferry-route-from"></strong>
						<span id="siteferry-route-from-meta"></span>
					</div>
					<svg class="sf-route-arrow" width="48" height="20" viewBox="0 0 48 20" aria-hidden="true" focusable="false"><path d="M2 10h42M36 3l8 7-8 7" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
					<div class="sf-route-end">
						<span class="sf-eyebrow"><?php esc_html_e( 'To', 'siteferry' ); ?></span>
						<strong id="siteferry-route-to"></strong>
						<span id="siteferry-route-to-meta"></span>
					</div>
					<button type="button" class="sf-route-change" id="siteferry-change-site"><?php esc_html_e( 'Change site', 'siteferry' ); ?></button>
				</div>

				<div class="sf-layout" data-wizard-step="choose">
					<div class="sf-main">
						<section class="sf-card" data-show="pull push">
							<h2 class="sf-card-title"><?php esc_html_e( 'Database', 'siteferry' ); ?></h2>
							<div class="sf-option-card">
								<label class="sf-option-main">
									<input type="checkbox" name="database" checked>
									<span>
										<span class="sf-toggle-title"><?php esc_html_e( 'Transfer the database', 'siteferry' ); ?></span>
										<span class="sf-toggle-hint" id="siteferry-db-hint"></span>
									</span>
								</label>
								<button type="button" class="button-link sf-link" id="siteferry-choose-tables"><?php esc_html_e( 'Choose tables', 'siteferry' ); ?></button>
							</div>
						</section>

						<section class="sf-card" data-show="pull push">
							<h2 class="sf-card-title"><?php esc_html_e( 'Files', 'siteferry' ); ?></h2>
							<div class="sf-toggles">
								<?php self::toggle( 'files', 'uploads', __( 'Media', 'siteferry' ), __( 'uploads · images and documents', 'siteferry' ) ); ?>
								<?php self::toggle( 'files', 'themes', __( 'Themes', 'siteferry' ), __( 'whole folders', 'siteferry' ) ); ?>
								<?php self::toggle( 'files', 'plugins', __( 'Plugins', 'siteferry' ), __( 'whole folders; Siteferry never replaces itself', 'siteferry' ) ); ?>
								<?php self::toggle( 'files', 'mu-plugins', __( 'Must-use plugins', 'siteferry' ), __( 'mu-plugins', 'siteferry' ) ); ?>
							</div>
							<fieldset class="sf-choice" data-files-only>
								<legend><?php esc_html_e( 'Which files travel?', 'siteferry' ); ?></legend>
								<label class="sf-radio">
									<input type="radio" name="review" value="1" checked>
									<span><span class="sf-toggle-title"><?php esc_html_e( 'Compare first and decide', 'siteferry' ); ?></span><span class="sf-toggle-hint"><?php esc_html_e( 'You will see how many files are new, changed or extra before anything moves.', 'siteferry' ); ?></span></span>
								</label>
								<label class="sf-radio">
									<input type="radio" name="review" value="0">
									<span><span class="sf-toggle-title"><?php esc_html_e( 'Bring everything new and changed', 'siteferry' ); ?></span><span class="sf-toggle-hint"><?php esc_html_e( 'No pause to review.', 'siteferry' ); ?></span></span>
								</label>
								<div class="sf-options" data-no-review hidden>
									<label><input type="checkbox" name="only_new"> <?php esc_html_e( 'Only new files: leave alone the files that exist on the destination with other content', 'siteferry' ); ?></label>
									<label><input type="checkbox" name="delete_files"> <?php esc_html_e( 'Delete files on the destination that don’t exist on the source (kept in a backup for 7 days)', 'siteferry' ); ?></label>
								</div>
							</fieldset>
							<p class="sf-help"><?php esc_html_e( 'Only what changed is transferred. Themes and plugins are swapped as whole folders at the end, and Siteferry never replaces itself. For a push, the other site must allow “code push” for themes and plugins.', 'siteferry' ); ?></p>
						</section>

						<section class="sf-card" data-show="export">
							<h2 class="sf-card-title"><?php esc_html_e( 'Include files (full-site .sfy package)', 'siteferry' ); ?></h2>
							<div class="sf-toggles">
								<?php self::toggle( 'export_files', 'uploads', __( 'Media', 'siteferry' ), __( 'uploads', 'siteferry' ) ); ?>
								<?php self::toggle( 'export_files', 'themes', __( 'Themes', 'siteferry' ), __( 'whole folders', 'siteferry' ) ); ?>
								<?php self::toggle( 'export_files', 'plugins', __( 'Plugins', 'siteferry' ), __( 'whole folders', 'siteferry' ) ); ?>
								<?php self::toggle( 'export_files', 'mu-plugins', __( 'Must-use plugins', 'siteferry' ), __( 'mu-plugins', 'siteferry' ) ); ?>
							</div>
							<p class="sf-help"><?php esc_html_e( 'Without files you get the database (.sql.gz). With files, a .sfy package with everything, which you import on another site with Siteferry.', 'siteferry' ); ?></p>
						</section>

						<section class="sf-card" data-show="import">
							<h2 class="sf-card-title"><?php esc_html_e( 'Choose the file', 'siteferry' ); ?></h2>
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
							<details class="sf-fold" id="siteferry-replacements">
								<summary class="sf-card-title"><?php esc_html_e( 'Replacements and advanced options', 'siteferry' ); ?></summary>
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
									<label><input type="checkbox" name="case_sensitive"> <?php esc_html_e( 'Case sensitive', 'siteferry' ); ?></label>
									<label><input type="checkbox" name="regex"> <?php esc_html_e( 'Regular expressions (PCRE with delimiters, e.g. #https?://old\.test#)', 'siteferry' ); ?></label>
									<label><input type="checkbox" name="replace_guids"> <?php esc_html_e( 'Also replace in guid (usually you should not)', 'siteferry' ); ?></label>
								</div>
								<details class="sf-more" id="siteferry-tables" data-hide="import">
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
							</details>
						</section>
					</div>

					<aside class="sf-card sf-summary">
						<h2 class="sf-card-title"><?php esc_html_e( 'Summary', 'siteferry' ); ?></h2>
						<p id="siteferry-summary" aria-live="polite"></p>
						<ul class="sf-safety" data-hide="export">
							<li><?php self::icon( 'M12 3 4 6v6c0 4.5 3.4 8.3 8 9 4.6-.7 8-4.5 8-9V6z' ); ?><span><strong><?php esc_html_e( 'A backup first', 'siteferry' ); ?></strong><?php esc_html_e( 'The current database of the destination is saved before anything changes, for 7 days.', 'siteferry' ); ?></span></li>
							<li><?php self::icon( 'M12 3.5a8.5 8.5 0 1 0 0 17 8.5 8.5 0 0 0 0-17zM12 7.5V12l3 2' ); ?><span><strong><?php esc_html_e( 'Changes only at the end', 'siteferry' ); ?></strong><?php esc_html_e( 'Everything is prepared aside; tables and folders are swapped in the last step.', 'siteferry' ); ?></span></li>
							<li><?php self::icon( 'M9 14 4 9l5-5M4 9h10a6 6 0 0 1 0 12h-3' ); ?><span><strong><?php esc_html_e( 'Undo for 7 days', 'siteferry' ); ?></strong><?php esc_html_e( 'If something is not right, go back to how it was with one click.', 'siteferry' ); ?></span></li>
						</ul>
						<label data-hide="export"><input type="checkbox" name="backup" checked> <?php esc_html_e( 'Back up the destination database first (kept for 7 days)', 'siteferry' ); ?></label>
						<p class="sf-callout is-warning" id="siteferry-users-note" data-show="pull import"><?php esc_html_e( 'Bringing a database also brings its users: you will have to log in with one of the source site’s users.', 'siteferry' ); ?></p>
						<button type="submit" class="button button-primary sf-start" id="siteferry-run"><?php esc_html_e( 'Start', 'siteferry' ); ?></button>
					</aside>
				</div>
			</form>

			<section id="siteferry-progress" class="sf-panel sf-run" hidden>
				<div class="sf-run-hero">
					<div class="sf-run-head">
						<div class="sf-run-heading">
							<span class="sf-eyebrow" id="siteferry-run-label"></span>
							<h2 id="siteferry-status"></h2>
						</div>
						<span class="sf-percent" id="siteferry-percent">0%</span>
					</div>
					<div class="sf-bar" role="progressbar" aria-labelledby="siteferry-status" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" id="siteferry-bar"><div class="sf-bar-fill"></div></div>
					<dl class="sf-tiles" id="siteferry-tiles"></dl>
					<p id="siteferry-step" class="screen-reader-text" aria-live="polite"></p>
				</div>

				<div class="sf-done" id="siteferry-done" hidden>
					<div class="sf-done-head">
						<span class="sf-done-icon" aria-hidden="true"><?php self::icon( 'm5 12 5 5 9-10' ); ?></span>
						<div>
							<h2 id="siteferry-done-title"></h2>
							<p id="siteferry-done-text" class="sf-help"></p>
						</div>
					</div>
					<dl class="sf-stats" id="siteferry-done-stats"></dl>
					<p class="sf-callout is-info" id="siteferry-done-login" hidden><?php esc_html_e( 'The users are now the source site’s, so your session ended. Log in with one of its users to continue.', 'siteferry' ); ?></p>
					<p class="sf-row">
						<a class="button button-primary" id="siteferry-login" href="<?php echo esc_url( wp_login_url( admin_url( 'tools.php?page=' . self::SLUG ) ) ); ?>" hidden><?php esc_html_e( 'Log in', 'siteferry' ); ?></a>
						<a class="button" href="<?php echo esc_url( $home ); ?>"><?php esc_html_e( 'View the site', 'siteferry' ); ?></a>
						<button type="button" class="button-link sf-link" data-go="home"><?php esc_html_e( 'Back to home', 'siteferry' ); ?></button>
					</p>
				</div>

				<div id="siteferry-review" class="sf-review" hidden>
					<div>
						<h2 class="sf-title"><?php esc_html_e( 'Review the comparison', 'siteferry' ); ?></h2>
						<p class="sf-help" id="siteferry-review-text"></p>
					</div>
					<div class="sf-card sf-table-card">
						<h3 class="sf-card-title"><?php esc_html_e( 'Files by folder', 'siteferry' ); ?></h3>
						<div class="sf-scroll">
							<table class="sf-review-table">
								<thead><tr><th scope="col"><?php esc_html_e( 'Folder', 'siteferry' ); ?></th><th scope="col" class="is-new"><?php esc_html_e( 'New', 'siteferry' ); ?></th><th scope="col" class="is-changed"><?php esc_html_e( 'Changed', 'siteferry' ); ?></th><th scope="col" class="is-extra"><?php esc_html_e( 'Only on the destination', 'siteferry' ); ?></th></tr></thead>
								<tbody id="siteferry-review-rows"></tbody>
								<tfoot id="siteferry-review-total"></tfoot>
							</table>
						</div>
					</div>
					<fieldset class="sf-cards">
						<legend class="sf-card-title"><?php esc_html_e( 'What to transfer', 'siteferry' ); ?></legend>
						<label class="sf-radio-card"><input type="radio" name="review_mode" value="all" checked> <span><span class="sf-toggle-title"><?php esc_html_e( 'New and changed files', 'siteferry' ); ?></span><span class="sf-toggle-hint" id="siteferry-mode-all"></span></span></label>
						<label class="sf-radio-card"><input type="radio" name="review_mode" value="new"> <span><span class="sf-toggle-title"><?php esc_html_e( 'Only new files', 'siteferry' ); ?></span><span class="sf-toggle-hint" id="siteferry-mode-new"></span></span></label>
					</fieldset>
					<label class="sf-danger-card"><input type="checkbox" id="siteferry-review-delete"> <span><span class="sf-toggle-title" id="siteferry-delete-title"><?php esc_html_e( 'Also delete the files that only the destination has (kept in a backup for 7 days)', 'siteferry' ); ?></span><span class="sf-toggle-hint"><?php esc_html_e( 'They are moved to the backup and can be recovered for 7 days. Leave this off if the destination has themes or plugins you want to keep.', 'siteferry' ); ?></span></span></label>
					<p class="sf-row"><button type="button" class="button button-primary sf-start" id="siteferry-review-go"><?php esc_html_e( 'Continue', 'siteferry' ); ?></button></p>
				</div>

				<div id="siteferry-message"></div>

				<div class="sf-run-body" id="siteferry-run-body">
					<section class="sf-card sf-run-main">
						<h3 class="sf-card-title" id="siteferry-main-title"><?php esc_html_e( 'Phases', 'siteferry' ); ?></h3>
						<ol class="sf-phases" id="siteferry-phases"></ol>
						<ul class="sf-changes" id="siteferry-changes" hidden></ul>
					</section>
					<aside class="sf-run-side">
						<div class="sf-card sf-tip" data-when="open">
							<h3><?php esc_html_e( 'You can keep browsing Siteferry', 'siteferry' ); ?></h3>
							<p><?php esc_html_e( 'Move to other Siteferry screens while this runs. Leaving this page or closing the tab pauses it with its progress kept: resume it when you are back.', 'siteferry' ); ?></p>
						</div>
						<div class="sf-card sf-tip" data-when="open">
							<h3><?php esc_html_e( 'The destination stays as it is', 'siteferry' ); ?></h3>
							<p><?php esc_html_e( 'Everything is prepared aside. The destination only changes in the last phase.', 'siteferry' ); ?></p>
						</div>
						<div class="sf-card sf-tip" data-when="done" id="siteferry-undo-card" hidden>
							<h3><?php esc_html_e( 'Something not right?', 'siteferry' ); ?></h3>
							<p><?php esc_html_e( 'For 7 days you can put this site back exactly as it was before this operation.', 'siteferry' ); ?></p>
							<p><button type="button" class="button" id="siteferry-undo-again"><?php esc_html_e( 'Undo this operation', 'siteferry' ); ?></button></p>
						</div>
						<div id="siteferry-files" class="sf-downloads"></div>
					</aside>
				</div>

				<div class="sf-row sf-actions-row">
					<button type="button" class="button button-primary" id="siteferry-resume" hidden><?php esc_html_e( 'Resume', 'siteferry' ); ?></button>
					<button type="button" class="button sf-danger" id="siteferry-cancel" hidden><?php esc_html_e( 'Cancel', 'siteferry' ); ?></button>
					<button type="button" class="button" id="siteferry-new" hidden><?php esc_html_e( 'New operation', 'siteferry' ); ?></button>
				</div>
				<details class="sf-log"><summary><?php esc_html_e( 'Technical log', 'siteferry' ); ?></summary><pre id="siteferry-log"></pre></details>
			</section>

			<section id="siteferry-settings" class="sf-panel sf-connection" role="tabpanel" aria-labelledby="siteferry-tab-connection" hidden>
				<div>
					<h2 class="sf-title"><?php esc_html_e( 'This site’s connection', 'siteferry' ); ?></h2>
					<p class="sf-help"><?php esc_html_e( 'Another site with Siteferry uses this info to pull from or push to this one. You decide what it may do.', 'siteferry' ); ?></p>
				</div>
				<div class="sf-layout">
					<section class="sf-card sf-main" aria-labelledby="siteferry-conn-title">
						<h3 class="sf-card-title" id="siteferry-conn-title"><?php esc_html_e( 'Connection info', 'siteferry' ); ?></h3>
						<div class="sf-secret" id="siteferry-own-connection" data-connection="<?php echo esc_attr( $settings['connection'] ); ?>">
							<span id="siteferry-own-url"></span>
							<span id="siteferry-own-key" class="is-masked"></span>
						</div>
						<p class="sf-row">
							<button type="button" class="button button-primary" id="siteferry-copy-connection"><?php esc_html_e( 'Copy', 'siteferry' ); ?></button>
							<button type="button" class="button" id="siteferry-show-key" aria-pressed="false"><?php esc_html_e( 'Show key', 'siteferry' ); ?></button>
							<span class="sf-grow"></span>
							<button type="button" class="button-link button-link-delete" id="siteferry-regenerate"><?php esc_html_e( 'Regenerate key', 'siteferry' ); ?></button>
						</p>
						<p class="sf-callout is-warning"><?php esc_html_e( 'Treat it like a password: whoever has it can do what you allow below. Regenerating it cuts off the sites that use it.', 'siteferry' ); ?></p>
					</section>
					<aside class="sf-card sf-summary">
						<h3 class="sf-card-title"><?php esc_html_e( 'How to connect', 'siteferry' ); ?></h3>
						<ol class="sf-howto">
							<li><?php esc_html_e( 'Copy the connection info.', 'siteferry' ); ?></li>
							<li><?php esc_html_e( 'On the other site, open Siteferry and choose Pull or Push.', 'siteferry' ); ?></li>
							<li><?php esc_html_e( 'Paste it in the Connect step. Siteferry checks the permissions before starting.', 'siteferry' ); ?></li>
						</ol>
					</aside>
				</div>
				<section class="sf-card" aria-labelledby="siteferry-perm-title">
					<h3 class="sf-card-title" id="siteferry-perm-title"><?php esc_html_e( 'What other sites may do', 'siteferry' ); ?></h3>
					<ul class="sf-perm-list">
						<?php foreach ( self::permissions() as $key => $perm ) : ?>
							<li>
								<span class="sf-perm-text"><label for="siteferry-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $perm['label'] ); ?></label><span class="sf-help"><?php echo esc_html( $perm['hint'] ); ?></span></span>
								<span class="sf-chip-perm is-<?php echo esc_attr( $perm['risk'] ); ?>"><?php echo esc_html( $perm['badge'] ); ?></span>
								<input type="checkbox" class="sf-switch" id="siteferry-<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( $key ); ?>" <?php checked( $settings[ $key ] ); ?>>
							</li>
						<?php endforeach; ?>
					</ul>
				</section>
				<section class="sf-card" aria-labelledby="siteferry-out-title">
					<h3 class="sf-card-title" id="siteferry-out-title"><?php esc_html_e( 'When this site connects to others', 'siteferry' ); ?></h3>
					<ul class="sf-perm-list">
						<li>
							<span class="sf-perm-text"><label for="siteferry-verify_ssl"><?php esc_html_e( 'Verify the HTTPS certificate', 'siteferry' ); ?></label><span class="sf-help"><?php esc_html_e( 'Turn it off only for local development with self-signed certificates.', 'siteferry' ); ?></span></span>
							<input type="checkbox" class="sf-switch" id="siteferry-verify_ssl" name="verify_ssl" <?php checked( $settings['verify_ssl'] ); ?>>
						</li>
					</ul>
				</section>
				<p id="siteferry-settings-status" class="sf-help" aria-live="polite"></p>
			</section>

			<dialog class="sf-dialog" id="siteferry-dialog" aria-labelledby="siteferry-dialog-title" aria-describedby="siteferry-dialog-text">
				<form method="dialog">
					<span class="sf-dialog-icon" aria-hidden="true"><?php self::icon( 'M12 8v5M12 16.5v.5M12 3.5a8.5 8.5 0 1 0 0 17 8.5 8.5 0 0 0 0-17z' ); ?></span>
					<h2 id="siteferry-dialog-title"></h2>
					<p id="siteferry-dialog-text"></p>
					<p class="sf-row sf-dialog-actions">
						<button type="submit" class="button" value="cancel" id="siteferry-dialog-cancel"><?php esc_html_e( 'Cancel', 'siteferry' ); ?></button>
						<button type="submit" class="button button-primary" value="ok" id="siteferry-dialog-ok"></button>
					</p>
				</form>
			</dialog>

			<button type="button" class="sf-float" id="siteferry-float" hidden><span class="sf-float-dot" aria-hidden="true"></span><span id="siteferry-float-text"></span></button>
		</div>
		<?php
	}

	/**
	 * What other sites may do with this one, for the chips and the connection screen.
	 *
	 * @return array<string, array{label: string, hint: string, short: string, badge: string, risk: string}>
	 */
	private static function permissions(): array {
		return array(
			'allow_pull'       => array(
				'label' => __( 'Pull the database', 'siteferry' ),
				'hint'  => __( 'Other sites can read this database.', 'siteferry' ),
				'short' => __( 'Pull', 'siteferry' ),
				'badge' => __( 'Read', 'siteferry' ),
				'risk'  => 'read',
			),
			'allow_pull_files' => array(
				'label' => __( 'Pull files', 'siteferry' ),
				'hint'  => __( 'Other sites can read media, themes and plugins, including their code.', 'siteferry' ),
				'short' => __( 'File pull', 'siteferry' ),
				'badge' => __( 'Read', 'siteferry' ),
				'risk'  => 'read',
			),
			'allow_push'       => array(
				'label' => __( 'Receive a database', 'siteferry' ),
				'hint'  => __( 'Other sites can replace this database and its media.', 'siteferry' ),
				'short' => __( 'Push', 'siteferry' ),
				'badge' => __( 'Write', 'siteferry' ),
				'risk'  => 'write',
			),
			'allow_push_code'  => array(
				'label' => __( 'Receive themes and plugins', 'siteferry' ),
				'hint'  => __( 'Other sites can replace the code this site runs.', 'siteferry' ),
				'short' => __( 'Code push', 'siteferry' ),
				'badge' => __( 'Code', 'siteferry' ),
				'risk'  => 'code',
			),
		);
	}

	/**
	 * A selectable card for one thing to transfer.
	 *
	 * @param string $name    Checkbox name.
	 * @param string $value   Checkbox value.
	 * @param string $label   Title.
	 * @param string $hint    Line under the title.
	 * @param bool   $checked Initially selected.
	 */
	private static function toggle( string $name, string $value, string $label, string $hint, bool $checked = false ): void {
		?>
		<label class="sf-toggle">
			<input type="checkbox" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $value ); ?>" <?php checked( $checked ); ?>>
			<span>
				<span class="sf-toggle-title"><?php echo esc_html( $label ); ?></span>
				<span class="sf-toggle-hint"><?php echo esc_html( $hint ); ?></span>
			</span>
		</label>
		<?php
	}

	/**
	 * A small card on the home screen that opens another operation.
	 *
	 * @param string $tab   Tab it opens.
	 * @param string $path  Icon path (24×24, stroked).
	 * @param string $title Title.
	 * @param string $text  Line under the title.
	 */
	private static function shortcut( string $tab, string $path, string $title, string $text ): void {
		?>
		<button type="button" class="sf-shortcut" data-go="<?php echo esc_attr( $tab ); ?>">
			<?php self::icon( $path ); ?>
			<span><span class="sf-toggle-title"><?php echo esc_html( $title ); ?></span><span class="sf-toggle-hint"><?php echo esc_html( $text ); ?></span></span>
		</button>
		<?php
	}

	/**
	 * "From → to" badges on the home cards.
	 *
	 * @param string $from     Left label.
	 * @param string $to       Right label.
	 * @param bool   $here_left Whether the left one is this site.
	 */
	private static function flow( string $from, string $to, bool $here_left ): void {
		?>
		<span class="sf-flow" aria-hidden="true">
			<span class="<?php echo $here_left ? 'is-here' : ''; ?>"><?php echo esc_html( $from ); ?></span>
			<svg width="28" height="12" viewBox="0 0 28 12" focusable="false"><path d="M1 6h24M20 1l5 5-5 5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
			<span class="<?php echo $here_left ? '' : 'is-here'; ?>"><?php echo esc_html( $to ); ?></span>
		</span>
		<?php
	}

	/**
	 * A decorative stroked icon.
	 *
	 * @param string $path SVG path (24×24).
	 */
	private static function icon( string $path ): void {
		?>
		<svg class="sf-icon" width="20" height="20" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="<?php echo esc_attr( $path ); ?>" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
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

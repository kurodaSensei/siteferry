<?php
/**
 * Página de administración.
 *
 * @package Siteferry
 */

namespace Siteferry\Admin;

use Siteferry\Engine\Schema;
use Siteferry\Plugin;
use Siteferry\Support\Settings;
use Siteferry\Support\UndoPoint;

/**
 * Herramientas → Siteferry.
 */
final class Page {

	private const SLUG = 'siteferry';

	/**
	 * Registra el menú.
	 */
	public static function register(): void {
		$hook = add_management_page( 'Siteferry', 'Siteferry', 'manage_options', self::SLUG, array( self::class, 'render' ) );
		if ( $hook ) {
			add_action( 'load-' . $hook, array( self::class, 'load' ) );
		}
	}

	/**
	 * Al cargar la página: mu-plugin al día y assets.
	 */
	public static function load(): void {
		Plugin::install_compat();
		// La fecha del archivo en la versión: el navegador nunca se queda con un JS viejo.
		$version = static fn( string $file ) => SITEFERRY_VERSION . '.' . filemtime( SITEFERRY_PATH . $file );
		wp_enqueue_style( 'siteferry-admin', plugins_url( 'assets/siteferry-admin.css', SITEFERRY_FILE ), array(), $version( 'assets/siteferry-admin.css' ) );
		wp_enqueue_script( 'siteferry-admin', plugins_url( 'assets/siteferry-admin.js', SITEFERRY_FILE ), array( 'wp-api-fetch' ), $version( 'assets/siteferry-admin.js' ), true );
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
	 * Pinta la página. El formulario es HTML normal; el JS solo lo envía y muestra el progreso.
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
					<legend class="screen-reader-text"><?php esc_html_e( 'Acción', 'siteferry' ); ?></legend>
					<label class="siteferry-action">
						<input type="radio" name="intent" value="export" checked>
						<strong><?php esc_html_e( 'Exportar', 'siteferry' ); ?></strong>
						<span><?php esc_html_e( 'Descarga la base de datos en un .sql.gz, con reemplazos opcionales para el sitio de destino.', 'siteferry' ); ?></span>
					</label>
					<label class="siteferry-action">
						<input type="radio" name="intent" value="replace">
						<strong><?php esc_html_e( 'Buscar y reemplazar', 'siteferry' ); ?></strong>
						<span><?php esc_html_e( 'Cambia textos en este sitio, también dentro de datos serializados y JSON.', 'siteferry' ); ?></span>
					</label>
					<label class="siteferry-action">
						<input type="radio" name="intent" value="import">
						<strong><?php esc_html_e( 'Importar', 'siteferry' ); ?></strong>
						<span><?php esc_html_e( 'Reemplaza la base de datos de este sitio por la de un .sql o .sql.gz.', 'siteferry' ); ?></span>
					</label>
					<label class="siteferry-action">
						<input type="radio" name="intent" value="pull">
						<strong><?php esc_html_e( 'Pull', 'siteferry' ); ?></strong>
						<span><?php esc_html_e( 'Trae la base de datos de otro sitio y reemplaza la de este.', 'siteferry' ); ?></span>
					</label>
					<label class="siteferry-action">
						<input type="radio" name="intent" value="push">
						<strong><?php esc_html_e( 'Push', 'siteferry' ); ?></strong>
						<span><?php esc_html_e( 'Envía la base de datos de este sitio a otro y reemplaza la de allá.', 'siteferry' ); ?></span>
					</label>
				</fieldset>

				<div class="siteferry-section" data-show="pull push">
					<label for="siteferry-connection"><strong><?php esc_html_e( 'Sitio remoto', 'siteferry' ); ?></strong></label>
					<textarea id="siteferry-connection" name="connection" class="large-text code" rows="2" placeholder="https://otro-sitio.com&#10;clave…"></textarea>
					<p class="description"><?php esc_html_e( 'Copia la información de conexión desde Herramientas → Siteferry del otro sitio.', 'siteferry' ); ?></p>
					<p><button type="button" class="button" id="siteferry-connect"><?php esc_html_e( 'Conectar', 'siteferry' ); ?></button> <span id="siteferry-remote-info" class="description" aria-live="polite"></span></p>
				</div>

				<fieldset class="siteferry-section siteferry-options" data-show="pull push">
					<legend><strong><?php esc_html_e( 'Qué transferir', 'siteferry' ); ?></strong></legend>
					<label><input type="checkbox" name="database" checked> <?php esc_html_e( 'Base de datos', 'siteferry' ); ?></label>
					<label><input type="checkbox" name="files" value="uploads"> <?php esc_html_e( 'Media (uploads)', 'siteferry' ); ?></label>
					<label><input type="checkbox" name="files" value="themes"> <?php esc_html_e( 'Temas', 'siteferry' ); ?></label>
					<label><input type="checkbox" name="files" value="plugins"> <?php esc_html_e( 'Plugins', 'siteferry' ); ?></label>
					<label><input type="checkbox" name="files" value="mu-plugins"> <?php esc_html_e( 'Plugins obligatorios (mu-plugins)', 'siteferry' ); ?></label>
					<label><input type="checkbox" name="delete_files"> <?php esc_html_e( 'Borrar en el destino los archivos que no existen en el origen (se guardan en un backup 7 días)', 'siteferry' ); ?></label>
					<span class="description"><?php esc_html_e( 'Solo viaja lo que cambió. Temas y plugins se cambian por carpeta completa al final, y Siteferry nunca se reemplaza a sí mismo. En un push, el otro sitio debe permitir «push de código» para temas y plugins.', 'siteferry' ); ?></span>
				</fieldset>

				<fieldset class="siteferry-section siteferry-options" data-show="export">
					<legend><strong><?php esc_html_e( 'Incluir archivos (paquete de sitio completo .sfy)', 'siteferry' ); ?></strong></legend>
					<label><input type="checkbox" name="export_files" value="uploads"> <?php esc_html_e( 'Media (uploads)', 'siteferry' ); ?></label>
					<label><input type="checkbox" name="export_files" value="themes"> <?php esc_html_e( 'Temas', 'siteferry' ); ?></label>
					<label><input type="checkbox" name="export_files" value="plugins"> <?php esc_html_e( 'Plugins', 'siteferry' ); ?></label>
					<label><input type="checkbox" name="export_files" value="mu-plugins"> <?php esc_html_e( 'Plugins obligatorios (mu-plugins)', 'siteferry' ); ?></label>
					<span class="description"><?php esc_html_e( 'Sin archivos se descarga la base de datos (.sql.gz). Con archivos, un paquete .sfy con todo, que se importa en otro sitio con Siteferry.', 'siteferry' ); ?></span>
				</fieldset>

				<div class="siteferry-section" data-show="import">
					<label for="siteferry-file"><strong><?php esc_html_e( 'Archivo', 'siteferry' ); ?></strong></label>
					<input type="file" id="siteferry-file" accept=".sql,.gz,.sfy">
					<p class="description"><?php esc_html_e( 'mysqldump, phpMyAdmin, una exportación .sql.gz o un paquete de sitio .sfy de Siteferry. El prefijo de tablas y la URL del sitio de origen se detectan solos.', 'siteferry' ); ?></p>
					<p>
						<label for="siteferry-source-prefix"><?php esc_html_e( 'Prefijo de tablas del archivo (opcional)', 'siteferry' ); ?></label>
						<input type="text" id="siteferry-source-prefix" name="source_prefix" class="regular-text code" placeholder="wp_" pattern="[A-Za-z0-9_]*">
						<span class="description"><?php esc_html_e( 'Solo si el archivo trae varias instalaciones de WordPress; el plugin te avisa.', 'siteferry' ); ?></span>
					</p>
				</div>

				<div class="siteferry-section">
					<h2><?php esc_html_e( 'Reemplazos', 'siteferry' ); ?></h2>
					<table class="widefat siteferry-pairs">
						<thead><tr><th><?php esc_html_e( 'Buscar', 'siteferry' ); ?></th><th><?php esc_html_e( 'Reemplazar por', 'siteferry' ); ?></th><th><span class="screen-reader-text"><?php esc_html_e( 'Quitar', 'siteferry' ); ?></span></th></tr></thead>
						<tbody>
							<tr>
								<td><input type="text" class="large-text" name="find" value="<?php echo esc_attr( $home ); ?>" aria-label="<?php esc_attr_e( 'Buscar', 'siteferry' ); ?>"></td>
								<td><input type="text" class="large-text" name="replace" placeholder="https://destino.test" aria-label="<?php esc_attr_e( 'Reemplazar por', 'siteferry' ); ?>"></td>
								<td><button type="button" class="button-link siteferry-remove" aria-label="<?php esc_attr_e( 'Quitar par', 'siteferry' ); ?>">✕</button></td>
							</tr>
						</tbody>
					</table>
					<p><button type="button" class="button" id="siteferry-add-pair"><?php esc_html_e( 'Añadir par', 'siteferry' ); ?></button></p>
					<p class="siteferry-options">
						<label data-show="import pull push"><input type="checkbox" name="auto_url" checked> <?php esc_html_e( 'Cambiar automáticamente la URL del sitio de origen por la del destino', 'siteferry' ); ?></label>
						<label><input type="checkbox" name="case_sensitive"> <?php esc_html_e( 'Distinguir mayúsculas', 'siteferry' ); ?></label>
						<label><input type="checkbox" name="regex"> <?php esc_html_e( 'Expresiones regulares (PCRE con delimitadores, p. ej. #https?://viejo\.test#)', 'siteferry' ); ?></label>
						<label><input type="checkbox" name="replace_guids"> <?php esc_html_e( 'Reemplazar también en guid (normalmente no se debe)', 'siteferry' ); ?></label>
					</p>
				</div>

				<details class="siteferry-section" data-hide="import">
					<summary><strong id="siteferry-tables-title"><?php echo esc_html( sprintf( /* translators: %d: número de tablas */ __( 'Tablas (%d)', 'siteferry' ), count( $tables ) ) ); ?></strong></summary>
					<p><button type="button" class="button-link" id="siteferry-toggle-tables"><?php esc_html_e( 'Marcar / desmarcar todas', 'siteferry' ); ?></button></p>
					<ul class="siteferry-tables" id="siteferry-table-list">
						<?php foreach ( $tables as $name => $info ) : ?>
							<li><label><input type="checkbox" name="tables" value="<?php echo esc_attr( $name ); ?>" checked> <code><?php echo esc_html( $name ); ?></code> <span class="description"><?php echo esc_html( size_format( $info['bytes'] ) . ' · ~' . number_format_i18n( $info['rows'] ) ); ?></span></label></li>
						<?php endforeach; ?>
					</ul>
				</details>

				<p class="siteferry-section" data-hide="export">
					<label><input type="checkbox" name="backup" checked> <?php esc_html_e( 'Crear un backup de la base de datos de destino antes (se conserva 7 días)', 'siteferry' ); ?></label>
				</p>

				<p><button type="submit" class="button button-primary button-hero" id="siteferry-run"><?php esc_html_e( 'Iniciar', 'siteferry' ); ?></button></p>
			</form>

			<section id="siteferry-progress" class="siteferry-card" hidden aria-live="polite">
				<h2 id="siteferry-status"></h2>
				<progress id="siteferry-bar" max="100" value="0"></progress>
				<p id="siteferry-step" class="description"></p>
				<div id="siteferry-message"></div>
				<p id="siteferry-files"></p>
				<p>
					<button type="button" class="button button-primary" id="siteferry-resume" hidden><?php esc_html_e( 'Continuar', 'siteferry' ); ?></button>
					<button type="button" class="button" id="siteferry-cancel" hidden><?php esc_html_e( 'Cancelar', 'siteferry' ); ?></button>
					<button type="button" class="button" id="siteferry-new" hidden><?php esc_html_e( 'Nueva operación', 'siteferry' ); ?></button>
				</p>
				<details><summary><?php esc_html_e( 'Registro', 'siteferry' ); ?></summary><pre id="siteferry-log"></pre></details>
			</section>

			<section id="siteferry-undo" class="siteferry-card" <?php echo $undo ? '' : 'hidden'; ?>>
				<h2><?php esc_html_e( 'Deshacer la última operación', 'siteferry' ); ?></h2>
				<p id="siteferry-undo-info"><?php echo esc_html( self::undo_text( $undo ) ); ?></p>
				<p><button type="button" class="button" id="siteferry-undo-run"><?php esc_html_e( 'Deshacer', 'siteferry' ); ?></button></p>
			</section>

			<section id="siteferry-settings" class="siteferry-card">
				<h2><?php esc_html_e( 'Conexión de este sitio', 'siteferry' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Pega esto en el otro sitio para hacer pull o push con este. Quien tenga la clave puede leer o reemplazar esta base de datos según los permisos de abajo: trátala como una contraseña.', 'siteferry' ); ?></p>
				<textarea id="siteferry-own-connection" class="large-text code" rows="2" readonly><?php echo esc_textarea( $settings['connection'] ); ?></textarea>
				<p>
					<button type="button" class="button" id="siteferry-copy-connection"><?php esc_html_e( 'Copiar', 'siteferry' ); ?></button>
					<button type="button" class="button-link button-link-delete" id="siteferry-regenerate"><?php esc_html_e( 'Regenerar clave', 'siteferry' ); ?></button>
				</p>
				<fieldset class="siteferry-options">
					<legend class="screen-reader-text"><?php esc_html_e( 'Permisos', 'siteferry' ); ?></legend>
					<label><input type="checkbox" name="allow_pull" <?php checked( $settings['allow_pull'] ); ?>> <?php esc_html_e( 'Permitir pull: otros sitios pueden leer esta base de datos', 'siteferry' ); ?></label>
					<label><input type="checkbox" name="allow_pull_files" <?php checked( $settings['allow_pull_files'] ); ?>> <?php esc_html_e( 'Permitir pull de archivos: otros sitios pueden leer media, temas y plugins (incluido su código)', 'siteferry' ); ?></label>
					<label><input type="checkbox" name="allow_push" <?php checked( $settings['allow_push'] ); ?>> <?php esc_html_e( 'Permitir push: otros sitios pueden reemplazar esta base de datos', 'siteferry' ); ?></label>
					<label><input type="checkbox" name="allow_push_code" <?php checked( $settings['allow_push_code'] ); ?>> <?php esc_html_e( 'Permitir push de código: otros sitios pueden reemplazar temas y plugins de este sitio', 'siteferry' ); ?></label>
					<label><input type="checkbox" name="verify_ssl" <?php checked( $settings['verify_ssl'] ); ?>> <?php esc_html_e( 'Verificar el certificado HTTPS al conectarse a otros sitios (desactívalo solo en desarrollo local)', 'siteferry' ); ?></label>
				</fieldset>
				<p id="siteferry-settings-status" class="description" aria-live="polite"></p>
			</section>
		</div>
		<?php
	}

	/**
	 * Descripción de un punto de deshacer (la misma que arma la interfaz al actualizarlo).
	 *
	 * @param array|null $undo Punto.
	 */
	private static function undo_text( ?array $undo ): string {
		if ( ! $undo ) {
			return '';
		}
		$parts = array();
		if ( $undo['db_backup'] ) {
			$parts[] = __( 'base de datos', 'siteferry' );
		}
		if ( $undo['roots'] ) {
			$parts[] = __( 'archivos', 'siteferry' ) . ' (' . implode( ', ', $undo['roots'] ) . ')';
		}
		/* translators: 1: operación, 2: fecha, 3: qué se restaura */
		return sprintf( __( '%1$s · %2$s · se restaura: %3$s.', 'siteferry' ), $undo['label'], wp_date( 'Y-m-d H:i', (int) $undo['time'] ), implode( ' y ', $parts ) );
	}
}

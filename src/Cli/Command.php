<?php
/**
 * Comandos WP-CLI.
 *
 * @package Siteferry
 */

namespace Siteferry\Cli;

use Siteferry\Job\JobStore;
use Siteferry\Job\Runner;
use Siteferry\Plugin;
use Siteferry\Support\Settings;
use Siteferry\Support\UndoPoint;

/**
 * Exporta, importa y reemplaza en la base de datos con Siteferry.
 */
final class Command {

	/**
	 * Exporta la base de datos a un .sql.gz, con reemplazos opcionales.
	 *
	 * ## OPTIONS
	 *
	 * [<pair>...]
	 * : Pares buscar/reemplazar: `viejo nuevo [viejo2 nuevo2]`.
	 *
	 * [--tables=<tables>]
	 * : Tablas separadas por coma (por defecto, todas las del prefijo).
	 *
	 * [--output=<file>]
	 * : Copiar el resultado a esta ruta.
	 *
	 * [--media]
	 * : Incluir uploads: el resultado es un paquete de sitio .sfy (BD + archivos).
	 *
	 * [--themes]
	 * : Incluir temas en el paquete .sfy.
	 *
	 * [--plugins]
	 * : Incluir plugins en el paquete .sfy.
	 *
	 * [--mu-plugins]
	 * : Incluir mu-plugins en el paquete .sfy.
	 *
	 * [--regex]
	 * : Los textos a buscar son expresiones regulares PCRE con delimitadores.
	 *
	 * [--case-sensitive]
	 * : Distinguir mayúsculas.
	 *
	 * [--guids]
	 * : Reemplazar también en la columna guid.
	 *
	 * ## EXAMPLES
	 *
	 *     wp siteferry export https://sitio.com http://sitio.test --output=sitio.sql.gz
	 *     wp siteferry export --media --themes --plugins --output=sitio.sfy
	 *
	 * @param array $args  Posicionales.
	 * @param array $assoc Opciones.
	 */
	public function export( array $args, array $assoc ): void {
		$job  = $this->run( 'export', array( 'files' => $this->roots( $assoc ) ) + $this->input( $args, $assoc ) );
		$file = $job['files']['export'];
		if ( isset( $assoc['output'] ) ) {
			copy( $file, $assoc['output'] );
			$file = $assoc['output'];
		}
		\WP_CLI::success( sprintf( 'Exportado: %s (%s filas)', $file, number_format( $job['rows'] ) ) );
	}

	/**
	 * Busca y reemplaza en la base de datos (seguro para datos serializados).
	 *
	 * ## OPTIONS
	 *
	 * <pair>...
	 * : Pares buscar/reemplazar: `viejo nuevo [viejo2 nuevo2]`.
	 *
	 * [--tables=<tables>]
	 * : Tablas separadas por coma (por defecto, todas las del prefijo).
	 *
	 * [--backup]
	 * : Crear un backup antes (por defecto). Usa --no-backup para omitirlo.
	 *
	 * [--regex]
	 * : Los textos a buscar son expresiones regulares PCRE con delimitadores.
	 *
	 * [--case-sensitive]
	 * : Distinguir mayúsculas.
	 *
	 * [--guids]
	 * : Reemplazar también en la columna guid.
	 *
	 * ## EXAMPLES
	 *
	 *     wp siteferry replace https://sitio.com http://sitio.test
	 *
	 * @param array $args  Posicionales.
	 * @param array $assoc Opciones.
	 */
	public function replace( array $args, array $assoc ): void {
		$job = $this->run( 'replace', $this->input( $args, $assoc ) );
		\WP_CLI::success( sprintf( 'Reemplazo terminado (%s filas).', number_format( $job['rows'] ) ) );
		$this->backup_notice( $job );
	}

	/**
	 * Importa un .sql o .sql.gz (mysqldump, phpMyAdmin o Siteferry).
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Archivo a importar.
	 *
	 * [<pair>...]
	 * : Pares buscar/reemplazar adicionales.
	 *
	 * [--backup]
	 * : Crear un backup antes (por defecto). Usa --no-backup para omitirlo.
	 *
	 * [--source-prefix=<prefix>]
	 * : Prefijo de tablas a importar si el archivo trae varias instalaciones de WordPress.
	 *
	 * [--auto-url]
	 * : Reemplazar la URL del sitio de origen por la de este sitio (por defecto). Usa --no-auto-url para omitirlo.
	 *
	 * [--regex]
	 * : Los textos a buscar son expresiones regulares PCRE con delimitadores.
	 *
	 * [--case-sensitive]
	 * : Distinguir mayúsculas.
	 *
	 * ## EXAMPLES
	 *
	 *     wp siteferry import produccion.sql.gz
	 *
	 * @param array $args  Posicionales.
	 * @param array $assoc Opciones.
	 */
	public function import( array $args, array $assoc ): void {
		$file = (string) array_shift( $args );
		if ( ! is_readable( $file ) ) {
			\WP_CLI::error( 'No se puede leer ' . $file );
		}
		$input         = $this->input( $args, $assoc );
		$input['file'] = (string) realpath( $file );
		$job           = $this->run( 'import', $input );
		\WP_CLI::success( sprintf( 'Importado (%s filas).', number_format( $job['rows'] ) ) );
		$this->backup_notice( $job );
	}

	/**
	 * Trae la base de datos de otro sitio y reemplaza la de este (pull).
	 *
	 * ## OPTIONS
	 *
	 * <url>
	 * : URL del otro sitio.
	 *
	 * <key>
	 * : Clave de conexión del otro sitio (`wp siteferry connection` allí).
	 *
	 * [<pair>...]
	 * : Pares buscar/reemplazar adicionales.
	 *
	 * [--tables=<tables>]
	 * : Tablas del otro sitio separadas por coma (por defecto, todas las de su prefijo).
	 *
	 * [--backup]
	 * : Backup de este sitio antes (por defecto). Usa --no-backup para omitirlo.
	 *
	 * [--auto-url]
	 * : Cambiar la URL del otro sitio por la de este (por defecto). Usa --no-auto-url para omitirlo.
	 *
	 * [--media]
	 * : Transferir también la media (uploads).
	 *
	 * [--themes]
	 * : Transferir también los temas.
	 *
	 * [--plugins]
	 * : Transferir también los plugins.
	 *
	 * [--mu-plugins]
	 * : Transferir también los plugins obligatorios (mu-plugins).
	 *
	 * [--delete]
	 * : Borrar en el destino los archivos que no existen en el origen (van a un backup 7 días).
	 *
	 * [--files-only]
	 * : Solo archivos, sin base de datos.
	 *
	 * [--regex]
	 * : Los textos a buscar son expresiones regulares PCRE con delimitadores.
	 *
	 * [--case-sensitive]
	 * : Distinguir mayúsculas.
	 *
	 * [--guids]
	 * : Reemplazar también en la columna guid.
	 *
	 * ## EXAMPLES
	 *
	 *     wp siteferry pull https://produccion.com abc123...
	 *     wp siteferry pull https://produccion.com abc123... --media --files-only
	 *
	 * @param array $args  Posicionales.
	 * @param array $assoc Opciones.
	 */
	public function pull( array $args, array $assoc ): void {
		$this->remote( 'pull', $args, $assoc );
	}

	/**
	 * Envía la base de datos de este sitio a otro y reemplaza la de allá (push).
	 *
	 * ## OPTIONS
	 *
	 * <url>
	 * : URL del otro sitio.
	 *
	 * <key>
	 * : Clave de conexión del otro sitio (`wp siteferry connection` allí).
	 *
	 * [<pair>...]
	 * : Pares buscar/reemplazar adicionales.
	 *
	 * [--tables=<tables>]
	 * : Tablas de este sitio separadas por coma (por defecto, todas las del prefijo).
	 *
	 * [--backup]
	 * : Backup del otro sitio antes, guardado allá (por defecto). Usa --no-backup para omitirlo.
	 *
	 * [--auto-url]
	 * : Cambiar la URL de este sitio por la del otro (por defecto). Usa --no-auto-url para omitirlo.
	 *
	 * [--media]
	 * : Transferir también la media (uploads).
	 *
	 * [--themes]
	 * : Transferir también los temas.
	 *
	 * [--plugins]
	 * : Transferir también los plugins.
	 *
	 * [--mu-plugins]
	 * : Transferir también los plugins obligatorios (mu-plugins).
	 *
	 * [--delete]
	 * : Borrar en el destino los archivos que no existen en el origen (van a un backup 7 días).
	 *
	 * [--files-only]
	 * : Solo archivos, sin base de datos.
	 *
	 * [--regex]
	 * : Los textos a buscar son expresiones regulares PCRE con delimitadores.
	 *
	 * [--case-sensitive]
	 * : Distinguir mayúsculas.
	 *
	 * [--guids]
	 * : Reemplazar también en la columna guid.
	 *
	 * ## EXAMPLES
	 *
	 *     wp siteferry push https://staging.sitio.com abc123...
	 *     wp siteferry push https://staging.sitio.com abc123... --themes --plugins --files-only
	 *
	 * @param array $args  Posicionales.
	 * @param array $assoc Opciones.
	 */
	public function push( array $args, array $assoc ): void {
		$this->remote( 'push', $args, $assoc );
	}

	/**
	 * Muestra la información de conexión de este sitio y ajusta qué pueden hacer otros sitios.
	 *
	 * ## OPTIONS
	 *
	 * [--allow-pull=<on|off>]
	 * : Permitir que otros sitios lean esta base de datos.
	 *
	 * [--allow-pull-files=<on|off>]
	 * : Permitir que otros sitios lean media, temas y plugins (incluido su código).
	 *
	 * [--allow-push=<on|off>]
	 * : Permitir que otros sitios reemplacen esta base de datos.
	 *
	 * [--allow-push-code=<on|off>]
	 * : Permitir que otros sitios reemplacen temas y plugins (código) en un push.
	 *
	 * [--verify-ssl=<on|off>]
	 * : Verificar el certificado HTTPS al conectarse a otros sitios.
	 *
	 * [--regenerate-key]
	 * : Crear una clave nueva (los sitios con la anterior dejan de conectarse).
	 *
	 * ## EXAMPLES
	 *
	 *     wp siteferry connection --allow-pull=on
	 *
	 * @param array $args  Posicionales.
	 * @param array $assoc Opciones.
	 */
	public function connection( array $args, array $assoc ): void {
		$values = array();
		foreach ( array( 'allow-pull', 'allow-pull-files', 'allow-push', 'allow-push-code', 'verify-ssl' ) as $flag ) {
			if ( isset( $assoc[ $flag ] ) ) {
				$values[ str_replace( '-', '_', $flag ) ] = in_array( strtolower( (string) $assoc[ $flag ] ), array( 'on', '1', 'yes', 'si', 'sí', 'true' ), true );
			}
		}
		$settings = Settings::save( $values );
		if ( isset( $assoc['regenerate-key'] ) ) {
			Settings::regenerate();
		}
		\WP_CLI::log( 'Pull: ' . ( $settings['allow_pull'] ? 'permitido' : 'no' ) . ' · Pull de archivos: ' . ( $settings['allow_pull_files'] ? 'permitido' : 'no' ) . ' · Push: ' . ( $settings['allow_push'] ? 'permitido' : 'no' ) . ' · Push de código: ' . ( $settings['allow_push_code'] ? 'permitido' : 'no' ) . ' · Verificar SSL: ' . ( $settings['verify_ssl'] ? 'sí' : 'no' ) );
		\WP_CLI::log( "Información de conexión:\n" . Settings::connection() );
	}

	/**
	 * Pull o push.
	 *
	 * @param string $intent pull | push.
	 * @param array  $args   url, key y pares.
	 * @param array  $assoc  Opciones.
	 */
	private function remote( string $intent, array $args, array $assoc ): void {
		$url                   = (string) array_shift( $args );
		$key                   = (string) array_shift( $args );
		$input                 = $this->input( $args, $assoc );
		$input['connection']   = $url . ' ' . $key;
		$input['files']        = $this->roots( $assoc );
		$input['delete_files'] = (bool) \WP_CLI\Utils\get_flag_value( $assoc, 'delete', false );
		$input['database']     = ! \WP_CLI\Utils\get_flag_value( $assoc, 'files-only', false );
		$job                   = $this->run( $intent, $input );
		\WP_CLI::success(
			sprintf(
				'%s terminado (%s filas, %s archivos).',
				'pull' === $intent ? 'Pull' : 'Push',
				number_format( $job['rows'] ),
				number_format( array_sum( array_column( $job['file_stats'], 'sent' ) ) )
			)
		);
		$this->backup_notice( $job );
	}

	/**
	 * Deshace la última operación que modificó este sitio (reemplazo, importación, pull o push recibido).
	 *
	 * ## OPTIONS
	 *
	 * [--yes]
	 * : No pedir confirmación.
	 *
	 * @param array $args  Posicionales.
	 * @param array $assoc Opciones.
	 */
	public function undo( array $args, array $assoc ): void {
		$point = UndoPoint::get();
		if ( ! $point ) {
			\WP_CLI::error( 'No hay nada que deshacer.' );
		}
		\WP_CLI::confirm( sprintf( 'Deshacer "%s" del %s? Lo cambiado después se pierde.', $point['label'], wp_date( 'Y-m-d H:i', (int) $point['time'] ) ), $assoc );
		$this->run( 'undo', array() );
		\WP_CLI::success( 'Deshecho.' );
	}

	/**
	 * Carpetas pedidas con --media, --themes, --plugins y --mu-plugins.
	 *
	 * @param array $assoc Opciones.
	 * @return string[]
	 */
	private function roots( array $assoc ): array {
		$flags = array(
			'media'      => 'uploads',
			'themes'     => 'themes',
			'plugins'    => 'plugins',
			'mu-plugins' => 'mu-plugins',
		);
		return array_values( array_filter( $flags, static fn( $flag ) => \WP_CLI\Utils\get_flag_value( $assoc, $flag, false ), ARRAY_FILTER_USE_KEY ) );
	}

	/**
	 * Opciones comunes.
	 *
	 * @param array $args  Pares.
	 * @param array $assoc Opciones.
	 */
	private function input( array $args, array $assoc ): array {
		if ( count( $args ) % 2 ) {
			\WP_CLI::error( 'Los pares buscar/reemplazar van de dos en dos.' );
		}
		return array(
			'pairs'          => array_chunk( $args, 2 ),
			'tables'         => isset( $assoc['tables'] ) ? array_map( 'trim', explode( ',', $assoc['tables'] ) ) : array(),
			'regex'          => \WP_CLI\Utils\get_flag_value( $assoc, 'regex', false ),
			'case_sensitive' => \WP_CLI\Utils\get_flag_value( $assoc, 'case-sensitive', false ),
			'replace_guids'  => \WP_CLI\Utils\get_flag_value( $assoc, 'guids', false ),
			'backup'         => \WP_CLI\Utils\get_flag_value( $assoc, 'backup', true ),
			'auto_url'       => \WP_CLI\Utils\get_flag_value( $assoc, 'auto-url', true ),
			'source_prefix'  => (string) ( $assoc['source-prefix'] ?? '' ),
		);
	}

	/**
	 * Crea el job y lo corre hasta el final con una barra de progreso.
	 *
	 * @param string $intent Acción.
	 * @param array  $input  Opciones.
	 */
	private function run( string $intent, array $input ): array {
		try {
			$job = Plugin::create_job( $intent, $input );
		} catch ( \Exception $e ) {
			\WP_CLI::error( $e->getMessage() );
		}
		$bar  = \WP_CLI\Utils\make_progress_bar( 'Siteferry', 100 );
		$seen = 0;
		while ( Runner::is_open( $job ) ) {
			$job     = Plugin::run_step( $job, 10 );
			$percent = Runner::percent( $job );
			$bar->tick( $percent - $seen );
			$seen = $percent;
		}
		$bar->finish();
		foreach ( $job['warnings'] as $warning ) {
			\WP_CLI::warning( $warning );
		}
		if ( 'done' !== $job['status'] ) {
			// Sin interfaz para "Cancelar": se limpia ya (tablas temporales y, en un push, el sitio remoto).
			JobStore::save( Plugin::runner()->cancel( $job ) );
			\WP_CLI::error( (string) $job['error'] );
		}
		return $job;
	}

	/**
	 * Dónde quedó el backup.
	 *
	 * @param array $job Job.
	 */
	private function backup_notice( array $job ): void {
		if ( isset( $job['files']['backup'] ) ) {
			\WP_CLI::log( 'Backup previo: ' . $job['files']['backup'] );
		}
	}
}

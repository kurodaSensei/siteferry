<?php
/**
 * Rutas REST locales.
 *
 * @package Siteferry
 */

namespace Siteferry\Http;

use Siteferry\Engine\Schema;
use Siteferry\Job\JobStore;
use Siteferry\Job\Runner;
use Siteferry\Plugin;
use Siteferry\Remote\Signer;
use Siteferry\Support\Settings;
use Siteferry\Support\Storage;
use Siteferry\Support\UndoPoint;

/**
 * Rutas `siteferry/v1`. Todas exigen manage_options; el nonce lo valida la REST de WordPress.
 */
final class RestController {

	private const NS = 'siteferry/v1';

	/**
	 * Registra las rutas.
	 */
	public static function register(): void {
		$routes = array(
			'/tables'                             => array( 'GET', 'tables' ),
			'/jobs'                               => array( 'POST', 'create' ),
			'/jobs/current'                       => array( 'GET', 'current' ),
			'/jobs/(?P<id>[a-f0-9]{16})/step'     => array( 'POST', 'step' ),
			'/jobs/(?P<id>[a-f0-9]{16})/cancel'   => array( 'POST', 'cancel' ),
			'/jobs/(?P<id>[a-f0-9]{16})/download' => array( 'GET', 'download' ),
			'/uploads'                            => array( 'POST', 'upload' ),
			'/remote/hello'                       => array( 'POST', 'hello' ),
			'/settings'                           => array( 'GET, POST', 'settings' ),
			'/settings/key'                       => array( 'POST', 'regenerate_key' ),
			'/undo'                               => array( 'GET, POST', 'undo' ),
		);
		foreach ( $routes as $route => [ $method, $callback ] ) {
			register_rest_route(
				self::NS,
				$route,
				array(
					'methods'             => $method,
					'callback'            => array( self::class, $callback ),
					'permission_callback' => static fn() => current_user_can( 'manage_options' ),
				)
			);
		}
	}

	/**
	 * Tablas del sitio.
	 */
	public static function tables(): \WP_REST_Response {
		global $wpdb;
		return new \WP_REST_Response( ( new Schema( Plugin::db() ) )->stats( $wpdb->prefix ) );
	}

	/**
	 * Crea un job.
	 *
	 * @param \WP_REST_Request $request Petición.
	 */
	public static function create( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$intent = (string) $request->get_param( 'intent' );
		$input  = array(
			'tables'         => array_map( 'strval', (array) $request->get_param( 'tables' ) ),
			'pairs'          => array(),
			'case_sensitive' => (bool) $request->get_param( 'case_sensitive' ),
			'regex'          => (bool) $request->get_param( 'regex' ),
			'replace_guids'  => (bool) $request->get_param( 'replace_guids' ),
			'backup'         => (bool) $request->get_param( 'backup' ),
			'auto_url'       => (bool) $request->get_param( 'auto_url' ),
			'source_prefix'  => (string) $request->get_param( 'source_prefix' ),
			'connection'     => (string) $request->get_param( 'connection' ),
			'files'          => array_map( 'strval', (array) $request->get_param( 'files' ) ),
			'delete_files'   => (bool) $request->get_param( 'delete_files' ),
		);
		if ( null !== $request->get_param( 'database' ) ) {
			$input['database'] = (bool) $request->get_param( 'database' );
		}
		foreach ( (array) $request->get_param( 'pairs' ) as $pair ) {
			$input['pairs'][] = array( (string) ( $pair['find'] ?? '' ), (string) ( $pair['replace'] ?? '' ) );
		}
		if ( 'import' === $intent ) {
			$path = Storage::upload_path( (string) $request->get_param( 'upload' ), (string) $request->get_param( 'ext' ) );
			if ( ! $path || ! is_file( $path ) ) {
				return new \WP_Error( 'siteferry_upload', __( 'No se encontró el archivo subido.', 'siteferry' ), array( 'status' => 400 ) );
			}
			$input['file']       = $path;
			$input['file_owned'] = true;
		}
		try {
			return new \WP_REST_Response( Plugin::view( Plugin::create_job( $intent, $input ) ), 201 );
		} catch ( \InvalidArgumentException $e ) {
			return new \WP_Error( 'siteferry_invalid', $e->getMessage(), array( 'status' => 400 ) );
		} catch ( \RuntimeException $e ) {
			return new \WP_Error( 'siteferry_error', $e->getMessage(), array( 'status' => 400 ) );
		}
	}

	/**
	 * Prueba la conexión con otro sitio y devuelve sus datos (para elegir tablas).
	 *
	 * @param \WP_REST_Request $request Petición.
	 */
	public static function hello( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		try {
			$remote = Signer::parse_connection( (string) $request->get_param( 'connection' ) ) + array( 'verify_ssl' => Settings::get()['verify_ssl'] );
			return new \WP_REST_Response( Plugin::hello( $remote ) );
		} catch ( \Exception $e ) {
			return new \WP_Error( 'siteferry_remote', $e->getMessage(), array( 'status' => 400 ) );
		}
	}

	/**
	 * Lee o guarda los ajustes de conexión de este sitio.
	 *
	 * @param \WP_REST_Request $request Petición.
	 */
	public static function settings( \WP_REST_Request $request ): \WP_REST_Response {
		if ( 'POST' === $request->get_method() ) {
			Settings::save( array_intersect_key( (array) $request->get_json_params(), array_flip( array( 'allow_pull', 'allow_pull_files', 'allow_push', 'allow_push_code', 'verify_ssl' ) ) ) );
		}
		return new \WP_REST_Response( Settings::get() + array( 'connection' => Settings::connection() ) );
	}

	/**
	 * Punto de deshacer (GET) o empezar a deshacer (POST).
	 *
	 * @param \WP_REST_Request $request Petición.
	 */
	public static function undo( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		if ( 'GET' === $request->get_method() ) {
			return new \WP_REST_Response( UndoPoint::get() );
		}
		try {
			return new \WP_REST_Response( Plugin::view( Plugin::create_job( 'undo', array() ) ), 201 );
		} catch ( \Exception $e ) {
			return new \WP_Error( 'siteferry_undo', $e->getMessage(), array( 'status' => 400 ) );
		}
	}

	/**
	 * Genera una clave nueva.
	 */
	public static function regenerate_key(): \WP_REST_Response {
		Settings::regenerate();
		return new \WP_REST_Response( Settings::get() + array( 'connection' => Settings::connection() ) );
	}

	/**
	 * Job actual (para retomar al recargar la página).
	 */
	public static function current(): \WP_REST_Response {
		return new \WP_REST_Response( Plugin::view( JobStore::get() ) );
	}

	/**
	 * Avanza el job.
	 *
	 * @param \WP_REST_Request $request Petición.
	 */
	public static function step( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$job = self::job( $request );
		if ( is_wp_error( $job ) ) {
			return $job;
		}
		if ( ! Runner::is_open( $job ) ) {
			return new \WP_REST_Response( Plugin::view( $job ) );
		}
		if ( ! JobStore::lock() ) {
			return new \WP_Error( 'siteferry_locked', __( 'Otro paso está en curso.', 'siteferry' ), array( 'status' => 409 ) );
		}
		try {
			$job = Plugin::run_step( JobStore::get() ?? $job, Plugin::step_seconds() );
		} finally {
			JobStore::unlock();
		}
		return new \WP_REST_Response( Plugin::view( $job ) );
	}

	/**
	 * Cancela el job.
	 *
	 * @param \WP_REST_Request $request Petición.
	 */
	public static function cancel( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$job = self::job( $request );
		if ( is_wp_error( $job ) ) {
			return $job;
		}
		if ( ! JobStore::lock( 30 ) ) {
			return new \WP_Error( 'siteferry_locked', __( 'Hay un paso en curso; inténtalo de nuevo.', 'siteferry' ), array( 'status' => 409 ) );
		}
		try {
			$job = JobStore::get() ?? $job;
			if ( Runner::is_open( $job ) || 'failed' === $job['status'] ) {
				$job = Plugin::runner()->cancel( $job );
				JobStore::save( $job );
			}
		} finally {
			JobStore::unlock();
		}
		return new \WP_REST_Response( Plugin::view( $job ) );
	}

	/**
	 * Descarga la exportación o el backup.
	 *
	 * @param \WP_REST_Request $request Petición.
	 */
	public static function download( \WP_REST_Request $request ): \WP_Error {
		$job = self::job( $request );
		if ( is_wp_error( $job ) ) {
			return $job;
		}
		$key   = 'backup' === $request->get_param( 'file' ) ? 'backup' : 'export';
		$files = Plugin::view( $job )['files'];
		if ( ! isset( $files[ $key ] ) ) {
			return new \WP_Error( 'siteferry_file', __( 'El archivo no está disponible.', 'siteferry' ), array( 'status' => 404 ) );
		}
		$path = $job['files'][ $key ];
		while ( ob_get_level() ) {
			ob_end_clean();
		}
		nocache_headers();
		header( 'Content-Type: ' . ( str_ends_with( $path, '.sfy' ) ? 'application/octet-stream' : 'application/gzip' ) );
		header( 'Content-Disposition: attachment; filename="' . basename( $path ) . '"' );
		header( 'Content-Length: ' . filesize( $path ) );
		readfile( $path );
		exit;
	}

	/**
	 * Recibe un trozo de un .sql / .sql.gz / .sfy. El cliente manda los trozos en orden con su offset.
	 *
	 * @param \WP_REST_Request $request Petición.
	 */
	public static function upload( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$name = strtolower( (string) $request->get_param( 'name' ) );
		$ext  = match ( true ) {
			str_ends_with( $name, '.sql.gz' ) => 'sql.gz',
			str_ends_with( $name, '.sql' )    => 'sql',
			str_ends_with( $name, '.sfy' )    => 'sfy',
			default                           => '',
		};
		$token = (string) $request->get_param( 'upload' );
		$token = '' === $token ? bin2hex( random_bytes( 8 ) ) : $token;
		$path  = Storage::upload_path( $token, $ext );
		$chunk = $request->get_file_params()['chunk'] ?? null;
		if ( ! $path ) {
			return new \WP_Error( 'siteferry_upload', __( 'Solo se aceptan archivos .sql, .sql.gz o .sfy.', 'siteferry' ), array( 'status' => 400 ) );
		}
		if ( ! $chunk || UPLOAD_ERR_OK !== $chunk['error'] || ! is_uploaded_file( $chunk['tmp_name'] ) ) {
			return new \WP_Error( 'siteferry_upload', __( 'No llegó el trozo del archivo.', 'siteferry' ), array( 'status' => 400 ) );
		}
		$size   = is_file( $path ) ? (int) filesize( $path ) : 0;
		$offset = (int) $request->get_param( 'offset' );
		if ( $offset !== $size ) {
			return new \WP_Error(
				'siteferry_offset',
				__( 'El trozo no está en orden.', 'siteferry' ),
				array(
					'status' => 409,
					'size'   => $size,
				)
			);
		}
		$in  = fopen( $chunk['tmp_name'], 'rb' );
		$out = fopen( $path, 'ab' );
		stream_copy_to_stream( $in, $out );
		fclose( $in );
		fclose( $out );
		clearstatcache( true, $path );

		return new \WP_REST_Response(
			array(
				'upload' => $token,
				'ext'    => $ext,
				'size'   => (int) filesize( $path ),
			)
		);
	}

	/**
	 * Job guardado si coincide con el id de la ruta.
	 *
	 * @param \WP_REST_Request $request Petición.
	 */
	private static function job( \WP_REST_Request $request ): array|\WP_Error {
		$job = JobStore::get();
		if ( ! $job || $job['id'] !== $request->get_param( 'id' ) ) {
			return new \WP_Error( 'siteferry_job', __( 'Esa operación ya no existe.', 'siteferry' ), array( 'status' => 404 ) );
		}
		return $job;
	}
}

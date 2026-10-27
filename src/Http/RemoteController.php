<?php
/**
 * Endpoint que atiende a otros sitios.
 *
 * @package Siteferry
 */

namespace Siteferry\Http;

use Siteferry\Job\JobStore;
use Siteferry\Plugin;
use Siteferry\Remote\Server;
use Siteferry\Remote\Signer;
use Siteferry\Support\Settings;
use Siteferry\Support\UndoPoint;

/**
 * POST siteferry/v1/remote. No usa sesión de WordPress: se autentica con la firma
 * HMAC del cuerpo, el reloj (±5 min) y un nonce de un solo uso. Las respuestas también van firmadas.
 */
final class RemoteController {

	/**
	 * Registra la ruta.
	 */
	public static function register(): void {
		register_rest_route(
			'siteferry/v1',
			'/remote',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'handle' ),
				'permission_callback' => '__return_true', // La autenticación es la firma (ver handle).
			)
		);
	}

	/**
	 * Atiende una petición firmada.
	 *
	 * @param \WP_REST_Request $request Petición.
	 */
	public static function handle( \WP_REST_Request $request ): void {
		$key               = Settings::key();
		[ $line, $binary ] = Signer::split( $request->get_body() );
		if ( ! Signer::valid( $line, (string) $request->get_header( 'x_siteferry_signature' ), $key ) ) {
			self::respond(
				array(
					'ok'    => false,
					'code'  => 'siteferry_signature',
					'error' => 'Firma inválida.',
				),
				401,
				$key
			);
		}
		$message = json_decode( $line, true );
		$op      = is_array( $message ) ? (string) ( $message['op'] ?? '' ) : '';
		$nonce   = is_array( $message ) ? (string) ( $message['nonce'] ?? '' ) : '';
		if ( '' === $op || ! preg_match( '/^[a-f0-9]{32}$/', $nonce ) ) {
			self::respond(
				array(
					'ok'    => false,
					'error' => 'Petición mal formada.',
				),
				400,
				$key
			);
		}
		if ( abs( time() - (int) ( $message['ts'] ?? 0 ) ) > Signer::MAX_SKEW ) {
			self::respond(
				array(
					'ok'    => false,
					'error' => 'Los relojes de los dos servidores difieren más de 5 minutos.',
				),
				401,
				$key,
				$nonce
			);
		}
		if ( null !== $binary && hash( 'sha256', $binary ) !== ( $message['sha256'] ?? null ) ) {
			self::respond(
				array(
					'ok'    => false,
					'error' => 'El paquete llegó dañado.',
				),
				400,
				$key,
				$nonce
			);
		}
		if ( ! self::fresh_nonce( $nonce ) ) {
			self::respond(
				array(
					'ok'    => false,
					'error' => 'Petición repetida.',
				),
				401,
				$key,
				$nonce
			);
		}

		Plugin::step_seconds();
		$locked = 'push_step' === $op && JobStore::lock( 20 );
		try {
			$server = new Server(
				Plugin::db(),
				Plugin::site(),
				Settings::get(),
				static fn() => JobStore::get(),
				static function ( array $job ): void {
					JobStore::save( $job );
				},
				SITEFERRY_VERSION
			);
			$args   = (array) ( $message['args'] ?? array() );
			unset( $args['_binary'] ); // Solo el binario firmado (por su sha256) puede llegar aquí.
			if ( null !== $binary ) {
				$args['_binary'] = $binary;
			}
			$data = $server->handle( $op, $args );
			$out  = $data['package'] ?? null;
			unset( $data['package'] );
			if ( 'push_step' === $op && 'done' === $data['status'] ) {
				wp_cache_flush(); // Las tablas cambiaron por debajo de la caché de objetos.
				$received = JobStore::get();
				UndoPoint::record( (string) ( $received['origin'] ?? $received['id'] ), 'Push recibido', $received['files']['backup'] ?? null, null, array() );
			}
			if ( 'files_finalize' === $op ) {
				UndoPoint::record( (string) $args['session'], 'Push recibido', null, Plugin::site()['storage'] . '/files-' . $args['session'], array( (string) $args['root'] ) );
			}
			$payload = array(
				'ok'   => true,
				'data' => $data,
			);
			$status  = 200;
		} catch ( \Throwable $e ) {
			$payload = array(
				'ok'    => false,
				'error' => $e->getMessage(),
			);
			$status  = 400;
			$out     = null;
		}
		if ( $locked ) {
			JobStore::unlock();
		}
		self::respond( $payload, $status, $key, $nonce, $out );
	}

	/**
	 * Registra el nonce y dice si era nuevo. Una sola opción que se poda sola: los nonces solo
	 * importan mientras su timestamp sea aceptable (2 × MAX_SKEW).
	 *
	 * @param string $nonce Nonce de la petición.
	 */
	private static function fresh_nonce( string $nonce ): bool {
		// ponytail: dos peticiones simultáneas pueden pisarse el registro; el peor caso es aceptar una repetición
		// dentro de la misma ventana de 10 minutos, y la firma sigue siendo obligatoria.
		$now  = time();
		$seen = array_filter( (array) get_option( 'siteferry_nonces', array() ), static fn( $ts ) => $now - (int) $ts < 2 * Signer::MAX_SKEW );
		if ( isset( $seen[ $nonce ] ) ) {
			return false;
		}
		$seen[ $nonce ] = $now;
		update_option( 'siteferry_nonces', $seen, false );
		return true;
	}

	/**
	 * Envía la respuesta firmada y termina (exit: así nadie altera el cuerpo después de firmarlo).
	 * Lleva el nonce de la petición: una respuesta firmada no sirve como respuesta a otra petición.
	 *
	 * @param array       $payload Datos.
	 * @param int         $status  Código HTTP.
	 * @param string      $key     Clave.
	 * @param string      $nonce   Nonce de la petición ('' si no se pudo leer).
	 * @param string|null $binary  Paquete que acompaña a la respuesta (files_get); su sha256 va firmado.
	 */
	private static function respond( array $payload, int $status, string $key, string $nonce = '', ?string $binary = null ): never {
		$payload['nonce'] = $nonce;
		if ( null !== $binary ) {
			$payload['sha256'] = hash( 'sha256', $binary );
		}
		$json = (string) wp_json_encode( $payload, JSON_UNESCAPED_SLASHES );
		while ( ob_get_level() ) {
			ob_end_clean();
		}
		status_header( $status );
		nocache_headers();
		header( null === $binary ? 'Content-Type: application/json; charset=utf-8' : 'Content-Type: application/octet-stream' );
		header( Signer::HEADER . ': ' . Signer::sign( $json, $key ) );
		echo null === $binary ? $json : $json . "\n" . $binary; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON firmado (+ binario con su sha256 firmado).
		exit;
	}
}

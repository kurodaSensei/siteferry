<?php
/**
 * Endpoint that serves other sites.
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
 * POST siteferry/v1/remote. No WordPress session: requests authenticate with an HMAC signature
 * of the body, the clock (±5 min) and a single-use nonce. Responses are signed too.
 */
final class RemoteController {

	/**
	 * Registers the route.
	 */
	public static function register(): void {
		register_rest_route(
			'siteferry/v1',
			'/remote',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'handle' ),
				'permission_callback' => '__return_true', // Authentication is the signature (see handle).
			)
		);
	}

	/**
	 * Handles a signed request.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public static function handle( \WP_REST_Request $request ): void {
		ob_start(); // Anything printed while handling it would void the signature header; respond() drops it.
		Plugin::install_compat(); // The site that receives a push may never open Siteferry's page after an update.
		$key               = Settings::key();
		[ $line, $binary ] = Signer::split( $request->get_body() );
		if ( ! Signer::valid( $line, (string) $request->get_header( 'x_siteferry_signature' ), $key ) ) {
			self::respond(
				array(
					'ok'    => false,
					'code'  => 'siteferry_signature',
					'error' => 'Invalid signature.',
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
					'error' => 'Malformed request.',
				),
				400,
				$key
			);
		}
		if ( abs( time() - (int) ( $message['ts'] ?? 0 ) ) > Signer::MAX_SKEW ) {
			self::respond(
				array(
					'ok'    => false,
					'code'  => Signer::CLOCK_CODE,
					'error' => 'The two servers’ clocks differ by more than 5 minutes.',
					'time'  => time(),
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
					'error' => 'The package arrived corrupted.',
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
					'error' => 'Repeated request.',
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
			unset( $args['_binary'] ); // Only the signed binary (via its sha256) can get here.
			if ( null !== $binary ) {
				$args['_binary'] = $binary;
			}
			$data = $server->handle( $op, $args );
			$out  = $data['package'] ?? null;
			unset( $data['package'] );
			if ( 'push_step' === $op && 'done' === $data['status'] ) {
				wp_cache_flush(); // The tables changed underneath the object cache.
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
	 * Records the nonce and tells whether it was new. A single self-pruning option: nonces only
	 * matter while their timestamp is acceptable (2 × MAX_SKEW).
	 *
	 * @param string $nonce Request nonce.
	 */
	private static function fresh_nonce( string $nonce ): bool {
		// ponytail: two simultaneous requests can overwrite each other's record; the worst case is accepting a replay
		// within the same 10-minute window, and the signature is still required.
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
	 * Sends the signed response and exits (so nothing can alter the body after it is signed).
	 * It carries the request nonce, so a signed response cannot be replayed as the answer to another request.
	 *
	 * @param array       $payload Datos.
	 * @param int         $status  HTTP status code.
	 * @param string      $key     Clave.
	 * @param string      $nonce   Request nonce ('' if it could not be read).
	 * @param string|null $binary  Package sent with the response (files_get); its sha256 is signed.
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
		echo null === $binary ? $json : $json . "\n" . $binary; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Signed JSON (+ binary with its signed sha256).
		exit;
	}
}

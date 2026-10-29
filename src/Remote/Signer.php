<?php
/**
 * Signing of messages between sites.
 *
 * @package Siteferry
 */

namespace Siteferry\Remote;

/**
 * HMAC-SHA256 over the whole body (which includes op, arguments, timestamp and nonce).
 * Changing a single byte of the request or the response invalidates the signature.
 */
final class Signer {

	/** Seconds of clock skew tolerated. */
	public const MAX_SKEW = 300;

	/** HTTP header carrying the signature. */
	public const HEADER = 'X-Siteferry-Signature';

	/** Error code of a request rejected for its time; the response carries the receiver's clock. */
	public const CLOCK_CODE = 'siteferry_clock';

	/**
	 * Signs a body.
	 *
	 * @param string $body Exact body.
	 * @param string $key  Shared key.
	 */
	public static function sign( string $body, string $key ): string {
		return hash_hmac( 'sha256', $body, $key );
	}

	/**
	 * Checks a signature in constant time.
	 *
	 * @param string $body      Exact body.
	 * @param string $signature Received signature.
	 * @param string $key       Shared key.
	 */
	public static function valid( string $body, string $signature, string $key ): bool {
		return '' !== $key && '' !== $signature && hash_equals( self::sign( $body, $key ), $signature );
	}

	/**
	 * Signable line of a request. If a binary accompanies it, it carries its sha256.
	 *
	 * @param string      $op     Operation.
	 * @param array       $args   Arguments.
	 * @param string|null $binary Binary that travels after the line.
	 * @param int         $offset Seconds the receiver's clock is ahead of this one (see clock_offset()).
	 */
	public static function message( string $op, array $args, ?string $binary = null, int $offset = 0 ): string {
		$message = array(
			'op'    => $op,
			'args'  => $args,
			'ts'    => time() + $offset,
			'nonce' => bin2hex( random_bytes( 16 ) ),
		);
		if ( null !== $binary ) {
			$message['sha256'] = hash( 'sha256', $binary );
		}
		return (string) json_encode( $message, JSON_UNESCAPED_SLASHES ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
	}

	/**
	 * How far ahead of this clock the other site's clock is, when it rejected a request for its
	 * time. A paused VM (Docker Desktop on a Mac that slept) wakes with its clock behind; the
	 * rejection happens before the operation runs, so the request can be signed again with the
	 * receiver's time. The response is signed, so only a holder of the key can move the clock, and
	 * the receiver still checks the time against its own clock and never accepts a nonce twice.
	 *
	 * @param array $response Signed response data.
	 */
	public static function clock_offset( array $response ): ?int {
		if ( ! empty( $response['ok'] ) || self::CLOCK_CODE !== ( $response['code'] ?? null ) || ! is_int( $response['time'] ?? null ) ) {
			return null;
		}
		return $response['time'] - time();
	}

	/**
	 * Splits a framed body: the signed JSON line and, if present, the binary that follows it.
	 * JSON never contains raw line breaks, so the first one marks the end of the line.
	 *
	 * @param string $body Body.
	 * @return array{0: string, 1: ?string}
	 */
	public static function split( string $body ): array {
		$newline = strpos( $body, "\n" );
		return false === $newline ? array( $body, null ) : array( substr( $body, 0, $newline ), (string) substr( $body, $newline + 1 ) );
	}

	/**
	 * Splits "URL KEY" (on one or two lines) into its parts.
	 *
	 * @param string $connection Text copied from the other site.
	 * @return array{url: string, key: string}
	 *
	 * @throws \InvalidArgumentException If it does not have the expected format.
	 */
	public static function parse_connection( string $connection ): array {
		$parts = preg_split( '/\s+/', trim( $connection ) );
		$url   = rtrim( (string) ( $parts[0] ?? '' ), '/' );
		$key   = (string) ( $parts[1] ?? '' );
		if ( 2 !== count( $parts ) || ! preg_match( '#^https?://#i', $url ) || ! preg_match( '/^[A-Za-z0-9]{32,64}$/', $key ) ) {
			throw new \InvalidArgumentException( 'The connection info must be the site URL and its key, exactly as shown in Siteferry on the other site.' );
		}
		// Over http the database would travel in clear text: only allowed towards development hosts.
		if ( ! preg_match( '#^https://#i', $url ) && ! self::is_dev_host( (string) parse_url( $url, PHP_URL_HOST ) ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Runs without WordPress too (tests).
			throw new \InvalidArgumentException( 'Use https: over http the database would travel unencrypted. http is only allowed with development hosts (localhost, .test, .local, private IPs).' );
		}
		return array(
			'url' => $url,
			'key' => $key,
		);
	}

	/**
	 * Hosts where http is accepted: the machine itself, development domains, dotless names
	 * (containers, intranet) and private IPs.
	 *
	 * @param string $host URL host.
	 */
	public static function is_dev_host( string $host ): bool {
		$host = strtolower( trim( $host, '[]' ) );
		if ( '' === $host ) {
			return false;
		}
		if ( 'localhost' === $host || '::1' === $host || ( ! str_contains( $host, '.' ) && ! str_contains( $host, ':' ) ) ) {
			return true;
		}
		foreach ( array( '.test', '.localhost', '.local', '.internal' ) as $suffix ) {
			if ( str_ends_with( $host, $suffix ) ) {
				return true;
			}
		}
		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return str_starts_with( $host, '127.' ) || ! filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
		}
		return false;
	}
}

<?php
/**
 * Signed calls to another site.
 *
 * @package Siteferry
 */

namespace Siteferry\Remote;

/**
 * HTTP client for the site-to-site protocol: signs every request and only accepts responses
 * signed with the same key.
 */
final class Client {

	/**
	 * Constructor.
	 *
	 * @param string $url        URL of the other site.
	 * @param string $key        Key of the other site.
	 * @param bool   $verify_ssl Verify the HTTPS certificate.
	 */
	public function __construct( private string $url, private string $key, private bool $verify_ssl = true ) {}

	/**
	 * Client with the connection stored in a job.
	 *
	 * @param array $job Pull or push job.
	 */
	public static function for_job( array $job ): self {
		return new self( $job['remote']['url'], $job['remote']['key'], $job['remote']['verify_ssl'] );
	}

	/**
	 * Calls an operation. Network drops and 5xx errors are retried: every operation
	 * is safe to repeat except push_begin.
	 *
	 * @param string      $op     Operation.
	 * @param array       $args   Arguments.
	 * @param string|null $binary Binary that accompanies the request (files_put).
	 * @return array Data; if the response carries a binary (files_get), it goes in 'package'.
	 *
	 * @throws Unreachable If the other site cannot be reached after the retries.
	 * @throws TooLarge If the other site's web server refuses the request for its size.
	 * @throws \RuntimeException If there is no valid response.
	 */
	public function call( string $op, array $args = array(), ?string $binary = null ): array {
		$attempts = 'push_begin' === $op ? 1 : 3;
		$offset   = null;
		for ( $attempt = 1; ; $attempt++ ) {
			$message  = Signer::message( $op, $args, $binary, $offset ?? 0 );
			$response = wp_remote_post(
				$this->url . '/?rest_route=/siteferry/v1/remote',
				array(
					'body'        => null === $binary ? $message : $message . "\n" . $binary,
					'timeout'     => 90,
					'redirection' => 0,
					'sslverify'   => $this->verify_ssl,
					'headers'     => array(
						// With a binary it is not valid JSON: the WordPress REST API would reject it when trying to read it.
						'Content-Type' => null === $binary ? 'application/json' : 'application/octet-stream',
						Signer::HEADER => Signer::sign( $message, $this->key ),
					),
				)
			);
			if ( is_wp_error( $response ) ) {
				if ( $attempt < $attempts ) {
					sleep( 2 * $attempt );
					continue;
				}
				throw new Unreachable( 'Could not connect to ' . $this->url . ': ' . $response->get_error_message() );
			}
			$code = (int) wp_remote_retrieve_response_code( $response );
			$raw  = (string) wp_remote_retrieve_body( $response );
			if ( 413 === $code ) {
				$size = strlen( $message ) + ( null === $binary ? 0 : strlen( $binary ) + 1 );
				throw new TooLarge( sprintf( 'The web server of the remote site refused a request of %.1f MB (HTTP 413).', $size / 1048576 ) );
			}
			if ( $code >= 500 ) {
				if ( $attempt < $attempts ) {
					sleep( 2 * $attempt );
					continue;
				}
				throw new Unreachable( "The remote site answered HTTP $code." );
			}
			[ $line, $package ] = Signer::split( $raw );
			if ( ! Signer::valid( $line, (string) wp_remote_retrieve_header( $response, strtolower( Signer::HEADER ) ), $this->key ) ) {
				throw new \RuntimeException( $this->unsigned( $code, $raw, (string) wp_remote_retrieve_header( $response, 'location' ) ) );
			}
			$data = json_decode( $line, true );
			if ( ( $data['nonce'] ?? null ) !== json_decode( $message, true )['nonce'] ) {
				throw new \RuntimeException( 'The remote site returned a response that does not match the request.' );
			}
			if ( null !== $package && hash( 'sha256', $package ) !== ( $data['sha256'] ?? null ) ) {
				throw new \RuntimeException( 'The package from the remote site arrived corrupted.' );
			}
			$skew = Signer::clock_offset( (array) $data );
			if ( null !== $skew && null === $offset ) {
				// ponytail: the offset lives for one call; keep it on the job if a clock stays off for a whole migration.
				$offset = $skew;
				continue; // Rejected before the operation ran: safe to repeat once, even push_begin.
			}
			if ( empty( $data['ok'] ) ) {
				throw new \RuntimeException( 'Remote site: ' . ( $data['error'] ?? 'unknown error' ) );
			}
			$result = (array) $data['data'];
			if ( null !== $package ) {
				$result['package'] = $package;
			}
			return $result;
		}
	}

	/**
	 * Explains a response without a valid signature.
	 *
	 * @param int    $code     HTTP code.
	 * @param string $raw      Body (untrusted).
	 * @param string $location Redirect target.
	 */
	private function unsigned( int $code, string $raw, string $location ): string {
		// Searched as text: if a plugin on the other site prints something first, the JSON arrives with junk in front.
		if ( str_contains( $raw, '"code":"siteferry_signature"' ) ) {
			return 'The connection key does not match the one on the remote site. Copy its connection info again.';
		}
		if ( $code >= 300 && $code < 400 ) {
			return "The remote site redirects to $location. Use that URL in the connection.";
		}
		if ( 404 === $code ) {
			return 'The remote site does not have Siteferry active or the URL is incorrect.';
		}
		return "Invalid response from the remote site (HTTP $code). Does it have Siteferry active and up to date?";
	}
}

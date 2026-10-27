<?php
/**
 * Collects the statements in memory.
 *
 * @package Siteferry
 */

namespace Siteferry\Engine;

/**
 * Sink for sending the statements to another site (push) or returning them in a response (pull).
 */
final class MemorySink implements Sink {

	/**
	 * Received statements.
	 *
	 * @var list<string>
	 */
	public array $statements = array();

	/**
	 * Stores the statement.
	 *
	 * @param string $statement SQL.
	 */
	public function write( string $statement ): void {
		$this->statements[] = $statement;
	}

	/**
	 * The statements compressed for transport in JSON.
	 */
	public function pack(): string {
		return self::encode( $this->statements );
	}

	/**
	 * Encodes a list of statements.
	 *
	 * @param list<string> $statements Statements.
	 */
	public static function encode( array $statements ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode, WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- compressed SQL transport.
		return base64_encode( (string) gzcompress( (string) json_encode( $statements ), 6 ) );
	}

	/**
	 * Decodes what encode() produced.
	 *
	 * @param string $packed Text.
	 * @return list<string>
	 *
	 * @throws \RuntimeException If it is not valid.
	 */
	public static function decode( string $packed ): array {
		$json       = @gzuncompress( (string) base64_decode( $packed, true ), 32 * 1024 * 1024 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- cap against compression "bombs"; the error is reported below. // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- compressed SQL transport.
		$statements = false === $json ? null : json_decode( $json, true );
		if ( ! is_array( $statements ) || array_filter( $statements, static fn( $s ) => ! is_string( $s ) ) ) {
			throw new \RuntimeException( 'Invalid statement package.' );
		}
		return array_values( $statements );
	}
}

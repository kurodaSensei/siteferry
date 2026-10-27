<?php
/**
 * Junta las sentencias en memoria.
 *
 * @package Siteferry
 */

namespace Siteferry\Engine;

/**
 * Sink para enviar las sentencias a otro sitio (push) o devolverlas en una respuesta (pull).
 */
final class MemorySink implements Sink {

	/**
	 * Sentencias recibidas.
	 *
	 * @var list<string>
	 */
	public array $statements = array();

	/**
	 * Guarda la sentencia.
	 *
	 * @param string $statement SQL.
	 */
	public function write( string $statement ): void {
		$this->statements[] = $statement;
	}

	/**
	 * Las sentencias comprimidas para viajar en JSON.
	 */
	public function pack(): string {
		return self::encode( $this->statements );
	}

	/**
	 * Codifica una lista de sentencias.
	 *
	 * @param list<string> $statements Sentencias.
	 */
	public static function encode( array $statements ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode, WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- transporte de SQL comprimido.
		return base64_encode( (string) gzcompress( (string) json_encode( $statements ), 6 ) );
	}

	/**
	 * Decodifica lo que produjo encode().
	 *
	 * @param string $packed Texto.
	 * @return list<string>
	 *
	 * @throws \RuntimeException Si no es válido.
	 */
	public static function decode( string $packed ): array {
		$json       = @gzuncompress( (string) base64_decode( $packed, true ), 32 * 1024 * 1024 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- tope contra "bombas" de compresión; el error se informa abajo. // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- transporte de SQL comprimido.
		$statements = false === $json ? null : json_decode( $json, true );
		if ( ! is_array( $statements ) || array_filter( $statements, static fn( $s ) => ! is_string( $s ) ) ) {
			throw new \RuntimeException( 'Paquete de sentencias inválido.' );
		}
		return array_values( $statements );
	}
}

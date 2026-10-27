<?php
/**
 * Lee sentencias de un archivo .sql.
 *
 * @package Siteferry
 */

namespace Siteferry\Engine;

/**
 * Lector por líneas para dumps de mysqldump, phpMyAdmin y de este plugin: una sentencia
 * termina en una línea que acaba en ";". Funciona porque esas herramientas escapan los
 * saltos de línea dentro de los valores.
 */
final class SqlFileReader {

	/**
	 * Lee sentencias completas desde $offset hasta acabar el archivo o el presupuesto.
	 * El offset devuelto siempre apunta al inicio de una sentencia, así que una sentencia
	 * a medias se vuelve a leer completa en la siguiente llamada.
	 *
	 * @param string   $path         Archivo .sql sin comprimir.
	 * @param int      $offset       Byte donde seguir.
	 * @param Budget   $budget       Tiempo disponible.
	 * @param callable $on_statement Recibe cada sentencia sin el ";" final.
	 * @return array{offset: int, done: bool, skipped: int}
	 *
	 * @throws \RuntimeException Si no se puede abrir.
	 */
	public static function read( string $path, int $offset, Budget $budget, callable $on_statement ): array {
		$handle = fopen( $path, 'rb' );
		if ( ! $handle ) {
			throw new \RuntimeException( 'No se puede leer ' . $path );
		}
		fseek( $handle, $offset );

		$buffer    = '';
		$skipped   = 0;
		$delimiter = false;
		$done      = true;

		while ( false !== ( $line = fgets( $handle ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
			$trimmed = trim( $line );
			if ( '' === $buffer ) {
				if ( $delimiter ) {
					$delimiter = ! preg_match( '/^DELIMITER\s+;$/i', $trimmed );
					$offset    = ftell( $handle );
					continue;
				}
				if ( '' === $trimmed || str_starts_with( $trimmed, '--' ) || str_starts_with( $trimmed, '#' ) ) {
					$offset = ftell( $handle );
					continue;
				}
				// ponytail: los bloques DELIMITER (triggers, procedimientos) se omiten.
				if ( preg_match( '/^DELIMITER\s/i', $trimmed ) ) {
					$delimiter = true;
					++$skipped;
					$offset = ftell( $handle );
					continue;
				}
			}
			$buffer .= $line;
			if ( str_ends_with( $trimmed, ';' ) ) {
				$on_statement( rtrim( trim( $buffer ), ';' ) );
				$buffer = '';
				$offset = ftell( $handle );
				if ( $budget->exhausted() ) {
					$done = false;
					break;
				}
			}
		}
		if ( $done && '' !== trim( $buffer ) ) {
			$on_statement( rtrim( trim( $buffer ), ';' ) ); // Última sentencia sin ";".
			$offset = ftell( $handle );
		}
		fclose( $handle );

		return array(
			'offset'  => $offset,
			'done'    => $done,
			'skipped' => $skipped,
		);
	}
}

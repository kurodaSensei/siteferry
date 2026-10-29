<?php
/**
 * Reads statements from a .sql file.
 *
 * @package Siteferry
 */

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Messages are plain text for the job log, WP-CLI and REST JSON; the admin shows them with textContent.
// phpcs:disable WordPress.WP.AlternativeFunctions -- Streams files of any size in chunks: WP_Filesystem has no streaming API, and this code also runs without WordPress (tests, WP-CLI packages).

namespace Siteferry\Engine;

/**
 * Line-based reader for dumps from mysqldump, phpMyAdmin and this plugin: a statement
 * ends at a line that ends in ";". It works because those tools escape the
 * line breaks inside values.
 */
final class SqlFileReader {

	/**
	 * Reads complete statements from $offset until the file or the budget runs out.
	 * The returned offset always points to the start of a statement, so a half-read
	 * statement is read again in full on the next call.
	 *
	 * @param string   $path         Uncompressed .sql file.
	 * @param int      $offset       Byte to continue from.
	 * @param Budget   $budget       Available time.
	 * @param callable $on_statement Receives each statement without the trailing ";", the offset
	 *                               right after it and the statements skipped so far in this call.
	 * @return array{offset: int, done: bool, skipped: int}
	 *
	 * @throws \RuntimeException If it cannot be opened.
	 */
	public static function read( string $path, int $offset, Budget $budget, callable $on_statement ): array {
		$handle = fopen( $path, 'rb' );
		if ( ! $handle ) {
			throw new \RuntimeException( 'Cannot read ' . $path );
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
				// ponytail: DELIMITER blocks (triggers, procedures) are skipped.
				if ( preg_match( '/^DELIMITER\s/i', $trimmed ) ) {
					$delimiter = true;
					++$skipped;
					$offset = ftell( $handle );
					continue;
				}
			}
			$buffer .= $line;
			if ( str_ends_with( $trimmed, ';' ) ) {
				$offset = ftell( $handle );
				$on_statement( rtrim( trim( $buffer ), ';' ), $offset, $skipped );
				$buffer = '';
				if ( $budget->exhausted() ) {
					$done = false;
					break;
				}
			}
		}
		if ( $done && '' !== trim( $buffer ) ) {
			$offset = ftell( $handle );
			$on_statement( rtrim( trim( $buffer ), ';' ), $offset, $skipped ); // Last statement without a ";".
		}
		fclose( $handle );

		return array(
			'offset'  => $offset,
			'done'    => $done,
			'skipped' => $skipped,
		);
	}
}

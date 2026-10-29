<?php
/**
 * Full site package (.sfy).
 *
 * @package Siteferry
 */

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Messages are plain text for the job log, WP-CLI and REST JSON; the admin shows them with textContent.
// phpcs:disable WordPress.WP.AlternativeFunctions -- Streams files of any size in chunks: WP_Filesystem has no streaming API, and this code also runs without WordPress (tests, WP-CLI packages).

namespace Siteferry\Files;

/**
 * Format: "SFYSITE1 {JSON metadata}\n" followed by blocks, each with a JSON line
 * {"type":"db"|"files","root":…,"length":N}\n followed by N bytes:
 * - db: a chunk of the .sql.gz dump (chunks are in order);
 * - files: a file package (Package) for one folder.
 * It is written by appending blocks at the end, so a long export advances request by request
 * without rewriting anything (a ZIP would be rewritten in full on each one).
 */
final class SitePackage {

	private const MAGIC = 'SFYSITE1 ';

	/** Maximum block size. */
	public const MAX_FRAME = 64 * 1024 * 1024;

	/**
	 * Creates the package with its metadata.
	 *
	 * @param string $path File.
	 * @param array  $meta Metadata (site, prefix, folders…).
	 *
	 * @throws \RuntimeException If it cannot be written.
	 */
	public static function start( string $path, array $meta ): void {
		if ( false === file_put_contents( $path, self::MAGIC . self::json( $meta ) . "\n" ) ) {
			throw new \RuntimeException( 'Cannot write ' . $path );
		}
	}

	/**
	 * Appends a block at the end.
	 *
	 * @param string $path  File.
	 * @param array  $frame type and, for files, root.
	 * @param string $bytes Content.
	 *
	 * @throws \RuntimeException If it cannot be written.
	 */
	public static function append( string $path, array $frame, string $bytes ): void {
		$frame['length'] = strlen( $bytes );
		$data            = self::json( $frame ) . "\n" . $bytes;
		if ( file_put_contents( $path, $data, FILE_APPEND ) !== strlen( $data ) ) {
			throw new \RuntimeException( 'Incomplete write to ' . $path . ' (disk full?)' );
		}
	}

	/**
	 * Package metadata.
	 *
	 * @param string $path File.
	 *
	 * @throws \RuntimeException If it is not a valid package.
	 */
	public static function meta( string $path ): array {
		$handle = fopen( $path, 'rb' );
		$line   = $handle ? fgets( $handle, 1024 * 1024 ) : false;
		if ( $handle ) {
			fclose( $handle );
		}
		$meta = is_string( $line ) && str_starts_with( $line, self::MAGIC ) ? json_decode( substr( $line, strlen( self::MAGIC ) ), true ) : null;
		if ( ! is_array( $meta ) ) {
			throw new \RuntimeException( 'Not a valid Siteferry package (.sfy).' );
		}
		return $meta;
	}

	/**
	 * Reads the block starting at $offset (0 = the first one, after the metadata).
	 *
	 * @param string $path   File.
	 * @param int    $offset Byte where the block starts.
	 * @return array{frame: array, bytes: string, next: int}|null Null at the end.
	 *
	 * @throws \RuntimeException If the block is malformed or truncated.
	 */
	public static function read( string $path, int $offset ): ?array {
		$handle = fopen( $path, 'rb' );
		if ( ! $handle ) {
			throw new \RuntimeException( 'Cannot read ' . $path );
		}
		if ( 0 === $offset ) {
			fgets( $handle, 1024 * 1024 ); // Metadata.
		} else {
			fseek( $handle, $offset );
		}
		$line = fgets( $handle, 64 * 1024 );
		if ( false === $line ) {
			fclose( $handle );
			return null;
		}
		$frame  = json_decode( $line, true );
		$length = $frame['length'] ?? null;
		$type   = $frame['type'] ?? null;
		if ( ! is_int( $length ) || $length < 0 || $length > self::MAX_FRAME || ! in_array( $type, array( 'db', 'files' ), true )
			|| ( 'files' === $type && ! in_array( $frame['root'] ?? null, Roots::ALL, true ) ) ) {
			fclose( $handle );
			throw new \RuntimeException( 'Invalid package block.' );
		}
		$bytes = $length > 0 ? (string) fread( $handle, $length ) : '';
		$next  = (int) ftell( $handle );
		fclose( $handle );
		if ( strlen( $bytes ) !== $length ) {
			throw new \RuntimeException( 'The package is truncated (incomplete download?).' );
		}
		return array(
			'frame' => $frame,
			'bytes' => $bytes,
			'next'  => $next,
		);
	}

	/**
	 * Single-line JSON.
	 *
	 * @param array $data Data.
	 */
	private static function json( array $data ): string {
		return (string) json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
	}
}

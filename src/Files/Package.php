<?php
/**
 * Batches of files to send between sites.
 *
 * @package Siteferry
 */

// phpcs:disable WordPress.WP.AlternativeFunctions -- Streams files of any size in chunks: WP_Filesystem has no streaming API, and this code also runs without WordPress (tests, WP-CLI packages).

namespace Siteferry\Files;

/**
 * Format: a first line "SFYPKG1 {JSON index}\n" followed by the raw bytes of each chunk, in
 * order. Each index entry is a chunk of a file:
 * path, offset (starting byte), length (bytes in this package), size (total size) and md5
 * (of the whole file). A large file travels in several packages and is only put in place once
 * it is complete and its md5 matches. No ZIP: there are no paths inside a compressed archive
 * that could escape, and no dependency on ZipArchive.
 */
final class Package {

	private const MAGIC = 'SFYPKG1 ';

	/** Maximum index size. */
	private const MAX_INDEX = 8 * 1024 * 1024;

	/**
	 * Builds a package from the files in a list, up to $max_bytes of content.
	 *
	 * @param Manifest      $pending   Files to send.
	 * @param PathGuard     $guard     Source root.
	 * @param array|null    $cursor    Null to start; pos (byte in the list), offset, size and md5 of the file in progress.
	 * @param int           $max_bytes Content bytes per package (at least one chunk is always included).
	 * @param int           $max_files Entries per package.
	 * @param callable|null $allow     fn(string $relative): bool; anything not allowed is reported as missing.
	 * @return array{package: string, cursor: ?array, files: int, bytes: int, missing: list<string>}
	 */
	public static function build( Manifest $pending, PathGuard $guard, ?array $cursor, int $max_bytes, int $max_files = 2000, ?callable $allow = null ): array {
		$cursor ??= array(
			'pos'    => 0,
			'offset' => 0,
		);
		$index    = array();
		$body     = '';
		$missing  = array();
		$next     = null;

		foreach ( $pending->read_from( (int) $cursor['pos'] ) as [ $file, , , $after ] ) {
			$absolute = ( null === $allow || $allow( $file ) ) ? $guard->resolve( $file ) : null;
			if ( null === $absolute || ! is_file( $absolute ) || is_link( $absolute ) ) {
				$missing[]     = $file; // Deleted or changed type after the scan.
				$cursor['pos'] = $after;
				continue;
			}
			if ( 0 === (int) $cursor['offset'] ) {
				// Size and hash when the file starts: if it changes between chunks, the final md5 will not match.
				clearstatcache( true, $absolute );
				$cursor['size'] = (int) filesize( $absolute );
				$cursor['md5']  = (string) md5_file( $absolute );
			}
			$offset = (int) $cursor['offset'];
			$length = max( 0, min( $cursor['size'] - $offset, $max_bytes - strlen( $body ) ) );
			$chunk  = '';
			if ( $length > 0 ) {
				$handle = fopen( $absolute, 'rb' );
				fseek( $handle, $offset );
				$chunk = (string) fread( $handle, $length );
				fclose( $handle );
			}
			$index[] = array(
				'path'   => $file,
				'offset' => $offset,
				'length' => strlen( $chunk ),
				'size'   => $cursor['size'],
				'md5'    => $cursor['md5'],
			);
			$body   .= $chunk;

			if ( $offset + strlen( $chunk ) < $cursor['size'] ) {
				$cursor['offset'] = $offset + strlen( $chunk ); // The file continues in the next package.
				$next             = $cursor;
				break;
			}
			$cursor = array(
				'pos'    => $after,
				'offset' => 0,
			);
			if ( strlen( $body ) >= $max_bytes || count( $index ) >= $max_files ) {
				$next = $cursor;
				break;
			}
		}

		// If the list ended there is no cursor; if it was cut short, $next points to what comes next.
		$done = null === $next;
		return array(
			'package' => self::MAGIC . json_encode( array( 'files' => $index ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n" . $body, // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
			'cursor'  => $done ? null : $next,
			'files'   => count( $index ),
			'bytes'   => strlen( $body ),
			'missing' => $missing,
		);
	}

	/**
	 * Writes a package to a root. Each chunk goes to "<file>.siteferry-part"; once a file is
	 * complete and its md5 matches, it is handed to $place (by default, rename).
	 *
	 * @param string        $package Package.
	 * @param PathGuard     $guard   Destination root.
	 * @param bool          $media   Reject executables.
	 * @param callable|null $place   fn(string $part, string $target, string $relative): void.
	 * @param callable|null $allow   fn(string $relative): bool; anything not allowed is not written.
	 * @return array{done: list<string>, partial: list<string>, failed: array<string, string>}
	 *
	 * @throws \RuntimeException If the package is malformed (nothing is written).
	 */
	public static function extract( string $package, PathGuard $guard, bool $media, ?callable $place = null, ?callable $allow = null ): array {
		$entries = self::parse( $package );
		$place ??= static function ( string $part, string $target ): void {
			rename( $part, $target );
		};
		$result = array(
			'done'    => array(),
			'partial' => array(),
			'failed'  => array(),
		);

		foreach ( $entries as $entry ) {
			$file     = $entry['path'];
			$absolute = $guard->resolve( $file );
			if ( null === $absolute ) {
				$result['failed'][ $file ] = 'path not allowed';
				continue;
			}
			if ( $media && PathGuard::executable( $file ) ) {
				$result['failed'][ $file ] = 'executable in media';
				continue;
			}
			if ( null !== $allow && ! $allow( $file ) ) {
				$result['failed'][ $file ] = 'path excluded';
				continue;
			}
			if ( ( is_dir( $absolute ) || is_link( $absolute ) ) || ! self::ensure_dir( dirname( $absolute ), $guard ) ) {
				$result['failed'][ $file ] = 'cannot write';
				continue;
			}
			$part    = $absolute . Scanner::PART;
			$current = is_file( $part ) ? (int) filesize( $part ) : 0;
			if ( is_link( $part ) ) {
				$result['failed'][ $file ] = 'cannot write';
				continue;
			}
			if ( $entry['offset'] > $current ) {
				// Retry of the last chunk of a file that is already in place: nothing to do.
				if ( 0 === $current && is_file( $absolute ) && filesize( $absolute ) === $entry['size'] && md5_file( $absolute ) === $entry['md5'] ) {
					$result['done'][] = $file;
					continue;
				}
				$result['failed'][ $file ] = 'a previous chunk is missing';
				continue;
			}
			// A repeated chunk (retry) rewrites from its offset: the result is the same.
			$handle = fopen( $part, 0 === $entry['offset'] ? 'wb' : 'r+b' );
			if ( ! $handle ) {
				$result['failed'][ $file ] = 'cannot write';
				continue;
			}
			ftruncate( $handle, $entry['offset'] );
			fseek( $handle, $entry['offset'] );
			fwrite( $handle, $entry['data'] );
			fclose( $handle );

			if ( $entry['offset'] + $entry['length'] < $entry['size'] ) {
				$result['partial'][] = $file;
				continue;
			}
			if ( md5_file( $part ) !== $entry['md5'] ) {
				unlink( $part );
				$result['failed'][ $file ] = 'content does not match (did it change during transfer?)';
				continue;
			}
			$place( $part, $absolute, $file );
			$result['done'][] = $file;
		}
		return $result;
	}

	/**
	 * Validates the whole package before touching anything.
	 *
	 * @param string $package Package.
	 * @return list<array{path: string, offset: int, length: int, size: int, md5: string, data: string}>
	 *
	 * @throws \RuntimeException If anything does not add up.
	 */
	private static function parse( string $package ): array {
		$newline = strpos( $package, "\n" );
		if ( ! str_starts_with( $package, self::MAGIC ) || false === $newline || $newline > self::MAX_INDEX ) {
			throw new \RuntimeException( 'Invalid file package.' );
		}
		$index = json_decode( substr( $package, strlen( self::MAGIC ), $newline - strlen( self::MAGIC ) ), true );
		if ( ! is_array( $index['files'] ?? null ) ) {
			throw new \RuntimeException( 'Invalid package index.' );
		}
		$entries = array();
		$at      = $newline + 1;
		foreach ( $index['files'] as $item ) {
			$path   = is_string( $item['path'] ?? null ) ? $item['path'] : '';
			$offset = $item['offset'] ?? null;
			$length = $item['length'] ?? null;
			$size   = $item['size'] ?? null;
			$md5    = is_string( $item['md5'] ?? null ) ? $item['md5'] : '';
			if ( null === PathGuard::clean( $path ) || ! is_int( $offset ) || ! is_int( $length ) || ! is_int( $size )
				|| $offset < 0 || $length < 0 || $offset + $length > $size || ! preg_match( '/^[a-f0-9]{32}$/', $md5 ) ) {
				throw new \RuntimeException( 'Invalid package entry.' );
			}
			$entries[] = array(
				'path'   => $path,
				'offset' => $offset,
				'length' => $length,
				'size'   => $size,
				'md5'    => $md5,
				'data'   => (string) substr( $package, $at, $length ),
			);
			$at       += $length;
		}
		if ( strlen( $package ) !== $at ) {
			throw new \RuntimeException( 'The package does not have the bytes it declares.' );
		}
		return $entries;
	}

	/**
	 * Creates the folder if missing, checking that it stays inside the root.
	 *
	 * @param string    $dir   Absolute folder.
	 * @param PathGuard $guard Root.
	 */
	private static function ensure_dir( string $dir, PathGuard $guard ): bool {
		if ( ! is_dir( $dir ) && ! mkdir( $dir, 0755, true ) && ! is_dir( $dir ) ) {
			return false;
		}
		$real = realpath( $dir );
		return false !== $real && ( $real === $guard->root() || str_starts_with( $real, $guard->root() . '/' ) );
	}
}

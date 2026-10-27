<?php
/**
 * Walks a folder in chunks.
 *
 * @package Siteferry
 */

namespace Siteferry\Files;

use Siteferry\Engine\Budget;

/**
 * Resumable scan: lists the files of a root with size and md5, in as many calls
 * as needed. The cursor is the queue of pending folders and the position within the current one.
 * It does not follow symlinks or list special files.
 */
final class Scanner {

	/** Suffix of half-received files; they are never listed. */
	public const PART = '.siteferry-part';

	/**
	 * Constructor.
	 *
	 * @param PathGuard    $guard   Root.
	 * @param list<string> $exclude fnmatch patterns against the relative path or the name.
	 * @param bool         $media   Skip executable files (media does not accept them).
	 */
	public function __construct( private PathGuard $guard, private array $exclude = array(), private bool $media = false ) {}

	/**
	 * Scans a chunk and adds what it finds to the manifest.
	 *
	 * @param array|null $cursor Null to start.
	 * @param Manifest   $out    Destination for the entries.
	 * @param Budget     $budget Time available.
	 * @return array{cursor: ?array, files: int, bytes: int} cursor null = finished.
	 */
	public function scan( ?array $cursor, Manifest $out, Budget $budget ): array {
		$cursor ??= array(
			'queue' => array( '' ),
			'pos'   => 0,
		);
		// The cursor may come from the other site: only strings and a non-negative integer.
		$cursor = array(
			'queue' => array_values( array_filter( (array) ( $cursor['queue'] ?? array() ), 'is_string' ) ),
			'pos'   => max( 0, (int) ( $cursor['pos'] ?? 0 ) ),
		);
		$files  = 0;
		$bytes  = 0;
		while ( $cursor['queue'] ) {
			$dir = $cursor['queue'][0];
			if ( ! $this->listable( $dir ) ) {
				array_shift( $cursor['queue'] ); // A folder that could not be in the queue: discard it.
				$cursor['pos'] = 0;
				continue;
			}
			$names = $this->names( $dir );
			$count = count( $names );
			for ( $i = $cursor['pos']; $i < $count; $i++ ) {
				$cursor['pos'] = $i + 1;
				$relative      = '' === $dir ? $names[ $i ] : $dir . '/' . $names[ $i ];
				$absolute      = $this->guard->root() . '/' . $relative;
				if ( $this->excluded( $relative ) || is_link( $absolute ) || null === PathGuard::clean( $relative ) ) {
					continue;
				}
				if ( is_dir( $absolute ) ) {
					$cursor['queue'][] = $relative;
				} elseif ( is_file( $absolute ) && ! ( $this->media && PathGuard::executable( $relative ) ) ) {
					// ponytail: a file is hashed in one go; one of several GB can exceed the request budget.
					$size = (int) filesize( $absolute );
					$out->add( $relative, $size, (string) md5_file( $absolute ) );
					++$files;
					$bytes += $size;
				}
				if ( $budget->exhausted() ) {
					break 2;
				}
			}
			if ( $cursor['pos'] >= $count ) {
				array_shift( $cursor['queue'] );
				$cursor['pos'] = 0;
			}
		}
		$out->close();
		return array(
			'cursor' => $cursor['queue'] ? $cursor : null,
			'files'  => $files,
			'bytes'  => $bytes,
		);
	}

	/**
	 * Names in a folder, sorted.
	 *
	 * @param string $dir Relative folder.
	 * @return list<string>
	 */
	private function names( string $dir ): array {
		$names = scandir( $this->guard->root() . ( '' === $dir ? '' : '/' . $dir ) );
		return false === $names ? array() : array_values( array_diff( $names, array( '.', '..' ) ) );
	}

	/**
	 * Whether a path is left out by a pattern or is a half-received file.
	 *
	 * @param string $relative Relative path.
	 */
	private function excluded( string $relative ): bool {
		return Roots::matches( $this->exclude, $relative );
	}

	/**
	 * Whether a queued folder is legitimate: inside the root, real (not a symlink) and not excluded.
	 *
	 * @param string $dir Relative folder ('' = root).
	 */
	private function listable( string $dir ): bool {
		if ( '' === $dir ) {
			return true;
		}
		$absolute = $this->guard->resolve( $dir );
		return null !== $absolute && is_dir( $absolute ) && ! is_link( $absolute ) && ! $this->excluded( $dir );
	}
}

<?php
/**
 * Folders that can be transferred.
 *
 * @package Siteferry
 */

namespace Siteferry\Files;

/**
 * Known roots and their default exclusions.
 */
final class Roots {

	/** Valid roots. */
	public const ALL = array( 'uploads', 'themes', 'plugins', 'mu-plugins' );

	/** Roots with code: swapped by whole folder and require their own permission for push. */
	public const CODE = array( 'themes', 'plugins', 'mu-plugins' );

	/**
	 * Whether the root contains code.
	 *
	 * @param string $root Root.
	 */
	public static function is_code( string $root ): bool {
		return in_array( $root, self::CODE, true );
	}

	/**
	 * Patterns excluded by default: caches, repositories, dependencies, other plugins' backups
	 * and Siteferry itself (it never replaces itself).
	 *
	 * @param string $root Root.
	 * @return list<string>
	 */
	public static function exclude( string $root ): array {
		$common = array( '.git', '.svn', 'node_modules', '.DS_Store', 'Thumbs.db' );
		return match ( $root ) {
			'uploads'    => array_merge( $common, array( 'siteferry-*', 'cache', 'ai1wm-backups', 'updraft', 'backwpup-*', 'wpo-cache', 'et-cache' ) ),
			'plugins'    => array_merge( $common, array( 'siteferry' ) ),
			'mu-plugins' => array_merge( $common, array( 'siteferry-compat.php' ) ),
			default      => $common,
		};
	}

	/**
	 * Whether a path is left out: some segment (or the whole path) matches a pattern, or it is a
	 * half-received file. Each segment is checked so that "updraft/x.zip" is left out even if
	 * the pattern is "updraft". Comparison is lowercase.
	 *
	 * @param list<string> $patterns fnmatch patterns.
	 * @param string       $relative Relative path.
	 */
	public static function matches( array $patterns, string $relative ): bool {
		// Case-insensitive: on macOS or Windows "UpDraft/x.zip" is the same folder as "updraft".
		$relative = strtolower( $relative );
		if ( str_ends_with( $relative, Scanner::PART ) ) {
			return true;
		}
		$segments = explode( '/', $relative );
		foreach ( array_map( 'strtolower', $patterns ) as $pattern ) {
			if ( fnmatch( $pattern, $relative ) ) {
				return true;
			}
			foreach ( $segments as $segment ) {
				if ( fnmatch( $pattern, $segment ) ) {
					return true;
				}
			}
		}
		return false;
	}
}

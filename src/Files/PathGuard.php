<?php
/**
 * Safe paths inside a root folder.
 *
 * @package Siteferry
 */

namespace Siteferry\Files;

/**
 * Every path that travels between sites is relative to a root (uploads, themes, plugins, mu-plugins)
 * and can never leave it: not with "..", not by being absolute, not through a symlink.
 */
final class PathGuard {

	/**
	 * Extensions a server can execute. Media never accepts them.
	 */
	private const EXECUTABLE = '/\.(?:php[0-9]?|pht|phtml|phar|phps|shtml|cgi|pl|py|sh|asp|aspx|jsp)(?:\.|$)/i';

	/**
	 * Web server or PHP configuration files.
	 */
	private const SERVER_CONFIG = array( '.htaccess', '.htpasswd', '.user.ini', 'php.ini', 'web.config' );

	/**
	 * Real root (no trailing slash).
	 *
	 * @var string
	 */
	private string $root;

	/**
	 * Constructor.
	 *
	 * @param string $root Root folder; must exist.
	 *
	 * @throws \InvalidArgumentException If it does not exist.
	 */
	public function __construct( string $root ) {
		$real = realpath( $root );
		if ( false === $real || ! is_dir( $real ) ) {
			throw new \InvalidArgumentException( 'Folder does not exist: ' . $root );
		}
		$this->root = rtrim( $real, '/' );
	}

	/**
	 * Real root.
	 */
	public function root(): string {
		return $this->root;
	}

	/**
	 * Normalizes a relative path ("a/b/c.jpg") or returns null if it is not acceptable.
	 *
	 * @param string $relative Received path.
	 */
	public static function clean( string $relative ): ?string {
		if ( '' === $relative || strlen( $relative ) > 1024 || preg_match( '/[\x00-\x1f\x7f\\\\:]/', $relative ) || str_starts_with( $relative, '/' ) ) {
			return null;
		}
		foreach ( explode( '/', $relative ) as $segment ) {
			if ( '' === $segment || '.' === $segment || '..' === $segment ) {
				return null;
			}
		}
		return $relative;
	}

	/**
	 * Absolute path inside the root, or null if the path is not acceptable or would escape the root
	 * (for example through a folder that is a symlink).
	 *
	 * @param string $relative Relative path.
	 */
	public function resolve( string $relative ): ?string {
		$clean = self::clean( $relative );
		if ( null === $clean ) {
			return null;
		}
		$absolute = $this->root . '/' . $clean;
		// The deepest ancestor that already exists must be inside the root.
		$existing = $absolute;
		while ( ! file_exists( $existing ) && ! is_link( $existing ) ) {
			$existing = dirname( $existing );
		}
		if ( is_link( $existing ) ) {
			return null;
		}
		$real = realpath( $existing );
		if ( false === $real || ( $real !== $this->root && ! str_starts_with( $real, $this->root . '/' ) ) ) {
			return null;
		}
		return $absolute;
	}

	/**
	 * Whether a file could be executed on the server (not accepted in media).
	 *
	 * @param string $relative Relative path.
	 */
	public static function executable( string $relative ): bool {
		$name = strtolower( basename( $relative ) );
		return in_array( $name, self::SERVER_CONFIG, true ) || 1 === preg_match( self::EXECUTABLE, $name );
	}
}

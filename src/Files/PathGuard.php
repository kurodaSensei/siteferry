<?php
/**
 * Rutas seguras dentro de una carpeta raíz.
 *
 * @package Siteferry
 */

namespace Siteferry\Files;

/**
 * Toda ruta que viaja entre sitios es relativa a una raíz (uploads, themes, plugins, mu-plugins)
 * y nunca puede salir de ella: ni con "..", ni siendo absoluta, ni a través de un symlink.
 */
final class PathGuard {

	/**
	 * Extensiones que un servidor puede ejecutar. En media no se aceptan nunca.
	 */
	private const EXECUTABLE = '/\.(?:php[0-9]?|pht|phtml|phar|phps|shtml|cgi|pl|py|sh|asp|aspx|jsp)(?:\.|$)/i';

	/**
	 * Archivos de configuración del servidor web o de PHP.
	 */
	private const SERVER_CONFIG = array( '.htaccess', '.htpasswd', '.user.ini', 'php.ini', 'web.config' );

	/**
	 * Raíz real (sin barra final).
	 *
	 * @var string
	 */
	private string $root;

	/**
	 * Constructor.
	 *
	 * @param string $root Carpeta raíz; debe existir.
	 *
	 * @throws \InvalidArgumentException Si no existe.
	 */
	public function __construct( string $root ) {
		$real = realpath( $root );
		if ( false === $real || ! is_dir( $real ) ) {
			throw new \InvalidArgumentException( 'No existe la carpeta ' . $root );
		}
		$this->root = rtrim( $real, '/' );
	}

	/**
	 * Raíz real.
	 */
	public function root(): string {
		return $this->root;
	}

	/**
	 * Normaliza una ruta relativa ("a/b/c.jpg") o devuelve null si no es aceptable.
	 *
	 * @param string $relative Ruta recibida.
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
	 * Ruta absoluta dentro de la raíz, o null si la ruta no es aceptable o escaparía de la raíz
	 * (por ejemplo a través de una carpeta que es un symlink).
	 *
	 * @param string $relative Ruta relativa.
	 */
	public function resolve( string $relative ): ?string {
		$clean = self::clean( $relative );
		if ( null === $clean ) {
			return null;
		}
		$absolute = $this->root . '/' . $clean;
		// El ancestro más profundo que ya existe tiene que estar dentro de la raíz.
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
	 * Si un archivo podría ejecutarse en el servidor (no se acepta en media).
	 *
	 * @param string $relative Ruta relativa.
	 */
	public static function executable( string $relative ): bool {
		$name = strtolower( basename( $relative ) );
		return in_array( $name, self::SERVER_CONFIG, true ) || 1 === preg_match( self::EXECUTABLE, $name );
	}
}

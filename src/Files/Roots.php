<?php
/**
 * Carpetas que se pueden transferir.
 *
 * @package Siteferry
 */

namespace Siteferry\Files;

/**
 * Raíces conocidas y sus exclusiones por defecto.
 */
final class Roots {

	/** Raíces válidas. */
	public const ALL = array( 'uploads', 'themes', 'plugins', 'mu-plugins' );

	/** Raíces con código: se cambian por carpeta completa y requieren permiso propio para push. */
	public const CODE = array( 'themes', 'plugins', 'mu-plugins' );

	/**
	 * Si la raíz contiene código.
	 *
	 * @param string $root Raíz.
	 */
	public static function is_code( string $root ): bool {
		return in_array( $root, self::CODE, true );
	}

	/**
	 * Patrones excluidos por defecto: caches, repositorios, dependencias, backups de otros plugins
	 * y el propio Siteferry (nunca se reemplaza a sí mismo).
	 *
	 * @param string $root Raíz.
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
	 * Si una ruta queda fuera: algún segmento (o la ruta entera) coincide con un patrón, o es un
	 * archivo a medio recibir. Se mira cada segmento para que "updraft/x.zip" quede fuera aunque
	 * el patrón sea "updraft". Se compara en minúsculas.
	 *
	 * @param list<string> $patterns Patrones fnmatch.
	 * @param string       $relative Ruta relativa.
	 */
	public static function matches( array $patterns, string $relative ): bool {
		// Sin distinguir mayúsculas: en macOS o Windows "UpDraft/x.zip" es la misma carpeta que "updraft".
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

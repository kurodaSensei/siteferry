<?php
/**
 * Recorre una carpeta por tramos.
 *
 * @package Siteferry
 */

namespace Siteferry\Files;

use Siteferry\Engine\Budget;

/**
 * Escaneo reanudable: lista los archivos de una raíz con tamaño y md5, en tantas llamadas
 * como haga falta. El cursor es la cola de carpetas pendientes y la posición dentro de la actual.
 * No sigue symlinks ni lista archivos especiales.
 */
final class Scanner {

	/** Sufijo de los archivos a medio recibir; nunca se listan. */
	public const PART = '.siteferry-part';

	/**
	 * Constructor.
	 *
	 * @param PathGuard    $guard   Raíz.
	 * @param list<string> $exclude Patrones fnmatch contra la ruta relativa o el nombre.
	 * @param bool         $media   Omitir archivos ejecutables (en media no se aceptan).
	 */
	public function __construct( private PathGuard $guard, private array $exclude = array(), private bool $media = false ) {}

	/**
	 * Escanea un tramo y agrega lo encontrado al manifiesto.
	 *
	 * @param array|null $cursor Null para empezar.
	 * @param Manifest   $out    Destino de las entradas.
	 * @param Budget     $budget Tiempo disponible.
	 * @return array{cursor: ?array, files: int, bytes: int} cursor null = terminado.
	 */
	public function scan( ?array $cursor, Manifest $out, Budget $budget ): array {
		$cursor ??= array(
			'queue' => array( '' ),
			'pos'   => 0,
		);
		// El cursor puede venir del otro sitio: solo textos y un entero no negativo.
		$cursor = array(
			'queue' => array_values( array_filter( (array) ( $cursor['queue'] ?? array() ), 'is_string' ) ),
			'pos'   => max( 0, (int) ( $cursor['pos'] ?? 0 ) ),
		);
		$files  = 0;
		$bytes  = 0;
		while ( $cursor['queue'] ) {
			$dir = $cursor['queue'][0];
			if ( ! $this->listable( $dir ) ) {
				array_shift( $cursor['queue'] ); // Una carpeta que no podría estar en la cola: se descarta.
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
					// ponytail: un archivo se hashea de una vez; uno de varios GB puede pasar del presupuesto de la petición.
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
	 * Nombres de una carpeta, ordenados.
	 *
	 * @param string $dir Carpeta relativa.
	 * @return list<string>
	 */
	private function names( string $dir ): array {
		$names = scandir( $this->guard->root() . ( '' === $dir ? '' : '/' . $dir ) );
		return false === $names ? array() : array_values( array_diff( $names, array( '.', '..' ) ) );
	}

	/**
	 * Si una ruta queda fuera por un patrón o es un archivo a medio recibir.
	 *
	 * @param string $relative Ruta relativa.
	 */
	private function excluded( string $relative ): bool {
		return Roots::matches( $this->exclude, $relative );
	}

	/**
	 * Si una carpeta de la cola es legítima: dentro de la raíz, real (no symlink) y no excluida.
	 *
	 * @param string $dir Carpeta relativa ('' = raíz).
	 */
	private function listable( string $dir ): bool {
		if ( '' === $dir ) {
			return true;
		}
		$absolute = $this->guard->resolve( $dir );
		return null !== $absolute && is_dir( $absolute ) && ! is_link( $absolute ) && ! $this->excluded( $dir );
	}
}

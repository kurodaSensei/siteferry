<?php
/**
 * Lotes de archivos para enviar entre sitios.
 *
 * @package Siteferry
 */

namespace Siteferry\Files;

/**
 * Formato: una primera línea "SFYPKG1 {índice JSON}\n" y después los bytes crudos de cada
 * tramo, en orden. Cada entrada del índice es un tramo de un archivo:
 * path, offset (desde qué byte), length (bytes en este paquete), size (tamaño total) y md5
 * (del archivo completo). Un archivo grande viaja en varios paquetes y solo se coloca cuando
 * está completo y su md5 coincide. Sin ZIP: no hay rutas dentro de un archivo comprimido que
 * puedan escapar ni dependencia de ZipArchive.
 */
final class Package {

	private const MAGIC = 'SFYPKG1 ';

	/** Tamaño máximo del índice. */
	private const MAX_INDEX = 8 * 1024 * 1024;

	/**
	 * Arma un paquete con los archivos de una lista, hasta $max_bytes de contenido.
	 *
	 * @param Manifest      $pending   Archivos a enviar.
	 * @param PathGuard     $guard     Raíz de origen.
	 * @param array|null    $cursor    Null para empezar; pos (byte en la lista), offset, size y md5 del archivo en curso.
	 * @param int           $max_bytes Bytes de contenido por paquete (siempre va al menos un tramo).
	 * @param int           $max_files Entradas por paquete.
	 * @param callable|null $allow     fn(string $relative): bool; lo no permitido se informa como faltante.
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
				$missing[]     = $file; // Se borró o cambió de tipo después del escaneo.
				$cursor['pos'] = $after;
				continue;
			}
			if ( 0 === (int) $cursor['offset'] ) {
				// Tamaño y hash al empezar el archivo: si cambia entre tramos, el md5 final no coincidirá.
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
				$cursor['offset'] = $offset + strlen( $chunk ); // El archivo sigue en el próximo paquete.
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

		// Si la lista se terminó, no hay cursor; si se cortó, $next apunta a lo que sigue.
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
	 * Escribe un paquete en una raíz. Cada tramo va a "<archivo>.siteferry-part"; cuando un archivo
	 * está completo y su md5 coincide se entrega a $place (por defecto, rename).
	 *
	 * @param string        $package Paquete.
	 * @param PathGuard     $guard   Raíz de destino.
	 * @param bool          $media   Rechazar ejecutables.
	 * @param callable|null $place   fn(string $part, string $target, string $relative): void.
	 * @param callable|null $allow   fn(string $relative): bool; lo no permitido no se escribe.
	 * @return array{done: list<string>, partial: list<string>, failed: array<string, string>}
	 *
	 * @throws \RuntimeException Si el paquete está mal formado (no se escribe nada).
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
				$result['failed'][ $file ] = 'ruta no permitida';
				continue;
			}
			if ( $media && PathGuard::executable( $file ) ) {
				$result['failed'][ $file ] = 'ejecutable en media';
				continue;
			}
			if ( null !== $allow && ! $allow( $file ) ) {
				$result['failed'][ $file ] = 'ruta excluida';
				continue;
			}
			if ( ( is_dir( $absolute ) || is_link( $absolute ) ) || ! self::ensure_dir( dirname( $absolute ), $guard ) ) {
				$result['failed'][ $file ] = 'no se puede escribir';
				continue;
			}
			$part    = $absolute . Scanner::PART;
			$current = is_file( $part ) ? (int) filesize( $part ) : 0;
			if ( is_link( $part ) ) {
				$result['failed'][ $file ] = 'no se puede escribir';
				continue;
			}
			if ( $entry['offset'] > $current ) {
				// Reintento del último tramo de un archivo que ya quedó colocado: no hay nada que hacer.
				if ( 0 === $current && is_file( $absolute ) && filesize( $absolute ) === $entry['size'] && md5_file( $absolute ) === $entry['md5'] ) {
					$result['done'][] = $file;
					continue;
				}
				$result['failed'][ $file ] = 'falta un tramo anterior';
				continue;
			}
			// Un tramo repetido (reintento) reescribe desde su offset: el resultado es el mismo.
			$handle = fopen( $part, 0 === $entry['offset'] ? 'wb' : 'r+b' );
			if ( ! $handle ) {
				$result['failed'][ $file ] = 'no se puede escribir';
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
				$result['failed'][ $file ] = 'el contenido no coincide (¿cambió durante el envío?)';
				continue;
			}
			$place( $part, $absolute, $file );
			$result['done'][] = $file;
		}
		return $result;
	}

	/**
	 * Valida el paquete completo antes de tocar nada.
	 *
	 * @param string $package Paquete.
	 * @return list<array{path: string, offset: int, length: int, size: int, md5: string, data: string}>
	 *
	 * @throws \RuntimeException Si algo no cuadra.
	 */
	private static function parse( string $package ): array {
		$newline = strpos( $package, "\n" );
		if ( ! str_starts_with( $package, self::MAGIC ) || false === $newline || $newline > self::MAX_INDEX ) {
			throw new \RuntimeException( 'Paquete de archivos inválido.' );
		}
		$index = json_decode( substr( $package, strlen( self::MAGIC ), $newline - strlen( self::MAGIC ) ), true );
		if ( ! is_array( $index['files'] ?? null ) ) {
			throw new \RuntimeException( 'Índice de paquete inválido.' );
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
				throw new \RuntimeException( 'Entrada de paquete inválida.' );
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
			throw new \RuntimeException( 'El paquete no tiene los bytes que declara.' );
		}
		return $entries;
	}

	/**
	 * Crea la carpeta si falta, comprobando que quede dentro de la raíz.
	 *
	 * @param string    $dir   Carpeta absoluta.
	 * @param PathGuard $guard Raíz.
	 */
	private static function ensure_dir( string $dir, PathGuard $guard ): bool {
		if ( ! is_dir( $dir ) && ! mkdir( $dir, 0755, true ) && ! is_dir( $dir ) ) {
			return false;
		}
		$real = realpath( $dir );
		return false !== $real && ( $real === $guard->root() || str_starts_with( $real, $guard->root() . '/' ) );
	}
}

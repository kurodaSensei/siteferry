<?php
/**
 * Paquete de sitio completo (.sfy).
 *
 * @package Siteferry
 */

namespace Siteferry\Files;

/**
 * Formato: "SFYSITE1 {metadatos JSON}\n" y después bloques, cada uno con una línea JSON
 * {"type":"db"|"files","root":…,"length":N}\n seguida de N bytes:
 * - db: un tramo del dump .sql.gz (los tramos van en orden);
 * - files: un paquete de archivos (Package) de una carpeta.
 * Se escribe agregando bloques al final, así una exportación larga avanza por peticiones sin
 * reescribir nada (un ZIP se reescribiría entero en cada una).
 */
final class SitePackage {

	private const MAGIC = 'SFYSITE1 ';

	/** Tamaño máximo de un bloque. */
	public const MAX_FRAME = 64 * 1024 * 1024;

	/**
	 * Crea el paquete con sus metadatos.
	 *
	 * @param string $path Archivo.
	 * @param array  $meta Metadatos (sitio, prefijo, carpetas…).
	 *
	 * @throws \RuntimeException Si no se puede escribir.
	 */
	public static function start( string $path, array $meta ): void {
		if ( false === file_put_contents( $path, self::MAGIC . self::json( $meta ) . "\n" ) ) {
			throw new \RuntimeException( 'No se puede escribir ' . $path );
		}
	}

	/**
	 * Agrega un bloque al final.
	 *
	 * @param string $path  Archivo.
	 * @param array  $frame type y, en files, root.
	 * @param string $bytes Contenido.
	 *
	 * @throws \RuntimeException Si no se puede escribir.
	 */
	public static function append( string $path, array $frame, string $bytes ): void {
		$frame['length'] = strlen( $bytes );
		$data            = self::json( $frame ) . "\n" . $bytes;
		if ( file_put_contents( $path, $data, FILE_APPEND ) !== strlen( $data ) ) {
			throw new \RuntimeException( 'Escritura incompleta en ' . $path . ' (¿disco lleno?)' );
		}
	}

	/**
	 * Metadatos del paquete.
	 *
	 * @param string $path Archivo.
	 *
	 * @throws \RuntimeException Si no es un paquete válido.
	 */
	public static function meta( string $path ): array {
		$handle = fopen( $path, 'rb' );
		$line   = $handle ? fgets( $handle, 1024 * 1024 ) : false;
		if ( $handle ) {
			fclose( $handle );
		}
		$meta = is_string( $line ) && str_starts_with( $line, self::MAGIC ) ? json_decode( substr( $line, strlen( self::MAGIC ) ), true ) : null;
		if ( ! is_array( $meta ) ) {
			throw new \RuntimeException( 'No es un paquete de Siteferry (.sfy) válido.' );
		}
		return $meta;
	}

	/**
	 * Lee el bloque que empieza en $offset (0 = el primero, tras los metadatos).
	 *
	 * @param string $path   Archivo.
	 * @param int    $offset Byte donde empieza el bloque.
	 * @return array{frame: array, bytes: string, next: int}|null Null al llegar al final.
	 *
	 * @throws \RuntimeException Si el bloque está mal formado o cortado.
	 */
	public static function read( string $path, int $offset ): ?array {
		$handle = fopen( $path, 'rb' );
		if ( ! $handle ) {
			throw new \RuntimeException( 'No se puede leer ' . $path );
		}
		if ( 0 === $offset ) {
			fgets( $handle, 1024 * 1024 ); // Metadatos.
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
			throw new \RuntimeException( 'Bloque de paquete inválido.' );
		}
		$bytes = $length > 0 ? (string) fread( $handle, $length ) : '';
		$next  = (int) ftell( $handle );
		fclose( $handle );
		if ( strlen( $bytes ) !== $length ) {
			throw new \RuntimeException( 'El paquete está cortado (¿descarga incompleta?).' );
		}
		return array(
			'frame' => $frame,
			'bytes' => $bytes,
			'next'  => $next,
		);
	}

	/**
	 * JSON en una línea.
	 *
	 * @param array $data Datos.
	 */
	private static function json( array $data ): string {
		return (string) json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
	}
}

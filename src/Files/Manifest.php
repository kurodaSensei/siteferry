<?php
/**
 * Lista de archivos con tamaño y hash.
 *
 * @package Siteferry
 */

namespace Siteferry\Files;

/**
 * Archivo JSONL con una entrada [ruta, tamaño, md5] por línea. Vive en disco para no cargar
 * la base de datos ni la memoria con miles de entradas.
 */
final class Manifest {

	/**
	 * Manejador de escritura, abierto al primer uso.
	 *
	 * @var resource|null
	 */
	private $handle = null;

	/**
	 * Constructor.
	 *
	 * @param string $path Archivo .jsonl.
	 */
	public function __construct( private string $path ) {}

	/**
	 * Ruta del archivo.
	 */
	public function path(): string {
		return $this->path;
	}

	/**
	 * Agrega una entrada.
	 *
	 * @param string $file Ruta relativa.
	 * @param int    $size Bytes.
	 * @param string $md5  Hash.
	 *
	 * @throws \RuntimeException Si no se puede escribir.
	 */
	public function add( string $file, int $size, string $md5 ): void {
		if ( ! $this->handle ) {
			$handle = fopen( $this->path, 'ab' );
			if ( ! $handle ) {
				throw new \RuntimeException( 'No se puede escribir ' . $this->path );
			}
			$this->handle = $handle;
		}
		fwrite( $this->handle, json_encode( array( $file, $size, $md5 ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
	}

	/**
	 * Cierra el archivo.
	 */
	public function close(): void {
		if ( $this->handle ) {
			fclose( $this->handle );
			$this->handle = null;
		}
	}

	/**
	 * Cierra al destruirse.
	 */
	public function __destruct() {
		$this->close();
	}

	/**
	 * Recorre las entradas desde un byte del archivo, junto con dónde empieza la siguiente
	 * (sirve de cursor sin releer el archivo desde el principio).
	 *
	 * @param int $pos Byte donde empezar.
	 * @return \Generator<int, array{0: string, 1: int, 2: string, 3: int}>
	 */
	public function read_from( int $pos ): \Generator {
		$this->close();
		if ( ! is_file( $this->path ) ) {
			return;
		}
		$handle = fopen( $this->path, 'rb' );
		fseek( $handle, $pos );
		while ( false !== ( $line = fgets( $handle ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
			$entry = json_decode( $line, true );
			if ( is_array( $entry ) && 3 === count( $entry ) ) {
				yield array( (string) $entry[0], (int) $entry[1], (string) $entry[2], (int) ftell( $handle ) );
			}
		}
		fclose( $handle );
	}

	/**
	 * Recorre las entradas.
	 *
	 * @return \Generator<int, array{0: string, 1: int, 2: string}>
	 */
	public function entries(): \Generator {
		$this->close();
		if ( ! is_file( $this->path ) ) {
			return;
		}
		$handle = fopen( $this->path, 'rb' );
		while ( false !== ( $line = fgets( $handle ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
			$entry = json_decode( $line, true );
			if ( is_array( $entry ) && 3 === count( $entry ) ) {
				yield array( (string) $entry[0], (int) $entry[1], (string) $entry[2] );
			}
		}
		fclose( $handle );
	}
}

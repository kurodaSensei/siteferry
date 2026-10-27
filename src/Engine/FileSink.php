<?php
/**
 * Escribe las sentencias en un .sql.gz.
 *
 * @package Siteferry
 */

namespace Siteferry\Engine;

/**
 * Sink que agrega sentencias a un archivo gzip. Cada petición abre en modo "a":
 * el archivo queda con varios miembros gzip seguidos, que es gzip válido.
 */
final class FileSink implements Sink {

	/**
	 * Manejador gzip, abierto al primer uso.
	 *
	 * @var resource|null
	 */
	private $handle = null;

	/**
	 * Constructor.
	 *
	 * @param string $path Ruta del .sql.gz.
	 */
	public function __construct( private string $path ) {}

	/**
	 * Escribe una sentencia, una por línea.
	 *
	 * @param string $statement SQL.
	 */
	public function write( string $statement ): void {
		$this->put( $statement . ";\n" );
	}

	/**
	 * Escribe un comentario.
	 *
	 * @param string $text Texto de una línea.
	 */
	public function comment( string $text ): void {
		$this->put( '-- ' . $text . "\n" );
	}

	/**
	 * Cierra el archivo.
	 */
	public function close(): void {
		if ( $this->handle ) {
			gzclose( $this->handle );
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
	 * Escribe texto crudo.
	 *
	 * @param string $data Texto.
	 *
	 * @throws \RuntimeException Si no se puede escribir.
	 */
	private function put( string $data ): void {
		if ( ! $this->handle ) {
			$handle = gzopen( $this->path, 'ab6' );
			if ( ! $handle ) {
				throw new \RuntimeException( 'No se puede escribir ' . $this->path );
			}
			$this->handle = $handle;
		}
		if ( gzwrite( $this->handle, $data ) !== strlen( $data ) ) {
			throw new \RuntimeException( 'Escritura incompleta en ' . $this->path . ' (¿disco lleno?)' );
		}
	}
}

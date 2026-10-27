<?php
/**
 * Writes the statements to a .sql.gz file.
 *
 * @package Siteferry
 */

namespace Siteferry\Engine;

/**
 * Sink that appends statements to a gzip file. Each request opens it in "a" mode:
 * the file ends up with several gzip members back to back, which is valid gzip.
 */
final class FileSink implements Sink {

	/**
	 * Gzip handle, opened on first use.
	 *
	 * @var resource|null
	 */
	private $handle = null;

	/**
	 * Constructor.
	 *
	 * @param string $path Path of the .sql.gz.
	 */
	public function __construct( private string $path ) {}

	/**
	 * Writes a statement, one per line.
	 *
	 * @param string $statement SQL.
	 */
	public function write( string $statement ): void {
		$this->put( $statement . ";\n" );
	}

	/**
	 * Writes a comment.
	 *
	 * @param string $text Single-line text.
	 */
	public function comment( string $text ): void {
		$this->put( '-- ' . $text . "\n" );
	}

	/**
	 * Closes the file.
	 */
	public function close(): void {
		if ( $this->handle ) {
			gzclose( $this->handle );
			$this->handle = null;
		}
	}

	/**
	 * Closes on destruction.
	 */
	public function __destruct() {
		$this->close();
	}

	/**
	 * Writes raw text.
	 *
	 * @param string $data Text.
	 *
	 * @throws \RuntimeException If it cannot be written.
	 */
	private function put( string $data ): void {
		if ( ! $this->handle ) {
			$handle = gzopen( $this->path, 'ab6' );
			if ( ! $handle ) {
				throw new \RuntimeException( 'Cannot write ' . $this->path );
			}
			$this->handle = $handle;
		}
		if ( gzwrite( $this->handle, $data ) !== strlen( $data ) ) {
			throw new \RuntimeException( 'Incomplete write to ' . $this->path . ' (disk full?)' );
		}
	}
}

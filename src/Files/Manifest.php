<?php
/**
 * List of files with size and hash.
 *
 * @package Siteferry
 */

namespace Siteferry\Files;

/**
 * JSONL file with one [path, size, md5] entry per line. It lives on disk so as not to load
 * the database or memory with thousands of entries.
 */
final class Manifest {

	/**
	 * Write handle, opened on first use.
	 *
	 * @var resource|null
	 */
	private $handle = null;

	/**
	 * Constructor.
	 *
	 * @param string $path .jsonl file.
	 */
	public function __construct( private string $path ) {}

	/**
	 * File path.
	 */
	public function path(): string {
		return $this->path;
	}

	/**
	 * Adds an entry.
	 *
	 * @param string $file Relative path.
	 * @param int    $size Bytes.
	 * @param string $md5  Hash.
	 *
	 * @throws \RuntimeException If it cannot be written.
	 */
	public function add( string $file, int $size, string $md5 ): void {
		if ( ! $this->handle ) {
			$handle = fopen( $this->path, 'ab' );
			if ( ! $handle ) {
				throw new \RuntimeException( 'Cannot write ' . $this->path );
			}
			$this->handle = $handle;
		}
		fwrite( $this->handle, json_encode( array( $file, $size, $md5 ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
	}

	/**
	 * Closes the file.
	 */
	public function close(): void {
		if ( $this->handle ) {
			fclose( $this->handle );
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
	 * Iterates the entries from a byte of the file, along with where the next one starts
	 * (works as a cursor without rereading the file from the start).
	 *
	 * @param int $pos Byte to start at.
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
	 * Iterates the entries.
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

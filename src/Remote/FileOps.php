<?php
/**
 * File operations this site serves for another site.
 *
 * @package Siteferry
 */

namespace Siteferry\Remote;

use Siteferry\Engine\Budget;
use Siteferry\Files\Applier;
use Siteferry\Files\Manifest;
use Siteferry\Files\Package;
use Siteferry\Files\PathGuard;
use Siteferry\Files\Roots;
use Siteferry\Files\Scanner;

/**
 * Stateless except for a push session (stage/new/backup in storage/files-<session>):
 * - files_scan: scans a chunk of a root and returns the entries (the comparison is done by whoever drives the operation).
 * - files_get: builds a package with the requested entries (pull).
 * - files_put: receives a package (push).
 * - files_finalize: deletes and swaps code folders (push).
 * Permissions are checked before any effect. No WordPress.
 */
final class FileOps {

	/** Content bytes per pull package. */
	private const GET_BYTES = 8 * 1024 * 1024;

	/**
	 * Constructor.
	 *
	 * @param array<string, string> $roots   Root => absolute folder.
	 * @param string                $storage Plugin working folder.
	 * @param array                 $perms   allow_pull_files, allow_push, allow_push_code.
	 */
	public function __construct( private array $roots, private string $storage, private array $perms ) {}

	/**
	 * Handles an operation.
	 *
	 * @param string      $op     Operation.
	 * @param array       $args   Arguments.
	 * @param string|null $binary Received package (files_put).
	 *
	 * @throws \RuntimeException If it is not allowed or the arguments are not valid.
	 */
	public function handle( string $op, array $args, ?string $binary ): array {
		$root = (string) ( $args['root'] ?? '' );
		if ( ! isset( $this->roots[ $root ] ) ) {
			throw new \RuntimeException( 'Folder not available: ' . $root );
		}
		return match ( $op ) {
			'files_scan'     => $this->scan( $root, $args ),
			'files_get'      => $this->get( $root, $args ),
			'files_put'      => $this->put( $root, $args, (string) $binary ),
			'files_finalize' => $this->finalize( $root, $args ),
			default          => throw new \RuntimeException( 'Unknown operation: ' . $op ),
		};
	}

	/**
	 * Scans a chunk of the root.
	 *
	 * @param string $root Root.
	 * @param array  $args cursor.
	 *
	 * @throws \RuntimeException If there is no permission.
	 */
	private function scan( string $root, array $args ): array {
		// Scanning serves pull (source) and push (destination).
		if ( empty( $this->perms['allow_pull_files'] ) && empty( $this->perms['allow_push'] ) ) {
			throw new \RuntimeException( 'The remote site does not allow file pull or push.' );
		}
		$manifest = new Manifest( $this->temp() );
		$scanner  = new Scanner( new PathGuard( $this->roots[ $root ] ), Roots::exclude( $root ), ! Roots::is_code( $root ) );
		$result   = $scanner->scan( is_array( $args['cursor'] ?? null ) ? $args['cursor'] : null, $manifest, new Budget( 10 ) );
		$entries  = iterator_to_array( $manifest->entries(), false );
		Applier::remove_tree( $manifest->path() ); // An empty folder never gets to create the file.
		return array(
			'entries' => self::pack( $entries ),
			'cursor'  => $result['cursor'],
		);
	}

	/**
	 * Builds a package with the requested entries.
	 *
	 * @param string $root Root.
	 * @param array  $args entries (packed), offset/size/md5 of the file in progress, max_bytes.
	 *
	 * @throws \RuntimeException If there is no permission.
	 */
	private function get( string $root, array $args ): array {
		if ( empty( $this->perms['allow_pull_files'] ) ) {
			throw new \RuntimeException( 'The remote site does not allow file pull. Enable it in its Siteferry.' );
		}
		$path     = $this->temp();
		$manifest = new Manifest( $path );
		foreach ( self::unpack( (string) ( $args['entries'] ?? '' ) ) as $entry ) {
			$manifest->add( (string) ( $entry[0] ?? '' ), (int) ( $entry[1] ?? 0 ), (string) ( $entry[2] ?? '' ) );
		}
		$manifest->close();
		$offset = (int) ( $args['offset'] ?? 0 );
		$cursor = $offset > 0 ? array(
			'pos'    => 0,
			'offset' => $offset,
			'size'   => (int) ( $args['size'] ?? 0 ),
			'md5'    => (string) ( $args['md5'] ?? '' ),
		) : null;
		// Only what the scanner would list is delivered: nothing excluded (other plugins' backups, caches…) and no executables in media.
		$exclude = Roots::exclude( $root );
		$media   = ! Roots::is_code( $root );
		$allow   = static fn( string $file ) => ! Roots::matches( $exclude, $file ) && ! ( $media && PathGuard::executable( $file ) );
		$built   = Package::build( $manifest, new PathGuard( $this->roots[ $root ] ), $cursor, max( 64 * 1024, min( self::GET_BYTES, (int) ( $args['max_bytes'] ?? self::GET_BYTES ) ) ), 2000, $allow );

		// How many entries were completed (the package cursor is a byte in the temporary list).
		$consumed = 0;
		$total    = 0;
		foreach ( $manifest->read_from( 0 ) as [ , , , $after ] ) {
			++$total;
			if ( null !== $built['cursor'] && $after <= $built['cursor']['pos'] ) {
				$consumed = $total;
			}
		}
		Applier::remove_tree( $path );
		$next = $built['cursor'];
		return array(
			'consumed' => null === $next ? $total : $consumed,
			'offset'   => (int) ( $next['offset'] ?? 0 ),
			'size'     => (int) ( $next['size'] ?? 0 ),
			'md5'      => (string) ( $next['md5'] ?? '' ),
			'files'    => $built['files'],
			'bytes'    => $built['bytes'],
			'missing'  => $built['missing'],
			'package'  => $built['package'],
		);
	}

	/**
	 * Receives a package from a push.
	 *
	 * @param string $root    Root.
	 * @param array  $args    session.
	 * @param string $package Package.
	 */
	private function put( string $root, array $args, string $package ): array {
		$this->require_push( $root );
		return $this->applier( $args )->receive( $root, $package );
	}

	/**
	 * Finishes a root of a push.
	 *
	 * @param string $root Root.
	 * @param array  $args session, deletions (packed).
	 */
	private function finalize( string $root, array $args ): array {
		$this->require_push( $root );
		$deletions = array_map( 'strval', self::unpack( (string) ( $args['deletions'] ?? '' ) ) );
		return $this->applier( $args )->finalize( $root, $deletions );
	}

	/**
	 * Push permissions (and code permission if the root is a code root).
	 *
	 * @param string $root Root.
	 *
	 * @throws \RuntimeException If they are not enabled.
	 */
	private function require_push( string $root ): void {
		if ( empty( $this->perms['allow_push'] ) ) {
			throw new \RuntimeException( 'The remote site does not allow push.' );
		}
		if ( Roots::is_code( $root ) && empty( $this->perms['allow_push_code'] ) ) {
			throw new \RuntimeException( 'The remote site does not allow code push (themes and plugins). Enable it in its Siteferry.' );
		}
	}

	/**
	 * Applier for the push session.
	 *
	 * @param array $args session.
	 *
	 * @throws \RuntimeException If the session is not valid.
	 */
	private function applier( array $args ): Applier {
		$session = (string) ( $args['session'] ?? '' );
		if ( ! preg_match( '/^[a-f0-9]{16}$/', $session ) ) {
			throw new \RuntimeException( 'Invalid file session.' );
		}
		return new Applier( $this->roots, $this->storage . '/files-' . $session );
	}

	/**
	 * Temporary file in the working folder.
	 */
	private function temp(): string {
		return $this->storage . '/tmp-' . bin2hex( random_bytes( 8 ) ) . '.jsonl';
	}

	/**
	 * Compressed list for travelling in JSON.
	 *
	 * @param array $items Items.
	 */
	public static function pack( array $items ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode, WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- compressed transport.
		return base64_encode( (string) gzcompress( (string) json_encode( array_values( $items ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ), 6 ) );
	}

	/**
	 * Inverse of pack(), with caps on decompressed size and entry count (no compression "bombs").
	 *
	 * @param string $packed Text.
	 * @return list<mixed>
	 *
	 * @throws \RuntimeException If it exceeds the caps or is not valid.
	 */
	public static function unpack( string $packed ): array {
		if ( '' === $packed ) {
			return array();
		}
		$json  = @gzuncompress( (string) base64_decode( $packed, true ), 16 * 1024 * 1024 ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode, WordPress.PHP.NoSilencedErrors.Discouraged -- compressed transport; the error is reported below.
		$items = false === $json ? null : json_decode( $json, true );
		if ( ! is_array( $items ) || count( $items ) > 200000 ) {
			throw new \RuntimeException( 'Compressed list is invalid or too large.' );
		}
		return array_values( $items );
	}
}

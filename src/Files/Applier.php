<?php
/**
 * Applies what was received on the destination.
 *
 * @package Siteferry
 */

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Messages are plain text for the job log, WP-CLI and REST JSON; the admin shows them with textContent.
// phpcs:disable WordPress.WP.AlternativeFunctions -- Streams files of any size in chunks: WP_Filesystem has no streaming API, and this code also runs without WordPress (tests, WP-CLI packages).

namespace Siteferry\Files;

/**
 * Places the files received in a transfer session.
 *
 * - Media: file by file; the previous file (if any) is moved to backup/ first.
 * - Code (themes, plugins, mu-plugins): everything goes to stage/; on finish, each top-level
 *   folder touched is built in full in new/ (copy of the current one + what was received − what
 *   was deleted) and swapped in at once, keeping the previous one in backup/. A plugin is never
 *   left half-copied.
 *
 * The session lives in $work: stage/, new/ and backup/ (backup/ is kept so the operation can be undone).
 */
final class Applier {

	/**
	 * Constructor.
	 *
	 * @param array<string, string> $roots Root => absolute folder.
	 * @param string                $work  Session folder.
	 */
	public function __construct( private array $roots, private string $work ) {}

	/**
	 * Media paths placed in this session ("root/path" => true), read once per instance.
	 *
	 * @var array<string, true>|null
	 */
	private ?array $placed = null;

	/**
	 * Guard for a live root.
	 *
	 * @param string $root Root.
	 *
	 * @throws \InvalidArgumentException If the root does not exist.
	 */
	public function live( string $root ): PathGuard {
		if ( ! isset( $this->roots[ $root ] ) ) {
			throw new \InvalidArgumentException( 'Unknown folder: ' . $root );
		}
		return new PathGuard( $this->roots[ $root ] );
	}

	/**
	 * Writes a received package.
	 *
	 * @param string $root    Root.
	 * @param string $package Package.
	 * @return array{done: list<string>, partial: list<string>, failed: array<string, string>}
	 */
	public function receive( string $root, string $package ): array {
		$live    = $this->live( $root );
		$exclude = Roots::exclude( $root );
		$allow   = static fn( string $file ) => ! Roots::matches( $exclude, $file ); // E.g. never siteferry/.
		if ( Roots::is_code( $root ) ) {
			return Package::extract( $package, new PathGuard( $this->dir( 'stage', $root ) ), false, null, $allow );
		}
		return Package::extract(
			$package,
			$live,
			true,
			function ( string $part, string $target, string $relative ) use ( $root ): void {
				$this->backup( $target, $root, $relative );
				rename( $part, $target );
				if ( ! $this->placed( $root, $relative ) ) {
					$this->note( "placed-$root", $relative ); // For undo: whatever arrived as new gets removed.
					$this->placed[ "$root/$relative" ] = true;
				}
			},
			$allow
		);
	}

	/**
	 * Finishes the root: deletes (to backup) what is listed and, for code, swaps the folders touched.
	 *
	 * @param string           $root      Root.
	 * @param iterable<string> $deletions Relative paths to delete on the destination.
	 * @return array{replaced: int, deleted: int, skipped: list<string>}
	 *
	 * @throws \RuntimeException If a folder cannot be moved.
	 */
	public function finalize( string $root, iterable $deletions ): array {
		$live    = $this->live( $root );
		$exclude = Roots::exclude( $root );
		$stats   = array(
			'replaced' => 0,
			'deleted'  => 0,
			'skipped'  => array(),
		);
		// Only what the scanner would list is deleted: never .htaccess, server configuration or excluded paths.
		$protected = static fn( string $relative ) => Roots::matches( $exclude, $relative ) || PathGuard::executable( $relative );
		if ( ! Roots::is_code( $root ) ) {
			foreach ( $deletions as $relative ) {
				if ( $protected( $relative ) ) {
					continue;
				}
				$absolute = $live->resolve( $relative );
				if ( null !== $absolute && is_file( $absolute ) && ! is_link( $absolute ) ) {
					$this->backup( $absolute, $root, $relative );
					++$stats['deleted'];
				}
			}
			return $stats;
		}

		$stage  = $this->dir( 'stage', $root );
		$by_top = array();
		foreach ( $deletions as $relative ) {
			if ( null !== PathGuard::clean( $relative ) && ! Roots::matches( $exclude, $relative ) ) {
				$by_top[ explode( '/', $relative )[0] ][] = $relative;
			}
		}
		$tops = array_unique( array_merge( array_diff( (array) scandir( $stage ), array( '.', '..' ) ), array_keys( $by_top ) ) );

		foreach ( $tops as $top ) {
			if ( Roots::matches( $exclude, (string) $top ) ) {
				continue; // Siteferry and anything excluded are never replaced.
			}
			$target = $live->resolve( (string) $top );
			if ( null === $target ) {
				$stats['skipped'][] = (string) $top; // E.g. a plugin that is a symlink on the destination.
				continue;
			}
			$staged = $stage . '/' . $top;
			$this->note(
				"swapped-$root",
				array(
					'top'     => (string) $top,
					'existed' => file_exists( $target ),
				)
			);
			if ( is_file( $staged ) || ( ! is_dir( $staged ) && ! is_dir( $target ) ) ) {
				// Loose file at the top level (mu-plugins/x.php, plugins/hello.php).
				if ( is_file( $target ) ) {
					$this->backup( $target, $root, (string) $top );
					++$stats[ is_file( $staged ) ? 'replaced' : 'deleted' ];
				}
				if ( is_file( $staged ) ) {
					rename( $staged, $target );
				}
				continue;
			}
			$new = $this->dir( 'new', $root ) . '/' . $top;
			if ( is_dir( $target ) ) {
				self::copy_tree( $target, $new );
			}
			if ( is_dir( $staged ) ) {
				self::move_tree( $staged, $new );
			}
			$guard = new PathGuard( $this->dir( 'new', $root ) );
			foreach ( $by_top[ $top ] ?? array() as $relative ) {
				$file = $guard->resolve( $relative );
				if ( null !== $file && is_file( $file ) ) {
					unlink( $file );
					++$stats['deleted'];
				}
			}
			if ( is_dir( $target ) ) {
				$this->backup( $target, $root, (string) $top );
			}
			// ponytail: rename requires the session and wp-content to be on the same filesystem.
			if ( ! rename( $new, $target ) ) {
				throw new \RuntimeException( 'Could not place ' . $root . '/' . $top );
			}
			++$stats['replaced'];
		}
		self::remove_tree( $stage );
		self::remove_tree( $this->work . '/new/' . $root );
		return $stats;
	}

	/**
	 * Destination files left over inside the top-level folders received in stage/
	 * (a site package carries each folder in full: whatever did not come no longer exists on the source).
	 *
	 * @param string $root Code root.
	 * @return list<string> Relative paths.
	 */
	public function leftovers( string $root ): array {
		$stage = $this->work . '/stage/' . $root;
		$live  = $this->roots[ $root ];
		$out   = array();
		foreach ( is_dir( $stage ) ? array_diff( (array) scandir( $stage ), array( '.', '..' ) ) : array() as $top ) {
			if ( ! is_dir( "$stage/$top" ) || is_link( "$live/$top" ) || ! is_dir( "$live/$top" ) ) {
				continue;
			}
			$items = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( "$live/$top", \FilesystemIterator::SKIP_DOTS ) );
			foreach ( $items as $item ) {
				$relative = substr( $item->getPathname(), strlen( $live ) + 1 );
				if ( $item->isFile() && ! file_exists( "$stage/$relative" ) ) {
					$out[] = $relative;
				}
			}
		}
		return $out;
	}

	/**
	 * Undoes what was done on a root during the session: what was there comes back from backup/ and what
	 * arrived as new is moved aside to undone/ (not deleted). It can be repeated: anything already moved
	 * aside or restored is not touched again.
	 *
	 * @param string $root Root.
	 * @return array{restored: int, removed: int}
	 */
	public function restore( string $root ): array {
		$live     = $this->live( $root );
		$backup   = $this->work . '/backup/' . $root;
		$undone   = $this->work . '/undone/' . $root;
		$restored = array_flip( array_map( 'strval', $this->notes( "restored-$root" ) ) );
		$stats    = array(
			'restored' => 0,
			'removed'  => 0,
		);
		if ( Roots::is_code( $root ) ) {
			// A repeated folder (finalize retry) counts by its first entry: the one from before the session.
			$swaps = array();
			foreach ( $this->notes( "swapped-$root" ) as $swap ) {
				$swaps[ (string) ( $swap['top'] ?? '' ) ] ??= $swap;
			}
			foreach ( $swaps as $swap ) {
				$top    = (string) ( $swap['top'] ?? '' );
				$target = '' === $top ? null : $live->resolve( $top );
				if ( null === $target || file_exists( "$undone/$top" ) || isset( $restored[ $top ] ) ) {
					continue;
				}
				if ( file_exists( $target ) ) {
					$this->move( $target, "$undone/$top" );
					++$stats['removed'];
				}
				if ( ! empty( $swap['existed'] ) && file_exists( "$backup/$top" ) ) {
					rename( "$backup/$top", $target );
					$this->note( "restored-$root", $top );
					++$stats['restored'];
				}
			}
			return $stats;
		}
		foreach ( array_unique( $this->notes( "placed-$root" ) ) as $relative ) {
			$target = $live->resolve( (string) $relative );
			if ( null !== $target && is_file( $target ) && ! file_exists( "$backup/$relative" ) && ! file_exists( "$undone/$relative" ) && ! isset( $restored[ $relative ] ) ) {
				$this->move( $target, "$undone/$relative" );
				++$stats['removed'];
			}
		}
		if ( is_dir( $backup ) ) {
			$items = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $backup, \FilesystemIterator::SKIP_DOTS ) );
			foreach ( $items as $item ) {
				if ( $item->isLink() || ! $item->isFile() ) {
					continue;
				}
				$relative = substr( $item->getPathname(), strlen( $backup ) + 1 );
				$target   = $live->resolve( $relative );
				if ( null === $target ) {
					continue;
				}
				if ( file_exists( $target ) ) {
					$this->move( $target, "$undone/$relative" );
				}
				$this->move( $item->getPathname(), $target );
				$this->note( "restored-$root", $relative );
				++$stats['restored'];
			}
		}
		return $stats;
	}

	/**
	 * Whether a media file already arrived in this session.
	 *
	 * @param string $root     Root.
	 * @param string $relative Relative path.
	 */
	private function placed( string $root, string $relative ): bool {
		if ( null === $this->placed ) {
			$this->placed = array();
			foreach ( Roots::ALL as $name ) {
				foreach ( $this->notes( "placed-$name" ) as $path ) {
					$this->placed[ "$name/$path" ] = true;
				}
			}
		}
		return isset( $this->placed[ "$root/$relative" ] );
	}

	/**
	 * Records something in a session JSONL log.
	 *
	 * @param string $name  Log.
	 * @param mixed  $entry Entry.
	 */
	private function note( string $name, mixed $entry ): void {
		if ( ! is_dir( $this->work ) ) {
			mkdir( $this->work, 0755, true );
		}
		file_put_contents( "$this->work/$name.jsonl", json_encode( $entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n", FILE_APPEND ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
	}

	/**
	 * Entries of a session log.
	 *
	 * @param string $name Log.
	 * @return list<mixed>
	 */
	private function notes( string $name ): array {
		$file = "$this->work/$name.jsonl";
		if ( ! is_file( $file ) ) {
			return array();
		}
		return array_map( static fn( $line ) => json_decode( $line, true ), (array) file( $file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) );
	}

	/**
	 * Moves a file or folder, creating whatever is needed (and replacing anything at the target).
	 *
	 * @param string $from Source.
	 * @param string $to   Target.
	 *
	 * @throws \RuntimeException If it cannot be moved.
	 */
	private function move( string $from, string $to ): void {
		if ( ! is_dir( dirname( $to ) ) ) {
			mkdir( dirname( $to ), 0755, true );
		}
		if ( file_exists( $to ) ) {
			self::remove_tree( $to );
		}
		if ( ! rename( $from, $to ) ) {
			throw new \RuntimeException( 'Could not move ' . $from );
		}
	}

	/**
	 * Moves what was on the destination to backup/, keeping the path.
	 *
	 * @param string $absolute File or folder.
	 * @param string $root     Root.
	 * @param string $relative Relative path.
	 *
	 * @throws \RuntimeException If it cannot be moved.
	 */
	private function backup( string $absolute, string $root, string $relative ): void {
		if ( ! file_exists( $absolute ) ) {
			return;
		}
		$to = $this->work . '/backup/' . $root . '/' . $relative;
		if ( file_exists( $to ) || ( ! Roots::is_code( $root ) && $this->placed( $root, $relative ) ) ) {
			// Retry: the backup (or its absence) is the state from before the session and the current file arrived during it.
			self::remove_tree( $absolute );
			return;
		}
		$this->dir( 'backup', $root );
		if ( ! is_dir( dirname( $to ) ) ) {
			mkdir( dirname( $to ), 0755, true );
		}
		if ( ! rename( $absolute, $to ) ) {
			throw new \RuntimeException( 'Could not back up ' . $root . '/' . $relative );
		}
	}

	/**
	 * Session working folder (created if missing).
	 *
	 * @param string $kind stage | new.
	 * @param string $root Root.
	 */
	private function dir( string $kind, string $root ): string {
		$dir = $this->work . '/' . $kind . '/' . $root;
		if ( ! is_dir( $dir ) ) {
			mkdir( $dir, 0755, true );
		}
		if ( ! is_file( $this->work . '/index.php' ) ) {
			file_put_contents( $this->work . '/index.php', "<?php\n// Silence is golden.\n" );
		}
		return $dir;
	}

	/**
	 * Copies a folder (without following symlinks).
	 *
	 * @param string $from Source.
	 * @param string $to   Target.
	 */
	private static function copy_tree( string $from, string $to ): void {
		if ( ! is_dir( $to ) ) {
			mkdir( $to, 0755, true );
		}
		$items = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $from, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::SELF_FIRST );
		foreach ( $items as $item ) {
			$path = $to . '/' . substr( $item->getPathname(), strlen( $from ) + 1 );
			if ( $item->isLink() ) {
				continue;
			}
			if ( $item->isDir() ) {
				if ( ! is_dir( $path ) ) {
					mkdir( $path, 0755, true );
				}
			} else {
				copy( $item->getPathname(), $path );
			}
		}
	}

	/**
	 * Moves the files of one folder onto another (overwriting).
	 *
	 * @param string $from Source.
	 * @param string $to   Target.
	 */
	private static function move_tree( string $from, string $to ): void {
		$items = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $from, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $items as $item ) {
			if ( $item->isLink() || ! $item->isFile() ) {
				continue;
			}
			$path = $to . '/' . substr( $item->getPathname(), strlen( $from ) + 1 );
			if ( ! is_dir( dirname( $path ) ) ) {
				mkdir( dirname( $path ), 0755, true );
			}
			rename( $item->getPathname(), $path );
		}
	}

	/**
	 * Deletes a folder or file (without following symlinks).
	 *
	 * @param string $path Path.
	 */
	public static function remove_tree( string $path ): void {
		if ( is_link( $path ) || is_file( $path ) ) {
			unlink( $path );
			return;
		}
		if ( ! is_dir( $path ) ) {
			return;
		}
		$items = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $path, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $items as $item ) {
			( $item->isDir() && ! $item->isLink() ) ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() );
		}
		rmdir( $path );
	}
}

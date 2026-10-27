<?php
/**
 * Aplica en el destino lo recibido.
 *
 * @package Siteferry
 */

namespace Siteferry\Files;

/**
 * Coloca los archivos recibidos en una sesión de transferencia.
 *
 * - Media: archivo por archivo; el anterior (si existía) se mueve a backup/ antes.
 * - Código (temas, plugins, mu-plugins): todo va a stage/; al finalizar, cada carpeta de primer
 *   nivel tocada se arma completa en new/ (copia de la actual + lo recibido − lo borrado) y se
 *   cambia de una vez, guardando la anterior en backup/. Un plugin nunca queda a medio copiar.
 *
 * La sesión vive en $work: stage/, new/ y backup/ (este se conserva para poder deshacer).
 */
final class Applier {

	/**
	 * Constructor.
	 *
	 * @param array<string, string> $roots Raíz => carpeta absoluta.
	 * @param string                $work  Carpeta de la sesión.
	 */
	public function __construct( private array $roots, private string $work ) {}

	/**
	 * Rutas de media colocadas en esta sesión ("raíz/ruta" => true), leídas una vez por instancia.
	 *
	 * @var array<string, true>|null
	 */
	private ?array $placed = null;

	/**
	 * Guard de una raíz viva.
	 *
	 * @param string $root Raíz.
	 *
	 * @throws \InvalidArgumentException Si la raíz no existe.
	 */
	public function live( string $root ): PathGuard {
		if ( ! isset( $this->roots[ $root ] ) ) {
			throw new \InvalidArgumentException( 'Carpeta desconocida: ' . $root );
		}
		return new PathGuard( $this->roots[ $root ] );
	}

	/**
	 * Escribe un paquete recibido.
	 *
	 * @param string $root    Raíz.
	 * @param string $package Paquete.
	 * @return array{done: list<string>, partial: list<string>, failed: array<string, string>}
	 */
	public function receive( string $root, string $package ): array {
		$live    = $this->live( $root );
		$exclude = Roots::exclude( $root );
		$allow   = static fn( string $file ) => ! Roots::matches( $exclude, $file ); // P. ej. nunca siteferry/.
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
					$this->note( "placed-$root", $relative ); // Para deshacer: lo que llegó nuevo se quita.
					$this->placed[ "$root/$relative" ] = true;
				}
			},
			$allow
		);
	}

	/**
	 * Termina la raíz: borra (a backup) lo indicado y, en código, cambia las carpetas tocadas.
	 *
	 * @param string           $root      Raíz.
	 * @param iterable<string> $deletions Rutas relativas a borrar en el destino.
	 * @return array{replaced: int, deleted: int, skipped: list<string>}
	 *
	 * @throws \RuntimeException Si no se puede mover una carpeta.
	 */
	public function finalize( string $root, iterable $deletions ): array {
		$live    = $this->live( $root );
		$exclude = Roots::exclude( $root );
		$stats   = array(
			'replaced' => 0,
			'deleted'  => 0,
			'skipped'  => array(),
		);
		// Solo se borra lo que el escáner listaría: nunca .htaccess, configuración del servidor ni rutas excluidas.
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
				continue; // Siteferry y lo excluido nunca se reemplazan.
			}
			$target = $live->resolve( (string) $top );
			if ( null === $target ) {
				$stats['skipped'][] = (string) $top; // P. ej. un plugin que es un symlink en el destino.
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
				// Archivo suelto en el primer nivel (mu-plugins/x.php, plugins/hello.php).
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
			// ponytail: rename exige que la sesión y wp-content estén en el mismo sistema de archivos.
			if ( ! rename( $new, $target ) ) {
				throw new \RuntimeException( 'No se pudo colocar ' . $root . '/' . $top );
			}
			++$stats['replaced'];
		}
		self::remove_tree( $stage );
		self::remove_tree( $this->work . '/new/' . $root );
		return $stats;
	}

	/**
	 * Archivos del destino que sobran dentro de las carpetas de primer nivel recibidas en stage/
	 * (un paquete de sitio trae cada carpeta completa: lo que no vino ya no existe en el origen).
	 *
	 * @param string $root Raíz de código.
	 * @return list<string> Rutas relativas.
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
	 * Deshace lo hecho en una raíz durante la sesión: lo que estaba vuelve desde backup/ y lo que
	 * llegó nuevo se aparta a undone/ (no se borra). Se puede repetir: lo ya apartado o restaurado
	 * no se vuelve a tocar.
	 *
	 * @param string $root Raíz.
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
			// Una carpeta repetida (reintento de finalize) cuenta por su primera anotación: la de antes de la sesión.
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
	 * Si un archivo de media ya llegó en esta sesión.
	 *
	 * @param string $root     Raíz.
	 * @param string $relative Ruta relativa.
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
	 * Anota algo en un registro JSONL de la sesión.
	 *
	 * @param string $name  Registro.
	 * @param mixed  $entry Entrada.
	 */
	private function note( string $name, mixed $entry ): void {
		if ( ! is_dir( $this->work ) ) {
			mkdir( $this->work, 0755, true );
		}
		file_put_contents( "$this->work/$name.jsonl", json_encode( $entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n", FILE_APPEND ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
	}

	/**
	 * Entradas de un registro de la sesión.
	 *
	 * @param string $name Registro.
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
	 * Mueve un archivo o carpeta creando lo necesario (y reemplazando lo que hubiera en el destino).
	 *
	 * @param string $from Origen.
	 * @param string $to   Destino.
	 *
	 * @throws \RuntimeException Si no se puede.
	 */
	private function move( string $from, string $to ): void {
		if ( ! is_dir( dirname( $to ) ) ) {
			mkdir( dirname( $to ), 0755, true );
		}
		if ( file_exists( $to ) ) {
			self::remove_tree( $to );
		}
		if ( ! rename( $from, $to ) ) {
			throw new \RuntimeException( 'No se pudo mover ' . $from );
		}
	}

	/**
	 * Mueve lo que había en el destino a backup/, conservando la ruta.
	 *
	 * @param string $absolute Archivo o carpeta.
	 * @param string $root     Raíz.
	 * @param string $relative Ruta relativa.
	 *
	 * @throws \RuntimeException Si no se puede mover.
	 */
	private function backup( string $absolute, string $root, string $relative ): void {
		if ( ! file_exists( $absolute ) ) {
			return;
		}
		$to = $this->work . '/backup/' . $root . '/' . $relative;
		if ( file_exists( $to ) || ( ! Roots::is_code( $root ) && $this->placed( $root, $relative ) ) ) {
			// Reintento: el backup (o su ausencia) es el estado de antes de la sesión y lo actual llegó en ella.
			self::remove_tree( $absolute );
			return;
		}
		$this->dir( 'backup', $root );
		if ( ! is_dir( dirname( $to ) ) ) {
			mkdir( dirname( $to ), 0755, true );
		}
		if ( ! rename( $absolute, $to ) ) {
			throw new \RuntimeException( 'No se pudo respaldar ' . $root . '/' . $relative );
		}
	}

	/**
	 * Carpeta de trabajo de la sesión (se crea si falta).
	 *
	 * @param string $kind stage | new.
	 * @param string $root Raíz.
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
	 * Copia una carpeta (sin seguir symlinks).
	 *
	 * @param string $from Origen.
	 * @param string $to   Destino.
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
	 * Mueve los archivos de una carpeta sobre otra (sobrescribiendo).
	 *
	 * @param string $from Origen.
	 * @param string $to   Destino.
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
	 * Borra una carpeta o archivo (sin seguir symlinks).
	 *
	 * @param string $path Ruta.
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

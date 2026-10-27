<?php
/**
 * Operaciones de archivos que este sitio atiende para otro.
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
 * Sin estado salvo la sesión de un push (stage/new/backup en storage/files-<sesión>):
 * - files_scan: escanea un tramo de una raíz y devuelve las entradas (la comparación la hace quien dirige).
 * - files_get: arma un paquete con las entradas pedidas (pull).
 * - files_put: recibe un paquete (push).
 * - files_finalize: borra y cambia carpetas de código (push).
 * Los permisos se comprueban antes de cualquier efecto. Sin WordPress.
 */
final class FileOps {

	/** Bytes de contenido por paquete de pull. */
	private const GET_BYTES = 8 * 1024 * 1024;

	/**
	 * Constructor.
	 *
	 * @param array<string, string> $roots   Raíz => carpeta absoluta.
	 * @param string                $storage Carpeta de trabajo del plugin.
	 * @param array                 $perms   allow_pull_files, allow_push, allow_push_code.
	 */
	public function __construct( private array $roots, private string $storage, private array $perms ) {}

	/**
	 * Atiende una operación.
	 *
	 * @param string      $op     Operación.
	 * @param array       $args   Argumentos.
	 * @param string|null $binary Paquete recibido (files_put).
	 *
	 * @throws \RuntimeException Si no está permitida o los argumentos no son válidos.
	 */
	public function handle( string $op, array $args, ?string $binary ): array {
		$root = (string) ( $args['root'] ?? '' );
		if ( ! isset( $this->roots[ $root ] ) ) {
			throw new \RuntimeException( 'Carpeta no disponible: ' . $root );
		}
		return match ( $op ) {
			'files_scan'     => $this->scan( $root, $args ),
			'files_get'      => $this->get( $root, $args ),
			'files_put'      => $this->put( $root, $args, (string) $binary ),
			'files_finalize' => $this->finalize( $root, $args ),
			default          => throw new \RuntimeException( 'Operación desconocida: ' . $op ),
		};
	}

	/**
	 * Escanea un tramo de la raíz.
	 *
	 * @param string $root Raíz.
	 * @param array  $args cursor.
	 *
	 * @throws \RuntimeException Si no hay permiso.
	 */
	private function scan( string $root, array $args ): array {
		// Escanear sirve al pull (origen) y al push (destino).
		if ( empty( $this->perms['allow_pull_files'] ) && empty( $this->perms['allow_push'] ) ) {
			throw new \RuntimeException( 'El sitio remoto no permite pull de archivos ni push.' );
		}
		$manifest = new Manifest( $this->temp() );
		$scanner  = new Scanner( new PathGuard( $this->roots[ $root ] ), Roots::exclude( $root ), ! Roots::is_code( $root ) );
		$result   = $scanner->scan( is_array( $args['cursor'] ?? null ) ? $args['cursor'] : null, $manifest, new Budget( 10 ) );
		$entries  = iterator_to_array( $manifest->entries(), false );
		Applier::remove_tree( $manifest->path() ); // Una carpeta vacía no llega a crear el archivo.
		return array(
			'entries' => self::pack( $entries ),
			'cursor'  => $result['cursor'],
		);
	}

	/**
	 * Arma un paquete con las entradas pedidas.
	 *
	 * @param string $root Raíz.
	 * @param array  $args entries (empaquetadas), offset/size/md5 del archivo en curso, max_bytes.
	 *
	 * @throws \RuntimeException Si no hay permiso.
	 */
	private function get( string $root, array $args ): array {
		if ( empty( $this->perms['allow_pull_files'] ) ) {
			throw new \RuntimeException( 'El sitio remoto no permite pull de archivos. Actívalo en su Siteferry.' );
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
		// Solo se entrega lo que el escáner listaría: nada excluido (backups de otros plugins, caches…) ni ejecutable en media.
		$exclude = Roots::exclude( $root );
		$media   = ! Roots::is_code( $root );
		$allow   = static fn( string $file ) => ! Roots::matches( $exclude, $file ) && ! ( $media && PathGuard::executable( $file ) );
		$built   = Package::build( $manifest, new PathGuard( $this->roots[ $root ] ), $cursor, max( 64 * 1024, min( self::GET_BYTES, (int) ( $args['max_bytes'] ?? self::GET_BYTES ) ) ), 2000, $allow );

		// Cuántas entradas quedaron completas (el cursor del paquete es un byte de la lista temporal).
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
	 * Recibe un paquete de un push.
	 *
	 * @param string $root    Raíz.
	 * @param array  $args    session.
	 * @param string $package Paquete.
	 */
	private function put( string $root, array $args, string $package ): array {
		$this->require_push( $root );
		return $this->applier( $args )->receive( $root, $package );
	}

	/**
	 * Termina una raíz de un push.
	 *
	 * @param string $root Raíz.
	 * @param array  $args session, deletions (empaquetadas).
	 */
	private function finalize( string $root, array $args ): array {
		$this->require_push( $root );
		$deletions = array_map( 'strval', self::unpack( (string) ( $args['deletions'] ?? '' ) ) );
		return $this->applier( $args )->finalize( $root, $deletions );
	}

	/**
	 * Permisos de push (y de código si la raíz lo es).
	 *
	 * @param string $root Raíz.
	 *
	 * @throws \RuntimeException Si no están activados.
	 */
	private function require_push( string $root ): void {
		if ( empty( $this->perms['allow_push'] ) ) {
			throw new \RuntimeException( 'El sitio remoto no permite push.' );
		}
		if ( Roots::is_code( $root ) && empty( $this->perms['allow_push_code'] ) ) {
			throw new \RuntimeException( 'El sitio remoto no permite push de código (temas y plugins). Actívalo en su Siteferry.' );
		}
	}

	/**
	 * Applier de la sesión de push.
	 *
	 * @param array $args session.
	 *
	 * @throws \RuntimeException Si la sesión no es válida.
	 */
	private function applier( array $args ): Applier {
		$session = (string) ( $args['session'] ?? '' );
		if ( ! preg_match( '/^[a-f0-9]{16}$/', $session ) ) {
			throw new \RuntimeException( 'Sesión de archivos inválida.' );
		}
		return new Applier( $this->roots, $this->storage . '/files-' . $session );
	}

	/**
	 * Archivo temporal en la carpeta de trabajo.
	 */
	private function temp(): string {
		return $this->storage . '/tmp-' . bin2hex( random_bytes( 8 ) ) . '.jsonl';
	}

	/**
	 * Lista comprimida para viajar en JSON.
	 *
	 * @param array $items Elementos.
	 */
	public static function pack( array $items ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode, WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- transporte comprimido.
		return base64_encode( (string) gzcompress( (string) json_encode( array_values( $items ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ), 6 ) );
	}

	/**
	 * Inverso de pack(), con tope de tamaño descomprimido y de entradas (sin "bombas" de compresión).
	 *
	 * @param string $packed Texto.
	 * @return list<mixed>
	 *
	 * @throws \RuntimeException Si excede los topes o no es válido.
	 */
	public static function unpack( string $packed ): array {
		if ( '' === $packed ) {
			return array();
		}
		$json  = @gzuncompress( (string) base64_decode( $packed, true ), 16 * 1024 * 1024 ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode, WordPress.PHP.NoSilencedErrors.Discouraged -- transporte comprimido; el error se informa abajo.
		$items = false === $json ? null : json_decode( $json, true );
		if ( ! is_array( $items ) || count( $items ) > 200000 ) {
			throw new \RuntimeException( 'Lista comprimida inválida o demasiado grande.' );
		}
		return array_values( $items );
	}
}

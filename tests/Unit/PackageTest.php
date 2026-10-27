<?php
/**
 * Pruebas del formato de paquete de archivos.
 *
 * @package Siteferry
 */

namespace Siteferry\Tests\Unit;

use Siteferry\Engine\Budget;
use Siteferry\Files\Manifest;
use Siteferry\Files\Package;
use Siteferry\Files\PathGuard;
use Siteferry\Files\Scanner;
use PHPUnit\Framework\TestCase;

final class PackageTest extends TestCase {

	private string $tmp;

	protected function setUp(): void {
		$this->tmp = sys_get_temp_dir() . '/siteferry-pkg-' . bin2hex( random_bytes( 4 ) );
		mkdir( "$this->tmp/src", 0777, true );
		mkdir( "$this->tmp/dst", 0777, true );
	}

	protected function tearDown(): void {
		$it = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $this->tmp, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $it as $f ) {
			( $f->isDir() && ! $f->isLink() ) ? rmdir( $f->getPathname() ) : unlink( $f->getPathname() );
		}
		rmdir( $this->tmp );
	}

	private function put( string $relative, string $content ): void {
		$path = "$this->tmp/src/$relative";
		if ( ! is_dir( dirname( $path ) ) ) {
			mkdir( dirname( $path ), 0777, true );
		}
		file_put_contents( $path, $content );
	}

	/** @return array<string, array{int, string}> */
	private function scan( string $root ): array {
		$m = new Manifest( "$this->tmp/scan-" . bin2hex( random_bytes( 3 ) ) . '.jsonl' );
		( new Scanner( new PathGuard( "$this->tmp/$root" ) ) )->scan( null, $m, new Budget( 60 ) );
		$out = array();
		foreach ( $m->entries() as [ $f, $s, $h ] ) {
			$out[ $f ] = array( $s, $h );
		}
		ksort( $out );
		return $out;
	}

	private function list_of_sources(): Manifest {
		$list = new Manifest( "$this->tmp/list.jsonl" );
		( new Scanner( new PathGuard( "$this->tmp/src" ) ) )->scan( null, $list, new Budget( 60 ) );
		return $list;
	}

	/** Paquete armado a mano: [path, offset, size, md5, data]. */
	private static function craft( array $items, string $tail = '' ): string {
		$index = array();
		$body  = '';
		foreach ( $items as [ $path, $offset, $size, $md5, $data ] ) {
			$index[] = array( 'path' => $path, 'offset' => $offset, 'length' => strlen( $data ), 'size' => $size, 'md5' => $md5 );
			$body   .= $data;
		}
		return 'SFYPKG1 ' . json_encode( array( 'files' => $index ) ) . "\n" . $body . $tail;
	}

	public function test_round_trip_in_small_packages_even_with_retries(): void {
		$this->put( 'vacío.txt', '' );
		$this->put( '2024/05/foto pequeña.jpg', str_repeat( 'a', 1000 ) );
		$this->put( 'año/canción ñ.mp3', random_bytes( 3 * 1024 * 1024 + 7 ) );
		for ( $i = 0; $i < 40; $i++ ) {
			$this->put( "muchos/$i.txt", "archivo $i" );
		}
		$list   = $this->list_of_sources();
		$src    = new PathGuard( "$this->tmp/src" );
		$dst    = new PathGuard( "$this->tmp/dst" );
		$cursor = null;
		$count  = 0;
		do {
			$built  = Package::build( $list, $src, $cursor, 512 * 1024, 10 );
			$cursor = $built['cursor'] ? json_decode( json_encode( $built['cursor'] ), true ) : null;
			Package::extract( $built['package'], $dst, true );
			$second = Package::extract( $built['package'], $dst, true ); // Reintento tras un corte de red.
			$this->assertSame( array(), $second['failed'] );
			++$count;
		} while ( $cursor );

		$this->assertGreaterThan( 6, $count );
		$this->assertSame( $this->scan( 'src' ), $this->scan( 'dst' ) );
		$this->assertSame( array(), glob( "$this->tmp/dst/*/*" . Scanner::PART ), 'no quedan partes' );
	}

	public function test_chunks_must_arrive_in_order(): void {
		$data   = str_repeat( 'x', 100 );
		$result = Package::extract( self::craft( array( array( 'a.bin', 50, 100, md5( $data ), substr( $data, 50 ) ) ) ), new PathGuard( "$this->tmp/dst" ), true );
		$this->assertSame( array( 'a.bin' => 'falta un tramo anterior' ), $result['failed'] );
	}

	public function test_file_changed_mid_transfer_is_not_placed(): void {
		$this->put( 'grande.bin', random_bytes( 200 * 1024 ) );
		$list  = $this->list_of_sources();
		$src   = new PathGuard( "$this->tmp/src" );
		$dst   = new PathGuard( "$this->tmp/dst" );
		$first = Package::build( $list, $src, null, 100 * 1024 );
		Package::extract( $first['package'], $dst, true );
		file_put_contents( "$this->tmp/src/grande.bin", random_bytes( 200 * 1024 ) ); // Cambia en medio.
		$second = Package::build( $list, $src, $first['cursor'], 100 * 1024 );
		$result = Package::extract( $second['package'], $dst, true );
		$this->assertArrayHasKey( 'grande.bin', $result['failed'] );
		$this->assertFileDoesNotExist( "$this->tmp/dst/grande.bin" );
		$this->assertFileDoesNotExist( "$this->tmp/dst/grande.bin" . Scanner::PART );
	}

	public function test_missing_files_are_reported(): void {
		$this->put( 'a.txt', 'a' );
		$this->put( 'b.txt', 'b' );
		$list = $this->list_of_sources();
		unlink( "$this->tmp/src/a.txt" );
		$built = Package::build( $list, new PathGuard( "$this->tmp/src" ), null, 1024 );
		$this->assertSame( array( 'a.txt' ), $built['missing'] );
		$this->assertSame( 1, $built['files'] );
	}

	public function test_malformed_packages_write_nothing(): void {
		$ok  = array( 'ok.txt', 0, 2, md5( 'ok' ), 'ok' );
		$bad = array(
			'ruta con ..'          => self::craft( array( $ok, array( '../fuera.txt', 0, 1, md5( 'x' ), 'x' ) ) ),
			'ruta absoluta'        => self::craft( array( array( '/etc/x', 0, 1, md5( 'x' ), 'x' ) ) ),
			'bytes de menos'       => substr( self::craft( array( $ok ) ), 0, -1 ),
			'bytes de más'         => self::craft( array( $ok ), 'extra' ),
			'cabecera falsa'       => 'PK' . self::craft( array( $ok ) ),
			'tramo mayor que size' => self::craft( array( array( 'x.txt', 0, 1, md5( 'xx' ), 'xx' ) ) ),
			'md5 no hexadecimal'   => self::craft( array( array( 'x.txt', 0, 1, 'zzz', 'x' ) ) ),
		);
		foreach ( $bad as $case => $package ) {
			try {
				Package::extract( $package, new PathGuard( "$this->tmp/dst" ), true );
				$this->fail( 'Debió rechazar: ' . $case );
			} catch ( \RuntimeException $e ) {
				$this->assertFileDoesNotExist( "$this->tmp/dst/ok.txt", $case );
			}
		}
	}

	public function test_unsafe_entries_are_skipped(): void {
		mkdir( "$this->tmp/afuera" );
		symlink( "$this->tmp/afuera", "$this->tmp/dst/enlace" );
		$result = Package::extract(
			self::craft(
				array(
					array( 'shell.php', 0, 5, md5( '<?php' ), '<?php' ),
					array( 'img/foto.php.jpg', 0, 1, md5( 'x' ), 'x' ),
					array( '.htaccess', 0, 1, md5( 'x' ), 'x' ),
					array( 'enlace/robado.txt', 0, 1, md5( 'x' ), 'x' ),
					array( 'falso.txt', 0, 5, md5( 'otra cosa' ), 'hola!' ),
					array( 'bien.txt', 0, 4, md5( 'bien' ), 'bien' ),
				)
			),
			new PathGuard( "$this->tmp/dst" ),
			true
		);
		$this->assertSame( array( 'bien.txt' ), $result['done'] );
		$this->assertSame( array( 'shell.php', 'img/foto.php.jpg', '.htaccess', 'enlace/robado.txt', 'falso.txt' ), array_keys( $result['failed'] ) );
		$this->assertSame( array(), glob( "$this->tmp/afuera/*" ), 'nada se escribió por el symlink' );
		$this->assertFileDoesNotExist( "$this->tmp/dst/shell.php" );
		$this->assertFileDoesNotExist( "$this->tmp/dst/falso.txt" );

		// En temas y plugins (media = false) el PHP sí se acepta.
		$code = Package::extract( self::craft( array( array( 'mi-plugin/mi-plugin.php', 0, 5, md5( '<?php' ), '<?php' ) ) ), new PathGuard( "$this->tmp/dst" ), false );
		$this->assertSame( array( 'mi-plugin/mi-plugin.php' ), $code['done'] );
	}
}

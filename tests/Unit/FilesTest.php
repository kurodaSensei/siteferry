<?php
/**
 * Tests for paths, scanning and file comparison.
 *
 * @package Siteferry
 */

namespace Siteferry\Tests\Unit;

use Siteferry\Engine\Budget;
use Siteferry\Files\Diff;
use Siteferry\Files\Manifest;
use Siteferry\Files\PathGuard;
use Siteferry\Files\Scanner;
use PHPUnit\Framework\TestCase;

final class FilesTest extends TestCase {

	private string $tmp;

	protected function setUp(): void {
		$this->tmp = sys_get_temp_dir() . '/siteferry-files-' . bin2hex( random_bytes( 4 ) );
		mkdir( $this->tmp );
	}

	protected function tearDown(): void {
		$it = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $this->tmp, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $it as $f ) {
			( $f->isDir() && ! $f->isLink() ) ? rmdir( $f->getPathname() ) : unlink( $f->getPathname() );
		}
		rmdir( $this->tmp );
	}

	private function put( string $root, string $relative, string $content ): void {
		$path = "$this->tmp/$root/$relative";
		if ( ! is_dir( dirname( $path ) ) ) {
			mkdir( dirname( $path ), 0777, true );
		}
		file_put_contents( $path, $content );
	}

	private function manifest( string $name ): Manifest {
		return new Manifest( "$this->tmp/$name.jsonl" );
	}

	/** @return array<string, array{int, string}> */
	private function read( Manifest $m ): array {
		$out = array();
		foreach ( $m->entries() as [ $file, $size, $md5 ] ) {
			$out[ $file ] = array( $size, $md5 );
		}
		ksort( $out );
		return $out;
	}

	public function test_path_guard_rejects_escapes(): void {
		$this->put( 'up', '2024/05/foto.jpg', 'x' );
		mkdir( "$this->tmp/secret" );
		symlink( "$this->tmp/secret", "$this->tmp/up/enlace" );
		$guard = new PathGuard( "$this->tmp/up" );

		$this->assertSame( realpath( "$this->tmp/up" ) . '/2024/05/foto.jpg', $guard->resolve( '2024/05/foto.jpg' ) );
		$this->assertNotNull( $guard->resolve( '2025/nueva/carpeta/archivo.png' ), 'paths that do not exist yet' );
		foreach ( array( '', '../secret/x', 'a/../../x', '/etc/passwd', 'a//b', './a', "a\0b", 'a\\..\\b', 'C:/x', 'enlace/x.txt', 'enlace' ) as $bad ) {
			$this->assertNull( $guard->resolve( $bad ), var_export( $bad, true ) );
		}
	}

	public function test_executables_are_detected(): void {
		foreach ( array( 'x.php', 'a/b/shell.PHP', 'x.php5', 'x.phtml', 'x.phar', 'foto.php.jpg', '.htaccess', 'dir/.user.ini', 'x.pht' ) as $name ) {
			$this->assertTrue( PathGuard::executable( $name ), $name );
		}
		foreach ( array( 'foto.jpg', 'documento.pdf', 'php-logo.png', 'estilos.css', 'video.mp4', 'phpinfo.txt' ) as $name ) {
			$this->assertFalse( PathGuard::executable( $name ), $name );
		}
	}

	public function test_resumable_scan_matches_a_full_scan(): void {
		foreach ( array( '2024/01/a.jpg' => 'aaa', '2024/01/b.jpg' => 'bb', '2024/02/c.pdf' => 'c', 'raiz.txt' => 'r', 'cache/x.html' => 'no', '2024/02/d.jpg' . Scanner::PART => 'parcial', 'shell.php' => '<?php', '2024/.htaccess' => 'deny' ) as $file => $content ) {
			$this->put( 'up', $file, $content );
		}
		symlink( '/etc', "$this->tmp/up/afuera" );
		$scanner = new Scanner( new PathGuard( "$this->tmp/up" ), array( 'cache' ), true );

		$full = $this->manifest( 'full' );
		$this->assertNull( $scanner->scan( null, $full, new Budget( 60 ) )['cursor'] );

		$step   = $this->manifest( 'step' );
		$cursor = null;
		$calls  = 0;
		do {
			$cursor = $scanner->scan( $cursor, $step, new Budget( 0 ) )['cursor'];
			$cursor = $cursor ? json_decode( json_encode( $cursor ), true ) : null; // As when saving it in the job.
			++$calls;
		} while ( $cursor );

		$this->assertGreaterThan( 5, $calls );
		$this->assertSame( $this->read( $full ), $this->read( $step ) );
		$this->assertSame(
			array(
				'2024/01/a.jpg' => array( 3, md5( 'aaa' ) ),
				'2024/01/b.jpg' => array( 2, md5( 'bb' ) ),
				'2024/02/c.pdf' => array( 1, md5( 'c' ) ),
				'raiz.txt'      => array( 1, md5( 'r' ) ),
			),
			$this->read( $full ),
			'no cache, partial files, executables, .htaccess or symlinks'
		);
	}

	public function test_code_roots_keep_php_files(): void {
		$this->put( 'plugins', 'mi-plugin/mi-plugin.php', '<?php // plugin' );
		$this->put( 'plugins', 'mi-plugin/node_modules/x.js', 'x' );
		$out = $this->manifest( 'code' );
		( new Scanner( new PathGuard( "$this->tmp/plugins" ), array( 'node_modules' ) ) )->scan( null, $out, new Budget( 60 ) );
		$this->assertSame( array( 'mi-plugin/mi-plugin.php' ), array_keys( $this->read( $out ) ) );
	}

	public function test_diff_finds_new_changed_and_deleted_files(): void {
		$source = $this->manifest( 'source' );
		$target = $this->manifest( 'target' );
		$source->add( 'igual.jpg', 3, md5( 'abc' ) );
		$source->add( 'nuevo.jpg', 5, md5( 'nuevo' ) );
		$source->add( 'cambiado.jpg', 3, md5( 'xyz' ) ); // Same size, different content.
		$target->add( 'igual.jpg', 3, md5( 'abc' ) );
		$target->add( 'cambiado.jpg', 3, md5( 'abc' ) );
		$target->add( 'sobra.jpg', 4, md5( 'sobr' ) );
		$source->close();
		$target->close();

		$send   = $this->manifest( 'send' );
		$delete = $this->manifest( 'delete' );
		$stats  = Diff::compute( $source, $target, $send, $delete );

		$this->assertSame(
			array(
				'send'          => 2,
				'bytes'         => 8,
				'delete'        => 1,
				'new'           => 1,
				'new_bytes'     => 5,
				'changed'       => 1,
				'changed_bytes' => 3,
				'extra'         => 1,
				'extra_bytes'   => 4,
			),
			$stats
		);
		$this->assertSame( array( 'cambiado.jpg', 'nuevo.jpg' ), array_keys( $this->read( $send ) ) );
		$this->assertSame( array( 'sobra.jpg' ), array_keys( $this->read( $delete ) ) );
	}

	public function test_diff_can_send_only_new_files(): void {
		$source = $this->manifest( 'source' );
		$target = $this->manifest( 'target' );
		$source->add( 'nuevo.jpg', 5, md5( 'nuevo' ) );
		$source->add( 'cambiado.jpg', 3, md5( 'xyz' ) );
		$target->add( 'cambiado.jpg', 3, md5( 'abc' ) );
		$target->add( 'sobra.jpg', 4, md5( 'sobr' ) );
		$source->close();
		$target->close();

		$send  = $this->manifest( 'send' );
		$stats = Diff::compute( $source, $target, $send, null, true );

		$this->assertSame( array( 'nuevo.jpg' ), array_keys( $this->read( $send ) ), 'a file that exists here with other content is left alone' );
		$this->assertSame( array( 1, 5, 0 ), array( $stats['send'], $stats['bytes'], $stats['delete'] ) );
		$this->assertSame( array( 1, 1, 1 ), array( $stats['new'], $stats['changed'], $stats['extra'] ), 'the comparison is counted in full either way' );
	}
}

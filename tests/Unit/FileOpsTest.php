<?php
/**
 * Pull y push de archivos entre dos "sitios" (carpetas), con FileOps del lado remoto.
 *
 * @package Siteferry
 */

namespace Siteferry\Tests\Unit;

use Siteferry\Engine\Budget;
use Siteferry\Files\Applier;
use Siteferry\Files\Diff;
use Siteferry\Files\Manifest;
use Siteferry\Files\Package;
use Siteferry\Files\PathGuard;
use Siteferry\Files\Roots;
use Siteferry\Files\Scanner;
use Siteferry\Remote\FileOps;
use PHPUnit\Framework\TestCase;

final class FileOpsTest extends TestCase {

	private string $tmp;

	protected function setUp(): void {
		$this->tmp = sys_get_temp_dir() . '/siteferry-ops-' . bin2hex( random_bytes( 4 ) );
		foreach ( array( 'a', 'b' ) as $site ) {
			foreach ( array( 'uploads', 'plugins', 'themes', 'storage' ) as $dir ) {
				mkdir( "$this->tmp/$site/$dir", 0777, true );
			}
		}
	}

	protected function tearDown(): void {
		Applier::remove_tree( $this->tmp );
	}

	private function put( string $site, string $relative, string $content ): void {
		$path = "$this->tmp/$site/$relative";
		if ( ! is_dir( dirname( $path ) ) ) {
			mkdir( dirname( $path ), 0777, true );
		}
		file_put_contents( $path, $content );
	}

	private function roots( string $site ): array {
		return array(
			'uploads' => "$this->tmp/$site/uploads",
			'plugins' => "$this->tmp/$site/plugins",
			'themes'  => "$this->tmp/$site/themes",
		);
	}

	/** El sitio "b" visto por la red: argumentos por JSON, binario aparte. */
	private function remote( array $perms ): \Closure {
		$ops = new FileOps( $this->roots( 'b' ), "$this->tmp/b/storage", $perms );
		return static function ( string $op, array $args, ?string $binary = null ) use ( $ops ): array {
			return $ops->handle( $op, json_decode( json_encode( $args ), true ), $binary );
		};
	}

	private function manifest(): Manifest {
		return new Manifest( "$this->tmp/m-" . bin2hex( random_bytes( 4 ) ) . '.jsonl' );
	}

	private function scan_local( string $site, string $root ): Manifest {
		$m = $this->manifest();
		( new Scanner( new PathGuard( $this->roots( $site )[ $root ] ), Roots::exclude( $root ), ! Roots::is_code( $root ) ) )->scan( null, $m, new Budget( 60 ) );
		return $m;
	}

	private function scan_remote( \Closure $remote, string $root ): Manifest {
		$m      = $this->manifest();
		$cursor = null;
		do {
			$res    = $remote( 'files_scan', array( 'root' => $root, 'cursor' => $cursor ) );
			$cursor = $res['cursor'];
			foreach ( FileOps::unpack( $res['entries'] ) as [ $f, $s, $h ] ) {
				$m->add( $f, $s, $h );
			}
		} while ( $cursor );
		$m->close();
		return $m;
	}

	/** @return array<string, string> ruta => md5 */
	private function tree( string $dir ): array {
		$out = array();
		$it  = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $f ) {
			$out[ substr( $f->getPathname(), strlen( $dir ) + 1 ) ] = md5_file( $f->getPathname() );
		}
		ksort( $out );
		return $out;
	}

	private function pull( \Closure $remote, string $root, bool $delete ): array {
		$send    = $this->manifest();
		$deletes = $this->manifest();
		Diff::compute( $this->scan_remote( $remote, $root ), $this->scan_local( 'a', $root ), $send, $delete ? $deletes : null );
		$local  = new Applier( $this->roots( 'a' ), "$this->tmp/a/storage/files-pull" );
		$pos    = 0;
		$file   = array( 'offset' => 0, 'size' => 0, 'md5' => '' );
		$failed = array();
		while ( true ) {
			$entries = array();
			$ends    = array();
			foreach ( $send->read_from( $pos ) as [ $f, $s, $h, $after ] ) {
				$entries[] = array( $f, $s, $h );
				$ends[]    = $after;
				if ( count( $entries ) >= 7 ) {
					break;
				}
			}
			if ( ! $entries ) {
				break;
			}
			$res    = $remote( 'files_get', array( 'root' => $root, 'entries' => FileOps::pack( $entries ), 'max_bytes' => 64 * 1024 ) + $file );
			$got    = $local->receive( $root, $res['package'] );
			$failed = array_merge( $failed, $got['failed'] );
			$pos    = $res['consumed'] > 0 ? $ends[ $res['consumed'] - 1 ] : $pos;
			$file   = array( 'offset' => $res['offset'], 'size' => $res['size'], 'md5' => $res['md5'] );
		}
		$stats = $local->finalize( $root, array_column( iterator_to_array( $deletes->entries(), false ), 0 ) );
		return array( 'failed' => $failed ) + $stats;
	}

	private function push( \Closure $remote, string $root, bool $delete ): array {
		$send    = $this->manifest();
		$deletes = $this->manifest();
		Diff::compute( $this->scan_local( 'a', $root ), $this->scan_remote( $remote, $root ), $send, $delete ? $deletes : null );
		$cursor = null;
		$failed = array();
		do {
			$built  = Package::build( $send, new PathGuard( $this->roots( 'a' )[ $root ] ), $cursor, 64 * 1024 );
			$cursor = $built['cursor'];
			$got    = $remote( 'files_put', array( 'root' => $root, 'session' => 'abcdef0123456789' ), $built['package'] );
			$failed = array_merge( $failed, $got['failed'] );
		} while ( $cursor );
		$stats = $remote( 'files_finalize', array( 'root' => $root, 'session' => 'abcdef0123456789', 'deletions' => FileOps::pack( array_column( iterator_to_array( $deletes->entries(), false ), 0 ) ) ) );
		return array( 'failed' => $failed ) + $stats;
	}

	public function test_pull_uploads_incrementally(): void {
		$this->put( 'b', 'uploads/2024/05/foto.jpg', 'foto nueva' );
		$this->put( 'b', 'uploads/2024/05/igual.jpg', 'igual' );
		$this->put( 'b', 'uploads/2024/06/cambiada.jpg', 'AAAA' );
		$this->put( 'b', 'uploads/video.mp4', random_bytes( 1536 * 1024 ) );
		$this->put( 'b', 'uploads/siteferry-xyz/backup.sql.gz', 'no viaja' );
		$this->put( 'a', 'uploads/2024/05/igual.jpg', 'igual' );
		$this->put( 'a', 'uploads/2024/06/cambiada.jpg', 'BBBB' ); // Mismo tamaño, otro contenido.
		$this->put( 'a', 'uploads/sobra.jpg', 'solo en a' );

		$stats = $this->pull( $this->remote( array( 'allow_pull_files' => true ) ), 'uploads', true );

		$this->assertSame( array(), $stats['failed'] );
		$this->assertSame( 1, $stats['deleted'] );
		$expected = $this->tree( "$this->tmp/b/uploads" );
		unset( $expected['siteferry-xyz/backup.sql.gz'] );
		$this->assertSame( $expected, $this->tree( "$this->tmp/a/uploads" ) );
		$this->assertSame( 'BBBB', file_get_contents( "$this->tmp/a/storage/files-pull/backup/uploads/2024/06/cambiada.jpg" ), 'lo reemplazado queda en backup' );
		$this->assertSame( 'solo en a', file_get_contents( "$this->tmp/a/storage/files-pull/backup/uploads/sobra.jpg" ) );
	}

	public function test_push_replaces_whole_plugin_folders(): void {
		$this->put( 'a', 'plugins/mi-plugin/mi-plugin.php', '<?php // v2' );
		$this->put( 'a', 'plugins/mi-plugin/inc/nuevo.php', '<?php // nuevo' );
		$this->put( 'a', 'plugins/hello.php', '<?php // hola v2' );
		$this->put( 'a', 'plugins/siteferry/siteferry.php', '<?php // el de a' );
		$this->put( 'b', 'plugins/mi-plugin/mi-plugin.php', '<?php // v1' );
		$this->put( 'b', 'plugins/mi-plugin/inc/viejo.php', '<?php // se elimina en v2' );
		$this->put( 'b', 'plugins/otro/otro.php', '<?php // no se toca' );
		$this->put( 'b', 'plugins/hello.php', '<?php // hola v1' );
		$this->put( 'b', 'plugins/siteferry/siteferry.php', '<?php // el de b' );

		$stats = $this->push( $this->remote( array( 'allow_push' => true, 'allow_push_code' => true ) ), 'plugins', true );

		$this->assertSame( array(), $stats['failed'] );
		$this->assertSame( '<?php // v2', file_get_contents( "$this->tmp/b/plugins/mi-plugin/mi-plugin.php" ) );
		$this->assertFileExists( "$this->tmp/b/plugins/mi-plugin/inc/nuevo.php" );
		$this->assertFileDoesNotExist( "$this->tmp/b/plugins/mi-plugin/inc/viejo.php" );
		$this->assertSame( '<?php // hola v2', file_get_contents( "$this->tmp/b/plugins/hello.php" ) );
		$this->assertSame( '<?php // el de b', file_get_contents( "$this->tmp/b/plugins/siteferry/siteferry.php" ), 'nunca se reemplaza a sí mismo' );
		$this->assertSame( '<?php // v1', file_get_contents( "$this->tmp/b/storage/files-abcdef0123456789/backup/plugins/mi-plugin/mi-plugin.php" ) );
		$this->assertSame( array(), glob( "$this->tmp/b/storage/files-abcdef0123456789/{stage,new}/plugins/*", GLOB_BRACE ), 'sin restos de trabajo' );
		// "otro" no estaba en el origen: con delete se va a backup; sin delete se habría quedado.
		$this->assertFileDoesNotExist( "$this->tmp/b/plugins/otro/otro.php" );
	}

	public function test_code_push_needs_its_own_permission(): void {
		$this->put( 'a', 'themes/tema/style.css', '/* tema */' );
		$this->put( 'a', 'uploads/foto.jpg', 'foto' );
		$remote = $this->remote( array( 'allow_push' => true ) );
		try {
			$this->push( $remote, 'themes', false );
			$this->fail( 'Debió pedir el permiso de código.' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'push de código', $e->getMessage() );
		}
		$this->assertSame( array(), $this->push( $remote, 'uploads', false )['failed'], 'media sí se puede' );
		$this->assertFileExists( "$this->tmp/b/uploads/foto.jpg" );

		$closed = $this->remote( array() );
		foreach ( array( 'files_scan', 'files_get', 'files_put' ) as $op ) {
			try {
				$closed( $op, array( 'root' => 'uploads', 'session' => 'abcdef0123456789' ), '' );
				$this->fail( 'Debió rechazar ' . $op );
			} catch ( \RuntimeException $e ) {
				$this->assertStringContainsString( 'no permite', $e->getMessage() );
			}
		}
	}

	public function test_peer_cursor_cannot_escape_or_reach_excluded_folders(): void {
		$this->put( 'b', 'afuera/secreto.txt', 'secreto' );
		symlink( "$this->tmp/b/afuera", "$this->tmp/b/uploads/enlace" );
		$this->put( 'b', 'uploads/updraft/backup-db.zip', 'wp-config y más' );
		$this->put( 'b', 'uploads/2024/foto.jpg', 'foto' );
		$remote = $this->remote( array( 'allow_pull_files' => true ) );
		foreach ( array( array( 'enlace' ), array( 'updraft' ), array( '../afuera' ), array( '/etc' ), array( 'updraft/../2024' ), array( 7 ) ) as $queue ) {
			$res = $remote( 'files_scan', array( 'root' => 'uploads', 'cursor' => array( 'queue' => $queue, 'pos' => 0 ) ) );
			$this->assertSame( array(), FileOps::unpack( $res['entries'] ), json_encode( $queue ) );
		}
		$all = FileOps::unpack( $remote( 'files_scan', array( 'root' => 'uploads', 'cursor' => null ) )['entries'] );
		$this->assertSame( array( '2024/foto.jpg' ), array_column( $all, 0 ) );
	}

	public function test_get_never_serves_excluded_or_executable_files(): void {
		$this->put( 'b', 'uploads/updraft/backup-db.zip', 'wp-config y más' );
		$this->put( 'b', 'uploads/shell.php', '<?php' );
		$this->put( 'b', 'uploads/.htaccess', 'deny' );
		$remote  = $this->remote( array( 'allow_pull_files' => true ) );
		$entries = array( array( 'updraft/backup-db.zip', 15, md5( 'x' ) ), array( 'shell.php', 5, md5( 'x' ) ), array( '.htaccess', 4, md5( 'x' ) ) );
		$res     = $remote( 'files_get', array( 'root' => 'uploads', 'entries' => FileOps::pack( $entries ) ) );
		$this->assertSame( array( 'updraft/backup-db.zip', 'shell.php', '.htaccess' ), $res['missing'] );
		$this->assertSame( 0, $res['files'] );
		$this->assertStringNotContainsString( 'wp-config', $res['package'] );
	}

	public function test_push_never_deletes_or_replaces_protected_files(): void {
		$this->put( 'b', 'uploads/.htaccess', 'php_flag engine off' );
		$this->put( 'b', 'uploads/siteferry-x/.htaccess', 'Require all denied' );
		$this->put( 'b', 'uploads/foto.jpg', 'se puede borrar' );
		$this->put( 'b', 'plugins/siteferry/siteferry.php', '<?php // original' );
		$remote = $this->remote( array( 'allow_push' => true, 'allow_push_code' => true ) );

		$remote( 'files_finalize', array( 'root' => 'uploads', 'session' => 'abcdef0123456789', 'deletions' => FileOps::pack( array( '.htaccess', 'siteferry-x/.htaccess', 'foto.jpg' ) ) ) );
		$this->assertFileExists( "$this->tmp/b/uploads/.htaccess" );
		$this->assertFileExists( "$this->tmp/b/uploads/siteferry-x/.htaccess" );
		$this->assertFileDoesNotExist( "$this->tmp/b/uploads/foto.jpg" );

		$evil = '<?php // reemplazado';
		$pkg  = 'SFYPKG1 ' . json_encode( array( 'files' => array( array( 'path' => 'siteferry/siteferry.php', 'offset' => 0, 'length' => strlen( $evil ), 'size' => strlen( $evil ), 'md5' => md5( $evil ) ) ) ) ) . "\n" . $evil;
		$got  = $remote( 'files_put', array( 'root' => 'plugins', 'session' => 'abcdef0123456789' ), $pkg );
		$this->assertSame( array( 'siteferry/siteferry.php' => 'ruta excluida' ), $got['failed'] );
		$remote( 'files_finalize', array( 'root' => 'plugins', 'session' => 'abcdef0123456789', 'deletions' => FileOps::pack( array( 'siteferry/siteferry.php' ) ) ) );
		$this->assertSame( '<?php // original', file_get_contents( "$this->tmp/b/plugins/siteferry/siteferry.php" ) );
	}

	public function test_exclusions_ignore_letter_case(): void {
		$this->assertTrue( Roots::matches( Roots::exclude( 'plugins' ), 'SiteFerry/siteferry.php' ) );
		$this->assertTrue( Roots::matches( Roots::exclude( 'uploads' ), 'UpDraft/backup-db.zip' ) );
		$this->assertTrue( Roots::matches( Roots::exclude( 'uploads' ), 'SiteFerry-abc/dump.sql.gz' ) );
		$this->assertTrue( Roots::matches( Roots::exclude( 'uploads' ), '2024/.ds_store' ) );
		$this->assertFalse( Roots::matches( Roots::exclude( 'uploads' ), '2024/foto-updraft.jpg' ) );

		$this->put( 'b', 'uploads/updraft/backup-db.zip', 'wp-config y más' );
		$res = $this->remote( array( 'allow_pull_files' => true ) )( 'files_get', array( 'root' => 'uploads', 'entries' => FileOps::pack( array( array( 'UPDRAFT/backup-db.zip', 15, md5( 'x' ) ) ) ) ) );
		$this->assertSame( array( 'UPDRAFT/backup-db.zip' ), $res['missing'] );
		$this->assertStringNotContainsString( 'wp-config', $res['package'] );
	}

	public function test_reading_files_needs_its_own_permission(): void {
		$remote = $this->remote( array( 'allow_pull' => true ) ); // Solo base de datos.
		foreach ( array( 'files_scan', 'files_get' ) as $op ) {
			try {
				$remote( $op, array( 'root' => 'uploads' ) );
				$this->fail( 'Debió rechazar ' . $op );
			} catch ( \RuntimeException $e ) {
				$this->assertStringContainsString( 'pull de archivos', $e->getMessage() );
			}
		}
	}

	public function test_compression_bombs_are_refused(): void {
		$bomb = base64_encode( gzcompress( '[' . str_repeat( '"aaaaaaaaaaaaaaaaaaaa",', 1000000 ) . '"a"]', 9 ) ); // ~22 MB al descomprimir.
		$this->assertLessThan( 200 * 1024, strlen( $bomb ) );
		$this->expectExceptionMessage( 'demasiado grande' );
		FileOps::unpack( $bomb );
	}

	public function test_remote_refuses_unsafe_requests(): void {
		$this->put( 'b', 'wp-config.php', '<?php define("DB_PASSWORD", "secreto");' );
		$remote = $this->remote( array( 'allow_pull_files' => true, 'allow_push' => true ) );

		$res = $remote( 'files_get', array( 'root' => 'uploads', 'entries' => FileOps::pack( array( array( '../wp-config.php', 1, md5( 'x' ) ) ) ) ) );
		$this->assertSame( array( '../wp-config.php' ), $res['missing'] );
		$this->assertStringNotContainsString( 'secreto', $res['package'] );

		$evil = 'SFYPKG1 ' . json_encode( array( 'files' => array( array( 'path' => 'shell.php', 'offset' => 0, 'length' => 5, 'size' => 5, 'md5' => md5( '<?php' ) ) ) ) ) . "\n<?php";
		$got  = $remote( 'files_put', array( 'root' => 'uploads', 'session' => 'abcdef0123456789' ), $evil );
		$this->assertArrayHasKey( 'shell.php', $got['failed'] );
		$this->assertFileDoesNotExist( "$this->tmp/b/uploads/shell.php" );

		foreach ( array( array( 'root' => 'uploads', 'session' => '../../x' ), array( 'root' => 'wp-admin', 'session' => 'abcdef0123456789' ) ) as $args ) {
			try {
				$remote( 'files_put', $args, $evil );
				$this->fail( 'Debió rechazar ' . json_encode( $args ) );
			} catch ( \RuntimeException $e ) {
				$this->assertMatchesRegularExpression( '/inválida|no disponible/', $e->getMessage() );
			}
		}
	}
}

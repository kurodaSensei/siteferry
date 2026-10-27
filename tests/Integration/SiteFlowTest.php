<?php
/**
 * Paquete de sitio completo (.sfy) y deshacer.
 *
 * @package Siteferry
 */

namespace Siteferry\Tests\Integration;

use Siteferry\Engine\Budget;
use Siteferry\Engine\Schema;
use Siteferry\Job\Planner;
use Siteferry\Job\Runner;
use Siteferry\Support\Db;

final class SiteFlowTest extends DbTestCase {

	private const OLD = 'http://old.test';
	private const NEW = 'https://new.example';

	private function seeded( string $prefix, string $home ): Db {
		[ $db ] = $this->database();
		$sql    = str_replace( '__P__', $prefix, (string) file_get_contents( dirname( __DIR__ ) . '/fixtures/seed-wp.sql' ) );
		$db->in_migration_session( fn() => $this->exec( $db, $sql ) );
		$db->query( "UPDATE {$prefix}options SET option_value = '$home' WHERE option_name IN ('home', 'siteurl')" );
		return $db;
	}

	/**
	 * Sitio con carpetas propias bajo $this->storage/$name.
	 */
	private function files_site( string $prefix, string $home, string $name ): array {
		$base = "$this->storage/$name";
		foreach ( array( 'uploads', 'plugins', 'themes', 'storage' ) as $dir ) {
			if ( ! is_dir( "$base/$dir" ) ) {
				mkdir( "$base/$dir", 0777, true );
			}
		}
		$site            = $this->site( $prefix, $home );
		$site['storage'] = "$base/storage";
		$site['roots']   = array(
			'uploads' => "$base/uploads",
			'plugins' => "$base/plugins",
			'themes'  => "$base/themes",
		);
		return $site;
	}

	private function put( string $relative, string $content ): void {
		$path = "$this->storage/$relative";
		if ( ! is_dir( dirname( $path ) ) ) {
			mkdir( dirname( $path ), 0777, true );
		}
		file_put_contents( $path, $content );
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

	/**
	 * Corre el job; con $repeat, los pasos de paquete y archivos se ejecutan dos veces desde el mismo
	 * estado guardado (una petición que se cortó antes de guardar el job y se repite).
	 */
	private function run_job( Db $db, array $job, bool $repeat = false ): array {
		$runner = new Runner( $db );
		for ( $i = 0; $i < 5000 && Runner::is_open( $job ); $i++ ) {
			if ( $repeat && in_array( $job['steps'][ $job['index'] ]['type'] ?? '', array( 'site_start', 'site_db', 'site_files', 'site_unpack', 'files_finalize', 'files_undo' ), true ) ) {
				$runner->step( $job, new Budget( 0 ) );
			}
			$job = $runner->step( $job, new Budget( 0 ) );
		}
		$this->assertSame( 'done', $job['status'], (string) $job['error'] . "\n" . implode( "\n", $job['log'] ) . "\nPaso: " . json_encode( $job['steps'][ $job['index'] ] ?? null ) . " cursor " . json_encode( $job['cursor'] ) );
		return $job;
	}

	/** Lo que UndoPoint::from_job guardaría tras el job. */
	private function undo_point( array $job ): array {
		return array(
			'db_backup' => $job['files']['backup'] ?? null,
			'files_dir' => $job['site']['storage'] . '/files-' . $job['id'],
			'roots'     => array_keys( $job['file_stats'] ),
		);
	}

	/** Estado comparable de la base. */
	private function snapshot( Db $db, string $prefix ): array {
		// Opciones por nombre: las siteferry_* se reinsertan (otro option_id) y el plugin queda siempre activo.
		$out = array( 'options' => $db->rows( "SELECT option_name, option_value FROM {$prefix}options WHERE option_name <> 'active_plugins' ORDER BY option_name" ) );
		foreach ( array( 'posts', 'usermeta', 'child', 'parent' ) as $table ) {
			$out[ $table ] = $this->checksum( $db, $prefix . $table );
		}
		return $out;
	}

	public function test_site_package_moves_database_and_files_and_undo_restores_everything(): void {
		$a = $this->seeded( 'wpa_', self::OLD );
		$b = $this->seeded( 'wpb_', self::NEW );
		$this->put( 'a/uploads/2024/05/foto.jpg', 'foto A' );
		$this->put( 'a/uploads/video.mp4', random_bytes( 9 * 1024 * 1024 + 7 ) ); // Más de un bloque de 8 MB.
		$this->put( 'a/plugins/mi-plugin/mi-plugin.php', '<?php // v2' );
		$this->put( 'a/plugins/nuevo/nuevo.php', '<?php // solo en A' );
		$this->put( 'b/uploads/2024/05/foto.jpg', 'foto B' );
		$this->put( 'b/uploads/solo-b.jpg', 'se queda' );
		$this->put( 'b/plugins/mi-plugin/mi-plugin.php', '<?php // v1' );
		$this->put( 'b/plugins/mi-plugin/viejo.php', '<?php // de v1' );
		$site_b       = $this->files_site( 'wpb_', self::NEW, 'b' );
		$before_db    = $this->snapshot( $b, 'wpb_' );
		$before_files = array(
			'uploads' => $this->tree( "$this->storage/b/uploads" ),
			'plugins' => $this->tree( "$this->storage/b/plugins" ),
		);

		$export = $this->run_job(
			$a,
			Planner::create( 'export', array( 'files' => array( 'uploads', 'plugins', 'themes', 'mu-plugins' ) ), $this->files_site( 'wpa_', self::OLD, 'a' ), new Schema( $a ) ),
			true
		);
		$this->assertStringEndsWith( '.sfy', $export['files']['export'] );
		$this->assertEqualsCanonicalizing( array( 'uploads', 'plugins', 'themes' ), array_keys( $export['file_stats'] ), 'mu-plugins no existe en el sitio' );

		$import = $this->run_job(
			$b,
			Planner::create(
				'import',
				array(
					'file'     => $export['files']['export'],
					'backup'   => true,
					'auto_url' => true,
				),
				$site_b,
				new Schema( $b )
			),
			true
		);

		$this->assertSame( self::NEW, $b->value( "SELECT option_value FROM wpb_options WHERE option_name = 'home'" ) );
		$this->assertStringContainsString( 'https:\/\/new.example\/a.jpg', (string) $b->value( 'SELECT post_content FROM wpb_posts WHERE ID = 1' ) );
		$this->assertSame( array(), ( new Schema( $b ) )->temp_tables() );
		$this->assertSame( $this->tree( "$this->storage/a/plugins" ), $this->tree( "$this->storage/b/plugins" ), 'el código se reemplaza por carpeta' );
		$this->assertSame( 'foto A', file_get_contents( "$this->storage/b/uploads/2024/05/foto.jpg" ) );
		$this->assertSame( md5_file( "$this->storage/a/uploads/video.mp4" ), md5_file( "$this->storage/b/uploads/video.mp4" ) );
		$this->assertSame( 'se queda', file_get_contents( "$this->storage/b/uploads/solo-b.jpg" ), 'importar no borra media' );

		$undo = Planner::create( 'undo', $this->undo_point( $import ), $site_b, new Schema( $b ) );
		$this->run_job( $b, $undo, true );

		$this->assertSame( $before_db, $this->snapshot( $b, 'wpb_' ) );
		$this->assertSame( $before_files['uploads'], $this->tree( "$this->storage/b/uploads" ) );
		$this->assertSame( $before_files['plugins'], $this->tree( "$this->storage/b/plugins" ) );
	}

	public function test_undo_a_replace(): void {
		$a      = $this->seeded( 'wpa_', self::OLD );
		$site   = $this->files_site( 'wpa_', self::OLD, 'a' );
		$before = $this->snapshot( $a, 'wpa_' );
		$job    = $this->run_job(
			$a,
			Planner::create(
				'replace',
				array(
					'pairs'  => array( array( self::OLD, self::NEW ) ),
					'backup' => true,
				),
				$site,
				new Schema( $a )
			)
		);
		$this->assertNotSame( $before, $this->snapshot( $a, 'wpa_' ) );

		$this->run_job( $a, Planner::create( 'undo', $this->undo_point( $job ), $site, new Schema( $a ) ) );
		$this->assertSame( $before, $this->snapshot( $a, 'wpa_' ) );
	}

	public function test_undo_only_accepts_paths_inside_storage(): void {
		$a = $this->seeded( 'wpa_', self::OLD );
		$this->expectException( \InvalidArgumentException::class );
		Planner::create(
			'undo',
			array(
				'db_backup' => dirname( __DIR__ ) . '/fixtures/seed-wp.sql',
				'files_dir' => $this->storage,
				'roots'     => array( 'uploads' ),
			),
			$this->files_site( 'wpa_', self::OLD, 'a' ),
			new Schema( $a )
		);
	}
}

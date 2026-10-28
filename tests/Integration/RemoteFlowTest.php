<?php
/**
 * Pull and push between two databases, with the real Server on the remote side.
 *
 * @package Siteferry
 */

namespace Siteferry\Tests\Integration;

use Siteferry\Engine\Budget;
use Siteferry\Engine\MemorySink;
use Siteferry\Engine\Schema;
use Siteferry\Job\Planner;
use Siteferry\Job\Runner;
use Siteferry\Remote\Server;
use Siteferry\Remote\Unreachable;
use Siteferry\Support\Db;

final class RemoteFlowTest extends DbTestCase {

	private const OLD = 'http://old.test';
	private const NEW = 'https://new.example';

	/**
	 * Job on the remote side (what JobStore saves in WordPress).
	 *
	 * @var array|null
	 */
	private ?array $remote_job = null;

	/**
	 * How many times to repeat each push_chunk (simulates retries after network drops).
	 *
	 * @var int
	 */
	private int $chunk_repeats = 1;

	/**
	 * Received chunks whose confirming save the remote loses once (a request cut off right
	 * after applying the chunk, before its job was saved).
	 *
	 * @var array<int, true>|null
	 */
	private ?array $lost_saves = null;

	private function seeded( string $prefix, string $home ): Db {
		[ $db ] = $this->database();
		$sql    = str_replace( '__P__', $prefix, (string) file_get_contents( dirname( __DIR__ ) . '/fixtures/seed-wp.sql' ) );
		$db->in_migration_session( fn() => $this->exec( $db, $sql ) );
		$db->query( "UPDATE {$prefix}options SET option_value = '$home' WHERE option_name IN ('home', 'siteurl')" );
		return $db;
	}

	/** Site "B": destination or remote, with its own options that must survive. */
	private function site_b(): Db {
		$db = $this->seeded( 'wpb_', self::NEW );
		$db->query( "UPDATE wpb_options SET option_value = '0' WHERE option_name = 'blog_public'" );
		$db->query( "UPDATE wpb_options SET option_value = 'B-job' WHERE option_name = 'siteferry_job'" );
		$db->query( "INSERT INTO wpb_posts (ID, post_content) VALUES (99, 'post que debe desaparecer')" );
		return $db;
	}

	private function server( Db $db, string $prefix, string $home, array $perms ): Server {
		return new Server(
			$db,
			$this->with_files( $this->site( $prefix, $home ), 'remote' ),
			$perms,
			fn() => $this->remote_job,
			function ( array $job ): void {
				$seq = (int) ( $job['received_seq'] ?? 0 );
				if ( null !== $this->lost_saves && $seq > (int) ( $this->remote_job['received_seq'] ?? 0 ) && ! isset( $this->lost_saves[ $seq ] ) ) {
					$this->lost_saves[ $seq ] = true;
					return;
				}
				$this->remote_job = $job;
			},
			'test'
		);
	}

	/**
	 * Fake client: passes every message through JSON, as over the network.
	 */
	private function client( Server $server ): \Closure {
		return function ( array $job, string $op, array $args, ?string $binary = null ) use ( $server ): array {
			$wire  = static fn( $data ) => json_decode( json_encode( $data ), true );
			$times = in_array( $op, array( 'push_chunk', 'files_put' ), true ) ? $this->chunk_repeats : 1;
			for ( $i = 0; $i < $times; $i++ ) {
				$sent = $wire( $args );
				if ( null !== $binary ) {
					$sent['_binary'] = $binary;
				}
				$result  = $server->handle( $op, $sent );
				$package = $result['package'] ?? null;
				unset( $result['package'] );
				$result = $wire( $result );
				if ( null !== $package ) {
					$result['package'] = $package;
				}
			}
			return $result;
		};
	}

	/**
	 * Runs the job to the end. With $repeat, every step first runs once from the same saved state
	 * and that result is thrown away (a request cut off before the job was saved, then repeated).
	 */
	private function run_job( Db $db, array $job, \Closure $client, bool $repeat = false ): array {
		$runner = new Runner( $db, $client );
		for ( $i = 0; $i < 5000 && Runner::is_open( $job ); $i++ ) {
			if ( $repeat ) {
				$runner->step( $job, new Budget( 0 ) );
			}
			$job = $runner->step( $job, new Budget( 0 ) );
		}
		$this->assertSame( 'done', $job['status'], (string) $job['error'] . "\n" . implode( "\n", $job['log'] ) );
		return $job;
	}

	private function remote_input( Server $server, array $extra = array() ): array {
		return array(
			'remote'      => array(
				'url' => 'http://remoto.test',
				'key' => str_repeat( 'k', 40 ),
			),
			'remote_info' => json_decode( json_encode( $server->handle( 'hello', array() ) ), true ),
			'auto_url'    => true,
			'backup'      => true,
		) + $extra;
	}

	private function option( Db $db, string $table, string $name ): ?string {
		return $db->value( "SELECT option_value FROM `$table` WHERE option_name = " . $db->quote( $name ) );
	}

	/**
	 * What B must satisfy after receiving site A (by pull or push).
	 */
	private function assert_b_has_site_a( Db $b ): void {
		$this->assertSame( self::NEW, $this->option( $b, 'wpb_options', 'home' ) );
		$this->assertSame( '0', $this->option( $b, 'wpb_options', 'blog_public' ) );
		$this->assertSame( 'B-job', $this->option( $b, 'wpb_options', 'siteferry_job' ) );
		$this->assertContains( 'siteferry/siteferry.php', unserialize( $this->option( $b, 'wpb_options', 'active_plugins' ) ) );
		$this->assertStringContainsString( self::NEW . '/x', unserialize( $this->option( $b, 'wpb_options', 'widget_text' ) )[2]['text'] );
		$this->assertNotNull( $this->option( $b, 'wpb_options', 'wpb_user_roles' ) );
		$this->assertStringContainsString( 'https:\/\/new.example\/a.jpg', (string) $b->value( 'SELECT post_content FROM wpb_posts WHERE ID = 1' ) );
		$this->assertSame( self::OLD . '/?p=1', $b->value( 'SELECT guid FROM wpb_posts WHERE ID = 1' ) );
		$this->assertSame( 'Hola mundo 🚀', $b->value( 'SELECT post_content FROM wpb_posts WHERE ID = 2' ) );
		$this->assertNull( $b->value( 'SELECT ID FROM wpb_posts WHERE ID = 99' ) );
		$this->assertSame( '1', $b->value( "SELECT COUNT(*) FROM wpb_usermeta WHERE meta_key = 'wpb_capabilities'" ) );
		$this->assertSame( '2', $b->value( 'SELECT COUNT(*) FROM wpb_child' ) );
		$this->assertSame(
			'wpb_parent',
			$b->value( "SELECT REFERENCED_TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'wpb_child' AND REFERENCED_TABLE_NAME IS NOT NULL" )
		);
		$this->assertSame( array(), ( new Schema( $b ) )->temp_tables() );
	}

	/**
	 * Adds file folders (and a working folder of its own) to a site's data.
	 */
	private function with_files( array $site, string $name ): array {
		$base = $this->storage . '/' . $name;
		foreach ( array( 'uploads', 'plugins', 'themes', 'storage' ) as $dir ) {
			if ( ! is_dir( "$base/$dir" ) ) {
				mkdir( "$base/$dir", 0777, true );
			}
		}
		$site['storage'] = "$base/storage";
		$site['roots']   = array(
			'uploads' => "$base/uploads",
			'plugins' => "$base/plugins",
			'themes'  => "$base/themes",
		);
		return $site;
	}

	private function put( string $site, string $relative, string $content ): void {
		$path = "$this->storage/$site/$relative";
		if ( ! is_dir( dirname( $path ) ) ) {
			mkdir( dirname( $path ), 0777, true );
		}
		file_put_contents( $path, $content );
	}

	/** @return array<string, string> path => md5 */
	private function tree( string $dir ): array {
		$out = array();
		$it  = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $f ) {
			$out[ substr( $f->getPathname(), strlen( $dir ) + 1 ) ] = md5_file( $f->getPathname() );
		}
		ksort( $out );
		return $out;
	}

	public function test_pull_brings_database_and_files(): void {
		$remote = $this->seeded( 'wpa_', self::OLD );
		$local  = $this->site_b();
		$this->with_files( array(), 'remote' );
		$this->with_files( array(), 'local' );
		$this->put( 'remote', 'uploads/2024/05/foto.jpg', 'foto' );
		$this->put( 'remote', 'uploads/2024/06/cambiada.jpg', 'AAAA' );
		$this->put( 'remote', 'uploads/video.mp4', random_bytes( 1024 * 1024 + 3 ) );
		$this->put( 'remote', 'plugins/mi-plugin/mi-plugin.php', '<?php // v2' );
		$this->put( 'local', 'uploads/2024/06/cambiada.jpg', 'BBBB' );
		$this->put( 'local', 'uploads/sobra.jpg', 'solo aquí' );
		$this->put( 'local', 'plugins/mi-plugin/mi-plugin.php', '<?php // v1' );
		$this->put( 'local', 'plugins/mi-plugin/viejo.php', '<?php // se va' );

		$server = $this->server( $remote, 'wpa_', self::OLD, array( 'allow_pull' => true, 'allow_pull_files' => true ) );
		$job    = Planner::create(
			'pull',
			$this->remote_input( $server, array( 'files' => array( 'uploads', 'plugins' ), 'delete_files' => true ) ),
			$this->with_files( $this->site( 'wpb_', self::NEW ), 'local' ),
			new Schema( $local )
		);
		$job    = $this->run_job( $local, $job, $this->client( $server ) );

		$this->assert_b_has_site_a( $local );
		$this->assertSame( $this->tree( "$this->storage/remote/uploads" ), $this->tree( "$this->storage/local/uploads" ) );
		$this->assertSame( $this->tree( "$this->storage/remote/plugins" ), $this->tree( "$this->storage/local/plugins" ) );
		$backup = $job['site']['storage'] . '/files-' . $job['id'] . '/backup';
		$this->assertSame( 'BBBB', file_get_contents( "$backup/uploads/2024/06/cambiada.jpg" ) );
		$this->assertSame( 'solo aquí', file_get_contents( "$backup/uploads/sobra.jpg" ) );
		$this->assertSame( '<?php // v1', file_get_contents( "$backup/plugins/mi-plugin/mi-plugin.php" ) );
		$this->assertSame( array(), $job['warnings'] );
		$this->assertSame( 3, $job['file_stats']['uploads']['send'], 'foto, cambiada and video' );
		$this->assertSame( 3, $job['file_stats']['uploads']['sent'] );
	}

	public function test_push_files_only_including_code(): void {
		$local  = $this->seeded( 'wpa_', self::OLD );
		$remote = $this->site_b();
		$this->with_files( array(), 'remote' );
		$this->with_files( array(), 'local' );
		$this->put( 'local', 'themes/tema/style.css', '/* v2 */' );
		$this->put( 'local', 'uploads/foto.jpg', 'foto' );
		$this->put( 'remote', 'themes/tema/style.css', '/* v1 */' );
		$this->put( 'remote', 'themes/tema/functions.php', '<?php // se conserva sin delete' );

		$this->chunk_repeats = 2; // Every package arrives twice.
		$server              = $this->server( $remote, 'wpb_', self::NEW, array( 'allow_push' => true, 'allow_push_code' => true ) );
		$job                 = Planner::create(
			'push',
			$this->remote_input( $server, array( 'files' => array( 'uploads', 'themes' ), 'database' => false ) ),
			$this->with_files( $this->site( 'wpa_', self::OLD ), 'local' ),
			new Schema( $local )
		);
		$this->assertNotContains( 'push_begin', array_column( $job['steps'], 'type' ), 'no database' );
		$job = $this->run_job( $local, $job, $this->client( $server ) );

		$this->assertSame( '/* v2 */', file_get_contents( "$this->storage/remote/themes/tema/style.css" ) );
		$this->assertFileExists( "$this->storage/remote/themes/tema/functions.php" );
		$this->assertSame( 'foto', file_get_contents( "$this->storage/remote/uploads/foto.jpg" ) );
		$this->assertSame( self::NEW, $this->option( $remote, 'wpb_options', 'home' ), 'the remote database does not change' );
		$this->assertSame( '/* v1 */', file_get_contents( "$this->storage/remote/storage/files-{$job['id']}/backup/themes/tema/style.css" ) );
		$this->assertSame( array(), $job['warnings'] );
	}

	public function test_code_push_without_permission_is_refused_before_starting(): void {
		$local  = $this->seeded( 'wpa_', self::OLD );
		$remote = $this->site_b();
		$server = $this->server( $remote, 'wpb_', self::NEW, array( 'allow_push' => true ) );
		$this->expectExceptionMessage( 'code push' );
		Planner::create(
			'push',
			$this->remote_input( $server, array( 'files' => array( 'plugins' ) ) ),
			$this->with_files( $this->site( 'wpa_', self::OLD ), 'local' ),
			new Schema( $local )
		);
	}

	public function test_pull_brings_the_remote_site(): void {
		$remote = $this->seeded( 'wpa_', self::OLD );
		$local  = $this->site_b();
		$server = $this->server( $remote, 'wpa_', self::OLD, array( 'allow_pull' => true ) );

		$job = Planner::create( 'pull', $this->remote_input( $server ), $this->site( 'wpb_', self::NEW ), new Schema( $local ) );
		$job = $this->run_job( $local, $job, $this->client( $server ) );

		$this->assert_b_has_site_a( $local );
		$this->assertFileExists( $job['files']['backup'] );
		$this->assertArrayNotHasKey( 'key', $job['remote'], 'the other site\'s key is not stored' );
		$this->assertSame( self::OLD, $this->option( $remote, 'wpa_options', 'home' ), 'the remote does not change' );
	}

	public function test_pull_stops_to_review_the_files_and_can_bring_only_new_ones(): void {
		$remote = $this->seeded( 'wpa_', self::OLD );
		$local  = $this->site_b();
		$this->with_files( array(), 'remote' );
		$this->with_files( array(), 'local' );
		$this->put( 'remote', 'uploads/nueva.jpg', 'nueva' );
		$this->put( 'remote', 'uploads/cambiada.jpg', 'AAAA' );
		$this->put( 'local', 'uploads/cambiada.jpg', 'BBBB' );
		$this->put( 'local', 'uploads/sobra.jpg', 'solo aquí' );

		$server = $this->server( $remote, 'wpa_', self::OLD, array( 'allow_pull' => true, 'allow_pull_files' => true ) );
		$job    = Planner::create(
			'pull',
			$this->remote_input( $server, array( 'files' => array( 'uploads' ), 'database' => false, 'review' => true ) ),
			$this->with_files( $this->site( 'wpb_', self::NEW ), 'local' ),
			new Schema( $local )
		);
		$runner = new Runner( $local, $this->client( $server ) );
		for ( $i = 0; $i < 500 && Runner::is_open( $job ); $i++ ) {
			$job = $runner->step( $job, new Budget( 0 ) );
		}

		$this->assertSame( 'review', $job['status'], (string) $job['error'] );
		$stats = $job['file_stats']['uploads'];
		$this->assertSame( array( 1, 1, 1 ), array( $stats['new'], $stats['changed'], $stats['extra'] ), 'new, changed and only on this site' );
		$this->assertFileDoesNotExist( "$this->storage/local/uploads/nueva.jpg", 'nothing travels before the review' );

		$job = $runner->review( $job, true, false );
		$this->assertSame( 1, $job['file_stats']['uploads']['send'] );
		$job = $this->run_job( $local, $job, $this->client( $server ) );

		$this->assertSame( 'nueva', file_get_contents( "$this->storage/local/uploads/nueva.jpg" ) );
		$this->assertSame( 'BBBB', file_get_contents( "$this->storage/local/uploads/cambiada.jpg" ), 'only new files: a changed one is left alone' );
		$this->assertSame( 'solo aquí', file_get_contents( "$this->storage/local/uploads/sobra.jpg" ) );
	}

	public function test_pull_pauses_when_the_remote_is_unreachable_and_resumes(): void {
		$remote = $this->seeded( 'wpa_', self::OLD );
		$local  = $this->site_b();
		$this->with_files( array(), 'remote' );
		$this->with_files( array(), 'local' );
		$this->put( 'remote', 'uploads/a.jpg', 'a' );
		$this->put( 'remote', 'uploads/b.jpg', 'b' );

		$server = $this->server( $remote, 'wpa_', self::OLD, array( 'allow_pull' => true, 'allow_pull_files' => true ) );
		$real   = $this->client( $server );
		$down   = true;
		$client = function ( array $job, string $op, array $args, ?string $binary = null ) use ( $real, &$down ): array {
			if ( 'files_get' === $op && $down ) {
				$down = false; // The other site comes back for the next request.
				throw new Unreachable( 'Could not connect to http://remoto.test: timed out.' );
			}
			return $real( $job, $op, $args, $binary );
		};
		$job    = Planner::create(
			'pull',
			$this->remote_input( $server, array( 'files' => array( 'uploads' ) ) ),
			$this->with_files( $this->site( 'wpb_', self::NEW ), 'local' ),
			new Schema( $local )
		);
		$runner = new Runner( $local, $client );
		for ( $i = 0; $i < 500 && Runner::is_open( $job ); $i++ ) {
			$job = $runner->step( $job, new Budget( 0 ) );
		}

		$this->assertSame( 'paused', $job['status'], 'a network problem pauses the job instead of failing it' );
		$this->assertStringContainsString( 'timed out', (string) $job['error'] );

		$job = $this->run_job( $local, $runner->step( $job, new Budget( 0 ) ), $client );

		$this->assert_b_has_site_a( $local );
		$this->assertSame( $this->tree( "$this->storage/remote/uploads" ), $this->tree( "$this->storage/local/uploads" ) );
	}

	public function test_pull_with_repeated_steps_brings_the_same_site(): void {
		$remote = $this->seeded( 'wpa_', self::OLD );
		// About 6 MB without a primary key: it arrives in several batches, and a repeated batch would duplicate rows.
		$remote->query( 'CREATE TABLE wpa_big (txt longtext NOT NULL) ENGINE=InnoDB' );
		for ( $i = 0; $i < 12; $i++ ) {
			$remote->query( "INSERT INTO wpa_big VALUES (REPEAT('$i', 524288))" );
		}
		$local  = $this->site_b();
		$server = $this->server( $remote, 'wpa_', self::OLD, array( 'allow_pull' => true ) );

		$job = Planner::create( 'pull', $this->remote_input( $server ), $this->site( 'wpb_', self::NEW ), new Schema( $local ) );
		$this->run_job( $local, $job, $this->client( $server ), true );

		$this->assertSame( '12', $local->value( 'SELECT COUNT(*) FROM wpb_big' ) );
		$this->assertSame( $this->checksum( $remote, 'wpa_big' ), $this->checksum( $local, 'wpb_big' ) );

		$this->assert_b_has_site_a( $local );
		foreach ( array( 'options', 'posts', 'usermeta', 'parent', 'child' ) as $table ) {
			$this->assertSame( $remote->value( "SELECT COUNT(*) FROM wpa_$table" ), $local->value( "SELECT COUNT(*) FROM wpb_$table" ) . '', $table );
		}
	}

	public function test_push_survives_chunks_cut_off_on_the_remote_after_applying(): void {
		$local  = $this->seeded( 'wpa_', self::OLD );
		$remote = $this->site_b();
		$server = $this->server( $remote, 'wpb_', self::NEW, array( 'allow_push' => true ) );

		$this->chunk_repeats = 2; // The client retries every chunk...
		$this->lost_saves    = array(); // ...and the remote lost the save of each one the first time.
		$job                 = Planner::create( 'push', $this->remote_input( $server ), $this->site( 'wpa_', self::OLD ), new Schema( $local ) );
		$this->run_job( $local, $job, $this->client( $server ) );

		$this->assertNotEmpty( $this->lost_saves );
		$this->assert_b_has_site_a( $remote );
		foreach ( array( 'options', 'posts', 'usermeta', 'parent', 'child' ) as $table ) {
			$this->assertSame( $local->value( "SELECT COUNT(*) FROM wpa_$table" ), $remote->value( "SELECT COUNT(*) FROM wpb_$table" ) . '', $table );
		}
	}

	public function test_push_sends_this_site_to_the_remote_even_with_retries(): void {
		$local  = $this->seeded( 'wpa_', self::OLD );
		$remote = $this->site_b();
		$server = $this->server( $remote, 'wpb_', self::NEW, array( 'allow_push' => true ) );

		$this->chunk_repeats = 2; // Every chunk arrives twice: it must not duplicate rows.
		$job                 = Planner::create( 'push', $this->remote_input( $server ), $this->site( 'wpa_', self::OLD ), new Schema( $local ) );
		$job                 = $this->run_job( $local, $job, $this->client( $server ) );

		$this->assert_b_has_site_a( $remote );
		foreach ( array( 'options', 'posts', 'usermeta', 'parent', 'child' ) as $table ) {
			$this->assertSame( $local->value( "SELECT COUNT(*) FROM wpa_$table" ), $remote->value( "SELECT COUNT(*) FROM wpb_$table" ) . '', $table );
		}
		$this->assertSame( 'done', $this->remote_job['status'] );
		$this->assertFileExists( $this->remote_job['files']['backup'], 'the backup is made on the remote' );
		$this->assertArrayNotHasKey( 'backup', $job['files'], 'and not on this site' );
		$this->assertSame( self::OLD, $this->option( $local, 'wpa_options', 'home' ), 'this site does not change' );
	}

	public function test_a_push_does_not_replace_a_paused_or_reviewing_job_on_the_remote(): void {
		$remote = $this->site_b();
		$server = $this->server( $remote, 'wpb_', self::NEW, array( 'allow_push' => true ) );

		foreach ( array( 'paused', 'review' ) as $status ) {
			$this->remote_job = array(
				'id'        => str_repeat( 'a', 16 ),
				'status'    => $status,
				'heartbeat' => time(),
			);
			try {
				$server->handle( 'push_begin', array( 'backup' => true ) );
				$this->fail( "A push replaced a job in \"$status\"." );
			} catch ( \RuntimeException $e ) {
				$this->assertStringContainsString( 'another operation in progress', $e->getMessage() );
			}
			$this->assertSame( $status, $this->remote_job['status'], 'the waiting job is kept' );
		}
	}

	public function test_remote_permissions_are_required(): void {
		$remote = $this->seeded( 'wpa_', self::OLD );
		$local  = $this->site_b();
		$server = $this->server( $remote, 'wpa_', self::OLD, array( 'allow_pull' => false, 'allow_push' => false ) );

		try {
			Planner::create( 'pull', $this->remote_input( $server ), $this->site( 'wpb_', self::NEW ), new Schema( $local ) );
			$this->fail( 'Should have rejected the pull.' );
		} catch ( \InvalidArgumentException $e ) {
			$this->assertStringContainsString( 'does not allow pull', $e->getMessage() );
		}
		// Even if someone bypasses the Planner, the server checks it too.
		$this->expectExceptionMessage( 'does not allow pull' );
		$server->handle( 'pull_rows', array( 'table' => 'wpa_options', 'target' => '_sft_wpb_options' ) );
	}

	public function test_push_finish_cannot_touch_other_tables_or_run_extra_sql(): void {
		$remote = $this->site_b();
		$remote->query( 'CREATE TABLE otra_app_usuarios (id INT PRIMARY KEY)' ); // Another application in the same database.
		$server = $this->server( $remote, 'wpb_', self::NEW, array( 'allow_push' => true ) );
		$id     = $server->handle( 'push_begin', array() )['id'];
		for ( $i = 0; $i < 20 && ! $server->handle( 'push_step', array( 'id' => $id ) )['ready']; $i++ ) {
			// Until it waits for data.
		}
		$fk  = 'CONSTRAINT `fk` FOREIGN KEY (`p`) REFERENCES `wpa_parent` (`id`)';
		$bad = array(
			'table of another application'    => array( 'swap' => array( '_sft_otra_app_usuarios' => 'otra_app_usuarios' ) ),
			'temp table of another table'    => array( 'swap' => array( '_sft_wpb_posts' => 'wpb_options' ) ),
			'extra SQL in the foreign key'   => array(
				'swap' => array( '_sft_wpb_child' => 'wpb_child' ),
				'fks'  => array( array( 'table' => 'wpb_child', 'clause' => $fk . ', RENAME TO otra_base.wpb_child' ) ),
			),
			'reference outside the prefix'   => array(
				'swap' => array( '_sft_wpb_child' => 'wpb_child' ),
				'fks'  => array( array( 'table' => 'wpb_child', 'clause' => str_replace( 'wpa_parent', 'otra_app_usuarios', $fk ) ) ),
			),
		);
		foreach ( $bad as $case => $args ) {
			try {
				$server->handle( 'push_finish', array( 'id' => $id ) + $args );
				$this->fail( 'Should have rejected: ' . $case );
			} catch ( \RuntimeException $e ) {
				$this->assertMatchesRegularExpression( '/Invalid/', $e->getMessage(), $case );
			}
		}

		$ok = Schema::foreign_key( $fk . ' ON DELETE CASCADE', array( 'wpa_parent' => 'wpb_parent' ), 'wpb_' );
		$this->assertSame( 'CONSTRAINT `fk` FOREIGN KEY (`p`) REFERENCES `wpb_parent` (`id`) ON DELETE CASCADE', $ok );
	}

	public function test_pull_rejects_foreign_keys_from_a_malicious_remote(): void {
		$local  = $this->site_b();
		$remote = $this->seeded( 'wpa_', self::OLD );
		$server = $this->server( $remote, 'wpa_', self::OLD, array( 'allow_pull' => true ) );
		$evil   = function ( array $job, string $op, array $args ) use ( $server ): array {
			$result = json_decode( json_encode( $server->handle( $op, $args ) ), true );
			if ( 'pull_rows' === $op && $result['fks'] ) {
				$result['fks'][0] .= ', RENAME TO otra_base.robada';
			}
			return $result;
		};
		$job = Planner::create( 'pull', $this->remote_input( $server ), $this->site( 'wpb_', self::NEW ), new Schema( $local ) );
		$job = ( new Runner( $local, $evil ) )->step( $job, new Budget( 30 ) );
		$this->assertSame( 'failed', $job['status'] );
		$this->assertStringContainsString( 'invalid foreign key', (string) $job['error'] );
		$this->assertSame( self::NEW, $this->option( $local, 'wpb_options', 'home' ), 'this site did not change' );
	}

	public function test_push_only_writes_temporary_tables(): void {
		$remote = $this->site_b();
		$server = $this->server( $remote, 'wpb_', self::NEW, array( 'allow_push' => true ) );
		$id     = $server->handle( 'push_begin', array() )['id'];
		for ( $i = 0; $i < 20 && ! $server->handle( 'push_step', array( 'id' => $id ) )['ready']; $i++ ) {
			// Until it waits for data.
		}

		foreach ( array( 'DROP TABLE IF EXISTS `wpb_posts`', 'INSERT INTO `wpb_options` (option_name) VALUES (1)', 'CREATE TABLE `_sft_x`AS SELECT 1; DROP TABLE wpb_posts', 'DROP TABLE IF EXISTS `_sft_x`, `wpb_posts`', 'DROP TABLE IF EXISTS `_sft_x` , `wpb_posts`', 'INSERT INTO `_sft_x` (`a`) SELECT * FROM wpb_posts' ) as $sql ) {
			try {
				$server->handle( 'push_chunk', array( 'id' => $id, 'seq' => 1, 'statements' => MemorySink::encode( array( $sql ) ) ) );
				$this->fail( 'Should have rejected: ' . $sql );
			} catch ( \RuntimeException $e ) {
				$this->assertNotSame( '', $e->getMessage() );
			}
		}
		$this->assertSame( '3', $remote->value( 'SELECT COUNT(*) FROM wpb_posts' ), 'the live tables are still intact' );
	}
}

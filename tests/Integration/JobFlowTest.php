<?php
/**
 * Full flows: export, import, replace and cancel.
 *
 * @package Siteferry
 */

namespace Siteferry\Tests\Integration;

use Siteferry\Engine\Budget;
use Siteferry\Engine\Schema;
use Siteferry\Job\Planner;
use Siteferry\Job\Runner;
use Siteferry\Support\Db;

final class JobFlowTest extends DbTestCase {

	private const OLD = 'http://old.test';
	private const NEW = 'https://new.example';

	/**
	 * Test site "A" (prefix wpa_, URL http://old.test).
	 */
	private function site_a(): Db {
		[ $db ] = $this->database();
		$sql    = str_replace( '__P__', 'wpa_', (string) file_get_contents( dirname( __DIR__ ) . '/fixtures/seed-wp.sql' ) );
		$db->in_migration_session( fn() => $this->exec( $db, $sql ) );
		return $db;
	}

	/**
	 * Destination site "B" (prefix wpb_, URL https://new.example) with its own options.
	 */
	private function site_b(): Db {
		[ $db ] = $this->database();
		$sql    = str_replace( '__P__', 'wpb_', (string) file_get_contents( dirname( __DIR__ ) . '/fixtures/seed-wp.sql' ) );
		$db->in_migration_session( fn() => $this->exec( $db, $sql ) );
		$db->query( "UPDATE wpb_options SET option_value = '" . self::NEW . "' WHERE option_name IN ('home', 'siteurl')" );
		$db->query( "UPDATE wpb_options SET option_value = '0' WHERE option_name = 'blog_public'" );
		$db->query( "UPDATE wpb_options SET option_value = 'B-job' WHERE option_name = 'siteferry_job'" );
		$db->query( "UPDATE wpb_options SET option_value = 'a:2:{i:0;s:35:\"siteferry/siteferry.php\";i:1;s:15:\"other/other.php\";}' WHERE option_name = 'active_plugins'" );
		$db->query( "INSERT INTO wpb_posts (ID, post_content) VALUES (99, 'post que debe desaparecer')" );
		return $db;
	}

	/**
	 * Runs the job to the end, one unit of work per call. Steps whose type is in $repeat first
	 * run once from the same saved state and that result is thrown away (a request that was cut
	 * off before the job was saved, then repeated).
	 *
	 * @param list<string> $repeat Step types to repeat.
	 */
	private function run_job( Db $db, array $job, array $repeat = array() ): array {
		$runner = new Runner( $db );
		for ( $i = 0; $i < 5000 && Runner::is_open( $job ); $i++ ) {
			if ( in_array( $job['steps'][ $job['index'] ]['type'] ?? '', $repeat, true ) ) {
				$runner->step( $job, new Budget( 0 ) );
			}
			$job = $runner->step( $job, new Budget( 0 ) );
		}
		$this->assertSame( 'done', $job['status'], (string) $job['error'] . "\n" . implode( "\n", $job['log'] ) );
		$this->assertGreaterThan( 10, $i, 'The job must advance in many short steps' );
		return $job;
	}

	private function option( Db $db, string $table, string $name ): ?string {
		return $db->value( "SELECT option_value FROM `$table` WHERE option_name = " . $db->quote( $name ) );
	}

	public function test_export_then_import_into_another_prefix(): void {
		$a = $this->site_a();
		$b = $this->site_b();

		$export = $this->run_job( $a, Planner::create( 'export', array(), $this->site( 'wpa_', self::OLD ), new Schema( $a ) ) );
		$file   = $export['files']['export'];
		$this->assertFileExists( $file );
		$this->assertStringNotContainsString( 'A-job', implode( '', gzfile( $file ) ), 'siteferry_ options (plugin keys) are not exported' );

		$job = Planner::create(
			'import',
			array(
				'file'     => $file,
				'auto_url' => true,
				'backup'   => true,
			),
			$this->site( 'wpb_', self::NEW ),
			new Schema( $b )
		);
		$job = $this->run_job( $b, $job );

		$this->assertSame( self::NEW, $this->option( $b, 'wpb_options', 'home' ) );
		$this->assertSame( self::NEW, $this->option( $b, 'wpb_options', 'siteurl' ) );
		$this->assertSame( '0', $this->option( $b, 'wpb_options', 'blog_public' ), 'the destination blog_public is kept' );
		$this->assertSame( 'B-job', $this->option( $b, 'wpb_options', 'siteferry_job' ), 'the destination siteferry_ options are kept' );
		$this->assertContains( 'siteferry/siteferry.php', unserialize( $this->option( $b, 'wpb_options', 'active_plugins' ) ) );
		$this->assertStringContainsString( self::NEW . '/x', unserialize( $this->option( $b, 'wpb_options', 'widget_text' ) )[2]['text'] );
		$this->assertNotNull( $this->option( $b, 'wpb_options', 'wpb_user_roles' ) );
		$this->assertNull( $this->option( $b, 'wpb_options', 'wpa_user_roles' ) );

		$content = (string) $b->value( 'SELECT post_content FROM wpb_posts WHERE ID = 1' );
		$this->assertStringContainsString( 'https:\/\/new.example\/a.jpg', $content );
		$this->assertStringContainsString( 'src="https://new.example/a.jpg"', $content );
		$this->assertSame( self::OLD . '/?p=1', $b->value( 'SELECT guid FROM wpb_posts WHERE ID = 1' ), 'guid is left alone' );
		$this->assertNull( $b->value( 'SELECT ID FROM wpb_posts WHERE ID = 99' ) );
		$this->assertSame( '1', $b->value( "SELECT COUNT(*) FROM wpb_usermeta WHERE meta_key = 'wpb_capabilities'" ) );
		$this->assertSame( '0', $b->value( "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'wpa\\_%'" ) );
		$this->assertSame( array(), ( new Schema( $b ) )->temp_tables() );
		$this->assertSame(
			'wpb_parent',
			$b->value( "SELECT REFERENCED_TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'wpb_child' AND REFERENCED_TABLE_NAME IS NOT NULL" )
		);

		$backup = implode( '', gzfile( $job['files']['backup'] ) );
		$this->assertStringContainsString( 'post que debe desaparecer', $backup );
		$this->assertFileExists( $file, 'a file that does not belong to the plugin is not deleted' );
		$this->assertFileDoesNotExist( $job['files']['import_sql'], 'the decompressed .sql is deleted' );
	}

	public function test_dump_with_two_installs_needs_the_source_prefix(): void {
		// A database with the active install (wpa_) and leftovers of another (wpb_) in the same database.
		$source = $this->site_a();
		$stale  = str_replace( '__P__', 'wpb_', (string) file_get_contents( dirname( __DIR__ ) . '/fixtures/seed-wp.sql' ) );
		$source->in_migration_session( fn() => $this->exec( $source, $stale ) );
		$source->query( "UPDATE wpb_options SET option_value = 'http://stale.test' WHERE option_name IN ('home', 'siteurl')" );
		$file = $this->run_job( $source, Planner::create( 'export', array(), $this->site( '', self::OLD ), new Schema( $source ) ) )['files']['export'];

		$b      = $this->site_b();
		$runner = new Runner( $b );
		$job    = Planner::create( 'import', array( 'file' => $file, 'auto_url' => true ), $this->site( 'wpb_', self::NEW ), new Schema( $b ) );
		for ( $i = 0; $i < 5000 && Runner::is_open( $job ); $i++ ) {
			$job = $runner->step( $job, new Budget( 0 ) );
		}
		$this->assertSame( 'failed', $job['status'] );
		$this->assertStringContainsString( 'wpa_, wpb_', (string) $job['error'] );
		$this->assertSame( 'post que debe desaparecer', $b->value( 'SELECT post_content FROM wpb_posts WHERE ID = 99' ), 'nothing changed' );
		$runner->cancel( $job );

		$job = Planner::create(
			'import',
			array(
				'file'          => $file,
				'auto_url'      => true,
				'source_prefix' => 'wpa_',
			),
			$this->site( 'wpb_', self::NEW ),
			new Schema( $b )
		);
		$job = $this->run_job( $b, $job );

		$this->assertSame( self::NEW, $this->option( $b, 'wpb_options', 'home' ) );
		$this->assertNull( $b->value( 'SELECT ID FROM wpb_posts WHERE ID = 99' ) );
		$this->assertNotEmpty( array_filter( $job['log'], static fn( $l ) => str_contains( $l, 'skipped because they would collide' ) && str_contains( $l, 'wpb_options' ) ) );
		$this->assertSame( '0', $b->value( "SELECT COUNT(*) FROM wpb_options WHERE option_value LIKE '%stale.test%'" ) );
		$this->assertSame( array(), ( new Schema( $b ) )->temp_tables() );
	}

	public function test_repeated_import_step_gives_the_same_result(): void {
		// The mysqldump fixture plus a table without a primary key, where a repeated INSERT
		// would not fail but silently duplicate rows.
		$file = $this->storage . '/repeat.sql';
		file_put_contents(
			$file,
			file_get_contents( dirname( __DIR__ ) . '/fixtures/mysqldump-wp.sql' )
			. "CREATE TABLE `wpa_log` (`msg` varchar(20) NOT NULL) ENGINE=InnoDB;\nINSERT INTO `wpa_log` VALUES ('a'),('b');\n"
		);
		$import = fn( Db $db, array $repeat ) => $this->run_job(
			$db,
			Planner::create(
				'import',
				array(
					'file'     => $file,
					'auto_url' => true,
				),
				$this->site( 'wpb_', self::NEW ),
				new Schema( $db )
			),
			$repeat
		);

		$once = $this->site_b();
		$import( $once, array() );
		$twice = $this->site_b();
		$job   = $import( $twice, array( 'import' ) );

		$this->assertNotEmpty( array_filter( $job['log'], static fn( $l ) => str_contains( $l, 'continuing where it stopped' ) ), 'the repeated requests resumed' );

		$this->assertSame( '2', $twice->value( 'SELECT COUNT(*) FROM wpb_log' ) );
		foreach ( array( 'wpb_child', 'wpb_log', 'wpb_options', 'wpb_parent', 'wpb_posts', 'wpb_usermeta' ) as $table ) {
			$this->assertSame( $this->checksum( $once, $table ), $this->checksum( $twice, $table ), $table );
		}
		$this->assertSame( array(), ( new Schema( $twice ) )->temp_tables() );
		$this->assertFileDoesNotExist( $job['files']['import_progress'], 'the progress record is deleted with the job' );
	}

	public function test_import_real_mysqldump_file(): void {
		$b   = $this->site_b();
		$job = Planner::create(
			'import',
			array(
				'file'     => dirname( __DIR__ ) . '/fixtures/mysqldump-wp.sql',
				'auto_url' => true,
			),
			$this->site( 'wpb_', self::NEW ),
			new Schema( $b )
		);
		$job = $this->run_job( $b, $job );

		$this->assertSame( self::NEW, $this->option( $b, 'wpb_options', 'home' ) );
		$this->assertSame( 'Hola mundo 🚀', $b->value( 'SELECT post_content FROM wpb_posts WHERE ID = 2' ) );
		$this->assertSame( '0000-00-00 00:00:00', $b->value( 'SELECT post_date_gmt FROM wpb_posts WHERE ID = 1' ) );
		$this->assertSame( '2', $b->value( 'SELECT COUNT(*) FROM wpb_child' ) );
		$this->assertGreaterThan( 0, $job['import']['skipped'] + count( array_filter( $job['log'], static fn( $l ) => str_contains( $l, 'skipped' ) ) ) );
		$this->assertSame( array(), ( new Schema( $b ) )->temp_tables() );
	}

	public function test_replace_in_place_with_backup(): void {
		$a   = $this->site_a();
		$job = Planner::create(
			'replace',
			array(
				'pairs'  => array( array( self::OLD, self::NEW ) ),
				'backup' => true,
			),
			$this->site( 'wpa_', self::OLD ),
			new Schema( $a )
		);
		$job = $this->run_job( $a, $job );

		$this->assertSame( self::NEW, $this->option( $a, 'wpa_options', 'home' ) );
		$this->assertStringContainsString( self::NEW . '/x', unserialize( $this->option( $a, 'wpa_options', 'widget_text' ) )[2]['text'] );
		$this->assertSame( self::OLD . '/?p=1', $a->value( 'SELECT guid FROM wpa_posts WHERE ID = 1' ) );
		$this->assertSame( 'A-job', $this->option( $a, 'wpa_options', 'siteferry_job' ) );
		$this->assertSame( array(), ( new Schema( $a ) )->temp_tables() );
		$this->assertStringContainsString( self::OLD, implode( '', gzfile( $job['files']['backup'] ) ), 'the backup has the previous data' );
	}

	public function test_cancel_leaves_live_tables_untouched(): void {
		$a      = $this->site_a();
		$runner = new Runner( $a );
		$job    = Planner::create( 'replace', array( 'pairs' => array( array( self::OLD, self::NEW ) ) ), $this->site( 'wpa_', self::OLD ), new Schema( $a ) );
		for ( $i = 0; $i < 4; $i++ ) {
			$job = $runner->step( $job, new Budget( 0 ) );
		}
		$this->assertNotSame( array(), ( new Schema( $a ) )->temp_tables() );

		$job = $runner->cancel( $job );
		$this->assertSame( 'cancelled', $job['status'] );
		$this->assertSame( array(), ( new Schema( $a ) )->temp_tables() );
		$this->assertSame( self::OLD, $this->option( $a, 'wpa_options', 'home' ) );
	}

	public function test_preflight_rejects_invalid_regex_without_touching_anything(): void {
		$a   = $this->site_a();
		$job = Planner::create(
			'replace',
			array(
				'pairs' => array( array( '#(#', 'x' ) ),
				'regex' => true,
			),
			$this->site( 'wpa_', self::OLD ),
			new Schema( $a )
		);
		$job = ( new Runner( $a ) )->step( $job, new Budget( 10 ) );
		$this->assertSame( 'failed', $job['status'] );
		$this->assertStringContainsString( 'Invalid regular expression', (string) $job['error'] );
		$this->assertSame( array(), ( new Schema( $a ) )->temp_tables() );
	}
}

<?php
/**
 * Base class for tests against a real MySQL.
 *
 * @package Siteferry
 */

namespace Siteferry\Tests\Integration;

use Siteferry\Support\Db;
use PHPUnit\Framework\TestCase;

/**
 * Creates disposable databases. Needs SITEFERRY_TEST_DB_HOST (and optionally _USER, _PASS).
 */
abstract class DbTestCase extends TestCase {

	/**
	 * Databases created in the current test.
	 *
	 * @var list<string>
	 */
	private array $databases = array();

	/**
	 * Open connections.
	 *
	 * @var list<\mysqli>
	 */
	private array $links = array();

	protected string $storage;

	protected function setUp(): void {
		if ( ! getenv( 'SITEFERRY_TEST_DB_HOST' ) ) {
			$this->markTestSkipped( 'Set SITEFERRY_TEST_DB_HOST to run the integration tests.' );
		}
		$this->storage = sys_get_temp_dir() . '/siteferry-' . bin2hex( random_bytes( 4 ) );
		mkdir( $this->storage );
	}

	protected function tearDown(): void {
		foreach ( $this->databases as $name ) {
			$this->links[0]->query( 'DROP DATABASE IF EXISTS `' . $name . '`' );
		}
		foreach ( $this->links as $link ) {
			$link->close();
		}
		if ( isset( $this->storage ) ) {
			\Siteferry\Files\Applier::remove_tree( $this->storage );
		}
	}

	/**
	 * Creates an empty database and returns [Db, mysqli] connected to it.
	 *
	 * @return array{0: Db, 1: \mysqli}
	 */
	protected function database(): array {
		$name = 'siteferry_t_' . bin2hex( random_bytes( 4 ) );
		$link = new \mysqli( getenv( 'SITEFERRY_TEST_DB_HOST' ), getenv( 'SITEFERRY_TEST_DB_USER' ) ?: 'root', getenv( 'SITEFERRY_TEST_DB_PASS' ) ?: 'root' );
		$link->set_charset( 'utf8mb4' );
		$link->query( "CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci" );
		$link->select_db( $name );
		$this->databases[] = $name;
		$this->links[]     = $link;
		return array( new Db( $link ), $link );
	}

	/**
	 * Runs several statements separated by ";\n".
	 *
	 * @param Db     $db  Connection.
	 * @param string $sql SQL.
	 */
	protected function exec( Db $db, string $sql ): void {
		foreach ( array_filter( array_map( 'trim', explode( ";\n", $sql ) ) ) as $statement ) {
			$db->query( $statement );
		}
	}

	/**
	 * CHECKSUM TABLE.
	 *
	 * @param Db     $db    Connection.
	 * @param string $table Table.
	 */
	protected function checksum( Db $db, string $table ): string {
		return (string) $db->rows( 'CHECKSUM TABLE `' . $table . '`' )[0]['Checksum'];
	}

	/**
	 * Site context for the Planner.
	 *
	 * @param string $prefix Table prefix.
	 * @param string $home   URL.
	 */
	protected function site( string $prefix, string $home ): array {
		return array(
			'prefix'        => $prefix,
			'home'          => $home,
			'siteurl'       => $home,
			'storage'       => $this->storage,
			'host'          => (string) parse_url( $home, PHP_URL_HOST ),
			'plugin'        => 'siteferry/siteferry.php',
			'multisite'     => false,
			'options_table' => $prefix . 'options',
			'preserve'      => array( 'blog_public' ),
		);
	}
}

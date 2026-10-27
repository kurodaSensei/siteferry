<?php
/**
 * Pruebas del motor SQL.
 *
 * @package Siteferry
 */

namespace Siteferry\Tests\Integration;

use Siteferry\Engine\Budget;
use Siteferry\Engine\FileSink;
use Siteferry\Engine\Finalizer;
use Siteferry\Engine\Schema;
use Siteferry\Engine\SqlFileReader;
use Siteferry\Engine\TableCopier;
use Siteferry\Engine\TableSink;
use Siteferry\Support\Db;

final class EngineTest extends DbTestCase {

	private const TABLES = <<<'SQL'
CREATE TABLE t_mix (
	id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
	txt LONGTEXT NULL,
	num DECIMAL(10,3) NULL,
	flt DOUBLE NULL,
	d DATETIME NULL,
	bin VARBINARY(64) NULL,
	blb BLOB NULL,
	flags BIT(3) NULL,
	doc JSON NULL,
	pt POINT NULL,
	PRIMARY KEY (id)
) ENGINE=InnoDB;
CREATE TABLE t_comp (a INT NOT NULL, b VARCHAR(20) NOT NULL, v TEXT, PRIMARY KEY (a, b));
CREATE TABLE t_nopk (v TEXT, n INT);
CREATE TABLE t_binpk (k VARBINARY(16) NOT NULL, v TEXT, PRIMARY KEY (k))
SQL;

	private function seed( Db $db ): void {
		// Fechas 0000-00-00 como las de los borradores de WordPress: solo entran sin modo estricto.
		$db->in_migration_session( fn() => $this->seed_rows( $db ) );
	}

	private function seed_rows( Db $db ): void {
		$this->exec( $db, self::TABLES );
		$db->query( "INSERT INTO t_mix (id, txt) VALUES (0, 'fila con id cero')" );
		$db->query(
			"INSERT INTO t_mix (txt, num, flt, d, bin, blb, flags, doc, pt) VALUES
			('línea 1\\nlínea 2\\r\\n\\ttab \\\\ barra \\' comilla \" doble ; punto y coma\\n', 12.345, 1.5e-7, '2024-02-29 23:59:59', 0x00FF10, 0x0001020304FFFE, b'101', '{\"u\": \"http://old.test\"}', ST_GeomFromText('POINT(1 2)')),
			('🚀 emoji 中文', NULL, NULL, NULL, '', '', b'000', '[]', NULL),
			('', -0.5, 0, '0000-00-00 00:00:00', NULL, NULL, NULL, NULL, NULL),
			(NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL)"
		);
		$db->query( str_repeat( "INSERT INTO t_mix (txt) VALUES ('" . str_repeat( 'x', 300000 ) . "');", 1 ) );
		for ( $i = 0; $i < 30; $i++ ) {
			$db->query( "INSERT INTO t_comp VALUES (" . ( $i % 3 ) . ", 'k$i', 'v$i'), (" . ( $i % 3 ) . ", 'z$i', NULL)" );
			$db->query( "INSERT INTO t_nopk VALUES ('n$i', $i)" );
			$db->query( "INSERT INTO t_binpk VALUES (UNHEX(MD5('$i')), 'b$i')" );
		}
	}

	/**
	 * Copia una tabla llamando una y otra vez con presupuesto 0 (una fila por llamada).
	 */
	private function copy_all( TableCopier $copier, string $source, string $target, $sink, array &$fks = array() ): int {
		$cursor = null;
		$calls  = 0;
		do {
			$r      = $copier->run( $source, $target, $source, $sink, $cursor, new Budget( 0 ), true );
			$fks    = array_merge( $fks, $r['fks'] );
			$cursor = $r['cursor'];
			++$calls;
		} while ( null !== $cursor );
		return $calls;
	}

	public function test_copy_to_temp_table_is_identical(): void {
		[ $db ] = $this->database();
		$this->seed( $db );

		$copier = new TableCopier( $db, new Schema( $db ), null, 64 * 1024 );
		$db->in_migration_session(
			function () use ( $copier, $db ) {
				foreach ( array( 't_mix', 't_comp', 't_nopk', 't_binpk' ) as $table ) {
					$calls = $this->copy_all( $copier, $table, Schema::TMP . $table, new TableSink( $db ) );
					$this->assertGreaterThan( 5, $calls, "$table debe copiarse en varios tramos" );
				}
			}
		);

		foreach ( array( 't_mix', 't_comp', 't_nopk', 't_binpk' ) as $table ) {
			$this->assertSame( $this->checksum( $db, $table ), $this->checksum( $db, Schema::TMP . $table ), $table );
		}
		$this->assertSame( '0', $db->value( "SELECT id FROM _sft_t_mix WHERE txt = 'fila con id cero'" ) );
	}

	public function test_dump_file_round_trip(): void {
		[ $source ] = $this->database();
		[ $target ] = $this->database();
		$this->seed( $source );
		$file = $this->storage . '/dump.sql.gz';

		$copier = new TableCopier( $source, new Schema( $source ), null, 64 * 1024 );
		$source->in_migration_session(
			function () use ( $copier, $file ) {
				foreach ( array( 't_mix', 't_comp', 't_nopk', 't_binpk' ) as $table ) {
					$sink = new FileSink( $file );
					$this->copy_all( $copier, $table, $table, $sink );
					$sink->close();
				}
			}
		);

		$plain = $this->storage . '/dump.sql';
		file_put_contents( $plain, implode( '', gzfile( $file ) ) ); // gzfile lee todos los miembros gzip.
		$this->assertStringNotContainsString( "línea 1\nlínea", file_get_contents( $plain ), 'Ningún valor debe ocupar varias líneas' );

		$target->in_migration_session(
			function () use ( $plain, $target ) {
				$offset = 0;
				do {
					$r      = SqlFileReader::read( $plain, $offset, new Budget( 0 ), static fn( $sql ) => $target->query( $sql ) );
					$offset = $r['offset'];
				} while ( ! $r['done'] );
			}
		);

		foreach ( array( 't_mix', 't_comp', 't_nopk', 't_binpk' ) as $table ) {
			$this->assertSame( $this->checksum( $source, $table ), $this->checksum( $target, $table ), $table );
		}
	}

	public function test_finalizer_swaps_and_recreates_foreign_keys(): void {
		[ $db ] = $this->database();
		$this->exec(
			$db,
			"CREATE TABLE p (id INT PRIMARY KEY, v TEXT) ENGINE=InnoDB;
			CREATE TABLE c (id INT PRIMARY KEY, p_id INT, CONSTRAINT fk_c_p FOREIGN KEY (p_id) REFERENCES p (id) ON DELETE CASCADE) ENGINE=InnoDB;
			INSERT INTO p VALUES (1, 'viejo'), (2, 'dos');
			INSERT INTO c VALUES (10, 1), (20, 2)"
		);
		$schema = new Schema( $db );
		$copier = new TableCopier( $db, $schema, new \Siteferry\Engine\Replacer( array( array( 'viejo', 'nuevo' ) ) ) );
		$fks    = array();
		$db->in_migration_session(
			function () use ( $copier, $db, &$fks ) {
				$this->copy_all( $copier, 'p', '_sft_p', new TableSink( $db ), $fks );
				$found = array();
				$this->copy_all( $copier, 'c', '_sft_c', new TableSink( $db ), $found );
				foreach ( $found as $clause ) {
					$fks[] = array( 'table' => 'c', 'clause' => $clause );
				}
			}
		);
		$this->assertCount( 1, $fks );

		$warnings = $db->in_migration_session(
			fn() => ( new Finalizer( $db, $schema ) )->run( array( '_sft_p' => 'p', '_sft_c' => 'c' ), $fks, array(), 'wp_options', array(), 'x/x.php' )
		);
		$this->assertSame( array(), $warnings );
		$this->assertSame( 'nuevo', $db->value( 'SELECT v FROM p WHERE id = 1' ) );
		$this->assertSame( array(), $schema->temp_tables() );
		$this->assertSame(
			'p',
			$db->value( "SELECT REFERENCED_TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'c' AND REFERENCED_TABLE_NAME IS NOT NULL" )
		);
		$db->query( 'DELETE FROM p WHERE id = 1' );
		$this->assertSame( '1', $db->value( 'SELECT COUNT(*) FROM c' ), 'ON DELETE CASCADE sigue activo' );
	}
}

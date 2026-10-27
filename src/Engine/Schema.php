<?php
/**
 * Table and column information.
 *
 * @package Siteferry
 */

namespace Siteferry\Engine;

use Siteferry\Support\Db;

/**
 * Reads the structure of the tables being migrated.
 */
final class Schema {

	/** New tables that will replace the live ones. */
	public const TMP = '_sft_';

	/** Raw tables loaded from a .sql file. */
	public const RAW = '_sfr_';

	/** Old tables during the swap. */
	public const OLD = '_sfo_';

	/** Foreign key clause exactly as SHOW CREATE TABLE writes it, and nothing else. */
	private const FOREIGN_KEY = '/^CONSTRAINT `([^`]{1,64})` FOREIGN KEY \((`[^`]{1,64}`(?:, ?`[^`]{1,64}`)*)\) REFERENCES `([^`]{1,64})` \((`[^`]{1,64}`(?:, ?`[^`]{1,64}`)*)\)((?: ON (?:DELETE|UPDATE) (?:RESTRICT|CASCADE|SET NULL|NO ACTION|SET DEFAULT))*)$/i';

	/** Maximum table name length so a temporary prefix still fits. */
	public const MAX_NAME = 64 - 5;

	/**
	 * Constructor.
	 *
	 * @param Db $db Connection.
	 */
	public function __construct( private Db $db ) {}

	/**
	 * Whether the name belongs to a temporary plugin table.
	 *
	 * @param string $name Table.
	 */
	public static function is_temp( string $name ): bool {
		return str_starts_with( $name, self::TMP ) || str_starts_with( $name, self::RAW ) || str_starts_with( $name, self::OLD );
	}

	/**
	 * Base tables (no views) with estimated rows and size.
	 *
	 * @param string $prefix Only tables starting with this (temporary ones start with "_sf").
	 * @return array<string, array{rows: int, bytes: int, avg: int}>
	 */
	public function stats( string $prefix = '' ): array {
		$rows = $this->db->rows(
			"SELECT TABLE_NAME AS name, TABLE_ROWS AS row_count, DATA_LENGTH + INDEX_LENGTH AS bytes, AVG_ROW_LENGTH AS avg
			FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME"
		);
		$out  = array();
		foreach ( $rows as $row ) {
			$name = (string) $row['name'];
			if ( str_starts_with( $name, $prefix ) ) {
				$out[ $name ] = array(
					'rows'  => (int) $row['row_count'],
					'bytes' => (int) $row['bytes'],
					'avg'   => (int) $row['avg'],
				);
			}
		}
		return $out;
	}

	/**
	 * Whether the table exists.
	 *
	 * @param string $name Table.
	 */
	public function exists( string $name ): bool {
		return null !== $this->db->value(
			'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ' . $this->db->quote( $name )
		);
	}

	/**
	 * Existing temporary plugin tables.
	 *
	 * @return list<string>
	 */
	public function temp_tables(): array {
		$names = array_column( $this->db->rows( 'SELECT TABLE_NAME AS name FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()' ), 'name' );
		return array_values( array_filter( $names, array( self::class, 'is_temp' ) ) );
	}

	/**
	 * Columns with their simplified type.
	 *
	 * @param string $table Table.
	 * @return list<array{name: string, kind: string}> kind: number | bit | binary | text | other.
	 */
	public function columns( string $table ): array {
		$out = array();
		foreach ( $this->db->rows( 'SHOW FULL COLUMNS FROM ' . Db::ident( $table ) ) as $row ) {
			$out[] = array(
				'name' => (string) $row['Field'],
				'kind' => self::kind( (string) $row['Type'] ),
			);
		}
		return $out;
	}

	/**
	 * Primary key columns, in order.
	 *
	 * @param string $table Table.
	 * @return list<string>
	 */
	public function primary_key( string $table ): array {
		$rows = $this->db->rows( 'SHOW KEYS FROM ' . Db::ident( $table ) . " WHERE Key_name = 'PRIMARY'" );
		usort( $rows, static fn( $a, $b ) => (int) $a['Seq_in_index'] <=> (int) $b['Seq_in_index'] );
		return array_column( $rows, 'Column_name' );
	}

	/**
	 * CREATE TABLE for $source under the name $target, on a single line.
	 *
	 * @param string $source     Source table.
	 * @param string $target     Name for the CREATE.
	 * @param bool   $defer_fks  Remove the foreign keys and return them separately.
	 * @return array{0: string, 1: list<string>} [CREATE, removed FOREIGN KEY clauses].
	 */
	public function create_sql( string $source, string $target, bool $defer_fks ): array {
		$rows   = $this->db->rows( 'SHOW CREATE TABLE ' . Db::ident( $source ) );
		$create = (string) $rows[0]['Create Table'];
		$create = preg_replace( '/^CREATE TABLE `(?:[^`]|``)+`/', 'CREATE TABLE ' . str_replace( '\\', '\\\\', Db::ident( $target ) ), $create, 1 );
		return self::split_foreign_keys( (string) $create, $defer_fks );
	}

	/**
	 * Removes the FOREIGN KEY lines from a CREATE TABLE formatted with one definition per line
	 * (as SHOW CREATE TABLE and mysqldump write it) and collapses it onto a single line.
	 *
	 * @param string $create CREATE TABLE.
	 * @param bool   $remove Remove the foreign keys.
	 * @return array{0: string, 1: list<string>}
	 */
	public static function split_foreign_keys( string $create, bool $remove = true ): array {
		// ponytail: if the CREATE comes on a single line, the foreign keys are not split out.
		$keep = array();
		$fks  = array();
		foreach ( preg_split( '/\R/', trim( $create ) ) as $line ) {
			$trimmed = trim( $line );
			if ( $remove && preg_match( '/^(CONSTRAINT\s.+\s)?FOREIGN KEY\s/i', $trimmed ) ) {
				$fks[] = rtrim( $trimmed, ',' );
				continue;
			}
			$keep[] = $trimmed;
		}
		$count = count( $keep );
		for ( $i = 0; $i < $count - 1; $i++ ) {
			if ( str_starts_with( $keep[ $i + 1 ], ')' ) ) {
				$keep[ $i ] = rtrim( $keep[ $i ], ',' );
			}
		}
		return array( implode( ' ', $keep ), $fks );
	}

	/**
	 * Simplified type derived from the MySQL column type.
	 *
	 * @param string $type Type, e.g. "bigint(20) unsigned".
	 */
	private static function kind( string $type ): string {
		$type = strtolower( $type );
		if ( preg_match( '/^(tinyint|smallint|mediumint|int|bigint|integer|decimal|numeric|float|double|real)\b/', $type ) ) {
			return 'number';
		}
		if ( str_starts_with( $type, 'bit' ) ) {
			return 'bit';
		}
		if ( preg_match( '/^(binary|varbinary|tinyblob|blob|mediumblob|longblob|geometry|point|linestring|polygon|multipoint|multilinestring|multipolygon|geometrycollection|geomcollection)\b/', $type ) ) {
			return 'binary';
		}
		if ( preg_match( '/^(char|varchar|tinytext|text|mediumtext|longtext|json)\b/', $type ) ) {
			return 'text';
		}
		return 'other';
	}

	/**
	 * Collations this server knows (loaded on first use).
	 *
	 * @var array<string, int>|null
	 */
	private ?array $collations = null;

	/**
	 * Replaces collations this server does not know with the closest one it has
	 * (e.g. MySQL 8's utf8mb4_0900_ai_ci when importing into MariaDB).
	 *
	 * @param string $sql Table definition.
	 */
	public function known_collations( string $sql ): string {
		$this->collations ??= array_flip( array_column( $this->db->rows( 'SHOW COLLATION' ), 'Collation' ) );
		return (string) preg_replace_callback(
			'/\b(utf8mb4|utf8mb3|utf8)_[a-z0-9_]+\b/i',
			function ( array $m ): string {
				if ( isset( $this->collations[ $m[0] ] ) ) {
					return $m[0];
				}
				$charsets = 'utf8mb4' === strtolower( $m[1] ) ? array( 'utf8mb4' ) : array( 'utf8mb3', 'utf8' );
				foreach ( $charsets as $charset ) {
					foreach ( array( '_unicode_520_ci', '_unicode_ci', '_general_ci' ) as $suffix ) {
						if ( isset( $this->collations[ $charset . $suffix ] ) ) {
							return $charset . $suffix;
						}
					}
				}
				return $m[0];
			},
			$sql
		);
	}

	/**
	 * Validates a foreign key coming from another site and returns it pointing at this site's
	 * table. Only accepts the exact SHOW CREATE TABLE form (no extra SQL) and a referenced
	 * table with the given prefix.
	 *
	 * @param string                $clause  Received clause.
	 * @param array<string, string> $ref_map Name on the source => name here.
	 * @param string                $prefix  Table prefix of this site.
	 * @return string|null Safe clause, or null if it is not valid.
	 */
	public static function foreign_key( string $clause, array $ref_map, string $prefix ): ?string {
		if ( ! preg_match( self::FOREIGN_KEY, trim( $clause ), $m ) ) {
			return null;
		}
		$target = (string) ( $ref_map[ $m[3] ] ?? $m[3] );
		if ( '' === $prefix || ! str_starts_with( $target, $prefix ) || self::is_temp( $target ) || ! preg_match( '/^[A-Za-z0-9_$]{1,64}$/', $target ) ) {
			return null;
		}
		return 'CONSTRAINT ' . Db::ident( $m[1] ) . ' FOREIGN KEY (' . $m[2] . ') REFERENCES ' . Db::ident( $target ) . ' (' . $m[4] . ')' . $m[5];
	}

	/**
	 * Whether a statement coming from another site is safe to run: it may only create, fill or
	 * drop ONE temporary _sft_ table, and nothing in it may read from, link to or write outside that table.
	 *
	 * @param string      $sql   Statement.
	 * @param string|null $table Exact table expected (null = any _sft_).
	 */
	public static function safe_temp_statement( string $sql, ?string $table = null ): bool {
		$name = null === $table ? '_sft_[A-Za-z0-9_$]{1,59}' : preg_quote( $table, '/' );
		if ( preg_match( '/^DROP TABLE IF EXISTS `' . $name . '`$/', $sql ) ) {
			return null === $table || str_starts_with( $table, self::TMP );
		}
		if ( ( null !== $table && ! str_starts_with( $table, self::TMP ) ) || ! preg_match( '/^(CREATE TABLE|INSERT INTO) `' . $name . '` \(/', $sql, $m ) ) {
			return false;
		}
		$plain = self::strip_literals( $sql );
		// No comments outside the string literals: a quote inside a comment would fool the
		// analysis, and /*!…*/ runs its contents. The copier never writes comments.
		if ( null === $plain || preg_match( '/\/\*|\*\/|#|--/', $plain ) ) {
			return false;
		}
		if ( 'INSERT INTO' === $m[1] ) {
			// Only VALUES with literals: NULL, number, hexadecimal or string (already emptied). Validated without
			// a repeated expression over the whole statement: with statements of hundreds of KB that
			// expression exhausts the PCRE stack.
			if ( ! preg_match( '/^INSERT INTO `` \(``(?:,``)*\) VALUES /', $plain, $head ) ) {
				return false;
			}
			$shape = preg_replace( "/0x[0-9A-Fa-f]+|NULL|''|-?[0-9]+(?:\\.[0-9]+)?(?:[eE][-+]?[0-9]+)?/", 'L', substr( $plain, strlen( $head[0] ) ) );
			if ( null === $shape || strspn( $shape, 'L(),' ) !== strlen( $shape ) || ! str_starts_with( $shape, '(' ) || ! str_ends_with( $shape, ')' ) ) {
				return false;
			}
			foreach ( explode( '),(', substr( $shape, 1, -1 ) ) as $tuple ) {
				if ( ! preg_match( '/^L(?:,L)*$/', $tuple ) ) {
					return false;
				}
			}
			return true;
		}
		// CREATE: columns and indexes, then only the table options SHOW CREATE TABLE writes,
		// with a single ENGINE from the list. Nothing that reads other tables, links them or writes to disk.
		$at = strrpos( $plain, ') ENGINE=' );
		if ( false === $at || 1 !== preg_match_all( '/\\bENGINE\\b/i', $plain ) ) {
			return false;
		}
		$body    = substr( $plain, 0, $at + 1 );
		$options = substr( $plain, $at + 2 );
		$option  = '(?:AUTO_INCREMENT=[0-9]+|(?:DEFAULT )?(?:CHARSET|CHARACTER SET|COLLATE)=[A-Za-z0-9_]+|COMMENT=\'\'|ROW_FORMAT=[A-Za-z]+'
			. '|(?:ENCRYPTION|COMPRESSION)=\'\'|ENCRYPTED=(?:YES|NO)'
			. '|(?:STATS_PERSISTENT|STATS_AUTO_RECALC|STATS_SAMPLE_PAGES|KEY_BLOCK_SIZE|PACK_KEYS|CHECKSUM|DELAY_KEY_WRITE|AVG_ROW_LENGTH|MAX_ROWS|MIN_ROWS'
			. '|PAGE_CHECKSUM|TRANSACTIONAL|PAGE_COMPRESSED|PAGE_COMPRESSION_LEVEL|ENCRYPTION_KEY_ID)=[0-9]+)';
		if ( ! preg_match( "/^ENGINE=(?:InnoDB|MyISAM|Aria|MEMORY)(?: $option)*$/i", $options ) ) {
			return false;
		}
		return ! preg_match( '/\\b(SELECT|REFERENCES|UNION|DIRECTORY|CONNECTION|TABLESPACE|PARTITION)\\b/i', $body );
	}

	/**
	 * The statement with every '…' string, `…` identifier or "…" string reduced to its empty quotes,
	 * so the structure can be analyzed without the contents of the values counting. Null if a
	 * quote is left unclosed.
	 *
	 * @param string $sql Statement.
	 */
	private static function strip_literals( string $sql ): ?string {
		$out = '';
		$len = strlen( $sql );
		$i   = 0;
		while ( $i < $len ) {
			$start = $i + strcspn( $sql, "'\"`", $i );
			$out  .= substr( $sql, $i, $start - $i );
			if ( $start >= $len ) {
				break;
			}
			$quote = $sql[ $start ];
			$stops = '`' === $quote ? '`' : $quote . '\\'; // In identifiers the backslash does not escape.
			$k     = $start + 1;
			while ( true ) {
				$k += strcspn( $sql, $stops, $k );
				if ( $k >= $len ) {
					return null;
				}
				if ( '\\' === $sql[ $k ] ) {
					$k += 2;
					continue;
				}
				if ( $k + 1 < $len && $sql[ $k + 1 ] === $quote ) {
					$k += 2; // Doubled quote inside the string.
					continue;
				}
				break;
			}
			$out .= $quote . $quote;
			$i    = $k + 1;
		}
		return $out;
	}
}

<?php
/**
 * Copies a table in batches, replacing along the way.
 *
 * @package Siteferry
 */

namespace Siteferry\Engine;

use Siteferry\Support\Db;

/**
 * Reads rows from a table in primary key order (or by offset if it has none),
 * applies the Replacer to the text columns and writes them as INSERTs into a Sink.
 */
final class TableCopier {

	/** Approximate memory for one page of rows. */
	private const PAGE_BYTES = 16 * 1024 * 1024;

	/**
	 * Constructor.
	 *
	 * @param Db            $db            Connection.
	 * @param Schema        $schema        Structure.
	 * @param Replacer|null $replacer      Replacements (null = literal copy).
	 * @param int           $max_bytes     Maximum size of an INSERT.
	 * @param bool          $replace_guids Also replace in the guid column.
	 * @param string        $prefix_from   Table prefix of the source.
	 * @param string        $prefix_to     Table prefix of the destination.
	 * @param string        $options_table Options table (name on the source) whose siteferry_* rows are not copied:
	 *                                     they hold plugin keys and are kept aside on finalize.
	 */
	public function __construct(
		private Db $db,
		private Schema $schema,
		private ?Replacer $replacer,
		private int $max_bytes = 512 * 1024,
		private bool $replace_guids = false,
		private string $prefix_from = '',
		private string $prefix_to = '',
		private string $options_table = ''
	) {}

	/**
	 * Copies a chunk of $source into $target.
	 *
	 * @param string     $source    Source table.
	 * @param string     $target    Destination table (name used in the SQL).
	 * @param string     $logical   Table name on the source site (without temporary prefix).
	 * @param Sink       $sink      Destination for the statements.
	 * @param array|null $cursor    Null to start: emits DROP + CREATE.
	 * @param Budget     $budget    Available time.
	 * @param bool       $defer_fks Remove the foreign keys from the CREATE and return them.
	 * @return array{cursor: ?array, rows: int, fks: list<string>} cursor null = table finished.
	 */
	public function run( string $source, string $target, string $logical, Sink $sink, ?array $cursor, Budget $budget, bool $defer_fks ): array {
		$columns = $this->schema->columns( $source );
		$private = $logical === $this->options_table;
		$pk      = $this->schema->primary_key( $source );
		$kinds   = array_column( $columns, 'kind', 'name' );
		$fks     = array();

		if ( null === $cursor ) {
			[ $create, $fks ] = $this->schema->create_sql( $source, $target, $defer_fks );
			$sink->write( 'DROP TABLE IF EXISTS ' . Db::ident( $target ) );
			$sink->write( $create );
			$cursor = array(
				'last'   => null,
				'offset' => 0,
			);
		}

		$writer = new SqlWriter( $sink, $target, array_column( $columns, 'name' ), $this->max_bytes );
		$page   = $this->page_size( $source );
		$rows   = 0;
		$done   = false;

		do {
			$result = $this->db->query( $this->select( $source, $columns, $pk, $cursor, $page ) );
			$read   = 0;
			$cut    = false;
			while ( $row = $result->fetch_assoc() ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
				++$read;
				if ( ! $private || ! str_starts_with( (string) $row['option_name'], 'siteferry_' ) ) {
					++$rows;
					$budget->spend( $writer->add( $this->format_row( $logical, $row, $kinds ) ) );
				}
				if ( $pk ) {
					$cursor['last'] = array_map( static fn( $col ) => $row[ $col ], $pk );
				} else {
					++$cursor['offset'];
				}
				if ( $budget->exhausted() ) {
					$cut = true;
					break;
				}
			}
			$result->free();
			if ( ! $cut && $read < $page ) {
				$done = true;
			}
		} while ( ! $done && ! $budget->exhausted() );

		$writer->flush();

		return array(
			'cursor' => $done ? null : $cursor,
			'rows'   => $rows,
			'fks'    => $fks,
		);
	}

	/**
	 * Rows per page based on the average row size.
	 *
	 * @param string $table Table.
	 */
	private function page_size( string $table ): int {
		$avg = (int) $this->db->value(
			'SELECT AVG_ROW_LENGTH FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ' . $this->db->quote( $table )
		);
		return max( 1, min( 1000, intdiv( self::PAGE_BYTES, max( 1, $avg ) ) ) );
	}

	/**
	 * SELECT for the next page.
	 *
	 * @param string                                  $table   Table.
	 * @param list<array{name: string, kind: string}> $columns Columns.
	 * @param list<string>                            $pk      Primary key.
	 * @param array                                   $cursor  Position.
	 * @param int                                     $page    Rows.
	 */
	private function select( string $table, array $columns, array $pk, array $cursor, int $page ): string {
		$exprs = array();
		$kinds = array();
		foreach ( $columns as $col ) {
			$name                  = Db::ident( $col['name'] );
			$kinds[ $col['name'] ] = $col['kind'];
			$exprs[]               = match ( $col['kind'] ) {
				'binary' => "HEX($name) AS $name",
				'bit'    => "CAST($name AS UNSIGNED) AS $name",
				default  => $name,
			};
		}
		$sql = 'SELECT ' . implode( ',', $exprs ) . ' FROM ' . Db::ident( $table );

		if ( ! $pk ) {
			// ponytail: without a primary key it paginates by offset (InnoDB scans in the same order); slow on huge tables without a PK.
			return $sql . ' LIMIT ' . (int) $cursor['offset'] . ', ' . $page;
		}
		$cols = '(' . implode( ',', array_map( array( Db::class, 'ident' ), $pk ) ) . ')';
		if ( null !== $cursor['last'] ) {
			$values = array();
			foreach ( $pk as $i => $col ) {
				$values[] = $this->literal( $cursor['last'][ $i ], $kinds[ $col ] );
			}
			$sql .= " WHERE $cols > (" . implode( ',', $values ) . ')';
		}
		return $sql . ' ORDER BY ' . implode( ',', array_map( array( Db::class, 'ident' ), $pk ) ) . ' LIMIT ' . $page;
	}

	/**
	 * SQL literals of a row, with replacements applied.
	 *
	 * @param string                 $logical Table name on the source.
	 * @param array<string, ?string> $row     Row.
	 * @param array<string, string>  $kinds   Type per column.
	 * @return list<string>
	 */
	private function format_row( string $logical, array $row, array $kinds ): array {
		$out = array();
		foreach ( $row as $col => $value ) {
			if ( null !== $value && 'text' === $kinds[ $col ] ) {
				if ( $this->replacer && ( 'guid' !== $col || $this->replace_guids ) ) {
					$value = $this->replacer->value( $value );
				}
				$value = $this->rewrite_prefix( $logical, $col, $value );
			}
			$out[] = $this->literal( $value, $kinds[ $col ] );
		}
		return $out;
	}

	/**
	 * Keys that carry the table prefix inside the data (capabilities and roles).
	 *
	 * @param string $logical Table on the source.
	 * @param string $col     Column.
	 * @param string $value   Value.
	 */
	private function rewrite_prefix( string $logical, string $col, string $value ): string {
		if ( $this->prefix_from === $this->prefix_to || '' === $this->prefix_from ) {
			return $value;
		}
		$from = $this->prefix_from;
		if ( $from . 'usermeta' === $logical && 'meta_key' === $col && str_starts_with( $value, $from ) ) {
			return $this->prefix_to . substr( $value, strlen( $from ) );
		}
		if ( $from . 'options' === $logical && 'option_name' === $col && $from . 'user_roles' === $value ) {
			return $this->prefix_to . 'user_roles';
		}
		return $value;
	}

	/**
	 * Value as an SQL literal.
	 *
	 * @param ?string $value Value (binaries arrive as hexadecimal).
	 * @param string  $kind  Simplified type.
	 */
	private function literal( ?string $value, string $kind ): string {
		if ( null === $value ) {
			return 'NULL';
		}
		return match ( $kind ) {
			'number', 'bit' => $value,
			'binary'        => '' === $value ? "''" : '0x' . $value,
			default         => $this->db->quote( $value ),
		};
	}
}

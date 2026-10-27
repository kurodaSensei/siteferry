<?php
/**
 * Direct MySQL access.
 *
 * @package Siteferry
 */

namespace Siteferry\Support;

/**
 * Thin wrapper around mysqli. In WordPress it uses the same connection as $wpdb.
 */
final class Db {

	/**
	 * Constructor.
	 *
	 * @param \mysqli $link Open connection.
	 */
	public function __construct( private \mysqli $link ) {}

	/**
	 * Uses the WordPress connection.
	 *
	 * @param \wpdb $wpdb Global instance.
	 */
	public static function from_wpdb( \wpdb $wpdb ): self {
		return new self( $wpdb->dbh );
	}

	/**
	 * Runs a statement.
	 *
	 * @param string $sql SQL.
	 *
	 * @throws \RuntimeException If MySQL returns an error.
	 */
	public function query( string $sql ): \mysqli_result|bool {
		$result = $this->link->query( $sql );
		if ( false === $result ) {
			throw new \RuntimeException( sprintf( 'MySQL %d: %s [%s]', $this->link->errno, $this->link->error, substr( $sql, 0, 200 ) ) );
		}
		return $result;
	}

	/**
	 * Rows as associative arrays.
	 *
	 * @param string $sql SQL.
	 * @return list<array<string, ?string>>
	 */
	public function rows( string $sql ): array {
		$result = $this->query( $sql );
		$rows   = $result->fetch_all( MYSQLI_ASSOC );
		$result->free();
		return $rows;
	}

	/**
	 * First column of the first row.
	 *
	 * @param string $sql SQL.
	 */
	public function value( string $sql ): ?string {
		$result = $this->query( $sql );
		$row    = $result->fetch_row();
		$result->free();
		return $row[0] ?? null;
	}

	/**
	 * Escaped string literal. Newlines stay as \n, so a value
	 * never spans more than one line in the dump.
	 *
	 * @param string $value Value.
	 */
	public function quote( string $value ): string {
		return "'" . $this->link->real_escape_string( $value ) . "'";
	}

	/**
	 * Identifier wrapped in backticks.
	 *
	 * @param string $name Table or column.
	 */
	public static function ident( string $name ): string {
		return '`' . str_replace( '`', '``', $name ) . '`';
	}

	/**
	 * Runs $work with the session set up for migration, then restores it.
	 *
	 * @param callable $work Work to run.
	 */
	public function in_migration_session( callable $work ): mixed {
		$charset = $this->link->character_set_name();
		$mode    = (string) $this->value( 'SELECT @@SESSION.sql_mode' );
		$fk      = (int) $this->value( 'SELECT @@SESSION.foreign_key_checks' );

		$this->link->set_charset( 'utf8mb4' );
		// Without NO_AUTO_VALUE_ON_ZERO, a row with ID 0 is renumbered on insert.
		$this->query( "SET SESSION sql_mode = 'NO_AUTO_VALUE_ON_ZERO', SESSION foreign_key_checks = 0" );
		try {
			return $work();
		} finally {
			$this->link->set_charset( $charset );
			$this->query( 'SET SESSION sql_mode = ' . $this->quote( $mode ) . ', SESSION foreign_key_checks = ' . $fk );
		}
	}
}

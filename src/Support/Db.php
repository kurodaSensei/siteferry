<?php
/**
 * Acceso directo a MySQL.
 *
 * @package Siteferry
 */

namespace Siteferry\Support;

/**
 * Envoltura mínima sobre mysqli. En WordPress usa la misma conexión que $wpdb.
 */
final class Db {

	/**
	 * Constructor.
	 *
	 * @param \mysqli $link Conexión abierta.
	 */
	public function __construct( private \mysqli $link ) {}

	/**
	 * Usa la conexión de WordPress.
	 *
	 * @param \wpdb $wpdb Instancia global.
	 */
	public static function from_wpdb( \wpdb $wpdb ): self {
		return new self( $wpdb->dbh );
	}

	/**
	 * Ejecuta una sentencia.
	 *
	 * @param string $sql SQL.
	 *
	 * @throws \RuntimeException Si MySQL devuelve error.
	 */
	public function query( string $sql ): \mysqli_result|bool {
		$result = $this->link->query( $sql );
		if ( false === $result ) {
			throw new \RuntimeException( sprintf( 'MySQL %d: %s [%s]', $this->link->errno, $this->link->error, substr( $sql, 0, 200 ) ) );
		}
		return $result;
	}

	/**
	 * Filas como arrays asociativos.
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
	 * Primera columna de la primera fila.
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
	 * Literal de texto escapado. Los saltos de línea quedan como \n, así que un valor
	 * nunca ocupa más de una línea en el dump.
	 *
	 * @param string $value Valor.
	 */
	public function quote( string $value ): string {
		return "'" . $this->link->real_escape_string( $value ) . "'";
	}

	/**
	 * Identificador entre backticks.
	 *
	 * @param string $name Tabla o columna.
	 */
	public static function ident( string $name ): string {
		return '`' . str_replace( '`', '``', $name ) . '`';
	}

	/**
	 * Ejecuta $work con la sesión preparada para migrar y la deja como estaba.
	 *
	 * @param callable $work Trabajo.
	 */
	public function in_migration_session( callable $work ): mixed {
		$charset = $this->link->character_set_name();
		$mode    = (string) $this->value( 'SELECT @@SESSION.sql_mode' );
		$fk      = (int) $this->value( 'SELECT @@SESSION.foreign_key_checks' );

		$this->link->set_charset( 'utf8mb4' );
		// Sin NO_AUTO_VALUE_ON_ZERO, una fila con ID 0 se renumera al insertarla.
		$this->query( "SET SESSION sql_mode = 'NO_AUTO_VALUE_ON_ZERO', SESSION foreign_key_checks = 0" );
		try {
			return $work();
		} finally {
			$this->link->set_charset( $charset );
			$this->query( 'SET SESSION sql_mode = ' . $this->quote( $mode ) . ', SESSION foreign_key_checks = ' . $fk );
		}
	}
}

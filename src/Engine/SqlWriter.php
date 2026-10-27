<?php
/**
 * Agrupa filas en INSERTs multi-fila.
 *
 * @package Siteferry
 */

namespace Siteferry\Engine;

use Siteferry\Support\Db;

/**
 * Construye `INSERT INTO t (cols) VALUES (...),(...)` sin pasar de $max_bytes por sentencia.
 * Una fila más grande que el límite va sola en su sentencia.
 */
final class SqlWriter {

	/**
	 * Inicio del INSERT.
	 *
	 * @var string
	 */
	private string $head;

	/**
	 * Tuplas pendientes.
	 *
	 * @var list<string>
	 */
	private array $tuples = array();

	/**
	 * Bytes pendientes.
	 *
	 * @var int
	 */
	private int $size = 0;

	/**
	 * Constructor.
	 *
	 * @param Sink         $sink      Destino.
	 * @param string       $table     Tabla destino.
	 * @param list<string> $columns   Nombres de columna.
	 * @param int          $max_bytes Tamaño máximo de una sentencia.
	 */
	public function __construct( private Sink $sink, string $table, array $columns, private int $max_bytes ) {
		$this->head = 'INSERT INTO ' . Db::ident( $table ) . ' (' . implode( ',', array_map( array( Db::class, 'ident' ), $columns ) ) . ') VALUES ';
	}

	/**
	 * Agrega una fila ya formateada como literales SQL.
	 *
	 * @param list<string> $values Literales.
	 * @return int Bytes de la tupla.
	 */
	public function add( array $values ): int {
		$tuple = '(' . implode( ',', $values ) . ')';
		if ( $this->tuples && strlen( $this->head ) + $this->size + strlen( $tuple ) > $this->max_bytes ) {
			$this->flush();
		}
		$this->tuples[] = $tuple;
		$this->size    += strlen( $tuple ) + 1;
		return strlen( $tuple );
	}

	/**
	 * Envía lo pendiente.
	 */
	public function flush(): void {
		if ( $this->tuples ) {
			$this->sink->write( $this->head . implode( ',', $this->tuples ) );
			$this->tuples = array();
			$this->size   = 0;
		}
	}
}

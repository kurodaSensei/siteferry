<?php
/**
 * Groups rows into multi-row INSERTs.
 *
 * @package Siteferry
 */

namespace Siteferry\Engine;

use Siteferry\Support\Db;

/**
 * Builds `INSERT INTO t (cols) VALUES (...),(...)` without exceeding $max_bytes per statement.
 * A row larger than the limit goes alone in its own statement.
 */
final class SqlWriter {

	/**
	 * Start of the INSERT.
	 *
	 * @var string
	 */
	private string $head;

	/**
	 * Pending tuples.
	 *
	 * @var list<string>
	 */
	private array $tuples = array();

	/**
	 * Pending bytes.
	 *
	 * @var int
	 */
	private int $size = 0;

	/**
	 * Constructor.
	 *
	 * @param Sink         $sink      Destination.
	 * @param string       $table     Destination table.
	 * @param list<string> $columns   Column names.
	 * @param int          $max_bytes Maximum size of a statement.
	 */
	public function __construct( private Sink $sink, string $table, array $columns, private int $max_bytes ) {
		$this->head = 'INSERT INTO ' . Db::ident( $table ) . ' (' . implode( ',', array_map( array( Db::class, 'ident' ), $columns ) ) . ') VALUES ';
	}

	/**
	 * Adds a row already formatted as SQL literals.
	 *
	 * @param list<string> $values Literals.
	 * @return int Bytes of the tuple.
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
	 * Sends what is pending.
	 */
	public function flush(): void {
		if ( $this->tuples ) {
			$this->sink->write( $this->head . implode( ',', $this->tuples ) );
			$this->tuples = array();
			$this->size   = 0;
		}
	}
}

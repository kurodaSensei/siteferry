<?php
/**
 * Runs the statements against the database.
 *
 * @package Siteferry
 */

namespace Siteferry\Engine;

use Siteferry\Support\Db;

/**
 * Sink that runs each statement (to fill temporary tables).
 */
final class TableSink implements Sink {

	/**
	 * Constructor.
	 *
	 * @param Db $db Connection.
	 */
	public function __construct( private Db $db ) {}

	/**
	 * Runs the statement.
	 *
	 * @param string $statement SQL.
	 */
	public function write( string $statement ): void {
		$this->db->query( $statement );
	}
}

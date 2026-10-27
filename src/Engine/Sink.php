<?php
/**
 * Destination for SQL statements.
 *
 * @package Siteferry
 */

namespace Siteferry\Engine;

/**
 * Receives complete SQL statements (without the trailing ";").
 */
interface Sink {

	/**
	 * Writes or runs a statement.
	 *
	 * @param string $statement SQL.
	 */
	public function write( string $statement ): void;
}

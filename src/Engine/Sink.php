<?php
/**
 * Destino de sentencias SQL.
 *
 * @package Siteferry
 */

namespace Siteferry\Engine;

/**
 * Recibe sentencias SQL completas (sin el ";" final).
 */
interface Sink {

	/**
	 * Escribe o ejecuta una sentencia.
	 *
	 * @param string $statement SQL.
	 */
	public function write( string $statement ): void;
}

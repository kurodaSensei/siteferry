<?php
/**
 * Ejecuta las sentencias en la base de datos.
 *
 * @package Siteferry
 */

namespace Siteferry\Engine;

use Siteferry\Support\Db;

/**
 * Sink que ejecuta cada sentencia (para llenar tablas temporales).
 */
final class TableSink implements Sink {

	/**
	 * Constructor.
	 *
	 * @param Db $db Conexión.
	 */
	public function __construct( private Db $db ) {}

	/**
	 * Ejecuta la sentencia.
	 *
	 * @param string $statement SQL.
	 */
	public function write( string $statement ): void {
		$this->db->query( $statement );
	}
}

<?php
/**
 * Cambia las tablas temporales por las vivas.
 *
 * @package Siteferry
 */

namespace Siteferry\Engine;

use Siteferry\Support\Db;

/**
 * Último paso: preserva opciones, hace el cambio atómico y recrea las llaves foráneas.
 */
final class Finalizer {

	/**
	 * Constructor.
	 *
	 * @param Db     $db     Conexión.
	 * @param Schema $schema Estructura.
	 */
	public function __construct( private Db $db, private Schema $schema ) {}

	/**
	 * Ejecuta el cambio.
	 *
	 * @param array<string, string>                      $swap          Tabla temporal => tabla final.
	 * @param list<array{table: string, clause: string}> $fks           Llaves foráneas diferidas (tabla final + cláusula).
	 * @param array<string, string>                      $ref_map       Nombre en el origen => nombre final (para REFERENCES).
	 * @param string                                     $options_table Tabla de opciones del sitio.
	 * @param list<string>                               $preserve      Opciones a conservar además de las siteferry_*.
	 * @param string                                     $plugin        Basename del plugin, que debe quedar activo.
	 * @return list<string> Advertencias.
	 */
	public function run( array $swap, array $fks, array $ref_map, string $options_table, array $preserve, string $plugin ): array {
		$tmp_options = array_search( $options_table, $swap, true );
		if ( false !== $tmp_options && $this->schema->exists( $options_table ) ) {
			$this->preserve_options( $options_table, (string) $tmp_options, $preserve, $plugin );
		}

		$renames = array();
		$old     = array();
		foreach ( $swap as $tmp => $final ) {
			if ( $this->schema->exists( $final ) ) {
				$this->db->query( 'DROP TABLE IF EXISTS ' . Db::ident( Schema::OLD . $final ) );
				$renames[] = Db::ident( $final ) . ' TO ' . Db::ident( Schema::OLD . $final );
				$old[]     = Db::ident( Schema::OLD . $final );
			}
			$renames[] = Db::ident( $tmp ) . ' TO ' . Db::ident( $final );
		}
		if ( $renames ) {
			// Una sola sentencia: o cambian todas las tablas o ninguna.
			$this->db->query( 'RENAME TABLE ' . implode( ', ', $renames ) );
		}
		if ( $old ) {
			$this->db->query( 'DROP TABLE IF EXISTS ' . implode( ', ', $old ) );
		}

		// ponytail: una llave foránea de una tabla que NO se migra hacia una que sí queda apuntando a la tabla
		// vieja borrada; WordPress core no tiene llaves foráneas.
		$warnings = array();
		foreach ( $fks as $fk ) {
			$clause = preg_replace_callback(
				'/REFERENCES\s+`((?:[^`]|``)+)`/i',
				static fn( $m ) => 'REFERENCES ' . Db::ident( $ref_map[ str_replace( '``', '`', $m[1] ) ] ?? str_replace( '``', '`', $m[1] ) ),
				$fk['clause']
			);
			try {
				$this->db->query( 'ALTER TABLE ' . Db::ident( $fk['table'] ) . ' ADD ' . $clause );
			} catch ( \RuntimeException $e ) {
				$warnings[] = 'No se pudo recrear la llave foránea en ' . $fk['table'] . ': ' . $e->getMessage();
			}
		}
		return $warnings;
	}

	/**
	 * Copia a la tabla de opciones nueva las opciones que no deben cambiar.
	 *
	 * @param string       $live     Tabla viva.
	 * @param string       $tmp      Tabla nueva.
	 * @param list<string> $preserve Nombres extra.
	 * @param string       $plugin   Basename del plugin.
	 */
	private function preserve_options( string $live, string $tmp, array $preserve, string $plugin ): void {
		$names = $preserve ? ' OR option_name IN (' . implode( ',', array_map( array( $this->db, 'quote' ), $preserve ) ) . ')' : '';
		$rows  = $this->db->rows( 'SELECT option_name, option_value, autoload FROM ' . Db::ident( $live ) . " WHERE option_name LIKE 'siteferry\\_%'" . $names );

		$this->db->query( 'DELETE FROM ' . Db::ident( $tmp ) . " WHERE option_name LIKE 'siteferry\\_%'" );
		foreach ( $rows as $row ) {
			$this->db->query(
				'INSERT INTO ' . Db::ident( $tmp ) . ' (option_name, option_value, autoload) VALUES ('
				. $this->db->quote( (string) $row['option_name'] ) . ',' . $this->db->quote( (string) $row['option_value'] ) . ',' . $this->db->quote( (string) $row['autoload'] )
				. ') ON DUPLICATE KEY UPDATE option_value = VALUES(option_value), autoload = VALUES(autoload)'
			);
		}

		$active  = $this->db->value( 'SELECT option_value FROM ' . Db::ident( $tmp ) . " WHERE option_name = 'active_plugins'" );
		$plugins = null === $active ? array() : unserialize( $active, array( 'allowed_classes' => false ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize
		$plugins = is_array( $plugins ) ? $plugins : array();
		if ( ! in_array( $plugin, $plugins, true ) ) {
			$plugins[] = $plugin;
			$value     = $this->db->quote( serialize( array_values( $plugins ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
			$this->db->query(
				'INSERT INTO ' . Db::ident( $tmp ) . " (option_name, option_value, autoload) VALUES ('active_plugins', $value, 'yes')"
				. ' ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)'
			);
		}
	}
}

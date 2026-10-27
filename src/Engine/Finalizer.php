<?php
/**
 * Swaps the temporary tables in for the live ones.
 *
 * @package Siteferry
 */

namespace Siteferry\Engine;

use Siteferry\Support\Db;

/**
 * Final step: preserves options, does the atomic swap and recreates the foreign keys.
 */
final class Finalizer {

	/**
	 * Constructor.
	 *
	 * @param Db     $db     Connection.
	 * @param Schema $schema Structure.
	 */
	public function __construct( private Db $db, private Schema $schema ) {}

	/**
	 * Runs the swap.
	 *
	 * @param array<string, string>                      $swap          Temporary table => final table.
	 * @param list<array{table: string, clause: string}> $fks           Deferred foreign keys (final table + clause).
	 * @param array<string, string>                      $ref_map       Source name => final name (for REFERENCES).
	 * @param string                                     $options_table Site options table.
	 * @param list<string>                               $preserve      Options to keep in addition to the siteferry_* ones.
	 * @param string                                     $plugin        Plugin basename, which must stay active.
	 * @return list<string> Warnings.
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
			// A single statement: either all tables are swapped or none are.
			$this->db->query( 'RENAME TABLE ' . implode( ', ', $renames ) );
		}
		if ( $old ) {
			$this->db->query( 'DROP TABLE IF EXISTS ' . implode( ', ', $old ) );
		}

		// ponytail: a foreign key from a table that is NOT migrated to one that is ends up pointing at the dropped
		// old table; WordPress core has no foreign keys.
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
				$warnings[] = 'Could not recreate the foreign key on ' . $fk['table'] . ': ' . $e->getMessage();
			}
		}
		return $warnings;
	}

	/**
	 * Copies into the new options table the options that must not change.
	 *
	 * @param string       $live     Live table.
	 * @param string       $tmp      New table.
	 * @param list<string> $preserve Extra names.
	 * @param string       $plugin   Plugin basename.
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

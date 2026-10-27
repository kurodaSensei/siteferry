<?php
/**
 * Copia una tabla por lotes, reemplazando en el camino.
 *
 * @package Siteferry
 */

namespace Siteferry\Engine;

use Siteferry\Support\Db;

/**
 * Lee filas de una tabla en orden de clave primaria (o por offset si no tiene),
 * aplica el Replacer a las columnas de texto y las escribe como INSERTs en un Sink.
 */
final class TableCopier {

	/** Memoria aproximada para una página de filas. */
	private const PAGE_BYTES = 16 * 1024 * 1024;

	/**
	 * Constructor.
	 *
	 * @param Db            $db            Conexión.
	 * @param Schema        $schema        Estructura.
	 * @param Replacer|null $replacer      Reemplazos (null = copia literal).
	 * @param int           $max_bytes     Tamaño máximo de un INSERT.
	 * @param bool          $replace_guids Reemplazar también en la columna guid.
	 * @param string        $prefix_from   Prefijo de tablas del origen.
	 * @param string        $prefix_to     Prefijo de tablas del destino.
	 * @param string        $options_table Tabla de opciones (nombre en el origen) cuyas filas siteferry_* no se copian:
	 *                                     llevan claves del plugin y se conservan aparte al finalizar.
	 */
	public function __construct(
		private Db $db,
		private Schema $schema,
		private ?Replacer $replacer,
		private int $max_bytes = 512 * 1024,
		private bool $replace_guids = false,
		private string $prefix_from = '',
		private string $prefix_to = '',
		private string $options_table = ''
	) {}

	/**
	 * Copia un tramo de $source a $target.
	 *
	 * @param string     $source    Tabla de origen.
	 * @param string     $target    Tabla destino (nombre usado en el SQL).
	 * @param string     $logical   Nombre de la tabla en el sitio de origen (sin prefijo temporal).
	 * @param Sink       $sink      Destino de las sentencias.
	 * @param array|null $cursor    Null para empezar: emite DROP + CREATE.
	 * @param Budget     $budget    Tiempo disponible.
	 * @param bool       $defer_fks Quitar las llaves foráneas del CREATE y devolverlas.
	 * @return array{cursor: ?array, rows: int, fks: list<string>} cursor null = tabla terminada.
	 */
	public function run( string $source, string $target, string $logical, Sink $sink, ?array $cursor, Budget $budget, bool $defer_fks ): array {
		$columns = $this->schema->columns( $source );
		$private = $logical === $this->options_table;
		$pk      = $this->schema->primary_key( $source );
		$kinds   = array_column( $columns, 'kind', 'name' );
		$fks     = array();

		if ( null === $cursor ) {
			[ $create, $fks ] = $this->schema->create_sql( $source, $target, $defer_fks );
			$sink->write( 'DROP TABLE IF EXISTS ' . Db::ident( $target ) );
			$sink->write( $create );
			$cursor = array(
				'last'   => null,
				'offset' => 0,
			);
		}

		$writer = new SqlWriter( $sink, $target, array_column( $columns, 'name' ), $this->max_bytes );
		$page   = $this->page_size( $source );
		$rows   = 0;
		$done   = false;

		do {
			$result = $this->db->query( $this->select( $source, $columns, $pk, $cursor, $page ) );
			$read   = 0;
			$cut    = false;
			while ( $row = $result->fetch_assoc() ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
				++$read;
				if ( ! $private || ! str_starts_with( (string) $row['option_name'], 'siteferry_' ) ) {
					++$rows;
					$budget->spend( $writer->add( $this->format_row( $logical, $row, $kinds ) ) );
				}
				if ( $pk ) {
					$cursor['last'] = array_map( static fn( $col ) => $row[ $col ], $pk );
				} else {
					++$cursor['offset'];
				}
				if ( $budget->exhausted() ) {
					$cut = true;
					break;
				}
			}
			$result->free();
			if ( ! $cut && $read < $page ) {
				$done = true;
			}
		} while ( ! $done && ! $budget->exhausted() );

		$writer->flush();

		return array(
			'cursor' => $done ? null : $cursor,
			'rows'   => $rows,
			'fks'    => $fks,
		);
	}

	/**
	 * Filas por página según el tamaño medio de fila.
	 *
	 * @param string $table Tabla.
	 */
	private function page_size( string $table ): int {
		$avg = (int) $this->db->value(
			'SELECT AVG_ROW_LENGTH FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ' . $this->db->quote( $table )
		);
		return max( 1, min( 1000, intdiv( self::PAGE_BYTES, max( 1, $avg ) ) ) );
	}

	/**
	 * SELECT de la siguiente página.
	 *
	 * @param string                                  $table   Tabla.
	 * @param list<array{name: string, kind: string}> $columns Columnas.
	 * @param list<string>                            $pk      Clave primaria.
	 * @param array                                   $cursor  Posición.
	 * @param int                                     $page    Filas.
	 */
	private function select( string $table, array $columns, array $pk, array $cursor, int $page ): string {
		$exprs = array();
		$kinds = array();
		foreach ( $columns as $col ) {
			$name                  = Db::ident( $col['name'] );
			$kinds[ $col['name'] ] = $col['kind'];
			$exprs[]               = match ( $col['kind'] ) {
				'binary' => "HEX($name) AS $name",
				'bit'    => "CAST($name AS UNSIGNED) AS $name",
				default  => $name,
			};
		}
		$sql = 'SELECT ' . implode( ',', $exprs ) . ' FROM ' . Db::ident( $table );

		if ( ! $pk ) {
			// ponytail: sin clave primaria se pagina por offset (InnoDB recorre en el mismo orden); lento en tablas enormes sin PK.
			return $sql . ' LIMIT ' . (int) $cursor['offset'] . ', ' . $page;
		}
		$cols = '(' . implode( ',', array_map( array( Db::class, 'ident' ), $pk ) ) . ')';
		if ( null !== $cursor['last'] ) {
			$values = array();
			foreach ( $pk as $i => $col ) {
				$values[] = $this->literal( $cursor['last'][ $i ], $kinds[ $col ] );
			}
			$sql .= " WHERE $cols > (" . implode( ',', $values ) . ')';
		}
		return $sql . ' ORDER BY ' . implode( ',', array_map( array( Db::class, 'ident' ), $pk ) ) . ' LIMIT ' . $page;
	}

	/**
	 * Literales SQL de una fila, con reemplazos aplicados.
	 *
	 * @param string                 $logical Nombre de la tabla en el origen.
	 * @param array<string, ?string> $row     Fila.
	 * @param array<string, string>  $kinds   Tipo por columna.
	 * @return list<string>
	 */
	private function format_row( string $logical, array $row, array $kinds ): array {
		$out = array();
		foreach ( $row as $col => $value ) {
			if ( null !== $value && 'text' === $kinds[ $col ] ) {
				if ( $this->replacer && ( 'guid' !== $col || $this->replace_guids ) ) {
					$value = $this->replacer->value( $value );
				}
				$value = $this->rewrite_prefix( $logical, $col, $value );
			}
			$out[] = $this->literal( $value, $kinds[ $col ] );
		}
		return $out;
	}

	/**
	 * Claves que llevan el prefijo de tablas dentro de los datos (capacidades y roles).
	 *
	 * @param string $logical Tabla en el origen.
	 * @param string $col     Columna.
	 * @param string $value   Valor.
	 */
	private function rewrite_prefix( string $logical, string $col, string $value ): string {
		if ( $this->prefix_from === $this->prefix_to || '' === $this->prefix_from ) {
			return $value;
		}
		$from = $this->prefix_from;
		if ( $from . 'usermeta' === $logical && 'meta_key' === $col && str_starts_with( $value, $from ) ) {
			return $this->prefix_to . substr( $value, strlen( $from ) );
		}
		if ( $from . 'options' === $logical && 'option_name' === $col && $from . 'user_roles' === $value ) {
			return $this->prefix_to . 'user_roles';
		}
		return $value;
	}

	/**
	 * Valor como literal SQL.
	 *
	 * @param ?string $value Valor (los binarios llegan en hexadecimal).
	 * @param string  $kind  Tipo simplificado.
	 */
	private function literal( ?string $value, string $kind ): string {
		if ( null === $value ) {
			return 'NULL';
		}
		return match ( $kind ) {
			'number', 'bit' => $value,
			'binary'        => '' === $value ? "''" : '0x' . $value,
			default         => $this->db->quote( $value ),
		};
	}
}

<?php
/**
 * Información de tablas y columnas.
 *
 * @package Siteferry
 */

namespace Siteferry\Engine;

use Siteferry\Support\Db;

/**
 * Lee la estructura de las tablas que se migran.
 */
final class Schema {

	/** Tablas nuevas que reemplazarán a las vivas. */
	public const TMP = '_sft_';

	/** Tablas crudas cargadas desde un .sql. */
	public const RAW = '_sfr_';

	/** Tablas viejas durante el cambio. */
	public const OLD = '_sfo_';

	/** Cláusula de llave foránea tal como la escribe SHOW CREATE TABLE, y nada más. */
	private const FOREIGN_KEY = '/^CONSTRAINT `([^`]{1,64})` FOREIGN KEY \((`[^`]{1,64}`(?:, ?`[^`]{1,64}`)*)\) REFERENCES `([^`]{1,64})` \((`[^`]{1,64}`(?:, ?`[^`]{1,64}`)*)\)((?: ON (?:DELETE|UPDATE) (?:RESTRICT|CASCADE|SET NULL|NO ACTION|SET DEFAULT))*)$/i';

	/** Largo máximo de un nombre de tabla para que quepa un prefijo temporal. */
	public const MAX_NAME = 64 - 5;

	/**
	 * Constructor.
	 *
	 * @param Db $db Conexión.
	 */
	public function __construct( private Db $db ) {}

	/**
	 * Si el nombre es de una tabla temporal del plugin.
	 *
	 * @param string $name Tabla.
	 */
	public static function is_temp( string $name ): bool {
		return str_starts_with( $name, self::TMP ) || str_starts_with( $name, self::RAW ) || str_starts_with( $name, self::OLD );
	}

	/**
	 * Tablas base (sin vistas) con filas estimadas y tamaño.
	 *
	 * @param string $prefix Solo las que empiezan así (las temporales empiezan por "_lf").
	 * @return array<string, array{rows: int, bytes: int, avg: int}>
	 */
	public function stats( string $prefix = '' ): array {
		$rows = $this->db->rows(
			"SELECT TABLE_NAME AS name, TABLE_ROWS AS row_count, DATA_LENGTH + INDEX_LENGTH AS bytes, AVG_ROW_LENGTH AS avg
			FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME"
		);
		$out  = array();
		foreach ( $rows as $row ) {
			$name = (string) $row['name'];
			if ( str_starts_with( $name, $prefix ) ) {
				$out[ $name ] = array(
					'rows'  => (int) $row['row_count'],
					'bytes' => (int) $row['bytes'],
					'avg'   => (int) $row['avg'],
				);
			}
		}
		return $out;
	}

	/**
	 * Si existe la tabla.
	 *
	 * @param string $name Tabla.
	 */
	public function exists( string $name ): bool {
		return null !== $this->db->value(
			'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ' . $this->db->quote( $name )
		);
	}

	/**
	 * Tablas temporales del plugin que existan.
	 *
	 * @return list<string>
	 */
	public function temp_tables(): array {
		$names = array_column( $this->db->rows( 'SELECT TABLE_NAME AS name FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()' ), 'name' );
		return array_values( array_filter( $names, array( self::class, 'is_temp' ) ) );
	}

	/**
	 * Columnas con su tipo simplificado.
	 *
	 * @param string $table Tabla.
	 * @return list<array{name: string, kind: string}> kind: number | bit | binary | text | other.
	 */
	public function columns( string $table ): array {
		$out = array();
		foreach ( $this->db->rows( 'SHOW FULL COLUMNS FROM ' . Db::ident( $table ) ) as $row ) {
			$out[] = array(
				'name' => (string) $row['Field'],
				'kind' => self::kind( (string) $row['Type'] ),
			);
		}
		return $out;
	}

	/**
	 * Columnas de la clave primaria, en orden.
	 *
	 * @param string $table Tabla.
	 * @return list<string>
	 */
	public function primary_key( string $table ): array {
		$rows = $this->db->rows( 'SHOW KEYS FROM ' . Db::ident( $table ) . " WHERE Key_name = 'PRIMARY'" );
		usort( $rows, static fn( $a, $b ) => (int) $a['Seq_in_index'] <=> (int) $b['Seq_in_index'] );
		return array_column( $rows, 'Column_name' );
	}

	/**
	 * CREATE TABLE de $source con el nombre $target, en una sola línea.
	 *
	 * @param string $source     Tabla de origen.
	 * @param string $target     Nombre para el CREATE.
	 * @param bool   $defer_fks  Quitar las llaves foráneas y devolverlas aparte.
	 * @return array{0: string, 1: list<string>} [CREATE, cláusulas FOREIGN KEY quitadas].
	 */
	public function create_sql( string $source, string $target, bool $defer_fks ): array {
		$rows   = $this->db->rows( 'SHOW CREATE TABLE ' . Db::ident( $source ) );
		$create = (string) $rows[0]['Create Table'];
		$create = preg_replace( '/^CREATE TABLE `(?:[^`]|``)+`/', 'CREATE TABLE ' . str_replace( '\\', '\\\\', Db::ident( $target ) ), $create, 1 );
		return self::split_foreign_keys( (string) $create, $defer_fks );
	}

	/**
	 * Quita las líneas FOREIGN KEY de un CREATE TABLE con formato de una definición por línea
	 * (el de SHOW CREATE TABLE y mysqldump) y lo deja en una sola línea.
	 *
	 * @param string $create CREATE TABLE.
	 * @param bool   $remove Quitar las llaves foráneas.
	 * @return array{0: string, 1: list<string>}
	 */
	public static function split_foreign_keys( string $create, bool $remove = true ): array {
		// ponytail: si el CREATE viene en una sola línea, las llaves foráneas no se separan.
		$keep = array();
		$fks  = array();
		foreach ( preg_split( '/\R/', trim( $create ) ) as $line ) {
			$trimmed = trim( $line );
			if ( $remove && preg_match( '/^(CONSTRAINT\s.+\s)?FOREIGN KEY\s/i', $trimmed ) ) {
				$fks[] = rtrim( $trimmed, ',' );
				continue;
			}
			$keep[] = $trimmed;
		}
		$count = count( $keep );
		for ( $i = 0; $i < $count - 1; $i++ ) {
			if ( str_starts_with( $keep[ $i + 1 ], ')' ) ) {
				$keep[ $i ] = rtrim( $keep[ $i ], ',' );
			}
		}
		return array( implode( ' ', $keep ), $fks );
	}

	/**
	 * Tipo simplificado a partir del tipo de columna de MySQL.
	 *
	 * @param string $type Tipo, p. ej. "bigint(20) unsigned".
	 */
	private static function kind( string $type ): string {
		$type = strtolower( $type );
		if ( preg_match( '/^(tinyint|smallint|mediumint|int|bigint|integer|decimal|numeric|float|double|real)\b/', $type ) ) {
			return 'number';
		}
		if ( str_starts_with( $type, 'bit' ) ) {
			return 'bit';
		}
		if ( preg_match( '/^(binary|varbinary|tinyblob|blob|mediumblob|longblob|geometry|point|linestring|polygon|multipoint|multilinestring|multipolygon|geometrycollection|geomcollection)\b/', $type ) ) {
			return 'binary';
		}
		if ( preg_match( '/^(char|varchar|tinytext|text|mediumtext|longtext|json)\b/', $type ) ) {
			return 'text';
		}
		return 'other';
	}

	/**
	 * Colaciones que conoce este servidor (se cargan al primer uso).
	 *
	 * @var array<string, int>|null
	 */
	private ?array $collations = null;

	/**
	 * Cambia las colaciones que este servidor no conoce por la más cercana que sí tenga
	 * (p. ej. utf8mb4_0900_ai_ci de MySQL 8 al importar en MariaDB).
	 *
	 * @param string $sql Definición de la tabla.
	 */
	public function known_collations( string $sql ): string {
		$this->collations ??= array_flip( array_column( $this->db->rows( 'SHOW COLLATION' ), 'Collation' ) );
		return (string) preg_replace_callback(
			'/\b(utf8mb4|utf8mb3|utf8)_[a-z0-9_]+\b/i',
			function ( array $m ): string {
				if ( isset( $this->collations[ $m[0] ] ) ) {
					return $m[0];
				}
				$charsets = 'utf8mb4' === strtolower( $m[1] ) ? array( 'utf8mb4' ) : array( 'utf8mb3', 'utf8' );
				foreach ( $charsets as $charset ) {
					foreach ( array( '_unicode_520_ci', '_unicode_ci', '_general_ci' ) as $suffix ) {
						if ( isset( $this->collations[ $charset . $suffix ] ) ) {
							return $charset . $suffix;
						}
					}
				}
				return $m[0];
			},
			$sql
		);
	}

	/**
	 * Valida una llave foránea que llega de otro sitio y la devuelve apuntando a la tabla de
	 * este sitio. Solo acepta la forma exacta de SHOW CREATE TABLE (nada de SQL adicional) y
	 * una tabla referenciada con el prefijo indicado.
	 *
	 * @param string                $clause  Cláusula recibida.
	 * @param array<string, string> $ref_map Nombre en el origen => nombre aquí.
	 * @param string                $prefix  Prefijo de tablas de este sitio.
	 * @return string|null Cláusula segura, o null si no es válida.
	 */
	public static function foreign_key( string $clause, array $ref_map, string $prefix ): ?string {
		if ( ! preg_match( self::FOREIGN_KEY, trim( $clause ), $m ) ) {
			return null;
		}
		$target = (string) ( $ref_map[ $m[3] ] ?? $m[3] );
		if ( '' === $prefix || ! str_starts_with( $target, $prefix ) || self::is_temp( $target ) || ! preg_match( '/^[A-Za-z0-9_$]{1,64}$/', $target ) ) {
			return null;
		}
		return 'CONSTRAINT ' . Db::ident( $m[1] ) . ' FOREIGN KEY (' . $m[2] . ') REFERENCES ' . Db::ident( $target ) . ' (' . $m[4] . ')' . $m[5];
	}

	/**
	 * Si una sentencia que llega de otro sitio es segura de ejecutar: solo puede crear, llenar o
	 * borrar UNA tabla temporal _sft_, y nada en ella puede leer, enlazar o escribir fuera de esa tabla.
	 *
	 * @param string      $sql   Sentencia.
	 * @param string|null $table Tabla exacta esperada (null = cualquier _sft_).
	 */
	public static function safe_temp_statement( string $sql, ?string $table = null ): bool {
		$name = null === $table ? '_sft_[A-Za-z0-9_$]{1,59}' : preg_quote( $table, '/' );
		if ( preg_match( '/^DROP TABLE IF EXISTS `' . $name . '`$/', $sql ) ) {
			return null === $table || str_starts_with( $table, self::TMP );
		}
		if ( ( null !== $table && ! str_starts_with( $table, self::TMP ) ) || ! preg_match( '/^(CREATE TABLE|INSERT INTO) `' . $name . '` \(/', $sql, $m ) ) {
			return false;
		}
		$plain = self::strip_literals( $sql );
		// Ningún comentario fuera de los textos: una comilla dentro de un comentario engañaría al
		// análisis, y /*!…*/ ejecuta su contenido. El copiador nunca escribe comentarios.
		if ( null === $plain || preg_match( '/\/\*|\*\/|#|--/', $plain ) ) {
			return false;
		}
		if ( 'INSERT INTO' === $m[1] ) {
			// Solo VALUES con literales: NULL, número, hexadecimal o texto (ya vaciado). Se valida sin
			// una expresión repetida sobre toda la sentencia: con sentencias de cientos de KB esa
			// expresión agota la pila de PCRE.
			if ( ! preg_match( '/^INSERT INTO `` \(``(?:,``)*\) VALUES /', $plain, $head ) ) {
				return false;
			}
			$shape = preg_replace( "/0x[0-9A-Fa-f]+|NULL|''|-?[0-9]+(?:\\.[0-9]+)?(?:[eE][-+]?[0-9]+)?/", 'L', substr( $plain, strlen( $head[0] ) ) );
			if ( null === $shape || strspn( $shape, 'L(),' ) !== strlen( $shape ) || ! str_starts_with( $shape, '(' ) || ! str_ends_with( $shape, ')' ) ) {
				return false;
			}
			foreach ( explode( '),(', substr( $shape, 1, -1 ) ) as $tuple ) {
				if ( ! preg_match( '/^L(?:,L)*$/', $tuple ) ) {
					return false;
				}
			}
			return true;
		}
		// CREATE: columnas e índices, y después solo las opciones de tabla que escribe SHOW CREATE TABLE,
		// con un único ENGINE de la lista. Nada que lea otras tablas, las enlace o escriba en disco.
		$at = strrpos( $plain, ') ENGINE=' );
		if ( false === $at || 1 !== preg_match_all( '/\\bENGINE\\b/i', $plain ) ) {
			return false;
		}
		$body    = substr( $plain, 0, $at + 1 );
		$options = substr( $plain, $at + 2 );
		$option  = '(?:AUTO_INCREMENT=[0-9]+|(?:DEFAULT )?(?:CHARSET|CHARACTER SET|COLLATE)=[A-Za-z0-9_]+|COMMENT=\'\'|ROW_FORMAT=[A-Za-z]+'
			. '|(?:ENCRYPTION|COMPRESSION)=\'\'|ENCRYPTED=(?:YES|NO)'
			. '|(?:STATS_PERSISTENT|STATS_AUTO_RECALC|STATS_SAMPLE_PAGES|KEY_BLOCK_SIZE|PACK_KEYS|CHECKSUM|DELAY_KEY_WRITE|AVG_ROW_LENGTH|MAX_ROWS|MIN_ROWS'
			. '|PAGE_CHECKSUM|TRANSACTIONAL|PAGE_COMPRESSED|PAGE_COMPRESSION_LEVEL|ENCRYPTION_KEY_ID)=[0-9]+)';
		if ( ! preg_match( "/^ENGINE=(?:InnoDB|MyISAM|Aria|MEMORY)(?: $option)*$/i", $options ) ) {
			return false;
		}
		return ! preg_match( '/\\b(SELECT|REFERENCES|UNION|DIRECTORY|CONNECTION|TABLESPACE|PARTITION)\\b/i', $body );
	}

	/**
	 * La sentencia con cada texto '…', identificador `…` o "…" reducido a sus comillas vacías,
	 * para analizar la estructura sin que el contenido de los valores cuente. Null si una
	 * comilla queda sin cerrar.
	 *
	 * @param string $sql Sentencia.
	 */
	private static function strip_literals( string $sql ): ?string {
		$out = '';
		$len = strlen( $sql );
		$i   = 0;
		while ( $i < $len ) {
			$start = $i + strcspn( $sql, "'\"`", $i );
			$out  .= substr( $sql, $i, $start - $i );
			if ( $start >= $len ) {
				break;
			}
			$quote = $sql[ $start ];
			$stops = '`' === $quote ? '`' : $quote . '\\'; // En identificadores la barra no escapa.
			$k     = $start + 1;
			while ( true ) {
				$k += strcspn( $sql, $stops, $k );
				if ( $k >= $len ) {
					return null;
				}
				if ( '\\' === $sql[ $k ] ) {
					$k += 2;
					continue;
				}
				if ( $k + 1 < $len && $sql[ $k + 1 ] === $quote ) {
					$k += 2; // Comilla doblada dentro del texto.
					continue;
				}
				break;
			}
			$out .= $quote . $quote;
			$i    = $k + 1;
		}
		return $out;
	}
}

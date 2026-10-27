<?php
/**
 * Ejecuta los pasos de un job.
 *
 * @package Siteferry
 */

namespace Siteferry\Job;

use Siteferry\Engine\Budget;
use Siteferry\Engine\FileSink;
use Siteferry\Engine\Finalizer;
use Siteferry\Engine\MemorySink;
use Siteferry\Engine\Replacer;
use Siteferry\Engine\Schema;
use Siteferry\Engine\SqlFileReader;
use Siteferry\Engine\TableCopier;
use Siteferry\Engine\TableSink;
use Siteferry\Files\Applier;
use Siteferry\Files\Diff;
use Siteferry\Files\Manifest;
use Siteferry\Files\Package;
use Siteferry\Files\PathGuard;
use Siteferry\Files\Roots;
use Siteferry\Files\Scanner;
use Siteferry\Files\SitePackage;
use Siteferry\Remote\FileOps;
use Siteferry\Support\Db;

/**
 * Avanza un job tanto como permita el presupuesto. No depende de WordPress:
 * recibe el job como array y devuelve el job actualizado.
 */
final class Runner {

	/**
	 * Estructura de la BD.
	 *
	 * @var Schema
	 */
	private Schema $schema;

	/**
	 * Constructor.
	 *
	 * @param Db            $db     Conexión.
	 * @param \Closure|null $remote fn(array $job, string $op, array $args, ?string $binary = null): array — llamada al sitio remoto (pull/push).
	 */
	public function __construct( private Db $db, private ?\Closure $remote = null ) {
		$this->schema = new Schema( $db );
	}

	/**
	 * Avanza el job. Si falla, queda en estado "failed" con el error; las tablas vivas no se tocan
	 * hasta el paso final.
	 *
	 * @param array  $job    Job.
	 * @param Budget $budget Tiempo disponible.
	 */
	public function step( array $job, Budget $budget ): array {
		if ( ! self::is_open( $job ) ) {
			return $job;
		}
		$job['status'] = 'running';
		try {
			$this->db->in_migration_session(
				function () use ( &$job, $budget ) {
					while ( isset( $job['steps'][ $job['index'] ] ) ) {
						if ( 'receive' === $job['steps'][ $job['index'] ]['type'] && empty( $job['received'] ) ) {
							break; // Esperando los datos del otro sitio.
						}
						if ( $this->run_step( $job, $job['steps'][ $job['index'] ], $budget ) ) {
							++$job['index'];
							$job['cursor']    = null;
							$job['step_rows'] = 0;
						}
						if ( $budget->exhausted() ) {
							break;
						}
					}
				}
			);
			if ( ! isset( $job['steps'][ $job['index'] ] ) ) {
				$job['status'] = 'done';
				unset( $job['remote']['key'] ); // La clave del otro sitio no se guarda más de lo necesario.
				self::log( $job, 'Terminado.' );
			}
		} catch ( \Throwable $e ) {
			$job['status'] = 'failed';
			$job['error']  = $e->getMessage();
			self::log( $job, 'Error: ' . $e->getMessage() );
		}
		$job['heartbeat'] = time();
		return $job;
	}

	/**
	 * Cancela: borra tablas temporales y archivos a medias.
	 *
	 * @param array $job Job.
	 */
	public function cancel( array $job ): array {
		foreach ( $this->schema->temp_tables() as $table ) {
			$this->db->query( 'DROP TABLE IF EXISTS ' . Db::ident( $table ) );
		}
		if ( 'push' === $job['intent'] && isset( $job['remote']['job'] ) ) {
			try {
				$this->remote( $job, 'push_cancel', array( 'id' => $job['remote']['job'] ) );
			} catch ( \RuntimeException $e ) {
				self::log( $job, 'No se pudo cancelar en el sitio remoto: ' . $e->getMessage() );
			}
		}
		// El backup nunca se borra aquí: puede ser lo único que quede si algo falló. Lo limpia wp-cron a los 7 días.
		foreach ( array_diff( $job['owned'], array( 'backup' ) ) as $key ) {
			if ( isset( $job['files'][ $key ] ) ) {
				self::delete( $job['files'][ $key ] );
			}
		}
		$job['status'] = 'cancelled';
		unset( $job['remote']['key'] );
		self::log( $job, 'Cancelado.' );
		return $job;
	}

	/**
	 * Si el job sigue en curso.
	 *
	 * @param array|null $job Job.
	 */
	public static function is_open( ?array $job ): bool {
		return $job && in_array( $job['status'], array( 'pending', 'running' ), true );
	}

	/**
	 * Porcentaje aproximado, ponderado por filas.
	 *
	 * @param array $job Job.
	 */
	public static function percent( array $job ): int {
		if ( 'done' === $job['status'] ) {
			return 100;
		}
		$total = 0;
		$done  = 0;
		foreach ( $job['steps'] as $i => $step ) {
			$weight = max( 1, (int) $step['est'] );
			$total += $weight;
			if ( $i < $job['index'] ) {
				$done += $weight;
			} elseif ( $i === $job['index'] ) {
				$done += min( $weight, (int) $job['step_rows'] );
			}
		}
		return $total ? (int) floor( $done * 100 / $total ) : 0;
	}

	/**
	 * Agrega una línea al registro del job (se guardan las últimas 200).
	 *
	 * @param array  $job     Job.
	 * @param string $message Texto.
	 */
	public static function log( array &$job, string $message ): void {
		$job['log'][] = gmdate( 'H:i:s' ) . ' ' . $message;
		$job['log']   = array_slice( $job['log'], -200 );
	}

	/**
	 * Ejecuta un tramo del paso actual.
	 *
	 * @param array  $job    Job.
	 * @param array  $step   Paso.
	 * @param Budget $budget Tiempo.
	 * @return bool Si el paso terminó.
	 *
	 * @throws \RuntimeException Si el paso falla.
	 */
	private function run_step( array &$job, array $step, Budget $budget ): bool {
		switch ( $step['type'] ) {
			case 'preflight':
				$this->preflight( $job );
				return true;

			case 'header':
				$sink = new FileSink( $job['files'][ $step['file'] ] );
				$sink->comment( 'Siteferry — ' . $job['site']['home'] . ' — ' . gmdate( 'c' ) );
				$sink->write( 'SET NAMES utf8mb4' );
				$sink->write( "SET sql_mode = 'NO_AUTO_VALUE_ON_ZERO'" );
				$sink->write( 'SET foreign_key_checks = 0' );
				$sink->close();
				return true;

			case 'copy':
				return empty( $step['remote'] ) ? $this->copy( $job, $step, $budget ) : $this->pull_copy( $job, $step );

			case 'receive':
				return true;

			case 'files_scan':
				return $this->files_scan( $job, $step, $budget );

			case 'files_diff':
				$this->files_diff( $job, $step['root'] );
				return true;

			case 'files_transfer':
				return 'pull' === $job['intent'] ? $this->files_pull( $job, $step, $budget ) : $this->files_push( $job, $step, $budget );

			case 'files_finalize':
				$this->files_finalize( $job, $step['root'] );
				return true;

			case 'site_start':
				SitePackage::start(
					$job['files']['export'],
					array(
						'format'  => 1,
						'version' => $job['site']['version'] ?? '',
						'home'    => $job['site']['home'],
						'siteurl' => $job['site']['siteurl'],
						'prefix'  => $job['site']['prefix'],
						'created' => gmdate( 'c' ),
						'roots'   => $step['roots'],
					)
				);
				$job['package_size'] = self::size( $job['files']['export'] );
				return true;

			case 'site_db':
				return $this->site_db( $job, $budget );

			case 'site_files':
				return $this->site_files( $job, $step, $budget );

			case 'site_unpack':
				return $this->site_unpack( $job, $budget );

			case 'files_undo':
				$stats = ( new Applier( $job['site']['roots'], $step['dir'] ) )->restore( $step['root'] );
				self::log( $job, "Archivos ({$step['root']}): {$stats['restored']} restaurados, {$stats['removed']} retirados (a undone/)." );
				return true;

			case 'push_begin':
				$job['remote']['job'] = $this->remote(
					$job,
					'push_begin',
					array(
						'backup'  => $job['options']['backup'],
						'session' => $job['id'], // El remoto une BD y archivos de este push en un mismo punto de deshacer.
					)
				)['id'];
				$job['remote']['seq'] = 0;
				self::log( $job, 'El sitio remoto empezó a recibir.' );
				return true;

			case 'remote_wait':
				return $this->remote_wait( $job, $step );

			case 'push_finish':
				$this->remote(
					$job,
					'push_finish',
					array(
						'id'      => $job['remote']['job'],
						'swap'    => $job['swap'],
						'fks'     => $job['fks'],
						'ref_map' => $job['ref_map'],
						'rows'    => $job['rows'],
					)
				);
				return true;

			case 'footer':
				$sink = new FileSink( $job['files'][ $step['file'] ] );
				foreach ( $job['file_fks'][ $step['file'] ] ?? array() as $fk ) {
					$sink->write( 'ALTER TABLE ' . Db::ident( $fk['table'] ) . ' ADD ' . $fk['clause'] );
				}
				$sink->write( 'SET foreign_key_checks = 1' );
				$sink->close();
				self::log( $job, basename( $job['files'][ $step['file'] ] ) . ': ' . self::megabytes( $job['files'][ $step['file'] ] ) );
				return true;

			case 'decompress':
				$this->decompress( $job );
				return true;

			case 'import':
				$result                    = SqlFileReader::read(
					$job['files']['import_sql'] ?? $job['files']['import'],
					(int) ( $job['cursor']['offset'] ?? 0 ),
					$budget,
					function ( string $sql ) use ( &$job ) {
						$this->import_statement( $job, $sql );
					}
				);
				$job['cursor']             = array( 'offset' => $result['offset'] );
				$job['step_rows']          = intdiv( $result['offset'], 200 );
				$job['import']['skipped'] += $result['skipped'];
				if ( $result['done'] ) {
					self::log( $job, count( $job['import']['tables'] ) . ' tablas cargadas.' );
					if ( $job['import']['skipped'] ) {
						self::log( $job, $job['import']['skipped'] . ' sentencias omitidas (vistas, triggers o procedimientos).' );
					}
				}
				return $result['done'];

			case 'plan_import':
				$this->plan_import( $job );
				return true;

			case 'finalize':
				$warnings = ( new Finalizer( $this->db, $this->schema ) )->run(
					$job['swap'],
					$job['fks'],
					'pull' === $job['intent'] ? array() : $job['ref_map'], // En pull las cláusulas ya vienen validadas y apuntando aquí.
					$job['site']['options_table'],
					$job['site']['preserve'],
					$job['site']['plugin']
				);
				foreach ( $warnings as $warning ) {
					$job['warnings'][] = $warning;
					self::log( $job, $warning );
				}
				self::log( $job, count( $job['swap'] ) . ' tablas reemplazadas.' );
				return true;

			case 'cleanup':
				foreach ( array_keys( $job['import']['tables'] ) as $name ) {
					if ( ! isset( $job['swap'][ Schema::RAW . $name ] ) ) {
						$this->db->query( 'DROP TABLE IF EXISTS ' . Db::ident( Schema::RAW . $name ) );
					}
				}
				foreach ( array( 'import', 'import_sql', 'import_gz', 'dump' ) as $key ) {
					if ( in_array( $key, $job['owned'], true ) && isset( $job['files'][ $key ] ) ) {
						self::delete( $job['files'][ $key ] );
					}
				}
				return true;
		}
		throw new \RuntimeException( 'Paso desconocido: ' . $step['type'] );
	}

	/**
	 * Copia un tramo de una tabla.
	 *
	 * @param array  $job    Job.
	 * @param array  $step   Paso copy.
	 * @param Budget $budget Tiempo.
	 */
	private function copy( array &$job, array $step, Budget $budget ): bool {
		$replacer = null;
		if ( $step['replace'] ) {
			$o        = $job['options'];
			$replacer = new Replacer( $o['pairs'], $o['case_sensitive'], $o['regex'] );
			$replacer = $replacer->is_empty() ? null : $replacer;
		}
		$to_file = 'file' === $step['sink'];
		$push    = 'remote' === $step['sink'];
		$sink    = match ( $step['sink'] ) {
			'file'   => new FileSink( $job['files'][ $step['file'] ] ),
			'remote' => new MemorySink(),
			default  => new TableSink( $this->db ),
		};
		// Los volcados a archivo son copias literales de este sitio: sin cambio de prefijo.
		$copier = new TableCopier(
			$this->db,
			$this->schema,
			$replacer,
			$job['max_bytes'],
			$job['options']['replace_guids'],
			$to_file ? '' : $job['prefix_from'],
			$to_file ? '' : $job['prefix_to'],
			$step['options_table'] ?? $job['site']['options_table']
		);

		$result = $copier->run( $step['source'], $step['target'], $step['logical'], $sink, $job['cursor'], $push ? $budget->limited( $job['remote']['chunk'] ) : $budget, true );
		if ( $to_file ) {
			$sink->close();
		}
		if ( $push && $sink->statements ) {
			// El cursor solo avanza si el otro sitio confirmó el trozo; los trozos van numerados.
			$seq = $job['remote']['seq'] + 1;
			$this->remote(
				$job,
				'push_chunk',
				array(
					'id'         => $job['remote']['job'],
					'seq'        => $seq,
					'statements' => $sink->pack(),
				)
			);
			$job['remote']['seq'] = $seq;
		}

		foreach ( $result['fks'] as $clause ) {
			$fk = array(
				'table'  => $to_file ? $step['target'] : $step['final'],
				'clause' => $clause,
			);
			if ( $to_file ) {
				$job['file_fks'][ $step['file'] ][] = $fk;
			} else {
				$job['fks'][] = $fk;
			}
		}
		$job['cursor']     = $result['cursor'];
		$job['step_rows'] += $result['rows'];
		$job['rows']      += $result['rows'];

		if ( null === $result['cursor'] ) {
			self::log( $job, $step['label'] . ': ' . $job['step_rows'] . ' filas' );
			return true;
		}
		return false;
	}

	/**
	 * Comprobaciones antes de tocar nada.
	 *
	 * @param array $job Job.
	 *
	 * @throws \RuntimeException Con todos los problemas encontrados.
	 */
	private function preflight( array &$job ): void {
		$errors = array();
		if ( $job['site']['multisite'] ) {
			$errors[] = 'Multisite todavía no está soportado.';
		}
		if ( $job['options']['regex'] ) {
			foreach ( $job['options']['pairs'] as [ $pattern ] ) {
				if ( false === @preg_match( $pattern, '' ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
					$errors[] = 'Expresión regular inválida: ' . $pattern;
				}
			}
		}
		foreach ( $job['steps'] as $step ) {
			if ( 'copy' !== $step['type'] ) {
				continue;
			}
			if ( empty( $step['remote'] ) && ! $this->schema->exists( $step['source'] ) ) {
				$errors[] = 'No existe la tabla ' . $step['source'];
			} elseif ( 'table' === $step['sink'] && strlen( $step['final'] ) > Schema::MAX_NAME ) {
				$errors[] = 'Nombre de tabla demasiado largo: ' . $step['final'];
			}
		}
		$storage = $job['site']['storage'];
		if ( ! is_dir( $storage ) || ! is_writable( $storage ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable
			$errors[] = 'No se puede escribir en la carpeta de archivos: ' . $storage;
		}
		if ( isset( $job['files']['import'] ) && ! is_readable( $job['files']['import'] ) ) {
			$errors[] = 'No se puede leer el archivo a importar.';
		}
		if ( $errors ) {
			throw new \RuntimeException( implode( ' ', $errors ) );
		}

		$packet           = (int) $this->db->value( 'SELECT @@max_allowed_packet' );
		$job['max_bytes'] = max( 64 * 1024, min( 1024 * 1024, intdiv( $packet, 4 ) ) );

		$leftovers = $this->schema->temp_tables();
		foreach ( $leftovers as $table ) {
			$this->db->query( 'DROP TABLE IF EXISTS ' . Db::ident( $table ) );
		}
		if ( $leftovers ) {
			self::log( $job, count( $leftovers ) . ' tablas temporales de un intento anterior eliminadas.' );
		}
		self::log( $job, 'Comprobaciones correctas.' );
	}

	/**
	 * Descomprime el .sql.gz subido.
	 *
	 * @param array $job Job.
	 *
	 * @throws \RuntimeException Si falla.
	 */
	private function decompress( array &$job ): void {
		// ponytail: se descomprime en una sola petición; con dumps de varios GB y poco tiempo por petición, subir el .sql sin comprimir.
		$target = $job['site']['storage'] . '/import-' . $job['id'] . '.sql';
		$in     = gzopen( $job['files']['import_gz'] ?? $job['files']['import'], 'rb' );
		$out    = fopen( $target, 'wb' );
		if ( ! $in || ! $out ) {
			throw new \RuntimeException( 'No se pudo descomprimir el archivo.' );
		}
		while ( ! gzeof( $in ) ) {
			$chunk = gzread( $in, 1024 * 1024 );
			if ( false === $chunk || fwrite( $out, $chunk ) !== strlen( $chunk ) ) {
				throw new \RuntimeException( 'No se pudo descomprimir el archivo (¿disco lleno o archivo dañado?).' );
			}
		}
		gzclose( $in );
		fclose( $out );

		$job['files']['import_sql'] = $target;
		$job['owned'][]             = 'import_sql';
		foreach ( $job['steps'] as &$step ) {
			if ( 'import' === $step['type'] ) {
				$step['est'] = intdiv( (int) filesize( $target ), 200 );
			}
		}
	}

	/**
	 * Ejecuta una sentencia del archivo importado sobre tablas crudas `_sfr_*`.
	 *
	 * @param array  $job Job.
	 * @param string $sql Sentencia.
	 *
	 * @throws \RuntimeException Si el nombre de tabla es demasiado largo o MySQL falla.
	 */
	private function import_statement( array &$job, string $sql ): void {
		$sql = ltrim( $sql );
		// Las tablas crudas se crean y borran aquí; nada del archivo puede tocar las tablas vivas.
		if ( preg_match( '/^(\/\*|SET\s|USE\s|LOCK\s+TABLES|UNLOCK\s+TABLES|DROP\s|CREATE\s+DATABASE|START\s+TRANSACTION|BEGIN|COMMIT)/i', $sql ) ) {
			return;
		}
		if ( ! preg_match( '/^(CREATE\s+TABLE(?:\s+IF\s+NOT\s+EXISTS)?|INSERT(?:\s+IGNORE)?\s+INTO|REPLACE\s+INTO|ALTER\s+TABLE)\s+`?([^`\s(]+)`?/i', $sql, $m ) ) {
			++$job['import']['skipped'];
			return;
		}
		$name = $m[2];
		$raw  = Schema::RAW . $name;
		if ( strlen( $raw ) > 64 ) {
			throw new \RuntimeException( 'Nombre de tabla demasiado largo: ' . $name );
		}
		$verb = strtoupper( (string) preg_replace( '/\s+/', ' ', $m[1] ) );
		$rest = substr( $sql, strlen( $m[0] ) );

		if ( str_starts_with( $verb, 'CREATE' ) ) {
			[ $create, $fks ] = Schema::split_foreign_keys( 'CREATE TABLE ' . Db::ident( $raw ) . $this->schema->known_collations( $rest ) );
			foreach ( $fks as $clause ) {
				$job['fks'][] = array(
					'table'  => $name,
					'clause' => $clause,
				);
			}
			$this->db->query( 'DROP TABLE IF EXISTS ' . Db::ident( $raw ) );
			$this->db->query( $create );
			$job['import']['tables'][ $name ] = true;
			return;
		}
		if ( str_starts_with( $verb, 'ALTER' ) && false !== stripos( $rest, 'FOREIGN KEY' ) ) {
			$job['fks'][] = array(
				'table'  => $name,
				'clause' => trim( (string) preg_replace( '/^\s*ADD\s+/i', '', $rest ) ),
			);
			return;
		}
		$this->db->query( $verb . ' ' . Db::ident( $raw ) . $rest );
	}

	/**
	 * Tras cargar el archivo: detecta el prefijo y la URL de origen y agrega los pasos restantes.
	 *
	 * @param array $job Job.
	 *
	 * @throws \RuntimeException Si el archivo no tenía tablas.
	 */
	private function plan_import( array &$job ): void {
		$names = array_keys( $job['import']['tables'] );
		if ( ! $names ) {
			throw new \RuntimeException( 'El archivo no contiene tablas.' );
		}
		$from = $this->source_prefix( $job, $names );
		$to   = $job['site']['prefix'];

		// Primero las tablas del prefijo elegido; una tabla sin ese prefijo que caiga en el mismo
		// nombre final (p. ej. restos de otra instalación) se omite.
		$own     = array_filter( $names, static fn( $n ) => '' !== $from && str_starts_with( $n, $from ) );
		$map     = array();
		$skipped = array();
		foreach ( array_merge( $own, array_diff( $names, $own ) ) as $name ) {
			$final = in_array( $name, $own, true ) ? $to . substr( $name, strlen( $from ) ) : $name;
			if ( in_array( $final, $map, true ) ) {
				$skipped[] = $name;
				continue;
			}
			if ( strlen( $final ) > Schema::MAX_NAME ) {
				throw new \RuntimeException( 'Nombre de tabla demasiado largo: ' . $final );
			}
			$map[ $name ] = $final;
		}
		if ( $skipped ) {
			self::log( $job, count( $skipped ) . " tablas omitidas porque chocarían con las del prefijo $from: " . implode( ', ', $skipped ) );
		}
		if ( '' !== $from ) {
			$job['prefix_from'] = $from;
			$job['prefix_to']   = $to;
			if ( $from !== $to ) {
				self::log( $job, "Prefijo de tablas: $from → $to" );
			}
		}

		if ( $job['options']['auto_url'] && '' !== $from ) {
			$this->add_url_pairs( $job, Schema::RAW . $from . 'options' );
		}

		$o          = $job['options'];
		$needs_copy = ! ( new Replacer( $o['pairs'], $o['case_sensitive'], $o['regex'] ) )->is_empty() || $from !== $to;
		$rows       = $this->schema->stats( Schema::RAW );
		$steps      = array();
		foreach ( $map as $name => $final ) {
			$raw = Schema::RAW . $name;
			if ( $needs_copy ) {
				$steps[]                             = Planner::copy( $raw, Schema::TMP . $final, $name, 'table', $rows[ $raw ]['rows'] ?? 1, $final ) + array( 'options_table' => $from . 'options' );
				$job['swap'][ Schema::TMP . $final ] = $final;
			} else {
				$job['swap'][ $raw ] = $final;
			}
		}
		$fks = array();
		foreach ( $job['fks'] as $fk ) {
			if ( isset( $map[ $fk['table'] ] ) ) {
				$fks[] = array(
					'table'  => $map[ $fk['table'] ],
					'clause' => $fk['clause'],
				);
			}
		}
		$job['fks']     = $fks;
		$job['ref_map'] = $map;

		// La limpieza va antes del cambio: tras importar, la sesión del usuario puede dejar de existir
		// y ninguna petición posterior llegaría a ejecutarse.
		$steps[] = Planner::step( 'cleanup', 'Limpieza' );
		$steps[] = Planner::step( 'finalize', 'Cambio de tablas' );
		array_splice( $job['steps'], $job['index'] + 1, 0, $steps );
	}

	/**
	 * Agrega pares para pasar de la URL del sitio de origen a la de este sitio.
	 *
	 * @param array  $job     Job.
	 * @param string $options Tabla de opciones cruda.
	 */
	private function add_url_pairs( array &$job, string $options ): void {
		if ( ! $this->schema->exists( $options ) ) {
			return;
		}
		$known = array_column( $job['options']['pairs'], 0 );
		$auto  = array();
		foreach ( array( 'siteurl', 'home' ) as $option ) {
			$old = rtrim( (string) $this->db->value( 'SELECT option_value FROM ' . Db::ident( $options ) . ' WHERE option_name = ' . $this->db->quote( $option ) ), '/' );
			$new = rtrim( (string) $job['site'][ $option ], '/' );
			if ( '' !== $old && $old !== $new && ! in_array( $old, $known, true ) ) {
				$auto[ $old ] = $new;
			}
		}
		uksort( $auto, static fn( $a, $b ) => strlen( $b ) <=> strlen( $a ) ); // La más larga primero.
		foreach ( $auto as $old => $new ) {
			$job['options']['pairs'][] = array( $old, $new );
			self::log( $job, "Reemplazo automático: $old → $new" );
		}
	}

	/**
	 * Prefijos de las instalaciones de WordPress en una lista de tablas (los que tienen options,
	 * posts y usermeta; los subsitios de multisite no tienen usermeta propio).
	 *
	 * @param string[] $names Tablas.
	 * @return string[]
	 */
	public static function detect_prefixes( array $names ): array {
		$found = array();
		foreach ( $names as $name ) {
			if ( str_ends_with( $name, 'options' ) ) {
				$prefix = substr( $name, 0, -7 );
				if ( in_array( $prefix . 'posts', $names, true ) && in_array( $prefix . 'usermeta', $names, true ) ) {
					$found[] = $prefix;
				}
			}
		}
		sort( $found );
		return $found;
	}

	/**
	 * Prefijo de la instalación a importar: el indicado, o el único que haya.
	 *
	 * @param array    $job   Job.
	 * @param string[] $names Tablas del archivo.
	 *
	 * @throws \RuntimeException Si hay varias instalaciones y no se indicó cuál, o la indicada no está.
	 */
	private function source_prefix( array $job, array $names ): string {
		$found  = self::detect_prefixes( $names );
		$chosen = (string) ( $job['options']['source_prefix'] ?? '' );
		if ( '' !== $chosen ) {
			if ( ! in_array( $chosen, $found, true ) ) {
				throw new \RuntimeException( "El archivo no tiene una instalación con el prefijo $chosen. Prefijos encontrados: " . implode( ', ', $found ) );
			}
			return $chosen;
		}
		if ( count( $found ) > 1 ) {
			throw new \RuntimeException( 'El archivo trae varias instalaciones de WordPress (prefijos: ' . implode( ', ', $found ) . '). Cancela e indica el prefijo de origen.' );
		}
		return $found[0] ?? '';
	}

	/**
	 * Borra un archivo si existe.
	 *
	 * @param string $path Ruta.
	 */
	private static function delete( string $path ): void {
		if ( is_file( $path ) ) {
			unlink( $path );
		}
	}

	/**
	 * Tamaño de un archivo para el registro.
	 *
	 * @param string $path Ruta.
	 */
	private static function megabytes( string $path ): string {
		$bytes = (int) filesize( $path );
		return $bytes < 1048576 ? ceil( $bytes / 1024 ) . ' KB' : number_format( $bytes / 1048576, 1 ) . ' MB';
	}

	/**
	 * Pull: pide al sitio remoto un tramo de la tabla y lo carga en la tabla temporal local.
	 *
	 * @param array $job  Job.
	 * @param array $step Paso copy remoto.
	 *
	 * @throws \RuntimeException Si el remoto devuelve algo que no corresponde a esa tabla.
	 */
	private function pull_copy( array &$job, array $step ): bool {
		$o      = $job['options'];
		$result = $this->remote(
			$job,
			'pull_rows',
			array(
				'table'          => $step['source'],
				'target'         => $step['target'],
				'cursor'         => $job['cursor'],
				'pairs'          => $o['pairs'],
				'case_sensitive' => $o['case_sensitive'],
				'regex'          => $o['regex'],
				'replace_guids'  => $o['replace_guids'],
				'prefix_to'      => $job['prefix_to'],
				'max_bytes'      => $job['max_bytes'],
			)
		);
		foreach ( MemorySink::decode( (string) $result['statements'] ) as $sql ) {
			if ( ! Schema::safe_temp_statement( $sql, $step['target'] ) ) {
				throw new \RuntimeException( 'El sitio remoto envió una sentencia que no corresponde a ' . $step['source'] . '.' );
			}
			$this->db->query( str_starts_with( $sql, 'CREATE' ) ? $this->schema->known_collations( $sql ) : $sql );
		}
		foreach ( (array) $result['fks'] as $clause ) {
			$safe = Schema::foreign_key( (string) $clause, $job['ref_map'], $job['site']['prefix'] );
			if ( null === $safe ) {
				throw new \RuntimeException( 'El sitio remoto envió una llave foránea inválida en ' . $step['source'] . '.' );
			}
			$job['fks'][] = array(
				'table'  => $step['final'],
				'clause' => $safe,
			);
		}
		$job['cursor']     = is_array( $result['cursor'] ) ? $result['cursor'] : null;
		$job['step_rows'] += (int) $result['rows'];
		$job['rows']      += (int) $result['rows'];

		if ( null === $job['cursor'] ) {
			self::log( $job, $step['label'] . ': ' . $job['step_rows'] . ' filas' );
			return true;
		}
		return false;
	}

	/**
	 * Push: espera a que el sitio remoto esté listo para recibir (until=ready) o termine el cambio (until=done).
	 *
	 * @param array $job  Job.
	 * @param array $step Paso remote_wait.
	 *
	 * @throws \RuntimeException Si el remoto falló.
	 */
	private function remote_wait( array &$job, array $step ): bool {
		$state = $this->remote( $job, 'push_step', array( 'id' => $job['remote']['job'] ) );
		if ( in_array( $state['status'], array( 'failed', 'cancelled' ), true ) ) {
			throw new \RuntimeException( 'Sitio remoto: ' . ( $state['error'] ?? $state['status'] ) );
		}
		$job['step_rows'] = (int) $state['percent'];
		if ( 'ready' === $step['until'] ) {
			if ( ! $state['ready'] ) {
				return false;
			}
			if ( $state['backup'] ) {
				self::log( $job, 'Backup en el sitio remoto: ' . $state['backup'] );
			}
			return true;
		}
		if ( 'done' !== $state['status'] ) {
			return false;
		}
		foreach ( (array) $state['warnings'] as $warning ) {
			$job['warnings'][] = 'Sitio remoto: ' . $warning;
		}
		self::log( $job, 'El sitio remoto quedó actualizado.' );
		return true;
	}

	/**
	 * Llama al sitio remoto.
	 *
	 * @param array       $job    Job (lleva la conexión).
	 * @param string      $op     Operación.
	 * @param array       $args   Argumentos.
	 * @param string|null $binary Binario que acompaña a la petición (files_put).
	 *
	 * @throws \RuntimeException Si no hay conexión configurada.
	 */
	private function remote( array $job, string $op, array $args, ?string $binary = null ): array {
		if ( ! $this->remote ) {
			throw new \RuntimeException( 'Esta operación necesita conexión con el sitio remoto.' );
		}
		return ( $this->remote )( $job, $op, $args, $binary );
	}

	/**
	 * Escanea una carpeta (de este sitio o del remoto) hacia la lista de origen o de destino.
	 *
	 * @param array  $job    Job.
	 * @param array  $step   Paso files_scan.
	 * @param Budget $budget Tiempo.
	 */
	private function files_scan( array &$job, array $step, Budget $budget ): bool {
		$root = $step['root'];
		$out  = $this->file_list( $job, $root, $step['out'] );
		if ( null === $job['cursor'] ) {
			Applier::remove_tree( $out->path() ); // Un reintento empieza de cero.
		}
		do {
			if ( 'remote' === $step['side'] ) {
				$result = $this->remote(
					$job,
					'files_scan',
					array(
						'root'   => $root,
						'cursor' => $job['cursor'],
					)
				);
				foreach ( FileOps::unpack( (string) $result['entries'] ) as $entry ) {
					$out->add( (string) $entry[0], (int) $entry[1], (string) $entry[2] );
				}
				$out->close();
			} else {
				$scanner = new Scanner( new PathGuard( $job['site']['roots'][ $root ] ), Roots::exclude( $root ), ! Roots::is_code( $root ) );
				$result  = $scanner->scan( $job['cursor'], $out, $budget );
			}
			$job['cursor'] = is_array( $result['cursor'] ) ? $result['cursor'] : null;
			++$job['step_rows'];
		} while ( null !== $job['cursor'] && ! $budget->exhausted() );
		return null === $job['cursor'];
	}

	/**
	 * Compara origen y destino de una carpeta; deja las listas de envío y borrado.
	 *
	 * @param array  $job  Job.
	 * @param string $root Carpeta.
	 */
	private function files_diff( array &$job, string $root ): void {
		$stats                      = Diff::compute(
			$this->file_list( $job, $root, 'source' ),
			$this->file_list( $job, $root, 'target' ),
			$this->fresh_list( $job, $root, 'send' ),
			$job['options']['delete_files'] ? $this->fresh_list( $job, $root, 'delete' ) : null
		);
		$job['file_stats'][ $root ] = $stats + array(
			'sent'    => 0,
			'failed'  => 0,
			'missing' => 0,
		);
		foreach ( $job['steps'] as &$other ) {
			if ( 'files_transfer' === $other['type'] && ( $other['root'] ?? null ) === $root ) {
				$other['est'] = intdiv( $stats['bytes'], 65536 ) + $stats['send'] + 1;
			}
		}
		unset( $other );
		$deletes = $job['options']['delete_files'] ? ', ' . $stats['delete'] . ' por borrar' : '';
		self::log( $job, "Archivos ($root): {$stats['send']} por enviar (" . number_format( $stats['bytes'] / 1048576, 1 ) . " MB)$deletes." );
	}

	/**
	 * Pull de archivos: pide paquetes al remoto y los coloca aquí.
	 *
	 * @param array  $job    Job.
	 * @param array  $step   Paso.
	 * @param Budget $budget Tiempo.
	 */
	private function files_pull( array &$job, array $step, Budget $budget ): bool {
		$root    = $step['root'];
		$send    = $this->file_list( $job, $root, 'send' );
		$applier = new Applier( $job['site']['roots'], $this->files_dir( $job ) );
		$cursor  = $job['cursor'] ?? array(
			'pos'    => 0,
			'offset' => 0,
			'size'   => 0,
			'md5'    => '',
		);
		do {
			$entries = array();
			$ends    = array();
			foreach ( $send->read_from( (int) $cursor['pos'] ) as [ $file, $size, $md5, $after ] ) {
				$entries[] = array( $file, $size, $md5 );
				$ends[]    = $after;
				if ( count( $entries ) >= 500 ) {
					break;
				}
			}
			if ( ! $entries ) {
				$job['cursor'] = null;
				return true;
			}
			$result = $this->remote(
				$job,
				'files_get',
				array(
					'root'      => $root,
					'entries'   => FileOps::pack( $entries ),
					'offset'    => $cursor['offset'],
					'size'      => $cursor['size'],
					'md5'       => $cursor['md5'],
					'max_bytes' => 4 * 1024 * 1024,
				)
			);
			$this->record_files( $job, $root, $applier->receive( $root, (string) $result['package'] ), count( (array) $result['missing'] ), (int) $result['bytes'] );
			$consumed      = (int) $result['consumed'];
			$cursor        = array(
				'pos'    => $consumed > 0 ? $ends[ $consumed - 1 ] : $cursor['pos'],
				'offset' => (int) $result['offset'],
				'size'   => (int) $result['size'],
				'md5'    => (string) $result['md5'],
			);
			$job['cursor'] = $cursor;
		} while ( ! $budget->exhausted() );
		return false;
	}

	/**
	 * Push de archivos: arma paquetes aquí y los envía al remoto.
	 *
	 * @param array  $job    Job.
	 * @param array  $step   Paso.
	 * @param Budget $budget Tiempo.
	 */
	private function files_push( array &$job, array $step, Budget $budget ): bool {
		$root = $step['root'];
		$send = $this->file_list( $job, $root, 'send' );
		do {
			$built = Package::build( $send, new PathGuard( $job['site']['roots'][ $root ] ), $job['cursor'], $job['remote']['chunk'] );
			$got   = array(
				'done'    => array(),
				'partial' => array(),
				'failed'  => array(),
			);
			if ( $built['files'] > 0 ) {
				$got = $this->remote(
					$job,
					'files_put',
					array(
						'root'    => $root,
						'session' => $job['id'],
					),
					$built['package']
				);
			}
			// El cursor solo avanza cuando el remoto confirmó el paquete.
			$this->record_files( $job, $root, $got, count( $built['missing'] ), $built['bytes'] );
			$job['cursor'] = $built['cursor'];
		} while ( null !== $job['cursor'] && ! $budget->exhausted() );
		return null === $job['cursor'];
	}

	/**
	 * Cierre de una carpeta: borrados y cambio de carpetas de código (aquí o en el remoto).
	 *
	 * @param array  $job  Job.
	 * @param string $root Carpeta.
	 */
	private function files_finalize( array &$job, string $root ): void {
		$deletions = array();
		if ( $job['options']['delete_files'] ) {
			foreach ( $this->file_list( $job, $root, 'delete' )->entries() as [ $file ] ) {
				$deletions[] = $file;
			}
		}
		if ( 'push' !== $job['intent'] ) {
			$applier = new Applier( $job['site']['roots'], $this->files_dir( $job ) );
			if ( 'import' === $job['intent'] && Roots::is_code( $root ) ) {
				$deletions = $applier->leftovers( $root ); // Paquete de sitio: el código queda igual que en el origen.
			}
			$stats = $applier->finalize( $root, $deletions );
		} else {
			// ponytail: la lista de borrados viaja entera en una petición; con decenas de miles convendría trocearla.
			$stats = $this->remote(
				$job,
				'files_finalize',
				array(
					'root'      => $root,
					'session'   => $job['id'],
					'deletions' => FileOps::pack( $deletions ),
				)
			);
		}
		foreach ( (array) ( $stats['skipped'] ?? array() ) as $skipped ) {
			$job['warnings'][] = "Archivos ($root): no se reemplazó $skipped (es un enlace simbólico en el destino).";
		}
		$sent = $job['file_stats'][ $root ] ?? array();
		self::log(
			$job,
			"Archivos ($root): " . ( $sent['sent'] ?? 0 ) . ' recibidos, ' . (int) ( $stats['replaced'] ?? 0 ) . ' carpetas o archivos de primer nivel cambiados, '
			. (int) ( $stats['deleted'] ?? 0 ) . ' borrados (a backup).'
		);
	}

	/**
	 * Cuenta lo enviado y convierte los fallos en advertencias (no detienen el job).
	 *
	 * @param array  $job     Job.
	 * @param string $root    Carpeta.
	 * @param array  $result  done, partial, failed.
	 * @param int    $missing Archivos que ya no existían en el origen.
	 * @param int    $bytes   Bytes de contenido del paquete.
	 */
	private function record_files( array &$job, string $root, array $result, int $missing, int $bytes ): void {
		$stats             = &$job['file_stats'][ $root ];
		$stats['sent']    += count( (array) $result['done'] );
		$stats['missing'] += $missing;
		$job['step_rows'] += intdiv( $bytes, 65536 ) + count( (array) $result['done'] );
		foreach ( (array) $result['failed'] as $file => $reason ) {
			++$stats['failed'];
			if ( $stats['failed'] <= 20 ) {
				$job['warnings'][] = "Archivos ($root): $file — $reason";
			}
		}
	}

	/**
	 * Carpeta de trabajo de los archivos del job (listas, zona temporal y backup).
	 *
	 * @param array $job Job.
	 */
	private function files_dir( array $job ): string {
		$dir = $job['site']['storage'] . '/files-' . $job['id'];
		if ( ! is_dir( $dir . '/lists' ) ) {
			mkdir( $dir . '/lists', 0755, true );
		}
		return $dir;
	}

	/**
	 * Lista de una carpeta: source, target, send o delete.
	 *
	 * @param array  $job  Job.
	 * @param string $root Carpeta.
	 * @param string $kind Tipo.
	 */
	private function file_list( array $job, string $root, string $kind ): Manifest {
		return new Manifest( $this->files_dir( $job ) . "/lists/$root-$kind.jsonl" );
	}

	/**
	 * Lista vacía (para escribirla de nuevo).
	 *
	 * @param array  $job  Job.
	 * @param string $root Carpeta.
	 * @param string $kind Tipo.
	 */
	private function fresh_list( array $job, string $root, string $kind ): Manifest {
		$list = $this->file_list( $job, $root, $kind );
		Applier::remove_tree( $list->path() );
		return $list;
	}

	/**
	 * Paquete: agrega el dump .sql.gz en tramos y luego lo borra.
	 *
	 * @param array  $job    Job.
	 * @param Budget $budget Tiempo.
	 */
	private function site_db( array &$job, Budget $budget ): bool {
		self::rewind( $job['files']['export'], $job['package_size'] );
		$dump   = $job['files']['dump'];
		$offset = (int) ( $job['cursor']['offset'] ?? 0 );
		$size   = (int) filesize( $dump );
		$handle = fopen( $dump, 'rb' );
		fseek( $handle, $offset );
		while ( $offset < $size ) {
			$chunk = (string) fread( $handle, 8 * 1024 * 1024 );
			SitePackage::append( $job['files']['export'], array( 'type' => 'db' ), $chunk );
			$offset             += strlen( $chunk );
			$job['cursor']       = array( 'offset' => $offset );
			$job['package_size'] = self::size( $job['files']['export'] );
			$job['step_rows']   += 1;
			if ( $budget->exhausted() ) {
				break;
			}
		}
		fclose( $handle );
		if ( $offset < $size ) {
			return false;
		}
		self::log( $job, 'Paquete: base de datos incluida (' . number_format( $size / 1048576, 1 ) . ' MB comprimida).' );
		return true;
	}

	/**
	 * Paquete: agrega los archivos de una carpeta.
	 *
	 * @param array  $job    Job.
	 * @param array  $step   Paso.
	 * @param Budget $budget Tiempo.
	 */
	private function site_files( array &$job, array $step, Budget $budget ): bool {
		$root = $step['root'];
		$send = $this->file_list( $job, $root, 'send' );
		self::rewind( $job['files']['export'], $job['package_size'] );
		do {
			$built = Package::build( $send, new PathGuard( $job['site']['roots'][ $root ] ), $job['cursor'], 8 * 1024 * 1024 );
			if ( $built['files'] > 0 ) {
				SitePackage::append(
					$job['files']['export'],
					array(
						'type' => 'files',
						'root' => $root,
					),
					$built['package']
				);
				$job['package_size'] = self::size( $job['files']['export'] );
			}
			$stats             = &$job['file_stats'][ $root ];
			$stats['sent']    += $built['files'];
			$stats['bytes']   += $built['bytes'];
			$stats['missing'] += count( $built['missing'] );
			unset( $stats );
			$job['step_rows'] += intdiv( $built['bytes'], 65536 ) + 1;
			$job['cursor']     = $built['cursor'];
		} while ( null !== $job['cursor'] && ! $budget->exhausted() );
		if ( null === $job['cursor'] ) {
			self::log( $job, "Paquete ($root): archivos incluidos (" . number_format( $job['file_stats'][ $root ]['bytes'] / 1048576, 1 ) . ' MB).' );
			return true;
		}
		return false;
	}

	/**
	 * Importar un paquete: el dump va a un archivo aparte y los archivos a su lugar (media) o a la
	 * zona temporal (código), con las mismas protecciones que un pull.
	 *
	 * @param array  $job    Job.
	 * @param Budget $budget Tiempo.
	 *
	 * @throws \RuntimeException Si el paquete no es válido o no trae la base de datos.
	 */
	private function site_unpack( array &$job, Budget $budget ): bool {
		$applier = new Applier( $job['site']['roots'], $this->files_dir( $job ) );
		$offset  = (int) ( $job['cursor']['offset'] ?? 0 );
		self::rewind( $job['files']['import_gz'], $job['cursor']['gz'] ?? 0 );
		do {
			$block = SitePackage::read( $job['files']['import'], $offset );
			if ( null === $block ) {
				$job['cursor'] = null;
				if ( ! is_file( $job['files']['import_gz'] ) ) {
					throw new \RuntimeException( 'El paquete no trae base de datos.' );
				}
				self::log( $job, 'Paquete desempacado.' );
				return true;
			}
			if ( 'db' === $block['frame']['type'] ) {
				file_put_contents( $job['files']['import_gz'], $block['bytes'], FILE_APPEND );
			} elseif ( in_array( $block['frame']['root'], $job['options']['files'], true ) ) {
				$root = $block['frame']['root'];
				$this->record_files( $job, $root, $applier->receive( $root, $block['bytes'] ), 0, strlen( $block['bytes'] ) );
			}
			$offset            = $block['next'];
			$job['cursor']     = array(
				'offset' => $offset,
				'gz'     => self::size( $job['files']['import_gz'] ),
			);
			$job['step_rows'] += intdiv( strlen( $block['bytes'] ), 65536 );
		} while ( ! $budget->exhausted() );
		return false;
	}

	/**
	 * Tamaño actual de un archivo (0 si no existe).
	 *
	 * @param string $path Archivo.
	 */
	private static function size( string $path ): int {
		clearstatcache( true, $path );
		return is_file( $path ) ? (int) filesize( $path ) : 0;
	}

	/**
	 * Recorta un archivo al tamaño guardado en el cursor: si una petición se cortó después de
	 * agregar un bloque pero antes de guardar el job, el bloque se agregaría dos veces.
	 *
	 * @param string $path Archivo.
	 * @param int    $size Tamaño confirmado.
	 */
	private static function rewind( string $path, int $size ): void {
		if ( self::size( $path ) > $size ) {
			$handle = fopen( $path, 'r+b' );
			ftruncate( $handle, $size );
			fclose( $handle );
		}
	}
}

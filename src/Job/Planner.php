<?php
/**
 * Convierte una petición en un plan de pasos.
 *
 * @package Siteferry
 */

namespace Siteferry\Job;

use Siteferry\Engine\Schema;
use Siteferry\Files\Roots;
use Siteferry\Files\SitePackage;

/**
 * Crea el job (un array serializable) para exportar, reemplazar o importar.
 */
final class Planner {

	public const INTENTS = array( 'export', 'replace', 'import', 'pull', 'push', 'receive', 'undo' );

	/**
	 * Crea un job.
	 *
	 * @param string $intent export | replace | import | pull | push | receive (lado remoto de un push).
	 * @param array  $input  tables, pairs, case_sensitive, regex, replace_guids, backup, auto_url, file, file_owned,
	 *                       source_prefix; para pull/push también remote (url, key, verify_ssl) y remote_info (hello).
	 * @param array  $site   prefix, home, siteurl, storage, host, plugin, multisite, options_table, preserve.
	 * @param Schema $schema Estructura de la BD.
	 *
	 * @throws \InvalidArgumentException Si la petición no es válida.
	 */
	public static function create( string $intent, array $input, array $site, Schema $schema ): array {
		if ( ! in_array( $intent, self::INTENTS, true ) ) {
			throw new \InvalidArgumentException( 'Acción desconocida.' );
		}
		$pairs = array();
		foreach ( (array) ( $input['pairs'] ?? array() ) as $pair ) {
			$find = (string) ( $pair[0] ?? '' );
			if ( '' !== $find ) {
				$pairs[] = array( $find, (string) ( $pair[1] ?? '' ) );
			}
		}
		if ( 'replace' === $intent && ! $pairs ) {
			throw new \InvalidArgumentException( 'Indica al menos un texto a buscar.' );
		}

		$all    = $schema->stats( $site['prefix'] );
		$tables = self::selected( $all, $input );

		$id  = bin2hex( random_bytes( 8 ) );
		$job = array(
			'id'          => $id,
			'intent'      => $intent,
			'status'      => 'pending',
			'created'     => time(),
			'heartbeat'   => time(),
			'site'        => $site,
			'options'     => array(
				'pairs'          => $pairs,
				'case_sensitive' => ! empty( $input['case_sensitive'] ),
				'regex'          => ! empty( $input['regex'] ),
				'replace_guids'  => ! empty( $input['replace_guids'] ),
				'backup'         => ! empty( $input['backup'] ),
				'auto_url'       => ! empty( $input['auto_url'] ),
				'source_prefix'  => preg_replace( '/[^A-Za-z0-9_]/', '', (string) ( $input['source_prefix'] ?? '' ) ),
				'files'          => array_values( array_intersect( Roots::ALL, (array) ( $input['files'] ?? array() ) ) ),
				'delete_files'   => ! empty( $input['delete_files'] ),
				'database'       => ! array_key_exists( 'database', $input ) || ! empty( $input['database'] ),
			),
			'steps'       => array( self::step( 'preflight', 'Comprobaciones' ) ),
			'index'       => 0,
			'cursor'      => null,
			'step_rows'   => 0,
			'files'       => array(),
			'owned'       => array(),
			'swap'        => array(),
			'fks'         => array(),
			'file_fks'    => array(),
			'ref_map'     => array(),
			'prefix_from' => '',
			'prefix_to'   => '',
			'import'      => array(
				'tables'  => array(),
				'skipped' => 0,
			),
			'max_bytes'   => 512 * 1024,
			'rows'        => 0,
			'log'         => array(),
			'warnings'    => array(),
			'file_stats'  => array(),
			'error'       => null,
		);

		$name = static fn( string $suffix ) => $site['storage'] . '/' . $site['host'] . '-' . gmdate( 'Ymd-His' ) . '-' . $id . $suffix . '.sql.gz';

		if ( 'export' === $intent ) {
			$roots = array_values( array_intersect( $job['options']['files'], array_keys( $site['roots'] ?? array() ) ) );
			if ( ! $roots ) {
				$job['files']['export'] = $name( '' );
				$job['owned'][]         = 'export';
				self::add_dump( $job, 'export', $tables, $all, true );
				return $job;
			}
			// Sitio completo: dump temporal + carpetas, todo en un paquete .sfy.
			$job['files']['dump']   = $name( '-db' );
			$job['files']['export'] = substr( $name( '' ), 0, -strlen( '.sql.gz' ) ) . '.sfy';
			array_push( $job['owned'], 'dump', 'export' );
			self::add_dump( $job, 'dump', $tables, $all, true );
			$job['steps'][] = self::step( 'site_start', 'Paquete: inicio' ) + array( 'roots' => $roots );
			$job['steps'][] = self::step( 'site_db', 'Paquete: base de datos' );
			foreach ( $roots as $root ) {
				$job['steps'][]             = self::step( 'files_scan', "Paquete ($root): escaneo" ) + array(
					'root' => $root,
					'side' => 'local',
					'out'  => 'send',
				);
				$job['steps'][]             = self::step( 'site_files', "Paquete ($root): archivos" ) + array( 'root' => $root );
				$job['file_stats'][ $root ] = array(
					'send'    => 0,
					'bytes'   => 0,
					'delete'  => 0,
					'sent'    => 0,
					'failed'  => 0,
					'missing' => 0,
				);
			}
			$job['steps'][] = self::step( 'cleanup', 'Limpieza' ); // El dump suelto ya está dentro del paquete.
			return $job;
		}

		if ( 'pull' === $intent || 'push' === $intent ) {
			self::prepare_remote( $job, $intent, $input );
		}

		// En un push el backup lo hace el sitio remoto (su job "receive"); un pull de solo archivos no toca la BD.
		if ( $job['options']['backup'] && 'push' !== $intent && ( 'pull' !== $intent || $job['options']['database'] ) ) {
			$job['files']['backup'] = $name( '-backup' );
			$job['owned'][]         = 'backup';
			self::add_dump( $job, 'backup', array_keys( $all ), $all, false );
		}

		if ( 'replace' === $intent ) {
			foreach ( $tables as $table ) {
				$job['steps'][]                      = self::copy( $table, Schema::TMP . $table, $table, 'table', $all[ $table ]['rows'], $table );
				$job['swap'][ Schema::TMP . $table ] = $table;
			}
			$job['steps'][] = self::step( 'finalize', 'Cambio de tablas' );
			return $job;
		}

		if ( 'receive' === $intent ) {
			$job['steps'][] = self::step( 'receive', 'Recibiendo datos' );
			$job['steps'][] = self::step( 'finalize', 'Cambio de tablas' );
			return $job;
		}

		// Orden: archivos a su lugar (media) o a la zona temporal (código) → BD → cambio de BD → borrados y cambio
		// de carpetas de código. El sitio solo cambia de verdad al final.
		if ( 'pull' === $intent ) {
			self::add_file_transfer( $job, 'pull' );
			if ( $job['options']['database'] ) {
				$remote = $input['remote_info']['tables'];
				foreach ( self::selected( $remote, $input ) as $table ) {
					$final                               = $job['prefix_to'] . substr( $table, strlen( $job['prefix_from'] ) );
					$job['steps'][]                      = self::copy( $table, Schema::TMP . $final, $table, 'table', (int) $remote[ $table ]['rows'], $final ) + array( 'remote' => true );
					$job['swap'][ Schema::TMP . $final ] = $final;
					$job['ref_map'][ $table ]            = $final;
				}
				$job['steps'][] = self::step( 'finalize', 'Cambio de tablas' );
			}
			self::add_file_finalize( $job );
			return $job;
		}

		if ( 'push' === $intent ) {
			self::add_file_transfer( $job, 'push' );
			if ( $job['options']['database'] ) {
				$job['steps'][] = self::step( 'push_begin', 'Conexión con el sitio remoto' );
				$job['steps'][] = self::step( 'remote_wait', 'Preparación en el sitio remoto' ) + array( 'until' => 'ready' );
				foreach ( $tables as $table ) {
					$final                               = $job['prefix_to'] . substr( $table, strlen( $job['prefix_from'] ) );
					$job['steps'][]                      = self::copy( $table, Schema::TMP . $final, $table, 'remote', $all[ $table ]['rows'], $final );
					$job['swap'][ Schema::TMP . $final ] = $final;
					$job['ref_map'][ $table ]            = $final;
				}
				$job['steps'][] = self::step( 'push_finish', 'Envío terminado' );
				$job['steps'][] = self::step( 'remote_wait', 'Cambio de tablas en el sitio remoto' ) + array( 'until' => 'done' );
			}
			self::add_file_finalize( $job );
			return $job;
		}

		if ( 'undo' === $intent ) {
			// Deshacer: primero los archivos (desde la sesión) y luego la BD (importando su backup).
			$storage = $site['storage'] . '/';
			$dir     = (string) ( $input['files_dir'] ?? '' );
			if ( '' !== $dir && str_starts_with( $dir, $storage . 'files-' ) && is_dir( $dir ) ) {
				foreach ( array_intersect( Roots::ALL, (array) ( $input['roots'] ?? array() ) ) as $root ) {
					$job['steps'][] = self::step( 'files_undo', "Archivos ($root): deshacer" ) + array(
						'root' => $root,
						'dir'  => $dir,
					);
				}
			}
			$backup = (string) ( $input['db_backup'] ?? '' );
			if ( '' !== $backup && str_starts_with( $backup, $storage ) && is_file( $backup ) ) {
				self::add_import( $job, $backup, false );
			}
			if ( 1 === count( $job['steps'] ) ) {
				throw new \InvalidArgumentException( 'No hay nada que deshacer.' );
			}
			return $job;
		}

		// Importar.
		$file = (string) ( $input['file'] ?? '' );
		if ( '' === $file ) {
			throw new \InvalidArgumentException( 'Falta el archivo a importar.' );
		}
		if ( ! str_ends_with( strtolower( $file ), '.sfy' ) ) {
			self::add_import( $job, $file, ! empty( $input['file_owned'] ) );
			return $job;
		}
		// Paquete de sitio: se desempaca (archivos a su lugar o a la zona temporal, dump aparte), se importa la BD
		// y al final se cambian las carpetas de código.
		try {
			$meta = SitePackage::meta( $file );
		} catch ( \RuntimeException $e ) {
			throw new \InvalidArgumentException( $e->getMessage() );
		}
		$roots                     = array_values( array_intersect( Roots::ALL, (array) ( $meta['roots'] ?? array() ), array_keys( $site['roots'] ?? array() ) ) );
		$job['options']['files']   = $roots;
		$job['files']['import']    = $file;
		$job['files']['import_gz'] = $site['storage'] . '/import-' . $id . '.sql.gz';
		$job['owned'][]            = 'import_gz';
		if ( ! empty( $input['file_owned'] ) ) {
			$job['owned'][] = 'import';
		}
		foreach ( $roots as $root ) {
			$job['file_stats'][ $root ] = array(
				'send'    => 0,
				'bytes'   => 0,
				'delete'  => 0,
				'sent'    => 0,
				'failed'  => 0,
				'missing' => 0,
			);
		}
		$job['steps'][] = self::step( 'site_unpack', 'Paquete: desempacar' ) + array( 'est' => intdiv( (int) filesize( $file ), 65536 ) + 1 );
		$job['steps'][] = self::step( 'decompress', 'Descomprimir la base de datos' );
		$job['steps'][] = self::step( 'import', 'Cargar la base de datos' );
		$job['steps'][] = self::step( 'plan_import', 'Preparar las tablas' );
		self::add_file_finalize( $job );
		return $job;
	}

	/**
	 * Pasos para cargar un .sql / .sql.gz en tablas crudas y planear el resto.
	 *
	 * @param array  $job   Job.
	 * @param string $file  Archivo.
	 * @param bool   $owned Si el archivo es del plugin (se borra al terminar).
	 */
	private static function add_import( array &$job, string $file, bool $owned ): void {
		$job['files']['import'] = $file;
		if ( $owned ) {
			$job['owned'][] = 'import';
		}
		if ( str_ends_with( strtolower( $file ), '.gz' ) ) {
			$job['steps'][] = self::step( 'decompress', 'Descomprimir el archivo' );
		}
		$import         = self::step( 'import', 'Cargar el archivo' );
		$import['est']  = is_file( $file ) && ! str_ends_with( strtolower( $file ), '.gz' ) ? intdiv( (int) filesize( $file ), 200 ) : 1;
		$job['steps'][] = $import;
		$job['steps'][] = self::step( 'plan_import', 'Preparar las tablas' );
	}

	/**
	 * Pull/push: conexión, permisos del otro sitio, prefijos y pares de URL automáticos.
	 *
	 * @param array  $job    Job.
	 * @param string $intent pull | push.
	 * @param array  $input  Petición (remote, remote_info).
	 *
	 * @throws \InvalidArgumentException Si el otro sitio no lo permite o no es compatible.
	 */
	private static function prepare_remote( array &$job, string $intent, array $input ): void {
		$info = (array) ( $input['remote_info'] ?? array() );
		if ( empty( $info[ 'allow_' . $intent ] ) ) {
			throw new \InvalidArgumentException( 'pull' === $intent ? 'El sitio remoto no permite pull. Actívalo en su Siteferry.' : 'El sitio remoto no permite push. Actívalo en su Siteferry.' );
		}
		if ( ! empty( $info['multisite'] ) ) {
			throw new \InvalidArgumentException( 'El sitio remoto es multisite; todavía no está soportado.' );
		}
		$here = $job['site'];
		$from = 'pull' === $intent ? $info : $here;
		$to   = 'pull' === $intent ? $here : $info;

		$files = $job['options']['files'];
		if ( ! $files && ! $job['options']['database'] ) {
			throw new \InvalidArgumentException( 'Elige la base de datos, archivos o ambos.' );
		}
		foreach ( $files as $root ) {
			if ( ! isset( $here['roots'][ $root ] ) || ! in_array( $root, (array) ( $info['roots'] ?? array() ), true ) ) {
				throw new \InvalidArgumentException( "La carpeta $root no está disponible en los dos sitios." );
			}
			if ( 'pull' === $intent && empty( $info['allow_pull_files'] ) ) {
				throw new \InvalidArgumentException( 'El sitio remoto no permite pull de archivos. Actívalo en su Siteferry.' );
			}
			if ( 'push' === $intent && Roots::is_code( $root ) && empty( $info['allow_push_code'] ) ) {
				throw new \InvalidArgumentException( 'El sitio remoto no permite push de código (temas y plugins). Actívalo en su Siteferry.' );
			}
		}

		$job['prefix_from'] = (string) $from['prefix'];
		$job['prefix_to']   = (string) $to['prefix'];
		$job['remote']      = array(
			'url'        => (string) $input['remote']['url'],
			'key'        => (string) $input['remote']['key'],
			'verify_ssl' => ! empty( $input['remote']['verify_ssl'] ),
			'home'       => (string) $info['home'],
			// Trozos de push: el SQL viaja comprimido y en base64 dentro del límite del otro servidor.
			'chunk'      => max( 256 * 1024, min( 4 * 1024 * 1024, (int) ( (int) $info['max_request'] / 1.5 ) ) ),
		);

		if ( $job['options']['auto_url'] ) {
			$known = array_column( $job['options']['pairs'], 0 );
			$auto  = array();
			foreach ( array( 'siteurl', 'home' ) as $option ) {
				$old = rtrim( (string) $from[ $option ], '/' );
				$new = rtrim( (string) $to[ $option ], '/' );
				if ( '' !== $old && $old !== $new && ! in_array( $old, $known, true ) ) {
					$auto[ $old ] = $new;
				}
			}
			uksort( $auto, static fn( $a, $b ) => strlen( $b ) <=> strlen( $a ) );
			foreach ( $auto as $old => $new ) {
				$job['options']['pairs'][] = array( $old, $new );
			}
		}
	}

	/**
	 * Pasos de archivos hasta dejarlos en su lugar (media) o en la zona temporal (código).
	 *
	 * @param array  $job    Job.
	 * @param string $intent pull | push.
	 */
	private static function add_file_transfer( array &$job, string $intent ): void {
		$source = 'pull' === $intent ? 'remote' : 'local';
		$target = 'pull' === $intent ? 'local' : 'remote';
		foreach ( $job['options']['files'] as $root ) {
			$job['steps'][] = self::step( 'files_scan', "Archivos ($root): origen" ) + array(
				'root' => $root,
				'side' => $source,
				'out'  => 'source',
			);
			$job['steps'][] = self::step( 'files_scan', "Archivos ($root): destino" ) + array(
				'root' => $root,
				'side' => $target,
				'out'  => 'target',
			);
			$job['steps'][] = self::step( 'files_diff', "Archivos ($root): comparación" ) + array( 'root' => $root );
			$job['steps'][] = self::step( 'files_transfer', "Archivos ($root): envío" ) + array( 'root' => $root );
		}
	}

	/**
	 * Pasos finales de archivos: borrados y cambio de carpetas de código.
	 *
	 * @param array $job Job.
	 */
	private static function add_file_finalize( array &$job ): void {
		foreach ( $job['options']['files'] as $root ) {
			$job['steps'][] = self::step( 'files_finalize', "Archivos ($root): cierre" ) + array( 'root' => $root );
		}
	}

	/**
	 * Tablas elegidas de una lista (todas si no se eligió ninguna).
	 *
	 * @param array $available Estadísticas por tabla.
	 * @param array $input     Petición (tables).
	 * @return string[]
	 */
	private static function selected( array $available, array $input ): array {
		$tables = array_values( array_intersect( array_keys( $available ), (array) ( $input['tables'] ?? array() ) ) );
		return $tables ? $tables : array_keys( $available );
	}

	/**
	 * Agrega los pasos de un volcado a archivo.
	 *
	 * @param array    $job     Job.
	 * @param string   $file    Clave del archivo (export | backup).
	 * @param string[] $tables  Tablas.
	 * @param array    $all     Estadísticas por tabla.
	 * @param bool     $replace Aplicar reemplazos.
	 */
	private static function add_dump( array &$job, string $file, array $tables, array $all, bool $replace ): void {
		$label          = 'backup' === $file ? 'Backup' : 'Exportación';
		$job['steps'][] = self::step( 'header', $label . ': inicio' ) + array( 'file' => $file );
		foreach ( $tables as $table ) {
			$step            = self::copy( $table, $table, $table, 'file', $all[ $table ]['rows'], $table );
			$step['file']    = $file;
			$step['label']   = $label . ': ' . $table;
			$step['replace'] = $replace;
			$job['steps'][]  = $step;
		}
		$job['steps'][] = self::step( 'footer', $label . ': cierre' ) + array( 'file' => $file );
	}

	/**
	 * Paso genérico.
	 *
	 * @param string $type  Tipo.
	 * @param string $label Texto para la interfaz.
	 */
	public static function step( string $type, string $label ): array {
		return array(
			'type'  => $type,
			'label' => $label,
			'est'   => 1,
		);
	}

	/**
	 * Paso de copia de una tabla.
	 *
	 * @param string $source  Tabla de origen.
	 * @param string $target  Tabla destino.
	 * @param string $logical Nombre en el sitio de origen.
	 * @param string $sink    file | table.
	 * @param int    $rows    Filas estimadas.
	 * @param string $final_name Nombre final de la tabla.
	 */
	public static function copy( string $source, string $target, string $logical, string $sink, int $rows, string $final_name ): array {
		return array(
			'type'    => 'copy',
			'label'   => $final_name,
			'source'  => $source,
			'target'  => $target,
			'logical' => $logical,
			'final'   => $final_name,
			'sink'    => $sink,
			'replace' => true,
			'est'     => max( 1, $rows ),
		);
	}
}

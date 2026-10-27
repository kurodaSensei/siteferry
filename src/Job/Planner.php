<?php
/**
 * Turns a request into a plan of steps.
 *
 * @package Siteferry
 */

namespace Siteferry\Job;

use Siteferry\Engine\Schema;
use Siteferry\Files\Roots;
use Siteferry\Files\SitePackage;

/**
 * Creates the job (a serializable array) to export, replace or import.
 */
final class Planner {

	public const INTENTS = array( 'export', 'replace', 'import', 'pull', 'push', 'receive', 'undo' );

	/**
	 * Creates a job.
	 *
	 * @param string $intent export | replace | import | pull | push | receive (remote side of a push).
	 * @param array  $input  tables, pairs, case_sensitive, regex, replace_guids, backup, auto_url, file, file_owned,
	 *                       source_prefix; for pull/push also remote (url, key, verify_ssl) and remote_info (hello).
	 * @param array  $site   prefix, home, siteurl, storage, host, plugin, multisite, options_table, preserve.
	 * @param Schema $schema Database structure.
	 *
	 * @throws \InvalidArgumentException If the request is invalid.
	 */
	public static function create( string $intent, array $input, array $site, Schema $schema ): array {
		if ( ! in_array( $intent, self::INTENTS, true ) ) {
			throw new \InvalidArgumentException( 'Unknown action.' );
		}
		$pairs = array();
		foreach ( (array) ( $input['pairs'] ?? array() ) as $pair ) {
			$find = (string) ( $pair[0] ?? '' );
			if ( '' !== $find ) {
				$pairs[] = array( $find, (string) ( $pair[1] ?? '' ) );
			}
		}
		if ( 'replace' === $intent && ! $pairs ) {
			throw new \InvalidArgumentException( 'Enter at least one text to search for.' );
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
			'steps'       => array( self::step( 'preflight', 'Preflight checks' ) ),
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
			// Full site: temporary dump + folders, all in one .sfy package.
			$job['files']['dump']   = $name( '-db' );
			$job['files']['export'] = substr( $name( '' ), 0, -strlen( '.sql.gz' ) ) . '.sfy';
			array_push( $job['owned'], 'dump', 'export' );
			self::add_dump( $job, 'dump', $tables, $all, true );
			$job['steps'][] = self::step( 'site_start', 'Package: start' ) + array( 'roots' => $roots );
			$job['steps'][] = self::step( 'site_db', 'Package: database' );
			foreach ( $roots as $root ) {
				$job['steps'][]             = self::step( 'files_scan', "Package ($root): scan" ) + array(
					'root' => $root,
					'side' => 'local',
					'out'  => 'send',
				);
				$job['steps'][]             = self::step( 'site_files', "Package ($root): files" ) + array( 'root' => $root );
				$job['file_stats'][ $root ] = array(
					'send'    => 0,
					'bytes'   => 0,
					'delete'  => 0,
					'sent'    => 0,
					'failed'  => 0,
					'missing' => 0,
				);
			}
			$job['steps'][] = self::step( 'cleanup', 'Cleanup' ); // The loose dump is already inside the package.
			return $job;
		}

		if ( 'pull' === $intent || 'push' === $intent ) {
			self::prepare_remote( $job, $intent, $input );
		}

		// In a push the backup is made by the remote site (its "receive" job); a files-only pull does not touch the database.
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
			$job['steps'][] = self::step( 'finalize', 'Swap tables' );
			return $job;
		}

		if ( 'receive' === $intent ) {
			$job['steps'][] = self::step( 'receive', 'Receiving data' );
			$job['steps'][] = self::step( 'finalize', 'Swap tables' );
			return $job;
		}

		// Order: files to their place (media) or to the staging area (code) → DB → DB swap → deletions and swap
		// of code folders. The site only really changes at the end.
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
				$job['steps'][] = self::step( 'finalize', 'Swap tables' );
			}
			self::add_file_finalize( $job );
			return $job;
		}

		if ( 'push' === $intent ) {
			self::add_file_transfer( $job, 'push' );
			if ( $job['options']['database'] ) {
				$job['steps'][] = self::step( 'push_begin', 'Connecting to the remote site' );
				$job['steps'][] = self::step( 'remote_wait', 'Preparing the remote site' ) + array( 'until' => 'ready' );
				foreach ( $tables as $table ) {
					$final                               = $job['prefix_to'] . substr( $table, strlen( $job['prefix_from'] ) );
					$job['steps'][]                      = self::copy( $table, Schema::TMP . $final, $table, 'remote', $all[ $table ]['rows'], $final );
					$job['swap'][ Schema::TMP . $final ] = $final;
					$job['ref_map'][ $table ]            = $final;
				}
				$job['steps'][] = self::step( 'push_finish', 'Send complete' );
				$job['steps'][] = self::step( 'remote_wait', 'Swap tables on the remote site' ) + array( 'until' => 'done' );
			}
			self::add_file_finalize( $job );
			return $job;
		}

		if ( 'undo' === $intent ) {
			// Undo: files first (from the session), then the database (importing its backup).
			$storage = $site['storage'] . '/';
			$dir     = (string) ( $input['files_dir'] ?? '' );
			if ( '' !== $dir && str_starts_with( $dir, $storage . 'files-' ) && is_dir( $dir ) ) {
				foreach ( array_intersect( Roots::ALL, (array) ( $input['roots'] ?? array() ) ) as $root ) {
					$job['steps'][] = self::step( 'files_undo', "Files ($root): undo" ) + array(
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
				throw new \InvalidArgumentException( 'There is nothing to undo.' );
			}
			return $job;
		}

		// Importar.
		$file = (string) ( $input['file'] ?? '' );
		if ( '' === $file ) {
			throw new \InvalidArgumentException( 'The file to import is missing.' );
		}
		if ( ! str_ends_with( strtolower( $file ), '.sfy' ) ) {
			self::add_import( $job, $file, ! empty( $input['file_owned'] ) );
			return $job;
		}
		// Site package: it is unpacked (files to their place or to the staging area, dump separately), the DB is imported
		// and finally the code folders are swapped.
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
		$job['steps'][] = self::step( 'site_unpack', 'Package: unpack' ) + array( 'est' => intdiv( (int) filesize( $file ), 65536 ) + 1 );
		$job['steps'][] = self::step( 'decompress', 'Decompress the database' );
		$job['steps'][] = self::step( 'import', 'Load the database' );
		$job['steps'][] = self::step( 'plan_import', 'Prepare the tables' );
		self::add_file_finalize( $job );
		return $job;
	}

	/**
	 * Steps to load a .sql / .sql.gz into raw tables and plan the rest.
	 *
	 * @param array  $job   Job.
	 * @param string $file  File.
	 * @param bool   $owned Whether the file belongs to the plugin (deleted when finished).
	 */
	private static function add_import( array &$job, string $file, bool $owned ): void {
		$job['files']['import'] = $file;
		if ( $owned ) {
			$job['owned'][] = 'import';
		}
		if ( str_ends_with( strtolower( $file ), '.gz' ) ) {
			$job['steps'][] = self::step( 'decompress', 'Decompress the file' );
		}
		$import         = self::step( 'import', 'Load the file' );
		$import['est']  = is_file( $file ) && ! str_ends_with( strtolower( $file ), '.gz' ) ? intdiv( (int) filesize( $file ), 200 ) : 1;
		$job['steps'][] = $import;
		$job['steps'][] = self::step( 'plan_import', 'Prepare the tables' );
	}

	/**
	 * Pull/push: connection, the other site's permissions, prefixes and automatic URL pairs.
	 *
	 * @param array  $job    Job.
	 * @param string $intent pull | push.
	 * @param array  $input  Request (remote, remote_info).
	 *
	 * @throws \InvalidArgumentException If the other site does not allow it or is not compatible.
	 */
	private static function prepare_remote( array &$job, string $intent, array $input ): void {
		$info = (array) ( $input['remote_info'] ?? array() );
		if ( empty( $info[ 'allow_' . $intent ] ) ) {
			throw new \InvalidArgumentException( 'pull' === $intent ? 'The remote site does not allow pull. Enable it in its Siteferry.' : 'The remote site does not allow push. Enable it in its Siteferry.' );
		}
		if ( ! empty( $info['multisite'] ) ) {
			throw new \InvalidArgumentException( 'The remote site is multisite; that is not supported yet.' );
		}
		$here = $job['site'];
		$from = 'pull' === $intent ? $info : $here;
		$to   = 'pull' === $intent ? $here : $info;

		$files = $job['options']['files'];
		if ( ! $files && ! $job['options']['database'] ) {
			throw new \InvalidArgumentException( 'Choose the database, files, or both.' );
		}
		foreach ( $files as $root ) {
			if ( ! isset( $here['roots'][ $root ] ) || ! in_array( $root, (array) ( $info['roots'] ?? array() ), true ) ) {
				throw new \InvalidArgumentException( "The $root folder is not available on both sites." );
			}
			if ( 'pull' === $intent && empty( $info['allow_pull_files'] ) ) {
				throw new \InvalidArgumentException( 'The remote site does not allow file pulls. Enable it in its Siteferry.' );
			}
			if ( 'push' === $intent && Roots::is_code( $root ) && empty( $info['allow_push_code'] ) ) {
				throw new \InvalidArgumentException( 'The remote site does not allow code pushes (themes and plugins). Enable it in its Siteferry.' );
			}
		}

		$job['prefix_from'] = (string) $from['prefix'];
		$job['prefix_to']   = (string) $to['prefix'];
		$job['remote']      = array(
			'url'        => (string) $input['remote']['url'],
			'key'        => (string) $input['remote']['key'],
			'verify_ssl' => ! empty( $input['remote']['verify_ssl'] ),
			'home'       => (string) $info['home'],
			// Push chunks: the SQL travels compressed and base64-encoded, within the other server's limit.
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
	 * File steps up to placing them in their place (media) or in the staging area (code).
	 *
	 * @param array  $job    Job.
	 * @param string $intent pull | push.
	 */
	private static function add_file_transfer( array &$job, string $intent ): void {
		$source = 'pull' === $intent ? 'remote' : 'local';
		$target = 'pull' === $intent ? 'local' : 'remote';
		foreach ( $job['options']['files'] as $root ) {
			$job['steps'][] = self::step( 'files_scan', "Files ($root): source" ) + array(
				'root' => $root,
				'side' => $source,
				'out'  => 'source',
			);
			$job['steps'][] = self::step( 'files_scan', "Files ($root): destination" ) + array(
				'root' => $root,
				'side' => $target,
				'out'  => 'target',
			);
			$job['steps'][] = self::step( 'files_diff', "Files ($root): compare" ) + array( 'root' => $root );
			$job['steps'][] = self::step( 'files_transfer', "Files ($root): transfer" ) + array( 'root' => $root );
		}
	}

	/**
	 * Final file steps: deletions and swap of code folders.
	 *
	 * @param array $job Job.
	 */
	private static function add_file_finalize( array &$job ): void {
		foreach ( $job['options']['files'] as $root ) {
			$job['steps'][] = self::step( 'files_finalize', "Files ($root): finalize" ) + array( 'root' => $root );
		}
	}

	/**
	 * Selected tables from a list (all of them if none was selected).
	 *
	 * @param array $available Per-table stats.
	 * @param array $input     Request (tables).
	 * @return string[]
	 */
	private static function selected( array $available, array $input ): array {
		$tables = array_values( array_intersect( array_keys( $available ), (array) ( $input['tables'] ?? array() ) ) );
		return $tables ? $tables : array_keys( $available );
	}

	/**
	 * Adds the steps of a dump to a file.
	 *
	 * @param array    $job     Job.
	 * @param string   $file    File key (export | backup).
	 * @param string[] $tables  Tables.
	 * @param array    $all     Per-table stats.
	 * @param bool     $replace Apply replacements.
	 */
	private static function add_dump( array &$job, string $file, array $tables, array $all, bool $replace ): void {
		$label          = 'backup' === $file ? 'Backup' : 'Export';
		$job['steps'][] = self::step( 'header', $label . ': start' ) + array( 'file' => $file );
		foreach ( $tables as $table ) {
			$step            = self::copy( $table, $table, $table, 'file', $all[ $table ]['rows'], $table );
			$step['file']    = $file;
			$step['label']   = $label . ': ' . $table;
			$step['replace'] = $replace;
			$job['steps'][]  = $step;
		}
		$job['steps'][] = self::step( 'footer', $label . ': finish' ) + array( 'file' => $file );
	}

	/**
	 * Generic step.
	 *
	 * @param string $type  Type.
	 * @param string $label UI text.
	 */
	public static function step( string $type, string $label ): array {
		return array(
			'type'  => $type,
			'label' => $label,
			'est'   => 1,
		);
	}

	/**
	 * Table copy step.
	 *
	 * @param string $source  Source table.
	 * @param string $target  Destination table.
	 * @param string $logical Name on the source site.
	 * @param string $sink    file | table.
	 * @param int    $rows    Estimated rows.
	 * @param string $final_name Final table name.
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

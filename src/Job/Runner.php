<?php
/**
 * Runs the steps of a job.
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
 * Advances a job as far as the budget allows. It does not depend on WordPress:
 * it takes the job as an array and returns the updated job.
 */
final class Runner {

	/**
	 * Database structure.
	 *
	 * @var Schema
	 */
	private Schema $schema;

	/**
	 * Constructor.
	 *
	 * @param Db            $db     Connection.
	 * @param \Closure|null $remote fn(array $job, string $op, array $args, ?string $binary = null): array — call to the remote site (pull/push).
	 */
	public function __construct( private Db $db, private ?\Closure $remote = null ) {
		$this->schema = new Schema( $db );
	}

	/**
	 * Advances the job. If it fails, it is left in the "failed" state with the error; live tables are
	 * not touched until the final step.
	 *
	 * @param array  $job    Job.
	 * @param Budget $budget Available time.
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
							break; // Waiting for data from the other site.
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
				unset( $job['remote']['key'] ); // The other site's key is not kept longer than necessary.
				self::log( $job, 'Done.' );
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
	 * Cancels: deletes temporary tables and half-written files.
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
				self::log( $job, 'Could not cancel on the remote site: ' . $e->getMessage() );
			}
		}
		// The backup is never deleted here: it may be all that is left if something failed. wp-cron cleans it up after 7 days.
		foreach ( array_diff( $job['owned'], array( 'backup' ) ) as $key ) {
			if ( isset( $job['files'][ $key ] ) ) {
				self::delete( $job['files'][ $key ] );
			}
		}
		$job['status'] = 'cancelled';
		unset( $job['remote']['key'] );
		self::log( $job, 'Cancelled.' );
		return $job;
	}

	/**
	 * Whether the job is still in progress.
	 *
	 * @param array|null $job Job.
	 */
	public static function is_open( ?array $job ): bool {
		return $job && in_array( $job['status'], array( 'pending', 'running' ), true );
	}

	/**
	 * Approximate percentage, weighted by rows.
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
	 * Appends a line to the job log (the last 200 are kept).
	 *
	 * @param array  $job     Job.
	 * @param string $message Text.
	 */
	public static function log( array &$job, string $message ): void {
		$job['log'][] = gmdate( 'H:i:s' ) . ' ' . $message;
		$job['log']   = array_slice( $job['log'], -200 );
	}

	/**
	 * Runs a chunk of the current step.
	 *
	 * @param array  $job    Job.
	 * @param array  $step   Step.
	 * @param Budget $budget Time.
	 * @return bool Whether the step finished.
	 *
	 * @throws \RuntimeException If the step fails.
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
				self::log( $job, "Files ({$step['root']}): {$stats['restored']} restored, {$stats['removed']} moved aside (to undone/)." );
				return true;

			case 'push_begin':
				$job['remote']['job'] = $this->remote(
					$job,
					'push_begin',
					array(
						'backup'  => $job['options']['backup'],
						'session' => $job['id'], // The remote joins the DB and files of this push into a single undo point.
					)
				)['id'];
				$job['remote']['seq'] = 0;
				self::log( $job, 'The remote site started receiving.' );
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
				$this->resume_import( $job );
				$result                    = SqlFileReader::read(
					$job['files']['import_sql'] ?? $job['files']['import'],
					(int) ( $job['cursor']['offset'] ?? 0 ),
					$budget,
					function ( string $sql, int $offset, int $skipped ) use ( &$job ) {
						$this->import_statement( $job, $sql );
						$this->record_import_progress( $job, $offset, $skipped );
					}
				);
				$job['cursor']             = array( 'offset' => $result['offset'] );
				$job['step_rows']          = intdiv( $result['offset'], 200 );
				$job['import']['skipped'] += $result['skipped'];
				if ( $result['done'] ) {
					self::log( $job, count( $job['import']['tables'] ) . ' tables loaded.' );
					if ( $job['import']['skipped'] ) {
						self::log( $job, $job['import']['skipped'] . ' statements skipped (views, triggers or procedures).' );
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
					'pull' === $job['intent'] ? array() : $job['ref_map'], // In a pull the clauses already arrive validated and pointing here.
					$job['site']['options_table'],
					$job['site']['preserve'],
					$job['site']['plugin']
				);
				foreach ( $warnings as $warning ) {
					$job['warnings'][] = $warning;
					self::log( $job, $warning );
				}
				self::log( $job, count( $job['swap'] ) . ' tables replaced.' );
				return true;

			case 'cleanup':
				foreach ( array_keys( $job['import']['tables'] ) as $name ) {
					if ( ! isset( $job['swap'][ Schema::RAW . $name ] ) ) {
						$this->db->query( 'DROP TABLE IF EXISTS ' . Db::ident( Schema::RAW . $name ) );
					}
				}
				foreach ( array( 'import', 'import_sql', 'import_gz', 'import_progress', 'dump' ) as $key ) {
					if ( in_array( $key, $job['owned'], true ) && isset( $job['files'][ $key ] ) ) {
						self::delete( $job['files'][ $key ] );
					}
				}
				return true;
		}
		throw new \RuntimeException( 'Unknown step: ' . $step['type'] );
	}

	/**
	 * Picks up the progress of a request that was cut off after running import statements but
	 * before the job was saved. Those statements are already in the raw tables, so running them
	 * again would fail with "Duplicate entry" (or silently duplicate rows in a table without a
	 * primary key). The load continues right after the last statement that was recorded.
	 *
	 * @param array $job Job.
	 */
	private function resume_import( array &$job ): void {
		$job['files']['import_progress'] ??= $job['site']['storage'] . '/import-' . $job['id'] . '.sql.progress';
		if ( ! in_array( 'import_progress', $job['owned'], true ) ) {
			$job['owned'][] = 'import_progress';
		}
		$file     = $job['files']['import_progress'];
		$progress = is_file( $file ) ? unserialize( (string) file_get_contents( $file ), array( 'allowed_classes' => false ) ) : null; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- Our own file, no classes allowed.
		if ( is_array( $progress ) && $progress['offset'] > (int) ( $job['cursor']['offset'] ?? 0 ) ) {
			$job['cursor']            = array( 'offset' => $progress['offset'] );
			$job['import']['tables']  = $progress['tables'];
			$job['import']['skipped'] = $progress['skipped'];
			$job['fks']               = $progress['fks'];
			self::log( $job, 'A previous request was cut off while loading; continuing where it stopped.' );
		}
	}

	/**
	 * Records the load progress after a statement, so a repeated request can resume from it.
	 * The file is replaced atomically: a request cut off mid-write leaves the previous record.
	 *
	 * @param array $job     Job.
	 * @param int   $offset  Offset right after the statement.
	 * @param int   $skipped Statements the reader skipped so far in this call.
	 *
	 * @throws \RuntimeException If it cannot be written.
	 */
	private function record_import_progress( array $job, int $offset, int $skipped ): void {
		// ponytail: a cut between a statement and this write still repeats that one statement; closing it needs the progress in the same transaction as the INSERT.
		$file = $job['files']['import_progress'];
		$data = serialize( // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Read back only by resume_import(), without classes.
			array(
				'offset'  => $offset,
				'tables'  => $job['import']['tables'],
				'skipped' => $job['import']['skipped'] + $skipped,
				'fks'     => $job['fks'],
			)
		);
		if ( false === file_put_contents( $file . '.tmp', $data ) || ! rename( $file . '.tmp', $file ) ) {
			throw new \RuntimeException( 'Cannot write ' . $file );
		}
	}

	/**
	 * Copies a chunk of a table.
	 *
	 * @param array  $job    Job.
	 * @param array  $step   Copy step.
	 * @param Budget $budget Time.
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
		// Dumps to a file are literal copies of this site: no prefix change.
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
			// The cursor only advances if the other site confirmed the chunk; chunks are numbered.
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
			self::log( $job, $step['label'] . ': ' . $job['step_rows'] . ' rows' );
			return true;
		}
		return false;
	}

	/**
	 * Checks before touching anything.
	 *
	 * @param array $job Job.
	 *
	 * @throws \RuntimeException With all the problems found.
	 */
	private function preflight( array &$job ): void {
		$errors = array();
		if ( $job['site']['multisite'] ) {
			$errors[] = 'Multisite is not supported yet.';
		}
		if ( $job['options']['regex'] ) {
			foreach ( $job['options']['pairs'] as [ $pattern ] ) {
				if ( false === @preg_match( $pattern, '' ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
					$errors[] = 'Invalid regular expression: ' . $pattern;
				}
			}
		}
		foreach ( $job['steps'] as $step ) {
			if ( 'copy' !== $step['type'] ) {
				continue;
			}
			if ( empty( $step['remote'] ) && ! $this->schema->exists( $step['source'] ) ) {
				$errors[] = 'Table not found: ' . $step['source'];
			} elseif ( 'table' === $step['sink'] && strlen( $step['final'] ) > Schema::MAX_NAME ) {
				$errors[] = 'Table name too long: ' . $step['final'];
			}
		}
		$storage = $job['site']['storage'];
		if ( ! is_dir( $storage ) || ! is_writable( $storage ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable
			$errors[] = 'Cannot write to the files folder: ' . $storage;
		}
		if ( isset( $job['files']['import'] ) && ! is_readable( $job['files']['import'] ) ) {
			$errors[] = 'Cannot read the file to import.';
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
			self::log( $job, count( $leftovers ) . ' temporary tables from a previous attempt removed.' );
		}
		self::log( $job, 'Preflight checks passed.' );
	}

	/**
	 * Decompresses the uploaded .sql.gz.
	 *
	 * @param array $job Job.
	 *
	 * @throws \RuntimeException If it fails.
	 */
	private function decompress( array &$job ): void {
		// ponytail: it is decompressed in a single request; with multi-GB dumps and little time per request, upload the uncompressed .sql.
		$target = $job['site']['storage'] . '/import-' . $job['id'] . '.sql';
		$in     = gzopen( $job['files']['import_gz'] ?? $job['files']['import'], 'rb' );
		$out    = fopen( $target, 'wb' );
		if ( ! $in || ! $out ) {
			throw new \RuntimeException( 'Could not decompress the file.' );
		}
		while ( ! gzeof( $in ) ) {
			$chunk = gzread( $in, 1024 * 1024 );
			if ( false === $chunk || fwrite( $out, $chunk ) !== strlen( $chunk ) ) {
				throw new \RuntimeException( 'Could not decompress the file (disk full or corrupt file?).' );
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
	 * Runs a statement from the imported file against raw `_sfr_*` tables.
	 *
	 * @param array  $job Job.
	 * @param string $sql Statement.
	 *
	 * @throws \RuntimeException If the table name is too long or MySQL fails.
	 */
	private function import_statement( array &$job, string $sql ): void {
		$sql = ltrim( $sql );
		// Raw tables are created and dropped here; nothing in the file can touch live tables.
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
			throw new \RuntimeException( 'Table name too long: ' . $name );
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
	 * After loading the file: detects the prefix and source URL and adds the remaining steps.
	 *
	 * @param array $job Job.
	 *
	 * @throws \RuntimeException If the file had no tables.
	 */
	private function plan_import( array &$job ): void {
		$names = array_keys( $job['import']['tables'] );
		if ( ! $names ) {
			throw new \RuntimeException( 'The file contains no tables.' );
		}
		$from = $this->source_prefix( $job, $names );
		$to   = $job['site']['prefix'];

		// Tables with the chosen prefix come first; a table without that prefix that lands on the same
		// final name (e.g. leftovers from another install) is skipped.
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
				throw new \RuntimeException( 'Table name too long: ' . $final );
			}
			$map[ $name ] = $final;
		}
		if ( $skipped ) {
			self::log( $job, count( $skipped ) . " tables skipped because they would collide with those of the $from prefix: " . implode( ', ', $skipped ) );
		}
		if ( '' !== $from ) {
			$job['prefix_from'] = $from;
			$job['prefix_to']   = $to;
			if ( $from !== $to ) {
				self::log( $job, "Table prefix: $from → $to" );
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

		// Cleanup goes before the swap: after importing, the user's session may stop existing
		// and no later request would get to run.
		$steps[] = Planner::step( 'cleanup', 'Cleanup' );
		$steps[] = Planner::step( 'finalize', 'Swap tables' );
		array_splice( $job['steps'], $job['index'] + 1, 0, $steps );
	}

	/**
	 * Adds pairs to turn the source site's URL into this site's URL.
	 *
	 * @param array  $job     Job.
	 * @param string $options Raw options table.
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
		uksort( $auto, static fn( $a, $b ) => strlen( $b ) <=> strlen( $a ) ); // Longest first.
		foreach ( $auto as $old => $new ) {
			$job['options']['pairs'][] = array( $old, $new );
			self::log( $job, "Automatic replacement: $old → $new" );
		}
	}

	/**
	 * Prefixes of the WordPress installs in a list of tables (those with options,
	 * posts and usermeta; multisite subsites have no usermeta of their own).
	 *
	 * @param string[] $names Tables.
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
	 * Prefix of the install to import: the given one, or the only one there is.
	 *
	 * @param array    $job   Job.
	 * @param string[] $names Tables in the file.
	 *
	 * @throws \RuntimeException If there are several installs and none was specified, or the specified one is missing.
	 */
	private function source_prefix( array $job, array $names ): string {
		$found  = self::detect_prefixes( $names );
		$chosen = (string) ( $job['options']['source_prefix'] ?? '' );
		if ( '' !== $chosen ) {
			if ( ! in_array( $chosen, $found, true ) ) {
				throw new \RuntimeException( "The file has no install with the prefix $chosen. Prefixes found: " . implode( ', ', $found ) );
			}
			return $chosen;
		}
		if ( count( $found ) > 1 ) {
			throw new \RuntimeException( 'The file contains several WordPress installs (prefixes: ' . implode( ', ', $found ) . '). Cancel and specify the source prefix.' );
		}
		return $found[0] ?? '';
	}

	/**
	 * Deletes a file if it exists.
	 *
	 * @param string $path Path.
	 */
	private static function delete( string $path ): void {
		if ( is_file( $path ) ) {
			unlink( $path );
		}
	}

	/**
	 * File size for the log.
	 *
	 * @param string $path Path.
	 */
	private static function megabytes( string $path ): string {
		$bytes = (int) filesize( $path );
		return $bytes < 1048576 ? ceil( $bytes / 1024 ) . ' KB' : number_format( $bytes / 1048576, 1 ) . ' MB';
	}

	/**
	 * Pull: asks the remote site for a chunk of the table and loads it into the local temporary table.
	 *
	 * @param array $job  Job.
	 * @param array $step Remote copy step.
	 *
	 * @throws \RuntimeException If the remote returns something that does not belong to that table.
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
				throw new \RuntimeException( 'The remote site sent a statement that does not belong to ' . $step['source'] . '.' );
			}
			$this->db->query( str_starts_with( $sql, 'CREATE' ) ? $this->schema->known_collations( $sql ) : $sql );
		}
		foreach ( (array) $result['fks'] as $clause ) {
			$safe = Schema::foreign_key( (string) $clause, $job['ref_map'], $job['site']['prefix'] );
			if ( null === $safe ) {
				throw new \RuntimeException( 'The remote site sent an invalid foreign key in ' . $step['source'] . '.' );
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
			self::log( $job, $step['label'] . ': ' . $job['step_rows'] . ' rows' );
			return true;
		}
		return false;
	}

	/**
	 * Push: waits for the remote site to be ready to receive (until=ready) or to finish the swap (until=done).
	 *
	 * @param array $job  Job.
	 * @param array $step remote_wait step.
	 *
	 * @throws \RuntimeException If the remote failed.
	 */
	private function remote_wait( array &$job, array $step ): bool {
		$state = $this->remote( $job, 'push_step', array( 'id' => $job['remote']['job'] ) );
		if ( in_array( $state['status'], array( 'failed', 'cancelled' ), true ) ) {
			throw new \RuntimeException( 'Remote site: ' . ( $state['error'] ?? $state['status'] ) );
		}
		$job['step_rows'] = (int) $state['percent'];
		if ( 'ready' === $step['until'] ) {
			if ( ! $state['ready'] ) {
				return false;
			}
			if ( $state['backup'] ) {
				self::log( $job, 'Backup on the remote site: ' . $state['backup'] );
			}
			return true;
		}
		if ( 'done' !== $state['status'] ) {
			return false;
		}
		foreach ( (array) $state['warnings'] as $warning ) {
			$job['warnings'][] = 'Remote site: ' . $warning;
		}
		self::log( $job, 'The remote site is up to date.' );
		return true;
	}

	/**
	 * Calls the remote site.
	 *
	 * @param array       $job    Job (carries the connection).
	 * @param string      $op     Operation.
	 * @param array       $args   Arguments.
	 * @param string|null $binary Binary that accompanies the request (files_put).
	 *
	 * @throws \RuntimeException If no connection is configured.
	 */
	private function remote( array $job, string $op, array $args, ?string $binary = null ): array {
		if ( ! $this->remote ) {
			throw new \RuntimeException( 'This operation requires a connection to the remote site.' );
		}
		return ( $this->remote )( $job, $op, $args, $binary );
	}

	/**
	 * Scans a folder (of this site or the remote one) into the source or destination list.
	 *
	 * @param array  $job    Job.
	 * @param array  $step   files_scan step.
	 * @param Budget $budget Time.
	 */
	private function files_scan( array &$job, array $step, Budget $budget ): bool {
		$root = $step['root'];
		$out  = $this->file_list( $job, $root, $step['out'] );
		if ( null === $job['cursor'] ) {
			Applier::remove_tree( $out->path() ); // A retry starts from scratch.
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
	 * Compares the source and destination of a folder; leaves the send and delete lists.
	 *
	 * @param array  $job  Job.
	 * @param string $root Folder.
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
		$deletes = $job['options']['delete_files'] ? ', ' . $stats['delete'] . ' to delete' : '';
		self::log( $job, "Files ($root): {$stats['send']} to send (" . number_format( $stats['bytes'] / 1048576, 1 ) . " MB)$deletes." );
	}

	/**
	 * File pull: requests packages from the remote and places them here.
	 *
	 * @param array  $job    Job.
	 * @param array  $step   Step.
	 * @param Budget $budget Time.
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
	 * File push: builds packages here and sends them to the remote.
	 *
	 * @param array  $job    Job.
	 * @param array  $step   Step.
	 * @param Budget $budget Time.
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
			// The cursor only advances once the remote has confirmed the package.
			$this->record_files( $job, $root, $got, count( $built['missing'] ), $built['bytes'] );
			$job['cursor'] = $built['cursor'];
		} while ( null !== $job['cursor'] && ! $budget->exhausted() );
		return null === $job['cursor'];
	}

	/**
	 * Finalizes a folder: deletions and swap of code folders (here or on the remote).
	 *
	 * @param array  $job  Job.
	 * @param string $root Folder.
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
				$deletions = $applier->leftovers( $root ); // Site package: the code ends up identical to the source.
			}
			$stats = $applier->finalize( $root, $deletions );
		} else {
			// ponytail: the deletion list travels whole in one request; with tens of thousands it would be worth chunking it.
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
			$job['warnings'][] = "Files ($root): did not replace $skipped (it is a symlink at the destination).";
		}
		$sent = $job['file_stats'][ $root ] ?? array();
		self::log(
			$job,
			"Files ($root): " . ( $sent['sent'] ?? 0 ) . ' received, ' . (int) ( $stats['replaced'] ?? 0 ) . ' top-level folders or files swapped, '
			. (int) ( $stats['deleted'] ?? 0 ) . ' deleted (moved to backup).'
		);
	}

	/**
	 * Counts what was sent and turns failures into warnings (they do not stop the job).
	 *
	 * @param array  $job     Job.
	 * @param string $root    Folder.
	 * @param array  $result  done, partial, failed.
	 * @param int    $missing Files that no longer existed at the source.
	 * @param int    $bytes   Content bytes of the package.
	 */
	private function record_files( array &$job, string $root, array $result, int $missing, int $bytes ): void {
		$stats             = &$job['file_stats'][ $root ];
		$stats['sent']    += count( (array) $result['done'] );
		$stats['missing'] += $missing;
		$job['step_rows'] += intdiv( $bytes, 65536 ) + count( (array) $result['done'] );
		foreach ( (array) $result['failed'] as $file => $reason ) {
			++$stats['failed'];
			if ( $stats['failed'] <= 20 ) {
				$job['warnings'][] = "Files ($root): $file — $reason";
			}
		}
	}

	/**
	 * Working folder for the job's files (lists, staging area and backup).
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
	 * List for a folder: source, target, send or delete.
	 *
	 * @param array  $job  Job.
	 * @param string $root Folder.
	 * @param string $kind Kind.
	 */
	private function file_list( array $job, string $root, string $kind ): Manifest {
		return new Manifest( $this->files_dir( $job ) . "/lists/$root-$kind.jsonl" );
	}

	/**
	 * Empty list (to be written again).
	 *
	 * @param array  $job  Job.
	 * @param string $root Folder.
	 * @param string $kind Kind.
	 */
	private function fresh_list( array $job, string $root, string $kind ): Manifest {
		$list = $this->file_list( $job, $root, $kind );
		Applier::remove_tree( $list->path() );
		return $list;
	}

	/**
	 * Package: appends the .sql.gz dump in chunks, then deletes it.
	 *
	 * @param array  $job    Job.
	 * @param Budget $budget Time.
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
		self::log( $job, 'Package: database included (' . number_format( $size / 1048576, 1 ) . ' MB compressed).' );
		return true;
	}

	/**
	 * Package: appends the files of a folder.
	 *
	 * @param array  $job    Job.
	 * @param array  $step   Step.
	 * @param Budget $budget Time.
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
			self::log( $job, "Package ($root): files included (" . number_format( $job['file_stats'][ $root ]['bytes'] / 1048576, 1 ) . ' MB).' );
			return true;
		}
		return false;
	}

	/**
	 * Imports a package: the dump goes to a separate file and the files to their place (media) or to the
	 * staging area (code), with the same protections as a pull.
	 *
	 * @param array  $job    Job.
	 * @param Budget $budget Time.
	 *
	 * @throws \RuntimeException If the package is invalid or has no database.
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
					throw new \RuntimeException( 'The package contains no database.' );
				}
				self::log( $job, 'Package unpacked.' );
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
	 * Current size of a file (0 if it does not exist).
	 *
	 * @param string $path File.
	 */
	private static function size( string $path ): int {
		clearstatcache( true, $path );
		return is_file( $path ) ? (int) filesize( $path ) : 0;
	}

	/**
	 * Truncates a file to the size saved in the cursor: if a request was cut off after
	 * appending a block but before saving the job, the block would be appended twice.
	 *
	 * @param string $path File.
	 * @param int    $size Confirmed size.
	 */
	private static function rewind( string $path, int $size ): void {
		if ( self::size( $path ) > $size ) {
			$handle = fopen( $path, 'r+b' );
			ftruncate( $handle, $size );
			fclose( $handle );
		}
	}
}

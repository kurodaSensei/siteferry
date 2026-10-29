<?php
/**
 * Operations this site serves when another site does a pull or push.
 *
 * @package Siteferry
 */

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Messages are plain text for the job log, WP-CLI and REST JSON; the admin shows them with textContent.

namespace Siteferry\Remote;

use Siteferry\Engine\Budget;
use Siteferry\Engine\MemorySink;
use Siteferry\Engine\Replacer;
use Siteferry\Engine\Schema;
use Siteferry\Engine\TableCopier;
use Siteferry\Job\Planner;
use Siteferry\Job\Runner;
use Siteferry\Support\Db;

/**
 * Remote-side logic. It does not depend on WordPress: the signature, the clock and the nonce have
 * already been checked by the caller (RemoteController); here the permissions are checked before
 * any effect.
 */
final class Server {

	public const PROTOCOL = 1;

	/** Bytes of SQL (uncompressed) per pull response. */
	private const PULL_BYTES = 4 * 1024 * 1024;

	/** Valid name for a temporary destination table. */
	private const TMP_NAME = '/^_sft_[A-Za-z0-9_$]{1,59}$/';


	/**
	 * DB structure.
	 *
	 * @var Schema
	 */
	private Schema $schema;

	/**
	 * Constructor.
	 *
	 * @param Db       $db      Connection.
	 * @param array    $site    Site data (see Planner::create).
	 * @param array    $perms   allow_pull, allow_push.
	 * @param \Closure $load    fn(): ?array — saved job.
	 * @param \Closure $save    fn(array $job): void.
	 * @param string   $version Plugin version.
	 */
	public function __construct(
		private Db $db,
		private array $site,
		private array $perms,
		private \Closure $load,
		private \Closure $save,
		private string $version
	) {
		$this->schema = new Schema( $db );
	}

	/**
	 * Handles an operation.
	 *
	 * @param string $op   Operation.
	 * @param array  $args Arguments.
	 *
	 * @throws \RuntimeException If the operation does not exist, is not allowed or fails.
	 */
	public function handle( string $op, array $args ): array {
		return match ( $op ) {
			'hello'       => $this->hello(),
			'pull_rows'   => $this->pull_rows( $args ),
			'push_begin'  => $this->push_begin( $args ),
			'push_step'   => $this->push_step( $args ),
			'push_chunk'  => $this->push_chunk( $args ),
			'push_finish' => $this->push_finish( $args ),
			'push_cancel' => $this->push_cancel( $args ),
			'files_scan', 'files_get', 'files_put', 'files_finalize' => ( new FileOps( $this->site['roots'] ?? array(), $this->site['storage'], $this->perms ) )
				->handle( $op, $args, $args['_binary'] ?? null ),
			default       => throw new \RuntimeException( 'Unknown operation: ' . $op ),
		};
	}

	/**
	 * This site's data for the other site.
	 */
	private function hello(): array {
		return array(
			'protocol'         => self::PROTOCOL,
			'version'          => $this->version,
			'home'             => $this->site['home'],
			'siteurl'          => $this->site['siteurl'],
			'prefix'           => $this->site['prefix'],
			'multisite'        => $this->site['multisite'],
			'tables'           => $this->schema->stats( $this->site['prefix'] ),
			'max_request'      => self::max_request(),
			'allow_pull'       => ! empty( $this->perms['allow_pull'] ),
			'allow_push'       => ! empty( $this->perms['allow_push'] ),
			'allow_push_code'  => ! empty( $this->perms['allow_push_code'] ),
			'allow_pull_files' => ! empty( $this->perms['allow_pull_files'] ),
			'roots'            => array_keys( $this->site['roots'] ?? array() ),
		);
	}

	/**
	 * Reads a chunk of a table and returns it as SQL for the other site's temporary table.
	 *
	 * @param array $args table, target, cursor, pairs, case_sensitive, regex, replace_guids, prefix_to, max_bytes.
	 *
	 * @throws \RuntimeException If it is not allowed or the arguments are not valid.
	 */
	private function pull_rows( array $args ): array {
		$this->require( 'allow_pull' );
		$table  = (string) ( $args['table'] ?? '' );
		$target = (string) ( $args['target'] ?? '' );
		if ( ! isset( $this->schema->stats( $this->site['prefix'] )[ $table ] ) ) {
			throw new \RuntimeException( 'Table not available: ' . $table );
		}
		if ( ! preg_match( self::TMP_NAME, $target ) ) {
			throw new \RuntimeException( 'Invalid destination table.' );
		}
		$replacer = new Replacer( (array) ( $args['pairs'] ?? array() ), ! empty( $args['case_sensitive'] ), ! empty( $args['regex'] ) );
		$copier   = new TableCopier(
			$this->db,
			$this->schema,
			$replacer->is_empty() ? null : $replacer,
			max( 64 * 1024, min( 1024 * 1024, (int) ( $args['max_bytes'] ?? 0 ) ) ),
			! empty( $args['replace_guids'] ),
			$this->site['prefix'],
			preg_replace( '/[^A-Za-z0-9_]/', '', (string) ( $args['prefix_to'] ?? '' ) ),
			$this->site['options_table']
		);
		$sink     = new MemorySink();
		$cursor   = is_array( $args['cursor'] ?? null ) ? $args['cursor'] : null;
		$result   = $this->db->in_migration_session(
			fn() => $copier->run( $table, $target, $table, $sink, $cursor, ( new Budget( 10 ) )->limited( self::PULL_BYTES ), true )
		);
		return array(
			'statements' => $sink->pack(),
			'cursor'     => $result['cursor'],
			'rows'       => $result['rows'],
			'fks'        => $result['fks'],
		);
	}

	/**
	 * Starts receiving a push: creates the receive job (with a backup if requested).
	 *
	 * @param array $args backup.
	 *
	 * @throws \RuntimeException If it is not allowed or another operation is in progress.
	 */
	private function push_begin( array $args ): array {
		$this->require( 'allow_push' );
		$current = ( $this->load )();
		// A job waiting for a review or a resume is still unfinished: a push must not replace it (and drop its temporary tables).
		if ( Runner::is_unfinished( $current ) && time() - (int) $current['heartbeat'] < 600 ) {
			throw new \RuntimeException( 'The remote site has another operation in progress.' );
		}
		$job           = Planner::create( 'receive', array( 'backup' => ! empty( $args['backup'] ) ), $this->site, $this->schema );
		$job['origin'] = preg_match( '/^[a-f0-9]{16}$/', (string) ( $args['session'] ?? '' ) ) ? (string) $args['session'] : $job['id'];
		( $this->save )( $job );
		return array( 'id' => $job['id'] );
	}

	/**
	 * Advances the receive job (preflight, backup and, at the end, the table swap).
	 *
	 * @param array $args id.
	 */
	private function push_step( array $args ): array {
		$job = $this->receive_job( $args );
		if ( Runner::is_open( $job ) ) {
			$job = ( new Runner( $this->db ) )->step( $job, new Budget( 10 ) );
			( $this->save )( $job );
		}
		$step = $job['steps'][ $job['index'] ]['type'] ?? null;
		return array(
			'status'   => $job['status'],
			'percent'  => Runner::percent( $job ),
			'ready'    => 'receive' === $step && empty( $job['received'] ),
			'error'    => $job['error'],
			'warnings' => $job['warnings'],
			'backup'   => isset( $job['files']['backup'] ) ? basename( $job['files']['backup'] ) : null,
		);
	}

	/**
	 * Runs a chunk of statements on _sft_ tables. Chunks are numbered: a repeated one
	 * (a retry after a network drop) is ignored, so rows are never inserted twice. Each
	 * statement applied is recorded with the chunk's hash, so a chunk whose request was cut
	 * off halfway is completed when it comes again, not applied twice.
	 *
	 * @param array $args id, seq, statements.
	 *
	 * @throws \RuntimeException If the chunk is out of order or carries something not allowed.
	 */
	private function push_chunk( array $args ): array {
		$job  = $this->receive_job( $args );
		$seq  = (int) ( $args['seq'] ?? 0 );
		$last = (int) ( $job['received_seq'] ?? 0 );
		if ( $seq <= $last ) {
			return array( 'seq' => $last );
		}
		if ( $seq !== $last + 1 ) {
			throw new \RuntimeException( "Chunk out of order ($seq after $last)." );
		}
		if ( 'receive' !== ( $job['steps'][ $job['index'] ]['type'] ?? null ) || ! Runner::is_open( $job ) ) {
			throw new \RuntimeException( 'The remote site is not waiting for data.' );
		}
		$statements = MemorySink::decode( (string) ( $args['statements'] ?? '' ) );
		foreach ( $statements as $sql ) {
			if ( ! Schema::safe_temp_statement( $sql ) ) {
				throw new \RuntimeException( 'Statement not allowed in a push.' );
			}
		}
		$hash    = md5( (string) ( $args['statements'] ?? '' ) );
		$applied = 0;
		$partial = $job['applying'] ?? array();
		if ( isset( $partial['seq'] ) && $partial['seq'] === $seq ) {
			if ( $partial['hash'] !== $hash ) {
				throw new \RuntimeException( "Chunk $seq came again with other content after a cut-off request." );
			}
			$applied = (int) $partial['applied'];
		}
		$this->db->in_migration_session(
			function () use ( $statements, $seq, $hash, &$applied, &$job ) {
				foreach ( array_slice( $statements, $applied ) as $sql ) {
					$this->db->query( str_starts_with( $sql, 'CREATE' ) ? $this->schema->known_collations( $sql ) : $sql );
					// ponytail: a cut between a statement and this save still repeats that one statement.
					$job['applying'] = array(
						'seq'     => $seq,
						'hash'    => $hash,
						'applied' => ++$applied,
					);
					( $this->save )( $job );
				}
			}
		);
		unset( $job['applying'] );
		$job['received_seq'] = $seq;
		$job['heartbeat']    = time();
		( $this->save )( $job );
		return array( 'seq' => $seq );
	}

	/**
	 * Finishes the transfer: which tables to swap and which foreign keys to recreate. The swap is done
	 * by the next push_step.
	 *
	 * @param array $args id, swap, fks, ref_map, rows.
	 *
	 * @throws \RuntimeException If the names are not valid.
	 */
	private function push_finish( array $args ): array {
		$job    = $this->receive_job( $args );
		$prefix = $this->site['prefix'];
		$swap   = array();
		// Only this site's tables, and each one only from its own temporary table.
		foreach ( (array) ( $args['swap'] ?? array() ) as $tmp => $final ) {
			$final = (string) $final;
			if ( ! preg_match( '/^[A-Za-z0-9_$]{1,59}$/', $final ) || ! str_starts_with( $final, $prefix ) || Schema::TMP . $final !== (string) $tmp ) {
				throw new \RuntimeException( 'Invalid table swap: ' . $final );
			}
			$swap[ $tmp ] = $final;
		}
		$ref_map = array();
		foreach ( (array) ( $args['ref_map'] ?? array() ) as $from => $to ) {
			if ( in_array( (string) $to, $swap, true ) ) {
				$ref_map[ (string) $from ] = (string) $to;
			}
		}
		$fks = array();
		foreach ( (array) ( $args['fks'] ?? array() ) as $fk ) {
			$clause = Schema::foreign_key( (string) ( $fk['clause'] ?? '' ), $ref_map, $prefix );
			if ( null === $clause || ! in_array( $fk['table'] ?? null, $swap, true ) ) {
				throw new \RuntimeException( 'Invalid foreign key.' );
			}
			$fks[] = array(
				'table'  => (string) $fk['table'],
				'clause' => $clause,
			);
		}
		$job['swap']     = $swap;
		$job['fks']      = $fks;
		$job['ref_map']  = array(); // The clauses already point to this site's tables.
		$job['rows']     = (int) ( $args['rows'] ?? 0 );
		$job['received'] = true;
		Runner::log( $job, count( $swap ) . ' tables received.' );
		( $this->save )( $job );
		return array( 'ok' => true );
	}

	/**
	 * Cancels the receive and cleans up.
	 *
	 * @param array $args id.
	 */
	private function push_cancel( array $args ): array {
		$job = $this->receive_job( $args );
		if ( Runner::is_open( $job ) || 'failed' === $job['status'] ) {
			$job = ( new Runner( $this->db ) )->cancel( $job );
			( $this->save )( $job );
		}
		return array( 'status' => $job['status'] );
	}

	/**
	 * Saved receive job, if it matches the id.
	 *
	 * @param array $args id.
	 *
	 * @throws \RuntimeException If it does not exist.
	 */
	private function receive_job( array $args ): array {
		$this->require( 'allow_push' );
		$job = ( $this->load )();
		if ( ! $job || 'receive' !== $job['intent'] || ( $args['id'] ?? null ) !== $job['id'] ) {
			throw new \RuntimeException( 'The receive no longer exists on the remote site.' );
		}
		return $job;
	}

	/**
	 * Requires a permission on this site.
	 *
	 * @param string $perm allow_pull | allow_push.
	 *
	 * @throws \RuntimeException If it is not enabled.
	 */
	private function require( string $perm ): void {
		if ( empty( $this->perms[ $perm ] ) ) {
			throw new \RuntimeException( 'allow_pull' === $perm ? 'The remote site does not allow pull.' : 'The remote site does not allow push.' );
		}
	}

	/**
	 * Bytes this server accepts in one request (with margin).
	 */
	public static function max_request(): int {
		$post = self::ini_bytes( (string) ini_get( 'post_max_size' ) );
		$max  = $post > 0 ? min( $post, 25 * 1024 * 1024 ) : 25 * 1024 * 1024;
		return (int) ( $max * 0.7 );
	}

	/**
	 * "8M" → bytes.
	 *
	 * @param string $value php.ini value.
	 */
	private static function ini_bytes( string $value ): int {
		$number = (int) $value;
		return match ( strtolower( substr( trim( $value ), -1 ) ) ) {
			'g'     => $number * 1024 ** 3,
			'm'     => $number * 1024 ** 2,
			'k'     => $number * 1024,
			default => $number,
		};
	}
}

<?php
/**
 * Operaciones que este sitio atiende cuando otro sitio hace pull o push.
 *
 * @package Siteferry
 */

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
 * Lógica del lado remoto. No depende de WordPress: la firma, el reloj y el nonce ya los
 * comprobó quien la llama (RemoteController); aquí se comprueban los permisos antes de
 * cualquier efecto.
 */
final class Server {

	public const PROTOCOL = 1;

	/** Bytes de SQL (sin comprimir) por respuesta de pull. */
	private const PULL_BYTES = 4 * 1024 * 1024;

	/** Nombre válido de tabla temporal de destino. */
	private const TMP_NAME = '/^_sft_[A-Za-z0-9_$]{1,59}$/';


	/**
	 * Estructura de la BD.
	 *
	 * @var Schema
	 */
	private Schema $schema;

	/**
	 * Constructor.
	 *
	 * @param Db       $db      Conexión.
	 * @param array    $site    Datos del sitio (ver Planner::create).
	 * @param array    $perms   allow_pull, allow_push.
	 * @param \Closure $load    fn(): ?array — job guardado.
	 * @param \Closure $save    fn(array $job): void.
	 * @param string   $version Versión del plugin.
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
	 * Atiende una operación.
	 *
	 * @param string $op   Operación.
	 * @param array  $args Argumentos.
	 *
	 * @throws \RuntimeException Si la operación no existe, no está permitida o falla.
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
			default       => throw new \RuntimeException( 'Operación desconocida: ' . $op ),
		};
	}

	/**
	 * Datos de este sitio para el otro.
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
	 * Lee un tramo de una tabla y lo devuelve como SQL hacia la tabla temporal del otro sitio.
	 *
	 * @param array $args table, target, cursor, pairs, case_sensitive, regex, replace_guids, prefix_to, max_bytes.
	 *
	 * @throws \RuntimeException Si no está permitido o los argumentos no son válidos.
	 */
	private function pull_rows( array $args ): array {
		$this->require( 'allow_pull' );
		$table  = (string) ( $args['table'] ?? '' );
		$target = (string) ( $args['target'] ?? '' );
		if ( ! isset( $this->schema->stats( $this->site['prefix'] )[ $table ] ) ) {
			throw new \RuntimeException( 'Tabla no disponible: ' . $table );
		}
		if ( ! preg_match( self::TMP_NAME, $target ) ) {
			throw new \RuntimeException( 'Tabla de destino inválida.' );
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
	 * Empieza a recibir un push: crea el job de recepción (con backup si se pidió).
	 *
	 * @param array $args backup.
	 *
	 * @throws \RuntimeException Si no está permitido o hay otra operación en curso.
	 */
	private function push_begin( array $args ): array {
		$this->require( 'allow_push' );
		$current = ( $this->load )();
		if ( Runner::is_open( $current ) && time() - (int) $current['heartbeat'] < 600 ) {
			throw new \RuntimeException( 'El sitio remoto tiene otra operación en curso.' );
		}
		$job           = Planner::create( 'receive', array( 'backup' => ! empty( $args['backup'] ) ), $this->site, $this->schema );
		$job['origin'] = preg_match( '/^[a-f0-9]{16}$/', (string) ( $args['session'] ?? '' ) ) ? (string) $args['session'] : $job['id'];
		( $this->save )( $job );
		return array( 'id' => $job['id'] );
	}

	/**
	 * Avanza el job de recepción (preflight, backup y, al final, el cambio de tablas).
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
	 * Ejecuta un trozo de sentencias sobre tablas _sft_. Los trozos van numerados: uno repetido
	 * (un reintento tras un corte de red) se ignora, así nunca se insertan filas dos veces.
	 *
	 * @param array $args id, seq, statements.
	 *
	 * @throws \RuntimeException Si el trozo está fuera de orden o trae algo no permitido.
	 */
	private function push_chunk( array $args ): array {
		$job  = $this->receive_job( $args );
		$seq  = (int) ( $args['seq'] ?? 0 );
		$last = (int) ( $job['received_seq'] ?? 0 );
		if ( $seq <= $last ) {
			return array( 'seq' => $last );
		}
		if ( $seq !== $last + 1 ) {
			throw new \RuntimeException( "Trozo fuera de orden ($seq tras $last)." );
		}
		if ( 'receive' !== ( $job['steps'][ $job['index'] ]['type'] ?? null ) || ! Runner::is_open( $job ) ) {
			throw new \RuntimeException( 'El sitio remoto no está esperando datos.' );
		}
		$statements = MemorySink::decode( (string) ( $args['statements'] ?? '' ) );
		foreach ( $statements as $sql ) {
			if ( ! Schema::safe_temp_statement( $sql ) ) {
				throw new \RuntimeException( 'Sentencia no permitida en un push.' );
			}
		}
		$this->db->in_migration_session(
			function () use ( $statements ) {
				foreach ( $statements as $sql ) {
					$this->db->query( str_starts_with( $sql, 'CREATE' ) ? $this->schema->known_collations( $sql ) : $sql );
				}
			}
		);
		$job['received_seq'] = $seq;
		$job['heartbeat']    = time();
		( $this->save )( $job );
		return array( 'seq' => $seq );
	}

	/**
	 * Termina el envío: qué tablas cambiar y qué llaves foráneas recrear. El cambio lo hace
	 * el siguiente push_step.
	 *
	 * @param array $args id, swap, fks, ref_map, rows.
	 *
	 * @throws \RuntimeException Si los nombres no son válidos.
	 */
	private function push_finish( array $args ): array {
		$job    = $this->receive_job( $args );
		$prefix = $this->site['prefix'];
		$swap   = array();
		// Solo tablas de este sitio, y cada una solo desde su propia tabla temporal.
		foreach ( (array) ( $args['swap'] ?? array() ) as $tmp => $final ) {
			$final = (string) $final;
			if ( ! preg_match( '/^[A-Za-z0-9_$]{1,59}$/', $final ) || ! str_starts_with( $final, $prefix ) || Schema::TMP . $final !== (string) $tmp ) {
				throw new \RuntimeException( 'Cambio de tablas inválido: ' . $final );
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
				throw new \RuntimeException( 'Llave foránea inválida.' );
			}
			$fks[] = array(
				'table'  => (string) $fk['table'],
				'clause' => $clause,
			);
		}
		$job['swap']     = $swap;
		$job['fks']      = $fks;
		$job['ref_map']  = array(); // Las cláusulas ya apuntan a las tablas de este sitio.
		$job['rows']     = (int) ( $args['rows'] ?? 0 );
		$job['received'] = true;
		Runner::log( $job, count( $swap ) . ' tablas recibidas.' );
		( $this->save )( $job );
		return array( 'ok' => true );
	}

	/**
	 * Cancela la recepción y limpia.
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
	 * Job de recepción guardado, si coincide con el id.
	 *
	 * @param array $args id.
	 *
	 * @throws \RuntimeException Si no existe.
	 */
	private function receive_job( array $args ): array {
		$this->require( 'allow_push' );
		$job = ( $this->load )();
		if ( ! $job || 'receive' !== $job['intent'] || ( $args['id'] ?? null ) !== $job['id'] ) {
			throw new \RuntimeException( 'La recepción ya no existe en el sitio remoto.' );
		}
		return $job;
	}

	/**
	 * Exige un permiso de este sitio.
	 *
	 * @param string $perm allow_pull | allow_push.
	 *
	 * @throws \RuntimeException Si no está activado.
	 */
	private function require( string $perm ): void {
		if ( empty( $this->perms[ $perm ] ) ) {
			throw new \RuntimeException( 'allow_pull' === $perm ? 'El sitio remoto no permite pull.' : 'El sitio remoto no permite push.' );
		}
	}

	/**
	 * Bytes que acepta este servidor en una petición (con margen).
	 */
	public static function max_request(): int {
		$post = self::ini_bytes( (string) ini_get( 'post_max_size' ) );
		$max  = $post > 0 ? min( $post, 25 * 1024 * 1024 ) : 25 * 1024 * 1024;
		return (int) ( $max * 0.7 );
	}

	/**
	 * "8M" → bytes.
	 *
	 * @param string $value Valor de php.ini.
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

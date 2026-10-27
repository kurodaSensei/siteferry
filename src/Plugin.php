<?php
/**
 * Plugin bootstrap.
 *
 * @package Siteferry
 */

namespace Siteferry;

use Siteferry\Admin\Page;
use Siteferry\Cli\Command;
use Siteferry\Engine\Budget;
use Siteferry\Engine\Schema;
use Siteferry\Http\RemoteController;
use Siteferry\Http\RestController;
use Siteferry\Job\JobStore;
use Siteferry\Job\Planner;
use Siteferry\Job\Runner;
use Siteferry\Remote\Client;
use Siteferry\Remote\Server;
use Siteferry\Remote\Signer;
use Siteferry\Support\Db;
use Siteferry\Support\Settings;
use Siteferry\Support\Storage;
use Siteferry\Support\UndoPoint;

/**
 * Registers hooks and holds what the REST API and WP-CLI share.
 */
final class Plugin {

	private const COMPAT_FILE = 'siteferry-compat.php';

	/**
	 * Registers hooks.
	 */
	public static function boot(): void {
		// Bundled translations; translate.wordpress.org files in wp-content/languages take precedence.
		add_action( 'init', static fn() => load_plugin_textdomain( 'siteferry', false, dirname( plugin_basename( SITEFERRY_FILE ) ) . '/languages' ) );
		add_action( 'rest_api_init', array( RestController::class, 'register' ) );
		add_action( 'rest_api_init', array( RemoteController::class, 'register' ) );
		add_action( 'admin_menu', array( Page::class, 'register' ) );
		add_action( 'siteferry_cleanup', array( self::class, 'cleanup' ) );
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'siteferry', Command::class );
		}
	}

	/**
	 * Activation: keys, compatibility mu-plugin and daily cleanup.
	 */
	public static function activate(): void {
		if ( ! get_option( 'siteferry_compat_key' ) ) {
			update_option( 'siteferry_compat_key', wp_generate_password( 32, false ), false );
		}
		self::install_compat();
		if ( ! wp_next_scheduled( 'siteferry_cleanup' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'siteferry_cleanup' );
		}
	}

	/**
	 * Deactivation: removes the mu-plugin and the cron event.
	 */
	public static function deactivate(): void {
		$target = WPMU_PLUGIN_DIR . '/' . self::COMPAT_FILE;
		if ( is_file( $target ) ) {
			wp_delete_file( $target );
		}
		wp_clear_scheduled_hook( 'siteferry_cleanup' );
	}

	/**
	 * Copies (or updates) the mu-plugin that switches off other plugins and the theme during steps.
	 */
	public static function install_compat(): void {
		$source = SITEFERRY_PATH . 'mu-plugin/' . self::COMPAT_FILE;
		$target = WPMU_PLUGIN_DIR . '/' . self::COMPAT_FILE;
		if ( is_file( $target ) && md5_file( $target ) === md5_file( $source ) ) {
			return;
		}
		if ( wp_mkdir_p( WPMU_PLUGIN_DIR ) ) {
			copy( $source, $target );
		}
	}

	/**
	 * Engine connection (the same one $wpdb uses).
	 */
	public static function db(): Db {
		global $wpdb;
		return Db::from_wpdb( $wpdb );
	}

	/**
	 * This site's data that the Planner needs.
	 */
	public static function site(): array {
		global $wpdb;
		return array(
			'prefix'        => $wpdb->prefix,
			'home'          => home_url(),
			'siteurl'       => site_url(),
			'storage'       => Storage::dir(),
			'host'          => sanitize_file_name( (string) ( wp_parse_url( home_url(), PHP_URL_HOST ) ?? 'site' ) ),
			'plugin'        => plugin_basename( SITEFERRY_FILE ),
			'multisite'     => is_multisite(),
			'options_table' => $wpdb->options,
			'preserve'      => array_values( (array) apply_filters( 'siteferry_preserved_options', array( 'blog_public' ) ) ),
			'version'       => SITEFERRY_VERSION,
			'roots'         => array_filter(
				array(
					'uploads'    => wp_upload_dir( null, false )['basedir'],
					'themes'     => get_theme_root(),
					'plugins'    => WP_PLUGIN_DIR,
					'mu-plugins' => WPMU_PLUGIN_DIR,
				),
				'is_dir'
			),
		);
	}

	/**
	 * Creates and stores a job.
	 *
	 * @param string $intent export | replace | import | pull | push.
	 * @param array  $input  Opciones (ver Planner::create); pull/push llevan `connection` ("URL CLAVE").
	 *
	 * @throws \RuntimeException If another job is in progress or the remote site does not respond.
	 * @throws \InvalidArgumentException If the request is invalid.
	 */
	public static function create_job( string $intent, array $input ): array {
		if ( JobStore::busy() ) {
			throw new \RuntimeException( __( 'Another operation is already in progress.', 'siteferry' ) );
		}
		if ( 'receive' === $intent ) {
			throw new \InvalidArgumentException( __( 'Unknown action.', 'siteferry' ) );
		}
		if ( 'undo' === $intent ) {
			// Only the stored point: never paths that arrive in the request.
			$input = UndoPoint::get() ?? throw new \InvalidArgumentException( __( 'There is nothing to undo.', 'siteferry' ) );
		}
		if ( 'pull' === $intent || 'push' === $intent ) {
			$input['remote']      = Signer::parse_connection( (string) ( $input['connection'] ?? '' ) ) + array( 'verify_ssl' => Settings::get()['verify_ssl'] );
			$input['remote_info'] = self::hello( $input['remote'] );
		}
		$job = Planner::create( $intent, $input, self::site(), new Schema( self::db() ) );
		JobStore::save( $job );
		do_action( 'siteferry_job_started', $job );
		return $job;
	}

	/**
	 * Greets the other site and checks that it speaks the same protocol.
	 *
	 * @param array $remote url, key, verify_ssl.
	 *
	 * @throws \RuntimeException If it does not respond or is not compatible.
	 */
	public static function hello( array $remote ): array {
		$info = ( new Client( $remote['url'], $remote['key'], $remote['verify_ssl'] ) )->call( 'hello' );
		if ( Server::PROTOCOL !== (int) ( $info['protocol'] ?? 0 ) ) {
			/* translators: %s: Siteferry version on the remote site */
			throw new \RuntimeException( sprintf( __( 'The remote site runs a different Siteferry version (%s). Update both.', 'siteferry' ), $info['version'] ?? '?' ) );
		}
		return $info;
	}

	/**
	 * Runner with access to the job's remote site (pull/push).
	 */
	public static function runner(): Runner {
		return new Runner( self::db(), static fn( array $job, string $op, array $args, ?string $binary = null ) => Client::for_job( $job )->call( $op, $args, $binary ) );
	}

	/**
	 * Advances the job and stores it.
	 *
	 * @param array $job     Job.
	 * @param float $seconds Presupuesto.
	 */
	public static function run_step( array $job, float $seconds ): array {
		$job = self::runner()->step( $job, new Budget( $seconds ) );
		if ( 'done' === $job['status'] && 'export' !== $job['intent'] ) {
			wp_cache_flush(); // The tables changed underneath the object cache.
		}
		JobStore::save( $job );
		if ( 'done' === $job['status'] ) {
			if ( 'undo' === $job['intent'] ) {
				UndoPoint::clear();
			} elseif ( in_array( $job['intent'], array( 'replace', 'import', 'pull' ), true ) ) {
				UndoPoint::from_job( $job );
			}
			do_action( 'siteferry_job_completed', $job );
		} elseif ( 'failed' === $job['status'] ) {
			do_action( 'siteferry_job_failed', $job );
		}
		return $job;
	}

	/**
	 * Seconds of work per web request.
	 */
	public static function step_seconds(): float {
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 120 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged
		}
		$max = (int) ini_get( 'max_execution_time' );
		return $max > 0 ? (float) max( 5, min( 15, intdiv( $max, 2 ) ) ) : 15.0;
	}

	/**
	 * Daily cleanup: old files and abandoned jobs.
	 */
	public static function cleanup(): void {
		Storage::cleanup();
		$job = JobStore::get();
		if ( $job && Runner::is_open( $job ) && ! JobStore::busy() ) {
			JobStore::save( self::runner()->cancel( $job ) );
		}
	}

	/**
	 * Job data the UI sees.
	 *
	 * @param array|null $job Job.
	 */
	public static function view( ?array $job ): ?array {
		if ( ! $job ) {
			return null;
		}
		$step  = $job['steps'][ $job['index'] ] ?? null;
		$files = array();
		foreach ( array( 'export', 'backup' ) as $key ) {
			$ready = 'export' === $key ? 'done' === $job['status'] : self::backup_ready( $job );
			if ( $ready && isset( $job['files'][ $key ] ) && is_file( $job['files'][ $key ] ) ) {
				$files[ $key ] = array(
					'name' => basename( $job['files'][ $key ] ),
					'size' => (int) filesize( $job['files'][ $key ] ),
				);
			}
		}
		return array(
			'id'       => $job['id'],
			'intent'   => $job['intent'],
			'database' => $job['options']['database'] ?? true,
			'status'   => $job['status'],
			'percent'  => Runner::percent( $job ),
			'step'     => $step ? $step['label'] : null,
			'rows'     => $job['rows'],
			'error'    => $job['error'],
			'warnings' => $job['warnings'],
			'log'      => array_slice( $job['log'], -40 ),
			'files'    => $files,
		);
	}

	/**
	 * Whether the backup is complete (its closing step has run).
	 *
	 * @param array $job Job.
	 */
	private static function backup_ready( array $job ): bool {
		foreach ( $job['steps'] as $i => $step ) {
			if ( 'footer' === $step['type'] && 'backup' === ( $step['file'] ?? '' ) ) {
				return $i < $job['index'] || 'done' === $job['status'];
			}
		}
		return false;
	}
}

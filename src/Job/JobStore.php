<?php
/**
 * Stores the current job.
 *
 * @package Siteferry
 */

namespace Siteferry\Job;

/**
 * The job lives in a non-autoloaded option. There is only one at a time.
 */
final class JobStore {

	// ponytail: a single global job; if parallelism is needed, switch to a per-job lock.

	private const OPTION = 'siteferry_job';

	/** Seconds of inactivity after which an open job is considered abandoned. */
	public const STALE = 600;

	/**
	 * Current job, or null.
	 */
	public static function get(): ?array {
		wp_cache_delete( self::OPTION, 'options' );
		$job = get_option( self::OPTION );
		return is_array( $job ) ? $job : null;
	}

	/**
	 * Saves the job.
	 *
	 * @param array $job Job.
	 */
	public static function save( array $job ): void {
		update_option( self::OPTION, $job, false );
	}

	/**
	 * Whether there is an unfinished job that is not abandoned.
	 */
	public static function busy(): bool {
		$job = self::get();
		return Runner::is_unfinished( $job ) && time() - (int) $job['heartbeat'] < self::STALE;
	}

	/**
	 * MySQL lock so two requests do not advance the same job at once.
	 *
	 * @param int $wait Seconds to wait.
	 */
	public static function lock( int $wait = 0 ): bool {
		global $wpdb;
		return '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $wpdb->prefix . 'siteferry_step', $wait ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- A MySQL lock: there is nothing to cache.
	}

	/**
	 * Releases the lock.
	 */
	public static function unlock(): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $wpdb->prefix . 'siteferry_step' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Releases the lock above.
	}
}

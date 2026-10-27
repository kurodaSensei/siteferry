<?php
/**
 * Guarda el job actual.
 *
 * @package Siteferry
 */

namespace Siteferry\Job;

/**
 * El job vive en una option sin autoload. Solo hay uno a la vez.
 */
final class JobStore {

	// ponytail: un único job global; si hace falta paralelismo, pasar a un bloqueo por job.

	private const OPTION = 'siteferry_job';

	/** Segundos sin actividad tras los que un job abierto se considera abandonado. */
	public const STALE = 600;

	/**
	 * Job actual o null.
	 */
	public static function get(): ?array {
		wp_cache_delete( self::OPTION, 'options' );
		$job = get_option( self::OPTION );
		return is_array( $job ) ? $job : null;
	}

	/**
	 * Guarda el job.
	 *
	 * @param array $job Job.
	 */
	public static function save( array $job ): void {
		update_option( self::OPTION, $job, false );
	}

	/**
	 * Si hay un job en curso que no está abandonado.
	 */
	public static function busy(): bool {
		$job = self::get();
		return Runner::is_open( $job ) && time() - (int) $job['heartbeat'] < self::STALE;
	}

	/**
	 * Bloqueo de MySQL para que dos peticiones no avancen el mismo job a la vez.
	 *
	 * @param int $wait Segundos de espera.
	 */
	public static function lock( int $wait = 0 ): bool {
		global $wpdb;
		return '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $wpdb->prefix . 'siteferry_step', $wait ) );
	}

	/**
	 * Libera el bloqueo.
	 */
	public static function unlock(): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $wpdb->prefix . 'siteferry_step' ) );
	}
}

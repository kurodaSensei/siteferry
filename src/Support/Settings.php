<?php
/**
 * Connection settings for this site.
 *
 * @package Siteferry
 */

namespace Siteferry\Support;

/**
 * Permissions that let other sites pull/push, and the shared key. Everything lives in
 * siteferry_* options, which are not exported and are kept when the database is replaced.
 */
final class Settings {

	private const OPTION = 'siteferry_settings';
	private const KEY    = 'siteferry_connection_key';

	/**
	 * Settings with their default values (everything closed; always verify SSL).
	 *
	 * @return array{allow_pull: bool, allow_pull_files: bool, allow_push: bool, allow_push_code: bool, verify_ssl: bool}
	 */
	public static function get(): array {
		$saved = (array) get_option( self::OPTION, array() );
		return array(
			'allow_pull'       => ! empty( $saved['allow_pull'] ),
			'allow_pull_files' => ! empty( $saved['allow_pull_files'] ),
			'allow_push'       => ! empty( $saved['allow_push'] ),
			'allow_push_code'  => ! empty( $saved['allow_push_code'] ),
			'verify_ssl'       => ! array_key_exists( 'verify_ssl', $saved ) || ! empty( $saved['verify_ssl'] ),
		);
	}

	/**
	 * Saves the given values.
	 *
	 * @param array $values allow_pull, allow_pull_files, allow_push, allow_push_code, verify_ssl.
	 */
	public static function save( array $values ): array {
		$settings = self::get();
		foreach ( array_keys( $settings ) as $name ) {
			if ( array_key_exists( $name, $values ) ) {
				$settings[ $name ] = (bool) $values[ $name ];
			}
		}
		update_option( self::OPTION, $settings, false );
		return $settings;
	}

	/**
	 * This site's shared key (created on first use).
	 */
	public static function key(): string {
		$key = (string) get_option( self::KEY );
		return '' === $key ? self::regenerate() : $key;
	}

	/**
	 * New key: sites that had the previous one can no longer connect.
	 */
	public static function regenerate(): string {
		$key = wp_generate_password( 40, false );
		update_option( self::KEY, $key, false );
		return $key;
	}

	/**
	 * Text to copy into the other site.
	 */
	public static function connection(): string {
		return home_url() . "\n" . self::key();
	}
}

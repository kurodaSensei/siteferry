<?php
/**
 * Ajustes de conexión de este sitio.
 *
 * @package Siteferry
 */

namespace Siteferry\Support;

/**
 * Permisos para que otros sitios hagan pull/push y la clave compartida. Todo vive en opciones
 * siteferry_*, que no se exportan y se conservan al reemplazar la base de datos.
 */
final class Settings {

	private const OPTION = 'siteferry_settings';
	private const KEY    = 'siteferry_connection_key';

	/**
	 * Ajustes con sus valores por defecto (todo cerrado; verificar SSL siempre).
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
	 * Guarda los valores indicados.
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
	 * Clave compartida de este sitio (se crea la primera vez).
	 */
	public static function key(): string {
		$key = (string) get_option( self::KEY );
		return '' === $key ? self::regenerate() : $key;
	}

	/**
	 * Nueva clave: los sitios que tenían la anterior dejan de poder conectarse.
	 */
	public static function regenerate(): string {
		$key = wp_generate_password( 40, false );
		update_option( self::KEY, $key, false );
		return $key;
	}

	/**
	 * Texto que se copia en el otro sitio.
	 */
	public static function connection(): string {
		return home_url() . "\n" . self::key();
	}
}

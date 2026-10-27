<?php
/**
 * Plugin Name:       Siteferry
 * Description:       Exporta, importa y reemplaza en la base de datos de WordPress, con reemplazo seguro en datos serializados.
 * Version:           0.4.0
 * Requires at least: 6.0
 * Requires PHP:      8.1
 * Author:            Kuroda Sensei
 * Text Domain:       siteferry
 *
 * @package Siteferry
 */

defined( 'ABSPATH' ) || exit;

define( 'SITEFERRY_VERSION', '0.4.0' );
define( 'SITEFERRY_FILE', __FILE__ );
define( 'SITEFERRY_PATH', plugin_dir_path( __FILE__ ) );

spl_autoload_register(
	static function ( string $class_name ): void {
		$prefix = 'Siteferry\\';
		if ( str_starts_with( $class_name, $prefix ) ) {
			require SITEFERRY_PATH . 'src/' . str_replace( '\\', '/', substr( $class_name, strlen( $prefix ) ) ) . '.php';
		}
	}
);

register_activation_hook( __FILE__, array( Siteferry\Plugin::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( Siteferry\Plugin::class, 'deactivate' ) );

Siteferry\Plugin::boot();

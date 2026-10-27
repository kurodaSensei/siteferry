<?php
/**
 * Plugin Name:       Siteferry
 * Description:       Export, import and search-replace the WordPress database safely across serialized data, and move databases and files between sites.
 * Version:           0.5.0
 * Requires at least: 6.0
 * Requires PHP:      8.1
 * Author:            Kuroda Sensei
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       siteferry
 * Domain Path:       /languages
 *
 * @package   Siteferry
 * @copyright 2026 Kuroda Sensei
 * @license   GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

define( 'SITEFERRY_VERSION', '0.5.0' );
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

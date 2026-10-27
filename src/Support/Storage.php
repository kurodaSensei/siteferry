<?php
/**
 * Carpeta de archivos del plugin.
 *
 * @package Siteferry
 */

namespace Siteferry\Support;

use Siteferry\Files\Applier;

/**
 * Dumps, backups y subidas en uploads/siteferry-<clave>/. El nombre aleatorio es la
 * protección real (nginx no lee .htaccess); los archivos solo se descargan por REST.
 */
final class Storage {

	/** Días que se conservan los archivos. */
	public const KEEP_DAYS = 7;

	/**
	 * Ruta de la carpeta, creada si no existe.
	 */
	public static function dir(): string {
		$key = (string) get_option( 'siteferry_storage_key' );
		if ( '' === $key ) {
			$key = strtolower( wp_generate_password( 24, false ) );
			update_option( 'siteferry_storage_key', $key, false );
		}
		$dir = wp_upload_dir( null, false )['basedir'] . '/siteferry-' . $key;
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
			file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" );
			file_put_contents( $dir . '/.htaccess', "Require all denied\nDeny from all\n" );
		}
		return $dir;
	}

	/**
	 * Ruta de un archivo de subida a partir de su identificador.
	 *
	 * @param string $token Identificador (16 caracteres hexadecimales).
	 * @param string $ext   sql | sql.gz | sfy.
	 */
	public static function upload_path( string $token, string $ext ): ?string {
		if ( ! preg_match( '/^[a-f0-9]{16}$/', $token ) || ! in_array( $ext, array( 'sql', 'sql.gz', 'sfy' ), true ) ) {
			return null;
		}
		return self::dir() . '/upload-' . $token . '.' . $ext;
	}

	/**
	 * Borra archivos viejos.
	 */
	public static function cleanup(): void {
		$old = time() - self::KEEP_DAYS * DAY_IN_SECONDS;
		foreach ( (array) glob( self::dir() . '/{*.sql*,*.sfy}', GLOB_BRACE ) as $file ) {
			if ( filemtime( $file ) < $old ) {
				wp_delete_file( $file );
			}
		}
		// Sesiones de archivos (listas, zona temporal y backup de lo reemplazado) y temporales sueltos.
		foreach ( (array) glob( self::dir() . '/{files-*,tmp-*}', GLOB_BRACE ) as $path ) {
			if ( filemtime( $path ) < $old ) {
				Applier::remove_tree( $path );
			}
		}
	}
}

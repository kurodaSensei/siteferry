<?php
/**
 * Plugin file folder.
 *
 * @package Siteferry
 */

namespace Siteferry\Support;

use Siteferry\Files\Applier;

/**
 * Dumps, backups and uploads in uploads/siteferry-<key>/. The random name is the
 * real protection (nginx does not read .htaccess); files are only downloaded through REST.
 */
final class Storage {

	/** Days that files are kept. */
	public const KEEP_DAYS = 7;

	/**
	 * Folder path, created if missing.
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
	 * Path of an uploaded file, from its token.
	 *
	 * @param string $token Token (16 hexadecimal characters).
	 * @param string $ext   sql | sql.gz | sfy.
	 */
	public static function upload_path( string $token, string $ext ): ?string {
		if ( ! preg_match( '/^[a-f0-9]{16}$/', $token ) || ! in_array( $ext, array( 'sql', 'sql.gz', 'sfy' ), true ) ) {
			return null;
		}
		return self::dir() . '/upload-' . $token . '.' . $ext;
	}

	/**
	 * Deletes old files.
	 */
	public static function cleanup(): void {
		$old = time() - self::KEEP_DAYS * DAY_IN_SECONDS;
		foreach ( (array) glob( self::dir() . '/{*.sql*,*.sfy}', GLOB_BRACE ) as $file ) {
			if ( filemtime( $file ) < $old ) {
				wp_delete_file( $file );
			}
		}
		// File sessions (lists, staging area and backup of what was replaced) and stray temporary files.
		foreach ( (array) glob( self::dir() . '/{files-*,tmp-*}', GLOB_BRACE ) as $path ) {
			if ( filemtime( $path ) < $old ) {
				Applier::remove_tree( $path );
			}
		}
	}
}

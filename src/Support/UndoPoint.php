<?php
/**
 * Undo point for the last operation that modified this site.
 *
 * @package Siteferry
 */

namespace Siteferry\Support;

use Siteferry\Files\Roots;

/**
 * What can be restored from the last operation: the database backup and the files session
 * (with what was replaced and the list of what arrived). It lives in a siteferry_* option, so it
 * survives the database replacement itself. Paths are stored relative to the working folder:
 * the web and WP-CLI may see the site through different paths (symlinked deployments).
 */
final class UndoPoint {

	private const OPTION = 'siteferry_undo';

	/**
	 * Current point, with only the parts that still exist (backups are deleted after 7 days).
	 */
	public static function get(): ?array {
		$point = get_option( self::OPTION );
		if ( ! is_array( $point ) ) {
			return null;
		}
		$storage            = Storage::dir() . '/';
		$backup             = $storage . basename( (string) ( $point['db_backup'] ?? '' ) );
		$dir                = $storage . basename( (string) ( $point['files_dir'] ?? '' ) );
		$point['db_backup'] = ( $point['db_backup'] ?? '' ) && is_file( $backup ) ? $backup : null;
		$point['files_dir'] = ( $point['files_dir'] ?? '' ) && is_dir( $dir ) ? $dir : null;
		$point['roots']     = $point['files_dir'] ? array_values( array_intersect( Roots::ALL, (array) ( $point['roots'] ?? array() ) ) ) : array();
		return ( $point['db_backup'] || $point['roots'] ) ? $point : null;
	}

	/**
	 * Records the undo point (or completes it, if it is the same operation).
	 *
	 * @param string      $id        Operation (the parts of a single push arrive separately).
	 * @param string      $label     Description.
	 * @param string|null $db_backup Database backup.
	 * @param string|null $files_dir Files session.
	 * @param string[]    $roots     Folders touched.
	 */
	public static function record( string $id, string $label, ?string $db_backup, ?string $files_dir, array $roots ): void {
		$current = get_option( self::OPTION );
		$same    = is_array( $current ) && ( $current['id'] ?? null ) === $id;
		update_option(
			self::OPTION,
			array(
				'id'        => $id,
				'label'     => $label,
				'time'      => time(),
				'db_backup' => null !== $db_backup ? basename( $db_backup ) : ( $same ? $current['db_backup'] : null ),
				'files_dir' => null !== $files_dir ? basename( $files_dir ) : ( $same ? $current['files_dir'] : null ),
				'roots'     => array_values( array_unique( array_merge( $same ? (array) $current['roots'] : array(), $roots ) ) ),
			),
			false
		);
	}

	/**
	 * Point built from a finished local job (replace, import or pull).
	 *
	 * @param array $job Job.
	 */
	public static function from_job( array $job ): void {
		$backup = $job['files']['backup'] ?? null;
		$dir    = $job['site']['storage'] . '/files-' . $job['id'];
		$roots  = array_keys( (array) ( $job['file_stats'] ?? array() ) );
		if ( ! ( $backup && is_file( $backup ) ) && ! ( $roots && is_dir( $dir ) ) ) {
			// No backup from this operation: an earlier point is no longer valid (restoring it would also undo this one).
			self::clear();
			return;
		}
		$labels = array(
			'replace' => 'Search-replace',
			'import'  => 'Import',
			'pull'    => 'Pull from ' . ( $job['remote']['home'] ?? 'another site' ),
		);
		self::record( $job['id'], $labels[ $job['intent'] ] ?? $job['intent'], $backup, $roots ? $dir : null, $roots );
	}

	/**
	 * There is nothing left to undo.
	 */
	public static function clear(): void {
		delete_option( self::OPTION );
	}
}

<?php
/**
 * Punto para deshacer la última operación que modificó este sitio.
 *
 * @package Siteferry
 */

namespace Siteferry\Support;

use Siteferry\Files\Roots;

/**
 * Qué se puede restaurar de la última operación: el backup de la base de datos y la sesión de
 * archivos (con lo reemplazado y la lista de lo que llegó). Vive en una opción siteferry_*, así que
 * sobrevive al propio reemplazo de la base de datos. Las rutas se guardan relativas a la carpeta
 * de trabajo: la web y WP-CLI pueden ver el sitio por rutas distintas (despliegues con symlink).
 */
final class UndoPoint {

	private const OPTION = 'siteferry_undo';

	/**
	 * Punto actual, solo con las partes que siguen existiendo (los backups se borran a los 7 días).
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
	 * Registra (o completa, si es la misma operación) el punto de deshacer.
	 *
	 * @param string      $id        Operación (las partes de un mismo push llegan por separado).
	 * @param string      $label     Descripción.
	 * @param string|null $db_backup Backup de la BD.
	 * @param string|null $files_dir Sesión de archivos.
	 * @param string[]    $roots     Carpetas tocadas.
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
	 * Punto a partir de un job local terminado (reemplazo, importación o pull).
	 *
	 * @param array $job Job.
	 */
	public static function from_job( array $job ): void {
		$backup = $job['files']['backup'] ?? null;
		$dir    = $job['site']['storage'] . '/files-' . $job['id'];
		$roots  = array_keys( (array) ( $job['file_stats'] ?? array() ) );
		if ( ! ( $backup && is_file( $backup ) ) && ! ( $roots && is_dir( $dir ) ) ) {
			// Sin backup de esta operación: un punto anterior ya no sirve (restaurarlo desharía también esta).
			self::clear();
			return;
		}
		$labels = array(
			'replace' => 'Buscar y reemplazar',
			'import'  => 'Importación',
			'pull'    => 'Pull desde ' . ( $job['remote']['home'] ?? 'otro sitio' ),
		);
		self::record( $job['id'], $labels[ $job['intent'] ] ?? $job['intent'], $backup, $roots ? $dir : null, $roots );
	}

	/**
	 * Ya no hay nada que deshacer.
	 */
	public static function clear(): void {
		delete_option( self::OPTION );
	}
}

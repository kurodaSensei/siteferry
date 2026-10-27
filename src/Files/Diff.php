<?php
/**
 * Qué archivos hay que enviar o borrar.
 *
 * @package Siteferry
 */

namespace Siteferry\Files;

/**
 * Compara el manifiesto del origen con el del destino.
 */
final class Diff {

	/**
	 * Escribe en $send lo nuevo o distinto (tamaño o md5) y, si se pasa $delete, lo que el
	 * destino tiene y el origen no.
	 *
	 * @param Manifest      $source Origen.
	 * @param Manifest      $target Destino.
	 * @param Manifest      $send   Salida: archivos a enviar.
	 * @param Manifest|null $delete Salida: archivos a borrar en el destino.
	 * @return array{send: int, bytes: int, delete: int}
	 */
	public static function compute( Manifest $source, Manifest $target, Manifest $send, ?Manifest $delete = null ): array {
		// ponytail: el manifiesto del destino se carga entero en memoria (≈100 bytes por archivo); con millones
		// de archivos convendría ordenar ambos y compararlos en una pasada.
		$have = array();
		foreach ( $target->entries() as [ $file, $size, $md5 ] ) {
			$have[ $file ] = array( $size, $md5 );
		}
		$stats = array(
			'send'   => 0,
			'bytes'  => 0,
			'delete' => 0,
		);
		foreach ( $source->entries() as [ $file, $size, $md5 ] ) {
			if ( ( $have[ $file ] ?? null ) !== array( $size, $md5 ) ) {
				$send->add( $file, $size, $md5 );
				++$stats['send'];
				$stats['bytes'] += $size;
			}
			unset( $have[ $file ] );
		}
		$send->close();
		if ( $delete ) {
			foreach ( $have as $file => [ $size, $md5 ] ) {
				$delete->add( $file, $size, $md5 );
				++$stats['delete'];
			}
			$delete->close();
		}
		return $stats;
	}
}

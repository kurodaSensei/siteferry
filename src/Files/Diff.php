<?php
/**
 * Which files need to be sent or deleted.
 *
 * @package Siteferry
 */

namespace Siteferry\Files;

/**
 * Compares the source manifest with the destination manifest.
 */
final class Diff {

	/**
	 * Writes to $send whatever is new or different (size or md5) and, if $delete is passed, whatever the
	 * destination has and the source does not.
	 *
	 * @param Manifest      $source Source.
	 * @param Manifest      $target Destination.
	 * @param Manifest      $send   Output: files to send.
	 * @param Manifest|null $delete Output: files to delete on the destination.
	 * @return array{send: int, bytes: int, delete: int}
	 */
	public static function compute( Manifest $source, Manifest $target, Manifest $send, ?Manifest $delete = null ): array {
		// ponytail: the destination manifest is loaded entirely into memory (≈100 bytes per file); with millions
		// of files it would be better to sort both and compare them in a single pass.
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

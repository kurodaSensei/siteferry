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
	 * Writes to $send whatever is new or different (size or md5), or only what is new with
	 * $only_new, and, if $delete is passed, whatever the destination has and the source does not.
	 * The three groups are counted in full whatever is sent, so a person can review them.
	 *
	 * @param Manifest      $source   Source.
	 * @param Manifest      $target   Destination.
	 * @param Manifest      $send     Output: files to send.
	 * @param Manifest|null $delete   Output: files to delete on the destination.
	 * @param bool          $only_new Leave alone the files that exist on the destination with other content.
	 * @return array{send: int, bytes: int, delete: int, new: int, new_bytes: int, changed: int, changed_bytes: int, extra: int, extra_bytes: int}
	 */
	public static function compute( Manifest $source, Manifest $target, Manifest $send, ?Manifest $delete = null, bool $only_new = false ): array {
		// ponytail: the destination manifest is loaded entirely into memory (≈100 bytes per file); with millions
		// of files it would be better to sort both and compare them in a single pass.
		$have = array();
		foreach ( $target->entries() as [ $file, $size, $md5 ] ) {
			$have[ $file ] = array( $size, $md5 );
		}
		$stats = array_fill_keys( array( 'send', 'bytes', 'delete', 'new', 'new_bytes', 'changed', 'changed_bytes', 'extra', 'extra_bytes' ), 0 );
		foreach ( $source->entries() as [ $file, $size, $md5 ] ) {
			$there = $have[ $file ] ?? null;
			unset( $have[ $file ] );
			if ( array( $size, $md5 ) === $there ) {
				continue;
			}
			$kind = null === $there ? 'new' : 'changed';
			++$stats[ $kind ];
			$stats[ "{$kind}_bytes" ] += $size;
			if ( 'new' === $kind || ! $only_new ) {
				$send->add( $file, $size, $md5 );
				++$stats['send'];
				$stats['bytes'] += $size;
			}
		}
		$send->close();
		foreach ( $have as $file => [ $size, $md5 ] ) {
			++$stats['extra'];
			$stats['extra_bytes'] += $size;
			if ( $delete ) {
				$delete->add( $file, $size, $md5 );
				++$stats['delete'];
			}
		}
		$delete?->close();
		return $stats;
	}
}

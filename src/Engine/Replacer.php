<?php
/**
 * Safe search-replace for column values.
 *
 * @package Siteferry
 */

namespace Siteferry\Engine;

/**
 * Replaces in plain text, in PHP serialized data and in JSON.
 *
 * Serialized data is walked as text and the length of every string is
 * recalculated: it is never unserialized, so no objects are instantiated and
 * floats, references and unknown classes are left exactly as they were.
 * A value is only rewritten if something changed.
 */
final class Replacer {

	private const MAX_DEPTH = 32;

	/**
	 * Strings to search for.
	 *
	 * @var list<string>
	 */
	private array $search = array();

	/**
	 * Replacements, in the same order as $search.
	 *
	 * @var list<string>
	 */
	private array $replace = array();

	/**
	 * Constructor.
	 *
	 * @param array<int, array{0: string, 1: string}> $pairs          [search, replace] pairs.
	 * @param bool                                    $case_sensitive Match case (non-regex only).
	 * @param bool                                    $regex          The "search" values are complete PCRE patterns (with delimiters).
	 */
	public function __construct( array $pairs, private bool $case_sensitive = false, private bool $regex = false ) {
		foreach ( $pairs as [ $find, $to ] ) {
			if ( '' === $find ) {
				continue;
			}
			$this->search[]  = $find;
			$this->replace[] = $to;
			if ( ! $regex && str_contains( $find, '/' ) ) {
				// The same URL escaped as in JSON (https:\/\/…), e.g. in block attributes.
				$this->search[]  = str_replace( '/', '\/', $find );
				$this->replace[] = str_replace( '/', '\/', $to );
			}
		}
	}

	/**
	 * Whether there is nothing to replace.
	 */
	public function is_empty(): bool {
		return ! $this->search;
	}

	/**
	 * Replaces in a column value.
	 *
	 * @param string $value Value as it comes out of the database.
	 */
	public function value( string $value ): string {
		if ( ! $this->search || ( ! $this->regex && ! $this->may_match( $value ) ) ) {
			return $value;
		}
		return $this->any( $value, 0 );
	}

	/**
	 * Quick rejection: almost no value contains what is being searched for.
	 *
	 * @param string $value Value.
	 */
	private function may_match( string $value ): bool {
		if ( str_contains( $value, '\\u' ) ) {
			return true; // JSON with unicode escapes: it has to be decoded to know.
		}
		foreach ( $this->search as $find ) {
			if ( false !== ( $this->case_sensitive ? strpos( $value, $find ) : stripos( $value, $find ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Decides whether the value is serialized data, JSON or text.
	 *
	 * @param string $value Value.
	 * @param int    $depth Nesting depth (serialized inside serialized…).
	 */
	private function any( string $value, int $depth ): string {
		if ( $depth > self::MAX_DEPTH ) {
			return $value;
		}
		if ( self::looks_serialized( $value ) ) {
			try {
				$pos = 0;
				$out = $this->walk( $value, $pos, $depth, true );
				if ( strlen( $value ) === $pos ) {
					return $out;
				}
			} catch ( \UnexpectedValueException ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
				// Invalid serialized data (e.g. lengths already broken): treated as text.
			}
		}
		return $this->json( $value, $depth ) ?? $this->text( $value );
	}

	/**
	 * Cheap check before attempting to walk the serialized data.
	 *
	 * @param string $value Value.
	 */
	private static function looks_serialized( string $value ): bool {
		$last = substr( $value, -1 );
		return ( ';' === $last || '}' === $last ) && 1 === preg_match( '/^(?:N;|[bidsaOCE]:)/', $value );
	}

	/**
	 * Walks a serialized token from $p and returns it rebuilt.
	 *
	 * @param string $s       Full serialized text.
	 * @param int    $p       Current position (advanced).
	 * @param int    $depth   Depth.
	 * @param bool   $replace Replace in the strings of this token (keys are left alone).
	 *
	 * @throws \UnexpectedValueException If the format is not valid.
	 */
	private function walk( string $s, int &$p, int $depth, bool $replace ): string {
		$start = $p;
		switch ( $s[ $p ] ?? '' ) {
			case 'N':
				$this->expect( $s, $p, 'N;' );
				return 'N;';

			case 'b':
			case 'i':
			case 'd':
			case 'r':
			case 'R':
				++$p;
				$this->expect( $s, $p, ':' );
				$end = strpos( $s, ';', $p );
				if ( false === $end ) {
					throw new \UnexpectedValueException();
				}
				$p = $end + 1;
				return substr( $s, $start, $p - $start );

			case 's':
				$len = $this->length( $s, $p );
				$this->expect( $s, $p, '"' );
				$str = substr( $s, $p, $len );
				if ( strlen( $str ) !== $len ) {
					throw new \UnexpectedValueException();
				}
				$p += $len;
				$this->expect( $s, $p, '";' );
				if ( $replace ) {
					$str = $this->any( $str, $depth + 1 );
				}
				return 's:' . strlen( $str ) . ':"' . $str . '";';

			case 'a':
				$count = $this->length( $s, $p );
				$this->expect( $s, $p, '{' );
				return 'a:' . $count . ':{' . $this->members( $s, $p, $count, $depth, $replace ) . '}';

			case 'O':
				$name = $this->class_name( $s, $p );
				$this->expect( $s, $p, ':' );
				$count = $this->number( $s, $p, ':' );
				$this->expect( $s, $p, '{' );
				return 'O:' . strlen( $name ) . ':"' . $name . '":' . $count . ':{' . $this->members( $s, $p, $count, $depth, $replace ) . '}';

			case 'C':
				// The class's own serialization: it is opaque, copied as is.
				$this->class_name( $s, $p );
				$this->expect( $s, $p, ':' );
				$len = $this->number( $s, $p, ':' );
				$this->expect( $s, $p, '{' );
				$p += $len;
				$this->expect( $s, $p, '}' );
				return substr( $s, $start, $p - $start );

			case 'E':
				$len = $this->length( $s, $p );
				$this->expect( $s, $p, '"' );
				$p += $len;
				$this->expect( $s, $p, '";' );
				return substr( $s, $start, $p - $start );
		}
		throw new \UnexpectedValueException();
	}

	/**
	 * Walks $count key/value pairs of an array or object and consumes the closing "}".
	 *
	 * @param string $s       Serialized text.
	 * @param int    $p       Position (advanced).
	 * @param int    $count   Number of pairs.
	 * @param int    $depth   Depth.
	 * @param bool   $replace Replace in the values.
	 */
	private function members( string $s, int &$p, int $count, int $depth, bool $replace ): string {
		$out = '';
		for ( $i = 0; $i < $count; $i++ ) {
			$out .= $this->walk( $s, $p, $depth, false );
			$out .= $this->walk( $s, $p, $depth, $replace );
		}
		$this->expect( $s, $p, '}' );
		return $out;
	}

	/**
	 * Reads `X:len:"Name"` and returns the class name.
	 *
	 * @param string $s Serialized text.
	 * @param int    $p Position (advanced).
	 */
	private function class_name( string $s, int &$p ): string {
		$len = $this->length( $s, $p );
		$this->expect( $s, $p, '"' );
		$name = substr( $s, $p, $len );
		$p   += $len;
		$this->expect( $s, $p, '"' );
		return $name;
	}

	/**
	 * Reads `X:123:` and returns 123.
	 *
	 * @param string $s Serialized text.
	 * @param int    $p Position (advanced).
	 */
	private function length( string $s, int &$p ): int {
		++$p;
		$this->expect( $s, $p, ':' );
		return $this->number( $s, $p, ':' );
	}

	/**
	 * Reads digits up to $end (and consumes it).
	 *
	 * @param string $s   Serialized text.
	 * @param int    $p   Position (advanced).
	 * @param string $end Terminating character.
	 *
	 * @throws \UnexpectedValueException If they are not digits.
	 */
	private function number( string $s, int &$p, string $end ): int {
		$stop = strpos( $s, $end, $p );
		if ( false === $stop ) {
			throw new \UnexpectedValueException();
		}
		$digits = substr( $s, $p, $stop - $p );
		if ( ! ctype_digit( $digits ) ) {
			throw new \UnexpectedValueException();
		}
		$p = $stop + 1;
		return (int) $digits;
	}

	/**
	 * Requires the text $token at position $p and consumes it.
	 *
	 * @param string $s     Serialized text.
	 * @param int    $p     Position (advanced).
	 * @param string $token Expected text.
	 *
	 * @throws \UnexpectedValueException If it is not there.
	 */
	private function expect( string $s, int &$p, string $token ): void {
		if ( substr( $s, $p, strlen( $token ) ) !== $token ) {
			throw new \UnexpectedValueException();
		}
		$p += strlen( $token );
	}

	/**
	 * Replaces inside a JSON document. Returns null if the value is not JSON.
	 *
	 * @param string $value Value.
	 * @param int    $depth Depth.
	 */
	private function json( string $value, int $depth ): ?string {
		$first = $value[0] ?? '';
		if ( '{' !== $first && '[' !== $first ) {
			return null;
		}
		// ponytail: integers larger than PHP_INT_MAX become floats when re-encoded; only affects values that do change.
		$data = json_decode( $value );
		if ( ! is_array( $data ) && ! is_object( $data ) ) {
			return null;
		}
		$changed = false;
		$data    = $this->json_walk( $data, $depth, $changed );
		if ( ! $changed ) {
			return $value;
		}
		// Preserve the original's style.
		$flags = JSON_PRESERVE_ZERO_FRACTION;
		if ( ! str_contains( $value, '\/' ) ) {
			$flags |= JSON_UNESCAPED_SLASHES;
		}
		if ( ! str_contains( $value, '\u' ) ) {
			$flags |= JSON_UNESCAPED_UNICODE;
		}
		$out = json_encode( $data, $flags ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- the engine does not depend on WordPress.
		return false === $out ? $value : $out;
	}

	/**
	 * Walks the decoded contents of a JSON document.
	 *
	 * @param mixed $data    Node.
	 * @param int   $depth   Depth.
	 * @param bool  $changed Set to true if something changed.
	 */
	private function json_walk( mixed $data, int $depth, bool &$changed ): mixed {
		if ( is_string( $data ) ) {
			$new     = $this->any( $data, $depth + 1 );
			$changed = $changed || $new !== $data;
			return $new;
		}
		if ( is_array( $data ) ) {
			foreach ( $data as $key => $item ) {
				$data[ $key ] = $this->json_walk( $item, $depth, $changed );
			}
		} elseif ( is_object( $data ) ) {
			foreach ( get_object_vars( $data ) as $key => $item ) {
				$data->$key = $this->json_walk( $item, $depth, $changed );
			}
		}
		return $data;
	}

	/**
	 * Replacement in plain text.
	 *
	 * @param string $value Value.
	 */
	private function text( string $value ): string {
		if ( $this->regex ) {
			foreach ( $this->search as $i => $pattern ) {
				$new = preg_replace( $pattern, $this->replace[ $i ], $value );
				if ( null !== $new ) {
					$value = $new;
				}
			}
			return $value;
		}
		return $this->case_sensitive
			? str_replace( $this->search, $this->replace, $value )
			: str_ireplace( $this->search, $this->replace, $value );
	}
}

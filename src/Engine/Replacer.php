<?php
/**
 * Buscar y reemplazar seguro para valores de columna.
 *
 * @package Siteferry
 */

namespace Siteferry\Engine;

/**
 * Reemplaza en texto plano, en datos serializados de PHP y en JSON.
 *
 * Los serializados se recorren como texto y se recalculan las longitudes de cada
 * string: nunca se deserializa, así que no se instancian objetos y los float,
 * referencias y clases desconocidas quedan exactamente como estaban.
 * Un valor solo se reescribe si algo cambió.
 */
final class Replacer {

	private const MAX_DEPTH = 32;

	/**
	 * Textos a buscar.
	 *
	 * @var list<string>
	 */
	private array $search = array();

	/**
	 * Reemplazos, en el mismo orden que $search.
	 *
	 * @var list<string>
	 */
	private array $replace = array();

	/**
	 * Constructor.
	 *
	 * @param array<int, array{0: string, 1: string}> $pairs          Pares [buscar, reemplazar].
	 * @param bool                                    $case_sensitive Distinguir mayúsculas (sin regex).
	 * @param bool                                    $regex          Los "buscar" son patrones PCRE completos (con delimitadores).
	 */
	public function __construct( array $pairs, private bool $case_sensitive = false, private bool $regex = false ) {
		foreach ( $pairs as [ $find, $to ] ) {
			if ( '' === $find ) {
				continue;
			}
			$this->search[]  = $find;
			$this->replace[] = $to;
			if ( ! $regex && str_contains( $find, '/' ) ) {
				// La misma URL escapada como en JSON (https:\/\/…), p. ej. en atributos de bloques.
				$this->search[]  = str_replace( '/', '\/', $find );
				$this->replace[] = str_replace( '/', '\/', $to );
			}
		}
	}

	/**
	 * Si no hay nada que reemplazar.
	 */
	public function is_empty(): bool {
		return ! $this->search;
	}

	/**
	 * Reemplaza en un valor de columna.
	 *
	 * @param string $value Valor tal como sale de la base de datos.
	 */
	public function value( string $value ): string {
		if ( ! $this->search || ( ! $this->regex && ! $this->may_match( $value ) ) ) {
			return $value;
		}
		return $this->any( $value, 0 );
	}

	/**
	 * Descarte rápido: casi ningún valor contiene lo que se busca.
	 *
	 * @param string $value Valor.
	 */
	private function may_match( string $value ): bool {
		if ( str_contains( $value, '\\u' ) ) {
			return true; // JSON con escapes unicode: hay que decodificar para saberlo.
		}
		foreach ( $this->search as $find ) {
			if ( false !== ( $this->case_sensitive ? strpos( $value, $find ) : stripos( $value, $find ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Decide si el valor es serializado, JSON o texto.
	 *
	 * @param string $value Valor.
	 * @param int    $depth Profundidad de anidamiento (serializado dentro de serializado…).
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
				// Serializado inválido (p. ej. longitudes ya rotas): se trata como texto.
			}
		}
		return $this->json( $value, $depth ) ?? $this->text( $value );
	}

	/**
	 * Comprobación barata antes de intentar recorrer el serializado.
	 *
	 * @param string $value Valor.
	 */
	private static function looks_serialized( string $value ): bool {
		$last = substr( $value, -1 );
		return ( ';' === $last || '}' === $last ) && 1 === preg_match( '/^(?:N;|[bidsaOCE]:)/', $value );
	}

	/**
	 * Recorre un token serializado desde $p y lo devuelve reconstruido.
	 *
	 * @param string $s       Texto serializado completo.
	 * @param int    $p       Posición actual (se avanza).
	 * @param int    $depth   Profundidad.
	 * @param bool   $replace Reemplazar en los strings de este token (las claves no se tocan).
	 *
	 * @throws \UnexpectedValueException Si el formato no es válido.
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
				// Serialización propia de la clase: es opaca, se copia tal cual.
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
	 * Recorre $count pares clave/valor de un array u objeto y consume el "}" final.
	 *
	 * @param string $s       Texto serializado.
	 * @param int    $p       Posición (se avanza).
	 * @param int    $count   Número de pares.
	 * @param int    $depth   Profundidad.
	 * @param bool   $replace Reemplazar en los valores.
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
	 * Lee `X:len:"Nombre"` y devuelve el nombre de la clase.
	 *
	 * @param string $s Texto serializado.
	 * @param int    $p Posición (se avanza).
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
	 * Lee `X:123:` y devuelve 123.
	 *
	 * @param string $s Texto serializado.
	 * @param int    $p Posición (se avanza).
	 */
	private function length( string $s, int &$p ): int {
		++$p;
		$this->expect( $s, $p, ':' );
		return $this->number( $s, $p, ':' );
	}

	/**
	 * Lee dígitos hasta $end (y lo consume).
	 *
	 * @param string $s   Texto serializado.
	 * @param int    $p   Posición (se avanza).
	 * @param string $end Carácter terminador.
	 *
	 * @throws \UnexpectedValueException Si no son dígitos.
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
	 * Exige el texto $token en la posición $p y lo consume.
	 *
	 * @param string $s     Texto serializado.
	 * @param int    $p     Posición (se avanza).
	 * @param string $token Texto esperado.
	 *
	 * @throws \UnexpectedValueException Si no está.
	 */
	private function expect( string $s, int &$p, string $token ): void {
		if ( substr( $s, $p, strlen( $token ) ) !== $token ) {
			throw new \UnexpectedValueException();
		}
		$p += strlen( $token );
	}

	/**
	 * Reemplaza dentro de un documento JSON. Devuelve null si el valor no es JSON.
	 *
	 * @param string $value Valor.
	 * @param int    $depth Profundidad.
	 */
	private function json( string $value, int $depth ): ?string {
		$first = $value[0] ?? '';
		if ( '{' !== $first && '[' !== $first ) {
			return null;
		}
		// ponytail: enteros mayores que PHP_INT_MAX pasan a float al re-codificar; solo afecta a valores que sí cambian.
		$data = json_decode( $value );
		if ( ! is_array( $data ) && ! is_object( $data ) ) {
			return null;
		}
		$changed = false;
		$data    = $this->json_walk( $data, $depth, $changed );
		if ( ! $changed ) {
			return $value;
		}
		// Conserva el estilo del original.
		$flags = JSON_PRESERVE_ZERO_FRACTION;
		if ( ! str_contains( $value, '\/' ) ) {
			$flags |= JSON_UNESCAPED_SLASHES;
		}
		if ( ! str_contains( $value, '\u' ) ) {
			$flags |= JSON_UNESCAPED_UNICODE;
		}
		$out = json_encode( $data, $flags ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- el motor no depende de WordPress.
		return false === $out ? $value : $out;
	}

	/**
	 * Recorre lo decodificado de un JSON.
	 *
	 * @param mixed $data    Nodo.
	 * @param int   $depth   Profundidad.
	 * @param bool  $changed Se pone en true si algo cambió.
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
	 * Reemplazo en texto plano.
	 *
	 * @param string $value Valor.
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

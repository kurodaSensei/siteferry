<?php
/**
 * Firma de los mensajes entre sitios.
 *
 * @package Siteferry
 */

namespace Siteferry\Remote;

/**
 * HMAC-SHA256 sobre el cuerpo completo (que incluye op, argumentos, timestamp y nonce).
 * Cambiar un solo byte de la petición o de la respuesta invalida la firma.
 */
final class Signer {

	/** Segundos de diferencia de reloj tolerados. */
	public const MAX_SKEW = 300;

	/** Cabecera HTTP con la firma. */
	public const HEADER = 'X-Siteferry-Signature';

	/**
	 * Firma un cuerpo.
	 *
	 * @param string $body Cuerpo exacto.
	 * @param string $key  Clave compartida.
	 */
	public static function sign( string $body, string $key ): string {
		return hash_hmac( 'sha256', $body, $key );
	}

	/**
	 * Comprueba una firma en tiempo constante.
	 *
	 * @param string $body      Cuerpo exacto.
	 * @param string $signature Firma recibida.
	 * @param string $key       Clave compartida.
	 */
	public static function valid( string $body, string $signature, string $key ): bool {
		return '' !== $key && '' !== $signature && hash_equals( self::sign( $body, $key ), $signature );
	}

	/**
	 * Línea firmable de una petición. Si la acompaña un binario, lleva su sha256.
	 *
	 * @param string      $op     Operación.
	 * @param array       $args   Argumentos.
	 * @param string|null $binary Binario que viaja después de la línea.
	 */
	public static function message( string $op, array $args, ?string $binary = null ): string {
		$message = array(
			'op'    => $op,
			'args'  => $args,
			'ts'    => time(),
			'nonce' => bin2hex( random_bytes( 16 ) ),
		);
		if ( null !== $binary ) {
			$message['sha256'] = hash( 'sha256', $binary );
		}
		return (string) json_encode( $message, JSON_UNESCAPED_SLASHES ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
	}

	/**
	 * Separa un cuerpo enmarcado: la línea JSON firmada y, si hay, el binario que la sigue.
	 * JSON nunca contiene saltos de línea crudos, así que el primero marca el final de la línea.
	 *
	 * @param string $body Cuerpo.
	 * @return array{0: string, 1: ?string}
	 */
	public static function split( string $body ): array {
		$newline = strpos( $body, "\n" );
		return false === $newline ? array( $body, null ) : array( substr( $body, 0, $newline ), (string) substr( $body, $newline + 1 ) );
	}

	/**
	 * Separa "URL CLAVE" (en una o dos líneas) en sus partes.
	 *
	 * @param string $connection Texto copiado del otro sitio.
	 * @return array{url: string, key: string}
	 *
	 * @throws \InvalidArgumentException Si no tiene el formato esperado.
	 */
	public static function parse_connection( string $connection ): array {
		$parts = preg_split( '/\s+/', trim( $connection ) );
		$url   = rtrim( (string) ( $parts[0] ?? '' ), '/' );
		$key   = (string) ( $parts[1] ?? '' );
		if ( 2 !== count( $parts ) || ! preg_match( '#^https?://#i', $url ) || ! preg_match( '/^[A-Za-z0-9]{32,64}$/', $key ) ) {
			throw new \InvalidArgumentException( 'La información de conexión debe ser la URL del sitio y su clave, tal como aparecen en Siteferry del otro sitio.' );
		}
		// Por http la base de datos viajaría en claro: solo se permite hacia hosts de desarrollo.
		if ( ! preg_match( '#^https://#i', $url ) && ! self::is_dev_host( (string) parse_url( $url, PHP_URL_HOST ) ) ) {
			throw new \InvalidArgumentException( 'Usa https: por http la base de datos viajaría sin cifrar. Solo se permite http con hosts de desarrollo (localhost, .test, .local, IPs privadas).' );
		}
		return array(
			'url' => $url,
			'key' => $key,
		);
	}

	/**
	 * Hosts donde se acepta http: la propia máquina, dominios de desarrollo, nombres sin punto
	 * (contenedores, intranet) e IPs privadas.
	 *
	 * @param string $host Host de la URL.
	 */
	public static function is_dev_host( string $host ): bool {
		$host = strtolower( trim( $host, '[]' ) );
		if ( '' === $host ) {
			return false;
		}
		if ( 'localhost' === $host || '::1' === $host || ( ! str_contains( $host, '.' ) && ! str_contains( $host, ':' ) ) ) {
			return true;
		}
		foreach ( array( '.test', '.localhost', '.local', '.internal' ) as $suffix ) {
			if ( str_ends_with( $host, $suffix ) ) {
				return true;
			}
		}
		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return str_starts_with( $host, '127.' ) || ! filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
		}
		return false;
	}
}

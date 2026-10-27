<?php
/**
 * Llamadas firmadas a otro sitio.
 *
 * @package Siteferry
 */

namespace Siteferry\Remote;

/**
 * Cliente HTTP del protocolo entre sitios: firma cada petición y solo acepta respuestas
 * firmadas con la misma clave.
 */
final class Client {

	/**
	 * Constructor.
	 *
	 * @param string $url        URL del otro sitio.
	 * @param string $key        Clave del otro sitio.
	 * @param bool   $verify_ssl Verificar el certificado HTTPS.
	 */
	public function __construct( private string $url, private string $key, private bool $verify_ssl = true ) {}

	/**
	 * Cliente con la conexión guardada en un job.
	 *
	 * @param array $job Job de pull o push.
	 */
	public static function for_job( array $job ): self {
		return new self( $job['remote']['url'], $job['remote']['key'], $job['remote']['verify_ssl'] );
	}

	/**
	 * Llama a una operación. Los cortes de red y errores 5xx se reintentan: todas las operaciones
	 * son seguras de repetir salvo push_begin.
	 *
	 * @param string      $op     Operación.
	 * @param array       $args   Argumentos.
	 * @param string|null $binary Binario que acompaña a la petición (files_put).
	 * @return array Datos; si la respuesta trae un binario (files_get), va en 'package'.
	 *
	 * @throws \RuntimeException Si no hay respuesta válida.
	 */
	public function call( string $op, array $args = array(), ?string $binary = null ): array {
		$attempts = 'push_begin' === $op ? 1 : 3;
		for ( $attempt = 1; ; $attempt++ ) {
			$message  = Signer::message( $op, $args, $binary );
			$response = wp_remote_post(
				$this->url . '/?rest_route=/siteferry/v1/remote',
				array(
					'body'        => null === $binary ? $message : $message . "\n" . $binary,
					'timeout'     => 90,
					'redirection' => 0,
					'sslverify'   => $this->verify_ssl,
					'headers'     => array(
						// Con binario no es JSON válido: la REST de WordPress lo rechazaría al intentar leerlo.
						'Content-Type' => null === $binary ? 'application/json' : 'application/octet-stream',
						Signer::HEADER => Signer::sign( $message, $this->key ),
					),
				)
			);
			if ( is_wp_error( $response ) ) {
				if ( $attempt < $attempts ) {
					sleep( 2 * $attempt );
					continue;
				}
				throw new \RuntimeException( 'No se pudo conectar con ' . $this->url . ': ' . $response->get_error_message() );
			}
			$code = (int) wp_remote_retrieve_response_code( $response );
			$raw  = (string) wp_remote_retrieve_body( $response );
			if ( $code >= 500 && $attempt < $attempts ) {
				sleep( 2 * $attempt );
				continue;
			}
			[ $line, $package ] = Signer::split( $raw );
			if ( ! Signer::valid( $line, (string) wp_remote_retrieve_header( $response, strtolower( Signer::HEADER ) ), $this->key ) ) {
				throw new \RuntimeException( $this->unsigned( $code, $raw, (string) wp_remote_retrieve_header( $response, 'location' ) ) );
			}
			$data = json_decode( $line, true );
			if ( ( $data['nonce'] ?? null ) !== json_decode( $message, true )['nonce'] ) {
				throw new \RuntimeException( 'El sitio remoto devolvió una respuesta que no corresponde a la petición.' );
			}
			if ( null !== $package && hash( 'sha256', $package ) !== ( $data['sha256'] ?? null ) ) {
				throw new \RuntimeException( 'El paquete del sitio remoto llegó dañado.' );
			}
			if ( empty( $data['ok'] ) ) {
				throw new \RuntimeException( 'Sitio remoto: ' . ( $data['error'] ?? 'error desconocido' ) );
			}
			$result = (array) $data['data'];
			if ( null !== $package ) {
				$result['package'] = $package;
			}
			return $result;
		}
	}

	/**
	 * Explica una respuesta sin firma válida.
	 *
	 * @param int    $code     Código HTTP.
	 * @param string $raw      Cuerpo (no confiable).
	 * @param string $location Destino de una redirección.
	 */
	private function unsigned( int $code, string $raw, string $location ): string {
		// Se busca como texto: si un plugin del otro sitio imprime algo antes, el JSON llega con basura delante.
		if ( str_contains( $raw, '"code":"siteferry_signature"' ) ) {
			return 'La clave de conexión no coincide con la del sitio remoto. Copia de nuevo su información de conexión.';
		}
		if ( $code >= 300 && $code < 400 ) {
			return "El sitio remoto redirige a $location. Usa esa URL en la conexión.";
		}
		if ( 404 === $code ) {
			return 'El sitio remoto no tiene Siteferry activo o la URL no es correcta.';
		}
		return "Respuesta no válida del sitio remoto (HTTP $code). ¿Tiene Siteferry activo y actualizado?";
	}
}

<?php
/**
 * Plugin Name: Siteferry — compatibilidad
 * Description: Durante los pasos de una migración desactiva los demás plugins y el tema para que no interfieran. Lo instala y lo quita Siteferry.
 *
 * @package Siteferry
 */

defined( 'ABSPATH' ) || exit;

( static function (): void {
	// Solo dos rutas exactas (con /wp-json/… o ?rest_route=…), nunca otra URL que las contenga en
	// el query string: apagar plugins en otra petición saltaría plugins de seguridad.
	// phpcs:disable WordPress.Security.ValidatedSanitizedInput, WordPress.Security.NonceVerification
	$route = isset( $_GET['rest_route'] )
		? (string) wp_unslash( $_GET['rest_route'] )
		: (string) wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH );
	$route = rawurldecode( $route );

	if ( preg_match( '#/siteferry/v1/jobs/[a-f0-9]{16}/step/?$#', $route ) ) {
		// Paso de un job de esta interfaz: la clave de compatibilidad y un job abierto.
		$key = (string) get_option( 'siteferry_compat_key' );
		if ( '' === $key || ! hash_equals( $key, (string) ( $_SERVER['HTTP_X_SITEFERRY_COMPAT'] ?? '' ) ) ) {
			return;
		}
		$job = get_option( 'siteferry_job' );
		if ( ! is_array( $job ) || ! in_array( $job['status'] ?? '', array( 'pending', 'running' ), true ) ) {
			return;
		}
	} elseif ( preg_match( '#/siteferry/v1/remote/?$#', $route ) ) {
		// Petición de otro sitio: firma HMAC válida sobre la línea JSON del cuerpo (la que firma el protocolo).
		$key  = (string) get_option( 'siteferry_connection_key' );
		$sent = (string) ( $_SERVER['HTTP_X_SITEFERRY_SIGNATURE'] ?? '' );
		$body = (string) file_get_contents( 'php://input' );
		$line = strstr( $body, "\n", true );
		if ( '' === $key || '' === $sent || ! hash_equals( hash_hmac( 'sha256', false === $line ? $body : $line, $key ), $sent ) ) {
			return;
		}
	} else {
		return;
	}
	// phpcs:enable

	add_filter(
		'option_active_plugins',
		static fn( $plugins ) => is_array( $plugins )
			? array_values( array_filter( $plugins, static fn( $p ) => str_ends_with( (string) $p, '/siteferry.php' ) ) )
			: $plugins
	);
	// Sin functions.php del tema: WordPress solo lo carga si existe en el directorio del tema.
	$nowhere = static fn() => WP_CONTENT_DIR . '/siteferry-no-theme';
	add_filter( 'template_directory', $nowhere );
	add_filter( 'stylesheet_directory', $nowhere );
} )();

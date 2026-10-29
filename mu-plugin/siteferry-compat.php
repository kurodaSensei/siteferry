<?php
/**
 * Plugin Name: Siteferry — compatibilidad
 * Description: During migration steps, switches off the other plugins and the theme so they cannot interfere. Installed and removed by Siteferry.
 *
 * @package Siteferry
 */

defined( 'ABSPATH' ) || exit;

( static function (): void {
	// Only two exact routes (via /wp-json/… or ?rest_route=…), never another URL that merely contains
	// them in the query string: switching plugins off on any other request would bypass security plugins.
	// phpcs:disable WordPress.Security.ValidatedSanitizedInput, WordPress.Security.NonceVerification
	$route = isset( $_GET['rest_route'] )
		? (string) wp_unslash( $_GET['rest_route'] )
		: (string) wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH );
	$route = rawurldecode( $route );

	if ( preg_match( '#/siteferry/v1/jobs/[a-f0-9]{16}/step/?$#', $route ) ) {
		// A job step from this site's UI: the compatibility key and an open job.
		$key = (string) get_option( 'siteferry_compat_key' );
		if ( '' === $key || ! hash_equals( $key, (string) ( $_SERVER['HTTP_X_SITEFERRY_COMPAT'] ?? '' ) ) ) {
			return;
		}
		$job = get_option( 'siteferry_job' );
		if ( ! is_array( $job ) || ! in_array( $job['status'] ?? '', array( 'pending', 'running' ), true ) ) {
			return;
		}
	} elseif ( preg_match( '#/siteferry/v1/remote/?$#', $route ) ) {
		// A request from another site: a valid HMAC signature over the body's JSON line (the one the protocol signs).
		$key  = (string) get_option( 'siteferry_connection_key' );
		$sent = (string) ( $_SERVER['HTTP_X_SITEFERRY_SIGNATURE'] ?? '' );
		$body = (string) file_get_contents( 'php://input' );
		$line = strstr( $body, "\n", true );
		if ( '' === $key || '' === $sent || ! hash_equals( hash_hmac( 'sha256', false === $line ? $body : $line, $key ), $sent ) ) {
			return;
		}
		// Whatever gets printed from here on (a PHP notice with display_errors on, say) would go out before the
		// signature header and void it: Siteferry drops this buffer before its signed response.
		ob_start();
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
	// No theme functions.php: WordPress only loads it if it exists in the theme directory.
	$nowhere = static fn() => WP_CONTENT_DIR . '/siteferry-no-theme';
	add_filter( 'template_directory', $nowhere );
	add_filter( 'stylesheet_directory', $nowhere );
} )();

<?php
/**
 * Runs when the plugin is deleted from WordPress: removes everything Siteferry stored.
 *
 * @package Siteferry
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/siteferry.php';

Siteferry\Plugin::uninstall();

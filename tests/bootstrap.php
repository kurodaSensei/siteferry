<?php
/**
 * Bootstrap de PHPUnit: el motor no depende de WordPress.
 *
 * @package Siteferry
 */

require dirname( __DIR__ ) . '/vendor/autoload.php';

// Igual que WordPress: los errores de mysqli se revisan a mano, no como excepciones.
mysqli_report( MYSQLI_REPORT_OFF );

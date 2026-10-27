<?php
/**
 * PHPUnit bootstrap: the engine does not depend on WordPress.
 *
 * @package Siteferry
 */

require dirname( __DIR__ ) . '/vendor/autoload.php';

// Same as WordPress: mysqli errors are checked by hand, not thrown as exceptions.
mysqli_report( MYSQLI_REPORT_OFF );

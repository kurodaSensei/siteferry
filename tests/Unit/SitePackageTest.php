<?php
/**
 * Pruebas del formato de paquete de sitio (.sfy).
 *
 * @package Siteferry
 */

namespace Siteferry\Tests\Unit;

use Siteferry\Files\SitePackage;
use PHPUnit\Framework\TestCase;

final class SitePackageTest extends TestCase {

	private string $file;

	protected function setUp(): void {
		$this->file = sys_get_temp_dir() . '/siteferry-site-' . bin2hex( random_bytes( 4 ) ) . '.sfy';
	}

	protected function tearDown(): void {
		if ( is_file( $this->file ) ) {
			unlink( $this->file );
		}
	}

	public function test_round_trip(): void {
		SitePackage::start( $this->file, array( 'home' => 'https://a.test', 'roots' => array( 'uploads' ) ) );
		SitePackage::append( $this->file, array( 'type' => 'db' ), "uno\ndos" );
		SitePackage::append( $this->file, array( 'type' => 'files', 'root' => 'uploads' ), '' );
		SitePackage::append( $this->file, array( 'type' => 'db' ), random_bytes( 1000 ) );

		$this->assertSame( 'https://a.test', SitePackage::meta( $this->file )['home'] );
		$types = array();
		for ( $offset = 0; $block = SitePackage::read( $this->file, $offset ); $offset = $block['next'] ) {
			$types[] = $block['frame']['type'] . ':' . strlen( $block['bytes'] );
		}
		$this->assertSame( array( 'db:7', 'files:0', 'db:1000' ), $types );
	}

	public function test_truncated_package_is_detected(): void {
		SitePackage::start( $this->file, array() );
		SitePackage::append( $this->file, array( 'type' => 'db' ), str_repeat( 'x', 100 ) );
		$handle = fopen( $this->file, 'r+b' );
		ftruncate( $handle, filesize( $this->file ) - 1 );
		fclose( $handle );
		$this->expectExceptionMessage( 'cortado' );
		SitePackage::read( $this->file, 0 );
	}

	public function test_rejects_other_files_and_unknown_roots(): void {
		file_put_contents( $this->file, "-- MySQL dump\n" );
		try {
			SitePackage::meta( $this->file );
			$this->fail( 'Un .sql renombrado no es un paquete.' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'no es un paquete', strtolower( $e->getMessage() ) );
		}
		file_put_contents( $this->file, "SFYSITE1 {}\n" . '{"type":"files","root":"../../etc","length":0}' . "\n" );
		$this->expectExceptionMessage( 'inválido' );
		SitePackage::read( $this->file, 0 );
	}
}

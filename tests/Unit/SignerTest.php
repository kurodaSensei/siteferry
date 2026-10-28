<?php
/**
 * Tests for the signature between sites.
 *
 * @package Siteferry
 */

namespace Siteferry\Tests\Unit;

use Siteferry\Remote\Signer;
use PHPUnit\Framework\TestCase;

final class SignerTest extends TestCase {

	private const KEY = 'clave0123456789clave0123456789clave01234';

	public function test_signature_covers_every_byte(): void {
		$body = Signer::message( 'pull_rows', array( 'table' => 'wp_posts' ) );
		$sig  = Signer::sign( $body, self::KEY );

		$this->assertTrue( Signer::valid( $body, $sig, self::KEY ) );
		$this->assertFalse( Signer::valid( $body . ' ', $sig, self::KEY ) );
		$this->assertFalse( Signer::valid( str_replace( 'wp_posts', 'wp_users', $body ), $sig, self::KEY ) );
		$this->assertFalse( Signer::valid( $body, $sig, self::KEY . 'x' ) );
		$this->assertFalse( Signer::valid( $body, '', self::KEY ) );
		$this->assertFalse( Signer::valid( $body, $sig, '' ) );
	}

	public function test_each_message_has_its_own_nonce_and_time(): void {
		$a = json_decode( Signer::message( 'hello', array() ), true );
		$b = json_decode( Signer::message( 'hello', array() ), true );
		$this->assertNotSame( $a['nonce'], $b['nonce'] );
		$this->assertEqualsWithDelta( time(), $a['ts'], 2 );
	}

	public function test_a_clock_rejection_tells_how_far_off_this_clock_is(): void {
		// The other site rejected the request for its time and sent its own clock: 10 minutes ahead.
		$offset = Signer::clock_offset(
			array(
				'ok'   => false,
				'code' => Signer::CLOCK_CODE,
				'time' => time() + 600,
			)
		);
		$this->assertEqualsWithDelta( 600, $offset, 2 );

		// The next message is stamped with the other site's time.
		$this->assertEqualsWithDelta( time() + 600, json_decode( Signer::message( 'hello', array(), null, $offset ), true )['ts'], 2 );

		// Any other answer leaves the clock alone.
		$this->assertNull( Signer::clock_offset( array( 'ok' => false, 'error' => 'Invalid signature.' ) ) );
		$this->assertNull( Signer::clock_offset( array( 'ok' => false, 'code' => Signer::CLOCK_CODE ) ) );
		$this->assertNull( Signer::clock_offset( array( 'ok' => true, 'code' => Signer::CLOCK_CODE, 'time' => time() + 600 ) ) );
	}

	public function test_parse_connection(): void {
		$this->assertSame(
			array(
				'url' => 'https://sitio.com',
				'key' => self::KEY,
			),
			Signer::parse_connection( "https://sitio.com/\n" . self::KEY )
		);
		$this->assertSame( 'https://sitio.com', Signer::parse_connection( 'https://sitio.com ' . self::KEY )['url'] );

		foreach ( array( 'https://sitio.com', 'sitio.com ' . self::KEY, 'https://sitio.com corta', 'https://a.com ' . self::KEY . ' extra', 'http://sitio.com ' . self::KEY, 'http://8.8.8.8 ' . self::KEY ) as $bad ) {
			try {
				Signer::parse_connection( $bad );
				$this->fail( 'Should have rejected: ' . $bad );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertMatchesRegularExpression( '/URL|https/', $e->getMessage() );
			}
		}
	}

	public function test_http_only_for_development_hosts(): void {
		foreach ( array( 'http://localhost', 'http://127.0.0.1:8090', 'http://example.test', 'http://siteferry-dev:8080', 'http://192.168.1.20', 'http://10.0.0.5', 'http://intranet.local', 'http://[::1]:8080' ) as $url ) {
			$this->assertSame( $url, Signer::parse_connection( $url . ' ' . self::KEY )['url'], $url );
		}
		$this->assertTrue( Signer::is_dev_host( 'mi-sitio.test' ) );
		$this->assertFalse( Signer::is_dev_host( 'produccion.com' ) );
		$this->assertFalse( Signer::is_dev_host( '203.0.113.9' ) );
	}
}

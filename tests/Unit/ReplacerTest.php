<?php
/**
 * Pruebas del Replacer.
 *
 * @package Siteferry
 */

namespace Siteferry\Tests\Unit;

use Siteferry\Engine\Replacer;
use PHPUnit\Framework\TestCase;

final class ReplacerTest extends TestCase {

	private const OLD = 'http://old.test';
	private const NEW = 'https://www.new-site.example';

	private function replacer( bool $case_sensitive = false ): Replacer {
		return new Replacer( array( array( self::OLD, self::NEW ) ), $case_sensitive );
	}

	public function test_plain_text_is_case_insensitive_by_default(): void {
		$this->assertSame( 'ver ' . self::NEW . '/a', $this->replacer()->value( 'ver HTTP://OLD.test/a' ) );
	}

	public function test_case_sensitive_mode(): void {
		$this->assertSame( 'HTTP://OLD.test/a', $this->replacer( true )->value( 'HTTP://OLD.test/a' ) );
		$this->assertSame( self::NEW . '/a', $this->replacer( true )->value( self::OLD . '/a' ) );
	}

	public function test_untouched_value_is_returned_identical(): void {
		$json = '{ "a" : "nada que ver" }';
		$this->assertSame( $json, $this->replacer()->value( $json ) );
		$this->assertSame( 'a:1:{i:0;d:0.1;}', $this->replacer()->value( 'a:1:{i:0;d:0.1;}' ) );
	}

	public function test_serialized_lengths_are_recalculated(): void {
		$value = serialize(
			array(
				'url'   => self::OLD . '/wp-content/uploads/a.jpg',
				'list'  => array( self::OLD, 'otro', 42, 1.5, true, null ),
				'emoji' => '🚀 ' . self::OLD,
			)
		);
		$out = $this->replacer()->value( $value );

		$this->assertSame(
			array(
				'url'   => self::NEW . '/wp-content/uploads/a.jpg',
				'list'  => array( self::NEW, 'otro', 42, 1.5, true, null ),
				'emoji' => '🚀 ' . self::NEW,
			),
			unserialize( $out )
		);
	}

	public function test_serialized_inside_serialized(): void {
		$value = serialize( array( 'inner' => serialize( array( 'u' => self::OLD ) ) ) );
		$outer = unserialize( $this->replacer()->value( $value ) );
		$this->assertSame( array( 'u' => self::NEW ), unserialize( $outer['inner'] ) );
	}

	public function test_objects_keep_class_and_visibility_without_being_instantiated(): void {
		$value = serialize( new FixtureWidget( self::OLD ) );
		$out   = $this->replacer()->value( $value );

		$this->assertStringContainsString( 'O:' . strlen( FixtureWidget::class ) . ':"' . FixtureWidget::class . '"', $out );
		$widget = unserialize( $out );
		$this->assertSame( array( self::NEW, self::NEW . '/p', self::NEW . '/q' ), $widget->urls() );
	}

	public function test_unknown_classes_are_fine(): void {
		$value = 'O:8:"NoExiste":1:{s:3:"url";s:15:"http://old.test";}';
		$out   = $this->replacer()->value( $value );
		$this->assertSame( 'O:8:"NoExiste":1:{s:3:"url";s:' . strlen( self::NEW ) . ':"' . self::NEW . '";}', $out );
	}

	public function test_references_custom_and_enum_tokens_are_kept(): void {
		$value = 'a:4:{i:0;s:15:"http://old.test";i:1;R:2;i:2;C:11:"ArrayObject":15:{http://old.test}i:3;E:7:"Foo:Bar";}';
		$out   = $this->replacer()->value( $value );
		$this->assertSame(
			'a:4:{i:0;s:' . strlen( self::NEW ) . ':"' . self::NEW . '";i:1;R:2;i:2;C:11:"ArrayObject":15:{http://old.test}i:3;E:7:"Foo:Bar";}',
			$out
		);
	}

	public function test_array_keys_are_not_replaced(): void {
		$value = serialize( array( self::OLD => self::OLD ) );
		$this->assertSame( array( self::OLD => self::NEW ), unserialize( $this->replacer()->value( $value ) ) );
	}

	public function test_broken_serialized_is_treated_as_text(): void {
		$broken = 's:99:"http://old.test";';
		$this->assertSame( 's:99:"' . self::NEW . '";', $this->replacer()->value( $broken ) );
	}

	public function test_json_keeps_its_style(): void {
		$this->assertSame(
			'{"a":"https:\/\/www.new-site.example\/x","e":{},"l":[],"n":1.0}',
			$this->replacer()->value( '{"a":"http:\/\/old.test\/x","e":{},"l":[],"n":1.0}' )
		);
		$this->assertSame(
			'["https://www.new-site.example","ñ"]',
			$this->replacer()->value( '["http://old.test","ñ"]' )
		);
	}

	public function test_json_escaped_urls_inside_text(): void {
		$block = '<!-- wp:image {"url":"http:\/\/old.test\/a.jpg"} --><img src="http://old.test/a.jpg"/>';
		$this->assertSame(
			'<!-- wp:image {"url":"https:\/\/www.new-site.example\/a.jpg"} --><img src="https://www.new-site.example/a.jpg"/>',
			$this->replacer()->value( $block )
		);
	}

	public function test_json_with_unicode_escapes_is_decoded(): void {
		$r = new Replacer( array( array( 'café', 'té' ) ) );
		$this->assertSame( '{"a":"té"}', $r->value( '{"a":"café"}' ) );
	}

	public function test_regex_mode(): void {
		$r = new Replacer( array( array( '#http://(old)\.test#', 'https://$1.example' ) ), false, true );
		$this->assertSame( serialize( 'x https://old.example' ), $r->value( serialize( 'x http://old.test' ) ) );

		$invalid = new Replacer( array( array( '#(#', 'x' ) ), false, true );
		$this->assertSame( 'sin cambios', @$invalid->value( 'sin cambios' ) );
	}

	public function test_empty_pairs(): void {
		$r = new Replacer( array( array( '', 'x' ) ) );
		$this->assertTrue( $r->is_empty() );
		$this->assertSame( 'abc', $r->value( 'abc' ) );
	}
}

/**
 * Objeto con propiedades de las tres visibilidades.
 */
final class FixtureWidget {
	public string $a;
	protected string $b;
	private string $c;

	public function __construct( string $base ) {
		$this->a = $base;
		$this->b = $base . '/p';
		$this->c = $base . '/q';
	}

	/** @return list<string> */
	public function urls(): array {
		return array( $this->a, $this->b, $this->c );
	}
}

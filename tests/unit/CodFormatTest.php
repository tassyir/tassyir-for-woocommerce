<?php
/**
 * COD form pure helpers: wilaya codes, phones, money and the rate-limit window; the three text sets.
 *
 * @package Tassyir\WooCommerce
 */

namespace Tassyir\WooCommerce\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tassyir\WooCommerce\Cod\Format;
use Tassyir\WooCommerce\Cod\Texts;

final class CodFormatTest extends TestCase {

	public function test_state_code(): void {
		$this->assertSame( 'DZ-16', Format::state_code( 16 ) );
		$this->assertSame( 'DZ-09', Format::state_code( 9 ) );
		$this->assertSame( 'DZ-58', Format::state_code( 58 ) );
	}

	public function test_mobile_numbers(): void {
		$this->assertSame( '0555123456', Format::mobile( '05 55 12 34 56' ) );
		$this->assertSame( '0661234501', Format::mobile( '+213 661-23-45-01' ) );
		$this->assertSame( '0770123402', Format::mobile( '00213770123402' ) );
		$this->assertSame( '0699112204', Format::mobile( '٠٦٩٩١١٢٢٠٤' ) );
		$this->assertSame( '0550987603', Format::mobile( '۰۵۵۰۹۸۷۶۰۳' ) );
		$this->assertSame( '', Format::mobile( '0455123456' ), 'landline' );
		$this->assertSame( '', Format::mobile( '055512345' ), 'too short' );
		$this->assertSame( '', Format::mobile( '' ) );
	}

	public function test_money(): void {
		$this->assertSame( 250000, Format::cents( '2500' ) );
		$this->assertSame( 129950, Format::cents( 1299.5 ) );
		$this->assertSame( '4800', Format::amount( 4800 ) );
		$this->assertSame( '4800.5', Format::amount( '4800.50' ) );
		$this->assertSame( '0', Format::amount( 0 ) );
	}

	public function test_rate_limit_window(): void {
		$state = null;
		for ( $i = 1; $i <= 3; $i++ ) {
			list( $allowed, $state ) = Format::hit( $state, 1000 + $i, 3, 600 );
			$this->assertTrue( $allowed, "hit $i" );
		}
		list( $allowed, $state ) = Format::hit( $state, 1100, 3, 600 );
		$this->assertFalse( $allowed, 'fourth hit in the window' );
		$this->assertSame( array( 3, 1001 ), $state );

		list( $allowed, $state ) = Format::hit( $state, 1601, 3, 600 );
		$this->assertTrue( $allowed, 'a new window' );
		$this->assertSame( array( 1, 1601 ), $state );

		list( $allowed ) = Format::hit( 'garbage', 50, 3, 600 );
		$this->assertTrue( $allowed );
	}

	public function test_the_three_text_sets_match(): void {
		$ar = Texts::defaults( 'ar' );
		foreach ( array( 'fr', 'en' ) as $lang ) {
			$texts = Texts::defaults( $lang );
			$this->assertSame( array_keys( $ar ), array_keys( $texts ), $lang );
			foreach ( $ar as $key => $value ) {
				preg_match_all( '/\{\w+\}/', $value, $want );
				preg_match_all( '/\{\w+\}/', $texts[ $key ], $got );
				$this->assertSame( $want[0], $got[0], "$lang $key placeholders" );
				$this->assertNotSame( '', $texts[ $key ], "$lang $key" );
			}
		}
		foreach ( Texts::EDITABLE as $key ) {
			$this->assertArrayHasKey( $key, $ar );
		}
		$this->assertSame( 'Have a discount code?', Texts::script( 'en' )['code_toggle'] );
		$this->assertArrayNotHasKey( 'heading', Texts::script( 'en' ) );
		$this->assertSame( $ar, Texts::defaults( 'xx' ), 'unknown language → Arabic' );
	}
}

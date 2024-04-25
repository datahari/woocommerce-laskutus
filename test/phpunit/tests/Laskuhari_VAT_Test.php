<?php
/**
 * PHPUnit tests for VAT calculations
 */

class Laskuhari_VAT_Test extends \PHPUnit\Framework\TestCase
{
    protected function setUp(): void {
        add_filter( "laskuhari_common_vat_rates", function( $vat_rates ) {
            return array_merge( $vat_rates, [26.59] ); // You never know...
        } );
    }

    /**
     * Test that the VAT is calculated correctly
     *
     * @return void
     */
    public function test_it_calculates_vat_correctly() {
        for( $price = 1; $price < 1000; $price += 0.01 ) {
            $with_tax = round( $price * 1.24, 2 );
            $this->assertEquals( 24, laskuhari_vat_percent( ($with_tax / $price - 1) * 100 ) );

            $with_tax = round( $price * 1.255, 2 );
            $this->assertEquals( 25.5, laskuhari_vat_percent( ($with_tax / $price - 1) * 100 ) );

            // You never know...
            $with_tax = round( $price * 1.2659, 2 );
            $this->assertEquals( 26.59, laskuhari_vat_percent( ($with_tax / $price - 1) * 100 ) );
        }
    }

    /**
     * Test that the VAT is calculated correctly with small numbers
     *
     * @return void
     */
    public function test_it_calculates_vat_correctly_with_small_numbers() {
        for( $price = 0.01; $price < 1; $price += 0.01 ) {
            $with_tax = round( $price * 1.24, 4 );
            $this->assertEquals( 24, laskuhari_vat_percent( ($with_tax / $price - 1) * 100 ) );

            $with_tax = round( $price * 1.255, 4 );
            $this->assertEquals( 25.5, laskuhari_vat_percent( ($with_tax / $price - 1) * 100 ) );

            // You never know...
            $with_tax = round( $price * 1.2659, 4 );
            $this->assertEquals( 26.59, laskuhari_vat_percent( ($with_tax / $price - 1) * 100 ) );
        }
    }
}

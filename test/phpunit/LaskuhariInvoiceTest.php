<?php
require_once( __DIR__ . "/../wordpress-bootstrap.php" );
require_once( __DIR__ . "/../woocommerce-bootstrap.php" );
require_once( __DIR__ . "/../../class-laskuhari-invoice.php" );

use PHPUnit\Framework\TestCase;

final class LaskuhariInvoiceTest extends TestCase
{
	function test_it_creates_payload_correctly() {
		$_SERVER['HTTP_HOST'] = "example.com";
		update_post_meta( LASKUHARI_WC_TEST_ORDER_ID, "_laskuhari_payment_terms", LASKUHARI_WC_TEST_DEFAULT_PAYMENT_TERM );

		$wc_order = new WC_Order();
		$invoice = new Laskuhari_Invoice( $wc_order );
		$invoice->set_buyer_reference( "test reference" );

		$payload = $invoice->create_payload();

		$this->assertEquals( "wc", $payload["ref"] );
		$this->assertEquals( "example.com", $payload["site"] );
		$this->assertEquals( 0, $payload["tyyppi"] );
		$this->assertEquals( false, $payload["laskunro"] );
		$this->assertEquals( date( "d.m.Y" ), $payload["pvm"] );
		$this->assertEquals( "test reference", $payload["viitteenne"] );
		$this->assertEquals( "", $payload["viitteemme"] );
		$this->assertEquals( LASKUHARI_WC_TEST_DEFAULT_PAYMENT_TERM, $payload["maksuehto"]["id"] );

		$this->assertEquals( [
			"yritys" => "",
			"ytunnus" => "",
			"henkilo" => "John Doe",
			"lahiosoite" => [
				"Testroad",
				"",
			],
			"postinumero" => "123456",
			"postitoimipaikka" => "Test city",
			"email" => "test@example.com",
			"puhelin" => "040 1234 567",
			"asiakasnro" => LASKUHARI_WC_TEST_CUSTOMER_NUMBER,
		], $payload["laskutusosoite"] );

		$this->assertEquals( [
			"wc_order_id" => LASKUHARI_WC_TEST_ORDER_ID,
			"wc_user_id" => LASKUHARI_WC_TEST_CUSTOMER_NUMBER,
		], $payload['woocommerce'] );

		$this->assertEquals( true, ! empty( $payload['wc_api_version'] ) );
	}
}

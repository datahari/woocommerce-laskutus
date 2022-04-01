<?php
define( "LASKUHARI_WC_TEST_ORDER_ID", 55 );
define( "LASKUHARI_WC_TEST_CUSTOMER_NUMBER", 67 );
define( "LASKUHARI_WC_TEST_DEFAULT_PAYMENT_TERM", 5543 );

class WC_Order
{
	public function get_address() {
		return [
			"first_name" => "John",
			"last_name" => "Doe",
			"address_1" => "Testroad",
			"address_2" => "",
			"company" => "",
			"postcode" => "123456",
			"city" => "Test city",
			"email" => "test@example.com",
			"phone" => "040 1234 567",
		];
	}

	public function get_order_number() {
		return "1234";
	}

	public function get_id() {
		return LASKUHARI_WC_TEST_ORDER_ID;
	}

	public function get_user_id() {
		return LASKUHARI_WC_TEST_CUSTOMER_NUMBER;
	}

	public function get_customer_id() {
		return $this->get_user_id();
	}
}

$test_is_checkout = false;
function is_checkout() {
	global $test_is_checkout;
	return $test_is_checkout;
}

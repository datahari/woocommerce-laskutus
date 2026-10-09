<?php
/**
 * PHPUnit tests for Laskuhari_API class (new webhooks)
 */

class Laskuhari_API_Test extends \PHPUnit\Framework\TestCase
{
    /**
     * Test that the Laskuhari API returns an error when called without proper authentication
     *
     * @return void
     */
    public function test_it_returns_an_error_when_called_without_proper_authentication() {
        $response = $this->send_api_request( "", "", null );

        $this->assertEquals( ["status" => "ERROR", "message" => "Unauthorized"], $response['body'] );
        $this->assertEquals( 401, $response['code'] );
    }

    /**
     * Test that the Laskuhari API returns an error when called with wrong signature
     *
     * @return void
     */
    public function test_it_returns_an_error_when_called_with_wrong_signature() {
        $secret = 'wrong_signature_qwertyuiopasdfghjklzxcvbnmqwertyuiopasdfghjklzxcvbnm';

        $response = $this->send_api_request( "", $secret );

        $this->assertEquals( ["status" => "ERROR", "message" => "Unauthorized"], $response['body'] );
        $this->assertEquals( 401, $response['code'] );
    }

    /**
     * Test that the Laskuhari API returns an error when called with an old timestamp
     *
     * @return void
     */
    public function test_it_returns_an_error_when_called_with_an_old_timestamp() {
        $headers = [
            'X-Webhook-Timestamp' => '1234567890',
        ];

        $response = $this->send_authenticated_api_request( "", $headers );

        $this->assertEquals( ["status" => "ERROR", "message" => "Blocked possible duplicate request"], $response['body'] );
        $this->assertEquals( 401, $response['code'] );
    }

    /**
     * Test that the Laskuhari API returns an error when called with a too large request
     *
     * @return void
     */
    public function test_it_returns_an_error_when_called_with_too_large_of_a_request() {
        $request_of_3000_bytes = str_repeat( 'a', 3000 );

        $response = $this->send_authenticated_api_request( $request_of_3000_bytes );

        $this->assertEquals( ["status" => "ERROR", "message" => "Request size limit exceeded"], $response['body'] );
        $this->assertEquals( 413, $response['code'] );
    }

    /**
     * Test that the Laskuhari API returns a notice when trying to update non-existent invoice
     *
     * @return void
     */
    public function test_it_returns_a_notice_when_trying_to_update_non_existent_invoice() {
        $request = json_encode( [
            "id" => "0b45d4ed-035c-4430-b47a-79a82b3777c4",
            "event" => "payment_status",
            "version" => "1.0",
            "occured_at" => "2026-08-22T17:18:43+03:00",
            "data" => [
                "invoice" => [
                    "id" => "d4a20f76-8b8b-4aa9-9ded-cd259fa6fd35",
                    "number" => "12345",
                    "status" => [
                        "id" => "12f91714-2867-4414-b6b2-532fd997c201",
                        "name" => "Maksettu",
                        "type" => "paid",
                        "status_in_reports" => "paid",
                    ],
                    "is_paid" => true,
                    "payment_date" => "2026-08-22",
                    "payment_method" => "invoice",
                    "reference_number" => "12303216",
                    "external" => [
                        "system" => "woocommerce",
                        "order_id" => $this->get_config()['laskuhari_api']['wc_order_id'],
                    ],
                ],
            ],
        ] );

        if( ! is_string( $request ) ) {
            throw new \Exception( "Error in JSON encode" );
        }

        $response = $this->send_authenticated_api_request( $request );

        $this->assertEquals( ["status" => "OK", "message" => "Invoice number not found here"], $response['body'] );
        $this->assertEquals( 200, $response['code'] );
    }

    /**
     * Test that the Laskuhari API returns a success message when successfully updated a payment status
     *
     * @return void
     */
    public function test_it_returns_a_success_message_when_successfully_updated_payment_status() {
        $request = json_encode( [
            "id" => "0b45d4ed-035c-4430-b47a-79a82b3777c4",
            "event" => "payment_status",
            "version" => "1.0",
            "occured_at" => "2026-08-22T17:18:43+03:00",
            "data" => [
                "invoice" => [
                    "id" => "8592c648-951b-4ab0-92c4-9ce27f59146a",
                    "number" => (string) $this->get_config()['laskuhari_api']['invoice_number'],
                    "status" => [
                        "id" => "12f91714-2867-4414-b6b2-532fd997c201",
                        "name" => "Maksettu",
                        "type" => "paid",
                        "status_in_reports" => "paid",
                    ],
                    "is_paid" => true,
                    "payment_date" => "2026-08-22",
                    "payment_method" => "invoice",
                    "reference_number" => "12303216",
                    "external" => [
                        "system" => "woocommerce",
                        "order_id" => $this->get_config()['laskuhari_api']['wc_order_id'],
                    ],
                ],
            ],
        ]);

        if( ! is_string( $request ) ) {
            throw new \Exception( "Error in JSON encode" );
        }

        $response = $this->send_authenticated_api_request( $request );

        $this->assertEquals( ["status" => "OK", "message" => "Payment status updated"], $response['body'] );
        $this->assertEquals( 200, $response['code'] );
    }

    /**
     * Test that the Laskuhari API returns an error when calling an unknown event
     *
     * @return void
     */
    public function test_it_returns_an_error_when_calling_an_unknown_event() {
        $request = json_encode([
            "event" => "unknown_event",
        ]);

        if( ! is_string( $request ) ) {
            throw new \Exception( "Error in JSON encode" );
        }

        $response = $this->send_authenticated_api_request( $request );

        $this->assertEquals( ["status" => "ERROR", "message" => "Unknown event"], $response['body'] );
        $this->assertEquals( 400, $response['code'] );
    }

    /**
     * Helper function to get global config array
     *
     * @return array<string, array<string, int|string>>
     */
    private function get_config(): array {
        return require( __DIR__ . "/../config.php" );
    }

    /**
     * Helper function to send properly authenticated API request
     *
     * @param string $request
     * @param array<string, string> $headers
     * @return array<string, mixed>
     */
    private function send_authenticated_api_request( $request, $headers = [] ) {
        $webhook_secret = (string)$this->get_config()['laskuhari_api']['webhook_secret'];

        return $this->send_api_request( $request, $webhook_secret, $headers );
    }

    /**
     * Helper function for generating an API request
     *
     * @param string $request
     * @param string $webhook_secret
     * @param array<string, string> $headers
     * @return array<string, mixed>
     */
    private function send_api_request( $request, $webhook_secret, $headers = [] ) {
        $api_url = (string)$this->get_config()['laskuhari_api']['url'];

        if( ! isset( $headers['X-Webhook-Timestamp'] ) ) {
            $headers['X-Webhook-Timestamp'] = time();
        }

        if( ! isset( $headers['X-Webhook-Id'] ) ) {
            $headers['X-Webhook-Id'] = "4c151f30-ccc1-439a-95c4-4456890af09a";
        }

        if( ! isset( $headers['X-Webhook-Signature'] ) && $webhook_secret ) {
            $headers['X-Webhook-Signature'] = hash_hmac( "sha256", $request, $webhook_secret );
        }

        // build the request arguments
        $args = array(
            'headers' => $headers,
            'timeout' => 20,
            'body' => $request,
            'sslverify' => false,
        );

        // send the POST request
        $response = wp_remote_post( $api_url, $args );

        // Check for errors
        if ( is_wp_error( $response ) ) {
            // Throw an exception if there are errors
            /** @var WP_Error $response */
            throw new Exception( 'Error: ' . $response->get_error_message() );
        }

        // extract the data from the response
        return [
            "body" => json_decode( wp_remote_retrieve_body( $response ), true ),
            "code" => wp_remote_retrieve_response_code( $response ),
        ];
    }
}

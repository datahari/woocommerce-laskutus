<?php
/**
 * PHPUnit tests for Laskuhari_API class
 */

class Laskuhari_API_Test extends \PHPUnit\Framework\TestCase
{
    /**
     * Test that the Laskuhari API returns an error when called without proper authentication
     *
     * @return void
     */
    public function test_it_returns_an_error_when_called_without_proper_authentication() {
        $response = $this->send_api_request( "", null, null );

        $this->assertEquals( ["status" => "ERROR", "message" => "Unauthorized"], $response['body'] );
        $this->assertEquals( 401, $response['code'] );
    }

    /**
     * Test that the Laskuhari API returns an error when called with wrong API key
     *
     * @return void
     */
    public function test_it_returns_an_error_when_called_with_wrong_api_key() {
        $apikey = 'wrong_api_key_qwertyuiopasdfghjklzxcvbnmqwertyuiopasdfghjklzxcvbnm';
        $uid = 123;

        $response = $this->send_api_request( "", $uid, $apikey );

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
            'X-Timestamp' => '1234567890',
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
        $request = json_encode([
            "event" => "payment_status",
            "status" => [
                "code" => 1,
                "name" => "Maksettu",
                "id" => 1234,
            ],
            "invoice_id" => 123456789,
            "wc_order_id" => 4321,
        ]);

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
        $request = json_encode([
            "event" => "payment_status",
            "status" => [
                "code" => 1,
                "name" => "Maksettu",
                "id" => 1234,
            ],
            "invoice_id" => $this->get_config()['laskuhari_api']['invoice_id'],
            "invoice_number" => $this->get_config()['laskuhari_api']['invoice_number'],
            "wc_order_id" => $this->get_config()['laskuhari_api']['wc_order_id'],
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
    private function get_config() {
        global $__laskuhari_test_config;
        return $__laskuhari_test_config;
    }

    /**
     * Helper function to send properly authenticated API request
     *
     * @param string $request
     * @param array<string, string> $headers
     * @return array<string, mixed>
     */
    private function send_authenticated_api_request( $request, $headers = [] ) {
        $uid = (int)$this->get_config()['laskuhari_api']['uid'];
        $apikey = (string)$this->get_config()['laskuhari_api']['apikey'];

        return $this->send_api_request( $request, $uid, $apikey, $headers );
    }

    /**
     * Helper function for generating an API request
     *
     * @param string $request
     * @param int|null $uid
     * @param string|null $apikey
     * @param array<string, string> $headers
     * @return array<string, mixed>
     */
    private function send_api_request( $request, $uid, $apikey, $headers = [] ) {
        // get the API url from the config array
        $api_url = (string)$this->get_config()['laskuhari_api']['url'];

        // add x-timestamp header if not set
        if( ! isset( $headers['X-Timestamp'] ) ) {
            $headers['X-Timestamp'] = time();
        }

        // add x-auth-key header if uid and apikey are set
        if( $uid && $apikey ) {
            $headers['X-Auth-Key'] = $this->generate_auth_key( $uid, $apikey, $request );
        }

        // build the request arguments
        $args = array(
            'headers' => $headers,
            'timeout' => 20,
            'body' => $request,
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

    /**
     * Helper function for generating auth key
     *
     * @param int $uid
     * @param string $apikey
     * @param string $request
     * @return string
     */
    protected function generate_auth_key( $uid, $apikey, $request ) {
        return hash( "sha256", implode( "+", [
            $uid,
            $apikey,
            $request
        ]) );
    }
}

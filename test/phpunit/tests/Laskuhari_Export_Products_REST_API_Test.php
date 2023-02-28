<?php
class Laskuhari_Export_Products_REST_API_Test extends \PHPUnit\Framework\TestCase
{
    /**
     * Test that the /products/laskuhari-export endpoint returns 10 products by default
     *
     * @return void
     */
    public function test_api_reponse_without_parameters()
    {
        // send request to API without parameters
        $response = $this->send_authenticated_api_request( "/wp-json/wc/v3/products/laskuhari-export" );

        // assert that it returns 10 products
        $this->assertTrue( is_array( $response ) );
        $this->assertCount( 10, $response );
    }

    /**
     * Test that the /products/laskuhari-export endpoint returns requested
     * number of products and the requested page
     *
     * @return void
     */
    public function test_api_reponse_with_parameters()
    {
        // send request to API with parameters asking for 14 products per page
        $response = $this->send_authenticated_api_request( "/wp-json/wc/v3/products/laskuhari-export?per_page=14&page=1" );

        // assert that it returns 14 products
        $this->assertTrue( is_array( $response ) );
        $this->assertCount( 14, $response );

        $last_product = end( $response );

        // get page 2 of a 13-per-page request
        $response = $this->send_authenticated_api_request( "/wp-json/wc/v3/products/laskuhari-export?per_page=13&page=2" );

        // assert that it returns 13 products
        $this->assertTrue( is_array( $response ) );
        $this->assertCount( 13, $response );

        // assert that the first product of the second page of 13-per-page request
        // is the same as the last product of the 14-per-page request
        $this->assertEquals( current( $response ), $last_product );
    }

    /**
     * Test that the /products/laskuhari-export endpoint returns attributes for variations
     *
     * @return void
     */
    public function test_api_returns_attributes_for_variations()
    {
        // send request to fetch 50 products
        /** @var array<array<string, mixed>> $response */
        $response = $this->send_authenticated_api_request( "/wp-json/wc/v3/products/laskuhari-export?per_page=50" );

        $variations_found = 0;

        // check that product variations have attributes
        foreach( $response as $product ) {
            if( $product['type'] === "variation" ) {
                /** @var array<string, array<string, array<string, mixed>>> $product */
                $variations_found++;

                foreach( $product['attributes'] as $attribute ) {
                    // assert that the product attributes have the keys "name" and "option"
                    $this->assertArrayHasKey( 'name', $attribute );
                    $this->assertArrayHasKey( 'option', $attribute );
    
                    // assert that the values of "name" and "option" are not empty
                    $this->assertNotEmpty( $attribute['name'] );
                    $this->assertNotEmpty( $attribute['option'] );
                }
            }
        }

        // make sure there were some variations
        $this->assertTrue( $variations_found > 0 );
    }

    /**
     * Test that the /products/laskuhari-test endpoint returns the correct response
     *
     * @return void
     */
    public function test_api_response_from_test_endpoint()
    {
        // send request to /products/laskuhari-test endpoint
        $response = $this->send_authenticated_api_request( "/wp-json/wc/v3/products/laskuhari-test" );

        // assert that it returns the correct response
        $this->assertEquals( [["response" => "laskuhari-active"]], $response );
    }

    /**
     * Test that the /products/laskuhari-count endpoint returns the correct response
     *
     * @return void
     */
    public function test_api_response_from_count_endpoint()
    {
        // send request to /products/laskuhari-count endpoint
        /** @var array<array<string, mixed>> $response */
        $response = $this->send_authenticated_api_request( "/wp-json/wc/v3/products/laskuhari-count" );

        // assert that it returns an array of an array with key "count"
        $this->assertEquals( "count", key( $response[0] ) );

        // assert that it returns an array of an array with a numeric value
        $this->assertTrue( is_numeric( current( $response[0] ) ) );

        // assert that it returns an array of an array with a non-zero value
        $this->assertTrue( current( $response[0] ) > 0 );
    }

    /**
     * Test that the API does not allow unauthenticated access
     *
     * @return void
     */
    public function test_api_doesnt_allow_unauthenticated_access()
    {
        // send request to /products/laskuhari-test endpoint
        $response = $this->send_unauthenticated_api_request( "/wp-json/wc/v3/products/laskuhari-test" );

        // expect a rest_forbidden code
        $this->assertEquals( "rest_forbidden", $response['code'] );
    }

    /**
     * Test that the API does not allow access with wrong key and secret
     *
     * @return void
     */
    public function test_api_doesnt_allow_wrongly_authenticated_access()
    {
        // send request to /products/laskuhari-test endpoint
        $response = $this->send_wrongly_authenticated_api_request( "/wp-json/wc/v3/products/laskuhari-test" );

        // expect a rest_forbidden code
        $this->assertEquals( "rest_forbidden", $response['code'] );
    }

    /**
     * Helper function for generating an API request
     *
     * @param string $api_endpoint
     * @param array<string, array<string, mixed>> $config
     * @param array<string, string> $headers
     * @return array<string, mixed>
     */
    private function send_api_request( $api_endpoint, $config, $headers ) {
        // get the API url from the config array
        $api_url = $config['wc_api']['url'];

        // build the request arguments
        $args = array(
            'headers' => $headers,
            'timeout' => 20,
        );

        // send the GET request
        $response = wp_remote_get( $api_url.$api_endpoint, $args );

        // Check for errors
        if ( is_wp_error( $response ) ) {
            // Throw an exception if there are errors
            /** @var WP_Error $response */
            throw new Exception( 'Error: ' . $response->get_error_message() );
        }

        // extract the data from the response
        $response = json_decode( wp_remote_retrieve_body( $response ), true );

        if( ! is_array( $response ) ) {
            throw new \Exception( "Error in JSON decode" );
        }

        return $response;
    }

    /**
     * Helper function for sending authenticated API request
     *
     * @param string $api_endpoint
     * @return array<string, mixed>
     */
    private function send_authenticated_api_request( $api_endpoint ) {
        global $__laskuhari_test_config;

        // get the API access keys from the global config array
        $consumer_key = $__laskuhari_test_config['wc_api']['consumer_key'];
        $consumer_secret = $__laskuhari_test_config['wc_api']['consumer_secret'];

        // set authorization header
        $headers = ['Authorization' => 'Basic ' . base64_encode( $consumer_key . ':' . $consumer_secret )];

        return $this->send_api_request( $api_endpoint, $__laskuhari_test_config, $headers );
    }

    /**
     * Helper function for sending unauthenticated API request
     *
     * @param string $api_endpoint
     * @return array<string, mixed>
     */
    private function send_unauthenticated_api_request( $api_endpoint ) {
        global $__laskuhari_test_config;

        // don't send headers
        $headers = [];

        return $this->send_api_request( $api_endpoint, $__laskuhari_test_config, $headers );
    }

    /**
     * Helper function for sending wrongly authenticated API request
     *
     * @param string $api_endpoint
     * @return array<string, mixed>
     */
    private function send_wrongly_authenticated_api_request( $api_endpoint ) {
        global $__laskuhari_test_config;

        // send wrong key and secret
        $consumer_key = "ck_thisisthewronkeyqwertyuiopasdfghjklzxcvb";
        $consumer_secret = "cs_thisisthewrongsecretqwertyuioasdfghjklzx";

        // set authorization header
        $headers = ['Authorization' => 'Basic ' . base64_encode( $consumer_key . ':' . $consumer_secret )];

        return $this->send_api_request( $api_endpoint, $__laskuhari_test_config, $headers );
    }
}

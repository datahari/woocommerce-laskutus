<?php
/**
 * Laskuhari API
 *
 * This class is used to handle incoming Laskuhari API requests
 * for updating invoice statuses in WooCommerce (and possibly other things in the future)
 *
 * @class Laskuhari_API
 *
 */

defined( 'ABSPATH' ) || exit;

class Laskuhari_API
{
    /**
     * Instance of the class
     *
     * @var Laskuhari_API
     */
    protected static $instance;

    /**
     * The request JSON as an array
     *
     * @var array
     */
    protected $request_json;

    /**
     * The request as a raw string
     *
     * @var string
     */
    protected $request;

    /**
     * WC_Gateway_Laskuhari object
     *
     * @var WC_Gateway_Laskuhari
     */
    protected $gateway_object;

    /**
     * Array of endpoints and their callback functions
     *
     * @param array $gateway_object
     */
    protected $endpoints = [
        "payment_status" => "handle_payment_status_request",
    ];

    /**
     * Initialize the API statically
     *
     * @param WC_Gateway_Laskuhari $gateway_object
     */
    public static function init( $gateway_object ) {
        if( ! isset( static::$instance ) ) {
            static::$instance = new static( $gateway_object );
        }

        return static::$instance;
    }

    /**
     * Initialize the class and set its properties.
     *
     * @param WC_Gateway_Laskuhari $gateway_object
     */
    private function __construct( $gateway_object ) {
        $this->gateway_object = $gateway_object;
        $this->handle_request();
    }

    /**
     * Generate auth key
     *
     * @param int $uid
     * @param string $apikey
     * @param string $request
     * @return string
     */
    public static function generate_auth_key( $uid, $apikey, $request ) {
        return hash( "sha256", implode( "+", [
            $uid,
            $apikey,
            $request
        ]) );
    }

    /**
     * Set response headers
     *
     * @return void
     */
    protected function set_response_headers() {
        header( "Content-Type: application/json; charset=utf-8" );
    }

    /**
     * Check that the request is not too large
     *
     * @return void
     */
    protected function check_content_max_length() {
        $content_max_length = apply_filters( "laskuhari_api_content_max_length", 2560 );

        // dont parse large requests
        if( $_SERVER['CONTENT_LENGTH'] > $content_max_length ) {
            http_response_code( 413 );

            echo json_encode([
                "status"  => "ERROR",
                "message" => "Request size limit exceeded"
            ]);
            exit;
        }
    }

    /**
     * Read the request in JSON format
     *
     * @return void
     */
    protected function read_request() {
        $this->request = @file_get_contents( "php://input" );
        $this->request_json = json_decode( $this->request, true );
    }

    /**
     * Check that the api key in the plugin settings
     * long enough to be a valid key
     *
     * @return void
     */
    protected function check_api_key_length() {
        // check that apikey is inserted into plugin settings
        if( strlen( $this->gateway_object->apikey ) < 64 ) {
            http_response_code( 500 );

            echo json_encode([
                "status"  => "ERROR",
                "message" => "Api key not found"
            ]);
            exit;
        }
    }

    /**
     * Get all headers and make them lowercase
     *
     * @return void
     */
    protected function get_headers() {
        if( isset( $this->headers ) ) {
            return $this->headers;
        }

        $headers = getallheaders();

        // make headers lowercase for case-insensitivity
        foreach( $headers as $key => $value ) {
            $headers[strtolower($key)] = $value;
        }

        $this->headers = $headers;

        return $this->headers;
    }

    /**
     * Get a single header
     *
     * @param string $header_name
     * @return string|null
     */
    protected function get_header( $header_name ) {
        $headers = $this->get_headers();
        return isset( $headers[$header_name] ) ? $headers[$header_name] : null;
    }

    /**
     * Check the auth key
     *
     * @return void
     */
    protected function check_auth_key() {
        $hash = static::generate_auth_key( $this->gateway_object->uid, $this->gateway_object->apikey, $this->request );
        $auth_key = $this->get_header( 'x-auth-key' );

        if( is_null( $auth_key ) || ! hash_equals( $hash, $auth_key ) ) {
            do_action( "laskuhari_unauthorized_api_request" );

            http_response_code( 401 );

            echo json_encode([
                "status"  => "ERROR",
                "message" => "Unauthorized"
            ]);
            exit;
        }
    }

    /**
     * Check the timestamp of the request to prevent duplicate requests
     *
     * @return void
     */
    protected function check_timestamp() {
        // check that timestamps are in sync at least 30 seconds
        $timestamp = $this->get_header( 'x-timestamp' );

        if( ! isset( $timestamp ) || abs( $timestamp - time() ) > 30 ) {
            do_action( "laskuhari_api_request_invalid_timestamp" );

            $this->response_error( "Blocked possible duplicate request", 401 );
        }
    }

    /**
     * Set response headers and check the request for errors
     *
     * @return void
     */
    protected function check_request() {
        $this->set_response_headers();
        $this->check_content_max_length();
        $this->read_request();
        $this->check_api_key_length();
        $this->check_auth_key();
        $this->check_timestamp();
    }

    /**
     * Check that the request is a Laskuhari API request
     *
     * @return void
     */
    protected function api_is_called() {
        return isset( $_GET['__laskuhari_api'] ) && (string) $_GET['__laskuhari_api'] === "true";
    }

    /**
     * Handle the current request
     *
     * @return void
     */
    public function handle_request() {
        // check if Laskuhari API is being called
        if( ! $this->api_is_called() ) {
            return false;
        }

        do_action( "laskuhari_api_request_received" );

        $this->check_request();

        do_action( "laskuhari_webhook", $this->request );

        if( isset( $this->endpoints[$this->request_json['event']] ) ) {
            $this->{$this->endpoints[$this->request_json['event']]}();
            exit;
        }

        http_response_code( 400 );

        echo json_encode([
            "status"  => "ERROR",
            "message" => "Unknown event"
        ]);
        exit;

    }

    /**
     * Respond with a 200 OK
     *
     * @param string $message
     * @return void
     */
    protected function response_ok( $message ) {
        http_response_code( 200 );

        echo json_encode([
            "status"  => "OK",
            "message" => $message
        ]);
        exit;
    }

    /**
     * Respond with an error
     *
     * @param string $message
     * @param integer $code
     * @return void
     */
    protected function response_error( $message, $code = 400 ) {
        http_response_code( $code );

        echo json_encode([
            "status"  => "ERROR",
            "message" => $message
        ]);
        exit;
    }

    /**
     * Handle request to update payment status of an invoice
     *
     * @return void
     */
    protected function handle_payment_status_request() {
        $status = $this->request_json['status'];

        if( isset( $this->request_json['wc_order_id'] ) && $this->request_json['wc_order_id'] > 0 ) {
            $invoice_number = laskuhari_invoice_number_by_order( $this->request_json['wc_order_id'] );
            $invoice_id     = laskuhari_invoice_id_by_order( $this->request_json['wc_order_id']);

            // if invoice id or number doesn't match, dont update status
            if( $this->request_json['invoice_id'] != $invoice_id || $this->request_json['invoice_number'] != $invoice_number ) {
                $this->response_ok( "Invoice number not found here" );
            }

            laskuhari_update_payment_status(
                $this->request_json['wc_order_id'],
                $status['code'],
                $status['name'],
                $status['id']
            );

            $this->response_ok( "Payment status updated" );
        }

        $this->response_ok( "Message received" );
    }
}

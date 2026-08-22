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

namespace Laskuhari;

defined( 'ABSPATH' ) || exit;

class Laskuhari_API
{
    /**
     * Instance of the class
     *
     * @var ?Laskuhari_API
     */
    protected static $instance;

    /**
     * The request JSON as an array
     *
     * @var array<string, mixed>
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
     * Cache for headers
     *
     * @var ?array<string, string> $headers
     */
    protected $headers;

    /**
     * Array of endpoints and their callback functions
     *
     * @var array<string, string> $endpoints
     */
    protected $endpoints = [
        "payment_status" => "handle_payment_status_request",
    ];

    /**
     * Initialize the API statically
     *
     * @param WC_Gateway_Laskuhari $gateway_object
     *
     * @return Laskuhari_API
     */
    public static function init( $gateway_object ) {
        if( ! isset( static::$instance ) ) {
            static::$instance = new Laskuhari_API( $gateway_object );
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
     *
     * @deprecated New webhooks use an HMAC signature with per-webhook secret
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
        $input = @file_get_contents( "php://input" );

        if( $input === false ) {
            $this->error( 400, "Unable to read input" );
            exit;
        }

        $this->request = $input;
        $decoded = json_decode( $this->request, true );

        if( ! is_array( $decoded ) ) {
            return;
        }

        foreach( $decoded as $key => $_ ) {
            if( ! is_string( $key ) ) {
                $this->error( 400, "Unexpected request" );
                exit;
            }
        }

        $this->request_json = $decoded;
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
     * @return array<string, string|int|float>
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
     * @return string|int|float|null
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
    protected function verify_signature() {
        // Legacy signature verification
        $auth_key = (string) $this->get_header( "x-auth-key" );

        if( $auth_key !== "" && strlen( $this->gateway_object->apikey ) >= 64 ) {
            $hash = static::generate_auth_key( $this->gateway_object->uid, $this->gateway_object->apikey, $this->request );

            if( hash_equals( $hash, $auth_key ) ) {
                return; // Request accepted
            }
        }

        // New signature verification
        $signature = (string) $this->get_header( "x-webhook-signature" );
        $webhook_secret = (string) get_option( "_laskuhari_webhook_secret" );

        if( $signature !== "" && strlen( $webhook_secret ) >= 64 ) {
            $hmac = hash_hmac( "sha256", $this->request, $webhook_secret );

            if( hash_equals( $hmac, $signature ) ) {
                return; // Request accepted
            }
        }

        // Fall through to error
        do_action( "laskuhari_unauthorized_api_request" );

        http_response_code( 401 );

        echo json_encode([
            "status"  => "ERROR",
            "message" => "Unauthorized"
        ]);
        exit;
    }

    /**
     * Check the timestamp of the request to prevent duplicate requests
     *
     * @return void
     */
    protected function check_timestamp() {
        // New timestamp
        $timestamp = intval( $this->get_header( 'x-webhook-timestamp' ) );

        if( ! $timestamp ) {
            // Legacy timestamp
            $timestamp = intval( $this->get_header( 'x-timestamp' ) );
        }

        // check that timestamps are in sync at least 60 seconds
        if( abs( $timestamp - time() ) > 60 ) {
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
        $this->verify_signature();
        $this->check_timestamp();
    }

    /**
     * Check that the request is a Laskuhari API request
     *
     * @return bool
     */
    protected function api_is_called() {
        return isset( $_GET['__laskuhari_api'] ) && $_GET['__laskuhari_api'] === "true";
    }

    /**
     * Print out an error response
     *
     * @param int $error_code
     * @param string $error_text
     * @return void
     */
    protected function error( $error_code, $error_text ) {
        http_response_code( $error_code );

        echo json_encode([
            "status"  => "ERROR",
            "message" => $error_text,
        ]);
        exit;
    }

    /**
     * Handle the current request
     *
     * @return void
     */
    public function handle_request() {
        // check if Laskuhari API is being called
        if( ! $this->api_is_called() ) {
            return;
        }

        do_action( "laskuhari_api_request_received" );

        $this->check_request();

        do_action( "laskuhari_webhook", $this->request );

        if( isset( $this->endpoints[$this->request_json['event']] ) ) {
            $this->{$this->endpoints[$this->request_json['event']]}();
            exit;
        }

        $this->error( 400, "Unknown event" );
    }

    /**
     * Respond with a 200 OK
     *
     * @param string $message
     * @phpstan-return never
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
     * @phpstan-return never
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
        $version = $this->request_json['version'] ?? "0.1";

        if( $version !== "0.1" ) {
            // New webhooks
            $invoice = $this->request_json['data']['invoice'] ?? [];
            $status = $invoice['status'] ?? null;

            if( ! is_array( $status ) ) {
                $this->error( 400, "Invalid status result" );
                exit;
            }

            $wc_order_id = $invoice['external']['order_id'] ?? null;
            $webhook_invoice_number = $invoice['number'] ?? null;

            if( isset( $wc_order_id ) && $wc_order_id > 0 ) {
                if( ! is_numeric( $wc_order_id ) ) {
                    $this->error( 400, "Invalid order ID" );
                    exit;
                }

                $invoice_number = laskuhari_invoice_number_by_order( (int) $wc_order_id );

                // if invoice number doesn't match, dont update status
                if( (string) $webhook_invoice_number !== (string) $invoice_number ) {
                    $this->response_ok( "Invoice number not found here" );
                }

                laskuhari_update_payment_status(
                    intval( $wc_order_id ),
                    ( $invoice['is_paid'] ?? false ) ? 1 : 0,
                    strval( $status['name'] ?? "" ),
                    strval( $status['id'] ?? "" )
                );

                $this->response_ok( "Payment status updated" );
            }

            $this->response_ok( "Message received" );
        }

        // Legacy webhooks
        $status = $this->request_json['status'];

        if( ! is_array( $status ) ) {
            $this->error( 400, "Invalid status result" );
            exit;
        }

        if( isset( $this->request_json['wc_order_id'] ) && $this->request_json['wc_order_id'] > 0 ) {
            if( ! is_numeric( $this->request_json['wc_order_id'] ) ) {
                $this->error( 400, "Invalid order ID" );
                exit;
            }

            $invoice_number = laskuhari_invoice_number_by_order( (int) $this->request_json['wc_order_id'] );
            $invoice_id     = laskuhari_invoice_id_by_order( (int) $this->request_json['wc_order_id'] );

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

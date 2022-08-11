<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

add_action( "init", "laskuhari_api_handle_request" );

function laskuhari_api_generate_auth_key( $uid, $apikey, $request ) {
    return hash( "sha256", implode( "+", [
        $uid,
        $apikey,
        $request
    ]) );
}

function laskuhari_api_handle_request() {
    // check if Laskuhari API is being called
    if( ! isset( $_GET['__laskuhari_api'] ) || $_GET['__laskuhari_api'] !== "true" ) {
        return false;
    }
    
    do_action( "laskuhari_api_request_received" );

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

    $request = @file_get_contents( "php://input" );
    $json = json_decode( $request, true );

    // initialize Laskuhari Gateway
    $laskuhari = new WC_Gateway_Laskuhari( true );

    // check that apikey is inserted into plugin settings
    if( strlen( $laskuhari->apikey ) < 64 ) {
        http_response_code( 500 );

        echo json_encode([
            "status"  => "ERROR",
            "message" => "Api key not found"
        ]);
        exit;
    }

    $headers = getallheaders();

    // make headers lowercase for case-insensitivity
    foreach( $headers as $key => $value ) {
        $headers[strtolower($key)] = $value;
    }

    $hash = laskuhari_api_generate_auth_key( $laskuhari->uid, $laskuhari->apikey, $request );

    // check that Auth-Key matches
    if( ! isset( $headers['x-auth-key'] ) || $headers['x-auth-key'] !== $hash ) {
        do_action( "laskuhari_unauthorized_api_request" );

        http_response_code( 401 );

        echo json_encode([
            "status"  => "ERROR",
            "message" => "Unauthorized"
        ]);
        exit;
    }

    // check that timestamps are in sync at least 30 seconds
    $timestamp = $headers['x-timestamp'];
    if( ! isset( $timestamp ) || abs( $timestamp - time() ) > 30 ) {
        do_action( "laskuhari_api_request_invalid_timestamp" );

        http_response_code( 401 );

        echo json_encode([
            "status"  => "ERROR",
            "message" => "Blocked possible duplicate request"
        ]);
        exit;
    }
    
    do_action( "laskuhari_webhook", $request );

    if( "payment_status" === $json['event'] ) {
        $status = $json['status'];

        if( isset( $json['wc_order_id'] ) && $json['wc_order_id'] > 0 ) {
            $invoice_number = laskuhari_invoice_number_by_order( $json['wc_order_id'] );
            $invoice_id     = laskuhari_invoice_id_by_order( $json['wc_order_id']);

            // if invoice id or number doesn't match, dont update status
            if( $json['invoice_id'] != $invoice_id || $json['invoice_number'] != $invoice_number ) {
                http_response_code( 200 );
        
                echo json_encode([
                    "status"  => "OK",
                    "message" => "Invoice number not found here"
                ]);
                exit;
            }

            laskuhari_update_payment_status(
                $order_id,
                $status['code'],
                $status['name'],
                $status['id']
            );
    
            http_response_code( 200 );
    
            echo json_encode([
                "status"  => "OK",
                "message" => "Payment status updated"
            ]);
            exit;
        }
    
        http_response_code( 200 );

        echo json_encode([
            "status"  => "OK",
            "message" => "Message received"
        ]);
        exit;
    }

    http_response_code( 400 );

    echo json_encode([
        "status"  => "ERROR",
        "message" => "Unknown event"
    ]);
    exit;
}
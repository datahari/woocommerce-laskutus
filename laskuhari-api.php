<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

add_action( "init", "laskuhari_api_handle_request" );

function laskuhari_api_generate_hash( $uid, $apikey, $request ) {
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
    $hash = laskuhari_api_generate_hash( $laskuhari->uid, $laskuhari->apikey, $request );

    // check that Auth-Key matches
    if( ! isset( $headers['X-Auth-Key'] ) || $headers['X-Auth-Key'] !== $hash ) {
		do_action( "laskuhari_unauthorized_api_request" );

	    http_response_code( 401 );

        echo json_encode([
            "status"  => "ERROR",
            "message" => "Unauthorized"
        ]);
        exit;
    }

    // check that timestamps are in sync at least 30 seconds
    if( ! isset( $json['timestamp'] ) || abs( $json['timestamp'] - time() ) > 30 ) {	
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
            update_post_meta( $json['wc_order_id'], '_laskuhari_payment_status', $status['code'] );
            update_post_meta( $json['wc_order_id'], '_laskuhari_payment_status_name', $status['name'] );
            update_post_meta( $json['wc_order_id'], '_laskuhari_payment_status_id', $status['id'] );
    
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
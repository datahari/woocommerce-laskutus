<?php
class LaskuhariPaymentTerm
{

    /**
     * Keep track of API request count
     *
     * @var integer
     */
    public static int $api_request_count = 0;

    /**
     * Save payment terms here
     *
     * @var array
     */
    public static array $payment_terms;

    public static function get_list( bool $force = false ): array|false {
        if( $force !== true ) {
            if( isset( self::$payment_terms ) ) {
                return self::$payment_terms;
            }

            $saved_terms = get_option( "_laskuhari_payment_terms" );

            if( $saved_terms ) {
                self::$payment_terms = apply_filters( "laskuhari_payment_terms", $saved_terms );
                return self::$payment_terms;
            }
        }

        // don't make too many api queries on one page load as it slows execution time
        if( self::$api_request_count++ >= 2 ) {
            return false;
        }

        $api_url = "https://" . laskuhari_domain() . "/rest-api/maksuehdot";
        $response = laskuhari_api_request( array(), $api_url, "Get payment terms" );

        if( $response['status'] === "OK" ) {
            update_option( "_laskuhari_payment_terms", $response['vastaus'], false );
            self::$payment_terms = apply_filters( "laskuhari_payment_terms", $response['vastaus'] );
            return self::$payment_terms;
        }

        return false;
    }
}

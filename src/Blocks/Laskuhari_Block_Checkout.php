<?php
/**
 * This is a class for registering the Laskuhari payment method
 * in the WooCommerce Block Checkout.
 */

namespace Laskuhari\Blocks;

defined( "ABSPATH" ) || exit;

class Laskuhari_Block_Checkout {
    /**
     * Extra fields for $_POST manipulation at checkout
     *
     * @var array<string, string>
     */
    public static $extra_post_fields = [];

    /**
     * Register the Laskuhari Block Checkout payment method
     */
    public function register(): void {
        // Register the Block Checkout payment method
        add_action(
            "woocommerce_blocks_payment_method_type_registration",
            function( $registry ) {
                $registry->register( new Laskuhari_Block_Checkout_Payment_Method() );
            }
        );

        // Register callback for updating Laskuhari payment method details
        woocommerce_store_api_register_update_callback( [
            "namespace" => "laskuhari",
            "callback"  => "laskuhari_checkout_update_order_review",
        ] );

        // Register hook for updating Laskuhari meta in order at checkout
        add_action(
            "woocommerce_store_api_checkout_update_order_from_request",
            [$this, "update_order_meta_at_checkout"],
            10,
            2
        );
    }

    /**
     * Update the Laskuhari metadata for an order when the checkout form is submitted
     */
    public function update_order_meta_at_checkout( \WC_Order $order, \WP_REST_Request $request ): void {
        if( $order->get_payment_method() !== "laskuhari" ) {
            return;
        }

        $fields = $request->get_param( "billing_address" );

        if( ! is_array( $fields ) ) {
            $fields = [];
        }

        $additional_fields = $request->get_param( "additional_fields" );
        if( is_array( $additional_fields ) ) {
            $fields = array_merge( $fields, $additional_fields );
        }

        $payment_data = $request->get_param( "payment_data" );

        if( is_array( $payment_data ) ) {
            foreach( $payment_data as $item ) {
                $key = $item["key"] ?? null;

                if( ! is_string( $key ) ) {
                    continue;
                }

                $value = $item["value"] ?? null;

                if( ! is_scalar( $value ) ) {
                    continue;
                }

                $fields[$key] = $value;
            }
        }

        $alt_fields = laskuhari_alternative_meta_fields();

        foreach( $alt_fields as $alt_field => $alt_keys ) {
            $key = laskuhari_resolve_alternative_meta_key( $alt_field, $fields );

            if( $key !== null ) {
                // Persist meta in order
                $order->update_meta_data( $alt_field, $fields[$key] );

                // Forward data to $_POST for legacy `validate_fields` support
                Laskuhari_Block_Checkout::$extra_post_fields[(string)reset( $alt_keys )] = $fields[$key];
            }
        }
    }
}

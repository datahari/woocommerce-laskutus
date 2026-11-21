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
        add_action(
            "woocommerce_blocks_payment_method_type_registration",
            function( $registry ) {
                $registry->register( new Laskuhari_Block_Checkout_Payment_Method() );
            }
        );

        add_action(
            "woocommerce_blocks_validate_location_address_fields",
            [$this, "intercept_block_checkout_fields"],
            10,
            3
        );
    }

    /**
     * Intercept Block Checkout fields used by Laskuhari
     * as alternative fields for metadata
     *
     * @param array<string, string> $fields
     * @param string $_group
     */
    public function intercept_block_checkout_fields( \WP_Error $_errors, $fields, $_group ): void {
        $alt_fields = laskuhari_alternative_meta_fields();

        foreach( $alt_fields as $alt_field => $alt_keys ) {
            $key = laskuhari_resolve_alternative_meta_key( $alt_field, $fields );

            if( $key !== null ) {
                Laskuhari_Block_Checkout::$extra_post_fields[(string)reset( $alt_keys )] = $fields[$key];
            }
        }
    }
}

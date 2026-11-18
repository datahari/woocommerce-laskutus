<?php
/**
 * This is a class for registering the Laskuhari payment method
 * in the WooCommerce Block Checkout.
 */

namespace Laskuhari;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

defined( "ABSPATH" ) || exit;

class Laskuhari_Block_Checkout extends AbstractPaymentMethodType {
    protected $name = "laskuhari";

    /**
     * The legacy gateway object that holds all settings
     *
     * @var WC_Gateway_Laskuhari $lh_gateway
     */
    protected $lh_gateway;

    public function __construct( WC_Gateway_Laskuhari $lh_gateway ) {
        $this->lh_gateway = $lh_gateway;
    }

    /**
     * Register the Laskuhari Block Checkout payment method
     */
    public function register(): void {
        add_action(
            "woocommerce_blocks_payment_method_type_registration",
            function( $registry ) {
                $registry->register( $this );
            }
        );
    }

    /**
     * Initialize the payment method
     */
    public function initialize(): void {
        // Nothing to do here
    }

    /**
     * Check if the payment method is active
     */
    public function is_active(): bool {
        return $this->lh_gateway->is_available();
    }

    /**
     * Register frontend JS for the payment method
     *
     * @return array<string> An array of script handles to enqueue
     */
    public function get_payment_method_script_handles(): array {
        wp_register_script(
            "laskuhari-js-block-checkout",
            plugins_url( "../js/block-checkout.js" , __FILE__ ), // @phpstan-ignore-line
            [ "wp-element", "wc-blocks-registry", "laskuhari-js-checkout" ],
            LASKUHARI_VERSION
        );

        wp_localize_script( "laskuhari-js-block-checkout", "laskuhariBlockCheckout", [
            "invoicingMethod" => get_laskuhari_meta( null, "_laskuhari_laskutustapa" ),
            "eInvoiceOperator" => get_laskuhari_meta( null, "_laskuhari_valittaja" ),
            "eInvoiceAddress" => get_laskuhari_meta( null, "_laskuhari_verkkolaskuosoite" ),
            "yourReference" => get_laskuhari_meta( null, "_laskuhari_viitteenne" ),
            "businessId" => get_laskuhari_meta( null, "_laskuhari_ytunnus" ),
            "user_email" => get_laskuhari_meta( null, "billing_email" ),
            "email" => get_laskuhari_meta( null, "_laskuhari_email" ),
            "operators" => laskuhari_operators(),
            "texts" => [
                "title" => $this->lh_gateway->title,
                "description" => $this->lh_gateway->description,
                "invoicingMethod_email" => apply_filters( "laskuhari_email_method_text", __( "Sähköposti", "laskuhari" ), null ),
                "invoicingMethod_eInvoice" => apply_filters( "laskuhari_einvoice_method_text", __( "Verkkolasku", "laskuhari" ), null ),
                "invoicingMethod_letter" => apply_filters( "laskuhari_letter_method_text", __( "Kirje", "laskuhari" ), null ),
                "invoicingMethod" => __( "Laskutustapa", "laskuhari" ),
                "selectInvoicingMethod" => __( "Valitse laskutustapa", "laskuhari" ),
                "email" => __( "Sähköpostiosoite", "laskuhari" ),
                "businessId" => __( "Y-tunnus", "laskuhari" ),
                "eInvoiceAddress" => __( "Verkkolaskuosoite / OVT", "laskuhari" ),
                "eInvoiceOperator" => __( "Verkkolaskuoperaattori", "laskuhari" ),
                "selectEInvoiceOperator" => __( "Valitse verkkolaskuoperaattori", "laskuhari" ),
                "yourReference" => __( "Viitteenne", "laskuhari" ),
                "optional" => __( "valinnainen", "laskuhari" ),
                "operators" => __( "Operaattorit", "laskuhari" ),
                "banks" => __( "Pankit", "laskuhari" ),
                "others" => __( "Muut", "laskuhari" ),
            ],
        ] );

        return [ "laskuhari-js-block-checkout" ];
    }
}

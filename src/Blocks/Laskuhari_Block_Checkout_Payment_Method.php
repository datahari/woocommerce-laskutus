<?php
/**
 * This is the payment method class for Laskuhari Block Checkout
 */

namespace Laskuhari\Blocks;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;
use Automattic\WooCommerce\Blocks\Domain\Services\CheckoutFields;
use Automattic\WooCommerce\Blocks\Package;
use Laskuhari\WC_Gateway_Laskuhari;

defined( "ABSPATH" ) || exit;

class Laskuhari_Block_Checkout_Payment_Method extends AbstractPaymentMethodType {
    /**
     * Name of the payment method
     *
     * @var string
     */
    protected $name = "laskuhari";

    /**
     * The legacy gateway object that holds all settings
     *
     * @var WC_Gateway_Laskuhari $lh_gateway
     */
    protected $lh_gateway;

    /**
     * Checks if the WooCommerce Block order form has a field
     * for a specific Laskuhari meta key
     *
     * @param string $meta_key
     * @param ?array $field_data Field data to use (default = get from WC registry)
     *
     * @return bool
     */
    protected function block_checkout_form_has_meta( $meta_key, $field_data = null ) {
        if( null === $field_data ) {
            $registry = Package::container()->get( CheckoutFields::class );

            $field_data = array_merge(
                $registry->get_core_fields(),
                $registry->get_additional_fields()
            );
        }

        $checkout_fields = [];

        foreach( $field_data as $field_name => $field_settings ) {
            $checkout_fields[$field_name] = $field_settings["default"] ?? "";
        }

        return (bool) laskuhari_resolve_alternative_meta_key( $meta_key, $checkout_fields );
    }

    /**
     * Initialize the payment method
     */
    public function initialize(): void {
        $this->lh_gateway = laskuhari_get_gateway_object();
    }

    /**
     * Check if the payment method is active
     *
     * NOTE: This checks whether the payment method is enabled,
     *       not whether it should be allowed with the current
     *       state of the checkout form / cart. That is done in
     *       the `WC_Gateway_Laskuhari` class.
     */
    public function is_active(): bool {
        return $this->lh_gateway->lh_get_option( "gateway_enabled" ) === "yes";
    }

    /**
     * Register frontend JS for the payment method
     *
     * @return array<string> An array of script handles to enqueue
     */
    public function get_payment_method_script_handles(): array {
        wp_register_script(
            "laskuhari-js-block-checkout",
            plugins_url( "../../js/block-checkout.js" , __FILE__ ), // @phpstan-ignore-line
            [ "wp-element", "wc-blocks-registry", "wc-blocks-checkout", "laskuhari-js-checkout" ],
            laskuhari_plugin_version()
        );

        wp_localize_script( "laskuhari-js-block-checkout", "laskuhariBlockCheckout", [
            "invoicingMethod" => get_laskuhari_meta( null, "_laskuhari_laskutustapa" ),
            "eInvoiceOperator" => get_laskuhari_meta( null, "_laskuhari_valittaja" ),
            "eInvoiceAddress" => get_laskuhari_meta( null, "_laskuhari_verkkolaskuosoite" ),
            "yourReference" => get_laskuhari_meta( null, "_laskuhari_viitteenne" ),
            "businessId" => get_laskuhari_meta( null, "_laskuhari_ytunnus" ),
            "user_email" => get_laskuhari_meta( null, "billing_email" ),
            "email" => get_laskuhari_meta( null, "_laskuhari_billing_email" ),
            "operators" => laskuhari_operators(),
            "emailEnabled" => $this->lh_gateway->email_lasku_kaytossa,
            "eInvoiceEnabled" => $this->lh_gateway->verkkolasku_kaytossa,
            "letterEnabled" => $this->lh_gateway->kirjelasku_kaytossa,
            "showInvoicingMethod" => ! $this->block_checkout_form_has_meta( "_laskuhari_laskutustapa" ),
            "showBusinessId" => ! $this->block_checkout_form_has_meta( "_laskuhari_ytunnus" ),
            "showEInvoiceOperator" => ! $this->block_checkout_form_has_meta( "_laskuhari_valittaja" ),
            "showEInvoiceAddress" => ! $this->block_checkout_form_has_meta( "_laskuhari_verkkolaskuosoite" ),
            "showYourReference" => ! $this->block_checkout_form_has_meta( "_laskuhari_viitteenne" ),
            "vatIdRequiredMethods" => laskuhari_vat_id_mandatory_for_methods(),
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

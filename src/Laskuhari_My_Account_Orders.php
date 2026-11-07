<?php
namespace Laskuhari;

use WC_Order;

defined( "ABSPATH" ) || exit;

/**
 * This class handles customizations to the My Account > Orders page
 */

class Laskuhari_My_Account_Orders
{
    /**
     * Register hooks for My Account > Orders page customizations
     *
     * @return void
     */
    public function register_hooks() {
        $this->add_invoice_download_action();
    }

    /**
     * Add a Download invoice button to My Orders page where the customer
     * can download the invoice of their order.
     *
     * @return void
     */
    protected function add_invoice_download_action() {
        $this->add_invoice_download_action_button();
        $this->register_invoice_download_action_hook();
    }

    /**
     * Build invoice download URL for an order.
     */
    protected function get_invoice_download_url( WC_Order $order ): string {
        $url = add_query_arg( [
            "action"   => "laskuhari_download_order_invoice",
            "order_id" => $order->get_id(),
        ], admin_url( "admin-post.php" ) );

        $url = wp_nonce_url( $url, "laskuhari_download_order_invoice_" . $order->get_id() );

        /**
         * A filter to customize the invoice download URL
         *
         * @param string $url The invoice download URL
         * @param WC_Order $order The WC_Order object in question
         */
        $url = apply_filters( "laskuhari_download_order_invoice_url", $url, $order );

        return $url;
    }

    /**
     * Add the Download invoice button to the My Orders page action buttons
     */
    protected function add_invoice_download_action_button(): void {
        add_filter( "woocommerce_my_account_my_orders_actions", function( array $actions, WC_Order $order ) {
            $show_button = $order->get_payment_method() === "laskuhari";

            /**
             * Filters whether the download invoice action button is shown for an order.
             *
             * @param bool $show_button Whether to show the button.
             * @param WC_Order $order The current order.
             * @param array<string, array<string, string>> $actions Existing actions.
             */
            $show_button = (bool) apply_filters( "laskuhari_show_download_order_invoice_button", $show_button, $order, $actions );

            if( ! $show_button ) {
                return $actions;
            }

            $invoice_id = laskuhari_invoice_id_by_order( $order );

            if( ! $invoice_id ) {
                return $actions;
            }

            /**
             * Filters the button label for the My Account invoice download action.
             *
             * @param string $label The button label.
             * @param WC_Order $order The current order.
             * @param array<string, array<string, string>> $actions Existing actions.
             */
            $label = (string) apply_filters( "laskuhari_download_order_invoice_button_label", __( "Lataa lasku", "laskuhari" ), $order, $actions );

            $actions["laskuhari-download-order-invoice"] = [
                "url" => $this->get_invoice_download_url( $order ),
                "name" => $label,
            ];

            return $actions;
        }, 10, 2 );
    }

    /**
     * Hook the action to the Download invoice button of My Orders page
     */
    protected function register_invoice_download_action_hook(): void {
        add_action( "admin_post_laskuhari_download_order_invoice", function() {
            $order_id = isset( $_GET["order_id"] ) ? absint( $_GET["order_id"] ) : 0;

            check_admin_referer( "laskuhari_download_order_invoice_" . $order_id );

            $order = laskuhari_get_wc_order( $order_id, "downloading account order invoice" );

            /**
             * Fires before handling My Account invoice download action.
             *
             * Callbacks may stop execution (e.g. wp_die / wp_redirect + exit)
             * to fully override the default behavior.
             *
             * @param int $order_id The order ID from the request.
             * @param ?WC_Order $order The resolved order object, or null if not found.
             */
            do_action( "laskuhari_before_download_order_invoice", $order_id, $order );

            if( ! $order || (int) $order->get_customer_id() !== get_current_user_id() ) {
                wp_die( __( "Sinulla ei ole oikeutta tähän toimintoon", "laskuhari" ), "", [ "response" => 403 ] );
            }

            Logger::enabled( "debug" ) && Logger::log( sprintf(
                "Laskuhari: Downloading invoice for order %d from My Orders page",
                $order_id
            ), "debug" );

            laskuhari_download( $order, true );

            wp_die( __( "Laskun lataus epäonnistui", "laskuhari" ), "", [ "response" => 500 ] );
        } );
    }
}

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

        return wp_nonce_url( $url, "laskuhari_download_order_invoice_" . $order->get_id() );
    }

    /**
     * Add the Download invoice button to the My Orders page action buttons
     */
    protected function add_invoice_download_action_button(): void {
        add_filter( "woocommerce_my_account_my_orders_actions", function( array $actions, WC_Order $order ) {
            $invoice_id = laskuhari_invoice_id_by_order( $order );

            if( ! $invoice_id ) {
                return $actions;
            }

            $actions["laskuhari-download-order-invoice"] = [
                "url" => $this->get_invoice_download_url( $order ),
                "name" => __( "Lataa lasku", "laskuhari" ),
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

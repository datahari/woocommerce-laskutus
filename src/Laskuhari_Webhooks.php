<?php
/**
 * This class is used for generating webhooks to Laskuhari
 */

namespace Laskuhari;

defined( "ABSPATH" ) || exit;

class Laskuhari_Webhooks
{
    /**
     * The Laskuhari Payment Gateway
     *
     * @var WC_Gateway_Laskuhari
     */
    protected $gateway;

    public function __construct( WC_Gateway_Laskuhari $gateway ) {
        $this->gateway = $gateway;
    }

    /**
     * Register AJAX endpoints for adding and removing webhooks
     *
     * @return void
     */
    public function register_endpoints(): void {
        add_action( "wp_ajax_laskuhari_add_webhooks", function() {
            check_ajax_referer( "laskuhari_admin_ajax", "nonce" );

            $this->add_webhook( "payment_status" );
        } );

        add_action( "wp_ajax_laskuhari_delete_webhooks", function() {
            check_ajax_referer( "laskuhari_admin_ajax", "nonce" );

            $this->delete_webhook( "payment_status" );
        } );
    }

    /**
     * Get the callback URL for accepting webhooks
     *
     * @return string
     */
    protected function get_callback_url(): string {
        return site_url( "/index.php" ) . "?__laskuhari_api=true";
    }

    /**
     * Add a webhook to Laskuhari
     *
     * @param string $event The event to listen for (e.g. `payment_status`)
     *
     * @return never
     */
    public function add_webhook( $event ): void {
        if( ! current_user_can( "manage_options" ) ) {
            wp_send_json_error( "Access Denied" );
        }

        if( $this->gateway->demotila ) {
            wp_send_json_error( __( "Demotunnuksilla ei voi lisätä webhookeja", "laskuhari" ) );
        }

        $callback_url = $this->get_callback_url();

        $api_url = "https://" . laskuhari_domain() . "/rest-api/webhooks/";
        $api_url = apply_filters( "laskuhari_webhooks_api_url", $api_url, $event, $callback_url );

        $payload = [
            "event" => $event,
            "url" => $callback_url,
            "version" => "1.0"
        ];

        $payload = apply_filters( "laskuhari_add_webhook_payload", $payload, $event, $callback_url );

        $payload = json_encode( $payload, laskuhari_json_flag() );

        $response = laskuhari_api_request( $payload, $api_url, "Add webhook" );

        if( $response === false ) {
            Logger::enabled( 'error' ) && Logger::log( sprintf(
                'Laskuhari: Failed to add webhook: Request failed'
            ), 'error' );

            wp_send_json_error( "Adding webhook failed" );
        }

        $secret = $response["secret"] ?? null;

        if( ! is_string( $secret ) ) {
            Logger::enabled( 'error' ) && Logger::log( sprintf(
                'Laskuhari: Failed to add webhook: No secret returned'
            ), 'error' );

            wp_send_json_error( "Adding webhook failed" );
        }

        $this->gateway->update_option( "payment_status_webhook_secret", $secret );
        $this->gateway->update_option( "payment_status_webhook_added", "v1" );
        $this->gateway->payment_status_webhook_added = true;

        wp_send_json_success( "Webhook added" );
    }

    /**
     * Delete a webhook from Laskuhari
     *
     * @param string $event The event to delete (e.g. `payment_status`)
     *
     * @return never
     */
    public function delete_webhook( $event ): void {
        if( ! current_user_can( "manage_options" ) ) {
            wp_send_json_error( "Access Denied" );
        }

        if( $this->gateway->demotila ) {
            wp_send_json_error( __( "Demotunnuksilla ei voi poistaa webhookeja", "laskuhari" ) );
        }

        $api_url = "https://" . laskuhari_domain() . "/rest-api/webhooks/";
        $api_url = apply_filters( "laskuhari_delete_webhooks_api_url", $api_url, $event );

        $get_response = laskuhari_api_request( "", $api_url, "Get webhooks", "json", "GET" );

        if( $get_response === false ) {
            Logger::enabled( 'error' ) && Logger::log( sprintf(
                'Laskuhari: Failed to get current webhooks: Request failed'
            ), 'error' );

            wp_send_json_error( "Deleting webhook failed" );
        }

        if( ! is_array( $get_response ) ) {
            Logger::enabled( 'error' ) && Logger::log( sprintf(
                'Laskuhari: Failed to get current webhooks: Invalid response'
            ), 'error' );

            wp_send_json_error( "Deleting webhook failed" );
        }

        if( ! isset( $get_response["response"] ) || ! is_array( $get_response["response"] ) ) {
            Logger::enabled( 'error' ) && Logger::log( sprintf(
                'Laskuhari: Failed to get current webhooks: Invalid response'
            ), 'error' );

            wp_send_json_error( "Deleting webhook failed" );
        }

        $current_webhooks = $get_response["response"];

        $webhook_deleted = false;

        foreach( $current_webhooks as $webhook ) {
            if( ! is_array( $webhook ) || ! isset( $webhook["event"] ) || ! is_string( $webhook["event"] ) ) {
                Logger::enabled( 'error' ) && Logger::log( sprintf(
                    'Laskuhari: Failed to get current webhooks: Webhook has no event type'
                ), 'error' );

                wp_send_json_error( "Deleting webhook failed" );
            }

            if( $webhook["event"] !== $event ) {
                continue;
            }

            if( ! isset( $webhook["id"] ) || ! is_string( $webhook["id"] ) ) {
                Logger::enabled( 'error' ) && Logger::log( sprintf(
                    'Laskuhari: Failed to get current webhooks: Webhook has no id'
                ), 'error' );

                wp_send_json_error( "Deleting webhook failed" );
            }

            $payload = [
                "id" => $webhook["id"],
            ];

            $payload = apply_filters( "laskuhari_delete_webhook_payload", $payload, $event );

            $payload = json_encode( $payload, laskuhari_json_flag() );

            $response = laskuhari_api_request( $payload, $api_url, "Delete webhook", "json", "DELETE" );

            if( $response === false ) {
                Logger::enabled( 'error' ) && Logger::log( sprintf(
                    'Laskuhari: Failed to delete webhook: Request failed'
                ), 'error' );

                wp_send_json_error( "Deleting webhook failed" );
            }

            if( ! is_array( $response ) || ! isset( $response["status"] ) || $response["status"] !== "OK" ) {
                Logger::enabled( 'error' ) && Logger::log( sprintf(
                    'Laskuhari: Failed to delete webhook: Status not OK'
                ), 'error' );

                wp_send_json_error( "Deleting webhook failed" );
            }

            $webhook_deleted = true;
        }

        $this->gateway->update_option( "payment_status_webhook_secret", "" );
        $this->gateway->update_option( "payment_status_webhook_added", "no" );
        $this->gateway->payment_status_webhook_added = false;

        if( $webhook_deleted ) {
            wp_send_json_success( "Webhook deleted" );
        } else {
            wp_send_json_success( "No webhook found" );
        }
    }
}

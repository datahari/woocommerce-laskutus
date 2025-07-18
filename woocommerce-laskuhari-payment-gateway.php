<?php
/*
Plugin Name: Laskuhari for WooCommerce
Plugin URI: https://www.laskuhari.fi/woocommerce-laskutus
Description: Lisää automaattilaskutuksen maksutavaksi WooCommerce-verkkokauppaan sekä mahdollistaa tilausten manuaalisen laskuttamisen
Version: 1.14.0
Author: Datahari Solutions
Author URI: https://www.datahari.fi
License: GPLv2
*/

/*
Based on WooCommerce Custom Payment Gateways
Author: Mehdi Akram
Author URI: http://shamokaldarpon.com/
*/

use Automattic\WooCommerce\Utilities\NumberUtil;
use Laskuhari\Exception\Finvoice\FinvoiceException;
use Laskuhari\Finvoice\FinvoiceValidator;
use Laskuhari\Laskuhari_API;
use Laskuhari\Laskuhari_Export_Products_REST_API;
use Laskuhari\Laskuhari_Nonce;
use Laskuhari\Laskuhari_Plugin_Updater;
use Laskuhari\Laskuhari_Troubleshooter;
use Laskuhari\Laskuhari_Uninstall;
use Laskuhari\Logger;
use Laskuhari\WC_Gateway_Laskuhari;

defined( 'ABSPATH' ) || exit;

require_once dirname( __FILE__ ) . '/autoload.php';

Laskuhari_Plugin_Updater::init();
Laskuhari_Troubleshooter::register_endpoint();
Laskuhari_Uninstall::register_uninstall_hook( __FILE__ );
Logger::register_log_cleanup();

if( apply_filters( "laskuhari_export_rest_api_enabled", true ) ) {
    Laskuhari_Export_Products_REST_API::init();
}

add_action( 'woocommerce_after_register_post_type', 'laskuhari_payment_gateway_load', 0 );

function laskuhari_payment_gateway_load() {
    if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
        add_action( 'admin_notices', 'laskuhari_fallback_notice' );
        return;
    }

    add_filter( 'woocommerce_payment_gateways', 'laskuhari_add_gateway' );

    $laskuhari_gateway_object = laskuhari_get_gateway_object();

    add_filter( 'plugin_action_links', 'laskuhari_plugin_action_links', 10, 2 );
    add_filter( 'laskuhari_sanitize_product_name', 'laskuhari_sanitize_product_name', 10, 2 );

    laskuhari_maybe_create_webhook();

    // Add actions for handling invoice creation from other payment methods
    if( count( $laskuhari_gateway_object->send_invoice_from_payment_methods ) ) {
        // Note: Creation of invoice PDF can not be hooked into this action, because
        //       some payment gateways can't handle a long running pre_payment_complete action
        //       and it will cause duplicate order confirmations to be sent
        add_action( 'woocommerce_pre_payment_complete', 'laskuhari_grab_paid_order_id' );

        // Note: This hook only runs if the invoice PDF is not attached to the order confirmation email.
        //       This hook runs after the confirmation email has already been sent.
        add_action( "woocommerce_payment_complete", "laskuhari_maybe_create_invoice_for_other_payment_method", 10, 1 );
    }

    add_filter( 'plugin_row_meta', 'laskuhari_register_plugin_links', 10, 2 );

    if( $laskuhari_gateway_object->lh_get_option( 'enabled' ) !== 'yes' ) {
        add_action( 'admin_notices', 'laskuhari_not_activated' );
        return;
    } elseif ( $laskuhari_gateway_object->demotila ) {
        add_action( 'admin_notices', 'laskuhari_demo_notice' );
    }

    if( $laskuhari_gateway_object->use_wp_cron && defined( "DISABLE_WP_CRON" ) && DISABLE_WP_CRON ) {
        add_action( 'admin_notices', 'laskuhari_wp_cron_disabled_notice' );
    }

    if( $laskuhari_gateway_object->lh_get_option( 'gateway_enabled' ) === 'yes' ) {
        laskuhari_maybe_add_vat_id_field();
        add_action( 'woocommerce_checkout_update_order_meta', 'laskuhari_checkout_update_order_meta' );
        add_action( 'woocommerce_after_checkout_validation', 'laskuhari_einvoice_notices', 10, 2);
        add_action( 'wp_footer', 'laskuhari_add_public_scripts' );
        add_action( 'wp_footer', 'laskuhari_add_styles' );
        add_action( 'woocommerce_cart_calculate_fees','laskuhari_add_invoice_surcharge', 10, 1 );
        add_action( 'woocommerce_checkout_update_order_review','laskuhari_checkout_update_order_review', 10, 1 );
        add_action( 'woocommerce_checkout_update_customer', 'laskuhari_checkout_update_customer', 10, 2 );
    }

    laskuhari_actions();

    if( $laskuhari_gateway_object->synkronoi_varastosaldot ) {
        add_action( 'woocommerce_product_set_stock', 'laskuhari_update_stock_delayed' );
        add_action( 'woocommerce_variation_set_stock', 'laskuhari_update_stock_delayed' );
        add_action( 'woocommerce_update_product', 'laskuhari_sync_product_on_save', 10, 1 );
        add_action( 'woocommerce_update_product_variation', 'laskuhari_sync_product_on_save', 10, 1 );
    }

    add_action( 'admin_print_scripts', 'laskuhari_add_public_scripts' );
    add_action( 'admin_print_scripts', 'laskuhari_add_admin_scripts' );
    add_action( 'admin_print_styles', 'laskuhari_add_admin_styles' );
    add_action( 'show_user_profile', 'laskuhari_user_profile_additional_info' );
    add_action( 'edit_user_profile', 'laskuhari_user_profile_additional_info' );
    add_action( 'personal_options_update', 'laskuhari_update_user_meta' );
    add_action( 'edit_user_profile_update', 'laskuhari_update_user_meta' );
    add_action( 'add_meta_boxes', 'laskuhari_metabox' );

    add_action( 'woocommerce_order_status_cancelled_to_processing_notification', "laskuhari_maybe_send_invoice_attached", 10, 1 );
    add_action( 'woocommerce_order_status_failed_to_processing_notification', "laskuhari_maybe_send_invoice_attached", 10, 1 );
    add_action( 'woocommerce_order_status_on-hold_to_processing_notification', "laskuhari_maybe_send_invoice_attached", 10, 1 );
    add_action( 'woocommerce_order_status_pending_to_processing_notification', "laskuhari_maybe_send_invoice_attached", 10, 1 );
    add_action( 'woocommerce_before_resend_order_emails', "laskuhari_resend_order_emails", 10, 2 );

    // Laskuhari custom column in order view (HPOS)
    add_action( 'manage_shop_order_posts_custom_column', 'laskuhari_add_invoice_status_to_custom_order_list_column' );
    add_filter( 'manage_edit-shop_order_columns', 'laskuhari_add_column_to_order_list' );

    // Laskuhari custom column in order view (legacy)
    add_action( 'woocommerce_shop_order_list_table_custom_column', 'laskuhari_add_invoice_status_to_custom_order_list_column', 10, 2 );
    add_filter( 'woocommerce_shop_order_list_table_columns', 'laskuhari_add_column_to_order_list' );

    // Laskuhari order bulk actions (HPOS)
    add_filter( 'bulk_actions-woocommerce_page_wc-orders', 'laskuhari_add_bulk_action_for_invoicing', 20, 1 );
    add_filter( 'handle_bulk_actions-woocommerce_page_wc-orders', 'laskuhari_handle_bulk_actions', 10, 3 );

    // Laskuhari order bulk actions (legacy)
    add_filter( 'bulk_actions-edit-shop_order', 'laskuhari_add_bulk_action_for_invoicing', 20, 1 );
    add_filter( 'handle_bulk_actions-edit-shop_order', 'laskuhari_handle_bulk_actions', 10, 3 );

    add_filter( 'woocommerce_order_get_payment_method_title', 'laskuhari_add_payment_terms_to_payment_method_title', 10, 2 );

    add_action( 'laskuhari_create_product_action', 'laskuhari_create_product_cron_hook', 10, 2 );
    add_action( 'laskuhari_update_stock_action', 'laskuhari_update_stock_cron_hook', 10, 1 );
    add_action( 'laskuhari_process_action_delayed_action', 'laskuhari_process_action_cron_hook', 10, 3 );

    if( isset( $_GET['laskuhari_luotu'] ) || isset( $_GET['laskuhari_lahetetty'] ) || isset( $_GET['laskuhari_notice'] ) || isset( $_GET['laskuhari_success'] ) ) {
        add_action( 'admin_notices', 'laskuhari_notices' );
    }

    if( apply_filters( "laskuhari_api_enabled", true ) ) {
        Laskuhari_API::init( $laskuhari_gateway_object );
    }

}

/**
 * Add webhook to Laskuhari for payment status updates if it hasn't
 * been added yet and the create_webhooks option is enabled.
 *
 * @return void
 */
function laskuhari_maybe_create_webhook() {
    $lh = laskuhari_get_gateway_object();

    if( $lh->create_webhooks && $lh->demotila && strlen( $lh->apikey ) > 64 && $lh->uid ) {
        $api_url = site_url( "/index.php" ) . "?__laskuhari_api=true";

        if( ! $lh->payment_status_webhook_added && laskuhari_add_webhook( "payment_status", $api_url ) ) {
            $lh->update_option( "payment_status_webhook_added", "yes" );
            $lh->payment_status_webhook_added = true;
        }
    } elseif( $lh->payment_status_webhook_added ) {
        $lh->update_option( "payment_status_webhook_added", "no" );
        $lh->payment_status_webhook_added = false;
    }
}

function laskuhari_get_gateway_object() {
    return WC_Gateway_Laskuhari::get_instance();
}

function laskuhari_domain() {
    return apply_filters( "laskuhari_domain", "oma.laskuhari.fi" );
}

/**
 * Gets the current Laskuhari plugin version
 *
 * @return string
 */
function laskuhari_plugin_version(): string {
    $plugin_data = get_file_data( __FILE__, ['Version' => 'Version'] );
    return $plugin_data['Version'];
}

function laskuhari_json_flag() {
    if( ! defined( JSON_INVALID_UTF8_SUBSTITUTE ) ) {
        if( ! defined( JSON_PARTIAL_OUTPUT_ON_ERROR ) ) {
            return 0;
        }
        return JSON_PARTIAL_OUTPUT_ON_ERROR;
    }
    return JSON_INVALID_UTF8_SUBSTITUTE;
}

function laskuhari_add_payment_terms_to_payment_method_title( $title, $order ) {
    $is_laskuhari_order = laskuhari_get_post_meta( $order->get_id(), '_payment_method', true ) === "laskuhari";
    if( is_admin() && $is_laskuhari_order && $payment_terms_name = laskuhari_get_post_meta( $order->get_id(), '_laskuhari_payment_terms_name', true ) ) {
        if( mb_stripos( $title, $payment_terms_name ) === false ) {
            $title .= " (" . $payment_terms_name . ")";
        }
    }
    return $title;
}

/**
 * Sanitizes the product name before adding it to the invoice.
 * By default it strips tags from the product name.
 *
 * @param string $product_name
 * @param array $data
 * @return string
 */
function laskuhari_sanitize_product_name( $product_name, $data ) {
    return strip_tags( $product_name );
}

function lh_create_select_box( $name, $options, $current = '' ) {
    $html = '<select name="'.esc_attr( $name ).'">';
    foreach( $options as $value => $text ) {
        $html .= '<option value="'.esc_attr( $value ).'"';
        if( $current == $value ) {
            $html .= " selected";
        }
        $html .= '>'.esc_html( $text ).'</option>';
    }
    $html .= '</select>';
    return $html;
}

// get the newest address book prefix of WooCommerce Address Book plugin
function laskuhari_get_newest_address_book_prefix( $customer ) {
    $address_book_prefix = "";

    $address_names = $customer->get_meta( 'wc_address_book_billing', true );

    if( empty( $address_names ) ) {
        $address_book_prefix = "billing_";
    } elseif( is_array( $address_names ) ) {
        $latest = 0;
        foreach( $address_names as $address_name ) {
            if( $address_name > $latest ) {
                $address_book_prefix = $address_name."_";
            }
        }
    }

    return $address_book_prefix;
}

// save customer specific laskuhari meta data when customer is updated
function laskuhari_checkout_update_customer( $customer ) {
    $meta_data_to_save = [
        "laskuhari-laskutustapa" => "_laskuhari_laskutustapa",
        "laskuhari-valittaja" => "_laskuhari_valittaja",
        "laskuhari-verkkolaskuosoite" => "_laskuhari_verkkolaskuosoite",
        "laskuhari-ytunnus" => "_laskuhari_ytunnus",
    ];

    // for compatibility with WooCommerce Address Book plugin
    if( isset( $_POST['billing_address_book'] ) ) {
        if( $_POST['billing_address_book'] === "add_new" ) {
            $address_book_prefix = laskuhari_get_newest_address_book_prefix( $customer );
        } else {
            $address_book_prefix = $_POST['billing_address_book']."_";
        }

        $meta_data_to_save["laskuhari-viitteenne"] = "_laskuhari_viitteenne";
    } else {
        $address_book_prefix = "";
    }

    $meta_data_to_save = apply_filters( "laskuhari_checkout_meta_data_to_save", $meta_data_to_save, $customer );

    foreach( $_POST as $key => $value ) {
        if( array_key_exists( $key, $meta_data_to_save ) ) {
            $meta_key = $meta_data_to_save[$key];
            $customer->update_meta_data( $address_book_prefix.$meta_key, $value );
        }
    }
}

/**
 * This function saves the ID of an order paid at checkout
 * so that it can be used in the woocommerce_email_attachments
 * hook to create an invoice of the paid order and attach it
 * to the order confirmation email
 *
 * Note: this function is hooked into woocommerce_pre_payment_complete
 *       and should not include any long running tasks since some
 *       payment gateways don't handle them properly, resulting in
 *       duplicated order confirmation emails
 *
 * @param int $order_id
 * @return void
 */
function laskuhari_grab_paid_order_id( $order_id ) {
    Logger::enabled( 'debug' ) && Logger::log( sprintf(
        'Laskuhari: Grabbing paid order ID %d',
        $order_id
    ), 'debug' );

    laskuhari_get_gateway_object()->paid_order_id = intval( $order_id );
}

/**
 * Creates an invoice from an order that was just paid at checkout using other payment methods
 *
 * WC_Gateway_Laskuhari->paid_order_id is set to the ID of the order that was paid during
 * this request in woocommerce_pre_payment_complete hook - this function is hooked to
 * woocommerce_payment_complete and the order status change hooks and will only
 * be run in which ever hook runs first.
 *
 * @param int $order_id
 * @return void
 */
function laskuhari_maybe_create_invoice_for_other_payment_method( $order_id ) {
    $laskuhari_gateway_object = laskuhari_get_gateway_object();

    // create invoice only if the order was just paid
    if( $laskuhari_gateway_object->paid_order_id !== $order_id ) {
        return;
    }

    // prevent later hooks from creating invoice again
    $laskuhari_gateway_object->paid_order_id = null;

    Logger::enabled( 'info' ) && Logger::log( sprintf(
        'Laskuhari: Handling maybe_create_invoice for order %d',
        $order_id
    ), 'info' );

    $order = wc_get_order( $order_id );

    if( ! $order ) {
        Logger::enabled( 'error' ) && Logger::log( sprintf(
            'Laskuhari: Could not get order object for order id %s at %s',
            $order_id,
            __FUNCTION__
        ), 'error' );

        return false;
    }

    $allowed_payment_methods = apply_filters(
        "laskuhari_send_invoice_from_payment_methods",
        $laskuhari_gateway_object->send_invoice_from_payment_methods,
        $order_id
    );

    $payment_method = $order->get_payment_method();

    if( ! in_array( $payment_method, $allowed_payment_methods ) ) {
        Logger::enabled( 'debug' ) && Logger::log( sprintf(
            'Laskuhari: %s is not in allowed payment methods: %s',
            $payment_method,
            implode( ", ", $allowed_payment_methods )
        ), 'debug' );

        return false;
    }

    laskuhari_set_order_meta( $order_id, '_laskuhari_paid_by_other', "yes" );

    // create invoice only if no invoice has been created yet
    $create_invoice = ! laskuhari_invoice_is_created_from_order( $order_id );

    // allow changing invoice creation logic by other plugins
    $create_invoice = apply_filters( "laskuhari_handle_payment_complete_create_invoice", $create_invoice, $order_id );

    if( $create_invoice ) {
        if( ! $laskuhari_gateway_object->use_wp_cron || $laskuhari_gateway_object->attach_receipt_to_wc_email ) {
            Logger::enabled( 'info' ) && Logger::log( sprintf(
                'Laskuhari: Creating invoice for order %s synchronously', $order_id
            ), 'info' );

            laskuhari_process_action( $order_id, false, false, false );
        } else {
            Logger::enabled( 'info' ) && Logger::log( sprintf(
                'Laskuhari: Creating invoice for order %s delayed', $order_id
            ), 'info' );

            laskuhari_process_action_delayed( $order_id, false, false, false );
        }
    } else {
        Logger::enabled( 'debug' ) && Logger::log( sprintf(
            'Laskuhari: Not creating invoice on payment complete, order %d',
            $order_id
        ), 'debug' );
    }
}

add_action( 'restrict_manage_posts', 'display_admin_shop_order_laskuhari_filter' );
function display_admin_shop_order_laskuhari_filter() {
    global $pagenow, $post_type;

    if( 'shop_order' === $post_type && 'edit.php' === $pagenow ) {
        $current = isset( $_GET['filter_laskuhari_status'] ) ? $_GET['filter_laskuhari_status'] : '';

        $options = [
            ""                     => 'Laskuhari: ' . __( 'Kaikki', 'laskuhari' ),
            "ei_laskutettu"        => 'Laskuhari: ' . __( 'Ei laskutettu', 'laskuhari' ),
            "ei_laskutettu_kaikki" => 'Laskuhari: ' . __( 'Ei laskutettu (Kaikki)', 'laskuhari' ),
            "lasku_luotu"          => 'Laskuhari: ' . __( 'Lasku luotu', 'laskuhari' ),
            "laskutettu"           => 'Laskuhari: ' . __( 'Laskutettu', 'laskuhari' ),
            "maksettu"             => 'Laskuhari: ' . __( 'Maksettu', 'laskuhari' ),
            "ei_maksettu"          => 'Laskuhari: ' . __( 'Ei maksettu', 'laskuhari' ),
            "jonossa"              => 'Laskuhari: ' . __( 'Jonossa', 'laskuhari' ),
        ];

        echo lh_create_select_box( "filter_laskuhari_status", $options, $current );
    }
}

add_action( 'pre_get_posts', 'laskuhari_custom_status_filter' );
function laskuhari_custom_status_filter( $query ) {
    global $pagenow;

    $status_queries = [
        "laskutettu" => [
            [
                'key'     => '_laskuhari_sent',
                'compare' => '=',
                'value'   => "yes",
            ]
        ],
        "jonossa" => [
            [
                'key'     => '_laskuhari_queued',
                'compare' => '=',
                'value'   => "yes",
            ]
        ],
        "ei_laskutettu" => [
            'relation' => 'and',
            [
                'relation' => 'or',
                [
                    'key'     => '_laskuhari_invoice_number',
                    'compare' => 'NOT EXISTS'
                ],
                [
                    'key'     => '_laskuhari_invoice_number',
                    'compare' => '=',
                    'value'   => '0'
                ],
                [
                    'key'     => '_laskuhari_invoice_number',
                    'compare' => '=',
                    'value'   => ''
                ]
            ],
            [
                [
                    'key'     => '_payment_method',
                    'compare' => '=',
                    'value'   => 'laskuhari'
                ]
            ]
        ],
        "ei_laskutettu_kaikki" => [
            'relation' => 'and',
            [
                'relation' => 'or',
                [
                    'key'     => '_laskuhari_invoice_number',
                    'compare' => 'NOT EXISTS'
                ],
                [
                    'key'     => '_laskuhari_invoice_number',
                    'compare' => '=',
                    'value'   => '0'
                ],
                [
                    'key'     => '_laskuhari_invoice_number',
                    'compare' => '=',
                    'value'   => ''
                ]
            ],
            [
                'relation' => 'or',
                [
                    'key'     => '_payment_method',
                    'compare' => '=',
                    'value'   => 'laskuhari'
                ],
                [
                    'key'     => '_payment_method',
                    'compare' => 'NOT EXISTS'
                ],
                [
                    'key'     => '_payment_method',
                    'compare' => '=',
                    'value'   => ''
                ]
            ]
        ],
        "lasku_luotu" => [
            'relation' => 'and',
            [
                'key'     => '_laskuhari_invoice_number',
                'compare' => '>',
                'value'   => "0",
            ],
            [
                'key'     => '_laskuhari_sent',
                'compare' => '!=',
                'value'   => "yes",
            ]
        ],
        "maksettu" => [
            'relation' => 'and',
            [
                'key'     => '_laskuhari_invoice_number',
                'compare' => '>',
                'value'   => "0",
            ],
            [
                'key'     => '_laskuhari_payment_status',
                'compare' => '=',
                'value'   => "1",
            ]
        ],
        "ei_maksettu" => [
            'relation' => 'and',
            [
                'key'     => '_laskuhari_invoice_number',
                'compare' => '>',
                'value'   => "0",
            ],
            [
                'key'     => '_laskuhari_payment_status',
                'compare' => '!=',
                'value'   => "1",
            ]
        ]
    ];

    if( $query->is_admin && $pagenow == 'edit.php' && isset( $_GET['filter_laskuhari_status'] ) && $_GET['post_type'] == 'shop_order' ) {
        if( ! isset( $status_queries[$_GET['filter_laskuhari_status']] ) ) {
            return;
        }
        $status_query = $status_queries[$_GET['filter_laskuhari_status']];
        $query->set( 'meta_query', $status_query );
    }

}

/**
 * Get list of einvoice operators
 *
 * @return array<string, array<string, string>> Associative array of operators. First level is operator type
 *                                              (operator or bank). Second level key is operator code
 *                                              and value is operator name.
 */
function laskuhari_operators() {
    return apply_filters( "laskuhari_operators", [
        "operators" => [
            "UTMOST"          => "4US Oy (UTMOST)",
            "003723327487"    => "Apix Messaging Oy (003723327487)",
            "APPER"           => "Apper Systems AB (APPER)",
            "BAWCFI22"        => "Basware Oyj (BAWCFI22)",
            "5909000716438"   => "Comarch (5909000716438)",
            "CREDIFLOW"       => "Crediflow AB (CREDIFLOW)",
            "ROUTTY"          => "Dynatos (ROUTTY)",
            "885790000000418" => "HighJump AS (885790000000418)",
            "INEXCHANGE"      => "InExchange Factorum AB (INEXCHANGE)",
            "EXPSYS"          => "Kofax Sweden Services AB (EXPSYS)",
            "981012224"       => "LOGIQ AS (981012224)",
            "003721291126"    => "Maventa (003721291126)",
            "003726044706"    => "Netbox Finland Oy (003726044706)",
            "003708599126"    => "OpenText Oy (003708599126)",
            "E204503"         => "OpusCapita Solutions Oy (E204503)",
            "003723609900"    => "Pagero (003723609900)",
            "FI28768767"      => "Posti Messaging Oy (FI28768767)",
            "003701150617"    => "PostNord Strålfors Oy (003701150617)",
            "003714377140"    => "Ropo Suomi Oy (003714377140)",
            "003703575029"    => "Telia / CGI (003703575029)",
            "003701011385"    => "TietoEvry Oyj (003701011385)",
            "885060259470028" => "Tradeshift (885060259470028)",
            "003722207029"    => "Ålands Post Ab (003722207029)"
        ],
        "banks" => [
            "HELSFIHH"        => "Aktia (HELSFIHH)",
            "DABAFIHH"        => "Danske Bank (DABAFIHH)",
            "DNBAFIHX"        => "DNB (DNBAFIHX)",
            "HANDFIHH"        => "Handelsbanken (HANDFIHH)",
            "NDEAFIHH"        => "Nordea Pankki (NDEAFIHH)",
            "ITELFIHH"        => "Säästöpankit (ITELFIHH)",
            "OKOYFIHH"        => "Osuuspankit (OKOYFIHH)",
            "POPFFI22"        => "POP Pankki  (POPFFI22)",
            "SBANFIHH"        => "S-Pankki (SBANFIHH)",
            "TAPIFI22"        => "LähiTapiola (TAPIFI22)",
            "AABAFI22"        => "Ålandsbanken (AABAFI22)"
        ]
    ] );
}

function laskuhari_user_meta() {
    $operators = laskuhari_operators();
    $custom_meta_fields = array();
    $custom_meta_fields = array(
        array(
            "name"  => "laskuhari_laskutusasiakas",
            "title" => __( 'Laskutusasiakas', 'laskuhari' ),
            "type"  => "checkbox"
        ),
        array(
            "name"    => "laskuhari_payment_terms_default",
            "title"   => __( 'Maksuehto', 'laskuhari' ),
            "type"    => "select",
            "options" => apply_filters( "laskuhari_payment_terms_select_box", laskuhari_get_payment_terms() )
        ),
        array(
            "name"  => "_laskuhari_laskutustapa",
            "title" => __( 'Laskutustapa', 'laskuhari' ),
            "type"  => "select",
            "options" => [
                "" => "-- Valitse --",
                "email" => "Sähköposti",
                "verkkolasku" => "Verkkolasku",
                "kirje" => "Kirje"
            ]
        ),
        array(
            "name"  => "_laskuhari_billing_email",
            "title" => __( 'Laskutussähköposti', 'laskuhari' ),
            "type"  => "text"
        ),
        array(
            "name"  => "_laskuhari_ytunnus",
            "title" => __( 'Y-tunnus', 'laskuhari' ),
            "type"  => "text"
        ),
        array(
            "name"  => "_laskuhari_verkkolaskuosoite",
            "title" => __( 'Verkkolaskuosoite', 'laskuhari' ),
            "type"  => "text"
        ),
        array(
            "name"  => "_laskuhari_valittaja",
            "title" => __( 'Verkkolaskuoperaattori', 'laskuhari' ),
            "type"  => "select",
            "options" => array_merge(
                ["" => "-- Valitse --"],
                $operators['operators'],
                $operators['banks']
            )
        )
    );

    return $custom_meta_fields;
}

add_filter( "laskuhari_payment_terms_select_box", "laskuhari_payment_terms_select_box" );
function laskuhari_payment_terms_select_box( $terms ) {
    $output = array(
        "" => __( "Oletus", "laskuhari" )
    );
    foreach( $terms as $term ) {
        $output[$term['id']] = $term['nimi'];
    }
    return $output;
}

function laskuhari_user_profile_additional_info( $user ) {
    echo '<h3>Laskuhari</h3>'.
         '<table class="form-table">';

    $meta_number = 0;
    $custom_meta_fields = laskuhari_user_meta();

    foreach ( $custom_meta_fields as $meta_field ) {
        $meta_number++;

        $meta_disp_name   = $meta_field['title'];
        $meta_field_name  = $meta_field['name'];

        $current_value = get_user_meta( $user->ID, $meta_field_name, true );

        if( "checkbox" === $meta_field['type'] ) {
            if ( "yes" === $current_value ) {
                $author_meta_checked = "checked";
            } else {
                $author_meta_checked = "";
            }

            echo '
            <tr>
                <th>' . $meta_disp_name . '</th>
                <td>
                    <input type="checkbox" name="' . $meta_field_name . '"
                           id="' . $meta_field_name . '"
                           value="yes" ' . $author_meta_checked . ' />
                    <label for="' . $meta_field_name . '">Kyllä</label><br />
                    <span class="description"></span>
                </td>
            </tr>';
        } elseif( "text" === $meta_field['type'] ) {
            echo '
            <tr>
                <th>' . $meta_disp_name . '</th>
                <td>
                    <input type="text" name="' . $meta_field_name . '"
                           id="' . $meta_field_name . '"
                           value="' . esc_attr( $current_value ) . '" /><br />
                    <span class="description"></span>
                </td>
            </tr>';
        } elseif( "select" === $meta_field['type'] ) {
            echo '
            <tr>
                <th>' . $meta_disp_name . '</th>
                <td>
                    '.lh_create_select_box( $meta_field_name, $meta_field['options'], $current_value ).'<br />
                    <span class="description"></span>
                </td>
            </tr>';
        }
    }

    echo '</table>';
}

function laskuhari_update_user_meta( $user_id ) {

    if( ! current_user_can( 'edit_user', $user_id ) ) {
        return false;
    }

    $meta_number = 0;
    $custom_meta_fields = laskuhari_user_meta();

    foreach ( $custom_meta_fields as $meta_field ) {
        $meta_number++;
        $meta_field_name = $meta_field['name'];

        update_user_meta( $user_id, $meta_field_name, $_POST[$meta_field_name] );
    }
}

function laskuhari_get_customer_payment_terms_default( $customerID ) {
    return get_user_meta( $customerID, "laskuhari_payment_terms_default", true );
}

function laskuhari_common_vat_rates( $product = null ) {
    $common_vat_rates = [25.5, 24, 14, 10];
    $common_vat_rates = apply_filters( "laskuhari_common_vat_rates", $common_vat_rates, $product );

    return $common_vat_rates;
}

/**
 * Convert a calculated VAT rate into one of common VAT rates
 *
 * @param float $percent
 * @return float
 */
function laskuhari_vat_percent( $percent ) {
    $diff = [];

    // calculate difference between given percent and common vat rates
    foreach( laskuhari_common_vat_rates() as $vat_rate ) {
        $diff[(string)$vat_rate] = abs( $vat_rate - $percent );
    }

    // sort differences smallest to largest
    asort( $diff, SORT_NUMERIC );

    // if the difference is larger than 2 %
    if( current( $diff ) > 2 ) {
        return round( $percent, 2 ); // return original percent
    }

    // go back to first vat rate
    reset( $diff );

    return key( $diff ); // return closest common vat rate
}

function laskuhari_get_vat_rate( $product = null ) {
    $taxes = null;

    if( is_a( $product, 'WC_Product' ) ) {
        $taxes = WC_Tax::get_rates( $product->get_tax_class() );
    }

    if( null === $taxes || 0 === count( $taxes ) ) {
        $taxes = WC_Tax::get_rates();
    }

    $taxes = apply_filters( "laskuhari_taxes", $taxes, $product );

    if( null === $taxes || 0 === count( $taxes ) ) {
        return 0;
    }

    foreach( $taxes as $rate ) {
        $vat_rate = $rate['rate'];
        if( false !== stripos( $rate['label'], "arvonlis" ) || false !== stripos( $rate['label'], "alv" ) ) {
            break;
        }
    }

    $common_vat_rates = laskuhari_common_vat_rates( $product );

    foreach( $taxes as $rate ) {
        if( in_array( $rate['rate'], $common_vat_rates ) ) {
            $vat_rate = $rate['rate'];
            break;
        }
    }

    $vat_rate = apply_filters( "laskuhari_get_vat_rate", $vat_rate, $product );

    return $vat_rate;
}

function laskuhari_continue_only_on_actions( $hooks ) {
    if( ! is_array( $hooks ) ) {
        return true;
    }

    if( isset( $_POST['action'] ) && in_array( $_POST['action'], $hooks ) ) {
        return true;
    }

    foreach( $hooks as $hook ) {
        if( doing_action( $hook ) ) {
            return true;
        }
    }

    return false;
}

function laskuhari_sync_product_on_save( $product_id ) {
    $hooks = [
        'save_post',
        'woocommerce_ajax_save_product_variations',
        'woocommerce_add_variation',
        'woocommerce_save_variations',
        'woocommerce_bulk_edit_variations',
        'inline-save'
    ];

    $hooks = apply_filters( "laskuhari_which_hooks_to_sync_product", $hooks, $product_id );

    if( ! laskuhari_continue_only_on_actions( $hooks ) ) {
        Logger::enabled( 'debug' ) && Logger::log( sprintf(
            'Laskuhari: Not syncing product info on action %s',
            substr( preg_replace( '/[^a-zA-Z0-9 \._-]/', '', $_POST['action'] ), 0, 64 )
        ), 'debug' );

        return false;
    }

    $laskuhari_gateway_object = laskuhari_get_gateway_object();
    if( $laskuhari_gateway_object->synkronoi_varastosaldot ) {
        $updating_product_id = 'laskuhari_update_product_' . $product_id;
        if ( false === laskuhari_get_transient( $updating_product_id ) ) {
            Logger::enabled( 'debug' ) && Logger::log( sprintf(
                'Laskuhari: Syncing product %s to Laskuhari',
                $product_id
            ), 'debug' );

            set_transient( $updating_product_id, $product_id, 2 );
            laskuhari_product_synced( $product_id, 'no' );
            laskuhari_create_product_delayed( $product_id, true );
        } else {
            Logger::enabled( 'debug' ) && Logger::log( sprintf(
                'Laskuhari: Not syncing product %s to Laskuhari: transient active',
                $product_id
            ), 'debug' );
        }
    }
}

/**
 * Get the product price with and without tax
 *
 * @param WC_Product $product
 * @param ?float $vat
 * @return array<string, float>
 */
function laskuhari_get_product_price( $product, $vat = null ) {
    $prices_include_tax = get_option( 'woocommerce_prices_include_tax' ) == 'yes' ? true : false;

    $vat = $vat ?? laskuhari_get_vat_rate( $product );

    $vat_multiplier = (100 + $vat) / 100;

    if( $prices_include_tax ) {
        $price_with_tax = (float) $product->get_regular_price( 'edit' );
        if( ! $price_with_tax ) {
            $price_with_tax = 0;
        }
        $price_without_tax = $price_with_tax / $vat_multiplier;
    } else {
        $price_without_tax = (float) $product->get_regular_price( 'edit' );
        if( ! $price_without_tax ) {
            $price_without_tax = 0;
        }
        $price_with_tax = $price_without_tax * $vat_multiplier;
    }

    return [
        "price_with_tax" => $price_with_tax,
        "price_without_tax" => $price_without_tax,
        "prices_include_tax" => $prices_include_tax,
        "vat" => $vat,
    ];
}

function laskuhari_create_product_delayed( $product, $update = false ) {
    $product = laskuhari_just_the_product_id( $product );

    laskuhari_schedule_background_event( 'laskuhari_create_product_action', [$product, $update], true );
}

/**
 * Wrapper for running the laskuhari_create_product cron hook
 * (for logging when event is run)
 *
 * @return void
 */
function laskuhari_create_product_cron_hook() {
    Logger::enabled( 'info' ) && Logger::log( sprintf(
        'Laskuhari: Running cron hook %s',
        __FUNCTION__
    ), 'info' );

    $args = func_get_args();

    if( ! did_action( "woocommerce_after_register_post_type" ) ) {
        Logger::enabled( 'debug' ) && Logger::log( sprintf(
            'Laskuhari: WC post types not registered in %s',
            __FUNCTION__
        ), 'debug' );

        add_action( "woocommerce_after_register_post_type", function() use ( $args ) {
            laskuhari_create_product( ...$args );
        } );
    } else {
        laskuhari_create_product( ...$args );
    }
}

function laskuhari_create_product( $product, $update = false ) {
    if( ! post_type_exists( "product" ) ) {
        Logger::enabled( 'warn' ) && Logger::log( sprintf(
            'Laskuhari: Product post type does not exist in %s',
            __FUNCTION__
        ), 'warn' );
        return false;
    }

    if( ! is_a( $product, WC_Product::class ) ) {
        $product_id = intval( $product );
        $product    = wc_get_product( $product_id );
    }

    if( ! $product ) {
        Logger::enabled( 'error' ) && Logger::log( sprintf(
            'Laskuhari: Product ID %d not found for product creation',
            $product_id
        ), 'error' );
        return false;
    }

    $product_id = $product->get_id();

    if( false === $update && laskuhari_product_synced( $product_id ) ) {
        return false;
    }

    if( $product->is_type( 'variation' ) ) {
        $product_id   = $product->get_parent_id();
        $variation_id = $product->get_id();
    } else {
        $product_id   = $product->get_id();
        $variation_id = 0;
    }

    $api_url = "https://" . laskuhari_domain() . "/rest-api/tuote/uusi";

    $api_url = apply_filters( "laskuhari_create_product_api_url", $api_url, $product );

    $price = laskuhari_get_product_price( $product );

    $price_with_tax = $price["price_with_tax"];
    $price_without_tax = $price["price_without_tax"];
    $vat = $price["vat"];
    $prices_include_tax = $price["prices_include_tax"];

    $payload = [
        "koodi" => $product->get_sku(),
        "nimike" => $product->get_name(),
        "ylatuote" => 0,
        "viivakoodi" => [
            "tyyppi" => "",
            "koodi" => ""
        ],
        "kommentti" => "",
        "ostohinta" => [
            "veroton" => 0,
            "alv" => $vat,
            "verollinen" => 0
        ],
        "myyntihinta" => [
            "veroton" => $price_without_tax,
            "alv" => $vat,
            "verollinen" => $price_with_tax
        ],
        "woocommerce" => [
            "wc_product_id" => $product_id,
            "wc_variation_id" => $variation_id,
            "prices_include_tax" => $prices_include_tax
        ],
        "varastosaldo" => $product->get_stock_quantity( 'edit' ),
        "varastoseuranta" => $product->get_manage_stock(),
        "halytysraja" => $product->get_low_stock_amount( 'edit' ),
        "varasto" => "",
        "hyllypaikka" => "",
        "maara" => 1,
        "yksikko" => "",
        "toistovali" => 0,
        "ennakko" => 0
    ];

    if( true === $update ) {
        $payload['korvaa_tuotteet'] = true;
    }

    $payload = apply_filters( "laskuhari_create_product_payload", $payload, $product );

    $payload = json_encode( $payload, laskuhari_json_flag() );

    $response = laskuhari_api_request( $payload, $api_url, "Product creation" );

    if( $response === false ) {
        Logger::enabled( 'error' ) && Logger::log( sprintf(
            'Laskuhari: Unknown error in syncing product %s',
            $product_id
        ), 'error' );
        return false;
    } else {
        Logger::enabled( 'debug' ) && Logger::log( sprintf(
            'Laskuhari: Product %s sync complete',
            $product_id
        ), 'debug' );
    }

    $product_id = $product->get_id();

    laskuhari_product_synced( $product_id, "yes" );

    return true;
}

function laskuhari_product_synced( $product, $set = null ) {
    if( ! post_type_exists( "product" ) ) {
        Logger::enabled( 'warn' ) && Logger::log( sprintf(
            'Laskuhari: Product post type does not exist in %s',
            __FUNCTION__
        ), 'warn' );
        return false;
    }

    if( ! is_a( $product, WC_Product::class ) ) {
        $product_id = intval( $product );
        $product    = wc_get_product( $product_id );
    }

    if( ! is_a( $product, WC_Product::class ) ) {
        Logger::enabled( 'error' ) && Logger::log( sprintf(
            'Laskuhari: Product ID %s not found for sync check',
            $product_id
        ), 'error' );
        return false;
    }

    if( $set !== null ) {
        update_post_meta( $product->get_id(), '_laskuhari_synced', $set );
        return $set;
    }

    return laskuhari_get_post_meta( $product->get_id(), '_laskuhari_synced', true ) === "yes";
}

function laskuhari_update_stock_delayed( $product ) {
    $product = laskuhari_just_the_product_id( $product );

    laskuhari_schedule_background_event( 'laskuhari_update_stock_action', [$product], true );
}

/**
 * Wrapper for running the laskuhari_update_stock cron hook
 * (for logging when event is run)
 *
 * @return void
 */
function laskuhari_update_stock_cron_hook() {
    Logger::enabled( 'info' ) && Logger::log( sprintf(
        'Laskuhari: Running cron hook %s',
        __FUNCTION__
    ), 'info' );

    $args = func_get_args();

    if( ! did_action( "woocommerce_after_register_post_type" ) ) {
        Logger::enabled( 'debug' ) && Logger::log( sprintf(
            'Laskuhari: WC post types not registered in %s',
            __FUNCTION__
        ), 'debug' );

        add_action( "woocommerce_after_register_post_type", function() use ( $args ) {
            laskuhari_update_stock( ...$args );
        } );
    } else {
        laskuhari_update_stock( ...$args );
    }
}

function laskuhari_update_stock( $product ) {
    if( ! post_type_exists( "product" ) ) {
        Logger::enabled( 'warn' ) && Logger::log( sprintf(
            'Laskuhari: Product post type does not exist in %s',
            __FUNCTION__
        ), 'warn' );
        return false;
    }

    if( ! is_a( $product, WC_Product::class ) ) {
        $product_id = intval( $product );
        $product    = wc_get_product( $product_id );
    }

    if( ! is_a( $product, WC_Product::class ) ) {
        Logger::enabled( 'error' ) && Logger::log( sprintf(
            'Laskuhari: Product ID %s not found for stock update',
            $product_id
        ), 'error' );
        return false;
    }

    $product_id   = $product->get_id();
    $variation_id = 0;

    if( ! laskuhari_product_synced( $product_id ) ) {
        return false;
    }

    if( $product->is_type( 'variation' ) ) {
        $product_id   = $product->get_parent_id();
        $variation_id = $product->get_id();
    }

    $stock_quantity = $product->get_stock_quantity( 'edit' );

    $api_url = "https://" . laskuhari_domain() . "/rest-api/tuote/varastosaldo/";

    $api_url = apply_filters( "laskuhari_stock_update_api_url", $api_url, $product );

    $payload = [
        "woocommerce" => [
            "wc_product_id"  => $product_id,
            "wc_variation_id" => $variation_id
        ],
        "varastosaldo" => [
            "aseta" => $stock_quantity
        ]
    ];

    $payload = apply_filters( "laskuhari_stock_update_payload", $payload, $product );

    $payload = json_encode( $payload, laskuhari_json_flag() );

    $response = laskuhari_api_request( $payload, $api_url, "Stock update" );

    if( $response === false ) {
        return false;
    }

    if( ! isset( $response['varastosaldo'] ) || $response['varastosaldo'] != $stock_quantity ) {
        Logger::enabled( 'error' ) && Logger::log( sprintf(
            'Laskuhari: Stock update did not work for product ID %s',
            $product_id
        ), 'error' );
        return false;
    }

    Logger::enabled( 'debug' ) && Logger::log( sprintf(
        'Laskuhari: Stock update for product %s complete',
        $product_id
    ), 'debug' );

    return true;
}

function laskuhari_add_webhook( $event, $url ) {
    $api_url = "https://" . laskuhari_domain() . "/rest-api/webhooks/";

    $api_url = apply_filters( "laskuhari_webhooks_api_url", $api_url, $event, $url );

    $payload = [
        "event" => $event,
        "url" => $url
    ];

    $payload = apply_filters( "laskuhari_add_webhook_payload", $payload, $event, $url );

    $payload = json_encode( $payload, laskuhari_json_flag() );

    $response = laskuhari_api_request( $payload, $api_url, "Add webhook" );

    if( $response === false ) {
        Logger::enabled( 'error' ) && Logger::log( sprintf(
            'Laskuhari: Failed to add webhook'
        ), 'error' );
        return false;
    }

    return true;
}

function laskuhari_set_invoice_sent_status( $invoice_id, $sent, $send_date = false ) {
    $api_url = "https://" . laskuhari_domain() . "/rest-api/lasku/".$invoice_id."/lahetetty";

    $api_url = apply_filters( "laskuhari_invoice_sent_status_api_url", $api_url, $invoice_id, $sent, $send_date );

    $payload = [
        "lahetetty" => $sent
    ];

    if( $send_date !== false ) {
        $payload["lahetyspaiva"] = $send_date;
    }

    $payload = apply_filters( "laskuhari_set_invoice_sent_status_payload", $payload, $sent, $send_date );

    $payload = json_encode( $payload, laskuhari_json_flag() );

    $response = laskuhari_api_request( $payload, $api_url, "Set invoice sent status" );

    if( $response === false ) {
        Logger::enabled( 'error' ) && Logger::log( sprintf(
            'Laskuhari: Failed to set invoice sent status'
        ), 'error' );

        return false;
    }

    return true;
}

// Lisää "Kirjaudu Laskuhariin" -linkki lisäosan tietoihin

function laskuhari_register_plugin_links( $links, $file ) {
    $base = plugin_basename( __FILE__ );

    if( $file == $base ) {
        $links[] = '<a href="https://' . laskuhari_domain() . '/" target="_blank">' . __( 'Kirjaudu Laskuhariin', 'laskuhari' ) . '</a>';
    }

    return $links;
}


// Lisää Laskuhari-sarake tilauslistaan

function laskuhari_add_column_to_order_list( $columns ) {
    $columns['laskuhari'] = 'Laskuhari';
    return $columns;
}

// Lisää Laskuhari-sarakkeeseen tilauksen laskutustila

function laskuhari_add_invoice_status_to_custom_order_list_column( $column, $order = null ) {
    if( $order ) {
        // HPOS
        $order_id = $order->get_id();
    } else {
        // Legacy
        global $post;
        $order_id = $post->ID;
    }

    if( 'laskuhari' === $column ) {
        $data = laskuhari_invoice_status( $order_id );
        if( $data['tila'] == "LASKUTETTU" ) {
            $status = "processing";
        } elseif( $data['tila'] == "LASKU LUOTU" ) {
            $status = "on-hold";
        } else {
            $status = "pending";
            $laskutustapa = laskuhari_get_post_meta( $order_id, '_payment_method', true );
            if( $laskutustapa != "laskuhari" ) {
                echo '-';
                return;
            }
        }

        $status_name = $data['tila'];

        $payment_status = laskuhari_order_payment_status( $order_id );

        if( "laskuhari-paid" === $payment_status['payment_status_class'] ) {
            $status_name = strtoupper( $payment_status['payment_status_name'] );
        }

        echo '<span class="order-status status-'.$status.' '.$payment_status['payment_status_class'].'"><span>' . __( $status_name, 'laskuhari' ) . '</span></span>';
    }
}

function laskuhari_add_bulk_action_for_invoicing( $actions ) {
    $nonce = Laskuhari_Nonce::create();
    $actions['laskuhari_batch_send_'.$nonce] = __( 'Luo ja lähetä laskut valituista tilauksista (Laskuhari)', 'laskuhari' );
    $actions['laskuhari_batch_create_'.$nonce] = __( 'Luo laskut valituista tilauksista (älä lähetä) (Laskuhari)', 'laskuhari' );
    return $actions;
}

function is_laskuhari_allowed_order_status( $status ) {
    return in_array( $status, [
        "processing",
        "completed",
        "on-hold"
    ] );
}

function laskuhari_handle_bulk_actions( $redirect_to, $action, $order_ids ) {
    if( ! is_admin() || ! current_user_can( 'edit_shop_orders' ) ) {
        return false;
    }

    $nonce = substr( $action, strrpos( $action, '_' ) + 1 );
    $action = substr( $action, 0, strrpos( $action, '_' ) );

    Laskuhari_Nonce::verify( $nonce );

    $allowed_actions = [
        "laskuhari_batch_send",
        "laskuhari_batch_create",
    ];

    if ( ! in_array( $action, $allowed_actions ) ) {
        return $redirect_to;
    }

    $send = $action === "laskuhari_batch_send";

    $data = array();

    $order_ids = array_unique( array_map( 'intval', $order_ids ) );

    foreach( $order_ids as $order_id ) {
        $order = wc_get_order( $order_id );

        if( ! $order ) {
            $data["notice"][] = __( sprintf( "Tilausta #%d ei löytynyt", $order_id ), 'laskuhari' );
            Logger::enabled( 'error' ) && Logger::log( sprintf(
                'Laskuhari: Could not find order ID %s in bulk invoice action',
                $order_id
            ), 'error' );
            continue;
        }

        $order_status = $order->get_status();

        if( ! is_laskuhari_allowed_order_status( $order_status ) ) {
            $data = array();
            $data["notice"][] = __( 'Tilausten tulee olla Käsittelyssä- tai Valmis-tilassa, ennen kuin ne voidaan laskuttaa.', 'laskuhari' );

            Logger::enabled( 'debug' ) && Logger::log( sprintf(
                'Laskuhari: Order %s in status %s can not be invoiced',
                $order_id,
                $order_status
            ), 'debug' );

            return laskuhari_back_url( $data, $redirect_to );
        }
    }

    foreach( $order_ids as $order_id ) {
        $send   = apply_filters( "laskuhari_bulk_action_send", $send, $order_id );
        $lh     = laskuhari_process_action( $order_id, $send, true );
        $data[] = $lh;
    }

    $back_url = laskuhari_back_url( $data, $redirect_to );

    $back_url = apply_filters( "laskuhari_return_url_after_bulk_action", $back_url, $order_ids );

    return $back_url;
}

function laskuhari_vat_number_fields() {
    return [
        "y_tunnus",
        "ytunnus",
        "y-tunnus",
        "vat_id",
        "vatid",
        "vat-id",
        "business_id",
        "businessid",
        "alv_tunnus",
        "alv-tunnus",
        "alvtunnus",
    ];
}

function laskuhari_vat_id_at_checkout() {
    $vat_number_fields = laskuhari_vat_number_fields();
    foreach( $_REQUEST as $key => $value ) {
        foreach( $vat_number_fields as $field_name ) {
            if( mb_stripos( $key, $field_name ) !== false ) {
                return $value;
            }
        }
    }
    return "";
}

function lh_is_vat_id_field( $field ) {
    $vat_number_fields = laskuhari_vat_number_fields();
    foreach( $vat_number_fields as $field_name ) {
        if( mb_stripos( $field, $field_name ) !== false ) {
            return true;
        }
    }
    return false;
}

function laskuhari_custom_billing_email_fields() {
    return [
        "laskutusemail",
        "laskutussahkoposti",
        "laskutus_email",
        "laskutus_sahkoposti",
        "laskuhari_billing_email"
    ];
}

function laskuhari_custom_billing_email_at_checkout() {
    $fields = laskuhari_custom_billing_email_fields();
    foreach( $_REQUEST as $key => $value ) {
        foreach( $fields as $field_name ) {
            if( mb_stripos( $key, $field_name ) !== false ) {
                return $value;
            }
        }
    }
    return "";
}

function lh_is_custom_billing_email_field( $field ) {
    $fields = laskuhari_custom_billing_email_fields();
    foreach( $fields as $field_name ) {
        if( mb_stripos( $field, $field_name ) !== false ) {
            return true;
        }
    }
    return false;
}

// Anna ilmoitus puutteellisista verkkolaskutiedoista kassasivulla
function laskuhari_einvoice_notices( $fields, $errors ) {
    if( $_POST['payment_method'] == "laskuhari" ) {
        if( empty( $_POST['laskuhari-laskutustapa'] ) ) {
            $errors->add( 'validation', __( 'Ole hyvä ja valitse laskutustapa' ) );
        } else {
            $vat_id = laskuhari_vat_id_at_checkout();
            $vat_id_required = in_array( $_POST['laskuhari-laskutustapa'], laskuhari_vat_id_mandatory_for_methods() );

            if( $vat_id_required && ! laskuhari_is_valid_vat_id( $vat_id ) ) {
                $method_name = laskuhari_method_name_by_slug( $_POST['laskuhari-laskutustapa'] );
                $errors->add( 'validation', sprintf( __( 'Y-tunnus on pakollinen %s-laskutustavalla', 'laskuhari' ), $method_name ) );
            }

            if( $_POST['laskuhari-laskutustapa'] === "verkkolasku" ) {
                try {
                    $verkkolaskuosoite = $_POST['laskuhari-verkkolaskuosoite'];
                    $valittaja = $_POST['laskuhari-valittaja'];

                    FinvoiceValidator::validate_finvoice_address( $verkkolaskuosoite, $valittaja, $vat_id );
                } catch( FinvoiceException $e ) {
                    $errors->add( 'validation', sprintf( __( 'Virheelliset verkkolaskutiedot: %s', 'laskuhari' ), $e->getMessage() ) );

                    Logger::enabled( 'info' ) && Logger::log( sprintf(
                        'Laskuhari: Invalid e-invoice address at checkout: %s (%s/%s)',
                        $e->getMessage(),
                        $verkkolaskuosoite,
                        $valittaja
                    ), 'info' );
                }
            }
        }
    }
}

// Check if a VAT ID is valid

function laskuhari_is_valid_vat_id( $vat_id ) {
    $is_valid = mb_strlen( laskuhari_vat_id_at_checkout() ) >= 6;
    return apply_filters( "laskuhari_is_valid_vat_id", $is_valid, $vat_id );
}

// Set which invoicing methods require VAT ID

function laskuhari_vat_id_mandatory_for_methods() {
    return apply_filters( "laskuhari_vat_id_mandatory_for_methods", [
        "verkkolasku",
        //"kirje",
        //"email",
    ] );
}

// Return name of invoicing method by slug

function laskuhari_method_name_by_slug( $slug ) {
    $names = apply_filters( "laskuhari_method_names_by_slug", [
        "verkkolasku" => "verkkolasku",
        "kirje" => "kirje",
        "email" => "sähköposti",
    ] );

    return $names[$slug];
}

// add a separate vat id field to billing details if vat id
// is required for other invoicing methods than eInvoice

function laskuhari_maybe_add_vat_id_field() {
    $priority = apply_filters( "laskuhari_woocommerce_billing_fields_filter_priority", 1100 );

    add_filter( 'woocommerce_billing_fields', function( $fields ) {
        if( laskuhari_vat_id_custom_field_exists( ["billing" => $fields] ) ) {
            return $fields;
        }

        $mandatory_for_methods = laskuhari_vat_id_mandatory_for_methods();

        // insert vat id field after field with this key
        $insert_after = apply_filters( "laskuhari_insert_vat_id_after_field", "billing_company", $fields );

        // vat id field details
        $billing_field = apply_filters( "laskuhari_vat_id_field", [
            'label' => __('Y-tunnus', 'laskuhari'),
            'required' => false,
            'type' => 'text',
        ] );

        foreach( $mandatory_for_methods as $method ) {
            // if a method other than eInvoice is found
            if( $method !== "verkkolasku" ) {
                $new_fields = [];

                // insert vat id field after specified field
                foreach( $fields as $name => $data ) {
                    $new_fields[$name] = $data;
                    if( $name === $insert_after ) {
                        $new_fields['billing_ytunnus'] = $billing_field;
                    }
                }

                // if specified field was not found, add field to the end
                if( ! isset( $new_fields['billing_ytunnus'] ) ) {
                    $new_fields['billing_ytunnus'] = $billing_field;
                }

                return $new_fields;
            }
        }

        return $fields;
    }, $priority );
}

// Päivitä Laskuharista tuleva metadata

function laskuhari_checkout_update_order_meta( $order_id ) {
    if( $_POST['payment_method'] != "laskuhari" ) {
        return false;
    }
    laskuhari_update_order_meta( $order_id );
}

function laskuhari_reset_order_metadata( $order_id ) {
    laskuhari_update_payment_status( $order_id, "", "", "" );
    laskuhari_set_order_meta( $order_id, '_laskuhari_payment_terms_name', "" );
    laskuhari_set_order_meta( $order_id, '_laskuhari_payment_terms', "" );
    laskuhari_set_order_meta( $order_id, '_laskuhari_sent', "" );
    laskuhari_set_order_meta( $order_id, '_laskuhari_invoice_number', "" );
    laskuhari_set_order_meta( $order_id, '_laskuhari_invoice_id', "" );
    laskuhari_set_order_meta( $order_id, '_laskuhari_uid', "" );
}

function laskuhari_set_order_meta( $order_id, $meta_key, $meta_value, $update_user_meta = false ) {
    $order = wc_get_order( $order_id );

    if( ! $order ) {
        Logger::enabled( 'error' ) && Logger::log( sprintf(
            'Laskuhari: Could not find order ID %s in set order meta',
            $order_id
        ), 'error' );
        return false;
    }

    // update order meta
    $order->update_meta_data( $meta_key, sanitize_meta( $meta_key, $meta_value, 'post' ) );
    $order->save_meta_data();

    if( $update_user_meta ) {
        $user = $order->get_user();

        // update user meta if it's not set already
        if( $user && ! $user->get( $meta_key ) ) {
            update_user_meta( $user->ID, $meta_key, sanitize_meta( $meta_key, $meta_value, 'user' ) );
        }
    }
}

function get_laskuhari_meta( $order_id, $meta_key, $single = true ) {
    $post_meta = laskuhari_get_post_meta( $order_id, $meta_key, $single );
    if( ! empty( $post_meta ) ) {
        return $post_meta;
    }
    if( $order = wc_get_order( $order_id ) ) {
        $user_id = $order->get_user_id();
    } elseif( is_checkout() ) {
        $user_id = get_current_user_id();
    } else {
        return false;
    }

    // for compatibility with WooCommerce Address Book plugin
    $address_book_prefix = "";
    if( isset( $_POST['post_data'] ) ) {
        parse_str( $_POST['post_data'], $data );

        if( isset( $data['billing_address_book'] ) ) {
            $address_book_prefix = $data['billing_address_book']."_";
        }
    }

    return get_user_meta( $user_id, $address_book_prefix.$meta_key, $single );
}

// Lisää tilauslomakkessa annetut lisätiedot metadataan

function laskuhari_update_order_meta( $order_id )  {
    $ytunnus = laskuhari_vat_id_at_checkout();
    if ( ! empty( $ytunnus ) ) {
        laskuhari_set_order_meta( $order_id, '_laskuhari_ytunnus', $ytunnus, true );
    }

    $billing_email = laskuhari_custom_billing_email_at_checkout();
    if ( isset( $billing_email ) ) {
        laskuhari_set_order_meta( $order_id, '_laskuhari_email', $billing_email, true );
    }

    if ( isset( $_REQUEST['laskuhari-laskutustapa'] ) ) {
        laskuhari_set_order_meta( $order_id, "_laskuhari_laskutustapa", $_REQUEST['laskuhari-laskutustapa'], true );
    }
    if ( isset( $_REQUEST['laskuhari-verkkolaskuosoite'] ) ) {
        laskuhari_set_order_meta( $order_id, '_laskuhari_verkkolaskuosoite', $_REQUEST['laskuhari-verkkolaskuosoite'], true );
    }
    if ( isset( $_REQUEST['laskuhari-email'] ) ) {
        laskuhari_set_order_meta( $order_id, '_laskuhari_email', $_REQUEST['laskuhari-email'], false );
    }
    if ( isset( $_REQUEST['laskuhari-valittaja'] ) ) {
        laskuhari_set_order_meta( $order_id, '_laskuhari_valittaja', $_REQUEST['laskuhari-valittaja'], true );
    }
    if ( isset( $_REQUEST['laskuhari-viitteenne'] ) ) {
        laskuhari_set_order_meta( $order_id, '_laskuhari_viitteenne', $_REQUEST['laskuhari-viitteenne'], false );
    }
}

function laskuhari_vat_id_custom_field_exists( $field_data = null ) {
    if( null === $field_data ) {
        $field_data = WC()->checkout->get_checkout_fields();
    }
    foreach( $field_data as $type => $fields ) {
        foreach( $fields as $field_name => $field_settings ) {
            if( lh_is_vat_id_field( $field_name ) ) {
                return true;
            }
        }
    }
    return false;
}

function laskuhari_custom_billing_email_field_exists() {
    $field_data = WC()->checkout->get_checkout_fields();
    foreach( $field_data as $type => $fields ) {
        foreach( $fields as $field_name => $field_settings ) {
            if( lh_is_custom_billing_email_field( $field_name ) ) {
                return true;
            }
        }
    }
    return false;
}

// Luo meta-laatikko Laskuharin toiminnoille tilauksen sivulle

function laskuhari_metabox() {
    if( ! is_admin() ) {
        return false;
    }

    if( isset( $_GET['action'] ) && $_GET['action'] == "edit" ) {
        add_meta_box(
            'laskuhari_metabox',       // Unique ID
            'Laskuhari',               // Box title
            'laskuhari_metabox_html',  // Content callback
            wc_get_page_screen_id( 'shop-order' ),
            'side',
            'core'
        );
    }
}

/**
 * Custom version of get_post_meta that flushes the cache
 * before getting post meta and also fetches from the
 * WooCommerce HPOS meta if not found in post meta
 *
 * @param int $post_id
 * @param string $key
 * @param boolean $single
 * @return mixed
 */
function laskuhari_get_post_meta( $post_id, $key, $single = true ) {
    wp_cache_flush();
    $post_meta = get_post_meta( $post_id, $key, $single );

    if( empty( $post_meta ) ) {
        $order = wc_get_order( $post_id );
        if( $order ) {
            $post_meta = $order->get_meta( $key, $single );
        }
    }

    return $post_meta;
}

/**
 * Custom version of get_transient that flushes the cache
 * before getting the transient
 *
 * @param string $transient
 * @return mixed
 */
function laskuhari_get_transient( $transient ) {
    wp_cache_flush();
    return get_transient( $transient );
}

function laskuhari_invoice_is_created_from_order( $order_id ) {
    return !! laskuhari_get_post_meta( $order_id, '_laskuhari_invoice_number', true );
}

// Hae tilauksen laskutustila

function laskuhari_invoice_status( $order_id ) {
    laskuhari_maybe_process_queued_invoice( $order_id );

    $laskunumero = laskuhari_get_post_meta( $order_id, '_laskuhari_invoice_number', true );
    $lahetetty   = laskuhari_get_post_meta( $order_id, '_laskuhari_sent', true ) == "yes";
    $queued      = laskuhari_get_post_meta( $order_id, '_laskuhari_queued', true ) === "yes";

    if( $laskunumero > 0 ) {
        $lasku_luotu = true;
        $tila        = "LASKU LUOTU";
        $tila_class  = " luotu";

        if( $lahetetty ) {
            $tila       = "LASKUTETTU";
            $tila_class = " laskutettu";
        }
    } else {
        $lasku_luotu = false;
        $tila        = "EI LASKUTETTU";
        $tila_class  = " ei-laskutettu";
    }

    if( $queued ) {
        $tila = "JONOSSA";
    }

    return [
        "lasku_luotu" => $lasku_luotu,
        "tila"        => $tila,
        "tila_class"  => $tila_class,
        "lahetetty"   => $lahetetty,
        "laskunumero" => $laskunumero
    ];
}

function laskuhari_order_payment_status( $order_id ) {
    $payment_status      = laskuhari_get_post_meta( $order_id, '_laskuhari_payment_status', true );
    $payment_status_name = laskuhari_get_post_meta( $order_id, '_laskuhari_payment_status_name', true );

    if ( 1 == $payment_status ) {
        $payment_status_class = "laskuhari-paid";
    } else {
        $payment_status_class = "laskuhari-not-paid";
    }

    return array(
        "payment_status_class" => $payment_status_class,
        "payment_status_name"  => $payment_status_name,
    );
}


// Luo metaboxin HTML

function laskuhari_metabox_html( $post ) {
    if( ! is_admin() ) {
        return false;
    }

    $laskuhari_gateway_object = laskuhari_get_gateway_object();

    $tiladata    = laskuhari_invoice_status( $post->ID );
    $tila        = $tiladata['tila'];
    $tila_class  = $tiladata['tila_class'];
    $lahetetty   = $tiladata['lahetetty'];
    $laskunumero = $tiladata['laskunumero'];
    $lasku_luotu = $tiladata['lasku_luotu'];

    $maksutapa     = laskuhari_get_post_meta( $post->ID, '_payment_method', true );
    $maksuehto     = laskuhari_get_post_meta( $post->ID, '_laskuhari_payment_terms', true );
    $maksuehtonimi = laskuhari_get_post_meta( $post->ID, '_laskuhari_payment_terms_name', true );
    $maksutapa_ei_laskuhari = $maksutapa && $maksutapa != "laskuhari" && $tila == "EI LASKUTETTU";

    if( $maksutapa_ei_laskuhari ) {
        echo '<b>' . __( 'HUOM! Tämän tilauksen maksutapa ei ole Laskuhari.', 'laskuhari' ) . '</b><br />';
        echo '<a class="laskuhari-nappi" href="#" onclick="if(confirm(\'HUOM! Tämän tilauksen maksutapa ei ole Laskuhari! Haluatko jatkaa?\')) {jQuery(\'.laskuhari-laskutoiminnot\').show(); jQuery(this).hide();} return false;">Näytä laskutoiminnot</a>';
        echo '<div class="laskuhari-laskutoiminnot" style="display: none">';
    }
    ?>
    <div class="laskuhari-tila<?php echo $tila_class; ?>"><?php echo __($tila, 'laskuhari'); ?></div>
    <?php
    $order = wc_get_order( $post->ID );
    if( $order && ! is_laskuhari_allowed_order_status ( $order->get_status() ) ) {
        echo __( 'Tilauksen statuksen täytyy olla Käsittelyssä tai Valmis, jotta voit laskuttaa sen.', 'laskuhari' );
    } else {
        $edit_link = $order->get_edit_order_url();
        $payment_terms = laskuhari_get_payment_terms();
        $payment_terms_select = "";
        if( is_array( $payment_terms ) && count( $payment_terms ) ) {
            $update_terms_link = '<a href="'.$edit_link.'&laskuhari_action=fetch_payment_terms"
                                     class="laskuhari-fetch-payment-terms"
                                     title="Hae uudelleen">&#8635;</a>';
            $payment_terms_select = '<b>'.__( 'Maksuehto', 'laskuhari' ).$update_terms_link.'</b><br />'.
                                    '<select name="laskuhari-maksuehto" id="laskuhari-maksuehto"><option value="">-- Valitse maksuehto --</option>';

            $payment_terms_default = laskuhari_get_customer_payment_terms_default( $order->get_customer_id() );

            if( ! $payment_terms_default ) {
                $payment_terms_default = $maksuehto;
            }

            foreach( $payment_terms as $term ) {
                if( $term['oletus'] && ! $payment_terms_default ) {
                    $default = ' selected';
                } elseif( $term['id'] == $payment_terms_default ) {
                    $default = ' selected';
                } else {
                    $default = '';
                }
                $payment_terms_select .= '<option value="'.esc_attr( $term['id'] ).'" '.$default.'>'.esc_html( $term['nimi'] ).'</option>';
            }
            $payment_terms_select .= '</select>';
        }

        $luo_teksti          = "Luo lasku";
        $luo_varoitus        = __( 'Haluatko varmasti luoda laskun tästä tilauksesta?', 'laskuhari' );
        $luo_ja_laheta       = __( "Luo ja lähetä lasku", "laskuhari" );
        $luo_laheta_varoitus = __( "Haluatko varmasti laskuttaa tämän tilauksen?", "laskuhari" );

        $invoicing_address = laskuhari_get_invoicing_address( $order );

        $missing_field = null;
        if( empty( $invoicing_address["lahiosoite"][0] ) && empty( $invoicing_address["lahiosoite"][1] ) ) {
            $missing_field = __( "lähiosoite", "laskuhari" );
        } elseif( empty( $invoicing_address["postinumero"] ) ) {
            $missing_field = __( "postinumero", "laskuhari" );
        } elseif( empty( $invoicing_address["postitoimipaikka"] ) ) {
            $missing_field = __( "postitoimipaikka", "laskuhari" );
        }

        $warning = null;
        if( $missing_field ) {
            $warning =  sprintf( __( "HUOM! Tilaukselta puuttuu %s, joten laskua ei voi lähettää kirjeenä eikä verkkolaskuna. Haluatko jatkaa?", "laskuhari" ), $missing_field );
            $warning_email =  sprintf( __( "HUOM! Tilaukselta puuttuu %s. Haluatko jatkaa?", "laskuhari" ), $missing_field );
            $warning_einvoice_letter =  sprintf( __( "Tilaukselta puuttuu %s. Laskua ei voida lähettää.", "laskuhari" ), $missing_field );
        }

        $warning_confirm = $warning ? "if( ! laskuhari_no_address_confirm( '".esc_attr( $warning )."' ) ) {return false;}" : "";
        $send_warning_confirm = $warning_email ? "if( ! laskuhari_no_address_confirm_send( '".esc_attr( $warning_email )."', '".esc_attr($warning_einvoice_letter)."' ) ) {return false;}" : "";

        $laskuhari = $laskuhari_gateway_object;
        if( $lasku_luotu ) {

            $invoice_id = laskuhari_invoice_id_by_order( $order->get_id() );
            if( $invoice_id ) {
                $open_link = '?avaa=' . $invoice_id;
                $status = laskuhari_order_payment_status( $order->get_id() );

                $update_title = __( 'Päivitä', 'laskuhari' );
                $update_link  = '<a href="'.$edit_link.'&laskuhari_action=update_metadata"
                                    class="laskuhari-update-payment-status"
                                    title="'.$update_title.'">&#8635;</a>';

                echo '<div class="laskuhari-payment-status '.$status['payment_status_class'].'">'.esc_html( $status['payment_status_name'] ).$update_link.'</div>';
            } else {
                $open_link = '?avaanro=' . $laskunumero;
                $status = false;
            }

            if( $maksuehtonimi ) {
                echo '<div class="laskuhari-payment-terms-name">'.esc_html( $maksuehtonimi ).'</div>';
            }

            $download_link = $edit_link . '&laskuhari_download=current&laskuhari_template=';

            echo '
            <div class="laskuhari-laskunumero">' . __( 'Lasku', 'laskuhari' ) . ' ' . $laskunumero.'</div>
            <a class="laskuhari-nappi lataa-lasku laskuhari-with-sidebutton" href="' . $edit_link . '&laskuhari_download=current" target="_blank">' . __( 'Lataa PDF', 'laskuhari' ) . '</a>
            <a class="laskuhari-nappi lataa-pdf laskuhari-sidebutton" data-toggle="sidebutton-download-pdf" href="#">&#9662;</a>
            <div class="laskuhari-sidebutton-menu" id="sidebutton-download-pdf">
                <a href="' . $download_link . 'lasku" target="_blank">' . __( 'Lataa lasku', 'laskuhari' ) . '</a>
                <a href="' . $download_link . 'kuitti" target="_blank">' . __( 'Lataa kuitti', 'laskuhari' ) . '</a>
                <a href="' . $download_link . 'kateiskuitti" target="_blank">' . __( 'Lataa käteiskuitti', 'laskuhari' ) . '</a>
                <a href="' . $download_link . 'lahete" target="_blank">' . __( 'Lataa lähete', 'laskuhari' ) . '</a>
                <a href="' . $download_link . 'tarjous" target="_blank">' . __( 'Lataa tarjous', 'laskuhari' ) . '</a>
                <a href="' . $download_link . 'tilausvahvistus" target="_blank">' . __( 'Lataa tilausvahvistus', 'laskuhari' ) . '</a>
            </div>
            <a class="laskuhari-nappi laheta-lasku" href="#">' . __('Lähetä lasku', 'laskuhari').'' . ( $lahetetty ? ' ' . __( 'uudelleen', 'laskuhari' ) . '' : '' ) . '</a>
            <div id="laskuhari-laheta-lasku-lomake" class="laskuhari-pikkulomake" style="display: none;"><div id="lahetystapa-lomake1"></div><input type="button" class="laskuhari-send-invoice-button" value="' . __( 'Lähetä lasku', 'laskuhari' ) . '" onclick="laskuhari_admin_action(\'sendonly\'); return false;" />
            </div>
            <a class="laskuhari-nappi avaa-laskuharissa" href="https://' . laskuhari_domain() . '/' . $open_link . '" target="_blank">' . __( 'Avaa Laskuharissa', 'laskuhari' ).'</a>';

            $luo_teksti = __( "Luo uusi lasku", "laskuhari" );
            $luo_varoitus = __( 'Tämä luo uuden laskun uudella laskunumerolla. Jatketaanko?', 'laskuhari' );
            $luo_laheta_varoitus = __( "Haluatko varmasti luoda uuden laskun uudella laskunumerolla?", "laskuhari" );
        }

        echo '
        <a class="laskuhari-nappi uusi-lasku" href="#">'.$luo_teksti.'</a>
        <div id="laskuhari-tee-lasku-lomake" class="laskuhari-pikkulomake" style="display: none;">
            '.$payment_terms_select;

        $laskuhari->viitteenne_lomake( $post->ID );

        echo '
            <input type="checkbox" id="laskuhari-send-check" /> <label for="laskuhari-send-check" id="laskuhari-send-check-label">Lähetä</label><br />
            <div id="laskuhari-create-and-send-method">
                <div id="lahetystapa-lomake2">';
                $laskuhari->lahetystapa_lomake( $post->ID );
        echo '</div>
                <input type="button" value="'.$luo_ja_laheta.'" id="laskuhari-create-and-send" onclick="'.$send_warning_confirm.'if(!confirm(\''.$luo_laheta_varoitus.'\')) {return false;} laskuhari_admin_action(\'send\');" />
            </div>
            <input type="button" id="laskuhari-create-only" value="'.$luo_teksti.'" onclick="'.$warning_confirm.'if(!confirm(\''.$luo_varoitus.'\')) {return false;} laskuhari_admin_action(\'create\');" />
        </div>';
    }

    if( $maksutapa_ei_laskuhari ) {
        echo '</div>';
    }
}

/**
 * Adds invoice surcharge to the cart
 *
 * @param WC_Cart $cart
 * @return void
 */
function laskuhari_add_invoice_surcharge( $cart ) {
    $laskuhari_gateway_object = laskuhari_get_gateway_object();
    $laskuhari = $laskuhari_gateway_object;

    if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
        return;
    }

    if( ! isset( WC()->session->chosen_payment_method ) || WC()->session->chosen_payment_method !== "laskuhari" ) {
        return;
    }

    $send_method = WC()->session->get( "laskuhari-laskutustapa" );

    $prices_include_tax = get_option( 'woocommerce_prices_include_tax' ) === 'yes' ? true : false;

    $laskutuslisa = $laskuhari->veroton_laskutuslisa( $prices_include_tax, $send_method, $cart->get_subtotal(), $cart, null );

    if( $laskutuslisa == 0 ) {
        return;
    }

    $cart->add_fee( __( 'Laskutuslisä', 'laskuhari' ), $laskutuslisa, true );
}

function laskuhari_checkout_update_order_review( $post_data ) {
    parse_str( $post_data, $result );
    WC()->session->set( "laskuhari-laskutustapa", isset( $result['laskuhari-laskutustapa'] ) ? $result['laskuhari-laskutustapa'] : "" );
}

function laskuhari_fallback_notice() {
    echo '
    <div class="notice notice-error is-dismissible">
        <p>Laskuhari for WooCommerce vaatii WooCommerce-lisäosan asennuksen.</p>
    </div>';
}

function laskuhari_add_gateway( $methods ) {
    $methods[] = WC_Gateway_Laskuhari::class;
    return $methods;
}

function laskuhari_settings_link() {
    return get_bloginfo('wpurl') . '/wp-admin/admin.php?page=wc-settings&tab=checkout&section=laskuhari';
}

function laskuhari_plugin_action_links( $links, $file ) {
    $this_plugin = plugin_basename( __FILE__ );

    if( $file == $this_plugin ) {
        $settings_link = '<a href="' . laskuhari_settings_link() . '">Asetukset</a>';
        array_unshift( $links, $settings_link );
    }

    return $links;
}

function laskuhari_actions() {
    if( ! is_admin() || ! current_user_can( "edit_shop_orders" ) ) {
        return false;
    }

    $order_id   = $_GET['order_id'] ?? $_GET['post'] ?? $_GET['id'] ?? 0;

    if( isset( $_GET['laskuhari'] ) && $_GET['laskuhari'] == "sendonly" ) {
        Laskuhari_Nonce::verify();

        Logger::enabled( 'debug' ) && Logger::log( sprintf(
            'Laskuhari: Sending invoice of order %s via bulk action',
            $order_id
        ), 'debug' );

        $lh = laskuhari_send_invoice( wc_get_order( $order_id ) );
        laskuhari_go_back( $lh );
        exit;
    }

    if( isset( $_GET['laskuhari'] ) ) {
        Laskuhari_Nonce::verify();

        $send = ($_GET['laskuhari'] == "send");

        Logger::enabled( 'debug' ) && Logger::log( sprintf(
            'Laskuhari: %s invoice of order %s via bulk action',
            $send ? "Sending and creating" : "Creating",
            $order_id
        ), 'debug' );

        $lh = laskuhari_process_action( $order_id, $send, false, false, true );

        laskuhari_go_back( $lh );
    }

    if( isset( $_GET['laskuhari_download'] ) ) {
        $args = [];

        if( isset( $_GET['laskuhari_template'] ) ) {
            $args = [
                'pohja' => $_GET['laskuhari_template'],
            ];
        }

        if( $_GET['laskuhari_download'] === "current" ) {
            $order_id = $_GET['post'] ?? $_GET['order_id'] ?? $_GET['id'];
        } else if( $_GET['laskuhari_download'] > 0 ) {
            $order_id = $_GET['order_id'] ?? $_GET['id'];
        }

        Logger::enabled( 'debug' ) && Logger::log( sprintf(
            'Laskuhari: Downloading invoice for order %d via action',
            $order_id
        ), 'debug' );

        $lh = laskuhari_download( $order_id, true, $args );

        laskuhari_go_back( $lh );
        exit;
    }

    // update saved metadata retrieved through the API
    if( isset( $_GET['laskuhari_action'] ) && $_GET['laskuhari_action'] === "update_metadata" ) {
        Logger::enabled( 'debug' ) && Logger::log( sprintf(
            'Laskuhari: Updating metadata for order %d via action',
            $order_id
        ), 'debug' );

        // reset payment status metadata
        laskuhari_update_payment_status( $order_id, "", "", "" );

        // update payment status metadata
        laskuhari_get_invoice_payment_status( $order_id );

        // redirect back
        laskuhari_go_back();
        exit;
    }

    // get the invoice amount data (for automated testing)
    if( isset( $_GET['laskuhari_action'] ) && $_GET['laskuhari_action'] === "get_amount_data" ) {
        $data = laskuhari_get_invoice_amount( $order_id );
        ?>
        <pre><?php echo json_encode( $data, JSON_PRETTY_PRINT ); ?></pre>
        <?php
        exit;
    }

    // get the invoice data (for automated testing)
    if( isset( $_GET['laskuhari_action'] ) && $_GET['laskuhari_action'] === "get_invoice_data" ) {
        $data = laskuhari_get_invoice_data( $order_id );
        ?>
        <pre><?php echo json_encode( $data, JSON_PRETTY_PRINT ); ?></pre>
        <?php
        exit;
    }

    // update list of payment terms
    if( isset( $_GET['laskuhari_action'] ) && $_GET['laskuhari_action'] === "fetch_payment_terms" ) {
        Logger::enabled( 'debug' ) && Logger::log( sprintf(
            'Laskuhari: Updating payment terms for order %d via action',
            $order_id
        ), 'debug' );

        laskuhari_get_payment_terms( true );
    }
}

function laskuhari_go_back( $lh = false, $url = false ) {
    wp_redirect( laskuhari_back_url( $lh, $url ) );
    exit;
}

function laskuhari_back_url( $lh = false, $url = false ) {

    if( is_array( $lh )&& isset( $lh[0] ) && is_array( $lh[0] ) ) {
        $data = array(
            "lahetetty" => array(),
            "luotu"     => array(),
            "notices"   => array(),
            "successes" => array()
        );
        foreach( $lh as $datas ) {
            $data["luotu"][]     = $datas["luotu"] ?? "";
            $data["lahetetty"][] = $datas["lahetetty"] ?? "";
            $data["notice"][]    = $datas["notice"] ?? "";
            $data["success"][]   = $datas["success"] ?? "";
        }
    } else {
        $data = $lh;
    }

    $remove = array(
        '_wpnonce', 'order_id', 'laskuhari', 'laskuhari_download', 'laskuhari',
        'laskuhari_luotu', 'laskuhari_success', 'laskuhari_lahetetty',
        'laskuhari_notice', 'laskuhari_send_invoice', 'laskuhari-laskutustapa',
        'laskuhari-maksuehto', 'laskuhari-ytunnus', 'laskuhari-verkkolaskuosoite',
        'laskuhari-valittaja', 'laskuhari_action', 'laskuhari-email'
    );

    $back = remove_query_arg(
        $remove,
        $url
    );

    if( is_array( $data ) ) {
        $back = add_query_arg(
            array(
                'laskuhari_luotu'     => $data["luotu"] ?? "",
                'laskuhari_lahetetty' => $data["lahetetty"] ?? "",
                'laskuhari_notice'    => $data["notice"] ?? "",
                'laskuhari_success'   => $data["success"] ?? ""
            ),
            $back
        );
    }

    return $back;
}

function laskuhari_demo_notice() {
    echo '
    <div class="notice is-dismissible">
        <p>HUOM! Laskuhari-lisäosan demotila on käytössä! Voit poistaa sen käytöstä <a href="' . laskuhari_settings_link() . '">asetuksista</a></p>
    </div>';
}

function laskuhari_wp_cron_disabled_notice() {
    echo '
    <div class="notice is-dismissible">
        <p>HUOM! Olet poistanut WP Cronin käytöstä sivustollasi, mutta Laskuhari-lisäosan <a href="' . laskuhari_settings_link() . '">asetuksista</a> on otettu käyttöön viivästetty laskujen luonti WP Cronia käyttämällä. Tämä saattaa aiheuttaa viivästystä laskujen muodostamiseen.</p>
    </div>';
}

function laskuhari_not_activated() {
    echo '
    <div class="notice notice-error is-dismissible">
        <p>HUOM! Laskuhari-lisäosaa ei ole otettu käyttöön. Ota se käyttöön <a href="' . laskuhari_settings_link() . '">asetuksista</a></p>
    </div>';
}

function laskuhari_force_array( $input ) {
    return is_array( $input ) ? $input : [$input];
}

function laskuhari_notices() {
    $notices   = laskuhari_force_array( $_GET['laskuhari_notice'] ?? "" );
    $successes = laskuhari_force_array( $_GET['laskuhari_success'] ?? "" );
    $orders    = laskuhari_force_array( $_GET['laskuhari_luotu'] ?? "" );
    $orders2   = laskuhari_force_array( $_GET['laskuhari_lahetetty'] ?? "" );

    foreach ( $notices as $key => $notice ) {
        if( $notice != "" ) {
            $notice_html = esc_html( $notice );
            $notice_html = str_replace( "__asetuksiin__", '<a href="' . laskuhari_settings_link() . '">asetuksiin</a>', $notice_html );
            echo '<div class="notice notice-error is-dismissible"><p>' . $notice_html . '</p></div>';
        }
    }

    foreach ( $successes as $key => $notice ) {
        if( $notice != "" ) {
            echo '<div class="notice notice-success is-dismissible" data-testid="laskuhari-success"><p>' . esc_html( $notice ) . '</p></div>';
        }
    }

    foreach ( $orders as $key => $notice ) {
        if( $notice != "" ) {
            $order = wc_get_order( $notice );
            echo '<div class="notice notice-success is-dismissible" data-testid="invoice-created" data-order-id="'.$order->get_id().'"><p>Tilauksesta #' . esc_html( $order->get_order_number() ) . ' luotu lasku</p></div>';
        }
    }

    foreach ( $orders2 as $key => $notice ) {
        if( $notice != "" ) {
            $order = wc_get_order( $notice );
            echo '<div class="notice notice-success is-dismissible" data-testid="invoice-sent" data-order-id="'.$order->get_id().'"><p>Tilauksesta #' . esc_html( $order->get_order_number() ) . ' lähetetty lasku</p></div>';
        }
    }
}

function laskuhari_add_styles() {
    wp_enqueue_style(
        'laskuhari-css',
        plugins_url( 'css/staili.css' , __FILE__ ),
        array(),
        filemtime( __FILE__ )
    );
}

function laskuhari_add_admin_styles() {
    wp_enqueue_style(
        'laskuhari-css-admin',
        plugins_url( 'css/admin.css' , __FILE__ ),
        array(),
        filemtime( __FILE__ )
    );
}

/**
 * When WP Cron is disabled, we need to run it manually to make sure
 * the scheduled actions are executed. This function checks if
 * WP Cron is disabled and there are scheduled actions that
 * need to be executed.
 *
 * @return bool
 */
function laskuhari_cron_needs_to_run() {
    if( defined( "DISABLE_LASKUHARI_CRON" ) && DISABLE_LASKUHARI_CRON ) {
        return false;
    }

    if( defined( "DISABLE_WP_CRON" ) && DISABLE_WP_CRON ) {
        $hooks = [
            "laskuhari_create_product_action",
            "laskuhari_update_stock_action",
            "laskuhari_process_action_delayed_action"
        ];

        foreach( $hooks as $hook ) {
            $time = laskuhari_wp_last_scheduled( $hook );
            if( $time !== false && $time < time() ) {
                return true;
            }
        }
    }

    return false;
}

function laskuhari_add_public_scripts() {
    wp_enqueue_script(
        'laskuhari-js-public',
        plugins_url( 'js/public.js' , __FILE__ ),
        array( 'jquery' ),
        filemtime( __FILE__ )
    );

    $cron_needs_to_run = laskuhari_cron_needs_to_run();

    wp_localize_script( 'laskuhari-js-public', 'laskuhariInfo', [
        'cron_url' => site_url( '/wp-cron.php?doing_wp_cron&laskuhari_cron' ),
        'cron_needs_to_run' => $cron_needs_to_run ? "yes" : "no"
    ] );
}

function laskuhari_add_admin_scripts() {
    wp_enqueue_script(
        'laskuhari-js-admin',
        plugins_url( 'js/admin.js' , __FILE__ ),
        array( 'jquery' ),
        filemtime( __FILE__ )
    );

    wp_localize_script( 'laskuhari-js-public', 'laskuhariInfo', [
        'nonce' => Laskuhari_Nonce::create(),
    ] );
}

function laskuhari_invoice_number_by_order( $orderid ) {
    return laskuhari_get_post_meta( $orderid, '_laskuhari_invoice_number', true );
}

/**
 * Gets invoice ID by invoice number via API
 *
 * @param string $invoice_number
 * @return int|false Invoice ID or false on failure
 */
function laskuhari_invoice_id_by_invoice_number( $invoice_number ) {
    $api_url = "https://" . laskuhari_domain() . "/rest-api/lasku/" . $invoice_number . "/get-id-by-number";
    $response = laskuhari_api_request( array(), $api_url, "Get ID by number" );

    if( false === $response ) {
        return false;
    }

    return intval( $response['invoice_id'] );
}

function laskuhari_get_invoice_payment_status( $order_id, $invoice_id = null ) {
    if ( null === $invoice_id ) {
        $invoice_id = laskuhari_invoice_id_by_order( $order_id );
    }

    // get invoice payment status from API
    $api_url  = "https://" . laskuhari_domain() . "/rest-api/lasku/" . $invoice_id . "/status";
    $response = laskuhari_api_request( array(), $api_url, "Get status" );

    if( false === $response ) {
        Logger::enabled( 'error' ) && Logger::log( sprintf(
            'Laskuhari: Failed to get payment status for order %d',
            $order_id
        ), 'error' );

        return false;
    }

    if( $response['status'] === "OK" ) {
        $status = $response['vastaus'];

        // save payment status to post_meta
        if ( isset( $status['maksustatus']['koodi'] ) ) {
            laskuhari_update_payment_status(
                $order_id,
                $status['maksustatus']['koodi'],
                $status['status']['nimi'],
                $status['status']['id']
            );
        }

        // return payment status
        return $status;
    }

    return false;
}

/**
 * Gets the invoice amount data from Laskuhari API
 *
 * @param ?int $order_id
 * @param ?int $invoice_id
 * @return array|false
 */
function laskuhari_get_invoice_amount( $order_id, $invoice_id = null ) {
    if ( null === $invoice_id ) {
        $invoice_id = laskuhari_invoice_id_by_order( $order_id );
    }

    // get invoice amount from API
    $api_url  = "https://" . laskuhari_domain() . "/rest-api/lasku/" . $invoice_id . "/loppusumma";
    $response = laskuhari_api_request( array(), $api_url, "Get amount" );

    if( false === $response ) {
        Logger::enabled( 'error' ) && Logger::log( sprintf(
            'Laskuhari: Failed to get amount data for order %d',
            $order_id
        ), 'error' );

        return false;
    }

    if( $response['status'] === "OK" ) {
        $status = $response['vastaus'];

        // return amount data
        return $status;
    }

    return false;
}

/**
 * Gets the invoice data from Laskuhari API
 *
 * @param ?int $order_id
 * @param ?int $invoice_id
 * @return array|false
 */
function laskuhari_get_invoice_data( $order_id, $invoice_id = null ) {
    if ( null === $invoice_id ) {
        $invoice_id = laskuhari_invoice_id_by_order( $order_id );
    }

    // get invoice data from API
    $api_url  = "https://" . laskuhari_domain() . "/rest-api/lasku/" . $invoice_id . "/json";
    $response = laskuhari_api_request( array(), $api_url, "Get invoice data" );

    if( false === $response ) {
        Logger::enabled( 'error' ) && Logger::log( sprintf(
            'Laskuhari: Failed to get invoice data for order %d',
            $order_id
        ), 'error' );

        return false;
    }

    if( $response['status'] === "OK" ) {
        $status = $response['vastaus'];

        // return data
        return $status;
    }

    return false;
}

/**
 * Update the payment status metadata of an invoice attached to an order
 * 
 * @param $order_id Order ID
 * @param $status_code Status code (0 = unpaid / 1 = paid)
 * @param $status_name Human readable name of status
 * @param $status_id ID of status in Laskuhari system
 * 
 * @return void
 */
function laskuhari_update_payment_status( $order_id, $status_code, $status_name, $status_id ) {
    $laskuhari_gateway_object = laskuhari_get_gateway_object();

    $old_status = laskuhari_get_post_meta( $order_id, '_laskuhari_payment_status', true );

    laskuhari_set_order_meta( $order_id, '_laskuhari_payment_status', $status_code );
    laskuhari_set_order_meta( $order_id, '_laskuhari_payment_status_name', $status_name );
    laskuhari_set_order_meta( $order_id, '_laskuhari_payment_status_id', $status_id );

    $order = wc_get_order( $order_id );

    if( $order->get_payment_method() === "laskuhari" ) {
        if( 1 == $status_code ) {
            // if invoice status is changed to paid, change order status based on settings
            $status_after_paid = $laskuhari_gateway_object->lh_get_option( "status_after_paid" );
            $status_after_paid = apply_filters( "laskuhari_status_after_update_status_paid", $status_after_paid, $order_id );
            if( $status_after_paid ) {
                Logger::enabled( 'debug' ) && Logger::log( sprintf(
                    'Laskuhari: Updating order %d payment status to %s after payment',
                    $order_id,
                    $status_after_paid
                ), 'debug' );

                $order->update_status( $status_after_paid );
            }
        } elseif( $old_status != "" ) {
            // if invoice status is changed to unpaid, change order status based on filter
            $status_after_unpaid = apply_filters( "laskuhari_status_after_update_status_unpaid", false, $order_id );
            if( $status_after_unpaid ) {
                Logger::enabled( 'debug' ) && Logger::log( sprintf(
                    'Laskuhari: Updating order %d payment status to %s after unpayment',
                    $order_id,
                    $status_after_unpaid
                ), 'debug' );

                $order->update_status( $status_after_unpaid );
            }
        }
    }

    do_action( "laskuhari_payment_status_updated", $order_id, $status_code, $status_name, $status_id );
}

function laskuhari_get_payment_terms( $force = false ) {
    if( $force !== true ) {
        $saved_terms = get_option( "_laskuhari_payment_terms" );
        if( $saved_terms ) {
            return apply_filters( "laskuhari_payment_terms", $saved_terms );
        }
    }

    $api_url = "https://" . laskuhari_domain() . "/rest-api/maksuehdot";
    $response = laskuhari_api_request( array(), $api_url, "Get payment terms" );

    if( false === $response ) {
        Logger::enabled( 'error' ) && Logger::log( 'Laskuhari: Failed to get payment terms', 'error' );
        return false;
    }

    if( $response['status'] === "OK" ) {
        update_option( "_laskuhari_payment_terms", $response['vastaus'], false );
        return apply_filters( "laskuhari_payment_terms", $response['vastaus'] );
    }

    return false;
}

/**
 * Get invoice ID for an order
 *
 * @param int $orderid
 * @return int|false Invoice ID or false on failure
 */
function laskuhari_invoice_id_by_order( $orderid ) {
    $invoice_id = laskuhari_get_post_meta( $orderid, '_laskuhari_invoice_id', true );

    if( ! $invoice_id ) {
        $invoice_number = laskuhari_invoice_number_by_order( $orderid );
        $invoice_id = laskuhari_invoice_id_by_invoice_number( $invoice_number );

        if( false === $invoice_id ) {
            return false;
        }

        laskuhari_set_order_meta( $orderid, '_laskuhari_invoice_id', $invoice_id );
    }

    return $invoice_id;
}

function laskuhari_uid_by_order( $orderid ) {
    return laskuhari_get_post_meta( $orderid, '_laskuhari_uid', true );
}

function laskuhari_download( $order_id, $redirect = true, $args = [] ) {
    $laskuhari_gateway_object = laskuhari_get_gateway_object();

    $laskuhari_uid    = $laskuhari_gateway_object->uid;

    if( ! $laskuhari_uid ) {
        return laskuhari_uid_error();
    }

    $invoice_id = laskuhari_invoice_id_by_order( $order_id );

    if( ! $invoice_id ) {
        $error_notice = __( "Virhe laskun latauksessa. Laskua ei löytynyt numerolla", "laskuhari" );

        Logger::enabled( 'error' ) && Logger::log( sprintf(
            'Invoice ID not found for order \'%d\' when downloading invoice',
            $order_id
        ), 'error' );

        return array(
            "notice" => urlencode( $error_notice )
        );
    }

    $order_uid = laskuhari_uid_by_order( $order_id );

    if( $order_uid && $laskuhari_uid != $order_uid ) {
        $error_notice = __( "Virhe laskun latauksessa. Lasku on luotu eri UID:llä, kuin asetuksissa määritetty UID", "laskuhari" );
        return array(
            "notice" => urlencode( $error_notice )
        );
    }

    if( laskuhari_order_is_paid_by_other_method( $order_id ) && ! isset( $args['pohja'] ) && ! isset( $args['leima'] ) ) {
        if( $laskuhari_gateway_object->paid_stamp ) {
            $args['leima'] = "maksettu";
        }

        if( $laskuhari_gateway_object->receipt_template ) {
            $args['pohja'] = "kuitti";
        }
    }

    $api_url = "https://" . laskuhari_domain() . "/rest-api/lasku/" . $invoice_id . "/pdf-link";

    $api_url = apply_filters( "laskuhari_get_pdf_url", $api_url, $order_id );

    $payload = apply_filters( "laskuhari_download_pdf_payload", $args, $order_id );

    $response = laskuhari_api_request( $payload, $api_url, "Get PDF", "url" );

    if( false === $response ) {
        return array(
            "notice" => urlencode( __( "Tilauksen PDF-laskun lataaminen epäonnistui", "laskuhari" ) )
        );
    }

    if( strpos( $response, "https://" ) !== 0 ) {
        return array(
            "notice" => urlencode( __( "Tilauksen PDF-laskun lataaminen epäonnistui (virheellinen URL)", "laskuhari" ) )
        );
    }

    if( $redirect !== true ) {
        return $response;
    }

    // ohjataan PDF-tiedostoon jos ei ollut virheitä
    wp_redirect( $response );
    exit;
}

/**
 * Performs an API request to Laskuhari and returns the response
 *
 * @param array|string $payload Request payload
 * @param string $api_url API URL
 * @param string $action_name Action name for logging
 * @param string $format Response format ("json" | "url")
 *
 * @return array|false|string Response data (associative array for "json" type
 *                            and string for "url" type) or false on failure.
 *
 *                            Response data may also contain a list of error
 *                            messages under the key "virheet".
 */
function laskuhari_api_request( $payload, $api_url, $action_name = "API request", $format = "json" ) {
    $laskuhari_gateway_object = laskuhari_get_gateway_object();

    Logger::enabled( 'debug' ) && Logger::log( sprintf(
        'Laskuhari: Sending API request to %s',
        $api_url
    ), 'debug' );

    if( ! $laskuhari_gateway_object->uid ) {
        Logger::enabled( 'error' ) && Logger::log( 'Laskuhari: UID error while sending API request', 'error' );

        return false;
    }

    if( is_array( $payload ) ) {
        $payload = json_encode( $payload, laskuhari_json_flag() );

        if( false === $payload ) {
            Logger::enabled( 'error' ) && Logger::log( sprintf(
                'Laskuhari: %s JSON error: %s',
                $action_name,
                json_last_error_msg()
            ), 'error' );

            return false;
        }
    }

    $auth_key = Laskuhari_API::generate_auth_key(
        $laskuhari_gateway_object->uid,
        $laskuhari_gateway_object->apikey,
        $payload
    );

    if( ! function_exists( "curl_init" ) ) {
        Logger::enabled( 'error' ) && Logger::log( sprintf(
            'Laskuhari: %s cURL not available',
            $action_name
        ), 'error' );

        return false;
    }

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_URL, $api_url);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
    curl_setopt($ch, CURLOPT_TIMEOUT, 100);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type:application/json',
        'X-UID:'.$laskuhari_gateway_object->uid,
        'X-Auth-Key:'.$auth_key,
        'X-Timestamp:'.time()
    ]);
    curl_setopt($ch, CURLOPT_POST, TRUE);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);

    $ch = apply_filters( "laskuhari_curl_settings", $ch );

    $response = curl_exec( $ch );

    Logger::enabled( 'debug' ) && Logger::log( sprintf(
        'Laskuhari cURL response: %s',
        $response
    ), 'debug' );

    $curl_errno = curl_errno( $ch );
    $curl_error = curl_error( $ch );

    if( $curl_errno ) {
        Logger::enabled( 'error' ) && Logger::log( sprintf(
            'Laskuhari: %s cURL error: %s: %s',
            $action_name,
            $curl_errno,
            $curl_error
        ), 'error' );

        return false;
    }

    if( "json" === $format ) {
        $response_json = json_decode( $response, true );

        if( null === $response_json ) {
            Logger::enabled( 'error' ) && Logger::log( sprintf(
                'Laskuhari: %s JSON decode error: %s',
                $action_name,
                json_last_error_msg()
            ), 'error' );

            return false;
        }

        if( ! isset( $response_json['status'] ) ) {
            $error_response = wc_print_r( $response, true );
            if( $error_response == "" ) {
                $error_response = "Empty response";
            }

            Logger::enabled( 'error' ) && Logger::log( sprintf(
                'Laskuhari: %s response error: %s',
                $action_name,
                $error_response
            ), 'error' );

            return false;
        }

        if( $response_json['status'] != "OK" ) {
            if( isset( $response_json["virheet"] ) && is_array( $response_json["virheet"] ) ) {
                Logger::enabled( 'debug' ) && Logger::log( sprintf(
                    'Laskuhari: %s API raw error: %s',
                    $action_name,
                    wc_print_r( $response_json, true )
                ), 'debug' );

                foreach( $response_json["virheet"] as $virhe ) {
                    Logger::enabled( 'error' ) && Logger::log( sprintf(
                        'Laskuhari: %s API error: %s',
                        $action_name,
                        $virhe
                    ), 'error' );
                }
            } else {
                Logger::enabled( 'error' ) && Logger::log( sprintf(
                    'Laskuhari: %s API raw error: %s',
                    $action_name,
                    wc_print_r( $response_json, true )
                ), 'error' );

                $response_json["virheet"] = [
                    __( "Tuntematon virhe", "laskuhari" )
                ];
            }
        }

        return $response_json;
    }

    return $response;
}

/**
 * Create an invoice row payload for Laskuhari API
 *
 * @param string $type "item" | "discount" | "invoicing_fee" | "rounding"
 * @param WC_Order_Item|null $item
 * @param int $order_id
 * @param array<string, mixed> $data
 * @return void
 */
function laskuhari_invoice_row( $type, $item, $order_id, $data ) {
    $row_payload = [
        "koodi" => $data['product_sku'] ?? "",
        "tyyppi" => "",
        "woocommerce" => [
            "wc_product_id" => $data['product_id'] ?? "",
            "wc_variation_id" => $data['variation_id'] ?? ""
        ],
        "nimike" => $data['nimike'],
        "maara" => $data['maara'],
        "yks" => $data['yks'] ?? "",
        "veroton" => $data['veroton'],
        "alv" => laskuhari_vat_percent( $data['alv'] ),
        "ale" => $data['ale'],
        "verollinen" => $data['verollinen'],
        "yhtveroton" => $data['yhtveroton'],
        "yhtverollinen" => $data['yhtverollinen'],
        "toistuvuus" => [
            "toistovali" => 0,
            "ennakko" => 0,
            "jatkuvuus" => false,
            "automaatio" => false,
            "toistoale" => 0
        ]
    ];

    $row_payload = apply_filters( "laskuhari_invoice_row_payload", $row_payload, $item, $order_id, $type );

    return $row_payload;
}

function laskuhari_uid_error() {
    $error_notice = 'Ole hyvä ja syötä Laskuharin UID ja API-koodi lisäosan __asetuksiin__ tai käytä demotilaa.';
    return array(
        "notice" => urlencode( $error_notice )
    );
}

function laskuhari_order_is_paid_by_other_method( $order ) {
    if( ! is_object( $order ) ) {
        $order = wc_get_order( $order );
    }
    return "yes" === laskuhari_get_post_meta( $order->get_id(), '_laskuhari_paid_by_other', true ) && $order->get_payment_method() !== "laskuhari";
}

/**
 * Calls laskuhari_send_invoice_attached if the given order requires
 * a PDF invoice to be attached to its order confirmation email.
 *
 * For other than Laskuhari payment methods, the invoice
 * will be created if it does not exist yet and the order
 * was paid in the current request
 *
 * @param WC_Order|int $order
 * @return void
 */
function laskuhari_maybe_send_invoice_attached( $order ) {
    $laskuhari_gateway_object = laskuhari_get_gateway_object();

    if( ! is_object( $order ) ) {
        $order = wc_get_order( $order );
    }

    if( ! $order ) {
        Logger::enabled( 'error' ) && Logger::log( sprintf(
            'Laskuhari: Order not found in %s',
            __FUNCTION__
        ), 'error' );

        return false;
    }

    if( $order->get_payment_method() === "laskuhari" ) {
        // attach invoice to the order confirmation email only
        // if that option is selected in the settings
        if( ! $laskuhari_gateway_object->attach_invoice_to_wc_email ) {
            Logger::enabled( 'debug' ) && Logger::log( sprintf(
                'Laskuhari: Not attaching invoice to order %d',
                $order->get_id()
            ), 'debug' );
            return false;
        }

        $send_method = laskuhari_get_order_send_method( $order->get_id() );

        // this filter can be used to attach PDF invoice to orders
        // that are invoiced with other methods than email
        $attach_on_methods = apply_filters( "laskuhari_attach_invoice_to_email_on_send_methods", ["email"] );

        // attach invoice to the order confirmation email only
        // when the send method is allowed (default: only attach on email method)
        if( ! in_array( $send_method, $attach_on_methods ) ) {
            Logger::enabled( 'debug' ) && Logger::log( sprintf(
                'Laskuhari: Not attaching invoice to send method \'%s\', order %d',
                $send_method,
                $order->get_id()
            ), 'debug' );

            return false;
        }
    } else {
        // attach receipt to the order confirmation email when order is made
        // with other payment methods, if that option is selected in the settings
        if( ! $laskuhari_gateway_object->attach_receipt_to_wc_email ) {
            Logger::enabled( 'debug' ) && Logger::log( sprintf(
                'Laskuhari: Not attaching receipt to order %d',
                $order->get_id()
            ), 'debug' );
            return false;
        }

        laskuhari_maybe_create_invoice_for_other_payment_method( $order->get_id() );
    }

    Logger::enabled( 'info' ) && Logger::log( sprintf(
        'Laskuhari: Attaching invoice to order %d',
        $order->get_id()
    ), 'info' );

    // attach invoice pdf to WC email
    laskuhari_send_invoice_attached( $order );
}

/**
 * Downloads invoice PDF of order and registers woocommerce_email_attachments filter
 * to attach PDF to the order confirmation email.
 *
 * Also registers woocommerce_email_order_details filter to add extra text to the
 * order confirmation email based on the plugin settings.
 *
 * Note: Invoice will only be attached if it has already been created for the order
 *
 * @param WC_Order|int $order
 * @return void
 */
function laskuhari_send_invoice_attached( $order ) {
    $laskuhari_gateway_object = laskuhari_get_gateway_object();

    if( ! is_object( $order ) ) {
        $order = wc_get_order( $order );
    }

    if( ! $order ) {
        Logger::enabled( 'error' ) && Logger::log( sprintf(
            'Laskuhari: Order not found in %s',
            __FUNCTION__
        ), 'error' );

        return false;
    }

    $invoice_number = laskuhari_get_post_meta( $order->get_id(), '_laskuhari_invoice_number', true );
    $invoice_id     = laskuhari_get_post_meta( $order->get_id(), '_laskuhari_invoice_id', true );

    if( ! $invoice_id ) {
        Logger::enabled( 'error' ) && Logger::log( sprintf(
            'Laskuhari: No invoice id for order %d in %s',
            $order->get_id(),
            __FUNCTION__
        ), 'error' );
        return false;
    }

    if( ! apply_filters( "laskuhari_send_invoice_attached_custom_rule", true, $order ) ) {
        Logger::enabled( 'debug' ) && Logger::log( sprintf(
            'Laskuhari: Not attaching invoice based on custom rule, order %s',
            $order->get_id()
        ), 'debug' );
        return false;
    }

    $args = [];
    $template_name = "lasku";

    if( laskuhari_order_is_paid_by_other_method( $order ) ) {
        if( $laskuhari_gateway_object->receipt_template ) {
            $template_name = "kuitti";
        }
    }

    $template_name = apply_filters( "laskuhari_attachment_template_name", $template_name, $order );

    // get pdf of invoice
    $pdf_url = laskuhari_download( $order->get_id(), false, $args );

    if( is_string( $pdf_url ) && strpos( $pdf_url, "https://".laskuhari_domain()."/" ) === 0 ) {
        $pdf = lh_get_file_from_url( $pdf_url );

        $pdf_filename = $template_name."_".intval( $invoice_number );
        $pdf_filename = apply_filters( "laskuhari_attachment_pdf_filename", $pdf_filename, $template_name, $order );

        // download invoice pdf to temporary file
        $temp_file = get_temp_dir().$pdf_filename.".pdf";
        if( file_exists( $temp_file ) ) {
            @unlink( $temp_file );
        }
        if( file_exists( $temp_file ) ) {
            $temp_file = get_temp_dir().$pdf_filename."_".wp_generate_password( 6, false ).".pdf";
        }
        file_put_contents( $temp_file, $pdf );

        if( $laskuhari_gateway_object->invoice_email_text_for_other_payment_methods && laskuhari_order_is_paid_by_other_method( $order ) ) {
            add_action( 'woocommerce_email_order_details', function( $order, $sent_to_admin ) use ( $laskuhari_gateway_object ) {
                if( ! $sent_to_admin ) {
                    $message = apply_filters(
                        "laskuhari_invoice_email_text_for_other_payment_methods_formatted",
                        wpautop( wptexturize( $laskuhari_gateway_object->invoice_email_text_for_other_payment_methods ) ),
                        $order
                    );
                    echo $message;
                }
            }, 5, 2 );
        }

        // hook attachment to WC email
        add_filter( "woocommerce_email_attachments", function( $attachments, $id, $object_type, $email_type ) use ( $temp_file, $order, $laskuhari_gateway_object, $invoice_id ) {
            $email_types = apply_filters(
                "laskuhari_attach_pdf_to_email_types",
                [
                    "WC_Email_Customer_Processing_Order",
                    "WC_Email_Customer_Invoice"
                ],
                $order
            );

            if( ! in_array( get_class( $email_type ), $email_types ) ) {
                return $attachments;
            }

            $conditional_email_types = apply_filters(
                "laskuhari_attach_pdf_to_email_types_conditionally",
                [
                    "WC_Email_Customer_Processing_Order"
                ],
                $order
            );

            if(
                in_array( get_class( $email_type ), $conditional_email_types ) &&
                ! $laskuhari_gateway_object->attach_invoice_to_wc_email &&
                ! laskuhari_order_is_paid_by_other_method( $order )
            ) {
                return $attachments;
            }

            $attachments[] = $temp_file;

            if( laskuhari_get_post_meta( $order->get_id(), '_laskuhari_sent', true ) !== "yes" ) {
                laskuhari_set_invoice_sent_status( $invoice_id, true, wp_date( "Y-m-d" ) );
                laskuhari_set_order_meta( $order->get_id(), '_laskuhari_sent', "yes" );
            }

            $order->add_order_note( __( "Liitetty lasku liitteksi tilaussähköpostiin (Laskuhari)", "laskuhari" ) );

            do_action( "laskuhari_after_pdf_attached_to_email", $order, $temp_file, $email_type, $attachments, $id, $object_type );

            return $attachments;
        }, 10, 4 );

        // make sure to delete temporary file
        add_action( "shutdown", function() use ( $temp_file ) {
            if( file_exists( $temp_file ) ) {
                unlink( $temp_file );
            }
        } );
    } else {
        $order->add_order_note( __( "Laskun liittäminen tilausvahvistuksen liitteeksi epäonnistui (Laskuhari)", "laskuhari" ) );

        Logger::enabled( 'error' ) && Logger::log( sprintf(
            'Laskuhari: Error attaching PDF to order %s',
            $order->get_id()
        ), 'error' );
    }
}

/**
 * Download file from an URL - alternative to file_get_contents
 * that works with PHP allow_url_fopen turned off
 *
 * @param string $url The URL to fetch file from
 * @since 1.3.5
 * @return string Contents of downloaded file
 */
function lh_get_file_from_url( $url ) {
    $ch = curl_init();

    curl_setopt( $ch, CURLOPT_URL, $url );
    curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );

    $data = curl_exec( $ch );

    curl_close( $ch );

    return $data;
}

/**
 * Attaches invoice PDF to resent order emails
 *
 * @param WC_Order|int $order
 * @param string $email_type
 * @return void
 */
function laskuhari_resend_order_emails( $order, $email_type ) {
    if( ! is_object( $order ) ) {
        $order = wc_get_order( $order );
    }

    if( ! $order ) {
        Logger::enabled( 'error' ) && Logger::log( sprintf(
            'Laskuhari: Order not found in %s',
            __FUNCTION__
        ), 'error' );

        return false;
    }

    $attach_on_email_types = apply_filters( "laskuhari_attach_invoice_on_resend_order_emails", [
        "customer_invoice"
    ], $order );

    if( in_array( $email_type, $attach_on_email_types ) ) {
        laskuhari_maybe_send_invoice_attached( $order );
    }
}

/**
 * Finds the value of a meta field from a product item
 *
 * @param array $item
 * @param array<string> $meta_keys
 * @param int|null $product_id
 * @return string|null
 */
function laskuhari_get_item_matching_meta( $item, $meta_keys, $product_id = null ) {
    $return_value = null;

    foreach( $meta_keys as $unit_field ) {
        if( isset( $item[$unit_field] ) ) {
            $return_value = $item[$unit_field];
            break;
        }

        if( isset( $item["_".$unit_field] ) ) {
            $return_value = $item["_".$unit_field];
            break;
        }

        // Check meta data
        $meta_data = $item["meta_data"];
        foreach( $meta_data as $meta ) {
            $meta = $meta->get_data();

            if( $meta["key"] === $unit_field ) {
                $return_value = $meta["value"];
                break;
            }

            if( $meta["key"] === "_".$unit_field ) {
                $return_value = $meta["value"];
                break;
            }
        }

        if( $product_id && $return_value = laskuhari_get_post_meta( $product_id, $unit_field, true )  ) {
           break;
        }

        if( $product_id && $return_value = laskuhari_get_post_meta( $product_id, "_".$unit_field, true )  ) {
           break;
        }
    }

    return $return_value;
}

/**
 * Determines the quantity unit to use for the invoice row
 * based on the product's meta data
 *
 * @param array $item
 * @param int $product_id
 * @param int $order_id
 * @return string
 */
function laskuhari_determine_quantity_unit( $item, $product_id, $order_id ) {
    $quantity_unit = "";

    $unit_fields = apply_filters( "laskuhari_quantity_unit_fields", [
        // Woocommerce Advanced Quantity (in product meta, not item meta)
        "_advanced-qty-quantity-suffix",
        "product-category-advanced-qty-quantity-suffix",
        "woo-advanced-qty-quantity-suffix",

        // Quantities and Units for WooCommerce (in product meta, not item meta)
        "unit",

        // General
        "qty-unit",
        "qty_unit",
        "qty-suffix",
        "qty_suffix",
        "quantity_unit",
        "yksikko",
        "yksikkö",
        "Yksikkö",
        "yks",
    ] );

    $quantity_unit = laskuhari_get_item_matching_meta( $item, $unit_fields, $product_id );
    $quantity_unit = apply_filters( "laskuhari_product_quantity_unit", $quantity_unit, $product_id, $order_id );

    return $quantity_unit;
}

/**
 * Determines the product SKU to use for the invoice row
 * based on the product's meta data
 *
 * @param array $item
 * @param int $product_id
 * @param string $product_sku
 * @param int $order_id
 * @return string
 */
function laskuhari_determine_product_sku( $item, $product_id, $product_sku, $order_id ) {
    $sku_fields = apply_filters( "laskuhari_product_sku_fields", [
        "sku",
        "product-sku",
        "product_sku",
        "tuotekoodi",
        "Tuotekoodi",
        "SKU",
        "koodi",
        "Koodi",
        "product-code",
        "product_code",
    ], $product_id, $product_sku, $order_id );

    $product_sku = laskuhari_get_item_matching_meta( $item, $sku_fields, $product_id );
    $product_sku = apply_filters( "laskuhari_product_sku", $product_sku, $product_id, $order_id );

    return $product_sku;
}

/**
 * Wrapper for running the laskuhari_process_action cron hook
 * (for logging when event is run)
 *
 * @return void
 */
function laskuhari_process_action_cron_hook() {
    Logger::enabled( 'info' ) && Logger::log( sprintf(
        'Laskuhari: Running cron hook %s',
        __FUNCTION__
    ), 'info' );

    $args = func_get_args();

    laskuhari_process_action( ...$args );
}

function laskuhari_process_action_delayed(
    $order_id,
    $send = false,
    $bulk_action = false,
    $from_gateway = false,
    $force_recreate = false
) {
    $args = func_get_args();

    // schedule background event
    $delayed_event = laskuhari_schedule_background_event(
        'laskuhari_process_action_delayed_action',
        $args,
        false,
        20,
        1
    );

    if( $delayed_event === true ) {
        Logger::enabled( 'notice' ) && Logger::log( sprintf(
            'Laskuhari: Scheduled background event for order %d',
            $order_id
        ), 'notice' );

        // mark invoice as queued if background event scheduling was successful
        laskuhari_set_order_meta( $order_id, '_laskuhari_queued', 'yes' );

        // save process args so that queue can be processed later in case of errors
        laskuhari_set_order_meta( $order_id, '_laskuhari_queued_args', $args );
    } else {
        Logger::enabled( 'notice' ) && Logger::log( sprintf(
            'Laskuhari: Scheduling background event failed for order %d',
            $order_id
        ), 'notice' );

        // if background event scheduling fails, process action now
        laskuhari_process_action( ...$args );
    }
}

/**
 * Process invoice of order that is marked as queued
 * but is not found in WP Cron queue
 *
 * @param int $order_id
 * @return array|false
 */
function laskuhari_maybe_process_queued_invoice( $order_id ) {
    $queued = laskuhari_get_post_meta( $order_id, '_laskuhari_queued', true ) === "yes";

    if( ! $queued ) {
        return false;
    }

    $queued_args = laskuhari_get_post_meta( $order_id, '_laskuhari_queued_args', true );

    if( ! is_array( $queued_args ) ) {
        Logger::enabled( 'error' ) && Logger::log( sprintf(
            'Laskuhari: Error processing queued invoice for order %d: Queued args not found',
            $order_id
        ), 'error' );

        $order = wc_get_order( $order_id );

        if( $order ) {
            $order->delete_meta_data( '_laskuhari_queued' );
            $order->delete_meta_data( '_laskuhari_queued_args' );
            $order->save_meta_data();
        }

        return false;
    }

    if( ! wp_next_scheduled( 'laskuhari_process_action_delayed_action', $queued_args ) ) {
        return laskuhari_process_action( ...$queued_args );
    }

    return false;
}

/**
 * Get the invoicing address for an order
 *
 * @param WC_Order $order
 * @return array<string, mixed>
 */
function laskuhari_get_invoicing_address( $order ) {
    $customer_id = $order->get_customer_id();
    $customer = $order->get_address( 'billing' );
    $ytunnus = get_laskuhari_meta( $order->get_id(), '_laskuhari_ytunnus', true );

    return [
        "yritys" => $customer['company'],
        "ytunnus" => $ytunnus,
        "henkilo" => trim( $customer['first_name'].' '.$customer['last_name'] ),
        "lahiosoite" => [
            $customer['address_1'],
            $customer['address_2']
        ],
        "postinumero" => $customer['postcode'],
        "postitoimipaikka" => $customer['city'],
        "email" => $customer['email'],
        "puhelin" => $customer['phone'],
        "asiakasnro" => $customer_id
    ];
}

function laskuhari_process_action(
    $order_id,
    $send = false,
    $bulk_action = false,
    $from_gateway = false,
    $force_recreate = false
) {
    $transient_name = "laskuhari_process_action_" . $order_id;
    $sleep_time = 0;
    while( ! $bulk_action && \laskuhari_get_transient( $transient_name ) === "yes" && $sleep_time < 20 ) {
        Logger::enabled( 'debug' ) && Logger::log( sprintf(
            'Laskuhari: Sleeping 5s while transient active, order %d',
            $order_id
        ), 'debug' );
        sleep( 5 );
        $sleep_time += 5;
    }

    if( $sleep_time === 20 ) {
        Logger::enabled( 'debug' ) && Logger::log( sprintf(
            'Laskuhari: Not processing Laskuhari action again while transient active, order %d',
            $order_id
        ), 'debug' );
        return false;
    }

    \set_transient( $transient_name, "yes", 60 );

    $laskuhari_gateway_object = laskuhari_get_gateway_object();

    $error_notice = "";
    $success      = "";

    $order = wc_get_order( $order_id );

    if( ! $order ) {
        Logger::enabled( 'error' ) && Logger::log( sprintf(
            'Laskuhari: Order not found in %s',
            __FUNCTION__
        ), 'error' );

        \delete_transient( $transient_name );

        return false;
    }

    $order->delete_meta_data( '_laskuhari_queued' );
    $order->delete_meta_data( '_laskuhari_queued_args' );
    $order->save_meta_data();

    // if invoice has already been created from this order
    if( laskuhari_invoice_is_created_from_order( $order_id ) ) {
        // if we are using bulk action to send, don't create invoice again, only send it
        if( $bulk_action && true === $send ) {
            Logger::enabled( 'debug' ) && Logger::log( sprintf(
                'Laskuhari: Invoice for order %s created already. Only sending via bulk action', $order_id
            ), 'debug' );

            \delete_transient( $transient_name );

            return laskuhari_send_invoice( $order, $bulk_action );
        }

        // only create invoice if forced
        if( $force_recreate !== true ) {
            $error_notice = __( 'Lasku on jo luotu tästä tilauksesta', 'laskuhari' );

            Logger::enabled( 'debug' ) && Logger::log( sprintf(
                'Laskuhari: Invoice for order %s created already. Not creating another.', $order_id
            ), 'debug' );

            return array(
                "notice" => urlencode( $error_notice )
            );
        } else {
            Logger::enabled( 'debug' ) && Logger::log( sprintf(
                'Laskuhari: Forcibly creating another invoice for order %d.', $order_id
            ), 'debug' );
        }
    } else {
        Logger::enabled( 'debug' ) && Logger::log( sprintf(
            'Laskuhari: No invoice for order %d. Creating a new one.', $order_id
        ), 'debug' );
    }

    if ( isset( $_REQUEST['laskuhari-viitteenne'] ) ) {
        laskuhari_set_order_meta( $order_id, '_laskuhari_viitteenne', $_REQUEST['laskuhari-viitteenne'], false );
    }

    if ( isset( $_REQUEST['laskuhari-email'] ) ) {
        laskuhari_set_order_meta( $order_id, '_laskuhari_email', $_REQUEST['laskuhari-email'], false );
    }

    if ( isset( $_REQUEST['laskuhari-laskutustapa'] ) ) {
        laskuhari_set_order_meta( $order_id, "_laskuhari_laskutustapa", $_REQUEST['laskuhari-laskutustapa'], false );
    }

    $prices_include_tax = laskuhari_get_post_meta( $order_id, '_prices_include_tax', true ) == 'yes' ? true : false;

    $send_method = laskuhari_get_order_send_method( $order->get_id() );

    // laskunlähetyksen asetukset
    $info = $laskuhari_gateway_object;
    $laskuhari_uid           = $info->uid;
    $laskutuslisa_alv        = $info->laskutuslisa_alv;
    $laskutuslisa_veroton    = $info->veroton_laskutuslisa( $prices_include_tax, $send_method, $order->get_subtotal(), null, $order );
    $laskutuslisa_verollinen = $info->verollinen_laskutuslisa( $prices_include_tax, $send_method, $order->get_subtotal(), null, $order );

    $add_surcharge = $laskutuslisa_veroton > 0;

    if( ! $laskuhari_uid ) {
        Logger::enabled( 'error' ) && Logger::log( sprintf(
            'Laskuhari: UID error while processing action, order %d',
            $order_id
        ), 'error' );

        \delete_transient( $transient_name );

        return laskuhari_uid_error();
    }

    // tilaajan tiedot
    $customer               = $order->get_address( 'billing' );
    $shippingdata           = $order->get_address( 'shipping' );

    $shipping_different = false;

    foreach ( $customer as $key => $bdata ) {
        if( ! isset( $shippingdata[$key] ) ) {
            continue;
        }

        $bdata = trim( $bdata );
        $sdata = trim( $shippingdata[$key] );

        if( in_array( $key, ["email", "phone"] ) && $sdata == "" ) {
            continue;
        }

        if( $sdata != "" && $sdata != $bdata ) {
            $shipping_different = true;
            break;
        }
    }

    if( ! $shipping_different ) {
        foreach( $shippingdata as $key => $sdata ) {
            $shippingdata[$key] = "";
        }
    }

    $viitteenne        = get_laskuhari_meta( $order->get_id(), '_laskuhari_viitteenne', true );
    $ytunnus           = get_laskuhari_meta( $order->get_id(), '_laskuhari_ytunnus', true );
    $verkkolaskuosoite = get_laskuhari_meta( $order->get_id(), '_laskuhari_verkkolaskuosoite', true );
    $valittaja         = get_laskuhari_meta( $order->get_id(), '_laskuhari_valittaja', true );

    if( isset( $_REQUEST['laskuhari-maksuehto'] ) && is_admin() ) {
        $maksuehto = $_REQUEST['laskuhari-maksuehto'];
    } else {
        $maksuehto = laskuhari_get_post_meta( $order->get_id(), '_laskuhari_payment_terms', true );
    }

    if( ! $maksuehto ) {
        $maksuehto = laskuhari_get_customer_payment_terms_default( $order->get_customer_id() );
    }

    $customer_id = apply_filters( 'laskuhari_customer_id', $order->get_user_id(), $order_id );

    // merkitään lasku heti maksetuksi, jos se tehtiin muusta maksutavasta
    if( laskuhari_order_is_paid_by_other_method( $order ) ) {
        $invoice_status = apply_filters( "laskuhari_invoice_status_from_other_payment_method", "MAKSETTU", $order_id );
    } else {
        $invoice_status = "AVOIN";
    }

    $invoice_status = apply_filters( "laskuhari_new_invoice_status", $invoice_status, $order_id );

    $payload = [
        "ref" => "wc",
        "site" => get_site_url(),
        "tyyppi" => 0,
        "laskunro" => false,
        "pvm" => date( 'd.m.Y' ),
        "viitteenne" => $viitteenne,
        "viitteemme" => "",
        "tilausnumero" => $order->get_order_number(),
        "metatiedot" => [
          "lahetetty" => false,
          "toimitettu" => false,
          "maksupvm" => false,
          "status" => $invoice_status
        ],
        "laskutusosoite" => laskuhari_get_invoicing_address( $order ),
        "toimitusosoite" => [
            "yritys" => $shippingdata['company'],
            "henkilo" => trim( $shippingdata['first_name'].' '.$shippingdata['last_name'] ),
            "lahiosoite" => [
                $shippingdata['address_1'],
                $shippingdata['address_2']
            ],
            "postinumero" => $shippingdata['postcode'],
            "postitoimipaikka" => $shippingdata['city']
        ],
        "verkkolasku" => [
            "toIdentifier" => $verkkolaskuosoite,
            "toIntermediator" => $valittaja,
            "buyerPartyIdentifier" => $ytunnus
        ],
        "woocommerce" => [
            "wc_order_id" => $order->get_id(),
            "wc_user_id" => $order->get_user_id()
        ]
    ];

    if( $maksuehto ) {
        $payload["maksuehto"] = [
            "id" => $maksuehto
        ];
    }

    $coupon_codes = $order->get_coupon_codes();

    // remove coupons starting with an underscore
    $coupon_codes = array_filter( $coupon_codes, function($v) {
        return $v[0] !== '_';
    } );

    $has_coupons = count( $coupon_codes ) > 0;

    // Cart discounts by VAT rate (key is VAT rate, value is sum, incl. tax)
    $cart_discounts = [];

    $products = $order->get_items( ["line_item", "shipping", "fee"] );
    $loppusumma = $order->get_total();
    $laskettu_summa = 0;

    $laskurivit = [];

    foreach( $products as $item ) {
        if( is_callable( [$item, "get_total"] ) ) {
            /** @var WC_Order_Item_Product $item */

            $total = $item->get_total();

            /** @var WC_Order_Item $item */
        } else {
            Logger::enabled( 'notice' ) && Logger::log( sprintf(
                'Laskuhari: Order item does not have a total (%s / %s), order %d',
                get_class( $item ),
                $item->get_name(),
                $order_id
            ), 'notice' );

            $total = 0;
        }

        if( is_callable( [$item, "get_subtotal"] ) ) {
            /** @var WC_Order_Item_Product $item */

            $subtotal = $item->get_subtotal();

            /** @var WC_Order_Item $item */
        } else {
            Logger::enabled( 'debug' ) && Logger::log( sprintf(
                'Laskuhari: Order item does not have a subtotal (%s / %s), order %d',
                get_class( $item ),
                $item->get_name(),
                $order_id
            ), 'debug' );

            $subtotal = $total;
        }

        if( is_callable( [$item, "get_taxes"] ) ) {
            /** @var WC_Order_Item_Product | WC_Order_Item_Fee $item */

            $tax_data = $item->get_taxes();

            /** @var WC_Order_Item $item */

            if( isset( $tax_data['total'] ) ) {
                $total_tax_4dp = array_sum( $tax_data['total'] );
            } else {
                $total_tax_4dp = 0;

                Logger::enabled( 'notice' ) && Logger::log( sprintf(
                    'Laskuhari: Order item does not have total tax (%s / %s), order %d',
                    get_class( $item ),
                    $item->get_name(),
                    $order_id
                ), 'notice' );
            }

            if( isset( $tax_data['subtotal'] ) ) {
                $subtotal_tax_4dp = array_sum( $tax_data['subtotal'] );
            } else {
                $subtotal_tax_4dp = $total_tax_4dp;

                Logger::enabled( 'notice' ) && Logger::log( sprintf(
                    'Laskuhari: Order item does not have subtotal tax (%s / %s), order %d',
                    get_class( $item ),
                    $item->get_name(),
                    $order_id
                ), 'notice' );
            }
        } else {
            Logger::enabled( 'debug' ) && Logger::log( sprintf(
                'Laskuhari: Order item does not have taxes (%s), order %d',
                get_class( $item ),
                $order_id
            ), 'debug' );

            $total_tax_4dp = 0;
            $subtotal_tax_4dp = 0;
        }

        $quantity = $item->get_quantity();

        // For orders with coupons, we will use the non-discounted
        // price for the invoice rows, since we will add the
        // coupons as negative discount rows in the end
        if( $has_coupons ) {
            $yht_verollinen = $subtotal + $subtotal_tax_4dp;
            $yht_veroton = $subtotal;

            if( $yht_veroton != 0 ) {
                $alv = NumberUtil::round( $subtotal_tax_4dp / $yht_veroton * 100, 2 );
            } else {
                $alv = 0;
            }
        } else {
            $yht_verollinen = $total + $total_tax_4dp;
            $yht_veroton = $total;

            if( $yht_veroton != 0 ) {
                $alv = NumberUtil::round( $total_tax_4dp / $yht_veroton * 100, 2 );
            } else {
                $alv = 0;
            }
        }

        // When ordering through the checkout, the invoicing surcharge is automatically
        // added as a fee. We don't need to add it separately in the end.
        if( is_a( $item, WC_Order_Item_Fee::class ) && $item->get_name() === "Laskutuslisä" ) {
            $add_surcharge = false;
        }

        if( $has_coupons ) {
            // Calculate discount amount for the row
            $row_discount = $yht_verollinen - ( $total + $total_tax_4dp );

            // Aggregate discounts by VAT rate
            if( ! isset( $cart_discounts[(string)$alv] ) ) {
                $cart_discounts[(string)$alv] = 0;
            }

            $cart_discounts[(string)$alv] += $row_discount;
        }

        if( $quantity != 0 ) {
            $yks_verollinen = round( $yht_verollinen / $quantity, 10 );
        } else {
            $yks_verollinen = 0;
        }

        if( $alv != -100 ) {
            $yks_veroton = $yks_verollinen / ( 1 + $alv / 100 );
        } else {
            $yks_veroton = 0;
        }

        if( is_a( $item, WC_Order_Item_Product::class ) ) {
            $variation_id = $item->get_variation_id();
            $product_id = $variation_id ? $variation_id : $item->get_product_id();

            if( $laskuhari_gateway_object->synkronoi_varastosaldot && ! laskuhari_product_synced( $product_id ) ) {
                laskuhari_create_product( $product_id );
            }

            set_transient( "laskuhari_update_product_" . $product_id, $product_id, 4 );
            $product = wc_get_product( $product_id );

            if( is_a( $product, WC_Product::class ) ) {
                $product_sku = laskuhari_determine_product_sku(
                    $item->get_data(),
                    $product_id,
                    $product->get_sku(),
                    $order_id
                );
            }
        } else {
            $product_id = 0;
            $variation_id = 0;
            $product_sku = "";
        }

        $ale = 0;

        if( $laskuhari_gateway_object->calculate_discount_percent ) {
            $price_with_tax = $subtotal + $subtotal_tax_4dp;
            $price_without_tax = $subtotal;

            if( $price_without_tax != 0 ) {
                $discount_amount = $price_without_tax - $yht_veroton;
                $discount_percent = $discount_amount / $price_without_tax * 100;
            } else {
                $discount_amount = 0;
                $discount_percent = 0;
            }

            if( $discount_percent > 0.009 && $discount_amount > 0.009 ) {
                $ale = $discount_percent;

                if( $quantity != 0 ) {
                    // Calculate price per unit so that it matches the rounded total price, tax included.
                    // This avoids rounding differences between Laskuhari and WooCommerce.
                    $yks_verollinen = NumberUtil::round( $price_with_tax, 2 ) / $quantity;
                    $yks_veroton = $yks_verollinen / ( 1 + $alv / 100 );

                    $ale_maara_verollinen = $yks_verollinen * ($ale / 100);
                    $yht_verollinen = ( $yks_verollinen - $ale_maara_verollinen ) * $quantity;

                    $ale_maara_veroton = $yks_veroton * ($ale / 100);
                    $yht_veroton = ( $yks_veroton - $ale_maara_veroton ) * $quantity;
                } else {
                    $yks_verollinen = 0;
                    $yks_veroton = 0;
                }
            }
        }

        if( $laskuhari_gateway_object->show_quantity_unit ) {
            $quantity_unit = laskuhari_determine_quantity_unit( $item->get_data(), $product_id, $order_id );
        } else {
            $quantity_unit = "";
        }

        $laskurivit[] = laskuhari_invoice_row( "item", $item, $order_id, [
            "product_sku"   => $product_sku,
            "product_id"    => $product_id,
            "variation_id"  => $variation_id,
            "nimike"        => apply_filters( "laskuhari_sanitize_product_name", $item->get_name(), $item->get_data() ),
            "maara"         => $quantity,
            "yks"           => $quantity_unit,
            "veroton"       => $yks_veroton,
            "alv"           => $alv,
            "verollinen"    => $yks_verollinen,
            "ale"           => $ale,
            "yhtveroton"    => $yht_veroton,
            "yhtverollinen" => $yht_verollinen
        ] );

        $laskettu_summa += $yht_verollinen;
    }

    // lisätään alennusrivit
    foreach( $cart_discounts as $vat_rate => $amount_with_vat ) {
        if( round( $amount_with_vat, 2 ) > 0 ) {
            $discount_name = "Alennus";

            $coupon_count = count( $coupon_codes );

            if( $coupon_count > 0 ) {
                if( $coupon_count > 1 ) {
                    $discount_name = __( "Kupongit", "laskuhari" );
                } else {
                    $discount_name = __( "Kuponki", "laskuhari" );
                }
                $discount_name .= " (".implode( ", ", $coupon_codes ).")";
            }

            $discount_row = [
                "nimike"        => $discount_name,
                "maara"         => 1,
                "veroton"       => $amount_with_vat / (1 + $vat_rate / 100) * -1,
                "alv"           => $vat_rate,
                "verollinen"    => $amount_with_vat * -1,
                "ale"           => 0,
                "yhtveroton"    => $amount_with_vat / (1 + $vat_rate / 100) * -1,
                "yhtverollinen" => $amount_with_vat * -1
            ];

            $laskurivit[] = laskuhari_invoice_row( "discount", null, $order_id, $discount_row );

            $laskettu_summa += $amount_with_vat * -1;
        }
    }

    // Add an invoice surcharge row if needed. Don't add an invoicing surcharge
    // if the order is paid by another method and the invoice is only a receipt
    if( $add_surcharge && ! laskuhari_order_is_paid_by_other_method( $order ) ) {
        $laskurivit[] = laskuhari_invoice_row( "invoicing_fee", null, $order_id, [
            "nimike"        => "Laskutuslisä",
            "maara"         => 1,
            "veroton"       => $laskutuslisa_veroton,
            "alv"           => $laskutuslisa_alv,
            "verollinen"    => $laskutuslisa_verollinen,
            "ale"           => 0,
            "yhtveroton"    => $laskutuslisa_veroton,
            "yhtverollinen" => $laskutuslisa_verollinen
        ] );
    }

    if( abs( $loppusumma-$laskettu_summa ) > 0.05 ) {
        $error_notice = 'Pyöristysvirhe liian suuri ('.$loppusumma.' - '.$laskettu_summa.' = ' . round( $loppusumma-$laskettu_summa, 2 ) . ')! Laskua ei luotu';
        $order->add_order_note( $error_notice );
        if( function_exists( 'wc_add_notice' ) ) {
            wc_add_notice( 'Laskun automaattinen lähetys epäonnistui. Lähetämme laskun manuaalisesti.', 'notice' );
        }

        Logger::enabled( 'error' ) && Logger::log( sprintf(
            'Laskuhari: Rounding amount too large for order %d',
            $order_id
        ), 'error' );

        \delete_transient( $transient_name );

        return array(
            "notice" => urlencode( $error_notice )
        );
    }

    if( round( $loppusumma, 2 ) != round( $laskettu_summa, 2 ) ) {
        Logger::enabled( 'notice' ) && Logger::log( sprintf(
            'Laskuhari: Adding rounding row for order %d (%.2f / %.2f)',
            $order_id,
            $loppusumma,
            $laskettu_summa
        ), 'notice' );

        $laskurivit[] = laskuhari_invoice_row( "rounding", null, $order_id, [
            "nimike"        => "Pyöristys",
            "maara"         => 1,
            "veroton"       => ($loppusumma-$laskettu_summa),
            "alv"           => 0,
            "verollinen"    => ($loppusumma-$laskettu_summa),
            "ale"           => 0,
            "yhtveroton"    => ($loppusumma-$laskettu_summa),
            "yhtverollinen" => ($loppusumma-$laskettu_summa)
        ]);
    }

    $payload['laskurivit'] = $laskurivit;
    $payload['wc_api_version'] = 3;
    $payload['wc_plugin_version'] = laskuhari_plugin_version();

    $api_url = "https://" . laskuhari_domain() . "/rest-api/lasku/uusi";
    $api_url = apply_filters( "laskuhari_create_invoice_api_url", $api_url, $order_id );

    $payload = apply_filters( "laskuhari_create_invoice_payload", $payload, $order_id );
    $payload = json_encode( $payload, laskuhari_json_flag() );

    $response = laskuhari_api_request( $payload, $api_url, "Create invoice" );

    $error_notice = "";
    $success = "";

    if( false === $response ) {
        $error_notice = 'Virhe laskun luomisessa.';
        $order->add_order_note( $error_notice );

        Logger::enabled( 'error' ) && Logger::log( sprintf(
            'Laskuhari: Error in creating invoice, order %d',
            $order_id
        ), 'error' );

        \delete_transient( $transient_name );

        return array(
            "notice" => urlencode( $error_notice )
        );
    }

    if( $response["status"] !== "OK" ) {
        $response_json = json_encode( $response, laskuhari_json_flag() );
        $error_notice = 'Virhe laskun luomisessa: ' . $response_json;

        $order->add_order_note( $error_notice );

        Logger::enabled( 'error' ) && Logger::log( sprintf(
            'Laskuhari: Error in creating invoice, order %d: %s',
            $order_id,
            $response_json
        ), 'error' );

        \delete_transient( $transient_name );

        return array(
            "notice" => urlencode( $error_notice )
        );
    }

    if( ! isset( $response['vastaus']['laskunro'] ) ) {
        Logger::enabled( 'error' ) && Logger::log( sprintf(
            'Laskuhari: Invoice number not found in response for order %d',
            $order_id
        ), 'error' );
    }

    if( ! isset( $response['vastaus']['lasku_id'] ) ) {
        Logger::enabled( 'error' ) && Logger::log( sprintf(
            'Laskuhari: Invoice ID not found in response for order %d',
            $order_id
        ), 'error' );
    }

    if( ! isset( $response['vastaus']['meta']['maksuehto'] ) ) {
        Logger::enabled( 'error' ) && Logger::log( sprintf(
            'Laskuhari: Payment terms not found in response for order %d',
            $order_id
        ), 'error' );
    }

    if( ! isset( $response['vastaus']['meta']['maksuehtonimi'] ) ) {
        Logger::enabled( 'error' ) && Logger::log( sprintf(
            'Laskuhari: Payment terms name not found in response for order %d',
            $order_id
        ), 'error' );
    }

    $laskunro = $response['vastaus']['laskunro'] ?? null;
    $laskuid  = $response['vastaus']['lasku_id'] ?? null;
    $maksuehto = $response['vastaus']['meta']['maksuehto'] ?? null;
    $maksuehtonimi = $response['vastaus']['meta']['maksuehtonimi'] ?? null;

    // jatketaan vain, jos ei ollut virheitä
    if( intval( $laskuid ) > 0 ) {
        laskuhari_reset_order_metadata( $order->get_id() );

        laskuhari_set_order_meta( $order->get_id(), '_laskuhari_sent', false );

        laskuhari_set_order_meta( $order->get_id(), '_laskuhari_invoice_number', $laskunro );
        laskuhari_set_order_meta( $order->get_id(), '_laskuhari_invoice_id', $laskuid );
        laskuhari_set_order_meta( $order->get_id(), '_laskuhari_uid', $laskuhari_uid );
        laskuhari_set_order_meta( $order->get_id(), '_laskuhari_payment_terms', $maksuehto );
        laskuhari_set_order_meta( $order->get_id(), '_laskuhari_payment_terms_name', $maksuehtonimi );

        $order->add_order_note( sprintf( __( 'Lasku #%s luotu Laskuhariin', 'laskuhari' ), $laskunro ) );

        $status_after_creation = apply_filters( "laskuhari_status_after_creation", false, $order->get_id(), $from_gateway );
        if( $status_after_creation ) {
            $order->update_status( $status_after_creation );
        }

        do_action( "laskuhari_invoice_created", $order->get_id(), $laskuid );

        laskuhari_get_invoice_payment_status( $order->get_id() );

        // don't send separate email invoice if it is attached to confirmation email
        if( $laskuhari_gateway_object->attach_invoice_to_wc_email && $from_gateway ) {
            if( $send_method === "email" ) {
                $order->add_order_note( __("Ei lähetetä erillistä sähköpostilaskua, koska lasku liitettiin jo tilausvahvistukseen") );
                $send = false;
            }
        }

        if( apply_filters( 'laskuhari_send_after_creation', $send, $send_method, $order ) ) {
            Logger::enabled( 'debug' ) && Logger::log( sprintf(
                'Laskuhari: Sending invoice for order %d after creation',
                $order->get_id()
            ), 'debug' );

            \delete_transient( $transient_name );

            return laskuhari_send_invoice( $order, $bulk_action );
        }

    } else {
        $response_json = json_encode( $response, laskuhari_json_flag() );
        $error_notice = 'Laskun luominen Laskuhariin epäonnistui: ' . $response_json;
        $order->add_order_note( $error_notice );

        Logger::enabled( 'error' ) && Logger::log( sprintf(
            'Laskuhari: API Error in creating invoice for order %d: %s',
            $order->get_id(),
            $response_json
        ), 'error' );

        \delete_transient( $transient_name );

        return array(
            "notice" => urlencode( $error_notice )
        );
    }

    $invoice_number = laskuhari_get_post_meta( $order_id, '_laskuhari_invoice_number', true );

    Logger::enabled( 'info' ) && Logger::log( sprintf(
        'Laskuhari: Created invoice with number %s for order %d',
        $invoice_number,
        $order->get_id()
    ), 'info' );

    \delete_transient( $transient_name );

    return array(
        "luotu"   => $order->get_id(),
        "notice"  => urlencode( $error_notice ),
        "success" => urlencode( $success )
    );
}

function laskuhari_get_order_send_method( $order_id ) {
    $laskuhari_gateway_object = laskuhari_get_gateway_object();

    $send_method = laskuhari_get_post_meta( $order_id, '_laskuhari_laskutustapa', true );

    $send_methods = array(
        "verkkolasku",
        "email",
        "kirje"
    );

    if( ! in_array( $send_method, $send_methods ) ) {
        $send_method = $laskuhari_gateway_object->send_method_fallback;
    }

    return $send_method;
}

function laskuhari_get_order_billing_email( $order ) {
    if( ! is_a( $order, "WC_Order" ) ) {
        $order = wc_get_order( $order );
    }

    if( ! $order ) {
        return "";
    }

    $invoicing_email = get_laskuhari_meta( $order->get_id(), '_laskuhari_email', true );
    $invoicing_email = $invoicing_email ? $invoicing_email : get_the_author_meta( "_laskuhari_billing_email", $order->get_customer_id() );
    $invoicing_email = $invoicing_email ? $invoicing_email : $order->get_billing_email();

    $invoicing_email = apply_filters( "laskuhari_invoicing_email", $invoicing_email, $order );

    return $invoicing_email;
}

function laskuhari_send_invoice( $order, $bulk_action = false ) {
    $laskuhari_gateway_object = laskuhari_get_gateway_object();

    if( ! apply_filters( "laskuhari_can_send_invoice", true, $order, $bulk_action ) ) {
        Logger::enabled( 'warning' ) && Logger::log( sprintf(
            'Laskuhari: Sending invoice for order %d disallowed by filter',
            $order->get_id()
        ), 'warning' );

        return array(
            "notice" => urlencode( __( "Laskun lähetys estetty" ) )
        );
    }

    // laskunlähetyksen asetukset
    $info = $laskuhari_gateway_object;
    $laskuhari_uid    = $info->uid;
    $sendername       = $info->laskuttaja;

    if( ! $laskuhari_uid ) {
        Logger::enabled( 'error' ) && Logger::log( sprintf(
            'Laskuhari: UID missing while sending invoice of order %d',
            $order->get_id()
        ), 'error' );

        return laskuhari_uid_error();
    }

    $order_id = $order->get_id();

    if( laskuhari_order_is_paid_by_other_method( $order ) ) {
        $email_message = $info->invoice_email_text_for_other_payment_methods;
    } else {
        $email_message = $info->laskuviesti;
    }

    // filter email subject and message for extension capability
    $email_message = apply_filters( "laskuhari_email_message", $email_message, $order_id );
    $sendername    = apply_filters( "laskuhari_sender_name", $sendername, $order_id );

    $invoice_id = laskuhari_invoice_id_by_order( $order_id );
    $order_uid  = laskuhari_get_post_meta( $order_id, '_laskuhari_uid', true );

    if( $order_uid && $laskuhari_uid != $order_uid ) {
        Logger::enabled( 'error' ) && Logger::log( sprintf(
            'Laskuhari: UID mismatch (O:%s != L:%s) while sending invoice of order %d',
            $order_uid,
            $laskuhari_uid,
            $order->get_id()
        ), 'error' );

        $error_notice = 'Virhe laskun lähetyksessä. Lasku on luotu eri UID:llä, kuin asetuksissa määritetty UID';
        $order->add_order_note( $error_notice );

        if( function_exists( 'wc_add_notice' ) ) {
            wc_add_notice( 'Laskun automaattinen lähetys epäonnistui. Lähetämme laskun manuaalisesti.', 'notice' );
        }

        return array(
            "notice" => urlencode( $error_notice )
        );
    }

    laskuhari_update_order_meta( $order_id );

    $api_url = "https://" . laskuhari_domain() . "/rest-api/lasku/" . $invoice_id . "/laheta";

    $api_url = apply_filters( "laskuhari_send_invoice_api_url", $api_url, $order_id, $invoice_id );

    $can_send = false;

    $send_method = laskuhari_get_order_send_method( $order_id );

    $mihin = "";

    if( $send_method == "verkkolasku" ) {
        $verkkolaskuosoite = trim( get_laskuhari_meta( $order_id, '_laskuhari_verkkolaskuosoite', true ) );
        $valittaja         = trim( get_laskuhari_meta( $order_id, '_laskuhari_valittaja', true ) );
        $ytunnus           = trim( get_laskuhari_meta( $order_id, '_laskuhari_ytunnus', true ) );

        try {
            FinvoiceValidator::validate_finvoice_address( $verkkolaskuosoite, $valittaja, $ytunnus );
        } catch( FinvoiceException $e ) {
            $error_notice = 'Virhe laskun lähetyksessä: ' . $e->getMessage();
            $order->add_order_note( $error_notice );

            if( function_exists( 'wc_add_notice' ) ) {
                wc_add_notice( 'Laskun automaattinen lähetys epäonnistui. Lähetämme laskun manuaalisesti.', 'notice' );
            }

            Logger::enabled( 'error' ) && Logger::log( sprintf(
                'Laskuhari: Invalid e-invoice address for order %d: %s (%s/%s)',
                $order->get_id(),
                $e->getMessage(),
                $verkkolaskuosoite,
                $valittaja
            ), 'error' );

            return array(
                "notice" => urlencode( $error_notice )
            );
        }

        $can_send = true;
        $miten    = "verkkolaskuna";
        $mihin    = "osoitteeseen $verkkolaskuosoite ($valittaja)";

        $payload = [
            "lahetystapa" => "verkkolasku",
            "osoite"      => [
                "ytunnus" => $ytunnus,
                "toIdentifier" => $verkkolaskuosoite,
                "toIntermediator" => $valittaja,
                "buyerPartyIdentifier" => $ytunnus
            ]
        ];
    } else if( $send_method == "email" ) {
        $email = get_laskuhari_meta( $order_id, '_laskuhari_email', true );
        $email = $email ? $email : laskuhari_get_order_billing_email( $order );

        if( stripos( $email , "@" ) === false ) {
            $error_notice = 'Virhe sähköpostilaskun lähetyksessä: sähköpostiosoite puuttuu tai on virheellinen';
            $order->add_order_note( $error_notice );

            if( function_exists( 'wc_add_notice' ) ) {
                wc_add_notice( 'Laskun automaattinen lähetys epäonnistui. Lähetämme laskun manuaalisesti.', 'notice' );
            }

            Logger::enabled( 'error' ) && Logger::log( sprintf(
                'Laskuhari: Invalid email address \'%s\' for order %d',
                $email,
                $order->get_id()
            ), 'error' );

            return array(
                "notice" => urlencode( $error_notice )
            );
        }

        $can_send   = true;
        $miten      = "sähköpostitse";
        $mihin      = "osoitteeseen " . $email;
        $sendername = $sendername ? $sendername : "Laskutus";

        if( $email_message == "" ) {
            $email_message = "Liitteenä lasku.";
        }

        $payload = [
            "lahetystapa" => "email",
            "osoite"      => $email,
            "aihe"        => "Lasku",
            "viesti"      => $email_message,
            "lahettaja"   => $sendername
        ];
    } else if( $send_method == "kirje" ) {
        $can_send = true;
        $miten    = "kirjeenä";

        $payload = [
            "lahetystapa" => "kirje"
        ];
    }

    $sent_order = "";
    $success = "";

    if( $can_send ) {

        $payload = apply_filters( "laskuhari_send_invoice_payload", $payload, $order_id, $invoice_id );

        $payload = json_encode( $payload, laskuhari_json_flag() );

        $response = laskuhari_api_request( $payload, $api_url, "Send invoice" );

        if( false === $response ) {
            $error_notice = 'Virhe laskun lähetyksessä.';
            $order->add_order_note( $error_notice );

            if( function_exists( 'wc_add_notice' ) ) {
                wc_add_notice( 'Laskun automaattinen lähetys epäonnistui. Lähetämme laskun manuaalisesti.', 'notice' );
            }

            Logger::enabled( 'error' ) && Logger::log( sprintf(
                'Laskuhari: Unknown error sending invoice of order %d',
                $order->get_id()
            ), 'error' );

            return array(
                "notice" => urlencode( $error_notice )
            );
        }

        if( $response["status"] !== "OK") {
            $error_notice = 'Virhe laskun lähetyksessä: ';
            $slash = "";
            foreach( $response["virheet"] as $virhe ) {
                if( $virhe === "KEY_ERROR" ) {
                    $virhe .= " (Huomaathan, että kokeilujaksolla tai demotunnuksilla ei voi lähettää kirje- ja verkkolaskuja)";
                }
                $error_notice .= $virhe . $slash;
                $slash = " / ";
            }

            $order->add_order_note( $error_notice );
            if( function_exists( 'wc_add_notice' ) ) {
                wc_add_notice( 'Laskun automaattinen lähetys epäonnistui. Lähetämme laskun manuaalisesti.', 'notice' );
            }

            Logger::enabled( 'error' ) && Logger::log( sprintf(
                'Laskuhari: Error sending invoice of order %d: %s',
                $order->get_id(),
                $error_notice
            ), 'error' );

            return array(
                "notice" => urlencode( $error_notice )
            );
        }

        Logger::enabled( 'debug' ) && Logger::log( sprintf(
            'Laskuhari: Invoice for order %d sent %s to %s',
            $order->get_id(),
            $miten,
            substr( $mihin, 0, 3 ) . "***" . substr( $mihin, -5 )
        ), 'debug' );

        $order->add_order_note( sprintf(
            __( 'Lasku lähetetty %s %s', 'laskuhari' ),
            $miten,
            $mihin
        ) );

        laskuhari_set_order_meta( $order->get_id(), '_laskuhari_sent', 'yes' );

        $status_after_sending = apply_filters( "laskuhari_status_after_sending", false, $order->get_id() );
        if( $status_after_sending ) {
            Logger::enabled( 'debug' ) && Logger::log( sprintf(
                'Laskuhari: Changed order %d status to %s after sending',
                $order->get_id(),
                $status_after_sending
            ), 'debug' );

            $order->update_status( $status_after_sending );
        }

        $sent_order = $order->get_id();

        do_action( "laskuhari_invoice_sent", $sent_order, $invoice_id );

    } else {
        $success = "Tilauksen #" . $order->get_order_number() . " lasku tallennettu Laskuhariin. Laskua ei lähetetty vielä.";
        $sent_order = "";
        $order->add_order_note( __( 'Lasku luotu Laskuhariin, mutta ei lähetetty.', 'laskuhari' ) );

        Logger::enabled( 'info' ) && Logger::log( sprintf(
            'Laskuhari: Order %s invoice created, but not sent',
            $order->get_id()
        ), 'info' );

        $status_after_unsent_creation = apply_filters( "laskuhari_status_after_unsent_creation", false, $order->get_id() );
        if( $status_after_unsent_creation ) {
            Logger::enabled( 'debug' ) && Logger::log( sprintf(
                'Laskuhari: Changed order %d status to %s after creation (but not sending)',
                $order->get_id(),
                $status_after_unsent_creation
            ), 'debug' );

            $order->update_status( $status_after_unsent_creation );
        }

        do_action( "laskuhari_invoice_created_but_not_sent", $sent_order, $invoice_id );
    }

    return array(
        "lahetetty" => $sent_order,
        "success"   => urlencode( $success )
    );

}

/**
 * Get the product ID of a WC_Product object or a product ID.
 *
 * @param WC_Product|int $product WC_Product object or product ID.
 * @return int|false Product ID or false if the product cannot be found.
 */
function laskuhari_just_the_product_id( $product ) {
    if( is_a( $product, 'WC_Product' ) ) {
        return $product->get_id();
    } elseif( is_numeric( $product ) ) {
        return (int) $product;
    }

    return false;
}

/**
 * Schedule an event to run delayed in the background with WP Cron
 *
 * @param string $event Hook name of event
 * @param array $args Arguments to pass to event hook
 * @param bool $no_duplicates Whether to create a new event if the same one already exists in the queue
 * @param int $delay_time_seconds Amount of time to delay the execution from current moment
 * @param int $interval_time_seconds Amount of time to add between scheduled events
 * @return bool
 */
function laskuhari_schedule_background_event(
    $event,
    $args,
    $no_duplicates = false,
    $delay_time_seconds = 30,
    $interval_time_seconds = 10
) {
    $func_args = func_get_args();

    // check for duplicate events
    if( $no_duplicates && wp_next_scheduled( $event, $args ) > time() ) {
        Logger::enabled( 'notice' ) && Logger::log( sprintf(
            'Laskuhari: Not scheduling duplicate event: %s',
            json_encode( $func_args )
        ), 'notice' );
        return false;
    } else {
        Logger::enabled( 'info' ) && Logger::log( sprintf(
            'Laskuhari: Scheduling background event: %s',
            json_encode( $func_args )
        ), 'info' );
    }

    // set starting time to now + $delay_time_seconds seconds
    $time = time() + $delay_time_seconds;

    // check last scheduled event of the same type
    $last_scheduled_time = laskuhari_wp_last_scheduled( $event );

    // set the time to which ever is the latest and add $interval_time_seconds seconds
    $time = max( $time, $last_scheduled_time ) + $interval_time_seconds;

    // apply filters so other plugins can change this
    $time = apply_filters( "laskuhari_schedule_background_event_time", $time, $event, $args, $no_duplicates );

    Logger::enabled( 'debug' ) && Logger::log( sprintf(
        'Laskuhari: Scheduled background event at %d: %s',
        $time,
        json_encode( $func_args )
    ), 'debug' );

    // schedule event
    return wp_schedule_single_event( $time, $event, $args );
}

/**
 * Get the last scheduled time for a given event hook.
 *
 * @param string $hook Event hook name.
 * @return int|false Unix timestamp of the last scheduled time for the event, or false if no events are scheduled.
 */
function laskuhari_wp_last_scheduled( $hook ) {
    // get all scheduled events for the hook
    $scheduled_events = _get_cron_array();
    $times = [];

    // loop through the scheduled events and get the times
    foreach( $scheduled_events as $time => $event ) {
        if( isset( $event[$hook] ) ) {
            $times[] = $time;
        }
    }

    // sort the times in ascending order
    sort( $times );

    // return the last time
    return end( $times );
}

<?php
/**
 * Configuration file for PHPUnit tests
 */

return [
    /**
     * Configuration for testing environment WooCommerce REST API
     */
    "wc_api" => [
        "url" => "",
        "consumer_key" => "",
        "consumer_secret" => "",
    ],

    /**
     * Configuration for testing environment Laskuhari API
     */
    "laskuhari_api" => [
        "url" => "",
        "apikey" => "",
        "uid" => 123,
        "wc_order_id" => 123, // WC order ID that has an invoice
        "invoice_id" => 123, // Laskuhari invoice ID that is associated with the WC order
        "invoice_number" => 123, // Laskuhari invoice number that is associated with the WC order
    ],
];

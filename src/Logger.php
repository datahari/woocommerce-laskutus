<?php
/**
 * Laskuhari Logger
 *
 * This class handles logging of the Laskuhari for WooCommerce plugin
 *
 * @class Logger
 */

namespace Laskuhari;

use WC_Logger;

defined( 'ABSPATH' ) || exit;

class Logger
{
    /**
     * The WC_Logger instance
     *
     * @var ?WC_Logger
     */
    protected static $logger;

    /**
     * The WC_Gateway_Laskuhari instance
     *
     * @var ?WC_Gateway_Laskuhari
     */
    protected static $gateway_instance;

    /**
     * Logs a message with given level with the WooCommerce Logger
     *
     * @param string $message
     * @param string $level
     * @return void
     */
    public static function log( $message, $level = 'info' ) {
        if( ! isset( self::$logger ) ) {
            self::$logger = wc_get_logger();
        }

        self::$logger->log( $level, $message, [
            "source" => "laskuhari"
        ] );
    }

    /**
     * Checks if the specific log level is enabled
     *
     * @param string $level
     * @return bool
     */
    public static function enabled( string $level ): bool {
        if( ! isset( self::$gateway_instance ) ) {
            self::$gateway_instance = WC_Gateway_Laskuhari::get_instance();
        }

        $log_level = self::$gateway_instance->log_level;

        if( $log_level === '' ) {
            return false;
        }

        $levels = [
            'emergency' => 8,
            'alert' => 7,
            'critical' => 6,
            'error' => 5,
            'warning' => 4,
            'notice' => 3,
            'info' => 2,
            'debug' => 1,
        ];

        if( ! isset( $levels[$level] ) ) {
            throw new \Exception( sprintf( "Unknown error level '%s'", $level ) );
        }

        if( ! isset( $levels[$log_level] ) ) {
            throw new \Exception( sprintf( "Misconfigured error level: '%s' is not valid", $log_level ) );
        }

        return $levels[$level] <= $levels[$log_level];
    }
}

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
     * A random identifier for the request
     *
     * @var ?int
     */
    protected static $request_id;

    /**
     * The available logging levels
     *
     * @var array<string, int>
     */
    public const LEVELS = [
        'none' => 0,
        'emergency' => 1,
        'alert' => 2,
        'critical' => 3,
        'error' => 4,
        'warning' => 5,
        'notice' => 6,
        'info' => 7,
        'debug' => 8,
    ];

    /**
     * Logs a message with given level with the WooCommerce Logger
     *
     * @param string $message
     * @param string $level
     * @return void
     */
    public static function log( $message, $level = 'info' ) {
        if( ! isset( self::$request_id ) ) {
            self::$request_id = rand( 0, 9999 );
        }

        if( ! isset( self::$logger ) ) {
            self::$logger = \wc_get_logger();
        }

        self::$logger->log( $level, $message . " (REQ-" . self::$request_id . ")", [
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
        $gateway_instance = WC_Gateway_Laskuhari::get_instance();
        $log_level = $gateway_instance->log_level;

        $checked_level = self::LEVELS[$level] ?? null;
        $settings_level = self::LEVELS[$log_level] ?? null;

        if( $checked_level === null ) {
            Logger::log( sprintf(
                "Unknown error level '%s'",
                $level
            ), 'warning' );
            return false;
        }

        if( $settings_level === null ) {
            Logger::log( sprintf(
                "Misconfigured error level: '%s' is not valid",
                $log_level
            ), 'warning' );
            return false;
        }

        return $checked_level <= $settings_level;
    }
}

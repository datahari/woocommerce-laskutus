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
     * Hook name for the scheduled log cleanup event
     *
     * @var string
     */
    public const SCHEDULED_EVENT_HOOK = 'laskuhari_cleanup_logs';

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

    /**
     * Register log cleanup schedule.
     *
     * @return void
     */
    public static function register_log_cleanup() {
        add_action( 'plugins_loaded', function() {
            if ( ! wp_next_scheduled( self::SCHEDULED_EVENT_HOOK ) ) {
                wp_schedule_event( time(), 'daily', self::SCHEDULED_EVENT_HOOK );
            }
        } );

        add_action( self::SCHEDULED_EVENT_HOOK, [self::class, "cleanup_logs"] );
    }

    /**
     * Deletes old logs.
     * By default, logs older than 30 days will be deleted and
     * a maximum of 1 megabyte of logs will be kept
     *
     * @return void
     */
    public static function cleanup_logs() {
        $log_files = self::get_log_files();

        if( ! $log_files ) {
            return;
        }

        sort( $log_files );

        $keep_logs_for_days = apply_filters( "laskuhari_log_file_expiration_days", 30 );
        $expiration_time = time() - 3600 * 24 * $keep_logs_for_days;

        $max_filesize = apply_filters( "laskuhari_log_max_total_filesize_bytes", 1024*1024 );
        $total_filesize = 0;

        foreach( $log_files as $log_file ) {
            $total_filesize += filesize( $log_file );
            if( filemtime( $log_file ) < $expiration_time || $total_filesize > $max_filesize ) {
                @unlink( $log_file );
            }
        }
    }

    /**
     * Get all Laskuhari log files
     *
     * @return array<string>|false
     */
    public static function get_log_files() {
        $log_file = \WC_Log_Handler_File::get_log_file_path( 'laskuhari' );

        if( ! is_string( $log_file ) ) {
            return false;
        }

        $log_dir_path = dirname( $log_file );
        $log_dir_res = opendir( $log_dir_path );

        if( ! $log_dir_res ) {
            return false;
        }

        $log_files = [];
        $count = 0;
        $limit = 30;
        while( ( $file = readdir( $log_dir_res ) ) !== false ) {
            $ext = pathinfo( $file, PATHINFO_EXTENSION );
            if( $ext === "log" && strpos( $file, "laskuhari-" ) === 0 ) {
                $log_files[] = rtrim( $log_dir_path, "/" ) . "/" . $file;
                $count++;
                if( $count > $limit ) {
                    break;
                }
            }
        }

        return $log_files;
    }
}

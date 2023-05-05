<?php
/**
 * This class is used to generate troubleshooting data of the Laskuhari plugin
 */

namespace Laskuhari;

defined( 'ABSPATH' ) || exit;

class Laskuhari_Troubleshooter
{
    /**
     * The Laskuhar Payment Gateway
     *
     * @var WC_Gateway_Laskuhari
     */
    protected WC_Gateway_Laskuhari $gateway;

    public function __construct( WC_Gateway_Laskuhari $gateway ) {
        $this->gateway = $gateway;
    }

    /**
     * Generates a troubleshooting summary
     *
     * @return string
     */
    public function get_summary(): string {
        $options = get_option( $this->gateway->get_option_key(), "<settings not found>" );

        unset( $options['apikey'] );

        $settings = json_encode( $options, JSON_PRETTY_PRINT );

        $summary  = "////////////////////////////////////////////////\n";
        $summary .= "/// Laskuhari Plugin Troubleshooting Summary ///\n";
        $summary .= "////////////////////////////////////////////////\n";
        $summary .= "\n";
        $summary .= "/// Plugin Settings:\n";
        $summary .= $settings;
        $summary .= "\n\n";
        $summary .= "/// Recent log data:\n";
        $summary .= $this->read_logs( 200 )."\n";
        $summary .= "/// END OF SUMMARY\n";

        return $summary;
    }

    /**
     * Read $limit most recent lines from the Laskuhari logs
     * and return them as a string
     *
     * @param integer $limit
     * @return string
     */
    protected function read_logs( int $limit ): string {
        $log_files = $this->get_log_files();

        if( ! $log_files ) {
            return "<unable to read log files>";
        }

        rsort( $log_files );
        $log_lines = $this->read_lines_from_files( $log_files, $limit );

        return implode( "\n", $log_lines );
    }

    /**
     * Read $limit lines from an array of files
     *
     * @param array<string> $log_files
     * @param integer $limit
     * @return array<string> Array of read lines
     */
    protected function read_lines_from_files( array $log_files, int $limit ) {
        $log_lines = [];
        foreach( $log_files as $log_file ) {
            try {
                $read_lines = $this->read_file_backwards( $log_file, $limit );
            } catch( \Exception $e ) {
                Logger::enabled( 'error' ) && Logger::log( sprintf(
                    'Unable to read log file %s',
                    $log_file
                ), 'info' );
                continue;
            }

            $log_lines = array_merge( $log_lines, $read_lines );
            $limit -= count( $read_lines );

            if( $limit <= 0 ) {
                break;
            }
        }

        return $log_lines;
    }

    /**
     * Get all Laskuhari log files
     *
     * @return array<string>|false
     */
    protected function get_log_files() {
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

    /**
     * Reads a file line by line backwards
     *
     * @param string $file
     * @param ?int $limit
     * @return array<string> Array of read lines
     * 
     * @throws \Exception if file is not found
     */
    protected function read_file_backwards( string $file, $limit = null ): array {
        $read_lines = [];
        $stream = fopen( $file, "r" );

        if( $stream === false ) {
            throw new \Exception( sprintf( "File '%s' not found!", $file ) );
        }

        $current_line = "";
        $pos = -1;
        $lines = 0;

        while( fseek( $stream, $pos, SEEK_END ) !== -1 ) {
            $char = fgetc( $stream );
            if( $char === PHP_EOL ) {
                $read_lines[] = $current_line;
                $current_line = "";
                $lines++;
                if( $limit !== null && $lines > $limit ) {
                    break;
                }
            } else {
                $current_line = $char . $current_line;
            }
            $pos--;
        }

        if( $limit === null || $lines <= $limit ) {
            $read_lines[] = $current_line;
        }

        return $read_lines;
    }
}

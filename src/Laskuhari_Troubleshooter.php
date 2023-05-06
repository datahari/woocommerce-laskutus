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
     * Register AJAX endpoint for getting troubleshoot summary
     *
     * @return void
     */
    public static function register_endpoint(): void {
        add_action( 'wp_ajax_get_troubleshooting_summary', function() {
            if( ! current_user_can( 'manage_options' ) ) {
                wp_send_json_error( 'Access Denied' );
            }

            wp_send_json_success(
                esc_html( laskuhari_get_gateway_object()->get_troubleshooting_summary() )
            );
        } );
    }

    /**
     * Generates a troubleshooting summary
     *
     * @return string
     */
    public function get_summary(): string {
        $options = get_option( $this->gateway->get_option_key(), "<settings not found>" );

        if( ! is_array( $options ) ) {
            $settings = "<unable to read settings>";
        } else {
            unset( $options['apikey'] );

            $settings = json_encode( $options, JSON_PRETTY_PRINT );
        }

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
        $log_files = Logger::get_log_files();

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
     * Reads a file line by line backwards
     *
     * @param string $file
     * @param ?int $limit
     * @return array<string> Array of read lines
     * 
     * @throws \Exception if file is not found
     */
    protected function read_file_backwards( string $file, $limit = null ): array {
        $stream = fopen( $file, "r" );

        if( $stream === false ) {
            throw new \Exception( sprintf( "File '%s' not found!", $file ) );
        }

        $chunk_size = 1024;
        $filesize = filesize( $file );

        // limit chunk size to filesize
        if( $filesize < $chunk_size ) {
            $chunk_size = $filesize;
        }

        $limit_reached = false;
        $end_reached = false;
        $first_line = true;

        $line_count = 0;
        $line_buffer = "";
        $read_lines = [];

        $offset = -$chunk_size;

        while( fseek( $stream, $offset, SEEK_END ) !== -1 ) {
            if( $chunk_size <= 0 ) {
                break;
            }

            $chunk = fread( $stream, $chunk_size );

            // move offset for next iteration
            $offset -= $chunk_size;

            // if offset goes beyond file beginning
            if( ! $end_reached && $filesize + $offset <= 0 ) {
                $end_reached = true;

                // substract overflow for chunk size
                $chunk_size -= abs( $filesize + $offset );

                // move offset of next iteration to beginning of file
                $offset = -$filesize;
            }

            // break if there's no more file to read
            if( $chunk === false ) {
                break;
            }

            // split chunk into lines
            $lines = explode( PHP_EOL, $chunk );
            $len = count( $lines );

            // if no newline was found, prepend partial line to buffer
            if( $len === 1 ) {
                $line_buffer = $lines[0] . $line_buffer;
                continue;
            }

            // read lines backwards, except first (last) one
            for( $i = ( $len - 1 ); $i > 0; $i-- ) {
                // add line buffer to the line
                $line = $lines[$i] . $line_buffer;

                // trim empty lines from the bottom
                if( ! $first_line || ! empty( $line ) ) {
                    $read_lines[] = $line;
                    $first_line = false;
                }

                // empty line buffer
                $line_buffer = "";

                // if limit reached, stop reading
                if( $limit !== null && ++$line_count > $limit ) {
                    $limit_reached = true;
                    break 2;
                }
            }

            // add first (last) line to buffer
            $line_buffer = $lines[0];
        }

        // if we got to the beginning of the file, add the last (first) line
        if( $end_reached && ! $limit_reached ) {
            $read_lines[] = $line_buffer;
        }

        return $read_lines;
    }
}

<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

/**
 * Autoloader for Laskuhari plugin classes
 */

spl_autoload_register( function( $class ) {
    $class = str_replace( '\\', '/', $class );
    if( strpos( $class, 'Laskuhari/' ) === 0 ) {
        $class = substr( $class, 10 );
        require_once( dirname( __FILE__ ) . '/src/' . $class . '.php' );
    }
} );

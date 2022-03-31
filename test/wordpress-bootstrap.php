<?php
# define constants
define( "ABSPATH", __DIR__ . "/assets/wordpress/" );
define( "WP_PLUGIN_DIR", __DIR__ . "/assets/wordpress/plugins" );
define( "WPMU_PLUGIN_DIR", __DIR__ . "/assets/wordpress/mu-plugins" );
define( "WP_CONTENT_DIR", ABSPATH . "/wp-content" );
define( "WP_LANG_DIR", WP_CONTENT_DIR . "/languages" );
define( "WPINC", "wp-includes" );

# define globals
$GLOBALS['wp_plugin_paths'] = array();

# define mock functions
function __( $text ) { return $text; }
function wp_installing() { return false; }
function wp_cache_get() { return; }
function wp_cache_add() { return false; }
function wp_cache_set() { return false; }

# define mock classes
class wpdb {
    public $options;
    function suppress_errors() {}
    function get_results() {}
    function get_row() {}
    function prepare() {}
}
$wpdb = new wpdb();

# require includes
require_once( __DIR__ . "/assets/wordpress/wp-includes/plugin.php" );
require_once( __DIR__ . "/assets/wordpress/wp-includes/functions.php" );

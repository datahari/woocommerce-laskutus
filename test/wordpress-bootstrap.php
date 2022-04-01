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

$mock_post_meta = [];
function get_post_meta( $post_id, $meta_key, $single = false ) {
	global $mock_post_meta;
	return $mock_post_meta[$post_id][$meta_key] ?? "";
}

function update_post_meta( $post_id, $meta_key, $meta_value ) {
	global $mock_post_meta;
	$mock_post_meta[$post_id][$meta_key] = $meta_value;
}

$mock_user_meta = [];
function get_user_meta( $user_id, $meta_key, $single = false ) {
	global $mock_user_meta;
	return $mock_user_meta[$user_id][$meta_key] ?? "";
}

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

<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/*
Plugin updater by Jeremy Clark: https://github.com/jeremyclark13/automatic-theme-plugin-update
*/

$laskuhari_wc_api_url = 'https://www.laskuhari.fi/wc/api/';
$laskuhari_plugin_slug = plugin_basename( dirname( __FILE__ ) );

// Take over the update check
add_filter( 'pre_set_site_transient_update_plugins', 'laskuhari_check_for_plugin_update' );

function laskuhari_check_for_plugin_update( $checked_data ) {
	global $laskuhari_wc_api_url, $laskuhari_plugin_slug, $wp_version;

	if ( empty( $checked_data->checked[$laskuhari_plugin_slug .'/'. $laskuhari_plugin_slug .'.php'] ) ) {
		return $checked_data;
	}

	$args = array(
		'slug' => $laskuhari_plugin_slug,
		'version' => $checked_data->checked[$laskuhari_plugin_slug .'/'. $laskuhari_plugin_slug .'.php'],
	);

	$request_string = array(
		'body' => array(
			'action' => 'basic_check',
			'request' => serialize( $args ),
			'api-key' => md5( get_bloginfo( 'url' ) )
		),
		'user-agent' => 'WordPress/' . $wp_version . '; ' . get_bloginfo( 'url' )
	);

	// Start checking for an update
	$raw_response = wp_remote_post( $laskuhari_wc_api_url, $request_string );

	if ( ! is_wp_error( $raw_response ) && ( $raw_response['response']['code'] == 200 ) ) {
		$response = unserialize( $raw_response['body'] );
	}

	// Feed the update data into WP updater
	if ( is_object( $response ) && ! empty( $response ) ) {
		$checked_data->response[$laskuhari_plugin_slug .'/'. $laskuhari_plugin_slug .'.php'] = $response;
	}

	return $checked_data;
}


// Take over the Plugin info screen
add_filter( 'plugins_api', 'laskuhari_plugin_api_call', 10, 3 );

function laskuhari_plugin_api_call( $def, $action, $args ) {
	global $laskuhari_plugin_slug, $laskuhari_wc_api_url, $wp_version;

	if ( ! isset( $args->slug ) || ( $args->slug != $laskuhari_plugin_slug ) ) {
		return false;
	}

	// Get the current version
	$plugin_info = get_site_transient( 'update_plugins' );
	$current_version = $plugin_info->checked[$laskuhari_plugin_slug .'/'. $laskuhari_plugin_slug .'.php'];
	$args->version = $current_version;

	$request_string = array(
		'body' => array(
			'action' => $action,
			'request' => serialize( $args ),
			'api-key' => md5( get_bloginfo( 'url' ) )
		),
		'user-agent' => 'WordPress/' . $wp_version . '; ' . get_bloginfo( 'url' )
	);

	$request = wp_remote_post( $laskuhari_wc_api_url, $request_string );

	if ( is_wp_error( $request ) ) {
		$res = new WP_Error( 'plugins_api_failed', __('An Unexpected HTTP Error occurred during the API request.</p> <p><a href="?" onclick="document.location.reload(); return false;">Try again</a>'), $request->get_error_message() );
	} else {
		$res = unserialize( $request['body'] );

		if ( $res === false ) {
			$res = new WP_Error( 'plugins_api_failed', __('An unknown error occurred'), $request['body'] );
		}
	}

	return $res;
}

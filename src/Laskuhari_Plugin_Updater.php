<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

/**
 * This class is used for updating the Laskuhari plugin through the WordPress UI
 */

class Laskuhari_Plugin_Updater
{
    /**
     * API endpoint of Laskuhari plugin updater
     *
     * @var string API_URL
     */
    private const API_URL = 'https://oma.laskuhari.fi/rest-api/wc/plugin';

    /**
     * Laskuhari plugin slug
     *
     * @var string PLUGIN_SLUG
     */
    private const PLUGIN_SLUG = 'woocommerce-laskuhari-payment-gateway';

    /**
     * Mock API response for testing purposes
     *
     * @var ?array|WP_Error $mock_response
     */
    private $mock_response;

    /**
     * The singleton instance of this class
     *
     * @var ?Laskuhari_Plugin_Updater
     */
    private static $instance;

    /**
     * Private constructor for singleton class
     */
    private function __construct() {
        $this->add_filters();
    }

    /**
     * Initialize the updater
     *
     * @return Laskuhari_Plugin_Updater
     */
    public static function init() {
        if( ! isset( self::$instance ) ) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Add filters for checking Laskuhari plugin updates and fetching plugin information
     *
     * @return void
     */
    private function add_filters() {
        // filter for checking updates
        add_filter( 'pre_set_site_transient_update_plugins', [$this, 'check_for_updates'] );

        // filter for getting plugin information
        add_filter( 'plugins_api', [$this, 'plugin_api_call'], 10, 3 );
    }

    /**
     * Checks the latest version of the Laskuhari plugin and adds it into
     * the WordPress plugin update list if there is a newer version available
     *
     * @param stdClass $transient
     *
     * @return stdClass
     */
    public function check_for_updates( $transient ) {
        $current_version = $this->get_plugin_version();

        $response = $this->api_fetch( self::API_URL );

        if( is_wp_error( $response ) || ($response instanceof WP_Error) ) {
            return $transient;
        }

        if( $response['response']['code'] !== 200 ) {
            return $transient;
        }

        $result = json_decode( $response['body'] );

        if( ! ($result instanceof stdClass) ) {
            return $transient;
        }

        if( ! isset( $result->version ) ) {
            return $transient;
        }

        if( ! property_exists( $transient, "checked" ) ) {
            $transient->checked = [];
        }

        // save update as checked
        $transient->checked[self::PLUGIN_SLUG . '/' . self::PLUGIN_SLUG . '.php'] = $current_version;

        if( ! version_compare( $current_version, $result->version, '<' ) ) {
            $item = (object) [
                'id' => self::PLUGIN_SLUG . '/' . self::PLUGIN_SLUG . '.php',
                'slug' => self::PLUGIN_SLUG,
                'plugin' => self::PLUGIN_SLUG . '/' . self::PLUGIN_SLUG . '.php',
                'new_version' => $current_version,
                'url' => '',
                'package' => '',
                'icons' => array(),
                'banners' => array(),
                'banners_rtl' => array(),
                'tested' => '',
                'requires_php' => '',
                'compatibility' => new stdClass(),
            ];

            // Adding the "mock" item to the `no_update` property is required
            // for the enable/disable auto-updates links to correctly appear in UI.
            $transient->no_update[self::PLUGIN_SLUG . '/' . self::PLUGIN_SLUG . '.php'] = $item;

            return $transient;
        }

        // add response to transient
        $update_response = $this->create_update_response( $result );
        $transient->response[self::PLUGIN_SLUG . '/' . self::PLUGIN_SLUG . '.php'] = $update_response;

        return $transient;
    }

    /**
     * Create a response for the WordPress update checker
     *
     * @param stdClass $result Result from Laskuhari plugin update API
     *
     * @return stdClass Response for the WordPress plugin updater
     */
    private function create_update_response( $result ) {
        $response = (object) [
            'id' => self::PLUGIN_SLUG . '/' . self::PLUGIN_SLUG . '.php',
            'slug' => self::PLUGIN_SLUG,
            'plugin' => self::PLUGIN_SLUG . '/' . self::PLUGIN_SLUG . '.php',
            'new_version' => $result->version,
            'url' => $result->homepage,
            'package' => $result->download_link,
            'requires' => $result->requires,
            'tested' => $result->tested,
            'requires_php' => $result->requires_php,
        ];

        return $response;
    }

    /**
     * Intercepts the plugins_api call when checking Laskuhari plugin information
     * and fetches the plugin information from the Laskuhari API
     *
     * @param false|object $result
     * @param string $action
     * @param object $args
     *
     * @return false|object
     */
    public function plugin_api_call( $result, $action, $args ) {
        // don't intercept other plugins' calls
        if ( ! isset( $args->slug ) || ( $args->slug != self::PLUGIN_SLUG ) ) {
            return $result;
        }

        // we have only implemented the "plugin_information" action
        if( $action !== "plugin_information" ) {
            return $result;
        }

        $response = $this->api_fetch( self::API_URL );

        if( is_wp_error( $response ) || ($response instanceof WP_Error) ) {
            /** @var WP_Error $response */
            return new WP_Error( 'plugins_api_failed', __('An Unexpected HTTP Error occurred during the API request.</p> <p><a href="?" onclick="document.location.reload(); return false;">Try again</a>'), $response->get_error_message() );
        }

        $result = json_decode( $response['body'] );

        if( ! ($result instanceof stdClass) ) {
            return new WP_Error( 'plugins_api_failed', __('An unknown error occurred'), $response['body'] );
        }

        // convert sections to an array
        $result->sections = (array)$result->sections;

        // add slug to result
        $result->slug = self::PLUGIN_SLUG;

        return $result;
    }

    /**
     * Gets the currently installed version of the Laskuhari for WooCommerce plugin
     *
     * @return string
     */
    private function get_plugin_version() {
        $plugin_data = get_plugin_data( WP_PLUGIN_DIR . '/' . self::PLUGIN_SLUG . '/' . self::PLUGIN_SLUG . '.php' );
        return $plugin_data['Version'];
    }

    /**
     * Fetches the plugin information from the Laskuhari API
     *
     * @param string $url
     *
     * @return array|WP_Error
     */
    private function api_fetch( $url ) {
        if( isset( $this->mock_response ) ) {
            return $this->mock_response;
        }

        return wp_remote_get( $url );
    }

    /**
     * Sets a mock response from the update API for testing purposes
     *
     * @param array|WP_Error $response
     *
     * @return void
     */
    public function set_mock_response( $response ) {
        $this->mock_response = $response;
    }
}

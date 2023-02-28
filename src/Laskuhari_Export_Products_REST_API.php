<?php
/**
 * Laskuhari Export Products REST API
 *
 * This class extends the WooCommerce REST API by adding custom endpoints
 * which the Laskuhari invoicing software uses to import products from WooCommerce
 *
 * Endpoints are:
 * - /products/laskuhari-export (for exporting products)
 * - /products/laskuhari-count (for counting number of products)
 * - /products/laskuhari-test (for testing that the API is available)
 *
 * The laskuhari-export endpoint returns a list of all products and product variations
 * without the need to create separate requests for each variable product. This greatly
 * speeds up the time needed for importing products
 *
 * @class Laskuhari_Export_Products_REST_API
 */

defined( 'ABSPATH' ) || exit;

class Laskuhari_Export_Products_REST_API
{
    /**
     * Instance of the class
     *
     * @var ?Laskuhari_Export_Products_REST_API
     */
    protected static $instance;

    /**
     * Initialize the REST API endpoints
     *
     * @return void
     */
    public static function init() {
        if( ! isset( static::$instance ) ) {
            static::$instance = new self();
            add_action( 'rest_api_init', [static::$instance, 'register_endpoints'] );
        }
    }

    /**
     * Static only class
     */
    private function __construct() {}

    /**
     * Register REST API endpoints
     *
     * @return void
     */
    public function register_endpoints() {
        // product export route
        register_rest_route( 'wc/v3', '/products/laskuhari-export', [
            'methods'  => 'GET',
            'callback' => [$this, 'export_callback'],
            'permission_callback' => [$this, 'check_permission'],
            'args' => [
                'page' => [
                    'required' => false,
                    'type' => 'integer',
                    'description' => 'Which page of results to return',
                ],
                'per_page' => [
                    'required' => false,
                    'type' => 'integer',
                    'description' => 'How many products to return per page',
                    'validate_callback' => [$this, 'check_per_page_param'],
                ],
            ],
        ] );

        // count products route
        register_rest_route( 'wc/v3', '/products/laskuhari-count', [
            'methods'  => 'GET',
            'callback' => [$this, 'count_callback'],
            'permission_callback' => [$this, 'check_permission'],
        ] );

        // test API route
        register_rest_route( 'wc/v3', '/products/laskuhari-test', [
            'methods'  => 'GET',
            'callback' => [$this, 'test_callback'],
            'permission_callback' => [$this, 'check_permission'],
        ] );
    }

    /**
     * Checks that the user has permission to access API endpoint
     *
     * @param WP_REST_Request $request
     * @return bool
     */
    public function check_permission( $request ) {
        $is_allowed = current_user_can( 'manage_options' );

        // apply general filter for Laskuhari REST API permissions
        $is_allowed = apply_filters( 'laskuhari_check_rest_api_permission', $is_allowed, $request );

        // apply specific filter for this REST API endpoint permission
        $is_allowed = apply_filters( 'laskuhari_check_rest_api_permission_export', $is_allowed, $request );

        return (bool)$is_allowed;
    }

    /**
     * Checks the validity of the 'per page' parameter
     *
     * @param string $value
     * @param WP_REST_Request $request
     * @param string $param
     * @return bool
     */
    public function check_per_page_param( $value, $request, $param ) {
        $is_valid = is_numeric( $value ) && $value <= 100;
        $is_valid = apply_filters( 'laskuhari_rest_api_export_check_per_page_param', $is_valid, $value, $request, $param );

        return (bool)$is_valid;
    }

    /**
     * Handles fetching of the endpoint result for /products/laskuhari-export
     *
     * @param WP_REST_Request $request
     * @return array<int, array<string, mixed>>
     *
     */
    public function export_callback( $request ) {
        $page = $request->get_param( 'page' ) ?: 1;
        $per_page = $request->get_param( 'per_page' ) ?: 10;

        $args = [
            'post_type' => [ 'product', 'product_variation' ],
            'post_status' => 'publish',
            'posts_per_page' => $per_page,
            'paged' => $page,
            'orderby' => 'ID',
            'order' => 'DESC',
        ];

        apply_filters( 'laskuhari_rest_api_export_get_products_args', $args, $request );

        $products_and_variations = get_posts( $args );

        $data = [];

        foreach( $products_and_variations as $product_or_variation ) {
            $product = wc_get_product( $product_or_variation );

            if( ! $product instanceof WC_Product ) {
                continue;
            }

            $product_data = [
                'id'                 => $product->get_id(),
                'name'               => $product->get_name(),
                'slug'               => $product->get_slug(),
                'type'               => $product->get_type(),
                'status'             => $product->get_status(),
                'downloadable'       => $product->is_downloadable(),
                'virtual'            => $product->is_virtual(),
                'sku'                => $product->get_sku(),
                'price'              => $product->get_price(),
                'regular_price'      => $product->get_regular_price(),
                'sale_price'         => $product->get_sale_price() ? $product->get_sale_price() : null,
                'taxable'            => $product->is_taxable(),
                'tax_status'         => $product->get_tax_status(),
                'tax_class'          => $product->get_tax_class(),
                'stock_quantity'     => $product->get_stock_quantity(),
                'manage_stock'       => $product->get_manage_stock(),
                'low_stock_amount'   => $product->get_low_stock_amount(),
                'purchaseable'       => $product->is_purchasable(),
                'catalog_visibility' => $product->get_catalog_visibility(),
                'on_sale'            => $product->is_on_sale(),
                'weight'             => $product->get_weight() ? $product->get_weight() : null,
                'parent_id'          => $product->get_parent_id(),
            ];

            if( $product->is_type( 'variation' ) ) {
                /** @var WC_Product_Variation $product */
                $product_data['attributes'] = $this->get_variation_attributes( $product );
            }

            $product_data = (array)apply_filters( 'laskuhari_rest_api_export_product_data', $product_data, $product, $request );

            $data[] = $product_data;
        }

        return $data;
    }

    /**
     * Gets an array of the variation attributes in the same
     * format as in the original WooCommerce REST API
     *
     * @param WC_Product_Variation $variation
     * @return array<int, array<string, string>>
     *
     */
    protected function get_variation_attributes( $variation ) {
        $attributes = $variation->get_variation_attributes();

        $parent_id = $variation->get_parent_id();
        $parent = wc_get_product( $parent_id );

        if( ! $parent instanceof WC_Product ) {
            throw new \Exception( "Error getting parent product of variation" );
        }

        $parent_attributes = $parent->get_attributes();

        $formatted_attributes = array();
        foreach( $attributes as $name => $option ) {
            $name = preg_replace( '/^attribute_/', '', $name );

            if( isset( $parent_attributes[$name] ) ) {
                $name = $parent_attributes[$name]->get_name();
            }

            $formatted_attributes[] = array(
                'name' => $name,
                'option' => $option
            );
        }

        return $formatted_attributes;
    }

    /**
     * Handles fetching of the endpoint result for /products/laskuhari-count
     *
     * This is used to get the number of products and product variations in the system
     *
     * @param WP_REST_Request $request
     * @return array<array<string, int>>
     */
    public function count_callback( $request ) {
        return [['count' => $this->product_count()]];

    }

    /**
     * Handles fetching of the endpoint result for /products/laskuhari-test
     *
     * This is used to test if the Laskuhari API is available
     *
     * @param WP_REST_Request $request
     * @return array<array<string, string>>
     */
    public function test_callback( $request ) {
        return [['response' => 'laskuhari-active']];
    }

    /**
     * Count the number of products, including variations
     * in the database
     *
     * @return int
     */
    protected function product_count() {
        $product_count = wp_count_posts( 'product' );
        $variation_count = wp_count_posts( 'product_variation' );

        return $product_count->publish + $variation_count->publish;
    }
}

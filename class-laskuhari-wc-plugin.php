<?php
class Laskuhari_WC_Plugin
{
    /**
     * Static variable to store singleton instance of plugin
     *
     * @var Laskuhari_WC_Plugin Singleton instance of plugin
     */
    public static ?Laskuhari_WC_Plugin $instance;

    /**
     * The name of the plugin
     *
     * @var string The name of the plugin
     */
    private $plugin_name = "woocommerce-laskuhari-payment-gateway";

    /**
     * Plugin settings
     *
     * @var ?array
     */
    private ?array $settings;

    /**
     * Conversion table for setting datatypes
     *
     * @var array
     */
    private $setting_datatypes = [
        "laskutuslisa" => self::DECIMAL,
        "laskutuslisa_alv" => self::DECIMAL,
        "synkronoi_varastosaldot" => self::YESNO
    ];

    const DECIMAL = 1;
    const YESNO = 2;

    /**
     * Gets the singleton instance of the plugin
     *
     * @return Laskuhari_WC_Plugin Singleton instance of plugin
     */
    public static function instance(): Laskuhari_WC_Plugin {
        if( ! isset( self::$instance ) ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Initialize the plugin
     */
    public function __construct() {
        $this->check_dependencies();
        $this->add_payment_gateway();
        $this->add_plugin_links();
    }

    /**
     * Check plugin dependencies and add admin notices
     *
     * @return void
     */
    public function check_dependencies() {
        $errors = [];

        if( ! function_exists( "curl_init" ) ) {
            $errors[] = __( "Laskuhari-lisäosa vaatii cURL-toiminnon toimiakseen. Lisäosa poistettiin käytöstä." );
        }

        foreach( $errors as $error ) {
            add_action( 'admin_notices', function() use ( $error ) {
                echo '<div class="notice notice-error is-dismissible"><p>'.esc_html( $error ).'</p></div>';
            } );
        }

        if( ! empty( $errors ) ) {
            $this->set_setting( "enabled", false );
            $this->save_settings();
        }

        return $errors;
    }

    /**
     * Get full path to main plugin file
     *
     * @return string Full path to main plugin file
     */
    public function get_plugin_file() {
        return  __DIR__ . "/" . $this->plugin_name . ".php";
    }

    /**
     * Add Laskuhari payment gateway to list of WooCommerce payment gateway
     *
     * @return void
     */
    private function add_payment_gateway() {
        add_filter( 'woocommerce_payment_gateways', function( $methods ) {
            $methods[] = 'WC_Gateway_Laskuhari';
            return $methods;
        } );
    }

    /**
     * Add link to Laskuhari login page to plugins page
     *
     * @return void
     */
    private function add_plugin_links() {

        add_filter( 'plugin_row_meta', function( array $links, string $plugin_file ) {
            $base = plugin_basename( $this->get_plugin_file() );
        
            if( $plugin_file == $base ) {
                $links[] = '<a href="https://' . $this->get_domain() . '/" target="_blank">' . __( 'Kirjaudu Laskuhariin', 'laskuhari' ) . '</a>';
            }
        
            return $links;
        }, 10, 2 );
        
    }

    /**
     * Get the domain of Laskuhari service
     * This can be used to change to testing environment
     *
     * @return string Domain name of Laskuhari service
     */
    public function get_domain(): string {
        return "oma.laskuhari.fi";
    }

    /**
     * Gets a plugin setting
     *
     * @param string $setting Name of setting
     * @return mixed
     */
    public function get_setting( string $setting ): mixed {
        $this->init_settings();

        $value = null;

        if( isset( $this->settings[$setting] ) ) {
            $value = $this->settings[$setting];
        }

        if( isset( $this->setting_datatypes[$setting] ) ) {
            $value = $this->convert_to_datatype( $this->setting_datatypes[$setting], $value );
        }

        return $value;
    }

    /**
     * Convert between datatypes
     *
     * @param integer $datatype
     * @param mixed $value
     * @return mixed
     */
    public function convert_to_datatype( int $datatype, mixed $value ): mixed {
        if( $datatype == self::DECIMAL ) {
            return $this->parse_decimal( $value );
        }
        if( $datatype == self::YESNO ) {
            return 'yes' === $value;
        }
        return $value;
    }

    /**
     * Set a setting
     *
     * @param string $setting Name of setting
     * @param mixed $value Value of setting
     * @return boolean
     */
    public function set_setting( string $setting, mixed $value ): bool {
        $this->settings[$setting] = $value;
        return true;
    }

    /**
     * Save settings to database
     *
     * @return void
     */
    public function save_settings() {
        $this->init_settings();
        return update_option( "woocommerce_laskuhari_settings", $this->settings );
    }

    /**
     * Initialize the settings array by fetching settings from database
     *
     * @return void
     */
    public function init_settings() {
        if( empty( $this->settings ) ) {
            $this->settings = get_option( "woocommerce_laskuhari_settings" );
        }
    }

    /**
     * Parse a number into a decimal value
     *
     * @param mixed $number
     * @return float
     */
    public function parse_decimal( $number ): float {
        if( is_string( $number ) ) {
            $number = preg_replace( ['/−/', '/,/', '/[^0-9\.-]+/'], ["-", ".", ""], $number );
        }
        return floatval( $number );
    }
}

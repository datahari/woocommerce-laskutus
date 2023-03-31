<?php
namespace Laskuhari;

use WC_Order;
use WC_Order_Item_Product;
use WC_Payment_Gateway;
use WC_Product;
use WC_Shipping_Zones;

defined( 'ABSPATH' ) || exit;

/**
 * Laskuhari Gateway.
 *
 * Provides a Payment Gateway for Laskuhari invoicing.
 * Edited from WC_Gateway_COD by Datahari Solutions
 *
 * @class   WC_Gateway_Laskuhari
 */
class WC_Gateway_Laskuhari extends WC_Payment_Gateway {

    /**
     * A static instance of this class
     *
     * @var ?WC_Gateway_Laskuhari
     */
    protected static $instance;

    /**
     * Invoicing fee amount
     *
     * @var float
     */
    public $laskutuslisa;

    /**
     * Invoicing fee VAT percentage
     *
     * @var float
     */
    public $laskutuslisa_alv;

    /**
     * Title of the gateway
     *
     * @var string
     */
    public $title;

    /**
     * Fallback method for sending invoices when a sending method is not selected
     *
     * @var string
     */
    public $send_method_fallback;

    /**
     * Whether the demo mode is enabled
     *
     * @var bool
     */
    public $demotila;

    /**
     * Whether to create webhooks to Laskuhari for invoice payment status updates
     *
     * @var bool
     */
    public $create_webhooks;

    /**
     * Whether the payment status webhook has been added to Laskuhari
     *
     * @var bool
     */
    public $payment_status_webhook_added;

    /**
     * Is email invoicing enabled
     *
     * @var bool
     */
    public $email_lasku_kaytossa;

    /**
     * Is eInvoice invoicing enabled
     *
     * @var bool
     */
    public $verkkolasku_kaytossa;

    /**
     * Is letter invoicing enabled
     *
     * @var bool
     */
    public $kirjelasku_kaytossa;

    /**
     * Is stock synchronization enabled
     *
     * @var bool
     */
    public $synkronoi_varastosaldot;

    /**
     * Is automatic invoice sending at checkout enabled
     *
     * @var bool
     */
    public $auto_gateway_enabled;

    /**
     * Is automatic invoice creation at checkout enabled
     *
     * @var bool
     */
    public $auto_gateway_create_enabled;

    /**
     * Whether the invoicing payment method has to be allowed
     * on a customer by customer basis
     *
     * @var bool
     */
    public $salli_laskutus_erikseen;

    /**
     * Invoice email text
     *
     * @var string
     */
    public $laskuviesti;

    /**
     * The name of the invoicer shown as the email sender
     *
     * @var string
     */
    public $laskuttaja;

    /**
     * Description of the invoicing payment method at checkout
     *
     * @var string
     */
    public $description;

    /**
     * Text that is shown in order confirmation page and emails
     *
     * @var string
     */
    public $instructions;

    /**
     * Allow invoicing only for these shipping methods
     *
     * @var array<string>
     */
    public $enable_for_methods;

    /**
     * Is invoicing method enabled for virtual products
     *
     * @var bool
     */
    public $enable_for_virtual;

    /**
     * Whether to show the quantity unit on the invoice
     *
     * @var bool
     */
    public $show_quantity_unit;

    /**
     * Whether to calculate discount percentage on the invoice
     *
     * @var bool
     */
    public $calculate_discount_percent;

    /**
     * Send an invoice also from these payment methods
     *
     * @var array<string>
     */
    public $send_invoice_from_payment_methods;

    /**
     * The invoice email text when payment has been made using
     * other payment methods than Laskuhari
     *
     * @var string
     */
    public $invoice_email_text_for_other_payment_methods;

    /**
     * Whether to attach invoice to WooCommerce emails
     *
     * @var bool
     */
    public $attach_invoice_to_wc_email;

    /**
     * Whether to attach receipt of payment from other
     * payment methods to WooCommerce emails
     *
     * @var bool
     */
    public $attach_receipt_to_wc_email;

    /**
     * Whether to add a paid stamp to the invoice
     *
     * @var bool
     */
    public $paid_stamp;

    /**
     * Whether to send invoices as receipts
     *
     * @var bool
     */
    public $receipt_template;

    /**
     * Laskuhari UID
     *
     * @var int
     */
    public $uid;

    /**
     * Laskuhari API-key
     *
     * @var string
     */
    public $apikey;

    /**
     * Whether actions are already added
     *
     * @var bool
     */
    protected static $actions_added = false;

    /**
     * Minimum amount that can be invoiced
     *
     * @var int
     */
    protected $min_amount = 0;

    /**
     * Get a static instance of this class
     *
     * @return WC_Gateway_Laskuhari
     */
    public static function get_instance() {
        if ( ! isset( self::$instance ) ) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Construct the gateway class.
     */
    public function __construct() {
        $this->id                 = 'laskuhari';
        $this->icon               = '';
        $this->method_title       = __( 'Laskuhari', 'laskuhari' );
        $this->method_description = __( 'Käytä Laskuhari-palvelua tilausten automaattiseen laskuttamiseen.', 'laskuhari' );
        $this->has_fields         = false;

        $this->set_public_properties();
        $this->init_settings();
        $this->init_form_fields();
        $this->set_api_credentials();
        $this->set_max_and_min_amounts();
        $this->add_actions();

        self::$instance = $this;
    }

    /**
     * Set values for public properties
     *
     * @return void
     */
    protected function set_public_properties() {
        $this->laskutuslisa             = $this->parse_decimal( $this->lh_get_option( 'laskutuslisa' ) );
        $this->laskutuslisa_alv         = $this->parse_decimal( $this->lh_get_option( 'laskutuslisa_alv' ) );
        $this->title                    = $this->lh_get_option( 'title' );
        $this->send_method_fallback     = $this->lh_get_option( 'send_method_fallback' );
        $this->demotila                 = $this->lh_get_option( 'demotila' ) === 'yes' ? true : false;
        $this->create_webhooks          = $this->lh_get_option( 'create_webhooks' ) === 'yes' ? true : false;
        $this->payment_status_webhook_added = $this->lh_get_option( 'payment_status_webhook_added' ) === 'yes' ? true : false;
        $this->email_lasku_kaytossa        = $this->lh_get_option( 'email_lasku_kaytossa' ) === 'yes' ? true : false;
        $this->verkkolasku_kaytossa        = $this->lh_get_option( 'verkkolasku_kaytossa' ) === 'yes' ? true : false;
        $this->kirjelasku_kaytossa         = $this->lh_get_option( 'kirjelasku_kaytossa' ) === 'yes' ? true : false;
        $this->synkronoi_varastosaldot     = $this->lh_get_option( 'synkronoi_varastosaldot' ) === 'yes' ? true : false;
        $this->auto_gateway_enabled        = $this->lh_get_option( 'auto_gateway_enabled' ) === 'yes' ? true : false;
        $this->auto_gateway_create_enabled = $this->lh_get_option( 'auto_gateway_create_enabled' ) === 'yes' ? true : false;
        $this->salli_laskutus_erikseen     = $this->lh_get_option( 'salli_laskutus_erikseen' ) === 'yes' ? true : false;
        $this->laskuviesti                 = trim(rtrim($this->lh_get_option( 'laskuviesti' )));
        $this->laskuttaja                  = $this->lh_get_option( 'laskuttaja' );
        $this->description                 = $this->lh_get_option( 'description' );
        $this->instructions                = $this->lh_get_option( 'instructions', $this->description );
        $this->enable_for_methods          = (array)$this->lh_get_option( 'enable_for_methods', array() );
        $this->enable_for_virtual          = $this->lh_get_option( 'enable_for_virtual' ) === 'yes' ? true : false;
        $this->show_quantity_unit          = $this->lh_get_option( 'show_quantity_unit' ) === 'yes' ? true : false;
        $this->calculate_discount_percent  = $this->lh_get_option( 'calculate_discount_percent' ) === 'yes' ? true : false;
        $this->send_invoice_from_payment_methods            = (array)$this->lh_get_option( 'send_invoice_from_payment_methods', array() );
        $this->invoice_email_text_for_other_payment_methods = trim(rtrim($this->lh_get_option( 'invoice_email_text_for_other_payment_methods' )));
        $this->attach_invoice_to_wc_email                   = $this->lh_get_option( 'attach_invoice_to_wc_email' ) === "yes";
        $this->attach_receipt_to_wc_email                   = $this->lh_get_option( 'attach_receipt_to_wc_email', 'yes' ) === "yes";
        $this->paid_stamp                                   = $this->lh_get_option( 'paid_stamp' ) === "yes";
        $this->receipt_template                             = $this->lh_get_option( 'receipt_template' ) === "yes";
    }

    /**
     * Set API credentials to demo credentials if demo mode is enabled,
     * otherwise set them to the credentials entered in the settings
     *
     * @return void
     */
    protected function set_api_credentials() {
        if( $this->demotila ) {
            $this->uid    = 3175;
            $this->apikey = "31d5348328d0044b303cc5d480e6050a35000b038fb55797edfcf426f1a62c2e9e2383a351f161cb";
        } else {
            $this->uid    = (int)$this->lh_get_option( 'uid' );
            $this->apikey = $this->lh_get_option( 'apikey' );
        }
    }

    /**
     * Add actions needed for the gateway
     *
     * @return void
     */
    protected function add_actions() {
        if( static::$actions_added ) {
            return;
        }

        add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
        add_action( 'woocommerce_thankyou_laskuhari', array( $this, 'thankyou_page' ) );
        add_action( 'woocommerce_email_before_order_table', array( $this, 'email_instructions' ), 10, 3 );

        static::$actions_added = true;
    }

    /**
     * Override generate_multiselect_html function to fetch other payment methods at print time.
     * Otherwise an infinite loop of fetching payment methods will break the script
     *
     * @param string $key
     * @param array<string, array<int|string, mixed>> $data
     * @since  1.3.4
     * @return string
     */
    public function generate_multiselect_html( $key, $data ) {
        if( $key === "send_invoice_from_payment_methods" ) {
            $payment_methods = $this->get_other_payment_methods();
            $data['options'] = $payment_methods;
        }

        return parent::generate_multiselect_html( $key, $data );
    }

    /**
     * Parse and set the minimum and maximum amounts for invoicing to be enabled.
     *
     * @return void
     */
    private function set_max_and_min_amounts() {
        $max_amount = (string)$this->lh_get_option( 'max_amount' );
        $min_amount = 0;

        if( strpos( $max_amount, "-" ) ) {
            $amounts = explode( "-", $max_amount );
            $min_amount = $amounts[0];
            $max_amount = $amounts[1];
        }

        $this->min_amount = intval( apply_filters( "laskuhari_min_amount", $min_amount ) );
        $this->max_amount = intval( apply_filters( "laskuhari_max_amount", $max_amount ) );
    }

    /**
     * Get option from settings, or default value if option is not set
     *
     * @param string $option
     * @param mixed $default
     *
     * @return string
     */
    public function lh_get_option( $option, $default = null ) {
        if( null === $default && isset( $this->form_fields[$option]['default'] ) ) {
            $default = $this->form_fields[$option]['default'];
        }
        return $this->get_option( $option, $default );
    }

    /**
     * Parse decimal number to float
     *
     * @param string|int|float $number
     * @return float
     */
    public function parse_decimal( $number ) {
        return floatval( preg_replace( ['/,/', '/[^0-9\.,]+/'], ['.', ''], (string)$number ) );
    }

    /**
     * Get a list of other available payment methods
     *
     * @return array<int|string, mixed>
     */
    public function get_other_payment_methods() {
        $gateways = WC()->payment_gateways()->payment_gateways();

        $payment_methods = [];

        foreach( $gateways as $id => $gateway ) {
            if( $id === "laskuhari" ) {
                continue;
            }
            if( $gateway->enabled !== "yes" ) {
                continue;
            }
            $title = $gateway->get_method_title();
            $payment_methods[$id] = $title ? $title : $id;
        }

        return $payment_methods;
    }

    /**
     * Get the invoicing fee without tax
     *
     * @param bool $sis_alv
     * @param string $send_method
     * @return float
     */
    public function veroton_laskutuslisa( $sis_alv, $send_method ) {
        $laskutuslisa = apply_filters( "laskuhari_invoice_surcharge", $this->laskutuslisa, $send_method, $sis_alv );

        if( $sis_alv ) {
            return $laskutuslisa / ( 1 + $this->laskutuslisa_alv / 100 );
        }

        return floatval( $laskutuslisa );
    }

    /**
     * Get the invoicing fee with tax
     *
     * @param bool $sis_alv
     * @param string $send_method
     * @return float
     */
    public function verollinen_laskutuslisa( $sis_alv, $send_method ) {
        $laskutuslisa = apply_filters( "laskuhari_invoice_surcharge", $this->laskutuslisa, $send_method, $sis_alv );
        $laskutuslisa = floatval( $laskutuslisa );

        if( $sis_alv ) {
            return $laskutuslisa;
        }

        return $laskutuslisa * ( 1 + $this->laskutuslisa_alv / 100 );
    }

    /**
     * Print the invoicing method selection form
     *
     * @param bool $order_id
     * @return void
     */
    public function lahetystapa_lomake( $order_id = false ) {
        $laskutustapa = get_laskuhari_meta( $order_id, '_laskuhari_laskutustapa', true );
        $valittaja = get_laskuhari_meta( $order_id, '_laskuhari_valittaja', true );
        $verkkolaskuosoite = get_laskuhari_meta( $order_id, '_laskuhari_verkkolaskuosoite', true );
        $ytunnus = get_laskuhari_meta( $order_id, '_laskuhari_ytunnus', true );

        $email_method_text    = apply_filters( "laskuhari_email_method_text", "Sähköposti", $order_id );
        $einvoice_method_text = apply_filters( "laskuhari_einvoice_method_text", "Verkkolasku", $order_id );
        $letter_method_text   = apply_filters( "laskuhari_letter_method_text", "Kirje", $order_id );

        if( ! is_string( $email_method_text ) || ! is_string( $einvoice_method_text ) || ! is_string( $letter_method_text ) ) {
            throw new \Exception( "Invoicing method texts must be strings" );
        }

        ?>
            <div id="laskuhari-lahetystapa-lomake">
            <select id="laskuhari-laskutustapa" class="laskuhari-pakollinen" name="laskuhari-laskutustapa">
                <option value="">-- <?php echo __('Valitse laskutustapa', 'laskuhari'); ?> --</option>
                <?php if( $this->email_lasku_kaytossa || is_admin() ): ?><option value="email"<?php echo ($laskutustapa == "email" ? ' selected' : ''); ?>><?php echo __($email_method_text, 'laskuhari'); ?></option><?php endif; ?>
                <?php if( $this->verkkolasku_kaytossa || is_admin() ): ?><option value="verkkolasku"<?php echo ($laskutustapa == "verkkolasku" ? ' selected' : ''); ?>><?php echo __($einvoice_method_text, 'laskuhari'); ?></option><?php endif; ?>
                <?php if( $this->kirjelasku_kaytossa || is_admin() ): ?><option value="kirje"<?php echo ($laskutustapa == "kirje" ? ' selected' : ''); ?>><?php echo __($letter_method_text, 'laskuhari'); ?></option><?php endif; ?>
            </select>
            <?php
            if( is_admin() ) {
                $invoicing_email = laskuhari_get_order_billing_email( $order_id );
                ?>
                <div id="laskuhari-sahkoposti-tiedot" style="<?php echo ($laskutustapa == "email" ? '' : 'display: none;'); ?>">
                    <div class="laskuhari-caption"><?php echo __( 'Sähköpostiosoite', 'laskuhari' ); ?>:</div>
                    <input type="text" id="laskuhari-email" value="<?php echo esc_attr( $invoicing_email ); ?>" name="laskuhari-email" /><br />
                </div>
                <?php
                }
            ?>
            <div id="laskuhari-verkkolasku-tiedot" style="<?php echo ($laskutustapa == "verkkolasku" ? '' : 'display: none;'); ?>">
                <?php
                if( ! is_checkout() || ! laskuhari_vat_id_custom_field_exists() ) {
                    ?>
                    <div class="laskuhari-caption"><?php echo __( 'Y-tunnus', 'laskuhari' ); ?>:</div>
                    <input type="text" class="verkkolasku-pakollinen" value="<?php echo esc_attr( $ytunnus ); ?>" id="laskuhari-ytunnus" name="laskuhari-ytunnus" /><br />
                    <?php
                }
                ?>
                <div class="laskuhari-caption"><?php echo __( 'Verkkolaskuosoite / OVT', 'laskuhari' ); ?>:</div>
                <input type="text" id="laskuhari-verkkolaskuosoite" value="<?php echo esc_attr( $verkkolaskuosoite ); ?>" name="laskuhari-verkkolaskuosoite" /><br />
                <div class="laskuhari-caption"><?php echo __( 'Verkkolaskuoperaattori', 'laskuhari' ); ?>:</div>
                <select id="laskuhari-valittaja" name="laskuhari-valittaja">
                    <option value="">-- <?php echo __( 'Valitse verkkolaskuoperaattori', 'laskuhari' ); ?> ---</option>
                    <?php echo $this->operators_select_options_html( $valittaja ); ?>
                </select>
            </div>
            </div>
        <?php
    }

    /**
     * Creates HTML for operator select options
     *
     * @param string $selected_operator Selected operator code
     *
     * @return string HTML for operator select options
     */
    public function operators_select_options_html( $selected_operator ) {
        $operators = laskuhari_operators();

        $output = '<optgroup label="'.__( 'Operaattorit', 'laskuhari' ).'">';

        foreach( $operators['operators'] as $code => $name ) {
            $selected = $selected_operator === $code ? ' selected' : '';
            $output .= '<option value="'.esc_attr( $code ).'"'.$selected.'>'.esc_html( $name ).' ('.esc_html( $code ).')</option>';
        }

        $output .= '</optgroup>';

        $output .= '<optgroup label="'.__( 'Pankit', 'laskuhari' ).'">';

        foreach( $operators['banks'] as $code => $name ) {
            $selected = $selected_operator === $code ? ' selected' : '';
            $output .= '<option value="'.esc_attr( $code ).'"'.$selected.'>'.esc_html( $name ).' ('.esc_html( $code ).')</option>';
        }

        $output .= '</optgroup>';

        return $output;
    }

    /**
     * Print the reference text field
     *
     * @param int|bool $order_id
     * @return void
     */
    public function viitteenne_lomake( $order_id = false ) {
        $viitteenne = get_laskuhari_meta( $order_id, '_laskuhari_viitteenne', true );
        ?>
        <div class="laskuhari-caption"><?php echo __( 'Viitteenne', 'laskuhari' ); ?> (<?php echo __( 'valinnainen', 'laskuhari' ); ?>):</div>
        <input type="text" id="laskuhari-viitteenne" value="<?php echo esc_attr( $viitteenne ); ?>" name="laskuhari-viitteenne" />
        <?php
    }

    /**
     * Print the fields shown at checkout
     *
     * @return void
     */
    public function payment_fields() {
        $description = $this->get_description();
        if ( $description ) {
            echo wpautop( wptexturize( $description ) );
        }

        $this->lahetystapa_lomake();
        $this->viitteenne_lomake();
    }

    /**
     * Get a list of shipping methods
     *
     * @return array<int|string, mixed>
     */
    public function get_shipping_methods() {
        $shipping_methods = [];

        if ( is_admin() && class_exists( "WC_Shipping_Zones" ) ) {
            $shipping_zones = WC_Shipping_Zones::get_zones();

            if( ! is_array( $shipping_zones ) ) {
                $shipping_zones = [];
            }

            foreach ($shipping_zones as $key => $zone ) {
                $locations = $zone['zone_locations'];

                // Get shipping methods for zone
                $zone_shipping_methods = $zone['shipping_methods'];
                if( ! is_array( $zone_shipping_methods ) ) {
                    $zone_shipping_methods = [];
                }

                foreach( $zone_shipping_methods as $method ) {
                    $method_id = $method->id;

                    // add instance id (shipping zone) to method ID if exists
                    if( null !== $method->instance_id ) {
                        $method_id .= ":".$method->instance_id;
                    }

                    // get title for shipping method
                    $method_title = $method->get_title();

                    // if title is not set, get method title
                    if( ! $method_title ) {
                        $method_title = $method->get_method_title();
                    }

                    // fallback: if no method title exists, use method id
                    if( ! $method_title ) {
                        $method_title = $method_id;
                    }

                    // if locations are included, add them to name
                    if( is_array( $locations ) && count( $locations ) > 0 ) {
                        $method_title .= " (";
                        $p = "";
                        $n = 0;
                        foreach( $locations as $location ) {
                            $method_title .= $p.$location->code;

                            // list only 5 locations max
                            if( $n >= 5 ) {
                                $method_title .= "...";
                                break;
                            }
                            $p = ", ";
                            $n++;
                        }
                        $method_title .= ")";
                    }

                    $shipping_methods[$method_id] = $method_title;
                }
            }
        }

        return $shipping_methods;
    }

    /**
     * Initialise Gateway Settings Form Fields.
     *
     * @return void
     */
    public function init_form_fields() {
        $shipping_methods = $this->get_shipping_methods();

        $this->form_fields = array(
            'enabled' => array(
                'title'       => __( 'Käytössä', 'laskuhari' ),
                'label'       => __( 'Ota käyttöön Laskuhari-lisäosa', 'laskuhari' ),
                'type'        => 'checkbox',
                'description' => '',
                'default'     => 'no'
            ),
            'heading_payment_gateway' => array(
                'title'       => __( 'Maksutapa', 'laskuhari' ),
                'type'        => 'title',
                'description' => '',
            ),
            'gateway_enabled' => array(
                'title'       => __( 'Maksutapa', 'laskuhari' ),
                'label'       => __( 'Ota käyttöön Laskutus-maksutapa', 'laskuhari' ),
                'type'        => 'checkbox',
                'description' => 'Lisää verkkokaupan kassalle Laskutus-maksutavan.',
                'default'     => 'yes'
            ),
            'auto_gateway_create_enabled' => array(
                'title'       => __( 'Automaattinen luonti', 'laskuhari' ),
                'label'       => __( 'Luo laskut automaattisesti', 'laskuhari' ),
                'type'        => 'checkbox',
                'description' => 'Luodaanko laskut automaattisesti Laskuhariin, kun asiakas tekee tilauksen?',
                'default'     => 'yes'
            ),
            'auto_gateway_enabled' => array(
                'title'       => __( 'Automaattinen lähetys', 'laskuhari' ),
                'label'       => __( 'Lähetä laskut automaattisesti', 'laskuhari' ),
                'type'        => 'checkbox',
                'description' => 'Lähetetäänkö laskut automaattisesti, kun asiakas tekee tilauksen?',
                'default'     => 'yes'
            ),
            'attach_invoice_to_wc_email' => array(
                'title'       => __( 'Tilausvahvistuksen liite', 'laskuhari' ),
                'label'       => __( 'Liitä lasku tilausvahvistuksen liitteeksi', 'laskuhari' ),
                'type'        => 'checkbox',
                'description' => 'Sähköpostilaskua ei tässä tapauksessa lähetetä erikseen',
                'default'     => 'no'
            ),
            'heading_billing_methods' => array(
                'title'       => __( 'Laskutustavat', 'laskuhari' ),
                'type'        => 'title',
                'description' => '',
            ),
            'email_lasku_kaytossa' => array(
                'title'       => __( 'Sähköpostilaskut käytössä', 'laskuhari' ),
                'label'       => __( 'Ota käyttöön sähköpostilaskut', 'laskuhari' ),
                'type'        => 'checkbox',
                'description' => '',
                'default'     => 'yes'
            ),
            'verkkolasku_kaytossa' => array(
                'title'       => __( 'Verkkolaskut käytössä', 'laskuhari' ),
                'label'       => __( 'Ota käyttöön verkkolaskut', 'laskuhari' ),
                'type'        => 'checkbox',
                'description' => '',
                'default'     => 'yes'
            ),
            'kirjelasku_kaytossa' => array(
                'title'       => __( 'Kirjelaskut käytössä', 'laskuhari' ),
                'label'       => __( 'Ota käyttöön kirjelaskut', 'laskuhari' ),
                'type'        => 'checkbox',
                'description' => '',
                'default'     => 'yes'
            ),
            'send_method_fallback' => array(
                'title'       => __( 'Lähetystapa (fallback)', 'laskuhari' ),
                'label'       => __( 'Valitse laskujen lähetystapa', 'laskuhari' ),
                'type'        => 'select',
                'description' => __( 'Valitse tapa, jolla haluat lähettää laskut, joiden lähetystapaa ei ole valittu', 'laskuhari' ),
                'default'     => $this->lh_get_option( 'lahetystapa_manuaalinen', 'ei' ),
                'options'     => array(
                    'email' => __( 'Sähköpostilasku', 'laskuhari' ),
                    'kirje' => __( 'Kirjelasku', 'laskuhari' ),
                    'ei'    => __( 'Tallenna Laskuhariin, älä lähetä', 'laskuhari' )
                )
            ),
            'heading_sync' => array(
                'title'       => __( 'Synkronointi', 'laskuhari' ),
                'type'        => 'title',
                'description' => '',
            ),
            'synkronoi_varastosaldot' => array(
                'title'       => __( 'Synkronoi varastosaldot', 'laskuhari' ),
                'label'       => __( 'Pidä varastosaldot Laskuharin ja WooCommercen välillä ajan tasalla', 'laskuhari' ),
                'type'        => 'checkbox',
                'description' => '',
                'default'     => 'no'
            ),
            'create_webhooks' => array(
                'title'       => __( 'Luo webhook', 'laskuhari' ),
                'label'       => __( 'Luo webhook Laskuhariin, jotta laskujen maksustatus päivittyy WooCommerceen automaattisesti', 'laskuhari' ),
                'type'        => 'checkbox',
                'description' => '',
                'default'     => 'no'
            ),
            'heading_api_settings' => array(
                'title'       => __( 'Rajapintatiedot', 'laskuhari' ),
                'type'        => 'title',
                'description' => '',
            ),
            'uid' => array(
                'title'       => __( 'UID', 'laskuhari' ),
                'type'        => 'text',
                'description' => __( 'Laskuhari-tunnuksesi UID (kysy asiakaspalvelusta)', 'laskuhari' ),
                'default'     => __( '', 'laskuhari' ),
            ),
            'apikey' => array(
                'title'       => __( 'API-koodi', 'laskuhari' ),
                'type'        => 'password',
                'description' => 'Laskuhari-tunnuksesi API-koodi (kysy asiakaspalvelusta)',
                'default'     => __( '', 'laskuhari' ),
            ),
            'heading_demo' => array(
                'title'       => __( 'Demotila', 'laskuhari' ),
                'type'        => 'title',
                'description' => '',
            ),
            'demotila' => array(
                'title'       => __( 'Demotila', 'laskuhari' ),
                'label'       => __( 'Ota käyttöön demotila', 'laskuhari' ),
                'type'        => 'checkbox',
                'description' => 'Demotilassa et tarvitse UID- tai API-koodia. Voit lähettää vain sähköpostilaskuja, ja ne lähetetään testiyrityksen tiedoilla. Jos haluat oman yrityksesi tiedot laskulle, luo tunnukset <a href="https://www.laskuhari.fi" target="_blank">Laskuhari.fi</a>-palveluun ja liitä UID ja API-koodi lisäosan asetuksiin sekä poista demotila käytöstä.',
                'default'     => 'yes'
            ),
            'heading_texts' => array(
                'title'       => __( 'Tekstit', 'laskuhari' ),
                'type'        => 'title',
                'description' => '',
            ),
            'laskuviesti' => array(
                'title'       => __( 'Laskuviesti', 'laskuhari' ),
                'type'        => 'textarea',
                'description' => __( 'Viesti, joka lähetetään saatetekstinä sähköpostilaskun ohessa', 'laskuhari' ),
                'default'     => __( 'Kiitos tilauksestasi. Liitteenä lasku tilaamistasi tuotteista.', 'laskuhari' ),
                'desc_tip'    => true,
            ),
            'laskuttaja' => array(
                'title'       => __( 'Laskuttaja', 'laskuhari' ),
                'type'        => 'text',
                'description' => __( 'Laskuttajan nimi, joka näkyy sähköpostilaskun lähettäjänä', 'laskuhari' ),
                'default'     => __( '', 'laskuhari' ),
                'desc_tip'    => true,
            ),
            'title' => array(
                'title'       => __( 'Maksutavan nimi', 'laskuhari' ),
                'type'        => 'text',
                'description' => __( 'Tämä näkyy maksutavan nimenä asiakkaalle', 'laskuhari' ),
                'default'     => __( 'Laskutus', 'laskuhari' ),
                'desc_tip'    => true,
            ),
            'description' => array(
                'title'       => __( 'Kuvaus', 'laskuhari' ),
                'type'        => 'textarea',
                'description' => __( 'Kuvaus, joka näytetään maksutavan yhteydessä', 'laskuhari' ),
                'default'     => __( 'Maksa tilauksesi kätevästi laskulla', 'laskuhari' ),
                'desc_tip'    => true,
            ),
            'instructions' => array(
                'title'       => __( 'Ohjeet', 'laskuhari' ),
                'type'        => 'textarea',
                'description' => __( 'Ohjeet, jotka näkyvät tilausvahvistussivulla ja tilausvahvistusviestissä', 'laskuhari' ),
                'default'     => __( 'Lähetämme sinulle laskun tilauksestasi.', 'laskuhari' ),
                'desc_tip'    => true,
            ),
            'heading_billing_fee' => array(
                'title'       => __( 'Laskutuslisä', 'laskuhari' ),
                'type'        => 'title',
                'description' => '',
            ),
            'laskutuslisa' => array(
                'title'       => __( 'Laskutuslisä', 'laskuhari' ),
                'type'        => 'text',
                'description' => __( 'Laskutuslisä, joka lisätään jokaiselle laskulle (EUR, 0 = ei laskutuslisää)', 'laskuhari' ),
                'default'     => __( '0', 'laskuhari' ),
                'desc_tip'    => true,
            ),
            'laskutuslisa_alv' => array(
                'title'       => __( 'Laskutuslisän ALV-%', 'laskuhari' ),
                'type'        => 'text',
                'description' => __( 'Laskutuslisän arvonlisäveroprosentti', 'laskuhari' ),
                'default'     => __( '24', 'laskuhari' ),
                'desc_tip'    => true,
            ),
            'heading_shipping_methods' => array(
                'title'       => __( 'Toimitustavat', 'laskuhari' ),
                'type'        => 'title',
                'description' => '',
            ),
            'enable_for_methods' => array(
                'title'             => __( 'Käytössä näille toimitustavoille', 'laskuhari' ),
                'type'              => 'multiselect',
                'class'             => 'wc-enhanced-select',
                'css'               => 'width: 450px;',
                'default'           => '',
                'description'       => __( 'Jätä tyhjäksi, jos haluat laskutuksen käyttöön kaikille toimitustavoille', 'laskuhari' ),
                'options'           => $shipping_methods,
                'desc_tip'          => true,
                'custom_attributes' => array(
                    'data-placeholder' => __( 'Valitse toimitustavat', 'laskuhari' )
                )
            ),
            'heading_payment_methods' => array(
                'title'       => __( 'Tee lasku/kuitti muilla maksutavoilla tehdyistä tilauksista', 'laskuhari' ),
                'type'        => 'title',
                'description' => __( 'Tällä toiminnolla voit lähettää esim. verkkomaksuista laskun asiakkaalle kuittina maksusta', 'laskuhari' ),
            ),
            'send_invoice_from_payment_methods' => array(
                'title'             => __( 'Luo lasku myös näistä maksutavoista', 'laskuhari' ),
                'type'              => 'multiselect',
                'class'             => 'wc-enhanced-select',
                'css'               => 'width: 450px;',
                'default'           => '',
                'options'           => [],
                'desc_tip'          => true,
                'custom_attributes' => array(
                    'data-placeholder' => __( 'Valitse maksutavat', 'laskuhari' )
                )
            ),
            'attach_receipt_to_wc_email' => array(
                'title'       => __( 'Lähetä', 'laskuhari' ),
                'label'       => __( 'Liitä muilla maksutavoilla maksettujen tilausten tilausvahvistuksen liiteeksi lasku', 'laskuhari' ),
                'type'        => 'checkbox',
                'default'     => 'yes'
            ),
            'paid_stamp' => array(
                'title'             => __( 'Maksettu-leima', 'laskuhari' ),
                'label'             => __( 'Lisää maksettu-leima muilla maksutavoilla maksettuihin laskuihin', 'laskuhari' ),
                'type'              => 'checkbox',
                'default'           => 'no'
            ),
            'receipt_template' => array(
                'title'             => __( 'Lähetä kuittina', 'laskuhari' ),
                'label'             => __( 'Käytä kuittipohjaa laskupohjan sijasta muilla maksutavoilla maksetuissa tilauksissa', 'laskuhari' ),
                'type'              => 'checkbox',
                'default'           => 'no'
            ),
            'invoice_email_text_for_other_payment_methods' => array(
                'title'       => __( 'Laskuviesti (muu maksutapa)', 'laskuhari' ),
                'type'        => 'textarea',
                'description' => __( 'Teksti, joka lisätään tilausvahvistusviestiin, kun tilaus on maksettu muuta maksutapaa käyttäen', 'laskuhari' ),
                'default'     => __( 'Liitteenä laskukopio kuittina tilaamistasi tuotteista.', 'laskuhari' ),
                'desc_tip'    => true,
            ),
            'heading_order_status' => array(
                'title'       => __( 'Tilauksen tila', 'laskuhari' ),
                'type'        => 'title',
                'description' => '',
            ),
            'status_after_gateway' => array(
                'title'       => __( 'Tilauksen tila laskutuksen jälkeen', 'laskuhari' ),
                'label'       => __( 'Valitse tilauksen tila laskutuksen jälkeen', 'laskuhari' ),
                'type'        => 'select',
                'description' => __( 'Mihin tilaan haluat asettaa tilauksen, kun tilaus tehdään kassan kautta laskutus-maksutavalla?', 'laskuhari' ),
                'default'     => 'processing',
                'options'     => array(
                    'processing' => __( 'Käsittelyssä', 'laskuhari' ),
                    'completed' => __( 'Valmis', 'laskuhari' ),
                    'on-hold' => __( 'Jonossa', 'laskuhari' )
                )
            ),
            'status_after_paid' => array(
                'title'       => __( 'Tilauksen tila laskun maksun jälkeen', 'laskuhari' ),
                'label'       => __( 'Valitse tilauksen tila laskun maksun jälkeen', 'laskuhari' ),
                'type'        => 'select',
                'description' => __( 'Mihin tilaan haluat asettaa tilauksen, kun siihen liitetty lasku maksetaan?', 'laskuhari' ),
                'default'     => '',
                'options'     => array(
                    '' => __( 'Ei muutosta', 'laskuhari' ),
                    'processing' => __( 'Käsittelyssä', 'laskuhari' ),
                    'completed' => __( 'Valmis', 'laskuhari' ),
                    'on-hold' => __( 'Jonossa', 'laskuhari' )
                )
            ),
            'heading_misc' => array(
                'title'       => __( 'Sekalaiset', 'laskuhari' ),
                'type'        => 'title',
                'description' => '',
            ),
            'salli_laskutus_erikseen' => array(
                'title'       => __( 'Salli vain laskutusasiakkaille', 'laskuhari' ),
                'label'       => __( 'Salli laskutus-maksutavan valinta vain tietyille asiakkaille', 'laskuhari' ),
                'type'        => 'checkbox',
                'description' => 'Salli vain niiden asiakkaiden valita laskutus-maksutapa, joilla on käyttäjätiedoissa laskutusasiakas-rasti',
                'default'     => 'no'
            ),
            'enable_for_virtual' => array(
                'title'             => __( 'Virtuaalituotteet', 'laskuhari' ),
                'label'             => __( 'Hyväksy laskutus-maksutapa, jos tuote on virtuaalinen', 'laskuhari' ),
                'type'              => 'checkbox',
                'default'           => 'yes'
            ),
            'show_quantity_unit' => array(
                'title'             => __( 'Vie yksiköt laskulle', 'laskuhari' ),
                'label'             => __( 'Vie laskulle tuotteen märään yksikkö (kpl, kg, m, jne.)', 'laskuhari' ),
                'type'              => 'checkbox',
                'description'       => 'Toiminto vaatii yhteensopivan lisäosan (esim. Woocommerce Advanced Quantity tai Quantities and Units for WooCommerce)',
                'default'           => 'yes'
            ),
            'calculate_discount_percent' => array(
                'title'             => __( 'Laske alennus', 'laskuhari' ),
                'label'             => __( 'Laske laskurivin aleprosentti tuotteen normaalista hinnasta', 'laskuhari' ),
                'type'              => 'checkbox',
                'default'           => 'no'
            ),
            'max_amount' => array(
                'title'       => __( 'Laskutusraja', 'laskuhari' ),
                'type'        => 'text',
                'description' => __( 'Älä salli laskutus-maksutapaa, jos tilauksen summa ylittää tämän (0 = ei rajaa) (voit myös käyttää muotoa min-max, esim. 50-500)', 'laskuhari' ),
                'default'     => '0',
            ),
        );
    }

    /**
     * Checks if logged in user can use this payment method
     *
     * @return bool
     */
    private function can_use_billing() {
        $can_use_billing = true;

        if( $this->salli_laskutus_erikseen ) {
            $current_user = wp_get_current_user();

            if( ! $current_user->ID ) {
                return false;
            }

            $can_use_billing = get_the_author_meta( "laskuhari_laskutusasiakas", $current_user->ID ) === "yes";
            $can_use_billing = apply_filters( "laskuhari_customer_can_use_billing", $can_use_billing, $current_user->ID );
        }

        return (bool)$can_use_billing;
    }

    /**
     * Check If The Gateway Is Available For Use.
     *
     * @return bool
     */
    public function is_available() {
        if( apply_filters( "laskuhari_pre_is_available", true ) === false ) {
            return false;
        }

        if( $this->lh_get_option( 'gateway_enabled' ) == 'no' ) {
            return false;
        }

        if ( WC()->cart && 0 < $this->min_amount && $this->min_amount > $this->get_order_total() ) {
            return false;
        }

        $order          = null;
        $needs_shipping = false;

        // Test if shipping is needed first
        if ( WC()->cart && WC()->cart->needs_shipping() ) {

            $needs_shipping = true;

        } elseif ( is_page( wc_get_page_id( 'checkout' ) ) && 0 < get_query_var( 'order-pay' ) ) {

            $order_id = absint( get_query_var( 'order-pay' ) );
            $order    = wc_get_order( $order_id );

            if( ! $order instanceof WC_Order ) {
                return false;
            }

            // Test if order needs shipping.
            if ( 0 < sizeof( $order->get_items() ) ) {

                foreach ( $order->get_items() as $item ) {

                    if( is_a( $item, WC_Order_Item_Product::class ) ) {
                        /** @var WC_Order_Item_Product $item */
                        $_product = $item->get_product();

                        if ( $_product instanceof WC_Product && $_product->needs_shipping() ) {
                            $needs_shipping = true;
                            break;
                        }
                    }
                }
            }
        }

        $needs_shipping = apply_filters( 'woocommerce_cart_needs_shipping', $needs_shipping );

        // Virtual order, with virtual disabled
        if ( ! $this->enable_for_virtual && ! $needs_shipping ) {
            return false;
        }

        // Tarkista käyttäjän laskutusasiakas-tieto
        if( ! $this->can_use_billing() ) {
            return false;
        }

        // Check methods
        if ( ! empty( $this->enable_for_methods ) && $needs_shipping ) {

            // Only apply if all packages are being shipped via chosen methods, or order is virtual
            $chosen_shipping_methods_session = WC()->session->get( 'chosen_shipping_methods' );

            if ( is_array( $chosen_shipping_methods_session ) ) {
                $chosen_shipping_methods = array_unique( $chosen_shipping_methods_session );
            } else {
                $chosen_shipping_methods = array();
            }

            $check_methods = [];

            if ( is_object( $order ) ) {
                foreach( $order->get_shipping_methods() as $item ){
                    $check_methods[] = $item->get_method_id().":".$item->get_instance_id();
                }

            } elseif ( empty( $chosen_shipping_methods ) || count( $chosen_shipping_methods ) > 1 ) {
                $check_methods = [];
            } elseif ( count( $chosen_shipping_methods ) == 1 ) {
                $check_methods = [$chosen_shipping_methods[0]];
            }

            if ( ! count( $check_methods ) ) {
                return false;
            }

            foreach( $check_methods as $check_method ) {
                $found = false;

                foreach ( $this->enable_for_methods as $method_id ) {
                    if( strpos( $method_id, ":" ) === false ) {
                        // fallback for older plugin versions (<= 1.1)
                        if( strpos( $check_method, $method_id ) === 0 ) {
                            $found = true;
                            break;
                        }
                    } else {
                        if( $check_method == $method_id ) {
                            $found = true;
                            break;
                        }
                    }
                }

                if ( ! $found ) {
                    return false;
                }
            }
        }

        return (bool)apply_filters( "laskuhari_is_available", parent::is_available() );
    }


    /**
     * Process the payment and return the result.
     *
     * @param int $order_id
     * @return array<string, string>
     */
    public function process_payment( $order_id ) {

        if( $this->auto_gateway_create_enabled ) {
            if( $this->attach_invoice_to_wc_email ) {
                laskuhari_process_action( $order_id, $this->auto_gateway_enabled, false, true );
            } else {
                laskuhari_process_action_delayed( $order_id, $this->auto_gateway_enabled, false, true );
            }
        }

        $order = wc_get_order( $order_id );

        if( ! $order instanceof WC_Order ) {
            throw new \Exception( "Unable to process order" );
        }

        do_action( "laskuhari_action_after_payment_completed_before_update_status" );

        $status_after_payment = $this->lh_get_option( "status_after_gateway" );
        $status_after_payment = apply_filters( "laskuhari_status_after_payment", $status_after_payment, $order_id );

        if( ! is_string( $status_after_payment ) ) {
            throw new \Exception( "Status after payment must be string, " . gettype( $status_after_payment ) . " given" );
        }

        $order->update_status( $status_after_payment );

        do_action( "laskuhari_action_after_payment_completed_before_reduce_stock_levels" );

        // Reduce stock levels
        $reduce_stock_levels = apply_filters( "laskuhari_reduce_stock_levels_after_payment", true, $order_id );
        if( $reduce_stock_levels ) {
            wc_reduce_stock_levels( $order_id );
        }

        do_action( "laskuhari_action_after_payment_completed_before_cart_empty" );

        // Remove cart
        WC()->cart->empty_cart();

        do_action( "laskuhari_action_after_payment_completed_after_cart_empty" );

        // Return thankyou redirect
        return array(
            'result'     => 'success',
            'redirect'    => $this->get_return_url( $order )
        );
    }

    /**
     * Output for the order received page.
     *
     * @return void
     */
    public function thankyou_page() {

        if ( $this->instructions ) {
            echo wpautop( wptexturize( $this->instructions ) );
        }

    }

    /**
     * Add content to the WC emails.
     *
     * @access public
     * @param WC_Order $order
     * @param bool $sent_to_admin
     * @param bool $plain_text
     *
     * @return void
     */
    public function email_instructions( $order, $sent_to_admin, $plain_text = false ) {

        if ( $this->instructions && ! $sent_to_admin && 'laskuhari' === $order->get_payment_method() ) {
            echo wpautop( wptexturize( $this->instructions ) ) . PHP_EOL;
        }

    }
}

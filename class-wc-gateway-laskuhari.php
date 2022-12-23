<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

/**
 * Laskuhari Gateway.
 *
 * Provides a Payment Gateway for Laskuhari invoicing.
 * Edited from WC_Gateway_COD by Datahari Solutions
 *
 * @class   WC_Gateway_Laskuhari
 * @extends WC_Payment_Gateway
 */
class WC_Gateway_Laskuhari extends WC_Payment_Gateway {

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
     * @var array
     */
    public $enable_for_methods;

    /**
     * Allow invoicing only for these customers
     *
     * @var array
     */
    public $enable_for_customers;

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
     * @var array
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
     * Whether to add a paid stamp to the invoice
     *
     * @var bool
     */
    public $paid_stamp;

    /**
     * Whether to send invoices as receipts
     *
     * @var string
     */
    public $receipt_template;

    /**
     * Construct the gateway class.
     */
    public function __construct() {
        $this->id                 = 'laskuhari';
        $this->icon               = apply_filters( 'woocommerce_laskuhari_icon', '' );
        $this->method_title       = __( 'Laskuhari', 'laskuhari' );
        $this->method_description = __( 'Käytä Laskuhari-palvelua tilausten automaattiseen laskuttamiseen.', 'laskuhari' );
        $this->has_fields         = false;

        $this->set_public_properties();
        $this->init_settings();
        $this->init_form_fields();
        $this->set_api_credentials();
        $this->set_max_and_min_amounts();
        $this->add_actions();
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
        $this->enable_for_methods          = $this->lh_get_option( 'enable_for_methods', array() );
        $this->enable_for_customers        = $this->lh_get_option( 'enable_for_customers', array() );
        $this->enable_for_virtual          = $this->lh_get_option( 'enable_for_virtual' ) === 'yes' ? true : false;
        $this->show_quantity_unit          = $this->lh_get_option( 'show_quantity_unit' ) === 'yes' ? true : false;
        $this->calculate_discount_percent  = $this->lh_get_option( 'calculate_discount_percent' ) === 'yes' ? true : false;
        $this->send_invoice_from_payment_methods            = $this->lh_get_option( 'send_invoice_from_payment_methods', array() );
        $this->invoice_email_text_for_other_payment_methods = trim(rtrim($this->lh_get_option( 'invoice_email_text_for_other_payment_methods' )));
        $this->attach_invoice_to_wc_email                   = $this->lh_get_option( 'attach_invoice_to_wc_email' ) === "yes";
        $this->paid_stamp                                   = $this->lh_get_option( 'paid_stamp' );
        $this->receipt_template                             = $this->lh_get_option( 'receipt_template' );
    }

    /**
     * Set API credentials to demo credentials if demo mode is enabled,
     * otherwise set them to the credentials entered in the settings
     *
     * @return void
     */
    protected function set_api_credentials() {
        if( $this->demotila == "yes" ) {
            $this->uid    = "3175";
            $this->apikey = "31d5348328d0044b303cc5d480e6050a35000b038fb55797edfcf426f1a62c2e9e2383a351f161cb";
        } else {
            $this->uid    = $this->lh_get_option( 'uid' );
            $this->apikey = $this->lh_get_option( 'apikey' );
        }
    }

    /**
     * Add actions needed for the gateway
     *
     * @return void
     */
    protected function add_actions() {
        add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
        add_action( 'woocommerce_thankyou_laskuhari', array( $this, 'thankyou_page' ) );
        add_action( 'woocommerce_email_before_order_table', array( $this, 'email_instructions' ), 10, 3 );
    }

    /**
     * Override generate_multiselect_html function to fetch other payment methods at print time.
     * Otherwise an infinite loop of fetching payment methods will break the script
     *
     * @param string $key
     * @param array $data
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
     * @return void
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
     * @param mixed $number
     * @return float
     */
    public function parse_decimal( $number ) {
        return preg_replace( ['/,/', '/[^0-9\.,]+/'], ['.', ''], $number );
    }

    /**
     * Get a list of other available payment methods
     *
     * @return array
     */
    public function get_other_payment_methods() {
        $gateways = WC()->payment_gateways->payment_gateways();

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

        return $laskutuslisa;
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

        if( $sis_alv ) {
            return $laskutuslisa;
        }

        return $laskutuslisa * ( 1 + $this->laskutuslisa_alv / 100 );
    }

    /**
     * Print the invoicing method selection form
     *
     * @param boolean $order_id
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
                    <optgroup label="<?php echo __( 'Operaattorit', 'laskuhari' ); ?>">
                        <option value="003723327487"<?php    echo ($valittaja == "003723327487" ? ' selected' : ''); ?>>Apix Messaging Oy (003723327487)</option>
                        <option value="BAWCFI22"<?php        echo ($valittaja == "BAWCFI22"     ? ' selected' : ''); ?>>Basware Oyj (BAWCFI22)</option>
                        <option value="003703575029"<?php    echo ($valittaja == "003703575029" ? ' selected' : ''); ?>>CGI (003703575029)</option>
                        <option value="885790000000418"<?php echo ($valittaja == "885790000000418" ? ' selected' : ''); ?>>HighJump AS (885790000000418)</option>
                        <option value="INEXCHANGE"<?php      echo ($valittaja == "INEXCHANGE"   ? ' selected' : ''); ?>>InExchange Factorum AB (INEXCHANGE)</option>
                        <option value="EXPSYS"<?php          echo ($valittaja == "EXPSYS"       ? ' selected' : ''); ?>>Lexmark Expert Systems AB (EXPSYS)</option>
                        <option value="003708599126"<?php    echo ($valittaja == "003708599126" ? ' selected' : ''); ?>>Liaison Technologies Oy (003708599126)</option>
                        <option value="003721291126"<?php    echo ($valittaja == "003721291126" ? ' selected' : ''); ?>>Maventa (003721291126)</option>
                        <option value="003726044706"<?php    echo ($valittaja == "003726044706" ? ' selected' : ''); ?>>Netbox Finland Oy (003726044706)</option>
                        <option value="E204503"<?php         echo ($valittaja == "E204503"      ? ' selected' : ''); ?>>OpusCapita Solutions Oy (E204503)</option>
                        <option value="003723609900"<?php    echo ($valittaja == "003723609900" ? ' selected' : ''); ?>>Pagero (003723609900)</option>
                        <option value="PALETTE"<?php         echo ($valittaja == "PALETTE"      ? ' selected' : ''); ?>>Palette Software (PALETTE)</option>
                        <option value="003710948874"<?php    echo ($valittaja == "003710948874" ? ' selected' : ''); ?>>Posti Messaging Oy (003710948874)</option>
                        <option value="003701150617"<?php    echo ($valittaja == "003701150617" ? ' selected' : ''); ?>>PostNord Strålfors Oy (003701150617)</option>
                        <option value="003714377140"<?php    echo ($valittaja == "003714377140" ? ' selected' : ''); ?>>Ropo Capital Oy (003714377140)</option>
                        <option value="003703575029"<?php    echo ($valittaja == "003703575029" ? ' selected' : ''); ?>>Telia (003703575029)</option>
                        <option value="003701011385"<?php    echo ($valittaja == "003701011385" ? ' selected' : ''); ?>>Tieto Oyj (003701011385)</option>
                        <option value="885060259470028"<?php echo ($valittaja == "885060259470028" ? ' selected' : ''); ?>>Tradeshift (885060259470028)</option>
                    </optgroup>
                    <optgroup label="<?php echo __('Pankit', 'laskuhari'); ?>">
                        <option value="HELSFIHH"<?php echo ($valittaja == "HELSFIHH" ? ' selected' : ''); ?>>Aktia (HELSFIHH)</option>
                        <option value="DABAFIHH"<?php echo ($valittaja == "DABAFIHH" ? ' selected' : ''); ?>>Danske Bank (DABAFIHH)</option>
                        <option value="DNBAFIHX"<?php echo ($valittaja == "DNBAFIHX" ? ' selected' : ''); ?>>DNB (DNBAFIHX)</option>
                        <option value="HANDFIHH"<?php echo ($valittaja == "HANDFIHH" ? ' selected' : ''); ?>>Handelsbanken (HANDFIHH)</option>
                        <option value="NDEAFIHH"<?php echo ($valittaja == "NDEAFIHH" ? ' selected' : ''); ?>>Nordea Pankki (NDEAFIHH)</option>
                        <option value="ITELFIHH"<?php echo ($valittaja == "ITELFIHH" ? ' selected' : ''); ?>>Oma Säästöpankki (ITELFIHH)</option>
                        <option value="OKOYFIHH"<?php echo ($valittaja == "OKOYFIHH" ? ' selected' : ''); ?>>Osuuspankit (OKOYFIHH)</option>
                        <option value="OKOYFIHH"<?php echo ($valittaja == "OKOYFIHH" ? ' selected' : ''); ?>>Pohjola Pankki (OKOYFIHH)</option>
                        <option value="POPFFI22"<?php echo ($valittaja == "POPFFI22" ? ' selected' : ''); ?>>POP Pankki  (POPFFI22)</option>
                        <option value="SBANFIHH"<?php echo ($valittaja == "SBANFIHH" ? ' selected' : ''); ?>>S-Pankki (SBANFIHH)</option>
                        <option value="TAPIFI22"<?php echo ($valittaja == "TAPIFI22" ? ' selected' : ''); ?>>LähiTapiola (TAPIFI22)</option>
                        <option value="ITELFIHH"<?php echo ($valittaja == "ITELFIHH" ? ' selected' : ''); ?>>Säästöpankit (ITELFIHH)</option>
                        <option value="AABAFI22"<?php echo ($valittaja == "AABAFI22" ? ' selected' : ''); ?>>Ålandsbanken (AABAFI22)</option>
                    </optgroup>
                </select>
            </div>
            </div>
        <?php
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
     * @return array
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
            'uid' => array(
                'title'       => __( 'UID', 'laskuhari' ),
                'type'        => 'text',
                'description' => __( 'Laskuhari-tunnuksesi UID (kysy asiakaspalvelusta)', 'laskuhari' ),
                'default'     => __( '', 'laskuhari' ),
            ),
            'apikey' => array(
                'title'       => __( 'API-koodi', 'laskuhari' ),
                'type'        => 'text',
                'description' => 'Laskuhari-tunnuksesi API-koodi (kysy asiakaspalvelusta)',
                'default'     => __( '', 'laskuhari' ),
            ),
            'demotila' => array(
                'title'       => __( 'Demotila', 'laskuhari' ),
                'label'       => __( 'Ota käyttöön demotila', 'laskuhari' ),
                'type'        => 'checkbox',
                'description' => 'Demotilassa et tarvitse UID- tai API-koodia. Voit lähettää vain sähköpostilaskuja, ja ne lähetetään testiyrityksen tiedoilla. Jos haluat oman yrityksesi tiedot laskulle, luo tunnukset <a href="https://www.laskuhari.fi" target="_blank">Laskuhari.fi</a>-palveluun ja liitä UID ja API-koodi lisäosan asetuksiin sekä poista demotila käytöstä.',
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
            'send_invoice_from_payment_methods' => array(
                'title'             => __( 'Lähetä lasku myös näistä maksutavoista', 'laskuhari' ),
                'type'              => 'multiselect',
                'class'             => 'wc-enhanced-select',
                'css'               => 'width: 450px;',
                'default'           => '',
                'description'       => __( 'Tällä toiminnolla voit lähettää esim. verkkomaksuista laskun asiakkaalle kuittina maksusta (lähetetään tilausvahvistuksen liitteenä)', 'laskuhari' ),
                'options'           => [],
                'desc_tip'          => true,
                'custom_attributes' => array(
                    'data-placeholder' => __( 'Valitse maksutavat', 'laskuhari' )
                )
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
     * @return boolean
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

        return $can_use_billing;
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

            // Test if order needs shipping.
            if ( 0 < sizeof( $order->get_items() ) ) {

                foreach ( $order->get_items() as $item ) {

                    $_product = $order->get_product_from_item( $item );

                    if ( $_product && $_product->needs_shipping() ) {
                        $needs_shipping = true;
                        break;
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

            if ( isset( $chosen_shipping_methods_session ) ) {
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

        return apply_filters( "laskuhari_is_available", parent::is_available() );
    }


    /**
     * Process the payment and return the result.
     *
     * @param int $order_id
     * @return array
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

        do_action( "laskuhari_action_after_payment_completed_before_update_status" );

        $status_after_payment = $this->lh_get_option( "status_after_gateway" );
        $status_after_payment = apply_filters( "laskuhari_status_after_payment", $status_after_payment, $order_id );

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
     */
    public function email_instructions( $order, $sent_to_admin, $plain_text = false ) {

        if ( $this->instructions && ! $sent_to_admin && 'laskuhari' === $order->get_payment_method() ) {
            echo wpautop( wptexturize( $this->instructions ) ) . PHP_EOL;
        }

    }
}

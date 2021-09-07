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

    public function __construct( $only_settings = false ) {
        $this->id                 = 'laskuhari';
        $this->icon               = apply_filters( 'woocommerce_laskuhari_icon', '' );
        $this->method_title       = __( 'Laskuhari', 'laskuhari' );
        $this->method_description = __( 'Käytä Laskuhari-palvelua tilausten automaattiseen laskuttamiseen.', 'laskuhari' );
        $this->has_fields         = false;

        // Load the settings
        if( ! $only_settings ) {
            $this->init_settings();
        }

        $this->init_form_fields();

        // Get settings
        $this->laskutuslisa             = $this->parse_decimal( $this->lh_get_option( 'laskutuslisa' ) );
        $this->laskutuslisa_alv         = $this->parse_decimal( $this->lh_get_option( 'laskutuslisa_alv' ) );
        $this->title                    = $this->lh_get_option( 'title' );
        $this->send_method_fallback     = $this->lh_get_option( 'send_method_fallback' );
        $this->demotila                 = $this->lh_get_option( 'demotila' ) === 'yes' ? true : false;
        $this->create_webhooks          = $this->lh_get_option( 'create_webhooks' ) === 'yes' ? true : false;
        $this->payment_status_webhook_added = $this->lh_get_option( 'payment_status_webhook_added' ) === 'yes' ? true : false;

        if( $this->demotila == "yes" ) {
            $this->uid    = "3175";
            $this->apikey = "31d5348328d0044b303cc5d480e6050a35000b038fb55797edfcf426f1a62c2e9e2383a351f161cb";
        } else {
            $this->uid    = $this->lh_get_option( 'uid' );
            $this->apikey = $this->lh_get_option( 'apikey' );
        }

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

        $this->send_invoice_from_payment_methods            = $this->lh_get_option( 'send_invoice_from_payment_methods', array() );
        $this->invoice_email_text_for_other_payment_methods = trim(rtrim($this->lh_get_option( 'invoice_email_text_for_other_payment_methods' )));

        if( ! $only_settings ) {
            add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
            add_action( 'woocommerce_thankyou_laskuhari', array( $this, 'thankyou_page' ) );
            add_action( 'woocommerce_email_before_order_table', array( $this, 'email_instructions' ), 10, 3 );

            // add webhooks if not added yet and not in demo mode
            if( $this->create_webhooks && $this->demotila != "yes" && strlen( $this->apikey ) > 64 && $this->uid ) {
                $api_url = site_url( "/index.php" ) . "?__laskuhari_api=true";

                if( ! $this->payment_status_webhook_added ) {
                    if( laskuhari_add_webhook( "payment_status", $api_url ) ) {
                        $this->update_option( "payment_status_webhook_added", "yes" );
                        $this->payment_status_webhook_added = true;
                    }
                }
            } elseif( $this->payment_status_webhook_added ) {
                $this->update_option( "payment_status_webhook_added", "no" );
                $this->payment_status_webhook_added = false;
            }
        }
    }

    public function lh_get_option( $option, $default = null ) {
        if( null === $default && isset( $this->form_fields[$option]['default'] ) ) {
            $default = $this->form_fields[$option]['default'];
        }
        return $this->get_option( $option, $default );
    }

    public function parse_decimal( $number ) {
        return preg_replace( ['/,/', '/[^0-9\.,]+/'], ['.', ''], $number );
    }

    public function get_other_payment_methods() {
        $gateways = array(
            'WC_Gateway_Paypal'
        );

        $gateways = apply_filters( 'woocommerce_payment_gateways', $gateways );
        $skip_gateways = apply_filters( 'laskuhari_skip_gateways', ["WC_Gateway_Laskuhari"] );

        $payment_methods = [];

        if( ! is_array( $gateways ) ) {
            return $payment_methods;
        }

        foreach( $gateways as $gateway_class ) {
            if( in_array( $gateway_class, $skip_gateways ) ) {
                continue;
            }
            if( ! class_exists( $gateway_class ) ) {
                continue;
            }
            try {
                $gateway = new $gateway_class();
            } catch( \Throwable $e ) {
                continue;
            }
            if( $gateway->enabled == 'yes' ) {
                $payment_methods[$gateway->id] = $gateway->method_title ? $gateway->method_title : $id;
            }
        }

        return $payment_methods;
    }

    public function veroton_laskutuslisa( $sis_alv ) {
        if( $sis_alv ) {
            return $this->laskutuslisa / ( 1 + $this->laskutuslisa_alv / 100 );
        }
        return $this->laskutuslisa;
    }

    public function verollinen_laskutuslisa( $sis_alv ) {
        if( $sis_alv ) {
            return $this->laskutuslisa;
        }
        return $this->laskutuslisa * ( 1 + $this->laskutuslisa_alv / 100 );
    }

    public function lahetystapa_lomake( $order_id = false ) {
        $laskutustapa = get_laskuhari_meta( $order_id, '_laskuhari_laskutustapa', true );
        $valittaja = get_laskuhari_meta( $order_id, '_laskuhari_valittaja', true );
        ?>
            <div id="laskuhari-lahetystapa-lomake">
            <select id="laskuhari-laskutustapa" class="laskuhari-pakollinen" name="laskuhari-laskutustapa">
                <option value="">-- <?php echo __('Valitse laskutustapa', 'laskuhari'); ?> --</option>
                <?php if( $this->email_lasku_kaytossa ): ?><option value="email"<?php echo ($laskutustapa == "email" ? ' selected' : ''); ?>><?php echo __('Sähköposti', 'laskuhari'); ?></option><?php endif; ?>
                <?php if( $this->verkkolasku_kaytossa ): ?><option value="verkkolasku"<?php echo ($laskutustapa == "verkkolasku" ? ' selected' : ''); ?>><?php echo __('Verkkolasku', 'laskuhari'); ?></option><?php endif; ?>
                <?php if( $this->kirjelasku_kaytossa ): ?><option value="kirje"<?php echo ($laskutustapa == "kirje" ? ' selected' : ''); ?>><?php echo __('Kirje', 'laskuhari'); ?></option><?php endif; ?>
            </select>
            <?php
            if( is_admin() ) {
                $invoicing_email = get_laskuhari_meta( $order_id, '_laskuhari_email', true );
                if( ! $invoicing_email ) {
                    $order = wc_get_order( $order_id );
                    if( $order ) {
                        $invoicing_email = $order->get_billing_email();
                    }
                }
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
                    <input type="text" class="verkkolasku-pakollinen" value="<?php echo esc_attr( get_laskuhari_meta( $order_id, '_laskuhari_ytunnus', true ) ); ?>" id="laskuhari-ytunnus" name="laskuhari-ytunnus" /><br />
                    <?php
                }
                ?>
                <div class="laskuhari-caption"><?php echo __( 'Verkkolaskuosoite / OVT', 'laskuhari' ); ?>:</div>
                <input type="text" id="laskuhari-verkkolaskuosoite" value="<?php echo esc_attr( get_laskuhari_meta( $order_id, '_laskuhari_verkkolaskuosoite', true ) ); ?>" name="laskuhari-verkkolaskuosoite" /><br />
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

    public function viitteenne_lomake( $order_id = false ) {
        ?>
        <div class="laskuhari-caption"><?php echo __( 'Viitteenne', 'laskuhari' ); ?> (<?php echo __( 'valinnainen', 'laskuhari' ); ?>):</div>
        <input type="text" id="laskuhari-viitteenne" value="<?php echo esc_attr( get_laskuhari_meta( $order_id, '_laskuhari_viitteenne', true ) ); ?>" name="laskuhari-viitteenne" />
        <?php
    }

    public function payment_fields() {
        $description = $this->get_description();
        if ( $description ) {
            echo wpautop( wptexturize( $description ) );
        }

        $this->lahetystapa_lomake();
        $this->viitteenne_lomake();
    }

    /**
     * Initialise Gateway Settings Form Fields.
     */
    public function init_form_fields() {
        $shipping_methods = array();

        if ( is_admin() && class_exists( "WC_Shipping_Zones" ) ) {
            // Get shipping zones
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

        $payment_methods = $this->get_other_payment_methods();

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
                'description' => __( 'Valitse tapa, jolla haluat lähettää massatoiminnolla lähetettävät laskut ja laskut, joiden lähetystapaa ei ole valittu', 'laskuhari' ),
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
                'description'       => __( 'Tällä toiminnolla voit lähettää esim. verkkomaksuista laskun asiakkaalle kuittina maksusta (lähetetään vain jos automaattinen lähetys on käytössä)', 'laskuhari' ),
                'options'           => $payment_methods,
                'desc_tip'          => true,
                'custom_attributes' => array(
                    'data-placeholder' => __( 'Valitse maksutavat', 'laskuhari' )
                )
            ),
            'invoice_email_text_for_other_payment_methods' => array(
                'title'       => __( 'Laskuviesti (muu maksutapa)', 'laskuhari' ),
                'type'        => 'textarea',
                'description' => __( 'Viesti, joka lähetetään saatetekstinä sähköpostilaskun ohessa, kun tilaus on maksettu muuta maksutapaa käyttäen', 'laskuhari' ),
                'default'     => __( 'Kiitos tilauksestasi. Liitteenä laskukopio kuittina tilaamistasi tuotteista.', 'laskuhari' ),
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
            )
        );
    }

    /**
     * Check If The Gateway Is Available For Use.
     *
     * @return bool
     */
    public function is_available() {

        if( $this->lh_get_option( 'gateway_enabled' ) == 'no' ) {
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

        $current_user = wp_get_current_user();

        // Tarkista käyttäjän laskutusasiakas-tieto
        $can_use_billing = get_the_author_meta( "laskuhari_laskutusasiakas", $current_user->ID ) !== "yes";
        $can_use_billing = apply_filters( "laskuhari_customer_can_use_billing", $can_use_billing, $current_user->ID );

        if( $this->salli_laskutus_erikseen && $can_use_billing ) {
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

        return parent::is_available();
    }


    /**
     * Process the payment and return the result.
     *
     * @param int $order_id
     * @return array
     */
    public function process_payment( $order_id ) {

        if( $this->auto_gateway_create_enabled ) {
            $lh = laskuhari_process_action( $order_id, $this->auto_gateway_enabled );
            $order      = $lh['order'];
            $notice     = $lh['notice'];
        }

        $order = wc_get_order( $order_id );

        do_action( "laskuhari_action_after_payment_completed_before_update_status" );

        $status_after_payment = apply_filters( "laskuhari_status_after_payment", "processing", $order_id );

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

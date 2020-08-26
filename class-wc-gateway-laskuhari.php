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
 * @class 		WC_Gateway_Laskuhari
 * @extends		WC_Payment_Gateway
 */
class WC_Gateway_Laskuhari extends WC_Payment_Gateway {

    /**
     * Constructor for the gateway.
     */
	public function __construct( $notexts = false ) {
		$this->id                 		= 'laskuhari';
		$this->icon               		= apply_filters( 'woocommerce_laskuhari_icon', '' );
		$this->method_title       		= __( 'Laskuhari', 'woocommerce' );
		$this->method_description 		= __( 'Käytä Laskuhari-palvelua tilausten automaattiseen laskuttamiseen<img src="https://www.laskuhari.fi/lh-wc.png" alt="." />', 'woocommerce' );
		$this->has_fields         		= false;

		// Load the settings
		$this->init_form_fields();
		$this->init_settings();

		// Get settings
		$this->laskutuslisa        		= preg_replace(['/,/', '/[^0-9\.,]+/'], ['.', ''], $this->get_option( 'laskutuslisa' ));
		$this->laskutuslisa_alv    		= preg_replace(['/,/', '/[^0-9\.,]+/'], ['.', ''], $this->get_option( 'laskutuslisa_alv' ));
		$this->title              		= $this->get_option( 'title' );
		$this->lahetystapa_manuaalinen  = $this->get_option( 'lahetystapa_manuaalinen' );
		$this->demotila                 = $this->get_option( 'demotila', 'yes' ) === 'yes' ? true : false;
		if( $this->demotila == "yes" ) {
			$this->uid                		= "3175";
			$this->apikey             		= "31d5348328d0044b303cc5d480e6050a35000b038fb55797edfcf426f1a62c2e9e2383a351f161cb";
		} else {
			$this->uid                		= $this->get_option( 'uid' );
			$this->apikey             		= $this->get_option( 'apikey' );
		}
		$this->email_lasku_kaytossa     = $this->get_option( 'email_lasku_kaytossa', 'yes' ) === 'yes' ? true : false;
		$this->verkkolasku_kaytossa     = $this->get_option( 'verkkolasku_kaytossa', 'yes' ) === 'yes' ? true : false;
		$this->kirjelasku_kaytossa      = $this->get_option( 'kirjelasku_kaytossa', 'yes' ) === 'yes' ? true : false;
		$this->auto_gateway_enabled     = $this->get_option( 'auto_gateway_enabled', 'yes' ) === 'yes' ? true : false;
		$this->auto_gateway_create_enabled = $this->get_option( 'auto_gateway_create_enabled', 'yes' ) === 'yes' ? true : false;
		$this->salli_laskutus_erikseen  = $this->get_option( 'salli_laskutus_erikseen', 'no' ) === 'yes' ? true : false;
		$this->laskuviesti        		= $this->get_option( 'laskuviesti' );
		$this->laskuttaja         		= $this->get_option( 'laskuttaja' );
		$this->description        		= $this->get_option( 'description' );
		$this->instructions       		= $this->get_option( 'instructions', $this->description );
		$this->enable_for_methods 		= $this->get_option( 'enable_for_methods', array() );
		$this->enable_for_customers     = $this->get_option( 'enable_for_customers', array() );
		$this->enable_for_virtual 		= $this->get_option( 'enable_for_virtual', 'yes' ) === 'yes' ? true : false;

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );

		if( $notexts == false ) {
			add_action( 'woocommerce_thankyou_laskuhari', array( $this, 'thankyou_page' ) );
	    	add_action( 'woocommerce_email_before_order_table', array( $this, 'email_instructions' ), 10, 3 );
		}
	}

	public function veroton_laskutuslisa( $sis_alv ) {
		if( $sis_alv ) {
			return $this->laskutuslisa / (1+$this->laskutuslisa_alv/100);
		}
		return $this->laskutuslisa;
	}

	public function verollinen_laskutuslisa( $sis_alv ) {
		if( $sis_alv ) {
			return $this->laskutuslisa;
		}
		return $this->laskutuslisa * (1+$this->laskutuslisa_alv/100);
	}

	public function lahetystapa_lomake( $order_id = false ) {
		$laskutustapa = get_post_meta($order_id, '_laskuhari_laskutustapa', true);
		$valittaja = get_post_meta($order_id, '_laskuhari_valittaja', true);
		?>
			<select onchange="tarkista_verkkolaskuosoite(jQuery);" id="laskuhari-laskutustapa" class="laskuhari-pakollinen" name="laskuhari-laskutustapa">
				<option value="">-- <?php echo __('Valitse laskutustapa', 'laskuhari'); ?> --</option>
				<?php if( $this->email_lasku_kaytossa ): ?><option value="email"<?php echo ($laskutustapa == "email" ? ' selected' : ''); ?>><?php echo __('Sähköposti', 'laskuhari'); ?></option><?php endif; ?>
				<?php if( $this->verkkolasku_kaytossa ): ?><option value="verkkolasku"<?php echo ($laskutustapa == "verkkolasku" ? ' selected' : ''); ?>><?php echo __('Verkkolasku', 'laskuhari'); ?></option><?php endif; ?>
				<?php if( $this->kirjelasku_kaytossa ): ?><option value="kirje"<?php echo ($laskutustapa == "kirje" ? ' selected' : ''); ?>><?php echo __('Kirje', 'laskuhari'); ?></option><?php endif; ?>
			</select>
			<div id="laskuhari-verkkolasku-tiedot" style="<?php echo ($laskutustapa == "verkkolasku" ? '' : 'display: none;'); ?>">
				<div class="laskuhari-caption"><?php echo __('Y-tunnus', 'laskuhari'); ?>:</div>
				<input type="text" class="verkkolasku-pakollinen" value="<?php echo esc_attr(get_post_meta($order_id, '_laskuhari_ytunnus', true)); ?>" id="laskuhari-ytunnus" name="laskuhari-ytunnus" /><br />
				<div class="laskuhari-caption"><?php echo __('Verkkolaskuosoite / OVT', 'laskuhari'); ?>:</div>
				<input type="text" id="laskuhari-verkkolaskuosoite" value="<?php echo esc_attr(get_post_meta($order_id, '_laskuhari_verkkolaskuosoite', true)); ?>" name="laskuhari-verkkolaskuosoite" /><br />
				<div class="laskuhari-caption"><?php echo __('Verkkolaskuoperaattori', 'laskuhari'); ?>:</div>
				<select id="laskuhari-valittaja" name="laskuhari-valittaja">
					<option value="">-- <?php echo __('Valitse verkkolaskuoperaattori', 'laskuhari'); ?> ---</option>
					<optgroup label="<?php echo __('Operaattorit', 'laskuhari'); ?>">
						<option value="003723327487"<?php echo ($valittaja == "003723327487" ? ' selected' : ''); ?>>Apix Messaging Oy (003723327487)</option>
						<option value="BAWCFI22"<?php echo ($valittaja == "BAWCFI22" ? ' selected' : ''); ?>>Basware Oyj (BAWCFI22)</option>
						<option value="003703575029"<?php echo ($valittaja == "003703575029" ? ' selected' : ''); ?>>CGI (003703575029)</option>
						<option value="885790000000418"<?php echo ($valittaja == "885790000000418" ? ' selected' : ''); ?>>HighJump AS (885790000000418)</option>
						<option value="INEXCHANGE"<?php echo ($valittaja == "INEXCHANGE" ? ' selected' : ''); ?>>InExchange Factorum AB (INEXCHANGE)</option>
						<option value="EXPSYS"<?php echo ($valittaja == "EXPSYS" ? ' selected' : ''); ?>>Lexmark Expert Systems AB (EXPSYS)</option>
						<option value="003708599126"<?php echo ($valittaja == "003708599126" ? ' selected' : ''); ?>>Liaison Technologies Oy (003708599126)</option>
						<option value="003721291126"<?php echo ($valittaja == "003721291126" ? ' selected' : ''); ?>>Maventa (003721291126)</option>
						<option value="003726044706"<?php echo ($valittaja == "003726044706" ? ' selected' : ''); ?>>Netbox Finland Oy (003726044706)</option>
						<option value="E204503"<?php echo ($valittaja == "E204503" ? ' selected' : ''); ?>>OpusCapita Solutions Oy (E204503)</option>
						<option value="003723609900"<?php echo ($valittaja == "003723609900" ? ' selected' : ''); ?>>Pagero (003723609900)</option>
						<option value="PALETTE"<?php echo ($valittaja == "PALETTE" ? ' selected' : ''); ?>>Palette Software (PALETTE)</option>
						<option value="003710948874"<?php echo ($valittaja == "003710948874" ? ' selected' : ''); ?>>Posti Messaging Oy (003710948874)</option>
						<option value="003701150617"<?php echo ($valittaja == "003701150617" ? ' selected' : ''); ?>>PostNord Strålfors Oy (003701150617)</option>
						<option value="003714377140"<?php echo ($valittaja == "003714377140" ? ' selected' : ''); ?>>Ropo Capital Oy (003714377140)</option>
						<option value="003703575029"<?php echo ($valittaja == "003703575029" ? ' selected' : ''); ?>>Telia (003703575029)</option>
						<option value="003701011385"<?php echo ($valittaja == "003701011385" ? ' selected' : ''); ?>>Tieto Oyj (003701011385)</option>
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
		<?php
	}

	public function payment_fields() {
		$description = $this->get_description();
		if ( $description ) {
			echo wpautop( wptexturize( $description ) );
		}

		$this->lahetystapa_lomake();
		?>
		<div class="laskuhari-caption"><?php echo __('Viitteenne', 'laskuhari'); ?> (<?php echo __('valinnainen', 'laskuhari'); ?>):</div>
		<input type="text" id="laskuhari-viitteenne" name="laskuhari-viitteenne" />
		<script type="text/javascript">
			function tarkista_laskutustapa($){
				if( $("#payment_method_laskuhari").is(":checked") ) {
					if( $("#laskuhari-laskutustapa").val() == "" || ($("#laskuhari-laskutustapa").val() == "verkkolasku" && $("#laskuhari-ytunnus").val() == "")) {
						$("#place_order").prop("disabled", true);
					} else {
						$("#place_order").prop("disabled", false);
					}
				}
			}
			(function($) {
				$(".verkkolasku-pakollinen").bind("keyup change", function(){
					tarkista_laskutustapa($);
				});
				$("#payment_method_laskuhari, #payment").on("change click", function() {
					if( $("#payment_method_laskuhari").prop("checked") != viime_maksutapa ) {
						$('body').trigger('update_checkout');
						viime_maksutapa = $("#payment_method_laskuhari").prop("checked");
					}
				});
			})(jQuery);
		</script>
		<?php
	}

    /**
     * Initialise Gateway Settings Form Fields.
     */
    public function init_form_fields() {
    	$shipping_methods = array();
    	$asiakkaat = array();

    	if ( is_admin() ) {
	    	foreach ( WC()->shipping()->load_shipping_methods() as $method ) {
		    	$shipping_methods[ $method->id ] = $method->get_title();
			}
			/*$customers = get_users();
	    	foreach ( $customers as $customer ) {
				$user = get_userdata( $customer->ID );
		    	$asiakkaat[ $customer->ID ] = sanitize_text_field($user->user_login." / ".$user->user_email." (".$customer->first_name." ".$customer->last_name).")";
	    	}*/
		}

    	$this->form_fields = array(
			'enabled' => array(
				'title'       => __( 'Käytössä', 'woocommerce' ),
				'label'       => __( 'Ota käyttöön Laskuhari-lisäosa', 'woocommerce' ),
				'type'        => 'checkbox',
				'description' => '',
				'default'     => 'yes'
			),
			'gateway_enabled' => array(
				'title'       => __( 'Maksutapa', 'woocommerce' ),
				'label'       => __( 'Ota käyttöön Laskutus-maksutapa', 'woocommerce' ),
				'type'        => 'checkbox',
				'description' => 'Lisää verkkokauppaan Laskutus-maksutavan, joka lähettää asiakkaalle tilauksesta laskun.',
				'default'     => 'yes'
			),
			'auto_gateway_create_enabled' => array(
				'title'       => __( 'Automaattinen luonti', 'woocommerce' ),
				'label'       => __( 'Luo laskut automaattisesti', 'woocommerce' ),
				'type'        => 'checkbox',
				'description' => 'Luodaanko laskut automaattisesti Laskuhariin, kun asiakas tekee tilauksen?',
				'default'     => 'yes'
			),
			'auto_gateway_enabled' => array(
				'title'       => __( 'Automaattinen lähetys', 'woocommerce' ),
				'label'       => __( 'Lähetä laskut automaattisesti', 'woocommerce' ),
				'type'        => 'checkbox',
				'description' => 'Lähetetäänkö laskut automaattisesti, kun asiakas tekee tilauksen?',
				'default'     => 'yes'
			),
			'email_lasku_kaytossa' => array(
				'title'       => __( 'Sähköpostilaskut käytössä', 'woocommerce' ),
				'label'       => __( 'Ota käyttöön sähköpostilaskut', 'woocommerce' ),
				'type'        => 'checkbox',
				'description' => '',
				'default'     => 'yes'
			),
			'verkkolasku_kaytossa' => array(
				'title'       => __( 'Verkkolaskut käytössä', 'woocommerce' ),
				'label'       => __( 'Ota käyttöön verkkolaskut', 'woocommerce' ),
				'type'        => 'checkbox',
				'description' => '',
				'default'     => 'yes'
			),
			'kirjelasku_kaytossa' => array(
				'title'       => __( 'Kirjelaskut käytössä', 'woocommerce' ),
				'label'       => __( 'Ota käyttöön kirjelaskut', 'woocommerce' ),
				'type'        => 'checkbox',
				'description' => '',
				'default'     => 'yes'
			),
			'uid' => array(
				'title'       => __( 'UID', 'woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Laskuhari-tunnuksesi UID (kysy asiakaspalvelusta)', 'woocommerce' ),
				'default'     => __( '', 'woocommerce' ),
			),
			'apikey' => array(
				'title'       => __( 'API-koodi', 'woocommerce' ),
				'type'        => 'text',
				'description' => 'Laskuhari-tunnuksesi API-koodi (kysy asiakaspalvelusta)',
				'default'     => __( '', 'woocommerce' ),
			),
			'demotila' => array(
				'title'       => __( 'Demotila', 'woocommerce' ),
				'label'       => __( 'Ota käyttöön demotila', 'woocommerce' ),
				'type'        => 'checkbox',
				'description' => 'Demotilassa et tarvitse UID- tai API-koodia. Voit lähettää vain sähköpostilaskuja, ja ne lähetetään testiyrityksen tiedoilla. Jos haluat oman yrityksesi tiedot laskulle, luo tunnukset <a href="https://www.laskuhari.fi" target="_blank">Laskuhari.fi</a>-palveluun ja liitä UID ja API-koodi lisäosan asetuksiin sekä poista demotila käytöstä.',
				'default'     => 'yes'
			),
			'lahetystapa_manuaalinen' => array(
				'title'       => __( 'Lähetystapa (massalaskutus)', 'woocommerce' ),
				'label'       => __( 'Valitse laskujen lähetystapa', 'woocommerce' ),
				'type'        => 'select',
				'description' => __( 'Valitse tapa, jolla haluat lähettää massatoiminnolla lähetettävät laskut. Koskee myös "Luo ja lähetä" -toimintoa', 'woocommerce' ),
				'default'     => 'ei',
				'options'     => array(
					'email' => __( 'Sähköpostilasku', 'woocommerce' ),
					'kirje' => __( 'Kirjelasku', 'woocommerce' ),
					'ei'    => __( 'Tallenna Laskuhariin, älä lähetä', 'woocommerce' )
				)
			),
			'laskuviesti' => array(
				'title'       => __( 'Laskuviesti', 'woocommerce' ),
				'type'        => 'textarea',
				'description' => __( 'Viesti, joka lähetetään saatetekstinä sähköpostilaskun ohessa', 'woocommerce' ),
				'default'     => __( 'Kiitos tilauksestasi. Liitteenä lasku tilaamistasi tuotteista.', 'woocommerce' ),
				'desc_tip'    => true,
			),
			'laskuttaja' => array(
				'title'       => __( 'Laskuttaja', 'woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Laskuttajan nimi, joka näkyy sähköpostilaskun lähettäjänä', 'woocommerce' ),
				'default'     => __( '', 'woocommerce' ),
				'desc_tip'    => true,
			),
			'title' => array(
				'title'       => __( 'Maksutavan nimi', 'woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Tämä näkyy maksutavan nimenä asiakkaalle', 'woocommerce' ),
				'default'     => __( 'Laskutus', 'woocommerce' ),
				'desc_tip'    => true,
			),
			'description' => array(
				'title'       => __( 'Kuvaus', 'woocommerce' ),
				'type'        => 'textarea',
				'description' => __( 'Kuvaus, joka näytetään maksutavan yhteydessä', 'woocommerce' ),
				'default'     => __( 'Maksa tilauksesi kätevästi laskulla', 'woocommerce' ),
				'desc_tip'    => true,
			),
			'instructions' => array(
				'title'       => __( 'Ohjeet', 'woocommerce' ),
				'type'        => 'textarea',
				'description' => __( 'Ohjeet, jotka näkyvät tilausvahvistussivulla', 'woocommerce' ),
				'default'     => __( 'Lähetämme sinulle laskun tilauksestasi.', 'woocommerce' ),
				'desc_tip'    => true,
			),
			'laskutuslisa' => array(
				'title'       => __( 'Laskutuslisä', 'woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Laskutuslisä, joka lisätään jokaiselle laskulle (EUR, 0 = ei laskutuslisää)', 'woocommerce' ),
				'default'     => __( '0', 'woocommerce' ),
				'desc_tip'    => true,
			),
			'laskutuslisa_alv' => array(
				'title'       => __( 'Laskutuslisän ALV-%', 'woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Laskutuslisän arvonlisäveroprosentti', 'woocommerce' ),
				'default'     => __( '24', 'woocommerce' ),
				'desc_tip'    => true,
			),
			'enable_for_methods' => array(
				'title'             => __( 'Käytössä näille toimitustavoille', 'woocommerce' ),
				'type'              => 'multiselect',
				'class'             => 'wc-enhanced-select',
				'css'               => 'width: 450px;',
				'default'           => '',
				'description'       => __( 'Jätä tyhjäksi, jos haluat laskutuksen käyttöön kaikille toimitustavoille', 'woocommerce' ),
				'options'           => $shipping_methods,
				'desc_tip'          => true,
				'custom_attributes' => array(
					'data-placeholder' => __( 'Valitse toimitustavat', 'woocommerce' )
				)
			),
			/*'enable_for_customers' => array(
				'title'             => __( 'Käytössä näille asiakkaille', 'woocommerce' ),
				'type'              => 'multiselect',
				'class'             => 'wc-enhanced-select',
				'css'               => 'width: 450px;',
				'default'           => '',
				'description'       => __( 'Jätä tyhjäksi, jos haluat laskutuksen käyttöön kaikille asiakkaille', 'woocommerce' ),
				'options'           => $asiakkaat,
				'desc_tip'          => true,
				'custom_attributes' => array(
					'data-placeholder' => __( 'Valitse asiakkaat', 'woocommerce' )
				)
			),*/
			'salli_laskutus_erikseen' => array(
				'title'       => __( 'Salli vain laskutusasiakkaille', 'woocommerce' ),
				'label'       => __( 'Salli laskutus-maksutavan valinta vain tietyille asiakkaille', 'woocommerce' ),
				'type'        => 'checkbox',
				'description' => 'Salli vain niiden asiakkaiden valita laskutus-maksutapa, joilla on käyttäjätiedoissa laskutusasiakas-rasti',
				'default'     => 'no'
			),
			'enable_for_virtual' => array(
				'title'             => __( 'Virtuaalituotteet', 'woocommerce' ),
				'label'             => __( 'Hyväksy laskutus-maksutapa, jos tuote on virtuaalinen', 'woocommerce' ),
				'type'              => 'checkbox',
				'default'           => 'yes'
			),
			'enforce_ssl' => array(
				'title'       => __( 'Vahvista SSL', 'woocommerce' ),
				'label'       => __( 'Vahvista SSL-yhteys Laskuharin rajapintaan (suositellaan)', 'woocommerce' ),
				'type'        => 'checkbox',
				'description' => 'Mikäli pois käytöstä SSL_VERIFYHOST = 0, SSL_VERIFYPEER = FALSE',
				'default'     => 'yes'
			)
 	   );
    }

	/**
	 * Check If The Gateway Is Available For Use.
	 *
	 * @return bool
	 */
	public function is_available() {

		if( $this->get_option('gateway_enabled') == 'no' ) {
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
		if( $this->salli_laskutus_erikseen && get_the_author_meta("laskuhari_laskutusasiakas", $current_user->ID ) != "yes" ) {
			return false;
		}
		
		// Check allowed users
		/*if ( ! empty( $this->enable_for_customers ) ) {

			$found = false;

			foreach ( $this->enable_for_customers as $user_id ) {
				if ( $current_user->ID == $user_id ) {
					$found = true;
					break;
				}
			}

			if ( ! $found ) {
				return false;
			}	
		}*/
		
		// Check methods
		if ( ! empty( $this->enable_for_methods ) && $needs_shipping ) {

			// Only apply if all packages are being shipped via chosen methods, or order is virtual
			$chosen_shipping_methods_session = WC()->session->get( 'chosen_shipping_methods' );

			if ( isset( $chosen_shipping_methods_session ) ) {
				$chosen_shipping_methods = array_unique( $chosen_shipping_methods_session );
			} else {
				$chosen_shipping_methods = array();
			}

			$check_method = false;

			if ( is_object( $order ) ) {
				if ( $order->shipping_method ) {
					$check_method = $order->shipping_method;
				}

			} elseif ( empty( $chosen_shipping_methods ) || sizeof( $chosen_shipping_methods ) > 1 ) {
				$check_method = false;
			} elseif ( sizeof( $chosen_shipping_methods ) == 1 ) {
				$check_method = $chosen_shipping_methods[0];
			}

			if ( ! $check_method ) {
				return false;
			}

			$found = false;

			foreach ( $this->enable_for_methods as $method_id ) {
				if ( strpos( $check_method, $method_id ) === 0 ) {
					$found = true;
					break;
				}
			}

			if ( ! $found ) {
				return false;
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
			$lh = laskuhari_process_action($order_id, $this->auto_gateway_enabled);
			$order      = $lh['order'];
			$notice     = $lh['notice'];
		}

		$order = wc_get_order($order_id);
		$order->update_status("processing");

		// Reduce stock levels
		wc_reduce_stock_levels( $order_id );

		// Remove cart
		WC()->cart->empty_cart();

		// Return thankyou redirect
		return array(
			'result' 	=> 'success',
			'redirect'	=> $this->get_return_url( $order )
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

<?php

namespace Laskuhari;

if( ! defined( 'ABSPATH' ) ) exit;

/**
 * The page under "My Account" where the customer
 * can modify their invoicing details
 */
class Laskuhari_Invoicing_Details_Endpoint {
    const ENDPOINT = 'invoicing-details';

    protected WC_Gateway_Laskuhari $gw;

    public function __construct( WC_Gateway_Laskuhari $gw ) {
        $this->gw = $gw;

        // Allow third parties to disable endpoint via filter
        $endpoint_enabled = apply_filters( "laskuhari_allow_invoicing_details_editing", true );

        if( ! $endpoint_enabled || ! $this->gw->can_use_billing() ) {
            $this->set_endpoint_registered( false );
            return;
        }

        // Register endpoint
        add_action( 'init', [$this, 'add_endpoint'] );
        add_filter( 'query_vars', [$this, 'add_query_var'] );

        // Add menu item
        add_filter( 'woocommerce_account_menu_items', [$this, 'add_menu_item'] );
        add_filter( 'the_title', [$this, 'endpoint_title'], 10, 2 );

        // Register endpoint content
        add_action( 'woocommerce_account_' . self::ENDPOINT . '_endpoint', [$this, 'endpoint_content'] );

        // Handle form submit
        add_action( 'template_redirect', [$this, 'handle_form_submit'] );
    }

    /**
     * Checks if the endpoint has already been registered
     * so that we know to flush the rewrite rules
     *
     * @return bool
     */
    protected function endpoint_is_registered() {
        return get_option( 'laskuhari_endpoint_' . self::ENDPOINT ) === "registered";
    }

    /**
     * Set an option whether endpoint is registered or not
     * so that we can flush the rewrite rules if needed
     *
     * @param bool $registered
     * @return void
     */
    protected function set_endpoint_registered( $registered ) {
        if( $registered ) {
            update_option( 'laskuhari_endpoint_' . self::ENDPOINT, 'registered', false );
        } else {
            delete_option( 'laskuhari_endpoint_' . self::ENDPOINT );
        }
    }

    /**
     * Add the rewrite endpoint of the page
     *
     * @return void
     */
    public function add_endpoint() {
        add_rewrite_endpoint( self::ENDPOINT, EP_ROOT | EP_PAGES );

        // Flush rewrite rules if needed
        if( ! $this->endpoint_is_registered() ) {
            flush_rewrite_rules();
            $this->set_endpoint_registered( true );
        }
    }

    /**
     * Register query var
     *
     * @param array<string> $vars
     * @return array<string>
     */
    public function add_query_var( $vars ) {
        $vars[] = self::ENDPOINT;
        return $vars;
    }

    /**
     * Add the menu item for the page in My Account
     *
     * @param array<string, string> $items
     * @return array<string, string>
     */
    public function add_menu_item( $items ) {
        $new = [];
        foreach( $items as $key => $label ) {
            $new[$key] = $label;
            if( 'edit-account' === $key ) {
                $new[self::ENDPOINT] = __( 'Laskutustiedot', 'laskuhari' );
            }
        }
        if( ! isset( $new[self::ENDPOINT] ) ) {
            $new[self::ENDPOINT] = __( 'Laskutustiedot', 'laskuhari' );
        }
        return $new;
    }

    /**
     * Set the endpoint title
     *
     * @param string $title
     * @param int $_post_id
     * @return string
     */
    public function endpoint_title( $title, $_post_id ) {
        global $wp_query;
        if( isset( $wp_query->query_vars[self::ENDPOINT] ) && is_account_page() && in_the_loop() ) {
            $title = __( 'Laskutustiedot', 'laskuhari' );
            remove_filter( 'the_title', [$this, 'endpoint_title'], 10 );
        }
        return $title;
    }

    /**
     * Get the fields available for editing and their meta keys
     *
     * @return array<string, array<string, string>>
     */
    protected function get_fields() {
        return [
            'laskutustapa' => [
                'label' => __( 'Laskutustapa', 'laskuhari' ),
                'meta'  => '_laskuhari_laskutustapa',
        	],
            'verkkolaskuosoite' => [
                'label' => __( 'Verkkolaskuosoite', 'laskuhari' ),
                'meta'  => '_laskuhari_verkkolaskuosoite',
        	],
            'valittaja' => [
                'label' => __( 'Välittäjätunnus', 'laskuhari' ),
                'meta'  => '_laskuhari_valittaja',
        	],
            'email' => [
                'label' => __( 'Laskutussähköposti', 'laskuhari' ),
                'meta'  => '_laskuhari_billing_email',
                'placeholder' => (string) get_user_meta( get_current_user_id(), "billing_email", true ), // @phpstan-ignore-line
        	],
            'ytunnus' => [
                'label' => __( 'Y-tunnus', 'laskuhari' ),
                'meta'  => '_laskuhari_ytunnus',
        	],
            'viitteenne' => [
                'label' => __( 'Viitteenne', 'laskuhari' ),
                'meta'  => '_laskuhari_viitteenne',
        	],
    	];
    }

    /**
     * Echo out the content of the page
     *
     * @return void
     */
    public function endpoint_content() {
        if( ! is_user_logged_in() ) {
            echo '<p>' . esc_html__( 'Sinun täytyy olla kirjautunut muokataksesi näitä tietoja.', 'laskuhari' ) . '</p>';
            return;
        }

        if( ! $this->gw->can_use_billing() ) {
            echo '<p>' . esc_html__( 'Sinulla ei ole oikeutta tähän sivuun.', 'laskuhari' ) . '</p>';
            return;
        }

        $fields = $this->get_fields();
        $values = [];
        foreach( $fields as $name => $cfg ) {
            $values[$name] = get_laskuhari_meta( null, $cfg['meta'] ) ?? "";
        }

        /** @var string $email_method_text */
        $email_method_text = apply_filters( "laskuhari_email_method_text", __( "Sähköposti", "laskuhari" ) );

        /** @var string $einvoice_method_text */
        $einvoice_method_text = apply_filters( "laskuhari_einvoice_method_text", __( "Verkkolasku", "laskuhari" ) );

        /** @var string $letter_method_text */
        $letter_method_text = apply_filters( "laskuhari_letter_method_text", __( "Kirje", "laskuhari" ) );

        ?>
        <form method="post" class="woocommerce-Form woocommerce-form" id="laskuhari-laskutustiedot-form">
            <p><?php esc_html_e( 'Käytämme näitä laskutustietoja, kun valitset maksutavaksi laskun.', 'laskuhari' ); ?></p>

            <p class="form-row form-row-wide">
                <label for="laskuhari-laskutustapa"><?php echo esc_html( $fields['laskutustapa']['label'] ); ?></label>
				<select name="laskutustapa" id="laskuhari-laskutustapa">
                    <option value="">-- <?php echo __( 'Valitse laskutustapa', 'laskuhari' ); ?> ---</option>

                    <?php if( $this->gw->email_lasku_kaytossa ): ?>
                        <option value="email"<?php echo ($values["laskutustapa"] == "email" ? ' selected' : ''); ?>><?php echo $email_method_text; ?></option>
                    <?php endif; ?>

                    <?php if( $this->gw->verkkolasku_kaytossa ): ?>
                        <option value="verkkolasku"<?php echo ($values["laskutustapa"] == "verkkolasku" ? ' selected' : ''); ?>><?php echo $einvoice_method_text; ?></option>
                    <?php endif; ?>

                    <?php if( $this->gw->kirjelasku_kaytossa ): ?>
                        <option value="kirje"<?php echo ($values["laskutustapa"] == "kirje" ? ' selected' : ''); ?>><?php echo $letter_method_text; ?></option>
                    <?php endif; ?>

                </select>
                <small id="laskuhari-kirje-tiedot">
                    <?php esc_html_e( 'Kirjelasku lähetetään tilauslomakkeella syötettyyn laskutusosoitteeseen', 'laskuhari' ); ?>
                </small>
            </p>

            <p class="form-row form-row-wide" id="laskuhari-sahkoposti-tiedot">
                <label for="email"><?php echo esc_html( $fields['email']['label'] ); ?></label>
                <input type="text" class="input-text" name="email" id="email" value="<?php echo esc_attr( $values['email'] ); ?>" placeholder="<?php echo esc_attr( $fields['email']['placeholder'] ); ?>" />
            </p>

            <div id="laskuhari-verkkolasku-tiedot">
                <p class="form-row form-row-wide">
                    <label for="verkkolaskuosoite"><?php echo esc_html( $fields['verkkolaskuosoite']['label'] ); ?></label>
                    <input type="text" class="input-text" name="verkkolaskuosoite" id="verkkolaskuosoite" value="<?php echo esc_attr( $values['verkkolaskuosoite'] ); ?>" />
                </p>

                <p class="form-row form-row-wide">
                    <label for="laskuhari-valittajatunnus"><?php echo esc_html( $fields['valittaja']['label'] ); ?></label>
                    <select name="valittaja" id="laskuhari-valittajatunnus" class="lh-select2" style="width: 100%">
                        <option value="">-- <?php echo __( 'Valitse verkkolaskuoperaattori', 'laskuhari' ); ?> ---</option>
                        <?php echo $this->gw->operators_select_options_html( $values['valittaja'] ); ?>
                    </select>
                </p>
            </div>

            <p class="form-row form-row-wide">
                <label for="ytunnus"><?php echo esc_html( $fields['ytunnus']['label'] ); ?></label>
                <input type="text" class="input-text" name="ytunnus" id="ytunnus" value="<?php echo esc_attr( $values['ytunnus'] ); ?>" />
            </p>

            <p class="form-row form-row-wide">
                <label for="viitteenne"><?php echo esc_html( $fields['viitteenne']['label'] ); ?></label>
                <input type="text" class="input-text" name="viitteenne" id="viitteenne" value="<?php echo esc_attr( $values['viitteenne'] ); ?>" />
                <small><?php esc_html_e( 'Vapaa viite, joka näkyy laskulla (esim. tilausviite tai tilaajan nimi).', 'laskuhari' ); ?></small>
            </p>

            <?php wp_nonce_field( 'save_invoicing_details', 'save_invoicing_details_nonce' ); ?>
            <button type="submit" name="save_invoicing_details_submit" class="woocommerce-Button button">
                <?php esc_html_e( 'Tallenna laskutustiedot', 'laskuhari' ); ?>
            </button>
        </form>
        <?php
    }

    /**
     * Handle the submission of the form
     *
     * @return void
     */
    public function handle_form_submit() {
        if( ! is_user_logged_in() ) return;
        if( ! isset( $_POST['save_invoicing_details_submit'] ) ) return;

        // @phpstan-ignore-next-line
        if( ! isset( $_POST['save_invoicing_details_nonce'] ) || ! wp_verify_nonce( $_POST['save_invoicing_details_nonce'], 'save_invoicing_details' ) ) {
            wc_add_notice( __( 'Turvatarkistus epäonnistui. Yritä uudelleen.', 'laskuhari' ), 'error' );
            wp_safe_redirect( wc_get_account_endpoint_url( self::ENDPOINT ) );
            exit;
        }

        $user_id = get_current_user_id();
        $fields  = $this->get_fields();

        foreach( $fields as $name => $cfg ) {
            // @phpstan-ignore-next-line
            $val = isset( $_POST[$name] ) ? sanitize_text_field( wp_unslash( $_POST[$name] ) ) : '';
            laskuhari_set_user_meta( $user_id, $cfg['meta'], $val );
        }

        wc_add_notice( __( 'Laskutustiedot tallennettu.', 'laskuhari' ) );
        wp_safe_redirect( wc_get_account_endpoint_url( self::ENDPOINT ) );
        exit;
    }
}

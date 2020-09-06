<?php
/*
Plugin Name: Laskuhari for WooCommerce
Plugin URI: https://www.laskuhari.fi/woocommerce-laskutus
Description: Lisää automaattilaskutuksen maksutavaksi WooCommerce-verkkokauppaan sekä mahdollistaa tilausten manuaalisen laskuttamisen
Version: 0.9.46
Author: Datahari Solutions
Author URI: https://www.datahari.fi
License: GPLv2
*/

/*
Based on WooCommerce Custom Payment Gateways
Author: Mehdi Akram
Author URI: http://shamokaldarpon.com/
*/

$laskuhari_plugin_version = "0.9.46";

require_once plugin_dir_path( __FILE__ ) . 'updater.php';

add_action( 'woocommerce_after_register_post_type', 'laskuhari_payment_gateway_load', 0 );

function laskuhari_payment_gateway_load() {
    global $laskuhari_gateway_object;

    if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
        add_action( 'admin_notices', 'laskuhari_fallback_notice' );
        return;
    }

    add_filter( 'plugin_action_links', 'laskuhari_plugin_action_links', 10, 2 );
    
    require_once plugin_dir_path( __FILE__ ) . 'class-wc-gateway-laskuhari.php';

    $laskuhari_gateway_object = new WC_Gateway_Laskuhari( true );

    if( $laskuhari_gateway_object->get_option( 'enabled' ) !== 'yes' ) {
        return;
    }

    if ( $laskuhari_gateway_object->demotila ) {
        add_action( 'admin_notices', 'laskuhari_demo_notice' );
    }

    add_filter( 'woocommerce_payment_gateways', 'laskuhari_add_gateway' );

    laskuhari_actions();

    if( $laskuhari_gateway_object->synkronoi_varastosaldot ) {
        add_action( 'woocommerce_product_set_stock', 'laskuhari_update_stock' ); 
        add_action( 'woocommerce_variation_set_stock', 'laskuhari_update_stock' ); 
    }

    add_action( 'wp_footer', 'laskuhari_add_public_scripts' );
    add_action( 'wp_footer', 'laskuhari_add_styles' );
    add_action( 'admin_print_scripts', 'laskuhari_add_public_scripts' );
    add_action( 'admin_print_scripts', 'laskuhari_add_admin_scripts' );
    add_action( 'admin_print_styles', 'laskuhari_add_styles' );
    add_action( 'woocommerce_cart_calculate_fees','laskuhari_add_invoice_surcharge', 10, 1 );
    add_action( 'show_user_profile', 'laskuhari_user_profile_additional_info' );
    add_action( 'edit_user_profile', 'laskuhari_user_profile_additional_info' );
    add_action( 'personal_options_update', 'laskuhari_update_user_meta' );
    add_action( 'edit_user_profile_update', 'laskuhari_update_user_meta' );
    add_action( 'manage_shop_order_posts_custom_column', 'laskuhari_add_invoice_status_to_custom_order_list_column' );
    add_action( 'woocommerce_checkout_process', 'laskuhari_einvoice_notices' );
    add_action( 'woocommerce_checkout_update_order_meta', 'laskuhari_checkout_update_order_meta' );
    add_action( 'add_meta_boxes', 'laskuhari_metabox' );
    add_action( 'woocommerce_update_product', 'laskuhari_sync_product_on_save', 10, 1 );
    add_action( 'woocommerce_update_product_variation', 'laskuhari_sync_product_on_save', 10, 1 );

    add_filter( 'bulk_actions-edit-shop_order', 'laskuhari_add_bulk_action_for_invoicing', 20, 1 );
    add_filter( 'handle_bulk_actions-edit-shop_order', 'laskuhari_handle_bulk_actions', 10, 3 );
    add_filter( 'plugin_row_meta', 'laskuhari_register_plugin_links', 10, 2 );
    add_filter( 'manage_edit-shop_order_columns', 'laskuhari_add_column_to_order_list' );

    if( isset( $_GET['laskuhari_luotu'] ) || isset( $_GET['laskuhari_lahetetty'] ) || isset( $_GET['laskuhari_notice'] ) || isset( $_GET['laskuhari_success'] ) ) {
        add_action( 'admin_notices', 'laskuhari_notices' );
    }
    
}

function laskuhari_domain() {
    return "oma.laskuhari.fi";
}

function laskuhari_user_meta() {
    $custom_meta_fields = array();
    $custom_meta_fields['laskuhari_laskutusasiakas'] = 'Laskutusasiakas';

    return $custom_meta_fields;
}

function laskuhari_user_profile_additional_info( $user ) {
    echo '<h3>Laskuhari</h3>'.
         '<table class="form-table">';

    $meta_number = 0;
    $custom_meta_fields = laskuhari_user_meta();

    foreach ( $custom_meta_fields as $meta_field_name => $meta_disp_name ) {
        $meta_number++;

        if ( get_the_author_meta( $meta_field_name, $user->ID ) == "yes" ) {
            $author_meta_checked = "checked";
        } else {
            $author_meta_checked = "";
        }

        echo '
        <tr>
            <th>' . $meta_disp_name . '</th>
            <td>
                <input type="checkbox" name="' . $meta_field_name . '" 
                       id="' . $meta_field_name . '" 
                       value="yes" ' . $author_meta_checked . ' /> 
                <label for="' . $meta_field_name . '">Kyllä</label><br />
                <span class="description"></span>
            </td>
        </tr>';
    }

    echo '</table>';
}

function laskuhari_update_user_meta( $user_id ) {

    if( ! current_user_can( 'edit_user', $user_id ) ) {
        return false;
    }

    $meta_number = 0;
    $custom_meta_fields = laskuhari_user_meta();

    foreach ( $custom_meta_fields as $meta_field_name => $meta_disp_name ) {
        $meta_number++;
        update_usermeta( $user_id, $meta_field_name, $_POST[$meta_field_name] );
    }
}

function laskuhari_get_vat_rate( $product = null ) {
    $taxes = null;

    if( is_a( $product, 'WC_Product' ) ) {
        $taxes = WC_Tax::get_rates( $product->get_tax_class() );
    }

    if( null === $taxes || 0 === count( $taxes ) ) {
        $taxes = WC_Tax::get_rates();
    }

    $taxes = apply_filters( "laskuhari_taxes", $taxes, $product );

    if( null === $taxes || 0 === count( $taxes ) ) {
        return 0;
    }

    foreach( $taxes as $rate ) {
        $vat_rate = $rate['rate'];
        if( false !== stripos( $rate['label'], "arvonlis" ) || false !== stripos( $rate['label'], "alv" ) ) {
            break;
        }
    }

    $common_vat_rates = [24, 14, 10];
    $common_vat_rates = apply_filters( "laskuhari_common_vat_rates", $common_vat_rates, $product );

    foreach( $taxes as $rate ) {
        if( in_array( $rate['rate'], $common_vat_rates ) ) {
            $vat_rate = $rate['rate'];
            break;
        }
    }

    $vat_rate = apply_filters( "laskuhari_get_vat_rate", $vat_rate, $product );

    return $vat_rate;
}

function laskuhari_sync_product_on_save( $product_id ) {
    global $laskuhari_gateway_object;
    if( $laskuhari_gateway_object->synkronoi_varastosaldot ) {
        laskuhari_product_synced( $product_id, 'no' );
        laskuhari_create_product( $product_id, true );
    }
}

function laskuhari_create_product( $product, $update = false ) {
    if( ! is_a( $product, WC_Product::class ) ) {
        $product_id = intval( $product );
        $product    = wc_get_product( $product_id );
    }

    if( $product === null ) {
        error_log( "Laskuhari: Product ID '" . intval( $product_id ) . "' not found for product creation" );
        return false;
    }

    if( false === $update && laskuhari_product_synced( $product_id ) ) {
        return false;
    }

    if( $product->is_type( 'variation' ) ) {
        $product_id   = $product->get_parent_id();
        $variation_id = $product->get_id();
    } else {
        $product_id   = $product->get_id();
        $variation_id = 0;
    }
    
    $api_url = "https://" . laskuhari_domain() . "/rest-api/tuote/uusi";

    $api_url = apply_filters( "laskuhari_create_product_api_url", $api_url, $product );

    $prices_include_tax = get_option( 'woocommerce_prices_include_tax' ) == 'yes' ? true : false;

    $vat = laskuhari_get_vat_rate( $product );

    $vat_multiplier = (100 + $vat) / 100;

    if( $prices_include_tax ) {
        $price_with_tax    = $product->get_regular_price();
        $price_without_tax = $price_with_tax / $vat_multiplier;
    } else {
        $price_without_tax = $product->get_regular_price();
        $price_with_tax    = $price_without_tax * $vat_multiplier;
    }

    $payload = [
        "koodi" => $product->get_sku(),
        "nimike" => $product->get_name(),
        "ylatuote" => 0,
        "viivakoodi" => [
            "tyyppi" => "",
            "koodi" => ""
        ],
        "kommentti" => "",
        "ostohinta" => [
            "veroton" => 0,
            "alv" => $vat,
            "verollinen" => 0
        ],
        "myyntihinta" => [
            "veroton" => $price_without_tax,
            "alv" => $vat,
            "verollinen" => $price_with_tax
        ],
        "woocommerce" => [
            "wc_product_id" => $product_id,
            "wc_variation_id" => $variation_id,
            "prices_include_tax" => $prices_include_tax
        ],
        "varastosaldo" => $product->get_stock_quantity(),
        "varastoseuranta" => $product->get_manage_stock(),
        "halytysraja" => $product->get_low_stock_amount(),
        "varasto" => "",
        "hyllypaikka" => "",
        "maara" => 1,
        "yksikko" => "",
        "toistovali" => 0,
        "ennakko" => 0
    ];

    if( true === $update ) {
        $payload['korvaa_tuotteet'] = true;
    }

    $payload = apply_filters( "laskuhari_create_product_payload", $payload, $product );

    $payload = json_encode( $payload );

    $response = laskuhari_api_request( $payload, $api_url, "Product creation" );

    if( $response === false ) {
        return false;
    }

    laskuhari_product_synced( $product_id, "yes" );

    return true;
}

function laskuhari_product_synced( $product, $set = null ) {
    if( ! is_a( $product, WC_Product::class ) ) {
        $product_id = intval( $product );
        $product    = wc_get_product( $product_id );
    }

    if( $product === null ) {
        error_log( "Laskuhari: Product ID '" . intval( $product_id ) . "' not found for sync check" );
        return false;
    }

    if( $set !== null ) {
        update_post_meta( $product->get_id(), '_laskuhari_synced', $set );
        return $set;
    }

    return get_post_meta( $product->get_id(), '_laskuhari_synced', true ) === "yes";
}

function laskuhari_update_stock( $product ) {
    if( ! is_a( $product, WC_Product::class ) ) {
        $product_id = intval( $product );
        $product    = wc_get_product( $product_id );
    }

    if( $product === null ) {
        error_log( "Laskuhari: Product ID '" . intval( $product_id ) . "' not found for stock update" );
        return false;
    }

    if( $product->is_type( 'variation' ) ) {
        $product_id   = $product->get_parent_id();
        $variation_id = $product->get_id();
    } else {
        $product_id   = $product->get_id();
        $variation_id = 0;
    }

    $stock_quantity = $product->get_stock_quantity();

    $api_url = "https://" . laskuhari_domain() . "/rest-api/tuote/varastosaldo/";

    $api_url = apply_filters( "laskuhari_stock_update_api_url", $api_url, $product );

    $payload = [
        "woocommerce" => [
            "wc_product_id"  => $product_id,
            "wc_variation_id" => $variation_id
        ],
        "varastosaldo" => [
            "aseta" => $stock_quantity
        ]
    ];

    $payload = apply_filters( "laskuhari_stock_update_payload", $payload, $product );

    $payload = json_encode( $payload );

    $response = laskuhari_api_request( $payload, $api_url, "Stock update" );

    if( $response === false ) {
        return false;
    }

    if( ! isset( $response['varastosaldo'] ) || $response['varastosaldo'] != $stock_quantity ) {
        error_log( "Laskuhari: Stock update did not work for product ID " . $product_id );
        error_log( "Laskuhari: Payload was: " . print_r( $payload, true ) );
        return false;
    }

    return true;
}

// Lisää "Kirjaudu Laskuhariin" -linkki lisäosan tietoihin

function laskuhari_register_plugin_links( $links, $file ) {
    $base = plugin_basename( __FILE__ );
    
    if( $file == $base ) {
        $links[] = '<a href="https://' . laskuhari_domain() . '/" target="_blank">' . __( 'Kirjaudu Laskuhariin', 'laskuhari' ) . '</a>';
    }

    return $links;
}


// Lisää Laskuhari-sarake tilauslistaan

function laskuhari_add_column_to_order_list( $columns ) {
    $columns['laskuhari'] = 'Laskuhari';
    return $columns;
}

// Lisää Laskuhari-sarakkeeseen tilauksen laskutustila

function laskuhari_add_invoice_status_to_custom_order_list_column( $column ) {
    global $post;
    if( 'laskuhari' === $column ) {
        $data = laskuhari_invoice_status( $post->ID );
        if( $data['tila'] == "LASKUTETTU" ) {
            $status = "processing";
        } elseif( $data['tila'] == "LASKU LUOTU" ) {
            $status = "on-hold";
        } else {
            $status = "pending";
            $laskutustapa = get_post_meta( $post->ID, '_payment_method', true );
            if( $laskutustapa != "laskuhari" ) {
                echo '-';
                return;
            }
        }
        echo '<span class="order-status status-'.$status.'"><span>' . __( $data['tila'], 'laskuhari' ) . '</span></span>';
    }
}

function laskuhari_add_bulk_action_for_invoicing( $actions ) {
    $actions['laskuhari-batch-send'] = __( 'Laskuta valitut tilaukset (Laskuhari)', 'laskuhari' );
    return $actions;
}

function laskuhari_handle_bulk_actions( $redirect_to, $action, $order_ids ) {
    if ( $action !== 'laskuhari-batch-send' ) {
        return $redirect_to;
    }

    $data = array();

    foreach( $order_ids as $order_id ) {
        $order = wc_get_order( $order_id );

        if( ! $order ) {
            error_log( "Laskuhari: Could not find order ID " . intval( $order_id ) . " in bulk invoice action" );
            continue;
        }

        $order_status = $order->get_status();

        if( "processing" !== $order_status ) {  
            $data = array();
            $data["notice"][] = __( 'Tilausten tulee olla Käsittelyssä-tilassa, ennen kuin ne voidaan laskuttaa.', 'laskuhari' );
            return laskuhari_back_url( $data, $redirect_to );
        }
    }

    foreach( $_GET['post'] as $order_id ) {
        $lh     = laskuhari_process_action( $order_id, true, true );
        $data[] = $lh;
    }
    
    $back_url = laskuhari_back_url( $data, $redirect_to );

    $back_url = apply_filters( "laskuhari_return_url_after_bulk_action", $back_url, $order_ids );

    return $back_url;
}

// Anna ilmoitus puutteellisista verkkolaskutiedoista kassasivulla 

function laskuhari_einvoice_notices() {
    if( $_POST['payment_method'] != "laskuhari" ) {
        return false;
    }

    if ( ! $_POST['laskuhari-laskutustapa'] ) {
        wc_add_notice( __( 'Ole hyvä ja valitse laskutustapa' ) , 'error' );
    }

    if ( ! $_POST['laskuhari-ytunnus'] && $_POST['laskuhari-laskutustapa'] == "verkkolasku" ) {
        wc_add_notice( __( 'Ole hyvä ja syötä y-tunnus verkkolaskun lähetystä varten' ) , 'error' );
    }

    if ( ! $_POST['laskuhari-verkkolaskuosoite'] && $_POST['laskuhari-laskutustapa'] == "verkkolasku" ) {
        //wc_add_notice( __( 'Ole hyvä ja syötä verkkolaskuosoite verkkolaskun lähetystä varten' ) , 'error' );
    }

    if ( ! $_POST['laskuhari-valittaja'] && $_POST['laskuhari-laskutustapa'] == "verkkolasku" ) {
        //wc_add_notice( __( 'Ole hyvä ja syötä verkkolaskuvälittäjä verkkolaskun lähetystä varten' ) , 'error' );
    }
}


// Päivitä Laskuharista tuleva metadata

function laskuhari_checkout_update_order_meta( $order_id ) {
    if( $_POST['payment_method'] != "laskuhari" ) {
        return false;
    }
    laskuhari_update_order_meta( $order_id );
}

// Lisää tilauslomakkessa annetut lisätiedot metadataan

function laskuhari_update_order_meta( $order_id)  {
    if ( isset( $_REQUEST['laskuhari-laskutustapa'] ) ) {
        update_post_meta( $order_id, '_laskuhari_laskutustapa', sanitize_text_field( $_REQUEST['laskuhari-laskutustapa'] ) );
    }
    if ( isset( $_REQUEST['laskuhari-ytunnus'] ) ) {
        update_post_meta( $order_id, '_laskuhari_ytunnus', sanitize_text_field( $_REQUEST['laskuhari-ytunnus'] ) );
    }
    if ( isset( $_REQUEST['laskuhari-verkkolaskuosoite'] ) ) {
        update_post_meta( $order_id, '_laskuhari_verkkolaskuosoite', sanitize_text_field( $_REQUEST['laskuhari-verkkolaskuosoite'] ) );
    }
    if ( isset( $_REQUEST['laskuhari-valittaja'] ) ) {
        update_post_meta( $order_id, '_laskuhari_valittaja', sanitize_text_field( $_REQUEST['laskuhari-valittaja'] ) );
    }
    if ( isset( $_REQUEST['laskuhari-viitteenne'] ) ) {
        update_post_meta( $order_id, '_laskuhari_viitteenne', sanitize_text_field( $_REQUEST['laskuhari-viitteenne'] ) );
    }
}

// Luo meta-laatikko Laskuharin toiminnoille tilauksen sivulle

function laskuhari_metabox() {
    if( $_GET['action'] == "edit" ) {
        add_meta_box(
            'laskuhari_metabox',       // Unique ID
            'Laskuhari',               // Box title
            'laskuhari_metabox_html',  // Content callback
            'shop_order',
            'side',
            'core'
        );
    }
}

function laskuhari_invoice_is_created_from_order( $order_id ) {
    return !! get_post_meta( $order_id, '_laskuhari_invoice_number', true );
}

// Hae tilauksen laskutustila

function laskuhari_invoice_status( $order_id ) {
    $laskunumero = get_post_meta( $order_id, '_laskuhari_invoice_number', true );
    $lahetetty   = get_post_meta( $order_id, '_laskuhari_sent', true ) == "yes";

    if( $laskunumero > 0 ) {
        $lasku_luotu = true;
        $tila        = "LASKU LUOTU";
        $tila_class  = " luotu";
        
        if( $lahetetty ) {
            $tila       = "LASKUTETTU";
            $tila_class = " laskutettu";
        }
    } else {
        $lasku_luotu = false;
        $tila        = "EI LASKUTETTU";
        $tila_class  = " ei-laskutettu";
    }

    return [
        "lasku_luotu" => $lasku_luotu,
        "tila"        => $tila,
        "tila_class"  => $tila_class,
        "lahetetty"   => $lahetetty,
        "laskunumero" => $laskunumero
    ];
}

// Luo metaboxin HTML

function laskuhari_metabox_html( $post ) {
    global $laskuhari_gateway_object;

    $tiladata    = laskuhari_invoice_status( $post->ID );
    $tila        = $tiladata['tila'];
    $tila_class  = $tiladata['tila_class'];
    $lahetetty   = $tiladata['lahetetty'];
    $laskunumero = $tiladata['laskunumero'];
    $lasku_luotu = $tiladata['lasku_luotu'];

    $maksutapa = get_post_meta( $post->ID, '_payment_method', true );

    if( $maksutapa && $maksutapa != "laskuhari" && $tila == "EI LASKUTETTU" ) {
        echo '<b>' . __( 'HUOM! Tämän tilauksen maksutapa ei ole Laskuhari.', 'laskuhari' ) . '</b><br />';
        echo '<a class="laskuhari-nappi" href="#" onclick="if(confirm(\'HUOM! Tämän tilauksen maksutapa ei ole Laskuhari! Haluatko jatkaa?\')) {jQuery(\'.laskuhari-laskutoiminnot\').show(); jQuery(this).hide();} return false;">Näytä laskutoiminnot</a>';
        echo '<div class="laskuhari-laskutoiminnot" style="display: none">';
    }
    ?>
    <div class="laskuhari-tila<?php echo $tila_class; ?>"><?php echo __($tila, 'laskuhari'); ?></div>
    <?php
    $order = wc_get_order( $post->ID );
    if( $order && $order->get_status() != "processing" ) {
        echo __( 'Vaihda tilauksen status Käsittelyssä-tilaan, jotta voit laskuttaa sen.', 'laskuhari' );
        return false;
    }

    $edit_link = get_edit_post_link( $post );
    if( $lasku_luotu ) {
        $laskuhari = $laskuhari_gateway_object;

        $invoice_id = laskuhari_invoice_id_by_order( $order->get_id() );
        if( $invoice_id ) {
            $open_link = '#/lasku/' . $invoice_id;
        } else {
            $open_link = '#/laskunro/' . $laskunumero;
        }

        echo '
        <div class="laskuhari-laskunumero">' . __( 'Lasku', 'laskuhari' ) . ' ' . $laskunumero.'</div>
        <a class="laskuhari-nappi lataa-lasku" href="' . $edit_link . '&laskuhari_download=current" target="_blank">' . __( 'Lataa PDF', 'laskuhari' ) . '</a>
        <a class="laskuhari-nappi laheta-lasku" href="#" onclick="jQuery(\'#laskuhari-laheta-lasku-lomake\').slideToggle(); return false;">' . __('Lähetä lasku', 'laskuhari').'' . ( $lahetetty ? ' ' . __( 'uudelleen', 'laskuhari' ) . '' : '' ) . '</a>
        <div id="laskuhari-laheta-lasku-lomake" style="display: none;">';

            $laskuhari->lahetystapa_lomake( $post->ID );

            echo '<input type="button" value="' . __( 'Lähetä lasku', 'laskuhari' ) . '" onclick="laskuhari_admin_lahetys(); return false;" />
        </div>
        <a class="laskuhari-nappi uusi-lasku" href="' . $edit_link . '&laskuhari=create" onclick="if(!confirm(\''.__( 'Tämä luo uuden laskun uudella laskunumerolla. Jatketaanko?', 'laskuhari' ).'\')) return false;">Tee uusi lasku</a>
        <a class="laskuhari-nappi avaa-laskuharissa" href="https://' . laskuhari_domain() . '/' . $open_link . '" target="_blank">' . __( 'Avaa Laskuharissa', 'laskuhari' ).'</a>';
    } else {
        echo '
        <a class="laskuhari-nappi laskuta" href="' . $edit_link . '&laskuhari=create" onclick="if(!confirm(\'' . __( 'Haluatko varmasti luoda laskun? Laskua ei vielä lähetetä.', 'laskuhari' ) . '\')) return false;">' . __( 'Tee lasku', 'laskuhari' ) . '</a>
        <a class="laskuhari-nappi laskuta" href="' . $edit_link . '&laskuhari=send" onclick="if(!confirm(\'' . __( 'Haluatko varmasti laskuttaa tämän tilauksen?', 'laskuhari' ) . '\')) return false;">' . __( 'Tee ja lähetä lasku', 'laskuhari' ) . '</a>';
    }

    if( $maksutapa && $maksutapa != "laskuhari" && $tila == "EI LASKUTETTU" ) {
        echo '</div>';
    }
}

// Lisää laskutuslisä tilaukselle

function laskuhari_add_invoice_surcharge( $cart ) {
    global $laskuhari_gateway_object;
    $laskuhari = $laskuhari_gateway_object;

    if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
        return;
    }
    
    if( ! isset( WC()->session->chosen_payment_method ) || WC()->session->chosen_payment_method !== "laskuhari" ) {
        return;
    }
    
    $laskutuslisa = $laskuhari->laskutuslisa;

    if( $laskutuslisa == 0 ) {
        return;
    }

    $prices_include_tax = get_option( 'woocommerce_prices_include_tax' ) === 'yes' ? true : false; 
    $cart->add_fee( __( 'Laskutuslisä', 'laskuhari' ), $laskuhari->veroton_laskutuslisa( $prices_include_tax ), true );
}

function laskuhari_fallback_notice() {
    echo '<div class="error"><p>' . sprintf( __( 'Laskuhari for Woocommerce vaatii %s viimeisimmän version toimiakseen.', 'laskuhari' ), '<a href="http://wordpress.org/extend/plugins/woocommerce/">WooCommercen</a>' ) . '</p></div>';
}
   
function laskuhari_add_gateway( $methods ) {
    $methods[] = 'WC_Gateway_Laskuhari';
    return $methods;
}

function laskuhari_settings_link() {
    return get_bloginfo('wpurl') . '/wp-admin/admin.php?page=wc-settings&tab=checkout&section=laskuhari';
}

function laskuhari_plugin_action_links( $links, $file ) {
    $this_plugin = plugin_basename( __FILE__ );

    if( $file == $this_plugin ) {
        $settings_link = '<a href="' . laskuhari_settings_link() . '">Asetukset</a>';
        array_unshift( $links, $settings_link );
    }

    return $links;
}

function laskuhari_actions() {
    if( isset( $_GET['laskuhari'] ) ) {
        $send       = ($_GET['laskuhari'] == "send");
        $order_id   = $_GET['order_id'] ? $_GET['order_id'] : $_GET['post'];
        $lh         = laskuhari_process_action( $order_id, $send );

        laskuhari_go_back( $lh );
    }

    if( isset( $_GET['laskuhari_download'] ) ) {
        if( $_GET['laskuhari_download'] === "current" ) {
            $lh = laskuhari_download( false, $_GET['post'] );
        } else if( $_GET['laskuhari_download'] > 0 ) {
            $lh = laskuhari_download( $_GET['laskuhari_download'], $_GET['order_id'] );
        }

        laskuhari_go_back( $lh );
        exit;
    }

    if( isset( $_GET['laskuhari_send_invoice'] ) ) {
        $lh = laskuhari_send_invoice( wc_get_order( $_GET['post'] ) );
        laskuhari_go_back( $lh );
        exit;
    }
}

function laskuhari_go_back( $lh, $url = false ) {
    wp_redirect( laskuhari_back_url( $lh, $url ) );
    exit;
}

function laskuhari_back_url( $lh, $url = false ) {

    if( isset($lh[0]) && is_array( $lh[0] ) ) {
        $data = array(
            "lahetetty" => array(),
            "luotu"     => array(),
            "notices"   => array(),
            "successes" => array()
        );
        foreach( $lh as $datas ) {
            $data["luotu"][]     = $datas["luotu"];
            $data["lahetetty"][] = $datas["lahetetty"];
            $data["notice"][]    = $datas["notice"];
            $data["success"][]   = $datas["success"];
        }
    } else {
        $data = $lh;
    }
    
    $remove = array( 
        '_wpnonce', 'order_id', 'laskuhari', 'laskuhari_download', 'laskuhari', 
        'laskuhari_luotu', 'laskuhari_success', 'laskuhari_lahetetty', 
        'laskuhari_notice', 'laskuhari_send_invoice', 'laskuhari-laskutustapa', 
        'laskuhari-ytunnus', 'laskuhari-verkkolaskuosoite', 'laskuhari-valittaja' 
    );

    $back = remove_query_arg(
        $remove,
        $url
    );

    $back = add_query_arg(
        array(
            'laskuhari_luotu'     => $data["luotu"],
            'laskuhari_lahetetty' => $data["lahetetty"],
            'laskuhari_notice'    => $data["notice"],
            'laskuhari_success'   => $data["success"]
        ),
        $back
    );

    return $back;
}

function laskuhari_demo_notice() {
    echo '
    <div class="notice is-dismissible">
        <p>HUOM! Laskuhari-lisäosan demotila on käytössä! Voit poistaa sen käytöstä <a href="' . laskuhari_settings_link() . '">asetuksista</a></p>
    </div>';
}

function laskuhari_notices() {
    $notices   = is_array($_GET['laskuhari_notice'])    ? $_GET['laskuhari_notice']    : array($_GET['laskuhari_notice']);
    $successes = is_array($_GET['laskuhari_success'])   ? $_GET['laskuhari_success']   : array($_GET['laskuhari_success']);
    $orders    = is_array($_GET['laskuhari_luotu'])     ? $_GET['laskuhari_luotu']     : array($_GET['laskuhari_luotu']);
    $orders2   = is_array($_GET['laskuhari_lahetetty']) ? $_GET['laskuhari_lahetetty'] : array($_GET['laskuhari_lahetetty']);

    foreach ( $notices as $key => $notice ) {
        if( $notice != "" ) {
            $notice_html = esc_html( $notice );
            $notice_html = str_replace( "__asetuksiin__", '<a href="' . laskuhari_settings_link() . '">asetuksiin</a>', $notice_html );
            echo '<div class="notice notice-error is-dismissible"><p>' . $notice_html . '</p></div>';
        }
    }

    foreach ( $successes as $key => $notice ) {
        if( $notice != "" ) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $notice ) . '</p></div>';
        }
    }

    foreach ( $orders as $key => $notice ) {
        if( $notice != "" ) {
            echo '<div class="notice notice-success is-dismissible"><p>Tilauksesta #' . esc_html( $notice ) . ' luotu lasku</p></div>';
        }
    }

    foreach ( $orders2 as $key => $notice ) {
        if( $notice != "" ) {
            echo '<div class="notice notice-success is-dismissible"><p>Tilauksesta #' . esc_html( $notice ) . ' lähetetty lasku</p></div>';
        }
    }
}

function laskuhari_add_styles() {
    wp_enqueue_style(
        'laskuhari-css',
        plugins_url( 'css/staili.css' , __FILE__ ),
        array()
    );
}

function laskuhari_add_public_scripts() {
    global $laskuhari_plugin_version;
    wp_enqueue_script(
        'laskuhari-js-public',
        plugins_url( 'js/public.js' , __FILE__ ),
        array( 'jquery' ),
        $laskuhari_plugin_version
    );
}

function laskuhari_add_admin_scripts() {
    global $laskuhari_plugin_version;
    wp_enqueue_script(
        'laskuhari-js-admin',
        plugins_url( 'js/admin.js' , __FILE__ ),
        array( 'jquery' ),
        $laskuhari_plugin_version
    );
}

function laskuhari_invoice_number_by_order( $orderid ) {
    return get_post_meta( $orderid, '_laskuhari_invoice_number', true );
}

function laskuhari_invoice_id_by_order( $orderid ) {
    return get_post_meta( $orderid, '_laskuhari_invoice_id', true );
}

function laskuhari_uid_by_order( $orderid ) {
    return get_post_meta( $orderid, '_laskuhari_uid', true );
}

function laskuhari_download( $invoice_number, $order_id, $invoice_id = false ) {
    global $laskuhari_gateway_object;

    $laskuhari_uid    = $laskuhari_gateway_object->uid;
    $laskuhari_apikey = $laskuhari_gateway_object->apikey;

    if( ! $laskuhari_uid ) {
        return laskuhari_uid_error();
    }

    if( $invoice_number === false ) {
        $invoice_number = laskuhari_invoice_number_by_order( $order_id );
    }

    if( $invoice_id === false ) {
        $invoice_id = laskuhari_invoice_id_by_order( $order_id );
    }

    $order_uid = laskuhari_uid_by_order( $order_id );

    if( $order_uid && $laskuhari_uid != $order_uid ) {
        $error_notice = __( "Virhe laskun latauksessa. Lasku on luotu eri UID:llä, kuin asetuksissa määritetty UID", "laskuhari" );
        return array(
            "notice" => urlencode( $error_notice )
        );
    }

    // luodaan vahvistuskoodi rajapintaa varten
    $t              = time();
    $digest_src     = $laskuhari_uid."+".$t."+".$laskuhari_apikey;
    $dt = hash("sha256", $digest_src);

    // laskunluontirajapinnan URL
    $url         =  "https://" . laskuhari_domain() . "/api/invoice/".$invoice_number."?uid=".$laskuhari_uid."&t=".$t."&dt=".$dt;
    $post        =  "action=getpdf";
    // suoritetaan curl
    $ch       = laskuhari_curl($post, $url);
    $response = curl_exec($ch);

    // tarkastetaan virheet
    if( curl_errno( $ch ) || stripos( $response, "error" ) !== false || strlen( $response ) < 10 ) {
        $error_notice = __( "Tilauksen PDF-laskun lataaminen epäonnistui", "laskuhari" );
        return array(
            "notice" => urlencode( $error_notice )
        );
    }

    // ohjataan PDF-tiedostoon jos ei ollut virheitä
    wp_redirect( $response );
    exit;
}

function laskuhari_api_request( $payload, $api_url, $action_name = "API request" ) {
    global $laskuhari_gateway_object;

    if( ! $laskuhari_gateway_object->uid ) {
        return laskuhari_uid_error();
    }

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_URL, $api_url);
    curl_setopt($ch, CURLOPT_FAILONERROR, 1);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
    curl_setopt($ch, CURLOPT_TIMEOUT, 100);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type:application/json']);
    curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
    curl_setopt($ch, CURLOPT_POST, TRUE);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);

    if( $laskuhari_gateway_object->enforce_ssl != "yes" ) {
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, FALSE);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
    }

    curl_setopt( $ch, CURLOPT_USERPWD, $laskuhari_gateway_object->uid . ":" . $laskuhari_gateway_object->apikey );

    $response = curl_exec( $ch );

    $curl_errno = curl_errno( $ch );
    $curl_error = curl_error( $ch );

    if( $curl_errno ) {
        error_log( "Laskuhari: " . $action_name . " cURL error: " . $curl_errno . ": " . $curl_error );
        error_log( "Laskuhari: Payload was: " . print_r( $payload, true ) );
    }

    $response_json = json_decode( $response, true );

    if( ! isset( $response_json['status'] ) ) {
        $error_response = print_r( $response, true );
        if( $error_response == "" ) {
            $error_response = "Empty response";
        }
        error_log( "Laskuhari: " . $action_name . " response error: " . $error_response );
        error_log( "Laskuhari: Payload was: " . print_r( $payload, true ) );
        return false;
    }

    if( $response_json['status'] != "OK" ) {
        error_log( "Laskuhari: " . $action_name . " response error: " . print_r( $response_json, true ) );
        error_log( "Laskuhari: Payload was: " . print_r( $payload, true ) );
        return false;
    }

    if( $curl_errno ) {
        return false;
    }
    
    return $response_json;
}

function laskuhari_curl($post, $url) {
    global $laskuhari_gateway_object;

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_FAILONERROR, 1);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
    curl_setopt($ch, CURLOPT_TIMEOUT, 100);
    curl_setopt($ch, CURLOPT_POST, TRUE);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post);

    // laskunlähetyksen asetukset
    $info = $laskuhari_gateway_object;
    if( $info->enforce_ssl != "yes" ) {
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, FALSE);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
    }
    
    return $ch;
}

function laskuhari_rivi( $i, $data ) {
    return  "&laskurivi[".$i."][tyyppi]=0".
            "&laskurivi[".$i."][koodi]=".
            "&laskurivi[".$i."][wc_product_id]=".urlencode( $data['product_id'] ).
            "&laskurivi[".$i."][wc_variation_id]=".urlencode( $data['variation_id'] ).
            "&laskurivi[".$i."][nimike]=".urlencode( $data['nimike'] ).
            "&laskurivi[".$i."][maara]=".urlencode( $data['maara'] ).
            "&laskurivi[".$i."][yks]=".
            "&laskurivi[".$i."][veroton]=".urlencode( $data['veroton'] ).
            "&laskurivi[".$i."][alv]=".urlencode( $data['alv'] ).
            "&laskurivi[".$i."][verollinen]=".$data['verollinen'].
            "&laskurivi[".$i."][ale]=".urlencode( $data['ale'] ).
            "&laskurivi[".$i."][yhtveroton]=".urlencode( $data['yhtveroton'] ).
            "&laskurivi[".$i."][yhtverollinen]=".urlencode( $data['yhtverollinen'] ).
            "&laskurivi[".$i."][toistovali]=0".
            "&laskurivi[".$i."][ennakko]=0".
            "&laskurivi[".$i."][jatkuvuus]=0".
            "&laskurivi[".$i."][automaatio]=0".
            "&laskurivi[".$i."][toistoale]=0";
}

function laskuhari_uid_error() {
    $error_notice = 'Ole hyvä ja syötä Laskuharin UID ja API-koodi lisäosan __asetuksiin__ tai käytä demotilaa.';
    return array(
        "notice" => urlencode( $error_notice )
    );
}

function laskuhari_process_action( $order_id, $send = false, $bulk_action = false ) {
    global $laskuhari_gateway_object;
    
    $error_notice = "";
    $success      = "";

    $order = wc_get_order( $order_id );

    // if we are using bulk action to send, don't create invoice again if it is already created, only send it
    if( $bulk_action && laskuhari_invoice_is_created_from_order( $order_id ) && true === $send ) {
        return laskuhari_send_invoice( $order, $bulk_action );
    }

    $prices_include_tax = get_post_meta( $order_id, '_prices_include_tax', true ) == 'yes' ? true : false;
    
    // laskunlähetyksen asetukset
    $info = $laskuhari_gateway_object;
    $laskuhari_uid           = $info->uid;
    $laskuhari_apikey        = $info->apikey;
    $laskutuslisa            = $info->laskutuslisa;
    $laskutuslisa_alv        = $info->laskutuslisa_alv;
    $laskutuslisa_veroton    = $info->veroton_laskutuslisa( $prices_include_tax );
    $laskutuslisa_verollinen = $info->verollinen_laskutuslisa( $prices_include_tax );

    if( ! $laskuhari_uid ) {
        return laskuhari_uid_error();
    }

    // tilaajan tiedot
    $customer               = $order->get_address( 'billing' );
    $shippingdata           = $order->get_address( 'shipping' );

    $shipping_different = false;

    foreach ( $customer as $key => $cdata ) {
        if( isset( $shippingdata[$key] ) && $shippingdata[$key] != $cdata ) {
            $shipping_different = true;
            break;
        }
    }

    if( ! $shipping_different ) {
        foreach( $shippingdata as $key => $sdata ) {
            $shippingdata[$key] = "";
        }
    }

    // tilatut tuotteet
    $products           = $order->get_items();

    // summat
    $loppusumma         = $order->get_total();
    $toimitustapa       = utf8_decode( $order->get_shipping_method() );
    $toimitusmaksu      = $order->get_total_shipping();
    $toimitus_vero      = $order->get_shipping_tax();
    $cart_discount      = $order->get_discount_total();
    $cart_discount_tax  = $order->get_discount_tax();

    $viitteenne        = get_post_meta( $order->get_id(), '_laskuhari_viitteenne', true );
    $ytunnus           = get_post_meta( $order->get_id(), '_laskuhari_ytunnus', true );
    $verkkolaskuosoite = get_post_meta( $order->get_id(), '_laskuhari_verkkolaskuosoite', true );
    $valittaja         = get_post_meta( $order->get_id(), '_laskuhari_valittaja', true );

    // luodaan vahvistuskoodi rajapintaa varten
    $t          = time();
    $digest_src = $laskuhari_uid."+".$t."+".$laskuhari_apikey;
    $dt         = hash("sha256", $digest_src);

    update_post_meta( $order->get_id(), '_laskuhari_sent', false );

    // laskunluontirajapinnan URL
    $url         =  "https://" . laskuhari_domain() . "/api/invoice?uid=".$laskuhari_uid."&t=".$t."&dt=".$dt;
    $post        =  "action=create&ref=wc";

    $post       .=  '&maksuehto='.
                    '&pvm='.date( 'd.m.Y' ).
                    '&lahetetty=0'.
                    '&viitteenne='.urlencode( $viitteenne ).
                    '&wc_order_id='.urlencode( $order->get_id() ).
                    '&tilausnumero='.urlencode( $order->get_order_number() ).
                    '&yritys='.urlencode( $customer['company'] ).
                    '&henkilo='.urlencode( $customer['first_name'].' '.$customer['last_name'] ).
                    '&lahiosoite='.urlencode( trim( $customer['address_1'].' '.$customer['address_2'] ) ).
                    '&postinumero='.urlencode( $customer['postcode'] ).
                    '&postitoimipaikka='.urlencode( $customer['city'] ).
                    '&toimitus_yritys='.urlencode( $shippingdata['company'] ).
                    '&toimitus_henkilo='.urlencode( $shippingdata['first_name'].' '.$shippingdata['last_name'] ).
                    '&toimitus_lahiosoite='.urlencode( trim( $shippingdata['address_1'].' '.$shippingdata['address_2'] ) ).
                    '&toimitus_postinumero='.urlencode( $shippingdata['postcode'] ).
                    '&toimitus_postitoimipaikka='.urlencode( $shippingdata['city'] ).
                    '&email='.urlencode( $customer['email'] ).
                    '&ytunnus='.urlencode( $ytunnus ).
                    '&puhelin='.urlencode( $customer['phone'] ).
                    '&toIdentifier='.urlencode( $verkkolaskuosoite ).
                    '&toIntermediator='.urlencode( $valittaja ).
                    '&buyerPartyIdentifier='.urlencode( $ytunnus );

    $i = 1;
    $laskettu_summa = 0;
    foreach( $products as $item ) {
        
        $data = $item->get_data();

        $yht_verollinen = round( $data['subtotal'] + $data['subtotal_tax'], 2 );

        if( $data['subtotal'] != 0 ) {
            $alv         = round( $data['subtotal_tax'] / $data['subtotal'] * 100, 0 );
            $yht_veroton = $yht_verollinen / ( 1 + $alv / 100 );
        } else {
            $alv         = 0;
            $yht_veroton = 0;
        }

        if( $data['subtotal'] != 0 ) {
            $yks_verollinen = round( $yht_verollinen / $data['quantity'], 2 );
            $yks_veroton    = $yks_verollinen / ( 1 + $alv / 100 );
        } else {
            $yks_verollinen = 0;
            $yks_veroton    = 0;
        }

        $ale = 0;

        $product_id = $data['variation_id'] ? $data['variation_id'] : $data['product_id'];

        if( $laskuhari_gateway_object->synkronoi_varastosaldot && ! laskuhari_product_synced( $product_id ) ) {
            laskuhari_create_product( $product_id );
        }

        $post .= laskuhari_rivi( $i, [
            "product_id"    => $data['product_id'],
            "variation_id"  => $data['variation_id'],
            "nimike"        => utf8_decode($data['name']),
            "maara"         => $data['quantity'],
            "veroton"       => $yks_veroton,
            "alv"           => $alv,
            "verollinen"    => $yks_verollinen,
            "ale"           => $ale,
            "yhtveroton"    => $yht_veroton,
            "yhtverollinen" => $yht_verollinen
        ] );
        
        $i++;
        $laskettu_summa += $yht_verollinen;
    }


    // lisätään toimitusmaksu
    if( $toimitusmaksu > 0 ) {
        $post .= laskuhari_rivi( $i, [
            "nimike"        => "Toimitustapa: " . $toimitustapa,
            "maara"         => 1,
            "veroton"       => $toimitusmaksu,
            "alv"           => round( $toimitus_vero / $toimitusmaksu * 100, 0 ),
            "verollinen"    => $toimitusmaksu + $toimitus_vero,
            "ale"           => 0,
            "yhtveroton"    => $toimitusmaksu,
            "yhtverollinen" => $toimitusmaksu + $toimitus_vero
        ]);

        $i++;
        $laskettu_summa += $toimitusmaksu + $toimitus_vero;
    }

    // lisätään alennus
    if( $cart_discount ) {
        $post .= laskuhari_rivi( $i, [
            "nimike"        => "Alennus",
            "maara"         => 1,
            "veroton"       => $cart_discount * -1,
            "alv"           => round( $cart_discount_tax / $cart_discount * 100, 0 ),
            "verollinen"    => ( $cart_discount + $cart_discount_tax ) * -1,
            "ale"           => 0,
            "yhtveroton"    => $cart_discount * -1,
            "yhtverollinen" => ( $cart_discount + $cart_discount_tax ) * -1
        ] );

        $i++;
        $laskettu_summa += ( $cart_discount + $cart_discount_tax ) * -1;
    }
    
    // lisätään maksut
    foreach( $order->get_items('fee') as $item_fee ){
        $fee_name      = $item_fee->get_name();
        $fee_total_tax = $item_fee->get_total_tax();
        $fee_total     = $item_fee->get_total();

        $fee_total_including_tax = $fee_total + $fee_total_tax;

        // otetaan laskutuslisä pois desimaalikorjauksen laskennasta
        if( $fee_name == "Laskutuslisä" ) {
            $loppusumma -= $fee_total_including_tax;
            continue;
        }

        if( $fee_total != 0 ) {
            $alv         = round( $fee_total_tax / $fee_total * 100, 0 );
            $yht_veroton = $fee_total;
        } else {
            $alv         = 0;
            $yht_veroton = 0;
        }

        if( $fee_total != 0 ) {
            $yks_verollinen = round( $fee_total_including_tax, 2 );
            $yks_veroton    = $yks_verollinen / ( 1 + $alv / 100 );
        } else {
            $yks_verollinen = 0;
            $yks_veroton    = 0;
        }

        $ale = 0;

        $post .= laskuhari_rivi( $i, [
            "nimike"        => utf8_decode( $fee_name ),
            "maara"         => 1,
            "veroton"       => $yks_veroton,
            "alv"           => $alv,
            "verollinen"    => $yks_verollinen,
            "ale"           => $ale,
            "yhtveroton"    => $yht_veroton,
            "yhtverollinen" => $fee_total
        ] );
        
        $i++;
        $laskettu_summa += $fee_total_including_tax;
    }

    // lisätään laskutuslisä
    if( $laskutuslisa ) {
        $post .= laskuhari_rivi( $i, [
            "nimike"        => "Laskutuslisä",
            "maara"         => 1,
            "veroton"       => $laskutuslisa_veroton,
            "alv"           => $laskutuslisa_alv,
            "verollinen"    => $laskutuslisa_verollinen,
            "ale"           => 0,
            "yhtveroton"    => $laskutuslisa_veroton,
            "yhtverollinen" => $laskutuslisa_verollinen
        ] );
        $i++;
    }

    if( abs( $loppusumma-$laskettu_summa ) > 0.05 ) {
        $error_notice = $error_notice = 'Pyöristysvirhe liian suuri ('.$loppusumma.' - '.$laskettu_summa.' = ' . round( $loppusumma-$laskettu_summa, 2 ) . ')! Laskua ei luotu';
        $order->add_order_note( $error_notice );
        if( function_exists( 'wc_add_notice' ) ) {
            wc_add_notice( 'Laskun automaattinen lähetys epäonnistui. Lähetämme laskun manuaalisesti.', 'notice' );
        }

        return array(
            "notice" => urlencode( $error_notice )
        );
    }

    if( round( $loppusumma, 2 ) != round( $laskettu_summa, 2 ) ) {
        $post .= laskuhari_rivi($i, [
            "nimike"        => "Pyöristys",
            "maara"         => 1,
            "veroton"       => ($loppusumma-$laskettu_summa),
            "alv"           => 0,
            "verollinen"    => ($loppusumma-$laskettu_summa),
            "ale"           => 0,
            "yhtveroton"    => ($loppusumma-$laskettu_summa),
            "yhtverollinen" => ($loppusumma-$laskettu_summa)
        ]);
    }

    $error_notice = "";
    $success = "";

    // suoritetaan curl
    $ch       = laskuhari_curl( $post, $url );
    $response = curl_exec( $ch );

    // tarkastetaan virheet
    if( curl_errno( $ch ) ) {
        $error_notice = 'Virhe laskun luomisessa. cURL errno. ' . curl_errno( $ch ) . ": " . curl_error( $ch );
        $order->add_order_note( $error_notice );
        if( function_exists( 'wc_add_notice' ) ) {
            wc_add_notice( 'Laskun automaattinen lähetys epäonnistui. Lähetämme laskun manuaalisesti.', 'notice' );
        }

        return array(
            "notice" => urlencode( $error_notice )
        );
    }

    if( stripos( $response, "error") !== false ) {
        $error_notice = 'Virhe laskun luomisessa: ' . $response;

        $order->add_order_note( $error_notice );
        if( function_exists( 'wc_add_notice' ) ) {
            wc_add_notice( 'Laskun automaattinen lähetys epäonnistui. Lähetämme laskun manuaalisesti.', 'notice' );
        }

        return array(
            "notice" => urlencode( $error_notice )
        );
    }

    $response = json_decode( $response, true );
    $laskunro = $response['new_invoice_number'];
    $laskuid  = $response['new_invoice_id'];

    curl_close( $ch );

    // jatketaan vain, jos ei ollut virheitä
    if( $error_notice == "" && intval( $laskuid ) > 0 ) {

        update_post_meta( $order->get_id(), '_laskuhari_invoice_number', $laskunro );
        update_post_meta( $order->get_id(), '_laskuhari_invoice_id', $laskuid );
        update_post_meta( $order->get_id(), '_laskuhari_uid', $laskuhari_uid );
        
        $order->add_order_note( __( 'Lasku #' . $laskunro . ' luotu Laskuhariin', 'laskuhari' ) );
        $order->update_status( 'processing' );

        if( $send ) {
            return laskuhari_send_invoice( $order, $bulk_action );
        }

    } else {
        $order->add_order_note( __( 'Laskun luominen Laskuhariin epäonnistui', 'laskuhari' ) );

        return array(
            "notice" => urlencode( $error_notice )
        );
    }

    return array(
        "luotu"   => $order->get_id(),
        "notice"  => urlencode( $error_notice ),
        "success" => urlencode( $success )
    );
}

function laskuhari_send_invoice( $order, $bulk_action = false ) {
    global $laskuhari_gateway_object;

    // laskunlähetyksen asetukset
    $info = $laskuhari_gateway_object;
    $laskuhari_uid    = $info->uid;
    $laskuhari_apikey = $info->apikey;
    $sendername       = $info->laskuttaja;
    $email_message    = $info->laskuviesti;

    if( ! $laskuhari_uid ) {
        return laskuhari_uid_error();
    }

    $order_id = $order->get_id();

    $laskuid   = get_post_meta( $order_id, '_laskuhari_invoice_id', true );
    $laskunro  = get_post_meta( $order_id, '_laskuhari_invoice_number', true );
    $order_uid = get_post_meta( $order_id, '_laskuhari_uid', true );

    if( $order_uid && $laskuhari_uid != $order_uid ) {
        $error_notice = 'Virhe laskun lähetyksessä. Lasku on luotu eri UID:llä, kuin asetuksissa määritetty UID';
        $order->add_order_note( $error_notice );
        if( function_exists( 'wc_add_notice' ) ) {
            wc_add_notice( 'Laskun automaattinen lähetys epäonnistui. Lähetämme laskun manuaalisesti.', 'notice' );
        }

        return array(
            "notice" => urlencode( $error_notice )
        );
    }

    $lahetystapa = "auto";

    if( $bulk_action === true ) {
       $lahetystapa = $info->lahetystapa_manuaalinen;
    }

    laskuhari_update_order_meta( $order_id );

    if( $lahetystapa == "auto" ) {
        $lahetystapa = get_post_meta( $order_id, '_laskuhari_laskutustapa', true );
        if( $lahetystapa == "verkkolasku" ) {
            $verkkolaskuosoite = get_post_meta( $order_id, '_laskuhari_verkkolaskuosoite', true );
            $valittaja         = get_post_meta( $order_id, '_laskuhari_valittaja', true );
            $ytunnus           = get_post_meta( $order_id, '_laskuhari_ytunnus', true );
        } elseif( $lahetystapa == "" ) {
            $lahetystapa = $info->lahetystapa_manuaalinen;
        }
    }

    if( trim(rtrim( $email_message )) == "" ) {
        $email_message = "Liitteenä lasku.";
    }

    // tilaajan tiedot
    $customer = $order->get_address( 'billing' );

    // luodaan POST-pyyntö
    $post = "action=send&ref=wc&site=".urlencode( $_SERVER['HTTP_HOST'] );

    $can_send = false;

    if( $lahetystapa == "verkkolasku" ) {
        $post .= "&invoiceType=finvoice";
        $post .= "&ytunnus=".urlencode( $ytunnus );
        $post .= "&toIdentifier=".urlencode( $verkkolaskuosoite );
        $post .= "&toIntermediator=".urlencode( $valittaja );
        $post .= "&buyerPartyIdentifier=".urlencode( $ytunnus );
        $can_send = true;
        $miten = "verkkolaskuna";
    } else if( $lahetystapa == "email" && $customer['email'] ) {
        $sendername      = $sendername ? $sendername : "Laskutus";
        $post           .= "&invoiceType=email";
        $post           .= "&eMailSenderName=".$sendername;
        $post           .= "&receiverEmail=".urlencode( $customer['email'] );
        $post           .= "&eMailSubject=Lasku";
        $post           .= "&eMailMessage=".urlencode( $email_message );
        $can_send = true;
        $miten = "sähköpostitse";
    } else if( $lahetystapa == "kirje" ) {
        $post .= "&invoiceType=letter";
        $can_send = true;
        $miten = "kirjeenä";
    }

    $lahetetty_tilaus = "";

    if( $can_send ) {

        // luodaan vahvistuskoodi rajapintaa varten
        $t          = time();
        $digest_src = $laskuhari_uid."+".$t."+".$laskuhari_apikey;
        $dt         = hash("sha256", $digest_src);

        // laskunlähetysrajapinnan URL
        $url        = "https://" . laskuhari_domain() . "/api/invoice/".intval($laskunro)."?uid=".$laskuhari_uid."&t=".$t."&dt=".$dt;

        // suoritetaan curl
        $ch         = laskuhari_curl( $post, $url );
        $response   = curl_exec( $ch );

        $error_notice = "";

        // tarkastetaan virheet
        if( curl_errno( $ch ) ) {
            $error_notice = 'Virhe laskun lähetyksessä. cURL errno. ' . curl_errno( $ch ) . ": " . curl_error( $ch );
            $order->add_order_note( $error_notice );
            if( function_exists( 'wc_add_notice' ) ) {
                wc_add_notice( 'Laskun automaattinen lähetys epäonnistui. Lähetämme laskun manuaalisesti.', 'notice' );
            }
            return array(
                "notice" => urlencode( $error_notice )
            );
        }

        if( stripos( $response, "error" ) !== false || stripos( $response, "ok" ) === false ) {
            $error_notice = 'Virhe laskun lähetyksessä: ' . $response;

            if( $response == "KEY_ERROR" ) {
                $error_notice .= ". Huomaathan, että kokeilujaksolla tai demotunnuksilla ei voi lähettää kirje- ja verkkolaskuja.";
            }

            $order->add_order_note( $error_notice );
            if( function_exists( 'wc_add_notice' ) ) {
                wc_add_notice( 'Laskun automaattinen lähetys epäonnistui. Lähetämme laskun manuaalisesti.', 'notice' );
            }

            return array(
                "notice" => urlencode( $error_notice )
            );
        }

        curl_close( $ch );

        $response = json_decode( $response, true );

        if( $error_notice == "" ) {
            $order->add_order_note( __( 'Lasku lähetetty ' . $miten, 'laskuhari' ) );
            update_post_meta( $order->get_id(), '_laskuhari_sent', 'yes' );
            $order->update_status( 'processing' );
            $lahetetty_tilaus = $order->get_id();
        }
    } else {
        $success = "Tilauksen #" . $order->get_id() . " lasku tallennettu Laskuhariin. Laskua ei lähetetty vielä.";
        $lahetetty_tilaus = "";
        $order->add_order_note( __( 'Lasku luotu Laskuhariin, mutta ei lähetetty.', 'laskuhari' ) );
        $order->update_status( 'processing' );
    }

    return array(
        "lahetetty" => $lahetetty_tilaus,
        "notice"    => urlencode( $error_notice ),
        "success"   => urlencode( $success )
    );

}

?>
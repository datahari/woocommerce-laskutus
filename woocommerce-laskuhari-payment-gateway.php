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
        add_action( 'admin_notices', 'woocommerce_laskuhari_fallback_notice' );
        return;
    }

    add_filter( 'woocommerce_payment_gateways', 'laskuhari_add_gateway' );
    add_filter( 'plugin_action_links', 'laskuhari_plugin_action_links', 10, 2 );
    
    require_once plugin_dir_path( __FILE__ ) . 'class-wc-gateway-laskuhari.php';

    $laskuhari_gateway_object = new WC_Gateway_Laskuhari( true );

    if( $laskuhari_gateway_object->get_option( 'enabled' ) !== 'yes' ) {
        return;
    }
    
    laskuhari_actions();

    if( $laskuhari_gateway_object->synkronoi_varastosaldot ) {
        add_action( 'woocommerce_product_set_stock', 'laskuhari_update_stock' ); 
    }

    add_action( 'wp_footer', 'laskuhari_add_public_scripts' );
    add_action( 'wp_footer', 'laskuhari_add_styles' );
    add_action( 'admin_print_scripts', 'laskuhari_add_public_scripts' );
    add_action( 'admin_print_scripts', 'laskuhari_add_admin_scripts' );
    add_action( 'admin_print_styles', 'laskuhari_add_styles' );
    add_action( 'woocommerce_cart_calculate_fees','wc_laskuhari_custom_surcharge', 10, 1 );
    add_action( 'show_user_profile', 'laskuhari_kayttajan_lisatiedot' );
    add_action( 'edit_user_profile', 'laskuhari_kayttajan_lisatiedot' );
    add_action( 'personal_options_update', 'laskuhari_paivita_kayttajan_lisatiedot' );
    add_action( 'edit_user_profile_update', 'laskuhari_paivita_kayttajan_lisatiedot' );
    add_action( 'manage_shop_order_posts_custom_column', 'laskuhari_laskutustila_sarakkeeseen' );
    add_action( 'woocommerce_checkout_process', 'laskuhari_verkkolaskutiedot' );
    add_action( 'woocommerce_checkout_update_order_meta', 'laskuhari_checkout_update_order_meta' );
    add_action( 'add_meta_boxes', 'laskuhari_metabox' );

    add_filter( 'bulk_actions-edit-shop_order', 'laskuhari_add_bulk_action_for_invoicing', 20, 1 );
    add_filter( 'handle_bulk_actions-edit-shop_order', 'laskuhari_handle_bulk_actions', 10, 3 );
    add_filter( 'plugin_row_meta', 'LHPG_register_plugin_links', 10, 2 );
    add_filter( 'manage_edit-shop_order_columns', 'laskuhari_sarake_tilauslistaan' );

    if( isset( $_GET['laskuhari_luotu'] ) || isset( $_GET['laskuhari_lahetetty'] ) || isset( $_GET['laskuhari_notice'] ) || isset( $_GET['laskuhari_success'] ) ) {
        add_action( 'admin_notices', 'laskuhari_notices' );
    }
    
}

function laskuhari_kayttajan_tiedot() {
    $custom_meta_fields = array();
    $custom_meta_fields['laskuhari_laskutusasiakas'] = 'Laskutusasiakas';

    return $custom_meta_fields;
}

function laskuhari_kayttajan_lisatiedot( $user ) {
    echo '<h3>Laskuhari</h3>'.
         '<table class="form-table">';

    $meta_number = 0;
    $custom_meta_fields = laskuhari_kayttajan_tiedot();

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

function laskuhari_paivita_kayttajan_lisatiedot( $user_id ) {

    if( ! current_user_can( 'edit_user', $user_id ) ) {
        return false;
    }

    $meta_number = 0;
    $custom_meta_fields = laskuhari_kayttajan_tiedot();

    foreach ( $custom_meta_fields as $meta_field_name => $meta_disp_name ) {
        $meta_number++;
        update_usermeta( $user_id, $meta_field_name, $_POST[$meta_field_name] );
    }
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

    if( $product->is_type( 'variable' ) ) {
        $product_id   = $product->get_parent_id();
        $variation_id = $product->get_id();
    } else {
        $product_id   = $product->get_id();
        $variation_id = 0;
    }

    $stock_quantity = $product->get_stock_quantity();

    $api_url = "https://testi.laskuhari.fi/rest-api/tuote/varastosaldo/";

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

    $curl     = laskuhari_api_request( $payload, $api_url );
    $response = curl_exec( $curl );

    $curl_errno = curl_errno( $curl );
    $curl_error = curl_error( $curl );

    if( $curl_errno ) {
        error_log( "Laskuhari: Stock update cURL error: " . $curl_errno . ": " . $curl_error );
        error_log( "Laskuhari: Payload was: " . print_r( $payload, true ) );
    }

    $response_json = json_decode( $response, true );

    if( ! isset( $response_json['status'] ) ) {
        $error_response = print_r( $response, true );
        if( $error_response == "" ) {
            $error_response = "Empty response";
        }
        error_log( "Laskuhari: Stock update response error: " . $error_response );
        error_log( "Laskuhari: Payload was: " . print_r( $payload, true ) );
        return false;
    }

    if( $response_json['status'] != "OK" ) {
        error_log( "Laskuhari: Stock update response error: " . print_r( $response_json, true ) );
        error_log( "Laskuhari: Payload was: " . print_r( $payload, true ) );
        return false;
    }

    if( $curl_errno ) {
        return false;
    }

    if( $response_json['varastosaldo'] != $stock_quantity ) {
        error_log( "Laskuhari: Stock update did not work for product ID " . $product_id );
        error_log( "Laskuhari: Payload was: " . print_r( $payload, true ) );
        return false;
    }

    
    return true;
}

// Lisää "Kirjaudu Laskuhariin" -linkki lisäosan tietoihin

function LHPG_register_plugin_links( $links, $file ) {
    $base = plugin_basename( __FILE__ );
    
    if( $file == $base ) {
        $links[] = '<a href="https://oma.laskuhari.fi/" target="_blank">' . __( 'Kirjaudu Laskuhariin', 'laskuhari' ) . '</a>';
    }

    return $links;
}


// Lisää Laskuhari-sarake tilauslistaan

function laskuhari_sarake_tilauslistaan( $columns ) {
    $columns['laskuhari'] = 'Laskuhari';
    return $columns;
}

// Lisää Laskuhari-sarakkeeseen tilauksen laskutustila

function laskuhari_laskutustila_sarakkeeseen( $column ) {
    global $post;
    if( 'laskuhari' === $column ) {
        $data = laskuhari_tilauksen_laskutustila( $post->ID );
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
        echo '<span class="order-status status-'.$status.'"><span>'.$data['tila'].'</span></span>';
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

function laskuhari_verkkolaskutiedot() {
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

function laskuhari_tilauksen_laskutustila( $order_id ) {
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

    $tiladata    = laskuhari_tilauksen_laskutustila( $post->ID );
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
        echo '
        <div class="laskuhari-laskunumero">' . __( 'Lasku', 'laskuhari' ) . ' ' . $laskunumero.'</div>
        <a class="laskuhari-nappi lataa-lasku" href="' . $edit_link . '&laskuhari_download=current" target="_blank">' . __( 'Lataa PDF', 'laskuhari' ) . '</a>
        <a class="laskuhari-nappi laheta-lasku" href="#" onclick="jQuery(\'#laskuhari-laheta-lasku-lomake\').slideToggle(); return false;">' . __('Lähetä lasku', 'laskuhari').'' . ( $lahetetty ? ' ' . __( 'uudelleen', 'laskuhari' ) . '' : '' ) . '</a>
        <div id="laskuhari-laheta-lasku-lomake" style="display: none;">';

            $laskuhari->lahetystapa_lomake( $post->ID );

            echo '<input type="button" value="' . __( 'Lähetä lasku', 'laskuhari' ) . '" onclick="laskuhari_admin_lahetys(); return false;" />
        </div>
        <a class="laskuhari-nappi uusi-lasku" href="' . $edit_link . '&laskuhari=create" onclick="if(!confirm(\''.__( 'Tämä luo uuden laskun uudella laskunumerolla. Jatketaanko?', 'laskuhari' ).'\')) return false;">Tee uusi lasku</a>
        <a class="laskuhari-nappi avaa-laskuharissa" href="https://oma.laskuhari.fi/#/laskunro/' . $laskunumero . '" target="_blank">' . __( 'Avaa Laskuharissa', 'laskuhari' ).'</a>';
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

function wc_laskuhari_custom_surcharge( $cart ) {
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

    $prices_include_tax = get_option( 'woocommerce_prices_include_tax' ) == 'yes' ? true : false; 
    $cart->add_fee( __( 'Laskutuslisä', 'laskuhari' ), $laskuhari->veroton_laskutuslisa( $prices_include_tax ), true );
}

/* WooCommerce fallback notice. */

function woocommerce_laskuhari_fallback_notice() {
    echo '<div class="error"><p>' . sprintf( __( 'WooCommerce Laskuhari Payment Gateway depends on the last version of %s to work!', 'LHPG' ), '<a href="http://wordpress.org/extend/plugins/woocommerce/">WooCommerce</a>' ) . '</p></div>';
}
   
function laskuhari_add_gateway( $methods ) {
    $methods[] = 'WC_Gateway_Laskuhari';
    return $methods;
}

function laskuhari_plugin_action_links( $links, $file ) {
    $this_plugin = plugin_basename( __FILE__ );

    if( $file == $this_plugin ) {
        $settings_link = '<a href="' . get_bloginfo('wpurl') . '/wp-admin/admin.php?page=wc-settings&tab=checkout&section=laskuhari">Asetukset</a>';
        array_unshift( $links, $settings_link );
    }

    return $links;
}

function laskuhari_actions() {
    if( isset($_GET['laskuhari']) ) {
        $send       = ($_GET['laskuhari'] == "send");
        $order_id   = $_GET['order_id'] ? $_GET['order_id'] : $_GET['post'];
        $lh         = laskuhari_process_action( $order_id, $send );

        laskuhari_go_back( $lh );
    }

    if( isset($_GET['laskuhari_download']) ) {
        if( $_GET['laskuhari_download'] == "current" ) {
            laskuhari_download( false, $_GET['post'] );
        } else if( $_GET['laskuhari_download'] > 0 ) {
            laskuhari_download( $_GET['laskuhari_download'], $_GET['order_id'] );
        }
        exit;
    }

    if( isset($_GET['laskuhari_send_invoice']) ) {
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

function laskuhari_notices() {
    $notices   = is_array($_GET['laskuhari_notice']) ? $_GET['laskuhari_notice'] : array($_GET['laskuhari_notice']);
    $successes = is_array($_GET['laskuhari_success']) ? $_GET['laskuhari_success'] : array($_GET['laskuhari_success']);
    $orders    = is_array($_GET['laskuhari_luotu']) ? $_GET['laskuhari_luotu'] : array($_GET['laskuhari_luotu']);
    $orders2   = is_array($_GET['laskuhari_lahetetty']) ? $_GET['laskuhari_lahetetty'] : array($_GET['laskuhari_lahetetty']);
    foreach( $notices as $key => $notice ) {
        if($notice != "") {
            echo '<div class="notice notice-error is-dismissible"><p>'.esc_html($notice).'</p></div>';
        }
    }
    foreach( $successes as $key => $notice ) {
        if($notice != "") {
            echo '<div class="notice notice-success is-dismissible"><p>'.esc_html($notice).'</p></div>';
        }
    }
    foreach( $orders as $key => $notice ) {
        if($notice != "") {
            echo '<div class="notice notice-success is-dismissible"><p>Tilauksesta #'.esc_html($notice).' luotu lasku</p></div>';
        }
    }
    foreach( $orders2 as $key => $notice ) {
        if($notice != "") {
            echo '<div class="notice notice-success is-dismissible"><p>Tilauksesta #'.esc_html($notice).' lähetetty lasku</p></div>';
        }
    }
}

function laskuhari_add_styles() {
    wp_enqueue_style(
        'laskuhari-css',
        plugins_url('css/staili.css' , __FILE__),
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

function laskuhari_download($invoice_number, $order_id) {
    global $laskuhari_gateway_object;

    // laskunlähetyksen asetukset
    $info = $laskuhari_gateway_object;
    $laskuhari_uid          = $info->uid;
    $laskuhari_apikey       = $info->apikey;

    if( $invoice_number === false ) {
        $invoice_number = laskuhari_invoice_number_by_order( $order_id );
    }

    // luodaan vahvistuskoodi rajapintaa varten
    $t              = time();
    $digest_src     = $laskuhari_uid."+".$t."+".$laskuhari_apikey;
    $dt = hash("sha256", $digest_src);

    // laskunluontirajapinnan URL
    $url         =  "https://oma.laskuhari.fi/api/invoice/".$invoice_number."?uid=".$laskuhari_uid."&t=".$t."&dt=".$dt;
    $post        =  "action=getpdf";
    // suoritetaan curl
    $ch       = laskuhari_curl($post, $url);
    $response = curl_exec($ch);

    // tarkastetaan virheet
    if(curl_errno($ch) || stripos(".".$response, "error")) {
        update_post_meta($order_id, '_laskuhari_invoice_number', '');
        add_action( 'admin_notices', 'laskuhari_getpdf_fail' );
        return;
    }

    //ohjataan PDF-tiedostoon jos ei ollut virheitä
    wp_redirect($response);
    exit;
}

function laskuhari_getpdf_fail() {
    echo '<div class="notice notice-error is-dismissible"><p>Tilauksen PDF-laskun lataaminen epäonnistui</p></div>';
}

function laskuhari_api_request( $payload, $api_url ) {
    global $laskuhari_gateway_object;

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

    // laskunlähetyksen asetukset
    $info = $laskuhari_gateway_object;
    if( $info->enforce_ssl != "yes" ) {
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, FALSE);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
    }

    curl_setopt( $ch, CURLOPT_USERPWD, $info->uid . ":" . $info->apikey );
    
    return $ch;
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
            "&laskurivi[".$i."][wc_product_id]=".urlencode($data['product_id']).
            "&laskurivi[".$i."][wc_variation_id]=".urlencode($data['variation_id']).
            "&laskurivi[".$i."][nimike]=".urlencode($data['nimike']).
            "&laskurivi[".$i."][maara]=".urlencode($data['maara']).
            "&laskurivi[".$i."][yks]=".
            "&laskurivi[".$i."][veroton]=".urlencode($data['veroton']).
            "&laskurivi[".$i."][alv]=".urlencode($data['alv']).
            "&laskurivi[".$i."][verollinen]=".$data['verollinen'].
            "&laskurivi[".$i."][ale]=".urlencode($data['ale']).
            "&laskurivi[".$i."][yhtveroton]=".urlencode($data['yhtveroton']).
            "&laskurivi[".$i."][yhtverollinen]=".urlencode($data['yhtverollinen']).
            "&laskurivi[".$i."][toistovali]=0".
            "&laskurivi[".$i."][ennakko]=0".
            "&laskurivi[".$i."][jatkuvuus]=0".
            "&laskurivi[".$i."][automaatio]=0".
            "&laskurivi[".$i."][toistoale]=0";
}

function laskuhari_process_action($order_id, $send = false, $massatoiminto = false) {
    global $wc_order_types;
    global $laskuhari_gateway_object;
    
    $error_notice = "";
    $success      = "";

    $order = wc_get_order($order_id);

    if( $massatoiminto && laskuhari_invoice_is_created_from_order( $order_id ) && true === $send ) {
        return laskuhari_send_invoice( $order, $massatoiminto );
    }

    $prices_include_tax = get_post_meta($order_id, '_prices_include_tax', true) == 'yes' ? true : false;
    
    // laskunlähetyksen asetukset
    $info = $laskuhari_gateway_object;
    $laskuhari_uid           = $info->uid;
    $laskuhari_apikey        = $info->apikey;
    $laskutuslisa            = $info->laskutuslisa;
    $laskutuslisa_alv        = $info->laskutuslisa_alv;
    $laskutuslisa_veroton    = $info->veroton_laskutuslisa( $prices_include_tax );
    $laskutuslisa_verollinen = $info->verollinen_laskutuslisa( $prices_include_tax );

    // tilaajan tiedot
    $customer               = $order->get_address('billing');
    $shippingdata           = $order->get_address('shipping');

    $shipping_different = false;
    foreach ($customer as $key => $cdata) {
        if($shippingdata[$key] != $cdata && isset($shippingdata[$key])) {
            $shipping_different = true;
            break;
        }
    }
    if(!$shipping_different) {
        foreach($shippingdata as $key => $sdata) {
            $shippingdata[$key] = "";
        }
    }

    // tilatut tuotteet
    $products           = $order->get_items();

    // summat
    $loppusumma         = $order->get_total();
    $toimitustapa       = utf8_decode($order->get_shipping_method());
    $toimitusmaksu      = $order->get_total_shipping();
    $toimitus_vero      = $order->get_shipping_tax();
    $alennus            = $order->get_total_discount();
    $cart_discount      = $order->get_discount_total();
    $cart_discount_tax  = $order->get_discount_tax();

    $viitteenne        = get_post_meta($order->get_id(), '_laskuhari_viitteenne', true);
    $ytunnus           = get_post_meta($order->get_id(), '_laskuhari_ytunnus', true);
    $verkkolaskuosoite = get_post_meta($order->get_id(), '_laskuhari_verkkolaskuosoite', true);
    $valittaja         = get_post_meta($order->get_id(), '_laskuhari_valittaja', true);

    // luodaan vahvistuskoodi rajapintaa varten
    $t          = time();
    $digest_src = $laskuhari_uid."+".$t."+".$laskuhari_apikey;
    $dt         = hash("sha256", $digest_src);


    update_post_meta( $order->get_id(), '_laskuhari_sent', false );

    // laskunluontirajapinnan URL
    $url         =  "https://oma.laskuhari.fi/api/invoice?uid=".$laskuhari_uid."&t=".$t."&dt=".$dt;
    $post        =  "action=create&ref=wc";

    $post       .=  '&maksuehto='.
                    '&pvm='.date('d.m.Y').
                    '&lahetetty=0'.
                    '&viitteenne='.urlencode($viitteenne).
                    '&wc_order_id='.urlencode($order->get_id()).
                    '&tilausnumero='.urlencode($order->get_order_number()).
                    '&yritys='.urlencode($customer['company']).
                    '&henkilo='.urlencode($customer['first_name'].' '.$customer['last_name']).
                    '&lahiosoite='.urlencode(trim($customer['address_1'].' '.$customer['address_2'])).
                    '&postinumero='.urlencode($customer['postcode']).
                    '&postitoimipaikka='.urlencode($customer['city']).
                    '&toimitus_yritys='.urlencode($shippingdata['company']).
                    '&toimitus_henkilo='.urlencode($shippingdata['first_name'].' '.$shippingdata['last_name']).
                    '&toimitus_lahiosoite='.urlencode(trim($shippingdata['address_1'].' '.$shippingdata['address_2'])).
                    '&toimitus_postinumero='.urlencode($shippingdata['postcode']).
                    '&toimitus_postitoimipaikka='.urlencode($shippingdata['city']).
                    '&email='.urlencode($customer['email']).
                    '&ytunnus='.urlencode($ytunnus).
                    '&puhelin='.urlencode($customer['phone']).
                    '&toIdentifier='.urlencode($verkkolaskuosoite).
                    '&toIntermediator='.urlencode($valittaja).
                    '&buyerPartyIdentifier='.urlencode($ytunnus);

    $i = 1;
    $laskettu_summa = 0;
    foreach($products as $item) {
        
        $data = $item->get_data();

        $yht_verollinen = round($data['subtotal'] + $data['subtotal_tax'], 2);

        if( $data['subtotal'] != 0 ) {
            $alv         = round($data['subtotal_tax']/$data['subtotal']*100, 0);
            $yht_veroton = $yht_verollinen / (1+$alv/100);
        } else {
            $alv         = 0;
            $yht_veroton = 0;
        }

        if( $data['subtotal'] != 0 ) {
            $yks_verollinen = round($yht_verollinen/$data['quantity'], 2);
            $yks_veroton    = $yks_verollinen/(1+$alv/100);
        } else {
            $yks_verollinen = 0;
            $yks_veroton    = 0;
        }

        $ale = 0;

        $post .= laskuhari_rivi($i, [
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
        ]);
        
        $i++;
        $laskettu_summa += $yht_verollinen;
    }


    // lisätään toimitusmaksu
    if($toimitusmaksu > 0) {
        $post .= laskuhari_rivi($i, [
            "nimike"        => "Toimitustapa: ".$toimitustapa,
            "maara"         => 1,
            "veroton"       => $toimitusmaksu,
            "alv"           => round($toimitus_vero/$toimitusmaksu*100, 0),
            "verollinen"    => ($toimitusmaksu+$toimitus_vero),
            "ale"           => 0,
            "yhtveroton"    => $toimitusmaksu,
            "yhtverollinen" => ($toimitusmaksu+$toimitus_vero)
        ]);

        $i++;
        $laskettu_summa += ($toimitusmaksu+$toimitus_vero);
    }

    // lisätään alennus
    if($cart_discount) {
        $post .= laskuhari_rivi($i, [
            "nimike"        => "Alennus",
            "maara"         => 1,
            "veroton"       => ($cart_discount*-1),
            "alv"           => round($cart_discount_tax/$cart_discount*100, 0),
            "verollinen"    => (($cart_discount+$cart_discount_tax)*-1),
            "ale"           => 0,
            "yhtveroton"    => ($cart_discount*-1),
            "yhtverollinen" => (($cart_discount+$cart_discount_tax)*-1)
        ]);

        $i++;
        $laskettu_summa += (($cart_discount+$cart_discount_tax)*-1);
    }
    
    // lisätään maksut
    foreach( $order->get_items('fee') as $item_fee ){
        $fee_name = $item_fee->get_name();
        $fee_total_tax = $item_fee->get_total_tax();
        $fee_total = $item_fee->get_total();
        $fee_total_including_tax = $fee_total + $fee_total_tax;

        // otetaan laskutuslisä pois desimaalikorjauksen laskennasta
        if( $fee_name == "Laskutuslisä" ) {
            $loppusumma -= $fee_total_including_tax;
            continue;
        }

        if( $fee_total != 0 ) {
            $alv         = round($fee_total_tax/$fee_total*100, 0);
            $yht_veroton = $fee_total;
        } else {
            $alv         = 0;
            $yht_veroton = 0;
        }

        if( $fee_total != 0 ) {
            $yks_verollinen = round($fee_total_including_tax, 2);
            $yks_veroton    = $yks_verollinen/(1+$alv/100);
        } else {
            $yks_verollinen = 0;
            $yks_veroton    = 0;
        }

        $ale = 0;

        $post .= laskuhari_rivi($i, [
            "nimike"        => utf8_decode($fee_name),
            "maara"         => 1,
            "veroton"       => $yks_veroton,
            "alv"           => $alv,
            "verollinen"    => $yks_verollinen,
            "ale"           => $ale,
            "yhtveroton"    => $yht_veroton,
            "yhtverollinen" => $fee_total
        ]);
        
        $i++;
        $laskettu_summa += $fee_total_including_tax;
    }

    // lisätään laskutuslisä
    if($laskutuslisa) {
        $post .= laskuhari_rivi($i, [
            "nimike"        => "Laskutuslisä",
            "maara"         => 1,
            "veroton"       => $laskutuslisa_veroton,
            "alv"           => $laskutuslisa_alv,
            "verollinen"    => $laskutuslisa_verollinen,
            "ale"           => 0,
            "yhtveroton"    => $laskutuslisa_veroton,
            "yhtverollinen" => $laskutuslisa_verollinen
        ]);
        $i++;
    }

    if( abs($loppusumma-$laskettu_summa) > 0.05 ) {
        $order->add_order_note( $error_notice='Pyöristysvirhe liian suuri ('.$loppusumma.' - '.$laskettu_summa.' = '.round($loppusumma-$laskettu_summa, 2).')! Laskua ei luotu' );
        if(function_exists('wc_add_notice')) wc_add_notice( 'Laskun automaattinen lähetys epäonnistui. Lähetämme laskun manuaalisesti.', 'notice' );

        return array(
            "notice" => urlencode($error_notice)
        );
    }

    if(round($loppusumma, 2) != round($laskettu_summa, 2)) {
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

    // suoritetaan curl
    if( ! $_GET['laskuhari_invoice_number'] ) {
        $ch       = laskuhari_curl($post, $url);
        $response = curl_exec($ch);

        // tarkastetaan virheet
        if(curl_errno($ch)) {
            $order->add_order_note( $error_notice='Virhe laskun lähetyksessä. cURL errno. '.curl_errno().": ".curl_error() );
            if(function_exists('wc_add_notice')) wc_add_notice( 'Laskun automaattinen lähetys epäonnistui. Lähetämme laskun manuaalisesti.', 'notice' );
        }
        if(stripos(".".$response, "error")) {
            $order->add_order_note( $error_notice='Virhe laskun lähetyksessä: '.$response );
            if(function_exists('wc_add_notice')) wc_add_notice( 'Laskun automaattinen lähetys epäonnistui. Lähetämme laskun manuaalisesti.', 'notice' );
        }

        $response = json_decode($response, true);
        $laskunro = $response['new_invoice_number'];

        curl_close($ch);
    } else {
        $laskunro = $_GET['laskuhari_invoice_number'];
    }

    // jatketaan vain, jos ei ollut virheitä
    if($error_notice == "") {

        update_post_meta( $order->get_id(), '_laskuhari_invoice_number', $laskunro );
        
        $order->add_order_note( __( 'Lasku #'.$laskunro.' luotu Laskuhariin', 'laskuhari' ) );
        $order->update_status('processing');

        if( $send ) {
            return laskuhari_send_invoice( $order, $massatoiminto );
        }

    } else {
        $order->add_order_note( __( 'Laskun luominen Laskuhariin epäonnistui', 'laskuhari' ) );
    }

    return array(
        "luotu" => $order->get_id(),
        "notice" => urlencode($error_notice),
        "success" => urlencode($success)
    );
}

function laskuhari_send_invoice($order, $massatoiminto = false) {
    global $laskuhari_gateway_object;

    // laskunlähetyksen asetukset
    $info = $laskuhari_gateway_object;
    $laskuhari_uid          = $info->uid;
    $laskuhari_apikey       = $info->apikey;
    $sendername             = $info->laskuttaja;
    $email_message          = $info->laskuviesti;

    $order_id = $order->get_id();

    $laskunro = get_post_meta($order_id, '_laskuhari_invoice_number', true);

    $lahetystapa = "auto";

    if($massatoiminto == true) {
       $lahetystapa = $info->lahetystapa_manuaalinen;
    }

    laskuhari_update_order_meta($order_id);

    if( $lahetystapa == "auto" ) {
        $lahetystapa = get_post_meta($order_id, '_laskuhari_laskutustapa', true);
        if( $lahetystapa == "verkkolasku" ) {
            $verkkolaskuosoite = get_post_meta($order_id, '_laskuhari_verkkolaskuosoite', true);
            $valittaja         = get_post_meta($order_id, '_laskuhari_valittaja', true);
            $ytunnus           = get_post_meta($order_id, '_laskuhari_ytunnus', true);
        } elseif( $lahetystapa == "" ) {
            $lahetystapa = $info->lahetystapa_manuaalinen;
        }
    }

    if(trim(rtrim($email_message)) == "") {
        $email_message = "Liitteenä lasku.";
    }

    // tilaajan tiedot
    $customer               = $order->get_address('billing');

    // luodaan POST-pyyntö
    $post = "action=send&ref=wc&site=".urlencode($_SERVER['HTTP_HOST']);

    $can_send = false;

    if( $lahetystapa == "verkkolasku" ) {
        $post .= "&invoiceType=finvoice";
        $post .= "&ytunnus=".urlencode($ytunnus);
        $post .= "&toIdentifier=".urlencode($verkkolaskuosoite);
        $post .= "&toIntermediator=".urlencode($valittaja);
        $post .= "&buyerPartyIdentifier=".urlencode($ytunnus);
        $can_send = true;
        $miten = "verkkolaskuna";
    } else if( $lahetystapa == "email" && $customer['email'] ) {
        $sendername      = $sendername ? $sendername : "Laskutus";
        $post           .= "&invoiceType=email";
        $post           .= "&eMailSenderName=".$sendername;
        $post           .= "&receiverEmail=".urlencode($customer['email']);
        $post           .= "&eMailSubject=Lasku";
        $post           .= "&eMailMessage=".urlencode($email_message);
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
        $url        = "https://oma.laskuhari.fi/api/invoice/".($laskunro*1)."?uid=".$laskuhari_uid."&t=".$t."&dt=".$dt;

        // suoritetaan curl
        $ch         = laskuhari_curl($post, $url);
        $response   = curl_exec($ch);

        // tarkastetaan virheet
        if(curl_errno($ch)) {
            $order->add_order_note( $error_notice='Virhe laskun lähetyksessä. cURL errno. '.curl_errno().": ".curl_error() );
            if(function_exists('wc_add_notice')) wc_add_notice( 'Laskun automaattinen lähetys epäonnistui. Lähetämme laskun manuaalisesti.', 'notice' );
        }
        if(stripos(".".$response, "error")) {
            $order->add_order_note( $error_notice='Virhe laskun lähetyksessä: '.$response );
            if(function_exists('wc_add_notice')) wc_add_notice( 'Laskun automaattinen lähetys epäonnistui. Lähetämme laskun manuaalisesti.', 'notice' );
        }

        curl_close($ch);

        $response = json_decode( $response, true );

        if($error_notice == "") {
            $order->add_order_note( __( 'Lasku lähetetty '.$miten, 'laskuhari' ) );
            update_post_meta( $order->get_id(), '_laskuhari_sent', 'yes' );
            $order->update_status('processing');
            $lahetetty_tilaus = $order->get_id();
        }
    } else {
        $success = "Tilauksen #".$order->get_id()." lasku tallennettu Laskuhariin. Laskua ei lähetetty vielä.";
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
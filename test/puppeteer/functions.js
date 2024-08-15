const config = require( "./config.js" );

exports.accept_dialog = dialog => {
    dialog.accept("1.43");
};

exports.login = async function( page, user, password ) {
    let user_login = await page.waitForSelector( "#user_login" );
    let user_pass = await page.waitForSelector( "#user_pass" );

    await exports.sleep( 1000 );

    await page.evaluate( () => document.getElementById("user_login").value = "");
    await page.evaluate( () => document.getElementById("user_pass").value = "");

    await exports.sleep( 200 );

    await user_login.type( user );
    await user_pass.type( password );

    await page.click( "#wp-submit" );
    await exports.sleep( 4000 );
}

exports.open_settings = async function( page ) {
    await page.goto( config.wordpress_url+"/wp-admin/admin.php?page=wc-settings&tab=checkout&section=laskuhari" );
    await exports.login( page, config.wordpress_user, config.wordpress_password );
    await page.waitForSelector( "#woocommerce_laskuhari_enabled" );
}

exports.check_invoice_amounts = async function( page, order_id, excl_tax, tax, incl_tax ) {
    await page.goto( config.wordpress_url+"/wp-admin/admin.php?page=wc-orders&action=edit&id="+order_id+"&laskuhari_action=get_amount_data" );
    await page.waitForSelector( "pre" );

    const data = await page.evaluate(() => {
        return JSON.parse( document.querySelector( "pre" ).textContent );
    });

    expect( data.veroton ).toBe( excl_tax );
    expect( data.alv ).toBe( tax );
    expect( data.verollinen ).toBe( incl_tax );
}

exports.check_invoice_row_amounts = async function( page, order_id, correct_rows ) {
    await page.goto( config.wordpress_url+"/wp-admin/admin.php?page=wc-orders&action=edit&id="+order_id+"&laskuhari_action=get_invoice_data" );
    await page.waitForSelector( "pre" );

    const data = await page.evaluate(() => {
        return JSON.parse( document.querySelector( "pre" ).textContent );
    });

    const rows = data.laskurivit;

    for( i in correct_rows ) {
        for( const key in correct_rows[i] ) {
            expect( Math.round( rows[i][key] * 100 ) / 100 ).toBe( correct_rows[i][key] );
        }
    }
}

exports.logout = async function( page ) {
    await exports.wait_for_loading( page );
    await page.hover( "#wp-admin-bar-my-account" );
    await exports.wait_for_loading( page );
    await page.click( "#wp-admin-bar-logout a" );
    await exports.wait_for_loading( page );
}

exports.add_product_to_cart_and_go_to_checkout = async function( page ) {
    // go to shop page
    await page.goto( config.wordpress_url+"/?post_type=product" );

    // add product to cart
    await page.waitForSelector( ".product-type-simple.post-15 .add_to_cart_button" );
    await page.click( ".product-type-simple.post-15 .add_to_cart_button" );

    // wait for add to cart action
    await page.waitForSelector( ".woocommerce-mini-cart__total.total" );

    // click to cart
    await page.click( "a.cart-contents" );

    // wait for checkout button and click it
    await page.waitForSelector( ".checkout-button" );
    await page.click( ".checkout-button" );

    // wait for checkout form
    await page.waitForSelector( "#place_order" );
}

exports.make_order_before_select_invoice_method = async function( page, testid ) {
    await exports.add_product_to_cart_and_go_to_checkout( page );
    await exports.fill_out_checkout_form( page, testid );
    await exports.select_laskuhari_payment_method( page );
    await exports.wait_for_loading( page );
}

exports.fill_out_checkout_form = async function( page, testid ) {
    // insert first name
    await page.click( "#billing_first_name" );
    await page.keyboard.type( "John " + testid );

    // insert last name
    await page.click( "#billing_last_name" );
    await page.keyboard.type( "Doe" );

    // fill address details
    await page.click( "#billing_address_1" );
    await page.keyboard.type( "Testroad 123" );
    await page.click( "#billing_postcode" );
    await page.keyboard.type( "00100" );
    await page.click( "#billing_city" );
    await page.keyboard.type( "Testplace" );
    await page.click( "#billing_phone" );
    await page.keyboard.type( "040 1234 567" );
    await page.evaluate(() => document.getElementById("billing_email").value="");
    await page.click( "#billing_email" );
    await page.keyboard.type( config.test_email );

    // blur from email field
    await page.click( "#order_review" );
}

exports.select_laskuhari_payment_method = async function( page ) {
    // select laskuhari payment method
    await exports.wait_for_loading( page );
    await page.waitForSelector( "label[for=payment_method_laskuhari]" );
    await page.click( "label[for=payment_method_laskuhari]" );
    await page.waitForSelector( "#laskuhari-laskutustapa" );
}

exports.select_paytrail_payment_method = async function( page ) {
    // select paytrail payment method
    await exports.wait_for_loading( page );
    await page.waitForSelector( "label[for=payment_method_paytrail]" );
    await page.click( "label[for=payment_method_paytrail]" );

    // click paytrail bank payment method
    await exports.wait_for_loading( page );
    await page.waitForSelector( ".paytrail-provider-group-title.bank" );
    await page.click( ".paytrail-provider-group-title.bank" );

    // select osuuspankki
    await exports.wait_for_loading( page );
    await page.waitForSelector( ".paytrail-woocommerce-payment-fields--list-item--input[value=osuuspankki]" );
    await page.click( ".paytrail-woocommerce-payment-fields--list-item--input[value=osuuspankki]" );
}

exports.wait_for_loading = async function( page ) {
    await exports.sleep( 500 );
    await page.waitForFunction( function() {
        return !jQuery( ".blockOverlay" ).is( ":visible" ) && !jQuery(":animated").length;
    } );
    await page.waitForNetworkIdle({timeout: 60000});
    await exports.sleep( 500 );
}

exports.sleep = async function( ms ) {
    return new Promise( resolve => setTimeout( resolve, ms ) );
}

exports.place_order = async function( page ) {
    await exports.sleep( 2000 );

    // send order
    await page.click( "#place_order" );

    // wait for order to complete
    await page.waitForSelector( ".woocommerce-order-received", {
        timeout: 60000
    } );
}

exports.make_order = async function( page, testid ) {
    await exports.make_order_before_select_invoice_method( page, testid );

    // select email invoicing
    await page.evaluate(function() {
        jQuery("#laskuhari-laskutustapa").val("email").change();
    });
    await exports.wait_for_loading( page );

    // insert reference
    await page.click( "#laskuhari-viitteenne" );
    await page.keyboard.type( "testing reference" );

    await exports.wait_for_loading( page );
    await exports.place_order( page );
}

/**
 * Gets the order ID from the order received page
 * @param {Page} page
 * @returns {Promise<string>}
 */
exports.grab_order_id = async function( page ) {
    // grab order ID
    let element = await page.$('li.woocommerce-order-overview__order.order strong');
    let order_id = await page.evaluate(el => el.textContent, element);

    return order_id;
}

/**
 * Gets the order ID from the URL (from admin area order page)
 * @param {Page} page
 * @returns {Promise<string>}
 */
exports.get_order_id = async function( page ) {
    const url = page.url();
    const order_id = url.match( /id=([0-9]+)/ )[1];
    return order_id;
}

exports.open_order_page = async function( page ) {
    let order_id = await exports.grab_order_id( page );

    // log in to order page
    await page.goto( config.wordpress_url+"/wp-login.php" );
    await exports.login( page, config.wordpress_user, config.wordpress_password );
    await page.goto( config.wordpress_url+"/wp-admin/post.php?post="+order_id+"&action=edit" );
    await page.waitForSelector( "#order_status" );
}

exports.open_invoice_pdf = async function( page ) {
    // open order page if we are not there already
    if( ! (await page.$('.laskuhari-tila')) ) {
        await exports.open_order_page( page );
    }

    // open the invoice PDF
    await page.waitForSelector( ".laskuhari-nappi.lataa-lasku" );
    await page.click( ".laskuhari-nappi.lataa-lasku" );
}

exports.reset_settings = async function( page ) {
    await page.evaluate( function() {
        let $ = jQuery;
        $("#woocommerce_laskuhari_enabled").prop( "checked", true );
        $("#woocommerce_laskuhari_gateway_enabled").prop( "checked", true );
        $("#woocommerce_laskuhari_auto_gateway_create_enabled").prop( "checked", false );
        $("#woocommerce_laskuhari_auto_gateway_enabled").prop( "checked", false );
        $("#woocommerce_laskuhari_attach_invoice_to_wc_email").prop( "checked", false );
        $("#woocommerce_laskuhari_email_lasku_kaytossa").prop( "checked", true );
        $("#woocommerce_laskuhari_verkkolasku_kaytossa").prop( "checked", true );
        $("#woocommerce_laskuhari_kirjelasku_kaytossa").prop( "checked", true );
        $("#woocommerce_laskuhari_synkronoi_varastosaldot").prop( "checked", false );
        $("#woocommerce_laskuhari_create_webhooks").prop( "checked", false );
        $("#woocommerce_laskuhari_uid").val("");
        $("#woocommerce_laskuhari_apikey").val("");
        $("#woocommerce_laskuhari_demotila").prop( "checked", true );
        $("#woocommerce_laskuhari_send_method_fallback").val("ei");
        $("#woocommerce_laskuhari_laskuviesti").val("Thank you for your order. Attached is an invoice.");
        $("#woocommerce_laskuhari_laskuttaja").val("Test Sender Ltd");
        $("#woocommerce_laskuhari_title").val("Invoice");
        $("#woocommerce_laskuhari_description").val("Pay by invoice easily");
        $("#woocommerce_laskuhari_instructions").val("We will send you an invoice");
        $("#woocommerce_laskuhari_laskutuslisa").val("0");
        $("#woocommerce_laskuhari_laskutuslisa_alv").val("0");
        $("#woocommerce_laskuhari_enable_for_methods option").attr("selected", false);
        $("#woocommerce_laskuhari_send_invoice_from_payment_methods option").attr("selected", false);
        $("#woocommerce_laskuhari_attach_receipt_to_wc_email").prop( "checked", false );
        $("#woocommerce_laskuhari_paid_stamp").prop( "checked", false );
        $("#woocommerce_laskuhari_receipt_template").prop( "checked", false );
        $("#woocommerce_laskuhari_invoice_email_text_for_other_payment_methods").val("Attached you will find an invoice as a receipt");
        $("#woocommerce_laskuhari_salli_laskutus_erikseen").prop( "checked", false );
        $("#woocommerce_laskuhari_enable_for_virtual").prop( "checked", true );
        $("#woocommerce_laskuhari_max_amount").val("0");
        $("#woocommerce_laskuhari_status_after_gateway").val("processing");
        $("#woocommerce_laskuhari_status_after_paid").val("");
    } );
}

exports.add_coupon_to_order = async function( page, coupon_code ) {
    const set_coupon_code = dialog => {
        dialog.accept( coupon_code );
    }

    page.on('dialog', set_coupon_code);

    // click "Add coupon"
    await page.click( ".button.add-coupon" );
    await exports.sleep( 600 );

    page.off('dialog', set_coupon_code);
}

exports.add_product_to_order = async function( page, product_name, quantity = 1 ) {
    // click "Add line item"
    await page.click( ".button.add-line-item" );
    await exports.sleep( 600 );

    // click "Add product"
    await page.click( ".button.add-order-item" );
    await exports.sleep( 400 );

    // click "Search for a product"
    await page.hover( ".wc-backbone-modal-content .select2-selection--single" );
    await exports.sleep( 200 );
    await page.click( ".wc-backbone-modal-content .select2-selection--single" );
    await exports.sleep( 700 );

    // input search keyword
    await page.click( ".select2-container .select2-search__field[aria-expanded=true]" );
    await page.keyboard.type( product_name );

    // wait for results
    await page.waitForSelector( ".select2-results__option[role=option]:not(.loading-results)" );

    // click on first result
    await page.click( ".select2-results__option[role=option]:not(.loading-results)" );

    // set quantity
    const qty = await page.$$( "input.quantity" );
    await qty[qty.length-2].click();
    await page.keyboard.type( quantity.toString() );

    // click on add product
    await page.click( ".wc-backbone-modal-content .button.button-primary.button-large" );

    // wait for product to appear in list
    await page.waitForSelector( "#order_line_items tr.item" );
    await exports.sleep( 1000 );
}

exports.create_manual_order = async function( page, testid ) {
    // go to new order creation
    await page.goto( config.wordpress_url + "/wp-admin/post-new.php?post_type=shop_order" );

    // click billing address edit button
    await page.waitForSelector( ".order_data_column a.edit_address" );
    await page.click( ".order_data_column a.edit_address" );

    // input customer details
    await page.waitForSelector( "#_billing_first_name" );
    await page.click( "#_billing_first_name" );
    await page.keyboard.type( "Jack " + testid );
    await page.click( "#_billing_last_name" );
    await page.keyboard.type( "Smith" );
    await page.click( "#_billing_address_1" );
    await page.keyboard.type( "Jack's road" );
    await page.click( "#_billing_city" );
    await page.keyboard.type( "Jackcity" );
    await page.click( "#_billing_postcode" );
    await page.keyboard.type( "54321" );
    await page.evaluate(() => document.getElementById("_billing_email").value="");
    await page.click( "#_billing_email" );
    await page.keyboard.type( config.test_email );

    await exports.add_product_to_order( page, "Hoodie" );

    // click "Add line item"
    await page.click( ".button.add-line-item" );
    await exports.sleep( 600 );

    // prepare for the fee amount prompt
    page.off('dialog', exports.accept_dialog);
    page.on('dialog', exports.accept_dialog);

    // click "Add fee"
    await page.click( ".button.add-order-fee" );
    await exports.sleep( 2000 );

    await page.waitForSelector( ".button.button-primary.calculate-action", {
        visible: true
    } );

    // click on "calculate"
    await page.click( ".button.button-primary.calculate-action" );

    // wait for changes to take effect
    await exports.sleep( 4000 );

    // create order
    await page.click( ".button.save_order.button-primary" );

    // wait for page to load
    await page.waitForSelector( "#laskuhari_metabox" );
}

exports.set_field_value = async function( page, selector, text ) {
    let element = await page.waitForSelector( selector, {
        visible: true
    } );
    let type = await element.evaluate( el => el.type );
    if( ["select-one"].includes( type ) ) {
        await element.select( text );
    } else {
        await element.type( text );
    }
}

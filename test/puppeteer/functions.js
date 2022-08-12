const config = require( "./config.js" );

exports.login = async function( page ) {
    await page.waitFor( "#user_login" );
    await page.click( "#user_login" );
    await page.waitFor( 100 );
    await page.keyboard.type( config.wordpress_user );
    await page.click( "#user_pass" );
    await page.waitFor( 100 );
    await page.keyboard.type( config.wordpress_password );
    await page.click( "#wp-submit" );
}

exports.open_settings = async function( page ) {
    await page.goto( config.wordpress_url+"/wp-admin/admin.php?page=wc-settings&tab=checkout&section=laskuhari" );
    await exports.login( page );
    await page.waitFor( "#woocommerce_laskuhari_enabled" );
}

exports.logout = async function( page ) {
    await page.waitFor( 200 );
    await page.hover( "#wp-admin-bar-my-account" );
    await page.waitFor( 500 );
    await page.click( "#wp-admin-bar-logout a" );
}

exports.make_order_before_select_invoice_method = async function( page ) {
    // go to shop page
    await page.goto( config.wordpress_url+"/?post_type=product" );

    // add product to cart
    await page.waitFor( ".product-type-simple.post-15 .add_to_cart_button" );
    await page.click( ".product-type-simple.post-15 .add_to_cart_button" );

    // wait for add to cart action
    await page.waitFor( ".woocommerce-mini-cart__total.total" );

    // click to cart
    await page.click( "a.cart-contents" );

    // wait for checkout button and click it
    await page.waitFor( ".checkout-button" );
    await page.click( ".checkout-button" );

    // wait for checkout form
    await page.waitFor( "#place_order" );

    // insert first name
    await page.click( "#billing_first_name" );
    await page.keyboard.type( "John" );

    // insert last name
    await page.click( "#billing_last_name" );
    await page.keyboard.type( "Doe" );

    // fill address details
    await page.click( "#billing_address_1" );
    await page.keyboard.type( "Testroad 123" );
    await page.click( "#billing_postcode" );
    await page.keyboard.type( "123456" );
    await page.click( "#billing_city" );
    await page.keyboard.type( "Testplace" );
    await page.click( "#billing_phone" );
    await page.keyboard.type( "040 1234 567" );
    await page.click( "#billing_email" );
    await page.keyboard.type( config.test_email );

    // select laskuhari payment method
    await page.waitFor(1000);
    await page.waitFor( "label[for=payment_method_laskuhari]" );
    await page.click( "label[for=payment_method_laskuhari]" );
    await page.waitFor( "#laskuhari-laskutustapa" );
    await page.waitFor(1000);
}

exports.place_order = async function( page ) {
    // send order
    await page.click( "#place_order" );

    // wait for order to complete
    await page.waitFor( ".woocommerce-order-received" );
}

exports.make_order = async function( page ) {
    await exports.make_order_before_select_invoice_method( page );

    // select email invoicing
    await page.evaluate(function() {
        jQuery("#laskuhari-laskutustapa").val("email").change();
    });
    await page.waitFor( 500 );

    // insert reference
    await page.click( "#laskuhari-viitteenne" );
    await page.keyboard.type( "testing reference" );

    await exports.place_order( page );
}

exports.open_order_page = async function( page ) {
    // grab order ID
    let element = await page.$('li.woocommerce-order-overview__order.order strong');
    let order_id = await page.evaluate(el => el.textContent, element);

    // log in to order page
    await page.goto( config.wordpress_url+"/wp-admin/post.php?post="+order_id+"&action=edit" );
    await exports.login( page );
    await page.waitFor( ".laskuhari-tila" );
}

exports.open_invoice_pdf = async function( page ) {
    // open order page if we are not there already
    if( ! (await page.$('.laskuhari-tila')) ) {
        await exports.open_order_page( page );
    }

    // open the invoice PDF
    await page.waitFor( ".laskuhari-nappi.lataa-lasku" );
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
        $("#woocommerce_laskuhari_enable_for_methods option").prop("selected", false);
        $("#woocommerce_laskuhari_send_invoice_from_payment_methods option").prop("selected", false);
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

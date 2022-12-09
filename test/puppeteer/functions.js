const config = require( "./config.js" );

const accept_dialog = dialog => {
    dialog.accept("1.43");
};

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
    await page.waitFor(2000);

    // send order
    await page.click( "#place_order" );

    // wait for order to complete
    await page.waitForSelector( ".woocommerce-order-received", {
        timeout: 45000
    } );
}

exports.make_order = async function( page ) {
    await exports.make_order_before_select_invoice_method( page );

    // select email invoicing
    await page.evaluate(function() {
        jQuery("#laskuhari-laskutustapa").val("email").change();
    });
    await page.waitFor( 1000 );

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

exports.create_manual_order = async function( page ) {
    // go to new order creation
    await page.goto( config.wordpress_url + "/wp-admin/post-new.php?post_type=shop_order" );

    // click billing address edit button
    await page.waitFor( ".order_data_column a.edit_address" );
    await page.click( ".order_data_column a.edit_address" );

    // input customer details
    await page.waitFor( "#_billing_first_name" );
    await page.click( "#_billing_first_name" );
    await page.keyboard.type( "Jack" );
    await page.click( "#_billing_last_name" );
    await page.keyboard.type( "Smith" );
    await page.click( "#_billing_address_1" );
    await page.keyboard.type( "Jack's road" );
    await page.click( "#_billing_city" );
    await page.keyboard.type( "Jackcity" );
    await page.click( "#_billing_postcode" );
    await page.keyboard.type( "54321" );
    await page.click( "#_billing_email" );
    await page.keyboard.type( config.test_email );

    // click "Add line item"
    await page.click( ".button.add-line-item" );
    await page.waitFor( 600 );

    // click "Add product"
    await page.click( ".button.add-order-item" );
    await page.waitFor( 400 );

    // click "Search for a product"
    await page.hover( ".wc-backbone-modal-content .select2-selection--single" );
    await page.waitFor( 200 );
    await page.click( ".wc-backbone-modal-content .select2-selection--single" );
    await page.waitFor( 700 );

    // input search keyword
    await page.click( ".select2-container .select2-search__field[aria-expanded=true]" );
    await page.keyboard.type( "Hoodie" );

    // wait for results
    await page.waitFor( ".select2-results__option[role=option]:not(.loading-results)" );

    // click on first result
    await page.click( ".select2-results__option[role=option]:not(.loading-results)" );

    // click on add product
    await page.click( ".wc-backbone-modal-content .button.button-primary.button-large" );

    // wait for product to appear in list
    await page.waitFor( "#order_line_items tr.item" );
    await page.waitFor( 1000 );

    // click "Add line item"
    await page.click( ".button.add-line-item" );
    await page.waitFor( 600 );

    // prepare for the fee amount prompt
    page.off('dialog', accept_dialog);
    page.on('dialog', accept_dialog);

    // click "Add fee"
    await page.click( ".button.add-order-fee" );
    await page.waitFor( 2000 );

    await page.waitForSelector( ".button.button-primary.calculate-action", {
        visible: true
    } );

    // click on "calculate"
    await page.click( ".button.button-primary.calculate-action" );

    // wait for changes to take effect
    await page.waitFor( 3000 );

    // create order
    await page.click( ".button.save_order.button-primary" );

    // wait for page to load
    await page.waitFor( "#laskuhari_metabox" );
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

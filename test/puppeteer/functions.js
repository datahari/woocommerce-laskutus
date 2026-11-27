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

/**
 * Expect the value of a specific attribute of an element
 */
exports.expect_attribute_value = async function( page, selector, attribute, value ) {
    expect( await page.$eval( selector, (el, attribute) => el.getAttribute( attribute ), attribute ) ).toBe( value );
}

/**
 * Expect the value of a specific selector
 */
exports.expect_field_value = async function( page, selector, value ) {
    expect( await page.$eval( selector, el => el.value ) ).toBe( value );
}

/**
 * Expect that the `textContent` of an element matches a regex
 */
exports.expect_text_regex = async function( page, selector, regex ) {
    const element = await page.$( selector );
    const val = await page.evaluate( el => el.textContent, element );
    expect( val ).toMatch( regex )
}

/**
 * Expect that the `textContent` of an element does NOT match a regex
 */
exports.expect_not_text_regex = async function( page, selector, regex ) {
    const element = await page.$( selector );
    const val = await page.evaluate( el => el.textContent, element );
    expect( val ).not.toMatch( regex )
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
    await page.waitForSelector("#wp-admin-bar-logout a");
    await page.evaluate( function() {
        location.href = jQuery("#wp-admin-bar-logout a").attr("href");
    } );
    await page.waitForNavigation();
}

exports.click_wait_navigation = async function( page, selector ) {
    await Promise.all( [
        page.waitForNavigation(),
        page.click( selector ),
    ] );
}

exports.add_product_to_cart_and_go_to_checkout = async function( page, block_checkout = false ) {
    // go to shop page
    await page.goto( config.wordpress_url+"/?post_type=product" );

    // add product to cart
    await page.waitForSelector( ".product-type-simple.post-15 .add_to_cart_button" );
    await page.click( ".product-type-simple.post-15 .add_to_cart_button" );

    // wait for add to cart action
    await page.waitForSelector( ".woocommerce-mini-cart__total.total" );

    if( block_checkout ) {
        await page.goto( config.wordpress_url + "/index.php/block-checkout" );
        return;
    }

    // click to cart
    await page.click( "a.cart-contents" );

    // wait for checkout button and click it
    await page.waitForSelector( ".checkout-button" );
    await page.click( ".checkout-button" );

    // wait for checkout form
    await page.waitForSelector( "#place_order" );
}

exports.make_order_before_select_invoice_method = async function( page, testid, block_checkout = false ) {
    await exports.add_product_to_cart_and_go_to_checkout( page, block_checkout );
    await exports.fill_out_checkout_form( page, testid, block_checkout );
    await exports.select_laskuhari_payment_method( page, block_checkout );
    await exports.wait_for_loading( page );
}

exports.fill_out_checkout_form = async function( page, testid, block_checkout = false ) {
    await page.type( block_checkout ? "#email" : "#billing_email", config.test_email );
    await exports.sleep( 500 );
    await page.type( block_checkout ? "#shipping-first_name" : "#billing_first_name", "John " + testid );
    await exports.sleep( 500 );
    await page.type( block_checkout ? "#shipping-last_name" : "#billing_last_name", "Doe" );
    await exports.sleep( 500 );
    await page.type( block_checkout ? "#shipping-address_1" : "#billing_address_1", "Testroad 123" );
    await exports.sleep( 500 );
    await page.type( block_checkout ? "#shipping-city" : "#billing_city", "Testplace" );
    await exports.sleep( 500 );

    if( block_checkout ) {
        await page.type( "#shipping-state", "Uusimaa" );
        await exports.sleep( 500 );
    }

    await page.type( block_checkout ? "#shipping-phone" : "#billing_phone", "040 1234 567" );
    await exports.sleep( 500 );
    await page.type( block_checkout ? "#shipping-postcode" : "#billing_postcode", "00100" );
    await exports.sleep( 500 );

    // blur from email field
    await page.click( block_checkout ? "#shipping-fields > div.wc-block-components-checkout-step__heading-container" : "#order_review" );
}

exports.select_laskuhari_payment_method = async function( page, block_checkout = false ) {
    // select laskuhari payment method
    await exports.wait_for_loading( page );

    let selector = "label[for=payment_method_laskuhari]";

    if( block_checkout ) {
        selector = "#radio-control-wc-payment-method-options-laskuhari__label";
    }

    await page.waitForSelector( selector );
    await page.click( selector );
    await page.waitForSelector( "#laskuhari-laskutustapa" );
}

exports.select_paytrail_payment_method = async function( page, block_checkout = false ) {
    if( block_checkout ) {
        // select paytrail payment method
        await exports.wait_for_loading( page );
        await page.waitForSelector( "#radio-control-wc-payment-method-options-paytrail__label" );
        await page.click( "#radio-control-wc-payment-method-options-paytrail__label" );
        return;
    }

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

exports.paytrail_select_payment_method_in_external_window = async function( page ) {
    // select Verkkopankki
    await page.waitForSelector( ".accordion-button" );
    await page.waitForNetworkIdle();
    await page.click( ".accordion-button" );

    // select OP
    await page.waitForSelector( "button[aria-label=OP]" );
    await page.click( "button[aria-label=OP]" );
}

exports.wait_for_loading = async function( page ) {
    await exports.sleep( 500 );
    await page.waitForFunction( function() {
        return !jQuery( ".blockOverlay" ).is( ":visible" ) && !jQuery(":animated").length;
    } );
    await exports.sleep( 500 );
}

exports.sleep = async function( ms ) {
    return new Promise( resolve => setTimeout( resolve, ms ) );
}

exports.place_order = async function( page, block_checkout, wait = true ) {
    await exports.wait_for_loading( page );

    // send order
    await page.click( block_checkout ? ".wc-block-components-button" : "#place_order" );

    if( wait ) {
        // wait for order to complete
        await page.waitForSelector( ".woocommerce-order-received", {
            timeout: 60000
        } );
    }
}

exports.make_order = async function( page, testid, block_checkout ) {
    await exports.make_order_before_select_invoice_method( page, testid, block_checkout );

    // select email invoicing
    await page.select( "#laskuhari-laskutustapa", "email" );
    await exports.wait_for_loading( page );

    // insert reference
    await page.click( "#laskuhari-viitteenne" );
    await page.keyboard.type( "testing reference" );

    await exports.wait_for_loading( page );
    await exports.place_order( page, block_checkout );
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
    return url.match( /(id|post)=([0-9]+)/ )[2];
}

exports.open_order_page = async function( page, order_id = null ) {
    order_id = order_id ?? await exports.grab_order_id( page );

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
        $("#use_wp_cron").val("no");
    } );
}

exports.save_settings = async function( page ) {
    await page.evaluate( function() {
        let $ = jQuery;
        $(".woocommerce-save-button").prop( "disabled", false );
    } );
    await page.click( ".woocommerce-save-button" );
}

exports.add_coupon_to_order = async function( page, coupon_code ) {
    const set_coupon_code = dialog => {
        dialog.accept( coupon_code );
    }

    page.on('dialog', set_coupon_code);

    // click "Add coupon"
    await page.waitForSelector( ".button.add-coupon" );
    await page.click( ".button.add-coupon" );
    await exports.sleep( 600 );

    page.off('dialog', set_coupon_code);
}

exports.add_product_to_order = async function( page, product_name, quantity = 1 ) {
    await exports.wait_for_loading( page );

    // click "Add line item"
    await page.click( ".button.add-line-item" );
    await exports.sleep( 600 );

    // click "Add product"
    await page.click( ".button.add-order-item" );
    await exports.sleep( 400 );

    // wait for product search to open
    await page.waitForSelector( ".select2-container .select2-search__field[aria-expanded=true]" );

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

// Selects an option of a select box whose value starts with text
exports.select_starting_with = async function( page, selector, text ) {
    let element = await page.waitForSelector( selector, {
        visible: true
    } );
    let options = await element.$$('option');
    for( const option of options ) {
        let value = await page.evaluate( el => el.value, option );
        if( value.startsWith( text ) ) {
            element.select( value );
            return;
        }
    }
    throw new Error( "No option found starting with: " + text );
}

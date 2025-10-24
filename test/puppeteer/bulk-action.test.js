const puppeteer = require('puppeteer');
const functions = require('./functions.js');
const config    = require('./config.js');

test("bulk-actions", async () => {
    const browser = await puppeteer.launch({
        headless: config.headless,
        defaultViewport: {
            width: 1452,
            height: 768
        },
        args:[
            '--start-maximized'
        ]
    });

    const page = await browser.newPage();
    await page.setDefaultNavigationTimeout( 60000 );

    page.on("pageerror", function(err) {
            theTempValue = err.toString();
            console.log("Page error: " + theTempValue);
            browser.close();
    });

    // log in to plugin settings page
    await functions.open_settings( page );

    // reset settings
    await functions.reset_settings( page );

    // select settings
    await page.evaluate( function() {
        let $ = jQuery;
        $("#woocommerce_laskuhari_auto_gateway_create_enabled").prop( "checked", true );
        $("#woocommerce_laskuhari_auto_gateway_enabled").prop( "checked", true );
        $("#woocommerce_laskuhari_status_after_gateway").val( "on-hold" );
        $("#woocommerce_laskuhari_status_after_paid").val( "processing" );
        $("#woocommerce_laskuhari_send_method_fallback").val( "ei" );
        $("#woocommerce_laskuhari_laskuviesti").val( "(bulk-actions-dont-send)" );
        $("#woocommerce_laskuhari_instructions").val( "(bulk-actions-dont-send)" );
    } );

    // save settings
    await functions.save_settings( page );

    // wait for settings to be saved
    await page.waitForNavigation();
    await page.waitForSelector( ".updated.inline" );

    /**
     *
     * Create an invoice from a manual order using the bulk action "create" while fallback sending method is "don't send".
     * The invoice should only be created and not sent
     *
     */

    // create manual order
    await functions.create_manual_order( page, "bulk-create-not-send1" );

    // change status of order
    await page.waitForSelector( "#order_status" );
    await page.select( "#order_status", "wc-processing" );

    // save order
    await page.click( ".button.save_order.button-primary" )
    await page.waitForNavigation();

    // go to orders list
    await page.goto( config.wordpress_url + "/wp-admin/edit.php?post_type=shop_order" );

    // select the latest order
    await page.click( '.wp-list-table tbody .check-column [type=checkbox]' );

    // select bulk action "create"
    await functions.select_starting_with( page, "#bulk-action-selector-top", "laskuhari_batch_create" );

    // submit
    await page.click( "#doaction" );

    // get order id from notice
    let notice_element = await page.waitForSelector( "[data-testid='invoice-created']" );
    let order_id = await page.evaluate( element => {
        return element.getAttribute( "data-order-id" );
    }, notice_element );

    await functions.sleep( 2000 );

    // go to order page
    let order_view_selector = "#post-"+order_id+" a.order-view"; // old
    let order_view_selector_alt = "#order-"+order_id+" a.order-view"; // hpos

    if( await page.$( order_view_selector ) !== null ) {
        await page.click( order_view_selector );
    } else {
        await page.click( order_view_selector_alt );
    }

    await page.waitForNavigation();

    // check that invoice was created but not sent
    let element = await page.$('.laskuhari-tila');
    let invoice_status = await page.evaluate(el => el.textContent, element);
    expect( invoice_status ).toBe( "LASKU LUOTU" );

    /**
     *
     * Create (and try to send) an invoice from a manual order using the bulk action "send" while fallback sending method is "don't send".
     * The invoice should only be created and NOT SENT because fallback method is "don't send" and the manual order
     * does not have an invoicing method yet
     *
     */

    // create another manual order
    await functions.create_manual_order( page, "bulk-create-try-send-fallback-no-send" );

    // change status of order
    await page.waitForSelector( "#order_status" );
    await page.select( "#order_status", "wc-processing" );
    await functions.sleep( 500 );

    // save order
    await page.click( ".button.save_order.button-primary" );
    await page.waitForNavigation();

    // go to orders list
    await page.goto( config.wordpress_url + "/wp-admin/edit.php?post_type=shop_order" );

    // select the latest order
    await page.click( '.wp-list-table tbody .check-column [type=checkbox]' );

    // select bulk action "send"
    await functions.select_starting_with( page, "#bulk-action-selector-top", "laskuhari_batch_send" );

    // submit
    await page.click( "#doaction" );

    // wait for order success notice
    await page.waitForSelector( "[data-testid='laskuhari-success']" );

    // click latest order
    await page.waitForSelector( ".wp-list-table tbody a.order-view" );
    await functions.sleep( 500 );
    await page.click( '.wp-list-table tbody a.order-view' );
    await page.waitForNavigation();

    // check that invoice was created but not sent
    element = await page.$('.laskuhari-tila');
    invoice_status = await page.evaluate(el => el.textContent, element);
    expect( invoice_status ).toBe( "LASKU LUOTU" );

    // log in to plugin settings page
    await page.goto( config.wordpress_url+"/wp-admin/admin.php?page=wc-settings&tab=checkout&section=laskuhari" );

    // set send fallback to "email"
    await page.evaluate( function() {
        let $ = jQuery;
        $("#woocommerce_laskuhari_send_method_fallback").val("email");
        $("#woocommerce_laskuhari_laskuviesti").val("(bulk-actions-send)");
        $("#woocommerce_laskuhari_instructions").val("(bulk-actions-send)");
    } );

    // save settings
    await functions.save_settings( page );

    // wait for settings to be saved
    await page.waitForNavigation();
    await page.waitForSelector( ".updated.inline" );

    /**
     *
     * Create and send an invoice from a manual order using the bulk action "send" while fallback sending method is "email".
     * The invoice should be created and sent
     *
     */

    // create yet another manual order
    await functions.create_manual_order( page, "bulk-create-send-email" );

    // change status of order
    await page.waitForSelector( "#order_status" );
    await page.select( "#order_status", "wc-processing" );

    // save order
    await page.click( ".button.save_order.button-primary" );
    await page.waitForNavigation();

    // go to orders list
    await page.goto( config.wordpress_url + "/wp-admin/edit.php?post_type=shop_order" );

    // select the latest order
    await page.click( '.wp-list-table tbody .check-column [type=checkbox]' );

    // select bulk action "send"
    await functions.select_starting_with( page, "#bulk-action-selector-top", "laskuhari_batch_send" );

    // submit
    await page.click( "#doaction" );

    // get order id from notice
    notice_element = await page.waitForSelector( "[data-testid='invoice-sent']" );
    order_id = await page.evaluate( element => {
        return element.getAttribute( "data-order-id" );
    }, notice_element );

    // go to order page
    order_view_selector = "#post-"+order_id+" a.order-view"; // old
    order_view_selector_alt = "#order-"+order_id+" a.order-view"; // hpos

    if( await page.$( order_view_selector ) !== null ) {
        await page.click( order_view_selector );
    } else {
        await page.click( order_view_selector_alt );
    }
    await page.waitForNavigation();

    // check that invoice was sent
    element = await page.$('.laskuhari-tila');
    invoice_status = await page.evaluate(el => el.textContent, element);
    expect( invoice_status ).toBe( "LASKUTETTU" );

    /**
     *
     * Create an invoice from a manual order using the bulk action while fallback sending method is "email".
     * The invoice should only be created and not sent
     *
     */

    // create a final manual order
    await functions.create_manual_order( page, "bulk-create-not-send2" );

    // change status of order
    await page.waitForSelector( "#order_status" );
    await page.select( "#order_status", "wc-processing" );

    // save order
    await page.click( ".button.save_order.button-primary" );
    await page.waitForNavigation();

    // go to orders list
    await page.goto( config.wordpress_url + "/wp-admin/edit.php?post_type=shop_order" );

    // select the latest order
    await page.click( '.wp-list-table tbody .check-column [type=checkbox]' );

    // select bulk action "create"
    await functions.select_starting_with( page, "#bulk-action-selector-top", "laskuhari_batch_create" );

    // submit
    await page.click( "#doaction" );
    await page.waitForNavigation();

    // click latest order
    await page.click( '.wp-list-table tbody a.order-view' );
    await page.waitForNavigation();

    // check that invoice was created but not sent
    element = await page.$('.laskuhari-tila');
    invoice_status = await page.evaluate(el => el.textContent, element);
    expect( invoice_status ).toBe( "LASKU LUOTU" );

    // close browser
    await browser.close();
}, 600000);

const puppeteer = require('puppeteer');
const functions = require('./functions.js');
const config = require('./config.js');

/**
 * Test creating an invoice from an order paid with the Paytrail payment method
 * (creates invoice but doesn't send it)
 */

test("checkout-with-paytrail", async () => {
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
        $("#woocommerce_laskuhari_send_invoice_from_payment_methods option[value='paytrail']").attr( "selected", true );
        $("#woocommerce_laskuhari_auto_gateway_create_enabled").prop( "checked", true );
        $("#woocommerce_laskuhari_auto_gateway_enabled").prop( "checked", false );
        $("#woocommerce_laskuhari_laskuviesti").val(
            $("#woocommerce_laskuhari_laskuviesti").val() +
            " (paytrail-no-attachment-test)"
        );
        $("#woocommerce_laskuhari_instructions").val(
            $("#woocommerce_laskuhari_instructions").val() +
            " (paytrail-no-attachment-test)"
        );
    } );

    // save settings
    await page.click( ".woocommerce-save-button" );

    // wait for settings to be saved
    await page.waitForNavigation();
    await page.waitForSelector( ".updated.inline" );

    // log out
    await functions.logout( page );

    // make an order
    await functions.add_product_to_cart_and_go_to_checkout( page );
    await functions.fill_out_checkout_form( page, "paytrail-lh-no-att" );
    await functions.select_paytrail_payment_method( page );
    await functions.place_order( page );

    // wait for order to be placed
    await page.waitForSelector( ".woocommerce-order-received" );

    // grab order id
    let order_id = await functions.grab_order_id( page );

    // go to orders list
    await page.goto( config.wordpress_url + "/wp-admin/edit.php?post_type=shop_order" );
    await functions.login( page, config.wordpress_user, config.wordpress_password );

    // check that the order was added to the list
    await functions.wait_for_loading( page );
    let element = await page.$('#post-'+order_id);
    expect(!!element).toBeTruthy();

    // close browser
    await browser.close();
}, 600000);

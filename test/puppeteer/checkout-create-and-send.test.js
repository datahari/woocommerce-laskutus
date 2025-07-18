const puppeteer = require('puppeteer');
const functions = require('./functions.js');
const config = require('./config.js');

test("checkout-create-and-send", async () => {
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
        $("#woocommerce_laskuhari_laskuviesti").val(
            $("#woocommerce_laskuhari_laskuviesti").val() +
            " (checkout-create-and-send)"
        );
        $("#woocommerce_laskuhari_instructions").val(
            $("#woocommerce_laskuhari_instructions").val() +
            " (checkout-create-and-send)"
        );
    } );

    // save settings
    await functions.save_settings( page );

    // wait for settings to be saved
    await page.waitForNavigation();
    await page.waitForSelector( ".updated.inline" );

    // log out
    await functions.logout( page );

    // make an order
    await functions.make_order( page, "create-and-send" );

    // open order page
    await functions.open_order_page( page );

    // check that invoice was sent
    let element = await page.$('.laskuhari-tila');
    let invoice_status = await page.evaluate(el => el.textContent, element);
    expect( invoice_status ).toBe( "LASKUTETTU" );

    // check that order status was set to processing
    element = await page.$('#select2-order_status-container');
    let order_status = await page.evaluate(el => el.textContent, element);
    expect( order_status ).toBe( "Käsittelyssä" );

    // wait for a while so we can inspect the result
    await functions.sleep( 5000 );

    // close browser
    await browser.close();
}, 600000);

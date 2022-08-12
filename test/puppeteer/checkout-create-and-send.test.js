const puppeteer = require('puppeteer');
const functions = require('./functions.js');

test("checkout-create-and-send", async () => {
    const browser = await puppeteer.launch({
        headless: false,
        defaultViewport: {
            width: 1452,
            height: 768
        },
        args:[
            '--start-maximized'
        ]
    });

    const page = await browser.newPage();

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
    } );

    // save settings
    await page.click( ".submit .button-primary" );

    // wait for settings to be saved
    await page.waitForNavigation();
    await page.waitFor( ".updated.woocommerce-message" );

    // log out
    await functions.logout( page );

    // make an order
    await functions.make_order( page );

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
    await page.waitFor( 5000 );

    // close browser
    await browser.close();
}, 100000);

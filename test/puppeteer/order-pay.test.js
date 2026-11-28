const puppeteer = require('puppeteer');
const functions = require('./functions.js');
const config    = require('./config.js');

/**
 * This tests that invoicing details are persisted to the order
 * when order is paid via the separate `/order-pay` page
 */

test("order-pay", async () => {
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
    } );

    // save settings
    await functions.save_settings( page );

    // create manual order
    await functions.create_manual_order( page, "order-pay" );

    // check that invoice was not created
    let element = await page.$('.laskuhari-tila');
    let invoice_status = await page.evaluate(el => el.textContent, element);
    expect( invoice_status ).toBe( "EI LASKUTETTU" );

    await functions.sleep( 600 );

    // save order
    await page.click( ".button.save_order.button-primary" );

    // click to enter the order payment page
    await page.waitForSelector( "label[for=order_status] a" );
    await page.click( "label[for=order_status] a" );

    // wait for page to load
    await page.waitForSelector( "#laskuhari-laskutustapa" );

    // select einvoice method
    await page.select( "#laskuhari-laskutustapa", "verkkolasku" );
    await functions.sleep( 1500 );
    await functions.wait_for_loading( page );

    // insert business id
    await page.locator( "#laskuhari-ytunnus" ).fill( "1111111-8" );

    // insert einvoice address
    await page.locator( "#laskuhari-verkkolaskuosoite" ).fill( "003711111118" );

    // select operator
    await page.select( "#laskuhari-valittaja", "E204503" );
    await functions.sleep( 500 );

    // insert reference
    await page.locator( "#laskuhari-viitteenne" ).fill( "persistence test" );

    // pay
    await functions.place_order( page, config.block_checkout );

    // open order page
    await functions.open_order_page( page );

    // wait to load page completely
    await functions.sleep( 500 );

    // click "Send invoice"
    await page.click( ".laskuhari-nappi.laheta-lasku" );
    await functions.sleep( 1000 );

    // check that the business id was saved
    element = await page.$('#laskuhari-ytunnus');
    val = await page.evaluate(el => el.value, element);
    expect( val ).toBe( "1111111-8" );

    // check that the einvoice address was saved
    element = await page.$('#laskuhari-verkkolaskuosoite');
    val = await page.evaluate(el => el.value, element);
    expect( val ).toBe( "003711111118" );

    // check that the operator was saved
    element = await page.$('#laskuhari-valittaja');
    val = await page.evaluate(el => el.value, element);
    expect( val ).toBe( "E204503" );

    // close browser
    await browser.close();
}, 600000);

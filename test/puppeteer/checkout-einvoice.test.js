const puppeteer = require('puppeteer');
const functions = require('./functions.js');

test("checkout-einvoice", async () => {
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

    // add products to cart and fill order form
    await functions.make_order_before_select_invoice_method( page );

    // select einvoice method
    await page.evaluate(function() {
        jQuery("#laskuhari-laskutustapa").val("verkkolasku").change();
    });
    await page.waitFor( 500 );

    // insert business id
    await page.click( "#laskuhari-ytunnus" );
    await page.keyboard.type( "1234567-8" );

    // insert einvoice address
    await page.click( "#laskuhari-verkkolaskuosoite" );
    await page.keyboard.type( "003712345678" );

    // select operator
    await page.evaluate(function() {
        jQuery("#laskuhari-valittaja").val("003723327487").change();
    });
    await page.waitFor( 500 );

    // insert reference
    await page.click( "#laskuhari-viitteenne" );
    await page.keyboard.type( "ref for einvoice" );

    await functions.place_order( page );

    // open order page
    await functions.open_order_page( page );

    // wait to load page completely
    await page.waitFor( 500 );

    // click "Send invoice"
    await page.click( ".laskuhari-nappi.laheta-lasku" );
    await page.waitFor( 1000 );

    // check that the business id was saved
    let element = await page.$('#laskuhari-ytunnus');
    let val = await page.evaluate(el => el.value, element);
    expect( val ).toBe( "1234567-8" );

    // check that the einvoice address was saved
    element = await page.$('#laskuhari-verkkolaskuosoite');
    val = await page.evaluate(el => el.value, element);
    expect( val ).toBe( "003712345678" );

    // check that the operator was saved
    element = await page.$('#laskuhari-valittaja');
    val = await page.evaluate(el => el.value, element);
    expect( val ).toBe( "003723327487" );

    // check that the invoice was tried to send via einvoice
    // note: invoice won't be sent with demo credentials
    // so we just check for the correct error notice
    element = await page.$('#woocommerce-order-notes .note_content');
    val = await page.evaluate(el => el.textContent, element);
    expect( val ).toMatch( /.*Virhe laskun lähetyksessä.*KEY_ERROR.*/ );

    // wait for a while so we can inspect the result
    await page.waitFor( 5000 );

    // close browser
    await browser.close();
}, 100000);

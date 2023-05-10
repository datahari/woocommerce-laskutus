const puppeteer = require('puppeteer');
const functions = require('./functions.js');
const config = require('./config.js');

test("checkout-einvoice", async () => {
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
            " (checkout-einvoice)"
        );
        $("#woocommerce_laskuhari_instructions").val(
            $("#woocommerce_laskuhari_instructions").val() +
            " (checkout-einvoice)"
        );
    } );

    // save settings
    await page.click( ".woocommerce-save-button" );

    // wait for settings to be saved
    await page.waitForNavigation();
    await page.waitForSelector( ".updated.inline" );

    // log out
    await functions.logout( page );

    // add products to cart and fill order form
    await functions.make_order_before_select_invoice_method( page, "checkout-send-einvoice" );

    // select einvoice method
    await page.evaluate(function() {
        jQuery("#laskuhari-laskutustapa").val("verkkolasku").change();
    });
    await functions.sleep( 1500 );

    // insert business id
    await functions.wait_for_loading( page );
    await page.click( "#laskuhari-ytunnus" );
    await page.keyboard.type( "1234567-8" );

    // insert einvoice address
    await page.click( "#laskuhari-verkkolaskuosoite" );
    await page.keyboard.type( "003712345678" );

    // select operator
    await page.evaluate(function() {
        jQuery("#laskuhari-valittaja").val("003723327487").change();
    });
    await functions.sleep( 500 );

    // insert reference
    await page.click( "#laskuhari-viitteenne" );
    await page.keyboard.type( "ref for einvoice" );

    await functions.place_order( page );

    // wait 30 seconds for cron queue to be processed
    await functions.sleep( 30000 );

    // open order page
    await functions.open_order_page( page );

    // wait to load page completely
    await functions.sleep( 500 );

    // click "Send invoice"
    await page.click( ".laskuhari-nappi.laheta-lasku" );
    await functions.sleep( 1000 );

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
    element = await page.$('#woocommerce-order-notes');
    val = await page.evaluate(el => el.textContent, element);
    expect( val ).toMatch( /.*Virhe laskun lähetyksessä.*KEY_ERROR.*/ );

    // wait for a while so we can inspect the result
    await functions.sleep( 5000 );

    // close browser
    await browser.close();
}, 600000);

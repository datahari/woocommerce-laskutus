const puppeteer = require('puppeteer');
const functions = require('./functions.js');
const config = require('./config.js');

test("checkout-with-invoicing-fee", async () => {
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
        $("#woocommerce_laskuhari_auto_gateway_enabled").prop( "checked", false );
        $("#woocommerce_laskuhari_laskutuslisa").val("1,65");
        $("#woocommerce_laskuhari_laskutuslisa_alv").val("24");
        $("#woocommerce_laskuhari_laskuviesti").val(
            $("#woocommerce_laskuhari_laskuviesti").val() +
            " (checkout-with-invoicing-fee)"
        );
        $("#woocommerce_laskuhari_instructions").val(
            $("#woocommerce_laskuhari_instructions").val() +
            " (checkout-with-invoicing-fee)"
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
    await functions.make_order( page, "with-invoicing-fee" );

    // wait 30 seconds for cron queue to be processed
    await functions.sleep( 30000 );

    // open order page
    await functions.open_order_page( page );

    // get order id from url
    const order_id = await functions.get_order_id( page );

    // check that invoicing fee was added
    let element = await page.$('#order_fee_line_items .name .view');
    let invoice_status = await page.evaluate(el => el.textContent, element);
    expect( invoice_status ).toMatch( /\s+Laskutuslisä\s+/ );

    // open invoice pdf
    await functions.open_invoice_pdf( page );

    // wait for a while so we can inspect the result
    await functions.sleep( 8000 );

    // check amounts
    await functions.check_invoice_amounts( page, order_id, 19.65, 4.72, 24.37 );

    // close browser
    await browser.close();
}, 600000);

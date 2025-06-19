const puppeteer = require('puppeteer');
const functions = require('./functions.js');
const config    = require('./config.js');

/**
 * This test checks that the plugin supports
 * the new 25.5 % VAT percentage.
 */

test("vat-percentages-25.5", async () => {
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

    // save settings
    await functions.save_settings( page );

    // go to new order creation
    await page.goto( config.wordpress_url + "/wp-admin/post-new.php?post_type=shop_order" );

    // change status of order
    await page.waitForSelector( "#order_status" );
    await page.select( "#order_status", "wc-processing" );

    // click billing address edit button
    await page.waitForSelector( ".order_data_column a.edit_address" );
    await page.click( ".order_data_column a.edit_address" );

    // input customer details
    await page.waitForSelector( "#_billing_first_name" );
    await page.click( "#_billing_first_name" );
    await page.keyboard.type( "Jack VAT 25.5" );
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

    // Add testing products
    await functions.add_product_to_order( page, "VAT 25.5 test 1" );

    // prepare for confirmation dialog
    page.off('dialog', functions.accept_dialog);
    page.on('dialog', functions.accept_dialog);

    // click on "calculate"
    await page.click( ".button.button-primary.calculate-action" );

    // wait for changes to take effect
    await functions.sleep( 3000 );

    // create order
    await page.click( ".button.save_order.button-primary" );
    await functions.sleep( 3000 );

    // wait for page to load
    await page.waitForSelector( "#laskuhari_metabox" );

    // check that invoice was not created
    let element = await page.$('.laskuhari-tila');
    let invoice_status = await page.evaluate(el => el.textContent, element);
    expect( invoice_status ).toBe( "EI LASKUTETTU" );

    await functions.sleep( 600 );

    // wait for "create invoice" button to load and click it
    await page.waitForSelector( ".laskuhari-nappi.uusi-lasku" );
    await functions.sleep( 2000 );
    await page.click( ".laskuhari-nappi.uusi-lasku" );
    await functions.sleep( 1000 );

    // click "create invoice"
    await page.click( "#laskuhari-create-only" );

    // wait for page to load
    await page.waitForSelector( ".laskuhari-payment-status" );

    // get order id from url
    const order_id = await functions.get_order_id( page );

    // open invoice pdf
    await functions.open_invoice_pdf( page );

    // wait for a while so we can assess the results
    await functions.sleep( 10000 );

    // check amounts
    await functions.check_invoice_amounts( page, order_id, 10, 2.55, 12.55 );

    // close browser
    await browser.close();
}, 600000);

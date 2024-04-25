const puppeteer = require('puppeteer');
const functions = require('./functions.js');
const config    = require('./config.js');

/**
 * This test checks a special case where rounding issues
 * could cause the invoice to be created with incorrect
 * totals when using a discounted price.
 *
 * Expected totals:
 * 283,81 excl. VAT
 *  68,11 VAT
 * 351,92 incl. VAT
 */

test("rounding-issues", async () => {
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
    await page.click( ".woocommerce-save-button" );

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
    await page.keyboard.type( "Jack Rounding" );
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
    await functions.add_product_to_order( page, "Rounding test 1" );
    await functions.add_product_to_order( page, "Rounding test 2" );
    await functions.add_product_to_order( page, "Rounding test 3" );

    // Go to edit mode for all products
    let product_edit_buttons = await page.$$( ".edit-order-item" );
    await product_edit_buttons[0].click();
    await product_edit_buttons[1].click();
    await product_edit_buttons[2].click();
    await functions.sleep( 100 );

    // Set prices for products
    await page.waitForSelector( ".line_total" );
    let totals = await page.$$( ".line_total" );
    await totals[0].evaluate(e => { e.value = "178,65"; });
    await totals[1].evaluate(e => { e.value = "72,15"; });
    await totals[2].evaluate(e => { e.value = "33"; });

    // Save changes
    await page.click( ".save-action" );
    await functions.sleep( 3000 );

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
    await functions.sleep( 6000 );

    // check amounts
    await functions.check_invoice_amounts( page, order_id, 283.81, 68.11, 351.92 );

    // close browser
    await browser.close();
}, 600000);

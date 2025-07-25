const puppeteer = require('puppeteer');
const functions = require('./functions.js');
const config    = require('./config.js');

/**
 * This test checks that the invoice row amounts
 * match the WooCommerce row amounts
 */

test("row-amounts", async () => {
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
    await page.keyboard.type( "Jack RowAmounts" );
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
    await functions.add_product_to_order( page, "Row amount test 1", 2 );
    await functions.add_product_to_order( page, "Row amount test 2" );
    await functions.add_product_to_order( page, "Row amount test 3", 100 );

    // Go to edit mode
    let product_edit_buttons = await page.$$( ".edit-order-item" );
    await product_edit_buttons[0].click();
    await product_edit_buttons[1].click();
    await product_edit_buttons[2].click();
    await functions.sleep( 100 );

    // Set price for product 2
    await page.waitForSelector( ".line_total" );
    let totals = await page.$$( ".line_total" );
    await totals[1].evaluate(e => { e.value = "90"; });

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
    await functions.check_invoice_row_amounts( page, order_id, [
        {
            veroton: 15.32,
            verollinen: 19.00,
            alv: 24,
            ale: 0,
            maara: 2,
            yhtveroton: 30.64,
            yhtverollinen: 37.99,
        },
        {
            veroton: 100.00,
            verollinen: 124.00,
            alv: 24,
            ale: 10,
            maara: 1,
            yhtveroton: 90.00,
            yhtverollinen: 111.6,
        },
        {
            veroton: 0.15,
            verollinen: 0.19,
            alv: 24,
            ale: 0,
            maara: 100,
            yhtveroton: 15.0,
            yhtverollinen: 18.6,
        }
    ] );

    // close browser
    await browser.close();
}, 600000);

const puppeteer = require('puppeteer');
const functions = require('./functions.js');
const config    = require('./config.js');

test("manual-order", async () => {
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

    // save settings
    await page.click( ".submit .button-primary" );

    // go to new order creation
    await page.goto( config.wordpress_url + "/wp-admin/post-new.php?post_type=shop_order" );

    // click billing address edit button
    await page.waitFor( ".order_data_column a.edit_address" );
    await page.click( ".order_data_column a.edit_address" );

    // input customer details
    await page.waitFor( "#_billing_first_name" );
    await page.click( "#_billing_first_name" );
    await page.keyboard.type( "Jack" );
    await page.click( "#_billing_last_name" );
    await page.keyboard.type( "Smith" );
    await page.click( "#_billing_address_1" );
    await page.keyboard.type( "Jack's road" );
    await page.click( "#_billing_city" );
    await page.keyboard.type( "Jackcity" );
    await page.click( "#_billing_postcode" );
    await page.keyboard.type( "54321" );

    // click "Add line item"
    await page.click( ".button.add-line-item" );
    await page.waitFor( 600 );

    // click "Add product"
    await page.click( ".button.add-order-item" );
    await page.waitFor( 400 );

    // click "Search for a product"
    await page.hover( ".wc-backbone-modal-content .select2-selection--single" );
    await page.waitFor( 200 );
    await page.click( ".wc-backbone-modal-content .select2-selection--single" );
    await page.waitFor( 700 );

    // input search keyword
    await page.click( ".select2-container .select2-search__field[aria-expanded=true]" );
    await page.keyboard.type( "Hoodie" );

    // wait for results
    await page.waitFor( ".select2-results__option[role=option]:not(.loading-results)" );

    // click on first result
    await page.click( ".select2-results__option[role=option]:not(.loading-results)" );

    // click on add product
    await page.click( ".wc-backbone-modal-content .button.button-primary.button-large" );

    // wait for product to appear in list
    await page.waitFor( "#order_line_items tr.item" );
    await page.waitFor( 1000 );

    // click "Add line item"
    await page.click( ".button.add-line-item" );
    await page.waitFor( 600 );

    // prepare for the fee amount prompt
    page.on('dialog', dialog => {
        dialog.accept("1.43");
    });

    // click "Add fee"
    await page.click( ".button.add-order-fee" );
    await page.waitFor( 2000 );

    // click on "calculate"
    await page.click( ".button.button-primary.calculate-action" );

    // wait for changes to take effect
    await page.waitFor( 3000 );

    // create order
    await page.click( ".button.save_order.button-primary" );

    // wait for page to load
    await page.waitFor( "#laskuhari_metabox" );

    // check that invoice was not created
    let element = await page.$('.laskuhari-tila');
    let invoice_status = await page.evaluate(el => el.textContent, element);
    expect( invoice_status ).toBe( "EI LASKUTETTU" );

    await page.waitFor( 600 );

    // change status of order
    await page.click( ".select2-selection__rendered[title*=Odottaa]" );
    await page.waitFor( 200 );
    await page.click( ".select2-results__option[id*=processing]" );

    // save order
    await page.click( ".button.save_order.button-primary" );

    // wait for "create invoice" button to load and click it
    await page.waitFor( ".laskuhari-nappi.uusi-lasku" );
    await page.click( ".laskuhari-nappi.uusi-lasku" );
    await page.waitFor( 600 );

    // input a reference
    await page.click( "#laskuhari-viitteenne" );
    await page.keyboard.type( "manual test reference" );

    // click "create invoice"
    await page.click( "#laskuhari-create-only" );

    // wait for page to load
    await page.waitFor( ".laskuhari-payment-status" );

    // check that an invoice was created
    element = await page.$('.laskuhari-tila');
    invoice_status = await page.evaluate(el => el.textContent, element);
    expect( invoice_status ).toBe( "LASKU LUOTU" );

    // check that invoice status is unpaid
    element = await page.$('.laskuhari-not-paid');
    invoice_status = await page.evaluate(el => el.textContent, element);
    expect( invoice_status ).toMatch( /.*Avoin.*/ );

    // click "send invoice"
    await page.click( ".laskuhari-nappi.laheta-lasku" );
    await page.waitFor( 600 );

    // select email invoice method
    await page.evaluate( function() {
        let $ = jQuery;
        $("#laskuhari-laskutustapa").val("email").change();
    } );
    await page.waitFor( 200 );

    // input email address
    await page.click( "#laskuhari-email" );
    await page.keyboard.type( config.test_email );

    // click "send invoice"
    await page.click( ".laskuhari-send-invoice-button" );
    await page.waitForNavigation();

    // check that a comment was left about invoice sending
    element = await page.$('#woocommerce-order-notes .note_content');
    val = await page.evaluate(el => el.textContent, element);
    expect( val ).toMatch( /.*Lasku lähetetty sähköpostitse.*/ );

    // open invoice pdf
    await functions.open_invoice_pdf( page );

    // wait for a while so we can assess the results
    await page.waitFor( 6000 );

    // close browser
    await browser.close();
}, 100000);

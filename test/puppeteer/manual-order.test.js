const puppeteer = require('puppeteer');
const functions = require('./functions.js');
const config    = require('./config.js');

/**
 * This test creates a manual order from the WooCommerce admin panel.
 * The order is first created in "Awaiting payment" status.
 * The order is then changed to "Processing" status.
 * An invoice is then created but NOT sent.
 * The invoice is then sent.
 * A new invoice is then created and sent at the same time.
 */

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
    await page.click( ".woocommerce-save-button" );

    // create manual order
    await functions.create_manual_order( page );

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

    // save invoice number
    element = await page.$('.laskuhari-laskunumero');
    let old_invoice_number = await page.evaluate(el => el.textContent, element);

    // click "create a new invoice"
    await page.click( ".laskuhari-nappi.uusi-lasku" );
    await page.waitFor( 600 );

    // select "send"
    await page.click( "#laskuhari-send-check" );
    await page.waitFor( 600 );

    // click "create and send"
    await page.click( "#laskuhari-create-and-send" );

    // wait for page to load
    await page.waitForNavigation();
    await page.waitFor( ".laskuhari-payment-status" );

    // check that an invoice was sent
    element = await page.$('.laskuhari-tila');
    invoice_status = await page.evaluate(el => el.textContent, element);
    expect( invoice_status ).toBe( "LASKUTETTU" );

    // check that invoice status is unpaid
    element = await page.$('.laskuhari-not-paid');
    invoice_status = await page.evaluate(el => el.textContent, element);
    expect( invoice_status ).toMatch( /.*Avoin.*/ );

    // check that the invoice number was changed
    element = await page.$('.laskuhari-laskunumero');
    let new_invoice_number = await page.evaluate(el => el.textContent, element);
    expect( new_invoice_number == old_invoice_number ).toBe( false );

    // open invoice pdf
    await functions.open_invoice_pdf( page );

    // wait for a while so we can assess the results
    await page.waitFor( 6000 );

    // close browser
    await browser.close();
}, 100000);

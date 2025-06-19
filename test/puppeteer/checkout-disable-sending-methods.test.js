const puppeteer = require('puppeteer');
const functions = require('./functions.js');
const config    = require('./config.js');

test("checkout-disable-sending-methods", async () => {
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

    // enable only email sending
    await page.evaluate( function() {
        let $ = jQuery;
        $("#woocommerce_laskuhari_email_lasku_kaytossa").prop( "checked", true );
        $("#woocommerce_laskuhari_verkkolasku_kaytossa").prop( "checked", false );
        $("#woocommerce_laskuhari_kirjelasku_kaytossa").prop( "checked", false );
        $("#woocommerce_laskuhari_laskuviesti").val(
            $("#woocommerce_laskuhari_laskuviesti").val() +
            " (checkout-disable-sending-methods)"
        );
        $("#woocommerce_laskuhari_instructions").val(
            $("#woocommerce_laskuhari_instructions").val() +
            " (checkout-disable-sending-methods)"
        );
    } );

    // save settings
    await functions.save_settings( page );

    // wait for settings to be saved
    await page.waitForNavigation();
    await page.waitForSelector( ".updated.inline" );

    // log out
    await functions.logout( page );

    // add product to cart and go to order page
    await functions.make_order_before_select_invoice_method( page, "test-sending-method-list-email" );

    // check that there is only email method available
    await page.waitForSelector( "#laskuhari-laskutustapa" );
    expect(!!(await page.$('#laskuhari-laskutustapa option[value=email]'))).toBe( true );
    expect(!!(await page.$('#laskuhari-laskutustapa option[value=verkkolasku]'))).toBe( false );
    expect(!!(await page.$('#laskuhari-laskutustapa option[value=kirje]'))).toBe( false );

    // log in to plugin settings page
    await functions.open_settings( page );

    // enable only einvoice sending
    await page.evaluate( function() {
        let $ = jQuery;
        $("#woocommerce_laskuhari_email_lasku_kaytossa").prop( "checked", false );
        $("#woocommerce_laskuhari_verkkolasku_kaytossa").prop( "checked", true );
        $("#woocommerce_laskuhari_kirjelasku_kaytossa").prop( "checked", false );
    } );

    // save settings
    await functions.save_settings( page );

    // wait for settings to be saved
    await page.waitForNavigation();
    await page.waitForSelector( ".updated.inline" );

    // log out
    await functions.logout( page );

    // add product to cart and go to order page
    await functions.make_order_before_select_invoice_method( page, "test-sending-method-list-einv" );

    // check that there is only einvoice method available
    await page.waitForSelector( "#laskuhari-laskutustapa" );
    expect(!!(await page.$('#laskuhari-laskutustapa option[value=email]'))).toBe( false );
    expect(!!(await page.$('#laskuhari-laskutustapa option[value=verkkolasku]'))).toBe( true );
    expect(!!(await page.$('#laskuhari-laskutustapa option[value=kirje]'))).toBe( false );

    // log in to plugin settings page
    await functions.open_settings( page );

    // enable only letter sending
    await page.evaluate( function() {
        let $ = jQuery;
        $("#woocommerce_laskuhari_email_lasku_kaytossa").prop( "checked", false );
        $("#woocommerce_laskuhari_verkkolasku_kaytossa").prop( "checked", false );
        $("#woocommerce_laskuhari_kirjelasku_kaytossa").prop( "checked", true );
    } );

    // save settings
    await functions.save_settings( page );

    // wait for settings to be saved
    await page.waitForNavigation();
    await page.waitForSelector( ".updated.inline" );

    // log out
    await functions.logout( page );

    // add product to cart and go to order page
    await functions.make_order_before_select_invoice_method( page, "test-sending-method-list-letter" );

    // check that there is only letter method available
    await page.waitForSelector( "#laskuhari-laskutustapa" );
    expect(!!(await page.$('#laskuhari-laskutustapa option[value=email]'))).toBe( false );
    expect(!!(await page.$('#laskuhari-laskutustapa option[value=verkkolasku]'))).toBe( false );
    expect(!!(await page.$('#laskuhari-laskutustapa option[value=kirje]'))).toBe( true );

    // close browser
    await browser.close();
}, 600000);

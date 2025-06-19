const puppeteer = require('puppeteer');
const functions = require('./functions.js');
const config    = require('./config.js');

test("checkout-only-for-billing-customers", async () => {
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

    // click checkbox so it scrolls into view
    await page.click( "#woocommerce_laskuhari_salli_laskutus_erikseen" );

    // enable user based invoicing
    await page.evaluate( function() {
        let $ = jQuery;
        $("#woocommerce_laskuhari_salli_laskutus_erikseen").prop( "checked", true );
    } );
    await functions.sleep( 2000 );

    // save settings
    await functions.save_settings( page );

    // wait for settings to be saved
    await page.waitForNavigation();
    await page.waitForSelector( ".updated.inline" );

    // log out
    await functions.logout( page );

    // add product to cart and go to order page
    await functions.add_product_to_cart_and_go_to_checkout( page );

    // scroll invoicing method selection into view
    await page.waitForSelector( ".order-total" );
    await page.click( ".order-total" );

    // check that the invoicing method is not available
    await page.waitForSelector( "#place_order" );
    expect(!!(await page.$('#payment_method_laskuhari'))).toBe( false );
    await functions.sleep( 2000 );

    // log in to plugin settings page
    await functions.open_settings( page );

    // click checkbox so it scrolls into view
    await page.click( "#woocommerce_laskuhari_salli_laskutus_erikseen" );

    // disable user based invoicing
    await page.evaluate( function() {
        let $ = jQuery;
        $("#woocommerce_laskuhari_salli_laskutus_erikseen").prop( "checked", false );
    } );
    await functions.sleep( 2000 );

    // save settings
    await functions.save_settings( page );

    // wait for settings to be saved
    await page.waitForNavigation();
    await page.waitForSelector( ".updated.inline" );

    // log out
    await functions.logout( page );

    // add product to cart and go to order page
    await functions.add_product_to_cart_and_go_to_checkout( page );

    // scroll invoicing method selection into view
    await page.waitForSelector( ".order-total" );
    await page.click( ".order-total" );

    // check that the invoicing method is available
    await page.waitForSelector( "#place_order" );
    expect(!!(await page.$('#payment_method_laskuhari'))).toBe( true );
    await functions.sleep( 2000 );

    // log in to plugin settings page
    await functions.open_settings( page );

    // click checkbox so it scrolls into view
    await page.click( "#woocommerce_laskuhari_salli_laskutus_erikseen" );

    // enable user based invoicing
    await page.evaluate( function() {
        let $ = jQuery;
        $("#woocommerce_laskuhari_salli_laskutus_erikseen").prop( "checked", true );
    } );
    await functions.sleep( 2000 );

    // save settings
    await functions.save_settings( page );

    // wait for settings to be saved
    await page.waitForNavigation();
    await page.waitForSelector( ".updated.inline" );

    // go to testing customer's edit page
    await page.goto( config.wordpress_url + "/wp-admin/user-edit.php?user_id=" + config.test_customer_id );

    // click checkbox so it scrolls into view
    await page.click( "#laskuhari_laskutusasiakas" );

    // allow invoicing for testing customer
    await page.evaluate( function() {
        let $ = jQuery;
        $("#laskuhari_laskutusasiakas").prop( "checked", true );
    } );
    await functions.sleep( 2000 );
    await page.waitForSelector( "#submit.button.button-primary" );
    await page.click( "#submit.button.button-primary" );

    // wait for settings to be saved
    await page.waitForNavigation();
    await page.waitForSelector( ".updated.notice" );

    // log out
    await functions.logout( page );

    // log in to testing customer
    await page.goto( config.wordpress_url + "/wp-login.php" );
    await functions.login( page, config.test_customer_user, config.test_customer_password );

    // add product to cart and go to order page
    await functions.add_product_to_cart_and_go_to_checkout( page );

    // scroll invoicing method selection into view
    await page.waitForSelector( ".order-total" );
    await page.click( ".order-total" );

    // check that the invoicing method is available
    await page.waitForSelector( "#place_order" );
    expect(!!(await page.$('#payment_method_laskuhari'))).toBe( true );
    await functions.sleep( 2000 );

    // log in to admin
    await page.goto( config.wordpress_url + "/wp-login.php" );
    await functions.login( page, config.wordpress_user, config.wordpress_password );

    // go to testing customer's edit page
    await page.goto( config.wordpress_url + "/wp-admin/user-edit.php?user_id=" + config.test_customer_id );
    await page.waitForSelector( "#laskuhari_laskutusasiakas" );

    // click checkbox so it scrolls into view
    await page.click( "#laskuhari_laskutusasiakas" );

    // disallow invoicing for testing customer
    await page.evaluate( function() {
        let $ = jQuery;
        $("#laskuhari_laskutusasiakas").prop( "checked", false );
    } );
    await functions.sleep( 2000 );
    await page.waitForSelector( "#submit.button.button-primary" );
    await page.click( "#submit.button.button-primary" );

    // wait for settings to be saved
    await page.waitForNavigation();
    await page.waitForSelector( ".updated.notice" );

    // log out
    await functions.logout( page );

    // log in to testing customer
    await page.goto( config.wordpress_url + "/wp-login.php" );
    await functions.login( page, config.test_customer_user, config.test_customer_password );

    // add product to cart and go to order page
    await functions.add_product_to_cart_and_go_to_checkout( page );

    // scroll invoicing method selection into view
    await page.waitForSelector( ".order-total" );
    await page.click( ".order-total" );

    // check that the invoicing method is not available
    await page.waitForSelector( "#place_order" );
    expect(!!(await page.$('#payment_method_laskuhari'))).toBe( false );
    await functions.sleep( 2000 );

    // close browser
    await browser.close();
}, 600000);

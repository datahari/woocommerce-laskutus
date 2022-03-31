<?php
require_once( __DIR__ . "/wordpress-bootstrap.php" );
require_once( __DIR__ . "/../class-laskuhari-wc-plugin.php" );

use PHPUnit\Framework\TestCase;

final class Laskuhari_WC_Plugin_Test extends TestCase
{
    /**
     * Reset the instance on every test
     *
     * @return void
     */
    function tearDown(): void {
        Laskuhari_WC_Plugin::$instance = null;
    }

    /**
     * Test it can be instantiated
     *
     * @return void
     */
	function test_instance_can_be_instantiated() {
		$plugin = Laskuhari_WC_Plugin::instance();

		$this->assertEquals( Laskuhari_WC_Plugin::class, get_class( $plugin ) );
	}

    /**
     * Test only one static instance is created
     *
     * @return void
     */
	function test_instance_can_be_instantiated_only_once() {
		$plugin1 = Laskuhari_WC_Plugin::instance();
		$plugin2 = Laskuhari_WC_Plugin::instance();

		$this->assertEquals( true, $plugin2 === $plugin1 );
	}

    /**
     * Test it adds the payment gateway to the list of WooCommerce payment gateways
     *
     * @return void
     */
    function test_it_adds_payment_gateway() {
        $plugin = Laskuhari_WC_Plugin::instance();

        // add some dummy gateways
        $payment_gateways = ["Test", "Test2"];

        // apply filters to gateway array
        $payment_gateways = apply_filters( "woocommerce_payment_gateways", $payment_gateways );

        // test it adds the new gateway
        $this->assertEquals( true, in_array( "WC_Gateway_Laskuhari", $payment_gateways ) );
        
        // test it doesn't remove existing gateways
        $this->assertEquals( true, in_array( "Test", $payment_gateways ) );
        $this->assertEquals( true, in_array( "Test2", $payment_gateways ) );
    }

    /**
     * Test it can determine the plugin file path
     * Note: This test is unreliable since the tests are not run inside a wordpress installation
     *
     * @return void
     */
    function test_it_can_determine_plugin_file_path() {
        $plugin = Laskuhari_WC_Plugin::instance();

        $expected = realpath( __DIR__ . "/../woocommerce-laskuhari-payment-gateway.php" );

        $this->assertEquals( $expected, $plugin->get_plugin_file() );
    }

    /**
     * Test the plugin can add plugin links
     * Note: This test is unreliable since the tests are not run inside a wordpress installation
     *
     * @return void
     */
    function test_it_adds_plugin_links() {
        $plugin = Laskuhari_WC_Plugin::instance();

        // we're expecing to see this link
        $plugin_link_html = '<a href="https://oma.laskuhari.fi/" target="_blank">Kirjaudu Laskuhariin</a>';

        // we test a weird path because the tests are not run from inside a wordpress installation
        $path = substr( realpath( __DIR__ . "/../woocommerce-laskuhari-payment-gateway.php" ), 1 );

        // test it adds plugin link when the plugin file matches
        $plugin_links = [];
        $plugin_links = apply_filters( "plugin_row_meta", $plugin_links, $path );

        $this->assertEquals( true, in_array( $plugin_link_html, $plugin_links ) );

        // test it doesn't add plugin link when the plugin file doesn't match
        $plugin_links = [];
        $plugin_links = apply_filters( "plugin_row_meta", $plugin_links, "some-random-plugin.php" );

        $this->assertEquals( false, in_array( $plugin_link_html, $plugin_links ) );
    }

    /**
     * Test it can set a setting
     *
     * @return void
     */
    function test_it_can_set_and_get_a_setting() {
        $plugin = Laskuhari_WC_Plugin::instance();

        $this->assertEquals( true, null === $plugin->get_setting( "sync_products" ) );

        $plugin->set_setting( "sync_products", true );
 
        $this->assertEquals( true, $plugin->get_setting( "sync_products" ) );
    }

    /**
     * Test it can parse numbers written in a human readable format
     * into a floating point number
     *
     * @return void
     */
    function test_it_can_parse_a_number_into_a_decimal() {
        $plugin = Laskuhari_WC_Plugin::instance();

        $this->assertEquals( 5.5, $plugin->parse_decimal( "5,50" ) );
        $this->assertEquals( 1234.32, $plugin->parse_decimal( "1 234,32" ) );
        $this->assertEquals( 1345.643232, $plugin->parse_decimal( "1345,643232" ) );
        $this->assertEquals( 142.323, $plugin->parse_decimal( 142.323 ) );
        $this->assertEquals( 142.323, $plugin->parse_decimal( "142.323" ) );
    }

    /**
     * Test it converts invoicing fee typed in a human readable format
     * into a floating point number
     *
     * @return void
     */
    function test_it_parses_invoicing_fee_settings_into_a_decimal() {
        $plugin = Laskuhari_WC_Plugin::instance();

        $this->assertEquals( 0, $plugin->get_setting( "laskutuslisa" ) );
        $this->assertEquals( 0, $plugin->get_setting( "laskutuslisa_alv" ) );

        $plugin->set_setting( "laskutuslisa", "1,50" );
        $plugin->set_setting( "laskutuslisa_alv", "24,00" );
        $plugin->set_setting( "some_random_setting", "2,50" );
 
        $this->assertEquals( 1.5, $plugin->get_setting( "laskutuslisa" ) );
        $this->assertEquals( 24, $plugin->get_setting( "laskutuslisa_alv" ) );
        $this->assertEquals( "2,50", $plugin->get_setting( "some_random_setting" ) );
    }

    /**
     * Test it converts specific settings into true/false from "yes"/"no"
     *
     * @return void
     */
    function test_it_parses_yesno_settings() {
        $plugin = Laskuhari_WC_Plugin::instance();

        $this->assertEquals( true, false === $plugin->get_setting( "synkronoi_varastosaldot" ) );
        $this->assertEquals( true, null === $plugin->get_setting( "some_random_setting" ) );

        $plugin->set_setting( "synkronoi_varastosaldot", "yes" );
        $plugin->set_setting( "some_random_setting", "yes" );
 
        $this->assertEquals( true, $plugin->get_setting( "synkronoi_varastosaldot" ) );
        $this->assertEquals( "yes", $plugin->get_setting( "some_random_setting" ) );

        $plugin->set_setting( "synkronoi_varastosaldot", "no" );
        $plugin->set_setting( "some_random_setting", "no" );
 
        $this->assertEquals( false, $plugin->get_setting( "synkronoi_varastosaldot" ) );
        $this->assertEquals( "no", $plugin->get_setting( "some_random_setting" ) );
    }
}

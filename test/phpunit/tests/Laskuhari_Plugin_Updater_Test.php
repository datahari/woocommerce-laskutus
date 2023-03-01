<?php

use Laskuhari\Laskuhari_Plugin_Updater;

/**
 * PHPUnit tests for Laskuhari_Plugin_Updater class
 */

class Laskuhari_Plugin_Updater_Test extends \PHPUnit\Framework\TestCase
{
    public function test_it_can_be_only_instantiated_once(): void {
        $updater1 = Laskuhari_Plugin_Updater::init();
        $updater2 = Laskuhari_Plugin_Updater::init();

        $this->assertSame( $updater1, $updater2 );
        $this->assertInstanceOf( Laskuhari_Plugin_Updater::class, $updater1 );
    }

    public function test_it_registers_filters(): void {
        $updater = Laskuhari_Plugin_Updater::init();

        $this->assertEquals( 10, has_filter( 'pre_set_site_transient_update_plugins', [$updater, 'check_for_updates'] ) );
        $this->assertEquals( 10, has_filter( 'plugins_api', [$updater, 'plugin_api_call'] ) );
    }

    public function test_it_adds_api_reponse_to_update_list_when_update_is_available(): void {
        $updater = Laskuhari_Plugin_Updater::init();
        $updater->set_mock_response( [
            'response' => [
                'code' => 200,
            ],
            'body' => json_encode( [
                'version' => '99.0.0',
                'homepage' => '',
                'download_link' => '',
                'requires' => '',
                'tested' => '',
                'requires_php' => '',
            ] ),
        ] );

        $transient = new stdClass();
        $transient->response = [];
        $transient->no_update = [];

        $transient = $updater->check_for_updates( $transient );

        $this->assertArrayHasKey( 'woocommerce-laskuhari-payment-gateway/woocommerce-laskuhari-payment-gateway.php', $transient->response );
        $this->assertArrayNotHasKey( 'woocommerce-laskuhari-payment-gateway/woocommerce-laskuhari-payment-gateway.php', $transient->no_update );
    }

    public function test_it_adds_api_reponse_to_no_update_list_when_update_is_not_available(): void {
        $updater = Laskuhari_Plugin_Updater::init();
        $updater->set_mock_response( [
            'response' => [
                'code' => 200,
            ],
            'body' => json_encode( [
                'version' => '0.0.0',
                'homepage' => '',
                'download_link' => '',
                'requires' => '',
                'tested' => '',
                'requires_php' => '',
            ] ),
        ] );

        $transient = new stdClass();
        $transient->response = [];
        $transient->no_update = [];

        $transient = $updater->check_for_updates( $transient );

        $this->assertArrayHasKey( 'woocommerce-laskuhari-payment-gateway/woocommerce-laskuhari-payment-gateway.php', $transient->no_update );
        $this->assertArrayNotHasKey( 'woocommerce-laskuhari-payment-gateway/woocommerce-laskuhari-payment-gateway.php', $transient->response );
    }

    public function test_it_keeps_other_plugins_update_info_unchanged_when_update_is_available(): void {
        $updater = Laskuhari_Plugin_Updater::init();
        $updater->set_mock_response( [
            'response' => [
                'code' => 200,
            ],
            'body' => json_encode( [
                'version' => '99.0.0',
                'homepage' => '',
                'download_link' => '',
                'requires' => '',
                'tested' => '',
                'requires_php' => '',
            ] ),
        ] );

        $transient = new stdClass();
        $transient->response = [
            "woocommerce/woocommerce.php" => (object) [
                "id" => "woocommerce/woocommerce.php",
                "slug" => "woocommerce",
                "plugin" => "woocommerce/woocommerce.php",
                "new_version" => "4.0.0",
                "url" => "https://woocommerce.com/",
                "package" => "https://downloads.wordpress.org/plugin/woocommerce.4.0.0.zip",
            ],
        ];
        $transient->no_update = [];

        $transient = $updater->check_for_updates( $transient );

        $this->assertArrayHasKey( 'woocommerce-laskuhari-payment-gateway/woocommerce-laskuhari-payment-gateway.php', $transient->response );
        $this->assertArrayHasKey( 'woocommerce/woocommerce.php', $transient->response );
    }

    public function test_it_keeps_other_plugins_update_info_unchanged_when_update_is_not_available(): void {
        $updater = Laskuhari_Plugin_Updater::init();
        $updater->set_mock_response( [
            'response' => [
                'code' => 200,
            ],
            'body' => json_encode( [
                'version' => '0.0.0',
                'homepage' => '',
                'download_link' => '',
                'requires' => '',
                'tested' => '',
                'requires_php' => '',
            ] ),
        ] );

        $transient = new stdClass();
        $transient->response = [
            "woocommerce/woocommerce.php" => (object) [
                "id" => "woocommerce/woocommerce.php",
                "slug" => "woocommerce",
                "plugin" => "woocommerce/woocommerce.php",
                "new_version" => "4.0.0",
                "url" => "https://woocommerce.com/",
                "package" => "https://downloads.wordpress.org/plugin/woocommerce.4.0.0.zip",
            ],
        ];
        $transient->no_update = [];

        $transient = $updater->check_for_updates( $transient );

        $this->assertArrayNotHasKey( 'woocommerce-laskuhari-payment-gateway/woocommerce-laskuhari-payment-gateway.php', $transient->response );
        $this->assertArrayHasKey( 'woocommerce-laskuhari-payment-gateway/woocommerce-laskuhari-payment-gateway.php', $transient->no_update );
        $this->assertArrayHasKey( 'woocommerce/woocommerce.php', $transient->response );
        $this->assertArrayNotHasKey( 'woocommerce/woocommerce.php', $transient->no_update );
    }

    public function test_it_adds_transient_for_checked_version(): void {
        $updater = Laskuhari_Plugin_Updater::init();
        $updater->set_mock_response( [
            'response' => [
                'code' => 200,
            ],
            'body' => json_encode( [
                'version' => '0.0.0',
                'homepage' => '',
                'download_link' => '',
                'requires' => '',
                'tested' => '',
                'requires_php' => '',
            ] ),
        ] );

        $transient = new stdClass();
        $transient->response = [
            "woocommerce/woocommerce.php" => (object) [
                "id" => "woocommerce/woocommerce.php",
                "slug" => "woocommerce",
                "plugin" => "woocommerce/woocommerce.php",
                "new_version" => "4.0.0",
                "url" => "https://woocommerce.com/",
                "package" => "https://downloads.wordpress.org/plugin/woocommerce.4.0.0.zip",
            ],
        ];
        $transient->no_update = [];

        $transient = $updater->check_for_updates( $transient );

        $this->assertArrayHasKey( 'woocommerce-laskuhari-payment-gateway/woocommerce-laskuhari-payment-gateway.php', $transient->checked );
    }
}

<?php
/**
 * This class handles the uninstallation of the Laskuhari plugin
 *
 * @class Laskuhari_Uninstall
 */

namespace Laskuhari;

defined( 'ABSPATH' ) || exit;

class Laskuhari_Uninstall
{
    public static function register_uninstall_hook( string $file ): void {
        \register_uninstall_hook( $file, [Laskuhari_Uninstall::class, "uninstall_hook"] );
    }

    public static function uninstall_hook(): void {
        wp_clear_scheduled_hook( Logger::SCHEDULED_EVENT_HOOK );
    }
}

<?php
/**
 * This class handles creation and verification of nonces
 * for Laskuhari actions that should only be performed
 * once to prevent double submissions.
 */

namespace Laskuhari;

class Laskuhari_Nonce
{
    /**
     * Create a nonce for Laskuhari actions.
     *
     * @return string
     */
    public static function create() {
        return bin2hex( random_bytes( 4 ) );
    }

    /**
     * Verifies the nonce for Laskuhari actions.
     *
     * @param ?string $nonce The nonce to verify. If null, it will use the nonce from the request.
     * @return void
     */
    public static function verify( $nonce = null ) {
        if( session_status() !== PHP_SESSION_ACTIVE ) {
            session_start();
        }

        self::garbage_collect();

        $nonce = $nonce ?? $_REQUEST['_lhnonce'] ?? null;

        if( ! $nonce ) {
            wp_die( __( "Vahvistuskoodi (nonce) puuttuu", "laskuhari" ) );
        }

        if(
            isset( $_SESSION['laskuhari_nonces'][$nonce] ) &&
            ( time() - $_SESSION['laskuhari_nonces'][$nonce] ) < HOUR_IN_SECONDS
        ) {
            wp_die( __( "Estetty kaksinkertainen tai vanhentunut toiminto", "laskuhari" ) );
        }

        $_SESSION['laskuhari_nonces'][$nonce] = time();
    }

    /**
     * Garbage collect old nonces.
     *
     * @return void
     */
    public static function garbage_collect() {
        if( session_status() !== PHP_SESSION_ACTIVE ) {
            session_start();
        }

        if( ! isset( $_SESSION['laskuhari_nonces'] ) ) {
            $_SESSION['laskuhari_nonces'] = [];
        }

        $now = time();

        foreach( $_SESSION['laskuhari_nonces'] as $nonce => $timestamp ) {
            if( ( $now - $timestamp ) > HOUR_IN_SECONDS ) {
                unset( $_SESSION['laskuhari_nonces'][$nonce] );
            }
        }
    }
}

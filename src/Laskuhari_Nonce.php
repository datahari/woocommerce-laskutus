<?php
/**
 * This class handles creation and verification of nonces
 * for Laskuhari actions that should only be performed
 * once to prevent double submissions.
 */

namespace Laskuhari;

use Exception;
use Random\RandomException;

class Laskuhari_Nonce
{
    /**
     * Create a nonce for Laskuhari actions.
     *
     * @return string
     */
    public static function create() {
        try {
            return bin2hex( random_bytes( 4 ) );
        } catch( RandomException $e ) {
            throw new Exception( $e->getMessage() );
        }
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
            return;
        }

        /** @var array<string, int> $nonces */
        $nonces = &$_SESSION['laskuhari_nonces'];

        if(
            isset( $nonces[$nonce] ) &&
            ( time() - $nonces[$nonce] ) < HOUR_IN_SECONDS
        ) {
            wp_die( __( "Estetty kaksinkertainen tai vanhentunut toiminto", "laskuhari" ) );
            return;
        }

        $nonces[$nonce] = time();
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

        /** @var array<string, int> $nonces */
        $nonces = &$_SESSION['laskuhari_nonces'];

        foreach( $nonces as $nonce => $timestamp ) {
            if( ( $now - $timestamp ) > HOUR_IN_SECONDS ) {
                unset( $nonces[$nonce] );
            }
        }
    }
}

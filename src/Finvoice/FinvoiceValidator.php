<?php
namespace Laskuhari\Finvoice;

use Laskuhari\Exception\Finvoice\BusinessIdMissingException;
use Laskuhari\Exception\Finvoice\EInvoiceAddressHasSpacesException;
use Laskuhari\Exception\Finvoice\EInvoiceAddressInvalidException;
use Laskuhari\Exception\Finvoice\EInvoiceAddressMissingException;
use Laskuhari\Exception\Finvoice\EInvoiceAddressTooShortException;
use Laskuhari\Exception\Finvoice\OperatorCodeHasSpacesException;
use Laskuhari\Exception\Finvoice\OperatorCodeHasSpecialCharactersException;
use Laskuhari\Exception\Finvoice\OperatorCodeMismatchException;
use Laskuhari\Exception\Finvoice\OperatorCodeMissingException;

class FinvoiceValidator
{
    /**
     * Checks the validity of an e-invoice address (only ToIdentifier)
     *
     * @param string $verkkolaskuosoite
     *
     * @return void
     *
     * @throws EInvoiceAddressTooShortException
     * @throws EInvoiceAddressHasSpacesException
     * @throws EInvoiceAddressInvalidException
     */
    public static function validate_einvoice_address( $verkkolaskuosoite ) {
        $verkkolaskuosoite = strtoupper( trim( $verkkolaskuosoite ) );

        $finvoice_min_len = apply_filters( "laskuhari_finvoice_min_len", 5 );
        $finvoice_min_len = is_numeric( $finvoice_min_len ) ? (int) $finvoice_min_len : 5;

        if( strlen( $verkkolaskuosoite ) < $finvoice_min_len ) {
            throw new EInvoiceAddressTooShortException( __( "Verkkolaskuosoite on liian lyhyt" ) );
        }

        if( preg_match( '/\s/', $verkkolaskuosoite ) === 1 ) {
            throw new EInvoiceAddressHasSpacesException( __( "Verkkolaskuosoite ei saa sisältää välilyöntejä" ) );
        }

        $fail_patterns = [
            "/[A-Z]{6}/" => __( "Osoitteessa on liikaa kirjaimia" ),
            "/^[A-Z]+$/" => __( "Osoite koostuu pelkästään kirjaimista" ),
            "/^37[0-9]{8}$/" => __( "Etunollat OVT-tunnuksesta puuttuvat (0037XXX)" ),
            "/[^A-Z0-9]/" => __( "Verkkolaskuosoite voi sisältää vain merkkejä A-Z ja 0-9" ),
        ];

        $fail_patterns = apply_filters( "laskuhari_einvoiceaddress_fail_patterns", $fail_patterns );

        if( ! is_array( $fail_patterns ) ) {
            $fail_patterns = [];
        }

        foreach( $fail_patterns as $pattern => $error ) {
            if( ! is_string( $error ) ) {
                continue;
            }

            $stripped = str_replace( [
                "BE",
                "LR",
                "FI",
                "OVT",
                "EDI",
                "TE",
            ], "", $verkkolaskuosoite );

            $custom_rule = apply_filters( "laskuhari_invalid_einvoice_address", true, $verkkolaskuosoite );

            if( preg_match( $pattern, $stripped ) === 1 && $custom_rule ) {
                throw new EInvoiceAddressInvalidException( $error );
            }
        }
    }

    /**
     * Checks the validity of an e-invoice address.
     *
     * @param string $verkkolaskuosoite
     * @param string $valittaja
     * @param string $ytunnus
     *
     * @return void
     *
     * @throws EInvoiceAddressMissingException
     * @throws OperatorCodeMismatchException
     * @throws OperatorCodeMissingException
     * @throws BusinessIdMissingException
     * @throws OperatorCodeHasSpacesException
     * @throws EInvoiceAddressHasSpacesException
     * @throws EInvoiceAddressTooShortException
     * @throws EInvoiceAddressInvalidException
     * @throws OperatorCodeHasSpecialCharactersException
     */
    public static function validate_finvoice_address( $verkkolaskuosoite, $valittaja, $ytunnus ) {
        if( empty( $verkkolaskuosoite ) ) {
            throw new EInvoiceAddressMissingException( __( "Verkkolaskuosoite puuttuu", "laskuhari" ) );
        }

        if( empty( $valittaja ) ) {
            throw new OperatorCodeMissingException( __( "Välittäjätunnus puuttuu" ) );
        }

        if( empty( $ytunnus ) ) {
            throw new BusinessIdMissingException( __( "Y-tunnus puuttuu" ) );
        }

        $verkkolaskuosoite = trim( $verkkolaskuosoite );
        $valittaja = trim( $valittaja );
        $ytunnus = trim( $ytunnus );

        $operators = laskuhari_operators();
        $operator_codes = array_merge(
            array_keys( $operators["operators"] ),
            array_keys( $operators["banks"] )
        );

        if( in_array( $verkkolaskuosoite, $operator_codes ) && $valittaja != $verkkolaskuosoite ) {
            throw new OperatorCodeMismatchException( __( "Verkkolaskuosoitteeksi on virheellisesti syötetty välittäjätunnus" ) );
        }

        if( preg_match( '/\s/', $valittaja ) === 1 ) {
            throw new OperatorCodeHasSpacesException( __( "Välittäjätunnus ei saa sisältää välilyöntejä" ) );
        }

        if( preg_match( '/[^A-Z0-9]/', $valittaja ) === 1 ) {
            throw new OperatorCodeHasSpecialCharactersException( __( "Välittäjätunnus voi sisältää vain merkkejä A-Z ja 0-9" ) );
        }

        self::validate_einvoice_address( $verkkolaskuosoite );
    }
}

<?php
/**
 * Extansion that adds extra user metadata to user profile
 */
class AddUserMeta extends LaskuhariExtension
{
    /**
     * Filters to be added by this extension
     *
     * @var array
     */
    protected array $filters = [];

    /**
     * Actions to be added by this extension
     *
     * @var array
     */
    protected array $actions = [
        [ 'show_user_profile', 'print_user_meta_form_table' ],
        [ 'edit_user_profile', 'print_user_meta_form_table' ],
        [ 'edit_user_profile_update', 'handle_user_meta_update' ],
        [ 'edit_user_profile_update', 'handle_user_meta_update' ],
    ];

    /**
     * Meta fields to add to user profile
     *
     * @return array
     */
    private function meta_fields(): array {
        return array(
            array(
                "name"  => "laskuhari_laskutusasiakas",
                "title" => __( 'Laskutusasiakas', 'laskuhari' ),
                "type"  => "checkbox"
            ),
            array(
                "name"    => "laskuhari_payment_terms_default",
                "title"   => __( 'Maksuehto', 'laskuhari' ),
                "type"    => "select",
                "options" => apply_filters( "laskuhari_payment_terms_select_box", LaskuhariPaymentTerm::get_list() )
            ),
            array(
                "name"  => "_laskuhari_laskutustapa",
                "title" => __( 'Laskutustapa', 'laskuhari' ),
                "type"  => "select",
                "options" => [
                    "" => "-- Valitse --",
                    "email" => "Sähköposti",
                    "verkkolasku" => "Verkkolasku",
                    "kirje" => "Kirje"
                ]
            ),
            array(
                "name"  => "_laskuhari_billing_email",
                "title" => __( 'Laskutussähköposti', 'laskuhari' ),
                "type"  => "text"
            ),
            array(
                "name"  => "_laskuhari_ytunnus",
                "title" => __( 'Y-tunnus', 'laskuhari' ),
                "type"  => "text"
            ),
            array(
                "name"  => "_laskuhari_verkkolaskuosoite",
                "title" => __( 'Verkkolaskuosoite', 'laskuhari' ),
                "type"  => "text"
            ),
            array(
                "name"  => "_laskuhari_valittaja",
                "title" => __( 'Verkkolaskuoperaattori', 'laskuhari' ),
                "type"  => "select",
                "options" => [
                    "" => "-- Valitse --",
                    "003723327487"    => "Apix Messaging Oy (003723327487)",
                    "BAWCFI22"        => "Basware Oyj (BAWCFI22)",
                    "003703575029"    => "CGI (003703575029)",
                    "885790000000418" => "HighJump AS (885790000000418)",
                    "INEXCHANGE"      => "InExchange Factorum AB (INEXCHANGE)",
                    "EXPSYS"          => "Lexmark Expert Systems AB (EXPSYS)",
                    "003708599126"    => "Liaison Technologies Oy (003708599126)",
                    "003721291126"    => "Maventa (003721291126)",
                    "003726044706"    => "Netbox Finland Oy (003726044706)",
                    "E204503"         => "OpusCapita Solutions Oy (E204503)",
                    "003723609900"    => "Pagero (003723609900)",
                    "PALETTE"         => "Palette Software (PALETTE)",
                    "003710948874"    => "Posti Messaging Oy (003710948874)",
                    "003701150617"    => "PostNord Strålfors Oy (003701150617)",
                    "003714377140"    => "Ropo Capital Oy (003714377140)",
                    "003703575029"    => "Telia (003703575029)",
                    "003701011385"    => "Tieto Oyj (003701011385)",
                    "885060259470028" => "Tradeshift (885060259470028)",
                    "HELSFIHH"        => "Aktia (HELSFIHH)",
                    "DABAFIHH"        => "Danske Bank (DABAFIHH)",
                    "DNBAFIHX"        => "DNB (DNBAFIHX)",
                    "HANDFIHH"        => "Handelsbanken (HANDFIHH)",
                    "NDEAFIHH"        => "Nordea Pankki (NDEAFIHH)",
                    "ITELFIHH"        => "Oma Säästöpankki (ITELFIHH)",
                    "OKOYFIHH"        => "Osuuspankit (OKOYFIHH)",
                    "OKOYFIHH"        => "Pohjola Pankki (OKOYFIHH)",
                    "POPFFI22"        => "POP Pankki  (POPFFI22)",
                    "SBANFIHH"        => "S-Pankki (SBANFIHH)",
                    "TAPIFI22"        => "LähiTapiola (TAPIFI22)",
                    "ITELFIHH"        => "Säästöpankit (ITELFIHH)",
                    "AABAFI22"        => "Ålandsbanken (AABAFI22)",
                ]
            )
        );
    }

    function print_user_meta_form_table( $user ) {
        echo '<h3>Laskuhari</h3>'.
             '<table class="form-table">';
    
        $meta_number = 0;
    
        foreach ( $this->meta_fields() as $meta_field ) {
            $meta_number++;
    
            $meta_disp_name   = $meta_field['title'];
            $meta_field_name  = $meta_field['name'];
    
            $current_value = get_user_meta( $user->ID, $meta_field_name, true );
    
            if( "checkbox" === $meta_field['type'] ) {
                if ( "yes" === $current_value ) {
                    $author_meta_checked = "checked";
                } else {
                    $author_meta_checked = "";
                }
    
                echo '
                <tr>
                    <th>' . $meta_disp_name . '</th>
                    <td>
                        <input type="checkbox" name="' . $meta_field_name . '"
                               id="' . $meta_field_name . '"
                               value="yes" ' . $author_meta_checked . ' />
                        <label for="' . $meta_field_name . '">Kyllä</label><br />
                        <span class="description"></span>
                    </td>
                </tr>';
            } elseif( "text" === $meta_field['type'] ) {
                echo '
                <tr>
                    <th>' . $meta_disp_name . '</th>
                    <td>
                        <input type="text" name="' . $meta_field_name . '"
                               id="' . $meta_field_name . '"
                               value="' . esc_attr( $current_value ) . '" /><br />
                        <span class="description"></span>
                    </td>
                </tr>';
            } elseif( "select" === $meta_field['type'] ) {
                echo '
                <tr>
                    <th>' . $meta_disp_name . '</th>
                    <td>
                        '.LaskuhariDOM::create_select_box( $meta_field_name, $meta_field['options'], $current_value ).'<br />
                        <span class="description"></span>
                    </td>
                </tr>';
            }
        }
    
        echo '</table>';
    }

    function handle_user_meta_update( $user_id ) {

        if( ! current_user_can( 'edit_user', $user_id ) ) {
            return false;
        }
    
        $meta_number = 0;
    
        foreach ( $this->meta_fields() as $meta_field ) {
            $meta_number++;
            $meta_field_name = $meta_field['name'];
    
            update_user_meta( $user_id, $meta_field_name, $_POST[$meta_field_name] );
        }
    }

}

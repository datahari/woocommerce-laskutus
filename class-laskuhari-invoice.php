<?php
class Laskuhari_Invoice
{
    /**
     * The WC_Order this invoice belongs to
     *
     * @var WC_Order
     */
    private WC_Order $order;

    /**
     * Variable to store invoice rows
     *
     * @var array Invoice rows
     */
    private array $invoice_rows = [];
    
    /**
     * Variable to store coupon codes of WC_Order
     *
     * @var array
     */
    private array $coupon_codes;
    
    /**
     * Variable to store the calculated total sum of invoice
     *
     * @var float
     */
    private float $calculated_total = 0;

    /**
     * Variable to store whether prices include tax or not
     *
     * @var boolean
     */
    private bool $prices_include_tax;

    /**
     * Invoicing fee should not be included when calculating rounding error of totals
     * so we save it to this variable
     *
     * @var float
     */
    private float $total_invoicing_fee_added = 0;

    /**
     * The payment term ID of this invoice
     *
     * @var integer
     */
    private int $payment_term;

    /**
     * The buyer reference of this invoice
     *
     * @var string
     */
    private string $buyer_reference;

    /**
     * The email address for sending this invoice
     * Note: customer email may be different
     *
     * @var string
     */
    private string $email_address;

    /**
     * Instantiate the class with a WC_Order
     *
     * @param WC_Order $order The order this invoice is made from
     */
    public function __construct( WC_Order $order ) {
        $this->order = $order;
    }

    /**
     * Constants for invoice statuses
     */
    public const UNPAID = 0; // unpaid invoice
    public const PAID = 1; // paid invoice

    /**
     * Get the specified setting of the plugin
     *
     * @param string $setting
     * @return mixed
     */
    private function get_setting( string $setting ): mixed {
        return Laskuhari_WC_Plugin::instance()->get_setting( $setting );
    }

    /**
     * Determine if prices include tax or not
     *
     * @return void
     */
    private function prices_include_tax() {
        if( ! isset( $this->prices_include_tax ) ) {
            $this->prices_include_tax = get_post_meta( $this->order->get_id(), '_prices_include_tax', true ) === 'yes';
        }
        return $this->prices_include_tax;
    }

    /**
     * Get invoicing fee excluding tax
     *
     * @return float
     */
    public function get_invoicing_fee_excluding_tax(): float {
        if( $this->prices_include_tax() ) {
            return $this->get_setting( "laskutuslisa" ) / ( 1 + $this->get_setting( "laskutuslisa_alv" ) / 100 );
        }
        return $this->get_setting( "laskutuslisa" );
    }

    /**
     * Get invoicing fee including tax
     *
     * @return float
     */
    public function get_invoicing_fee_including_tax(): float {
        if( $this->prices_include_tax() ) {
            return $this->get_setting( "laskutuslisa" );
        }
        return $this->get_setting( "laskutuslisa" ) * ( 1 + $this->get_setting( "laskutuslisa_alv" ) / 100 );
    }

    /**
     * Determines whether the shipping address of the order
     * is different from the billing address
     *
     * @return bool
     */
    public function ship_to_different_address(): bool {
        $billingdata  = $this->get_billing_address();
        $shippingdata = $this->get_shipping_address();

        $shipping_different = false;

        foreach ( $billingdata as $key => $bdata ) {
            if( ! isset( $shippingdata[$key] ) ) {
                continue;
            }

            $sdata = trim( $shippingdata[$key] );

            if( in_array( $key, ["email", "phone"] ) && $sdata == "" ) {
                continue;
            }

            $bdata = trim( $bdata );

            if( $sdata != "" && $sdata != $bdata ) {
                $shipping_different = true;
                break;
            }
        }

        if( ! $shipping_different ) {
            foreach( $shippingdata as $key => $sdata ) {
                $shippingdata[$key] = "";
            }
        }

        return $shipping_different;
    }

    /**
     * Get coupon codes from the WC_Order
     *
     * @param boolean|null $remove_technical_coupons
     * @return array
     */
    public function get_coupon_codes( ?bool $remove_technical_coupons = false ): array {
        if( isset( $this->coupon_codes ) ) {
            return $this->coupon_codes;
        }

        $coupon_codes = $this->order->get_coupon_codes();

        if( true === $remove_technical_coupons ) {
            // remove coupons starting with an underscore
            $coupon_codes = array_filter( $coupon_codes, function( $v ) {
                return '_' !== $v[0];
            } );
        }

        return $coupon_codes;
    }

    /**
     * Determine if the WC_Order has coupons
     *
     * @return boolean
     */
    public function has_coupons(): bool {
        return $this->coupon_count() > 0;
    }

    /**
     * Returns number of coupons attached to WC_Order
     *
     * @return int Number of coupons attached to WC_Order
     */
    public function coupon_count(): int {
        return count( $this->get_coupon_codes() );
    }

    /**
     * Add products from order to invoice rows
     *
     * @return void
     */
    public function add_products() {
        $products           = $this->order->get_items();
        $cart_discount      = $this->order->get_discount_total();
        $cart_discount_tax  = $this->order->get_discount_tax();

        foreach( $products as $item ) {

            $data = $item->get_data();

            if( $this->has_coupons() ) {
                $sub = 'sub';
            } else {
                $sub = '';
            }

            $total_with_tax = round( $data[$sub.'total'] + $data[$sub.'total_tax'], 2 );

            if( $data[$sub.'total'] != 0 ) {
                $vat               = round( $data[$sub.'total_tax'] / $data[$sub.'total'] * 100, 0 );
                $total_without_tax = $total_with_tax / ( 1 + $vat / 100 );
            } else {
                $vat               = 0;
                $total_without_tax = 0;
            }

            if( $data[$sub.'total'] != 0 ) {
                $unit_price_with_tax    = round( $total_with_tax / $data['quantity'], 10 );
                $unit_price_without_tax = $unit_price_with_tax / ( 1 + $vat / 100 );
            } else {
                $unit_price_with_tax    = 0;
                $unit_price_without_tax = 0;
            }

            if( ! $this->has_coupons() ) {
                $cart_discount     -= $data['subtotal'] - $data['total'];
                $cart_discount_tax -= $data['subtotal_tax'] - $data['total_tax'];
            }

            $dicount = 0;

            $product_id = $data['variation_id'] ? $data['variation_id'] : $data['product_id'];

            if( $this->get_setting( "synkronoi_varastosaldot" ) && ! laskuhari_product_synced( $product_id ) ) {
                laskuhari_create_product( $product_id );
            }

            if( $product_id ) {
                set_transient( "laskuhari_update_product_" . $product_id, $product_id, 4 );
                $product = wc_get_product( $product_id );
                $product_sku = $product->get_sku();
            } else {
                $product_sku = "";
            }

            $this->invoice_rows[] = laskuhari_invoice_row( [
                "product_sku"   => $product_sku,
                "product_id"    => $data['product_id'],
                "variation_id"  => $data['variation_id'],
                "nimike"        => $data['name'],
                "maara"         => $data['quantity'],
                "veroton"       => $unit_price_without_tax,
                "alv"           => $vat,
                "verollinen"    => $unit_price_with_tax,
                "ale"           => $dicount,
                "yhtveroton"    => $total_without_tax,
                "yhtverollinen" => $total_with_tax
            ] );

            $this->calculated_total += $total_with_tax;
        }
    }

    /**
     * Create an invoice row from the shipping cost
     *
     * @return void
     */
    public function add_shipping_cost() {
        $shipping_cost = $this->order->get_shipping_total();

        if( $shipping_cost == 0 ) {
            return false;
        }

        $shipping_tax         = $this->order->get_shipping_tax();
        $shipping_tax_percent = round( $shipping_tax / $shipping_cost, 2 );

        // calculate shipping cost down to multiple decimals
        // get_shipping_total returns rounded excluding tax
        $shipping_cost        = round( $shipping_cost + $shipping_tax, 2 ) / ( 1 + $shipping_tax_percent );
        $shipping_cost        = round( $shipping_cost + $shipping_tax, 2 ) / ( 1 + $shipping_tax_percent );

        $shipping_method      = $this->order->get_shipping_method();

        $this->invoice_rows[] = laskuhari_invoice_row( [
            "nimike"        => __( "Toimitustapa: " ) . $shipping_method,
            "maara"         => 1,
            "veroton"       => $shipping_cost,
            "alv"           => round( $shipping_tax / $shipping_cost * 100, 0 ),
            "verollinen"    => $shipping_cost + $shipping_tax,
            "ale"           => 0,
            "yhtveroton"    => $shipping_cost,
            "yhtverollinen" => $shipping_cost + $shipping_tax
        ]);

        $this->calculated_total += $shipping_cost + $shipping_tax;
    }

    /**
     * Create an invoice row form the discount
     *
     * @return void
     */
    public function add_discount() {
        $cart_discount      = $this->order->get_discount_total();
        $cart_discount_tax  = $this->order->get_discount_tax();

        if( abs( round( $cart_discount, 2 ) ) == 0 ) {
            return false;
        }

        $coupon_codes = $this->get_coupon_codes( true );

        $discount_name = __( "Alennus", "laskuhari" );

        if( $this->has_coupons() ) {
            if( $this->coupon_count() > 1 ) {
                $discount_name = __( "Kupongit", "laskuhari" );
            } else {
                $discount_name = __( "Kuponki", "laskuhari" );
            }
            $discount_name .= " (".implode( ", ", $coupon_codes ).")";
        }

        $this->invoice_rows[] = laskuhari_invoice_row( [
            "nimike"        => $discount_name,
            "maara"         => 1,
            "veroton"       => $cart_discount * -1,
            "alv"           => round( $cart_discount_tax / $cart_discount * 100, 0 ),
            "verollinen"    => ( $cart_discount + $cart_discount_tax ) * -1,
            "ale"           => 0,
            "yhtveroton"    => $cart_discount * -1,
            "yhtverollinen" => ( $cart_discount + $cart_discount_tax ) * -1
        ] );

        $this->calculated_total += ( $cart_discount + $cart_discount_tax ) * -1;
    }

    /**
     * Create an invoice row for every fee
     * 
     * The invoicing fee row is skipped because it will be added to the invoice every time
     * so adding it here would add a double invoicing fee row when making invoices
     * from existing orders
     *
     * @return void
     */
    public function add_fees() {
        foreach( $this->order->get_items('fee') as $item_fee ){
            $fee_name      = $item_fee->get_name();
            $fee_total_tax = $item_fee->get_total_tax();
            $fee_total     = $item_fee->get_total();
    
            $fee_total_including_tax = $fee_total + $fee_total_tax;
    
            // save amount of invoicing fee added so that it can be
            // substracted from the rounding error calculation
            if( $fee_name == "Laskutuslisä" ) {
                $this->total_invoicing_fee_added += $fee_total_including_tax;
                continue;
            }
    
            if( $fee_total != 0 ) {
                $alv         = round( $fee_total_tax / $fee_total * 100, 0 );
                $yht_veroton = $fee_total;
            } else {
                $alv         = 0;
                $yht_veroton = 0;
            }
    
            if( $fee_total != 0 ) {
                $yks_verollinen = round( $fee_total_including_tax, 2 );
                $yks_veroton    = $yks_verollinen / ( 1 + $alv / 100 );
            } else {
                $yks_verollinen = 0;
                $yks_veroton    = 0;
            }
    
            $this->invoice_rows[] = laskuhari_invoice_row( [
                "nimike"        => $fee_name,
                "maara"         => 1,
                "veroton"       => $yks_veroton,
                "alv"           => $alv,
                "verollinen"    => $yks_verollinen,
                "ale"           => 0,
                "yhtveroton"    => $yht_veroton,
                "yhtverollinen" => $fee_total
            ] );
    
            $this->calculated_total += $fee_total_including_tax;
        }
    }

    /**
     * Add invoicing fee to the invoice if necessary
     *
     * @return bool
     */
    public function add_invoicing_fee() {
        if( ! $this->get_setting( "laskutuslisa" ) ) {
            return false;
        }
    
        if( laskuhari_order_is_paid_by_other_method( $this->order ) ) {
            return false;
        }

        $invoicing_fee_tax           = $this->get_setting( "laskutuslisa_alv" );
        $invoicing_fee_excluding_tax = $this->get_invoicing_fee_excluding_tax();
        $invoicing_fee_including_tax = $this->get_invoicing_fee_including_tax();

        $this->invoice_rows[] = laskuhari_invoice_row( [
            "nimike"        => __( "Laskutuslisä", "laskuhari" ),
            "maara"         => 1,
            "veroton"       => $invoicing_fee_excluding_tax,
            "alv"           => $invoicing_fee_tax,
            "verollinen"    => $invoicing_fee_including_tax,
            "ale"           => 0,
            "yhtveroton"    => $invoicing_fee_excluding_tax,
            "yhtverollinen" => $invoicing_fee_including_tax
        ] );

        return true;
    }

    /**
     * Get how much rounding error is allowed between WC_Order total and invoice total
     *
     * @return float
     */
    public function get_allowed_rounding_error(): float {
        return apply_filters( "laskuhari_get_allowed_rounding_error", 0.05 );
    }

    /**
     * Get the amount of rounding error between WC_Order total and invoice total
     * 
     * Invoicing fee is substracted because it will be added in the end but is
     * already included in existing orders
     *
     * @return float
     */
    public function get_rounding_error(): float {
        $wc_order_total = $this->order->get_total();
        $order_total_without_invoicing_fee = $wc_order_total - $this->total_invoicing_fee_added;
        $rounding_error = $order_total_without_invoicing_fee - $this->calculated_total;

        return $rounding_error;
    }

    /**
     * Check the rounding error and add a notice if it's too large
     * 
     * Returns array with notice if error is present or false if no error
     *
     * @return array|false
     */
    public function check_rounding_error(): mixed {
        $rounding_error = $this->get_rounding_error();

        if( abs( $rounding_error ) > $this->get_allowed_rounding_error() ) {

            $error_notice = sprintf(
                'Pyöristysvirhe liian suuri (%s)! Laskua ei luotu',
                round( $rounding_error, 2 )
            );

            $this->order->add_order_note( $error_notice );

            if( function_exists( 'wc_add_notice' ) ) {
                wc_add_notice( __( 'Laskun automaattinen lähetys epäonnistui. Lähetämme laskun manuaalisesti.' ), 'notice' );
            }

            return array(
                "notice" => urlencode( $error_notice )
            );
        }

        return false;
    }

    /**
     * Add a rounding row to the invoice so that the WC_Order total is same as invoice total
     *
     * @return void
     */
    public function add_rounding_row() {
        $rounding_error = $this->get_rounding_error();

        if( round( $rounding_error, 2 ) == 0 ) {
            return false;
        }

        $this->invoice_rows[] = laskuhari_invoice_row( [
            "nimike"        => __( "Pyöristys", "laskuhari" ),
            "maara"         => 1,
            "veroton"       => $rounding_error,
            "alv"           => 0,
            "verollinen"    => $rounding_error,
            "ale"           => 0,
            "yhtveroton"    => $rounding_error,
            "yhtverollinen" => $rounding_error
        ]);

        return true;
    }

    /**
     * Get invoice metadata from order meta or user meta, in that order,
     * which ever has the requested metadata
     *
     * @param string $meta_key
     * @param boolean $single
     * @return mixed
     */
    function get_meta( string $meta_key, $single = true ): mixed {
        $post_meta = get_post_meta( $this->order->get_id(), $meta_key, $single );

        if( $post_meta ) {
            return $post_meta;
        }

        if( is_checkout() ) { // TODO: check if this is still necessary
            $user_id = get_current_user_id();
        } else {
            $user_id = $this->order->get_user_id();
        }

        return get_user_meta( $user_id, $meta_key, $single );
    }

    /**
     * Get the customer's number
     *
     * @return mixed
     */
    public function get_customer_number(): mixed {
        return apply_filters( 'laskuhari_customer_id', $this->order->get_user_id(), $this );
    }

    /**
     * Get the buyer's reference
     *
     * @return string
     */
    public function get_buyer_reference(): string {
        if( isset( $this->buyer_reference ) ) {
            return $this->buyer_reference;
        }
        return $this->get_meta( '_laskuhari_viitteenne' );
    }

    /**
     * Set the buyer reference
     *
     * @param string $buyer_reference Buyer reference
     * @return void
     */
    public function set_buyer_reference( string $buyer_reference ) {
        $this->buyer_reference = $buyer_reference;
        return $this;
    }

    /**
     * Get the buyer's business ID
     *
     * @return string
     */
    public function get_business_id(): string {
        return $this->get_meta( '_laskuhari_ytunnus' );
    }

    /**
     * Get the buyer's einvoice address
     *
     * @return string
     */
    public function get_einvoice_address(): string {
        return $this->get_meta( '_laskuhari_verkkolaskuosoite' );
    }

    /**
     * Get the buyer's einvoice operator
     *
     * @return string
     */
    public function get_einvoice_operator(): string {
        return $this->get_meta( '_laskuhari_valittaja' );
    }

    /**
     * Get the billing address
     *
     * @return array
     */
    public function get_billing_address(): array {
        return $this->order->get_address( 'billing' );
    }

    /**
     * Get the shipping address
     *
     * @return array
     */
    public function get_shipping_address(): array {
        return $this->order->get_address( 'shipping' );
    }

    /**
     * Get the defautl status for a newly created invoice
     *
     * @return integer
     */
    public function status_for_new_invoice(): int {
        return apply_filters( "laskuhari_new_invoice_status", self::UNPAID, $this );
    }

    /**
     * Convert internal status identifier to one used by the API
     *
     * @param int $status
     * @return string
     */
    public function convert_status_for_api( int $status ): string {
        $api_statuses = [
            self::UNPAID => "AVOIN",
            self::PAID => "MAKSETTU"
        ];
        return $api_statuses[$status];
    }

    /**
     * Gets the payment term ID for the invoice
     *
     * @return int
     */
    public function get_payment_term(): int {
        if( isset( $this->payment_term ) ) {
            return $this->payment_term;
        }

        $payment_term = $this->get_meta( '_laskuhari_payment_terms' );

        if( ! $payment_term ) {
            $payment_term = get_user_meta( $this->order->get_customer_id(), "laskuhari_payment_terms_default", true );
        }

        return intval( $payment_term );
    }

    /**
     * Set the payment term ID
     *
     * @param integer $payment_term Payment term ID
     * @return void
     */
    public function set_payment_term( int $payment_term ) {
        $this->payment_term = $payment_term;
        return $this;
    }

    /**
     * Get the email address for sending the invoice
     *
     * @return string
     */
    public function get_email_address(): string {
        if( isset( $this->email_address ) ) {
            return $this->email_address;
        }
        return $this->get_meta( '_laskuhari_email' );
    }

    /**
     * Set the email address for sending
     *
     * @param string $email_address Email address
     * @return void
     */
    public function set_email_address( string $email_address ) {
        $this->email_address = $email_address;
        return $this;
    }

    /**
     * Create the invoice payload used for the API
     *
     * @return array
     */
    public function create_payload(): array {
        $billing_address = $this->get_billing_address();
        $shipping_address = $this->get_shipping_address();

        $status = $this->convert_status_for_api( $this->status_for_new_invoice() );

        return [
            "ref" => "wc",
            "site" => $_SERVER['HTTP_HOST'],
            "tyyppi" => 0,
            "laskunro" => false,
            "pvm" => date( 'd.m.Y' ),
            "viitteenne" => $this->get_buyer_reference(),
            "viitteemme" => "",
            "tilausnumero" => $this->order->get_order_number(),
            "metatiedot" => [
                "lahetetty" => false,
                "toimitettu" => false,
                "maksupvm" => false,
                "status" => $status,
            ],
            "maksuehto" => [
                "id" => $this->get_payment_term(),
            ],
            "laskutusosoite" => [
                "yritys" => $billing_address['company'],
                "ytunnus" => $this->get_business_id(),
                "henkilo" => trim( $billing_address['first_name'].' '.$billing_address['last_name'] ),
                "lahiosoite" => [
                    $billing_address['address_1'],
                    $billing_address['address_2'],
                ],
                "postinumero" => $billing_address['postcode'],
                "postitoimipaikka" => $billing_address['city'],
                "email" => $billing_address['email'],
                "puhelin" => $billing_address['phone'],
                "asiakasnro" => $this->get_customer_number(),
            ],
            "toimitusosoite" => [
                "yritys" => $shipping_address['company'],
                "henkilo" => trim( $shipping_address['first_name'].' '.$shipping_address['last_name'] ),
                "lahiosoite" => [
                    $shipping_address['address_1'],
                    $shipping_address['address_2'],
                ],
                "postinumero" => $shipping_address['postcode'],
                "postitoimipaikka" => $shipping_address['city'],
            ],
            "verkkolasku" => [
                "toIdentifier" => $this->get_einvoice_address(),
                "toIntermediator" => $this->get_einvoice_operator(),
                "buyerPartyIdentifier" => $this->get_business_id(),
            ],
            "woocommerce" => [
                "wc_order_id" => $this->order->get_id(),
                "wc_user_id" => $this->order->get_user_id(),
            ],
            "laskurivit" => $this->invoice_rows,
            "wc_api_version" => 3,
        ];
    }
}

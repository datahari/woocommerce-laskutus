let laskuhari_viime_maksutapa = null;
let laskuhari_viime_laskutustapa = "";

(function($) {
    function init_select2() {
        try {
            $(".lh-select2").select2();
        } catch( error ) {
            console.log( "Notice: Select2 not available" );
        }
    }

    function laskuhari_invoicing_method_field() {
        return $("#laskuhari-laskutustapa, .woocommerce-checkout :input[name*='laskutustapa']").first();
    }

    function laskuhari_business_id_field() {
        const selector = "#laskuhari-ytunnus, .woocommerce-checkout :input[name*='" + laskuhariInfo.vat_number_fields.join("'], .woocommerce-checkout :input[name*='")+"']";
        return $(selector).first();
    }

    function laskuhari_einvoice_address_field() {
        const selector = "#laskuhari-verkkolaskuosoite, .woocommerce-checkout :input[name*='" + laskuhariInfo.einvoice_address_fields.join("'], .woocommerce-checkout :input[name*='")+"']";
        return $(selector).first();
    }

    function laskuhari_einvoice_operator_field() {
        const selector = "#laskuhari-verkkolaskuosoite, .woocommerce-checkout :input[name*='" + laskuhariInfo.einvoice_operator_fields.join("'], .woocommerce-checkout :input[name*='")+"']";
        return $(selector).first();
    }

    function laskuhari_changed_send_method() {
        const laskutustapa = laskuhari_invoicing_method_field().val();

        if( laskutustapa == "verkkolasku" ) {
            $("#laskuhari-verkkolasku-tiedot").show();
            $(".verkkolasku-pakollinen").attr("required", true);
        } else {
            $("#laskuhari-verkkolasku-tiedot").hide();
            $(".verkkolasku-pakollinen").attr("required", false);
        }

        if( laskutustapa == "email" ) {
            $("#laskuhari-sahkoposti-tiedot").show();
            $("#laskuhari-email").attr("required", true);
        } else {
            $("#laskuhari-sahkoposti-tiedot").hide();
            $("#laskuhari-email").attr("required", false);
        }

        if( laskutustapa == "kirje" ) {
            $("#laskuhari-kirje-tiedot").show();
        } else {
            $("#laskuhari-kirje-tiedot").hide();
        }

        if( laskutustapa !== laskuhari_viime_laskutustapa ) {
            $("body").trigger("update_checkout");
        }

        laskuhari_viime_laskutustapa = laskutustapa;
    }

    function laskuhari_tarkista_laskutustapa() {
        if( ! $("#payment_method_laskuhari").is(":checked") ) {
            return null;
        }

        const laskutustapa = laskuhari_invoicing_method_field().val();

        let virhe = null;

        if( ! laskutustapa ) {
            virhe = "Ole hyvä ja valitse laskutustapa";
        } else if( laskutustapa === "verkkolasku" ) {
            const ytunnus = laskuhari_business_id_field().val();
            const verkkolaskuosoite = laskuhari_einvoice_address_field().val();
            const valittajatunnus = laskuhari_einvoice_operator_field().val();

            if( ytunnus == "" ) {
                virhe = "Syötä y-tunnuksesi, jotta voimme lähettää sinulle verkkolaskun";
            } else if( verkkolaskuosoite == "" ) {
                virhe = "Syötä verkkolaskuosoitteesi, jotta voimme lähettää sinulle verkkolaskun";
            } else if( valittajatunnus == "" ) {
                virhe = "Syötä välittäjätunnus, jotta voimme lähettää sinulle verkkolaskun";
            }
        }

        if( virhe ) {
            $("#place_order").addClass("laskuhari-place-order-disabled");
        } else {
            $("#place_order").removeClass("laskuhari-place-order-disabled");
        }

        return virhe;
    }

    $(document).ready(function() {
        $("body").on("keyup change", function( e ) {
            const target = $(e.target);

            if(
                target.closest(".verkkolasku-pakollinen").length ||
                target.closest(".woocommerce-checkout").length
            ) {
                laskuhari_tarkista_laskutustapa();
            }

            if( target.is( laskuhari_invoicing_method_field() ) ) {
                laskuhari_changed_send_method();
            }
        });

        $("body").bind("updated_checkout payment_method_selected", function(){
            laskuhari_tarkista_laskutustapa();
            init_select2();
        });

        $(".woocommerce-checkout").on("checkout_place_order", function() {
            if( $(".laskuhari-place-order-disabled").length ) {
                const laskutustapa = laskuhari_invoicing_method_field().val();

                if( laskutustapa == "" ) {
                    alert( "Valitse laskutustapa" );
                } else if( laskutustapa == "verkkolasku" ) {
                    const laskutustapa_virhe = laskuhari_tarkista_laskutustapa();
                    if( laskutustapa_virhe !== null ) {
                        alert( laskutustapa_virhe );
                    }
                }

                return false;
            }
        });

        $("body").on("change click", "#payment_method_laskuhari, #payment", function() {
            laskuhari_tarkista_laskutustapa();

            if( laskuhari_viime_maksutapa !== null && $("#payment_method_laskuhari").prop("checked") != laskuhari_viime_maksutapa ) {
                console.log("Maksutapa vaihtui", laskuhari_viime_maksutapa, $("#payment_method_laskuhari").prop("checked"));
                $('body').trigger('update_checkout');
            }

            laskuhari_viime_maksutapa = $("#payment_method_laskuhari").prop("checked");
        });

        if( $("#laskuhari-laskutustiedot-form").length ) {
            laskuhari_changed_send_method();
        }

        init_select2();
    });
})(jQuery);

if( laskuhariInfo.cron_needs_to_run === "yes" ) {
    fetch( laskuhariInfo.cron_url )
    .then( response => {
        if( ! response.ok) {
            console.error( "Laskuhari: Failed to trigger WP-Cron." );
        }
    } )
    .catch( error  => {
        console.error( "Laskuhari: Error triggering WP-Cron:", error );
    } );
}

var laskuhari_viime_maksutapa = false;

(function($) {
    var laskuhari_updating_checkout = false;

    function laskuhari_changed_send_method() {
        if( $("#laskuhari-laskutustapa").val() == "verkkolasku" ) {
            $("#laskuhari-verkkolasku-tiedot").show();
            $(".verkkolasku-pakollinen").attr("required", true);
        } else {
            $("#laskuhari-verkkolasku-tiedot").hide();
            $(".verkkolasku-pakollinen").attr("required", false);
        }
        if( $("#laskuhari-laskutustapa").val() == "email" ) {
            $("#laskuhari-sahkoposti-tiedot").show();
            $("#laskuhari-email").attr("required", true);
        } else {
            $("#laskuhari-sahkoposti-tiedot").hide();
            $("#laskuhari-email").attr("required", false);
        }

        if( ! laskuhari_updating_checkout ) {
            laskuhari_updating_checkout = true;
            $('body').trigger('update_checkout');
        }
    }
    function laskuhari_tarkista_laskutustapa(){
        if( $("#payment_method_laskuhari").is(":checked") ) {
            if( $("#laskuhari-laskutustapa").val() == "" || ($("#laskuhari-laskutustapa").val() == "verkkolasku" && $("#laskuhari-ytunnus").val() == "")) {
                $("#place_order").addClass("laskuhari-place-order-disabled");
            } else {
                $("#place_order").removeClass("laskuhari-place-order-disabled");
            }
        }
    }
	$(document).ready(function() {
        $("body").on("keyup change", ".verkkolasku-pakollinen", function(){
            laskuhari_tarkista_laskutustapa();
        });
        $("body").bind("updated_checkout", function(){
            laskuhari_tarkista_laskutustapa();
            setTimeout(function() {
                laskuhari_updating_checkout = false;
            }, 1000);
        });
        $(".woocommerce-checkout").on("checkout_place_order", function() {
            if( $(".laskuhari-place-order-disabled").length ) {
                if( $("#laskuhari-laskutustapa").val() == "" ) {
                    alert("Valitse laskutustapa");
                } else if( $("#laskuhari-laskutustapa").val() == "verkkolasku" && $("#laskuhari-ytunnus").val() == "" ) {
                    alert("Syötä vähintään Y-tunnus verkkolaskun lähetystä varten");
                }
                return false;
            }
        });
        $("body").on("change click", "#payment_method_laskuhari, #payment", function() {
            laskuhari_tarkista_laskutustapa();
            if( $("#payment_method_laskuhari").prop("checked") != laskuhari_viime_maksutapa ) {
                $('body').trigger('update_checkout');
                laskuhari_viime_maksutapa = $("#payment_method_laskuhari").prop("checked");
            }
        });
        $("body").on("keyup change", "#laskuhari-laskutustapa", function() {
            laskuhari_changed_send_method();
        });
    });
})(jQuery);
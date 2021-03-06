var laskuhari_viime_maksutapa = false;

(function($) {
    function laskuhari_tarkista_verkkolaskuosoite() {
        if( $("#laskuhari-laskutustapa").val() == "verkkolasku" ) {
            $("#laskuhari-verkkolasku-tiedot").show();
            $(".verkkolasku-pakollinen").attr("required", true);
        } else {
            $("#laskuhari-verkkolasku-tiedot").hide();
            $(".verkkolasku-pakollinen").attr("required", false);
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
            laskuhari_tarkista_verkkolaskuosoite();
        });
    });
})(jQuery);
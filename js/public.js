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
                $("#place_order:enabled").prop("disabled", true).addClass("laskuhari-place-order-disabled");
            } else {
                $("#place_order.laskuhari-place-order-disabled").prop("disabled", false).removeClass("laskuhari-place-order-disabled");
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
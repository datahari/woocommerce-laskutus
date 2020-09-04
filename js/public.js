var laskuhari_viime_maksutapa = false;

function laskuhari_tarkista_verkkolaskuosoite($) {
	if( typeof laskuhari_tarkista_laskutustapa === "function" ) laskuhari_tarkista_laskutustapa($);
	if( $("#laskuhari-laskutustapa").val() == "verkkolasku" ) {
		$("#laskuhari-verkkolasku-tiedot").show();
		$(".verkkolasku-pakollinen").attr("required", true);
	} else {
		$("#laskuhari-verkkolasku-tiedot").hide();
		$(".verkkolasku-pakollinen").attr("required", false);
	}
}

function laskuhari_tarkista_laskutustapa($){
    if( $("#payment_method_laskuhari").is(":checked") ) {
        if( $("#laskuhari-laskutustapa").val() == "" || ($("#laskuhari-laskutustapa").val() == "verkkolasku" && $("#laskuhari-ytunnus").val() == "")) {
            $("#place_order").prop("disabled", true);
        } else {
            $("#place_order").prop("disabled", false);
        }
    }
}

(function($) {
	$(document).ready(function() {
        $(".verkkolasku-pakollinen").bind("keyup change", function(){
            laskuhari_tarkista_laskutustapa($);
        });
        $("#payment_method_laskuhari, #payment").on("change click", function() {
            if( $("#payment_method_laskuhari").prop("checked") != laskuhari_viime_maksutapa ) {
                $('body').trigger('update_checkout');
                laskuhari_viime_maksutapa = $("#payment_method_laskuhari").prop("checked");
            }
        });
    });
})(jQuery);
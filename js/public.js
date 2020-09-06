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

var lh_place_order_original_value = null;
function laskuhari_tarkista_laskutustapa($){
    if( lh_place_order_original_value === null ) {
        lh_place_order_original_value = $("#place_order").prop("disabled");
    }
    $("#place_order").prop("disabled", lh_place_order_original_value);
    if( $("#payment_method_laskuhari").is(":checked") ) {
        if( $("#laskuhari-laskutustapa").val() == "" || ($("#laskuhari-laskutustapa").val() == "verkkolasku" && $("#laskuhari-ytunnus").val() == "")) {
            $("#place_order").prop("disabled", true);
        } else {
            $("#place_order").prop("disabled", lh_place_order_original_value);
        }
    }
}

(function($) {
	$(document).ready(function() {
        $(".verkkolasku-pakollinen").bind("keyup change", function(){
            laskuhari_tarkista_laskutustapa($);
        });
    });
})(jQuery);
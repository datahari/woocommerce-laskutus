(function($) {
	$(document).ready(function() {
		if( $("body.post-type-shop_order").length ) {
			$("#bulk-action-selector-top").append('<option value="laskuhari-batch-send">Laskuta valitut tilaukset</option>');
			$("#doaction").click(function() {
				if($("#bulk-action-selector-top").val() == "laskuhari-batch-send") {
					laskuhari_loading();
				}
			});
		}
	});
})(jQuery);

var viime_maksutapa = false;

function laskuhari_loading() {
	jQuery("body").append('<div class=".blockUI" id="laskuhari-loading"></div>');
}

function tarkista_verkkolaskuosoite($) {
	if( typeof tarkista_laskutustapa === "function" ) tarkista_laskutustapa($);
	if( $("#laskuhari-laskutustapa").val() == "verkkolasku" ) {
		$("#laskuhari-verkkolasku-tiedot").show();
		$(".verkkolasku-pakollinen").attr("required", true);
	} else {
		$("#laskuhari-verkkolasku-tiedot").hide();
		$(".verkkolasku-pakollinen").attr("required", false);
	}
}

function laskuhari_admin_lahetys() {
	$ = jQuery;
	var errors = false;
	$( ".laskuhari-pakollinen:visible" ).each(function() {
		if( $(this).val() == "" ) {
			errors = true;
			alert("Täytä pakolliset kentät!");
			return false;
		}
	});
	if( errors ) {
		return false;
	}
	if( ! confirm('Haluatko varmasti lähettää tämän laskun?') ) {
		return false;
	}
	laskuhari_loading();
	var urli = window.location.href.split("#");
	urli = urli[0];
	if( urli.indexOf("?") === -1 ) {
		urli = urli + '?';
	} else {
		urli = urli + '&';
	}
	window.location.href=urli+'laskuhari_send_invoice=current&laskuhari-laskutustapa='+encodeURIComponent(jQuery('#laskuhari-laskutustapa').val())+'&laskuhari-ytunnus='+encodeURIComponent(jQuery('#laskuhari-ytunnus').val())+'&laskuhari-verkkolaskuosoite='+encodeURIComponent(jQuery('#laskuhari-verkkolaskuosoite').val())+'&laskuhari-valittaja='+encodeURIComponent(jQuery('#laskuhari-valittaja').val());
}
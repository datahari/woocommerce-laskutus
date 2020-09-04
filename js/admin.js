(function($) {
	$(document).ready(function() {
		$("#doaction").click(function() {
			if($("#bulk-action-selector-top").val() == "laskuhari-batch-send") {
				laskuhari_loading();
			}
		});
	});
})(jQuery);

function laskuhari_loading() {
	jQuery("body").append('<div class=".blockUI" id="laskuhari-loading"></div>');
}

function laskuhari_admin_lahetys() {
	var $ = jQuery;
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

	var laskutustapa      = $('#laskuhari-laskutustapa').val();
	var ytunnus           = $('#laskuhari-ytunnus').val();
	var verkkolaskuosoite = $('#laskuhari-verkkolaskuosoite').val();
	var valittajatunnus   = $('#laskuhari-valittaja').val();

	window.location.href = urli+
		'laskuhari_send_invoice=current&laskuhari-laskutustapa='+encodeURIComponent(laskutustapa)+
		'&laskuhari-ytunnus='+encodeURIComponent(ytunnus)+
		'&laskuhari-verkkolaskuosoite='+encodeURIComponent(verkkolaskuosoite)+
		'&laskuhari-valittaja='+encodeURIComponent(valittajatunnus);
}
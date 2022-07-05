(function($) {
	$(document).ready(function() {
		$("body").on("click", "#doaction", function() {
			if($("#bulk-action-selector-top").val() == "laskuhari-batch-send") {
				laskuhari_loading();
			}
		});
		$("body").on("click", ".laskuhari-nappi.uusi-lasku", function() {
			$('#laskuhari-laheta-lasku-lomake').slideUp();
			$("#laskuhari-lahetystapa-lomake").appendTo( $("#lahetystapa-lomake2") );
			$("#laskuhari-tee-lasku-lomake").slideToggle();
			return false;
		});
		$("body").on("click", ".laskuhari-nappi.laheta-lasku", function() {
			$('#laskuhari-laheta-lasku-lomake').slideToggle();
			$("#laskuhari-lahetystapa-lomake").appendTo( $("#lahetystapa-lomake1") );
			$("#laskuhari-tee-lasku-lomake").slideUp();
			return false;
		});
		$("body").on("click change", "#laskuhari-send-check", function() {
			if( $("#laskuhari-send-check").is(":checked") ) {
				$("#laskuhari-lahetystapa-lomake").appendTo( $("#lahetystapa-lomake2") );
				$("#laskuhari-create-only").slideUp();
				$("#laskuhari-create-and-send-method").slideDown(function() {
				});
			} else {
				$("#laskuhari-lahetystapa-lomake").appendTo( $("#lahetystapa-lomake1") );
				$("#laskuhari-create-only").slideDown();
				$("#laskuhari-create-and-send-method").slideUp();
			}
		});
		$("body").on( "click", ".laskuhari-sidebutton", function() {
			$("#"+$(this).attr("data-toggle")).slideToggle();
			return false;
		} );
		$("body").on( "click", ".laskuhari-sidebutton-menu a", function() {
			$("#"+$(this).closest(".laskuhari-sidebutton-menu").attr("id")).slideUp();
		} );
	});
})(jQuery);

function laskuhari_loading() {
	jQuery("body").append('<div class=".blockUI" id="laskuhari-loading"></div>');
}

function laskuhari_admin_action( action ) {
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
	if( action === "send" && ! confirm('Haluatko varmasti lähettää laskun?') ) {
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
	var maksuehto         = $('#laskuhari-maksuehto').val();
	var ytunnus           = $('#laskuhari-ytunnus').val();
	var verkkolaskuosoite = $('#laskuhari-verkkolaskuosoite').val();
	var valittajatunnus   = $('#laskuhari-valittaja').val();
	var viitteenne        = $('#laskuhari-viitteenne').val();
	var email             = $('#laskuhari-email').val();

	window.location.href = urli+
		'laskuhari='+action+
		'&laskuhari-laskutustapa='+encodeURIComponent(laskutustapa)+
		'&laskuhari-maksuehto='+encodeURIComponent(maksuehto)+
		'&laskuhari-ytunnus='+encodeURIComponent(ytunnus)+
		'&laskuhari-verkkolaskuosoite='+encodeURIComponent(verkkolaskuosoite)+
		'&laskuhari-valittaja='+encodeURIComponent(valittajatunnus)+
		'&laskuhari-viitteenne='+encodeURIComponent(viitteenne)+
		'&laskuhari-email='+encodeURIComponent(email);
}
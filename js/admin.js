(function($) {
	$(document).ready(function() {
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
		$("body").on( "submit", "#posts-filter", handle_laskuhari_action ); // legacy
		$("body").on( "submit", "#wc-orders-filter", handle_laskuhari_action );
		$("body").on( "click", ".lh-show-debug-summary", function() {
			laskuhari_loading();
			$.ajax({
				url: ajaxurl,
				type: 'post',
				dataType: 'json',
				data: {
					action: 'get_troubleshooting_summary'
				},
				success: function( response ) {
					laskuhari_loading_stop();
					if( response.success ) {
						$(".lh-debug-summary-modal").remove();
						$("body").append( `
							<div class="lh-debug-summary-modal">
								<h2>Laskuhari-vianselvitys</h2>
								<p>Kopioi alla olevat tiedot ja välitä ne Laskuharin asiakaspalveluun, kun ilmoitat ongelmasta lisäosassa. Näiden tietojen avulla pystymme paremmin selvittää, mikä on vialla.</p>
								<textarea readonly class="lh-debug-summary">${response.data}</textarea>
								<button class="button-primary lh-close-debug-summary">Sulje</button>
								<button class="button-secondary lh-download-debug-summary">Lataa</button>
							</div>
						` );
					} else {
						alert( response.data );
					}
				},
			}).always( function() {
				laskuhari_loading_stop();
			} );

			return false;
		});
		$("body").on( "click", function(e) {
			if( ! $(e.target).closest(".lh-debug-summary-modal").length ) {
				lh_hide_debug_summary();
			}
		} );
		$("body").on( "focus", ".lh-debug-summary", function() {
			$(this).select();
		} );
		$("body").on( "click", ".lh-close-debug-summary", function() {
			lh_hide_debug_summary();
		} );
		$("body").on( "click", ".lh-download-debug-summary", function() {
			let blob = new Blob( [$(".lh-debug-summary").val()], {type: "text/plain"} );
			let url = URL.createObjectURL( blob );
			let link = document.createElement( "a" );
			link.href = url;
			link.download = "laskuhari-vianselvitys.txt";
			link.click();
		} );
	});

	function lh_hide_debug_summary() {
		$(".lh-debug-summary-modal").fadeOut( function() {
			$(this).remove();
		} );
	}
})(jQuery);

function laskuhari_loading() {
	jQuery("body").append('<div class=".blockUI" id="laskuhari-loading"></div>');
}

function laskuhari_loading_stop() {
	jQuery("#laskuhari-loading").fadeOut( function() {
		jQuery(this).remove();
	} );
}

function laskuhari_admin_action( action ) {
	var $ = jQuery;

	var laskutustapa = $('#laskuhari-laskutustapa').val();

	if( laskutustapa === "" && action === "send" ) {
		alert( "Valitse laskutustapa!" );
		return false;
	}

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

	var maksuehto         = $('#laskuhari-maksuehto').val();
	var ytunnus           = $('#laskuhari-ytunnus').val();
	var verkkolaskuosoite = $('#laskuhari-verkkolaskuosoite').val();
	var valittajatunnus   = $('#laskuhari-valittaja').val();
	var viitteenne        = $('#laskuhari-viitteenne').val();
	var email             = $('#laskuhari-email').val();

	window.location.href = urli+
		'laskuhari='+action+
		'&_lhnonce='+encodeURIComponent(laskuhariInfo.nonce)+
		'&laskuhari-laskutustapa='+encodeURIComponent(laskutustapa)+
		'&laskuhari-maksuehto='+encodeURIComponent(maksuehto)+
		'&laskuhari-ytunnus='+encodeURIComponent(ytunnus)+
		'&laskuhari-verkkolaskuosoite='+encodeURIComponent(verkkolaskuosoite)+
		'&laskuhari-valittaja='+encodeURIComponent(valittajatunnus)+
		'&laskuhari-viitteenne='+encodeURIComponent(viitteenne)+
		'&laskuhari-email='+encodeURIComponent(email);
}

function laskuhari_no_address_confirm( warning ) {
	return confirm( warning );
}

function laskuhari_no_address_confirm_send( warning_email, warning_einvoice_letter ) {
	const $ = jQuery;

	if( $('#laskuhari-laskutustapa').val() === "" ) {
		alert( "Valitse laskutustapa!" );
		return false;
	}

	if( $('#laskuhari-laskutustapa').val() === "email" ) {
		return confirm( warning_email );
	} else {
		alert( warning_einvoice_letter );
		return false;
	}
}

function handle_laskuhari_action() {
	const action = jQuery( "#bulk-action-selector-top" ).val();

	if( action.indexOf( "laskuhari_batch_send" ) === 0 && ! confirm( "Haluatko varmasti luoda ja LÄHETTÄÄ laskut valituista tilauksista?" ) ) {
		return false;
	}

	if( action.indexOf( "laskuhari_batch_create" ) === 0 && ! confirm( "Haluatko varmasti luoda laskut valituista tilauksista? (laskuja ei lähetetä)" ) ) {
		return false;
	}

	if( action.indexOf( "laskuhari" ) === 0 ) {
		laskuhari_loading();
	}
}

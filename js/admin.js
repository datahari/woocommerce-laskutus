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
		$("body").on( "click", "#laskuhari-add-webhook-button", async function() {
			if( ! await laskuhari_confirm( "Haluatko varmasti lisätä webhookin?", "Lisää webhook" ) ) {
				return false;
			}

			laskuhari_loading();
			$.ajax({
				url: ajaxurl,
				type: 'post',
				dataType: 'json',
				data: {
					action: 'laskuhari_add_webhooks',
					nonce: laskuhariInfo.wpnonce
				},
				success: function( response ) {
					laskuhari_loading_stop();
					if( response.success ) {
						$( "#laskuhari-delete-webhook-button" ).removeClass( "laskuhari-hidden" );
						$( "#laskuhari-add-webhook-button" ).addClass( "laskuhari-hidden" );
						laskuhari_flash_card( "Webhook lisätty onnistuneesti.", "success" );
					} else {
						laskuhari_error( "Virhe: " + response.data );
					}
				},
				error: function() {
					laskuhari_error( "Virhe lisättäessä webhookia" );
				},
			}).always( function() {
				laskuhari_loading_stop();
			} );

			return false;
		});
		$("body").on( "click", "#laskuhari-delete-webhook-button", async function() {
			if( ! await laskuhari_confirm( "Haluatko varmasti poistaa webhookin?", "Poista webhook" ) ) {
				return false;
			}

			laskuhari_loading();
			$.ajax({
				url: ajaxurl,
				type: 'post',
				dataType: 'json',
				data: {
					action: 'laskuhari_delete_webhooks',
					nonce: laskuhariInfo.wpnonce
				},
				success: function( response ) {
					laskuhari_loading_stop();
					if( response.success ) {
						$( "#laskuhari-delete-webhook-button" ).addClass( "laskuhari-hidden" );
						$( "#laskuhari-add-webhook-button" ).removeClass( "laskuhari-hidden" );
						laskuhari_flash_card( "Webhook poistettu onnistuneesti.", "success" );
					} else {
						laskuhari_error( "Virhe: " + response.data );
					}
				},
				error: function() {
					laskuhari_error( "Virhe webhookin poistossa" );
				},
			}).always( function() {
				laskuhari_loading_stop();
			} );

			return false;
		});
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

async function laskuhari_confirm( message, ok_label = "OK", cancel_label = "Peruuta", type = "confirm" ) {
	const $ = jQuery;

	return await new Promise( function( resolve ) {
		$(".lh-confirm-modal").remove();
		$(".lh-error-modal").remove();

		const is_error = type === "error";
		const show_cancel = cancel_label !== null;
		const role = is_error ? "alertdialog" : "dialog";
		const notice_html = is_error
			? '<div class="notice notice-error inline" style="margin:0 0 12px; padding:8px 12px;"><p style="margin:0;">' + $("<div>").text( message ).html() + '</p></div>'
			: '<p class="lh-confirm-message">' + $("<div>").text( message ).html() + '</p>';
		const cancel_button_html = show_cancel
			? '<button type="button" class="button button-secondary lh-confirm-cancel">' + $("<div>").text( cancel_label ).html() + '</button>'
			: '';
		const ok_button_class = is_error ? ' lh-confirm-ok-error' : '';

		const modal = $(
			'<div class="lh-confirm-modal' + ( is_error ? ' lh-error-modal' : '' ) + '">' +
				'<div role="' + role + '" aria-modal="true" class="lh-confirm-dialog">' +
					notice_html +
					'<p class="lh-confirm-actions">' +
						cancel_button_html +
						'<button type="button" class="button button-primary lh-confirm-ok' + ok_button_class + '">' + $("<div>").text( ok_label ).html() + '</button>' +
					'</p>' +
				'</div>' +
			'</div>'
		);

		function close_modal( value ) {
			$(document).off( "keydown.lhConfirmModal" );
			modal.remove();
			resolve( value );
		}

		modal.on( "click", ".lh-confirm-cancel", function() {
			close_modal( false );
		} );

		modal.on( "click", ".lh-confirm-ok", function() {
			close_modal( true );
		} );

		modal.on( "click", function( event ) {
			if( event.target === this ) {
				close_modal( show_cancel ? false : true );
			}
		} );

		$(document).on( "keydown.lhConfirmModal", function( event ) {
			if( event.key === "Escape" ) {
				close_modal( show_cancel ? false : true );
			}

			if( event.key === "Enter" && ! show_cancel ) {
				close_modal( true );
			}
		} );

		$("body").append( modal );
		modal.find( ".lh-confirm-ok" ).trigger( "focus" );
	} );
}

async function laskuhari_error( message, ok_label = "OK" ) {
	return await laskuhari_confirm( message, ok_label, null, "error" );
}

function laskuhari_flash_card( message, type = "info", duration = 4000 ) {
	const $ = jQuery;
	const safe_message = $("<div>").text( message ).html();

	let container = $(".lh-flash-card-container");
	if( container.length === 0 ) {
		$("body").append( '<div class="lh-flash-card-container" aria-live="polite" aria-atomic="true"></div>' );
		container = $(".lh-flash-card-container");
	}

	const flash_card = $(
		'<div class="lh-flash-card lh-flash-card-' + type + '">' +
			'<span class="lh-flash-card-message">' + safe_message + '</span>' +
		'</div>'
	);

	container.append( flash_card );

	window.setTimeout( function() {
		flash_card.addClass( "lh-flash-card-hide" );
		window.setTimeout( function() {
			flash_card.remove();
		}, 260 );
	}, duration );
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

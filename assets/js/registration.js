/**
 * Ukrywa pola formularza rejestracji, które nie należą do wybranej profesji.
 *
 * Skrypt tylko ukrywa. Bez niego formularz pokazuje pola wszystkich profesji, a serwer
 * i tak odsiewa wartości po macierzy, więc wynik zapisu nie zależy od tego pliku.
 */
( function () {
	'use strict';

	var config = window.pusersRegistration;
	if ( ! config || ! config.map ) {
		return;
	}

	var select = document.getElementById( config.professionField );
	if ( ! select || ! select.form ) {
		return;
	}

	var wrappers = select.form.querySelectorAll( '[data-pusers-field]' );
	var initialRequired = new Map();

	Array.prototype.forEach.call( wrappers, function ( wrapper ) {
		Array.prototype.forEach.call( wrapper.querySelectorAll( 'input, select, textarea' ), function ( control ) {
			initialRequired.set( control, control.required );
		} );
	} );

	function isRequirable( control ) {
		return [ 'hidden', 'checkbox', 'radio' ].indexOf( control.type ) === -1;
	}

	function apply() {
		var states = config.map[ select.value ];

		Array.prototype.forEach.call( wrappers, function ( wrapper ) {
			var controls = wrapper.querySelectorAll( 'input, select, textarea' );

			// Profesja niewybrana: stan jak z serwera, nic nie ukrywamy.
			if ( ! states ) {
				wrapper.hidden = false;
				Array.prototype.forEach.call( controls, function ( control ) {
					control.required = initialRequired.get( control );
				} );
				return;
			}

			var state = states[ wrapper.getAttribute( 'data-pusers-field' ) ];

			// Ukryte pole nie może blokować wysłania własnym atrybutem required.
			wrapper.hidden = ! state;
			Array.prototype.forEach.call( controls, function ( control ) {
				if ( isRequirable( control ) ) {
					control.required = state === 'required';
				}
			} );
		} );
	}

	select.addEventListener( 'change', apply );
	apply();
}() );

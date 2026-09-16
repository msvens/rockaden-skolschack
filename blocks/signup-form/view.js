/**
 * Front-end behaviour for the signup form.
 *
 * No build step and no dependencies, mirroring the club theme's blocks. Every message is read from
 * a data-* attribute, translated server-side, so this file contains no user-facing text.
 *
 * Four things differ from the theme's feedback form on purpose. The security token and the school
 * list are fetched on load rather than printed into the page, so the form still works when the page
 * is served from a cache. A failed submission reads the response body and shows the specific
 * message, because a parent told only "something went wrong" cannot act on it. The server names the
 * field it rejected, so that field is marked and focused rather than left for the reader to find in
 * a form this long. And the same checks run here as the reader leaves each field, so a mistyped
 * personnummer is caught where it was typed instead of after pressing the button.
 *
 * On that last point: these rules are a copy of the server's, which is a cost worth naming. They
 * are kept honest by taking every message from the server's own catalogue, and by the server
 * checking all of it again on submission. Nothing here decides whether a signup is accepted; it
 * only decides when the reader finds out.
 */
( function () {
	'use strict';

	var forms = document.querySelectorAll( '.rsk-signup' );

	if ( ! forms.length ) {
		return;
	}

	/** The Luhn check a Swedish personnummer carries, over its last ten digits. */
	function luhnIsValid( digits ) {
		var sum = 0;

		for ( var i = 0; i < 10; i++ ) {
			var digit = parseInt( digits.charAt( digits.length - 10 + i ), 10 );

			if ( i % 2 === 0 ) {
				digit *= 2;
			}

			sum += digit > 9 ? digit - 9 : digit;
		}

		return sum % 10 === 0;
	}

	/** Whether eight digits are a date that exists, matching the server's floor of 1900. */
	function isRealDate( yyyymmdd ) {
		var year = parseInt( yyyymmdd.slice( 0, 4 ), 10 );
		var month = parseInt( yyyymmdd.slice( 4, 6 ), 10 );
		var day = parseInt( yyyymmdd.slice( 6, 8 ), 10 );

		if ( year < 1900 || month < 1 || month > 12 || day < 1 ) {
			return false;
		}

		// Day 0 of the next month is the last day of this one.
		return day <= new Date( year, month, 0 ).getDate();
	}

	/**
	 * WordPress's own is_email rule, followed step for step.
	 *
	 * Approximating it was worse than useless: a looser pattern here passes an address the
	 * server then refuses, and a stricter one refuses an address the server would have taken.
	 * Both leave the reader with a field that disagrees with itself. The odd-looking parts are
	 * WordPress's, not ours: the six-character floor, and a local part that is ASCII only, which
	 * is why an address beginning with å is rejected.
	 */
	function looksLikeEmail( value ) {
		if ( value.length < 6 || value.indexOf( '@', 1 ) === -1 ) {
			return false;
		}

		var at = value.lastIndexOf( '@' );
		var local = value.slice( 0, at );
		var domain = value.slice( at + 1 );

		if ( ! /^[a-zA-Z0-9!#$%&'*+/=?^_`{|}~.-]+$/.test( local ) ) {
			return false;
		}

		if ( domain.indexOf( '..' ) !== -1 || /^[.-]|[.-]$/.test( domain ) ) {
			return false;
		}

		var labels = domain.split( '.' );

		if ( labels.length < 2 ) {
			return false;
		}

		return labels.every( function ( label ) {
			return /^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/i.test( label );
		} );
	}

	Array.prototype.forEach.call( forms, function ( form ) {
		var contextUrl = form.getAttribute( 'data-context-url' );
		var submitUrl = form.getAttribute( 'data-submit-url' );
		var status = form.querySelector( '.rsk-signup__status' );
		var submit = form.querySelector( '.rsk-signup__submit' );
		var school = form.querySelector( 'select[name="school_id"]' );
		var nonce = '';

		if ( ! contextUrl || ! submitUrl || ! status || ! submit || ! school ) {
			return;
		}

		var msg = function ( name ) {
			return form.getAttribute( 'data-msg-' + name ) || '';
		};

		/**
		 * What is wrong with one field, or an empty string when nothing is.
		 *
		 * Blank optional fields pass: the reader has not filled them in yet, and saying so while
		 * they are still working through the form would be nagging rather than helping.
		 */
		var rules = {
			first_name: function ( v ) {
				return v === '' ? msg( 'first-name' ) : '';
			},
			last_name: function ( v ) {
				return v === '' ? msg( 'last-name' ) : '';
			},
			personnummer: function ( v ) {
				if ( v === '' ) {
					return '';
				}

				// The server strips anything that is not a digit before looking, so a hyphen or
				// a space must be just as acceptable here.
				var digits = v.replace( /\D+/g, '' );

				if ( ! /^\d{12}$/.test( digits ) ) {
					return msg( 'pnr-format' );
				}

				if ( ! isRealDate( digits.slice( 0, 8 ) ) ) {
					return msg( 'pnr-date' );
				}

				return luhnIsValid( digits ) ? '' : msg( 'pnr-checksum' );
			},
			postal_code: function ( v ) {
				return v === '' || /^\d{5}$/.test( v ) ? '' : msg( 'postal' );
			},
			school_id: function ( v ) {
				return v === '' ? msg( 'school' ) : '';
			},
			guardian1_email: function ( v ) {
				return v === '' || looksLikeEmail( v ) ? '' : msg( 'email' ).replace( '%s', v );
			},
			guardian2_email: function ( v ) {
				return v === '' || looksLikeEmail( v ) ? '' : msg( 'email' ).replace( '%s', v );
			},
		};

		function setStatus( message, state ) {
			status.textContent = message || '';
			status.className = 'rsk-signup__status' + ( state ? ' is-' + state : '' );
		}

		/** The slot under a field where its own message goes, created the first time it is needed. */
		function errorSlot( input ) {
			var field = input.closest( '.rsk-signup__field' );

			if ( ! field ) {
				return null;
			}

			var slot = field.querySelector( '.rsk-signup__error' );

			if ( ! slot ) {
				slot = document.createElement( 'span' );
				slot.className = 'rsk-signup__error';
				slot.id = ( input.id || input.name ) + '-error';
				field.appendChild( slot );
			}

			return slot;
		}

		function showProblem( input, message ) {
			var slot = errorSlot( input );

			if ( message ) {
				input.classList.add( 'is-invalid' );
				input.setAttribute( 'aria-invalid', 'true' );

				if ( slot ) {
					slot.textContent = message;
					input.setAttribute( 'aria-errormessage', slot.id );
				}

				return;
			}

			input.classList.remove( 'is-invalid' );
			input.removeAttribute( 'aria-invalid' );
			input.removeAttribute( 'aria-errormessage' );

			if ( slot ) {
				slot.textContent = '';
			}
		}

		/** Check one field and show the outcome. Returns its message, or an empty string. */
		function checkField( input ) {
			var rule = rules[ input.name ];
			var message = rule ? rule( input.value.trim() ) : '';

			showProblem( input, message );

			return message;
		}

		function clearInvalid() {
			Array.prototype.forEach.call(
				form.querySelectorAll( '.is-invalid' ),
				function ( element ) {
					showProblem( element, '' );
				}
			);
		}

		// The server's field names match the input names, so the offending input can be pointed at
		// directly. Opening the disclosure first matters: a second guardian's bad address is
		// otherwise marked inside a section the reader cannot see.
		function markInvalid( field, message ) {
			var input = field && form.querySelector( '[name="' + field + '"]' );

			if ( ! input ) {
				return;
			}

			var section = input.closest( 'details' );

			if ( section ) {
				section.open = true;
			}

			showProblem( input, message );
			input.focus();
		}

		// Checked when the reader leaves a field rather than on every keystroke: being corrected
		// halfway through typing a number nobody has finished is worse than not being corrected.
		// Once a field is marked, though, it clears as soon as the typing makes it valid.
		Object.keys( rules ).forEach( function ( name ) {
			var input = form.querySelector( '[name="' + name + '"]' );

			if ( ! input ) {
				return;
			}

			input.addEventListener( 'blur', function () {
				checkField( input );
			} );

			input.addEventListener( 'input', function () {
				if ( input.classList.contains( 'is-invalid' ) ) {
					checkField( input );
				}
			} );

			if ( input.tagName === 'SELECT' ) {
				input.addEventListener( 'change', function () {
					checkField( input );
				} );
			}
		} );

		function fillSchools( schools ) {
			school.innerHTML = '';

			var placeholder = document.createElement( 'option' );
			placeholder.value = '';
			placeholder.textContent = form.getAttribute( 'data-choose-school' ) || '';
			school.appendChild( placeholder );

			schools.forEach( function ( item ) {
				var option = document.createElement( 'option' );
				option.value = item.id;
				option.textContent = item.name;
				school.appendChild( option );
			} );
		}

		// Both the token and the school list come from here, so neither can be stale behind a
		// page cache. The form stays disabled until it arrives.
		submit.disabled = true;

		fetch( contextUrl, { credentials: 'same-origin' } )
			.then( function ( response ) {
				if ( ! response.ok ) {
					throw new Error( 'context failed' );
				}
				return response.json();
			} )
			.then( function ( data ) {
				nonce = data.nonce || '';
				fillSchools( data.schools || [] );
				submit.disabled = false;
			} )
			.catch( function () {
				setStatus( form.getAttribute( 'data-error' ) || '', 'error' );
			} );

		form.addEventListener( 'submit', function ( event ) {
			event.preventDefault();

			if ( ! nonce ) {
				setStatus( form.getAttribute( 'data-error' ) || '', 'error' );
				return;
			}

			clearInvalid();

			// Everything is checked, not just the first failure, so the reader sees every field
			// that needs attention at once rather than one per attempt.
			var firstBad = null;

			Object.keys( rules ).forEach( function ( name ) {
				var input = form.querySelector( '[name="' + name + '"]' );

				if ( input && checkField( input ) && ! firstBad ) {
					firstBad = input;
				}
			} );

			if ( firstBad ) {
				var section = firstBad.closest( 'details' );

				if ( section ) {
					section.open = true;
				}

				setStatus( '', '' );
				firstBad.focus();
				return;
			}

			submit.disabled = true;
			setStatus( form.getAttribute( 'data-sending' ) || '', 'sending' );

			var payload = {};

			Array.prototype.forEach.call( form.elements, function ( element ) {
				if ( element.name && element.type !== 'submit' ) {
					payload[ element.name ] = element.value;
				}
			} );

			fetch( submitUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': nonce,
				},
				body: JSON.stringify( payload ),
			} )
				.then( function ( response ) {
					return response.json().then( function ( body ) {
						return { ok: response.ok, body: body };
					} );
				} )
				.then( function ( result ) {
					if ( result.ok ) {
						form.reset();
						clearInvalid();
						setStatus( form.getAttribute( 'data-success' ) || '', 'success' );
						return;
					}

					// The server explains what is wrong; showing that beats a generic apology.
					var message =
						result.body && result.body.message
							? result.body.message
							: form.getAttribute( 'data-error' ) || '';

					setStatus( message, 'error' );
					markInvalid(
						result.body && result.body.data ? result.body.data.field : '',
						message
					);
					submit.disabled = false;
				} )
				.catch( function () {
					setStatus( form.getAttribute( 'data-error' ) || '', 'error' );
					submit.disabled = false;
				} );
		} );
	} );
}() );

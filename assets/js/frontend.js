/**
 * BorsFlow Forms – front end.
 *
 * Vanilla JS, no dependencies. Mirrors the server's validation and conditional
 * logic (BorsFlow_Fields::validate / ::visibility); the server stays authoritative.
 * Without JS the form posts to admin-post.php and still works.
 */
( function () {
	'use strict';

	var globals = window.borsflowForms || {};
	var M = globals.messages || {};

	function msg( key, arg ) {
		var s = M[ key ] || M.invalid || 'Invalid value.';
		return arg === undefined ? s : s.replace( /%[ds]/, String( arg ) );
	}

	function closestField( el ) {
		return el.closest( '[data-bf-field]' );
	}

	function controlsFor( wrap ) {
		return Array.prototype.slice.call( wrap.querySelectorAll( 'input, select, textarea' ) );
	}

	/** Current value of a field wrapper: string or array. */
	function valueOf( wrap ) {
		if ( ! wrap ) {
			return '';
		}
		var controls = controlsFor( wrap );
		var group = wrap.querySelector( 'fieldset.bf-group' );
		if ( group ) {
			var checked = controls.filter( function ( c ) {
				return c.checked;
			} ).map( function ( c ) {
				return c.value;
			} );
			return group.getAttribute( 'data-bf-type' ) === 'radio' ? ( checked[ 0 ] || '' ) : checked;
		}
		var c = controls[ 0 ];
		if ( ! c ) {
			return '';
		}
		if ( c.type === 'checkbox' ) {
			return c.checked ? c.value : '';
		}
		if ( c.type === 'file' ) {
			return c.files && c.files.length ? 'file' : '';
		}
		if ( c.multiple ) {
			return Array.prototype.slice.call( c.selectedOptions ).map( function ( o ) {
				return o.value;
			} );
		}
		return c.value;
	}

	/** Mirrors BorsFlow_Fields::rule_matches(). */
	function ruleMatches( rule, actual ) {
		var values = Array.isArray( actual ) ? actual.map( String ) : ( actual === '' || actual === null || actual === undefined ? [] : [ String( actual ) ] );
		var expected = String( rule.value || '' );
		switch ( rule.operator ) {
			case 'empty':
				return values.length === 0;
			case 'not_empty':
				return values.length > 0;
			case 'not_equals':
				return values.indexOf( expected ) === -1;
			case 'contains':
				return expected !== '' && values.some( function ( v ) {
					return v.toLowerCase().indexOf( expected.toLowerCase() ) !== -1;
				} );
			default:
				return values.indexOf( expected ) !== -1;
		}
	}

	function Form( form ) {
		this.form = form;
		this.root = form.closest( '.borsflow' );
		this.config = {};
		try {
			this.config = JSON.parse( form.getAttribute( 'data-bf-config' ) || '{}' );
		} catch ( e ) {}
		this.status = this.root.querySelector( '.bf-status' );
		this.alert = this.root.querySelector( '.bf-alert' );
		this.button = form.querySelector( '.bf-submit' );
		this.submitted = false;
		this.busy = false;
		this.init();
	}

	Form.prototype.init = function () {
		var self = this;
		this.root.classList.add( 'bf-js' );

		// Hidden fields prefilled from the query string (works behind page caches).
		var params = new URLSearchParams( window.location.search );
		this.form.querySelectorAll( 'input[data-bf-query]' ).forEach( function ( input ) {
			var v = params.get( input.getAttribute( 'data-bf-query' ) );
			if ( v !== null ) {
				input.value = v;
			}
		} );

		this.applyConditions();
		this.form.addEventListener( 'input', function ( e ) {
			self.applyConditions();
			if ( self.submitted ) {
				var wrap = closestField( e.target );
				if ( wrap ) {
					self.validateField( wrap );
				}
			}
		} );
		this.form.addEventListener( 'change', function ( e ) {
			self.applyConditions();
			var wrap = closestField( e.target );
			if ( wrap && ( self.submitted || wrap.classList.contains( 'bf-has-error' ) ) ) {
				self.validateField( wrap );
			}
		} );
		this.form.addEventListener( 'focusout', function ( e ) {
			var wrap = closestField( e.target );
			if ( wrap && e.target.value && ! wrap.contains( e.relatedTarget ) ) {
				self.validateField( wrap );
			}
		} );
		this.form.addEventListener( 'submit', function ( e ) {
			self.onSubmit( e );
		} );
	};

	Form.prototype.fieldByKey = function ( key ) {
		return this.form.querySelector( '[data-bf-field="' + key + '"]' ) || this.form.querySelector( 'input[type="hidden"][data-bf-key="' + key + '"]' );
	};

	/** Mirrors BorsFlow_Fields::visibility(): a hidden source counts as empty. */
	Form.prototype.applyConditions = function () {
		var self = this;
		var memo = {};
		var wraps = Array.prototype.slice.call( this.form.querySelectorAll( '[data-bf-cond]' ) );

		function visible( el, depth ) {
			if ( ! el || ! el.hasAttribute( 'data-bf-cond' ) ) {
				return true;
			}
			var id = el.getAttribute( 'data-bf-field' ) || el.id || wraps.indexOf( el );
			if ( memo.hasOwnProperty( id ) ) {
				return memo[ id ];
			}
			memo[ id ] = true; // Cycle guard.
			var c;
			try {
				c = JSON.parse( el.getAttribute( 'data-bf-cond' ) );
			} catch ( e ) {
				return true;
			}
			if ( ! c.enabled || ! c.rules || ! c.rules.length || depth > 20 ) {
				return true;
			}
			var results = c.rules.map( function ( rule ) {
				var src = self.fieldByKey( rule.field );
				var actual = src && src !== el && visible( src, depth + 1 ) ? ( src.tagName === 'INPUT' ? src.value : valueOf( src ) ) : '';
				return ruleMatches( rule, actual );
			} );
			var match = c.logic === 'or' ? results.indexOf( true ) !== -1 : results.indexOf( false ) === -1;
			memo[ id ] = c.action === 'show' ? match : ! match;
			return memo[ id ];
		}

		wraps.forEach( function ( el ) {
			var show = visible( el, 0 );
			el.hidden = ! show;
			controlsFor( el ).forEach( function ( c ) {
				c.disabled = ! show; // Disabled controls are neither submitted nor validated.
			} );
			if ( ! show ) {
				self.clearError( el );
			}
		} );
	};

	Form.prototype.validateField = function ( wrap ) {
		if ( wrap.hidden ) {
			this.clearError( wrap );
			return true;
		}
		var controls = controlsFor( wrap );
		var c = controls[ 0 ];
		if ( ! c ) {
			return true;
		}
		var group = wrap.querySelector( 'fieldset.bf-group' );
		var type = group ? group.getAttribute( 'data-bf-type' ) : c.getAttribute( 'data-bf-type' );
		var required = group ? group.hasAttribute( 'data-bf-required' ) : c.required;
		var value = valueOf( wrap );
		var empty = Array.isArray( value ) ? value.length === 0 : String( value ).trim() === '';
		var error = '';

		if ( empty ) {
			if ( required ) {
				error = type === 'consent' ? msg( 'consent' ) : msg( 'required' );
			}
		} else if ( type === 'file' ) {
			var file = c.files[ 0 ];
			var exts = ( c.getAttribute( 'data-bf-exts' ) || '' ).split( ',' ).filter( Boolean );
			var ext = ( file.name.split( '.' ).pop() || '' ).toLowerCase();
			if ( exts.length && exts.indexOf( ext ) === -1 ) {
				error = msg( 'file_type', exts.join( ', ' ) );
			} else if ( file.size > Number( c.getAttribute( 'data-bf-max-size' ) || Infinity ) ) {
				error = msg( 'file_size', c.getAttribute( 'data-bf-max-size-label' ) || '' );
			}
		} else if ( ! Array.isArray( value ) && ! group && c.tagName !== 'SELECT' && c.type !== 'checkbox' ) {
			error = this.checkValue( c, type, value );
		}

		if ( error ) {
			this.showError( wrap, error );
			return false;
		}
		this.clearError( wrap );
		return true;
	};

	/** Mirrors the type/length/range/pattern checks in BorsFlow_Fields::validate(). */
	Form.prototype.checkValue = function ( c, type, value ) {
		var len = Array.from( value ).length;
		var minL = c.getAttribute( 'minlength' );
		var maxL = c.getAttribute( 'maxlength' );
		if ( minL && len < Number( minL ) ) {
			return msg( 'min_length', minL );
		}
		if ( maxL && len > Number( maxL ) ) {
			return msg( 'max_length', maxL );
		}
		switch ( type ) {
			case 'email':
				if ( ! /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test( value ) ) {
					return msg( 'email' );
				}
				break;
			case 'url':
				try {
					var u = new URL( value );
					if ( u.protocol !== 'http:' && u.protocol !== 'https:' ) {
						return msg( 'url' );
					}
				} catch ( e ) {
					return msg( 'url' );
				}
				break;
			case 'phone':
				if ( ! /^[0-9+().\-\s/]{5,30}$/.test( value ) || ( value.match( /\d/g ) || [] ).length < 5 ) {
					return msg( 'phone' );
				}
				break;
			case 'number':
				if ( value === '' || isNaN( Number( value ) ) ) {
					return msg( 'number' );
				}
				if ( c.min !== '' && Number( value ) < Number( c.min ) ) {
					return msg( 'min', c.min );
				}
				if ( c.max !== '' && Number( value ) > Number( c.max ) ) {
					return msg( 'max', c.max );
				}
				break;
			case 'date':
			case 'time':
				var re = type === 'date' ? /^\d{4}-\d{2}-\d{2}$/ : /^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/;
				if ( ! re.test( value ) ) {
					return msg( type );
				}
				if ( ( c.min && value < c.min ) || ( c.max && value > c.max ) ) {
					return msg( 'range' );
				}
				break;
		}
		var pattern = c.getAttribute( 'data-bf-pattern' );
		if ( pattern ) {
			var rx = null;
			try {
				rx = new RegExp( '^(?:' + pattern + ')$', 'u' );
			} catch ( e ) {}
			if ( rx && ! rx.test( value ) ) {
				return c.getAttribute( 'data-bf-pattern-message' ) || msg( 'pattern' );
			}
		}
		return '';
	};

	Form.prototype.errorEl = function ( wrap ) {
		return wrap.querySelector( '.bf-error' );
	};

	Form.prototype.showError = function ( wrap, text ) {
		var err = this.errorEl( wrap );
		if ( ! err ) {
			return;
		}
		err.textContent = text;
		err.hidden = false;
		wrap.classList.add( 'bf-has-error' );
		var targets = wrap.querySelector( 'fieldset.bf-group' ) ? [ wrap.querySelector( 'fieldset.bf-group' ) ].concat( controlsFor( wrap ) ) : controlsFor( wrap );
		targets.forEach( function ( t ) {
			if ( t.tagName !== 'FIELDSET' ) {
				t.setAttribute( 'aria-invalid', 'true' );
			}
			var ids = ( t.getAttribute( 'aria-describedby' ) || '' ).split( /\s+/ ).filter( Boolean );
			if ( ids.indexOf( err.id ) === -1 && ( t.tagName === 'FIELDSET' || ! wrap.querySelector( 'fieldset.bf-group' ) ) ) {
				ids.push( err.id );
				t.setAttribute( 'aria-describedby', ids.join( ' ' ) );
			}
		} );
	};

	Form.prototype.clearError = function ( wrap ) {
		var err = this.errorEl( wrap );
		wrap.classList.remove( 'bf-has-error' );
		if ( ! err ) {
			return;
		}
		err.textContent = '';
		err.hidden = true;
		controlsFor( wrap ).concat( Array.prototype.slice.call( wrap.querySelectorAll( 'fieldset' ) ) ).forEach( function ( t ) {
			t.removeAttribute( 'aria-invalid' );
			var ids = ( t.getAttribute( 'aria-describedby' ) || '' ).split( /\s+/ ).filter( function ( id ) {
				return id && id !== err.id;
			} );
			if ( ids.length ) {
				t.setAttribute( 'aria-describedby', ids.join( ' ' ) );
			} else {
				t.removeAttribute( 'aria-describedby' );
			}
		} );
	};

	Form.prototype.validateAll = function () {
		var self = this;
		var first = null;
		this.form.querySelectorAll( '[data-bf-field]' ).forEach( function ( wrap ) {
			if ( ! self.validateField( wrap ) && ! first ) {
				first = wrap;
			}
		} );
		return first;
	};

	Form.prototype.focusFirstError = function ( wrap ) {
		var target = wrap.querySelector( 'input:not([type="hidden"]), select, textarea' );
		if ( target ) {
			target.focus();
		}
	};

	Form.prototype.showAlert = function ( text ) {
		if ( ! this.alert ) {
			return;
		}
		this.alert.textContent = text || '';
		this.alert.hidden = ! text;
	};

	Form.prototype.setBusy = function ( busy ) {
		this.busy = busy;
		this.form.setAttribute( 'aria-busy', busy ? 'true' : 'false' );
		if ( this.button ) {
			this.button.disabled = busy;
			this.button.textContent = busy ? ( this.config.loadingText || this.config.submitText ) : this.config.submitText;
		}
	};

	Form.prototype.captchaToken = function () {
		var captcha = this.config.captcha || {};
		var input = this.form.querySelector( 'input[name="bf_recaptcha"]' );
		if ( captcha.type !== 'recaptcha' || ! input || ! window.grecaptcha ) {
			return Promise.resolve();
		}
		return new Promise( function ( resolve ) {
			window.grecaptcha.ready( function () {
				window.grecaptcha.execute( captcha.siteKey, { action: 'borsflow_submit' } ).then( function ( token ) {
					input.value = token;
					resolve();
				}, resolve );
			} );
		} );
	};

	Form.prototype.onSubmit = function ( e ) {
		if ( ! window.fetch || ! window.FormData ) {
			return; // Native POST fallback.
		}
		e.preventDefault();
		if ( this.busy ) {
			return;
		}
		var self = this;
		this.submitted = true;
		this.showAlert( '' );
		this.applyConditions();

		var firstError = this.validateAll();
		if ( firstError ) {
			this.focusFirstError( firstError );
			return;
		}

		if ( this.config.preview ) {
			this.status.innerHTML = '<div class="bf-success"><p></p></div>';
			this.status.querySelector( 'p' ).textContent = msg( 'preview' );
			return;
		}

		this.setBusy( true );
		this.captchaToken().then( function () {
			var data = new FormData( self.form );
			data.set( 'bf_page_url', window.location.href.split( '#' )[ 0 ] );
			data.set( 'bf_referrer', document.referrer || '' );
			return fetch( self.config.rest, {
				method: 'POST',
				body: data,
				credentials: 'same-origin',
				headers: { Accept: 'application/json' },
			} );
		} ).then( function ( res ) {
			return res.json().catch( function () {
				return { success: false, message: msg( 'network' ) };
			} );
		} ).then( function ( json ) {
			self.handleResponse( json );
		} ).catch( function () {
			self.showAlert( msg( 'network' ) );
		} ).then( function () {
			self.setBusy( false );
		} );
	};

	Form.prototype.handleResponse = function ( json ) {
		var self = this;
		if ( json && json.success ) {
			if ( json.redirect ) {
				window.location.assign( json.redirect );
				return;
			}
			this.form.hidden = true;
			this.status.innerHTML = '<div class="bf-success">' + ( json.message || '' ) + '</div>';
			this.status.setAttribute( 'tabindex', '-1' );
			this.status.focus();
			this.root.dispatchEvent( new CustomEvent( 'borsflow:success', { bubbles: true, detail: json } ) );
			return;
		}

		if ( window.turnstile && this.form.querySelector( '.cf-turnstile' ) ) {
			try {
				window.turnstile.reset( this.form.querySelector( '.cf-turnstile' ) );
			} catch ( e ) {}
		}

		var errors = ( json && json.errors ) || {};
		var first = null;
		Object.keys( errors ).forEach( function ( key ) {
			var wrap = self.form.querySelector( '[data-bf-field="' + key + '"]' );
			if ( wrap ) {
				self.showError( wrap, errors[ key ] );
				first = first || wrap;
			}
		} );
		this.showAlert( ( json && json.message ) || msg( 'network' ) );
		if ( first ) {
			this.focusFirstError( first );
		}
		this.root.dispatchEvent( new CustomEvent( 'borsflow:error', { bubbles: true, detail: json } ) );
	};

	function init( form ) {
		if ( ! form || form.__borsflow ) {
			return;
		}
		form.__borsflow = new Form( form );
	}

	window.BorsFlowForms = { init: init };

	function boot() {
		document.querySelectorAll( 'form.bf-form' ).forEach( init );
	}
	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
}() );

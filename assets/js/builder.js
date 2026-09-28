/**
 * BorsFlow Forms – visual form builder.
 *
 * Plain jQuery + jQuery UI (shipped with WordPress), no build step.
 * State lives in one object; the canvas, field panel and settings tabs are
 * rendered from it, and the live preview is rendered by the server (the same
 * PHP renderer the front end uses) so it can never drift from reality.
 */
( function ( $, wp ) {
	'use strict';

	var data = window.borsflowBuilder;
	if ( ! data ) {
		return;
	}
	var __ = wp.i18n.__;
	var sprintf = wp.i18n.sprintf;

	var state = clone( data.form );
	var selectedId = null;
	var dirty = false;
	var previewTimer = null;
	var previewSeq = 0;
	var activeTab = 'fields';

	var $root, $canvas, $panel, $frame, $status;

	/* ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------- */

	function clone( o ) {
		return JSON.parse( JSON.stringify( o ) );
	}

	function esc( s ) {
		return String( s === null || s === undefined ? '' : s ).replace( /[&<>"']/g, function ( c ) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ c ];
		} );
	}

	function uid() {
		return 'f_' + Math.random().toString( 36 ).slice( 2, 10 );
	}

	function slugify( s ) {
		return String( s || '' )
			.toLowerCase()
			.normalize( 'NFD' ).replace( /[̀-ͯ]/g, '' )
			.replace( /[^a-z0-9_]+/g, '_' )
			.replace( /^_+|_+$/g, '' )
			.slice( 0, 64 );
	}

	function isLayout( type ) {
		return !! ( data.types[ type ] && data.types[ type ].layout );
	}

	function supports( field, prop ) {
		var t = data.types[ field.type ];
		return !! t && t.supports.indexOf( prop ) !== -1;
	}

	function findField( id ) {
		for ( var i = 0; i < state.fields.length; i++ ) {
			if ( state.fields[ i ].id === id ) {
				return state.fields[ i ];
			}
		}
		return null;
	}

	function dataFields() {
		return state.fields.filter( function ( f ) {
			return ! isLayout( f.type ) && f.key;
		} );
	}

	function uniqueKey( base, exceptId ) {
		base = slugify( base ) || 'field';
		var key = base;
		var n = 2;
		var taken = function ( k ) {
			return data.reserved.indexOf( k ) !== -1 || state.fields.some( function ( f ) {
				return f.id !== exceptId && f.key === k;
			} );
		};
		while ( taken( key ) ) {
			key = base + '_' + n++;
		}
		return key;
	}

	function getPath( obj, path ) {
		return path.split( '.' ).reduce( function ( o, k ) {
			return o === undefined || o === null ? undefined : o[ k ];
		}, obj );
	}

	function setPath( obj, path, value ) {
		var parts = path.split( '.' );
		var last = parts.pop();
		var target = parts.reduce( function ( o, k ) {
			if ( typeof o[ k ] !== 'object' || o[ k ] === null ) {
				o[ k ] = {};
			}
			return o[ k ];
		}, obj );
		target[ last ] = value;
	}

	function newField( type ) {
		var f = clone( data.blanks[ type ] );
		var label = data.types[ type ].label;
		f.id = uid();
		f._autoKey = true;
		if ( type === 'heading' ) {
			f.content = __( 'Section title', 'borsflow-forms' );
		} else if ( type === 'html' ) {
			f.content = '<p>' + __( 'Add some text here.', 'borsflow-forms' ) + '</p>';
		} else {
			f.label = label;
			f.key = uniqueKey( type === 'consent' ? 'consent' : label );
		}
		if ( type === 'consent' ) {
			f.label = __( 'Consent', 'borsflow-forms' );
			f.content = __( 'I agree to be contacted about my enquiry.', 'borsflow-forms' );
		}
		if ( data.types[ type ].has_options ) {
			f.options = [
				{ label: __( 'Option 1', 'borsflow-forms' ), value: 'option_1', selected: false },
				{ label: __( 'Option 2', 'borsflow-forms' ), value: 'option_2', selected: false },
			];
		}
		return f;
	}

	function changed( opts ) {
		dirty = true;
		setStatus( __( 'Unsaved changes', 'borsflow-forms' ), 'dirty' );
		if ( ! opts || ! opts.noPreview ) {
			schedulePreview();
		}
	}

	function setStatus( text, kind ) {
		$status.text( text ).attr( 'data-kind', kind || '' );
	}

	/* ---------------------------------------------------------------------
	 * Shell
	 * ------------------------------------------------------------------- */

	function renderShell() {
		var tabs = [
			[ 'fields', __( 'Fields', 'borsflow-forms' ) ],
			[ 'settings', __( 'Settings', 'borsflow-forms' ) ],
			[ 'notifications', __( 'Notifications', 'borsflow-forms' ) ],
			[ 'crm', __( 'CRM mapping', 'borsflow-forms' ) ],
		];

		var palette = Object.keys( data.types ).map( function ( type ) {
			var t = data.types[ type ];
			return '<li class="bf-b-palette-item" data-type="' + esc( type ) + '">' +
				'<button type="button" class="button bf-b-add" data-type="' + esc( type ) + '" title="' + esc( sprintf( __( 'Add %s field', 'borsflow-forms' ), t.label ) ) + '">' +
				'<span class="dashicons dashicons-' + esc( t.icon ) + '" aria-hidden="true"></span> ' + esc( t.label ) +
				'</button></li>';
		} ).join( '' );

		var html =
			'<div class="bf-b-header">' +
				'<label class="screen-reader-text" for="bf-b-title">' + esc( __( 'Form name', 'borsflow-forms' ) ) + '</label>' +
				'<input type="text" id="bf-b-title" class="bf-b-title" value="' + esc( state.title ) + '">' +
				'<label class="bf-b-enabled"><input type="checkbox" id="bf-b-enabled"' + ( state.enabled ? ' checked' : '' ) + '> ' + esc( __( 'Enabled', 'borsflow-forms' ) ) + '</label>' +
				'<input type="text" class="bf-b-shortcode code" readonly aria-label="' + esc( __( 'Shortcode', 'borsflow-forms' ) ) + '" value="' + esc( '[borsflow_form id="' + state.id + '"]' ) + '">' +
				'<span class="bf-b-status" role="status" aria-live="polite"></span>' +
				'<button type="button" class="button button-primary button-large" id="bf-b-save">' + esc( __( 'Save form', 'borsflow-forms' ) ) + '</button>' +
			'</div>' +
			'<nav class="nav-tab-wrapper bf-b-tabs" role="tablist">' +
				tabs.map( function ( t ) {
					return '<button type="button" role="tab" class="nav-tab" id="bf-b-tab-' + t[ 0 ] + '" data-tab="' + t[ 0 ] + '" aria-controls="bf-b-pane-' + t[ 0 ] + '">' + esc( t[ 1 ] ) + '</button>';
				} ).join( '' ) +
			'</nav>' +
			'<div class="bf-b-pane" id="bf-b-pane-fields" role="tabpanel" aria-labelledby="bf-b-tab-fields">' +
				'<div class="bf-b-columns">' +
					'<div class="bf-b-palette postbox"><h2 class="hndle">' + esc( __( 'Add fields', 'borsflow-forms' ) ) + '</h2>' +
						'<p class="description">' + esc( __( 'Drag onto the canvas, or click to append.', 'borsflow-forms' ) ) + '</p>' +
						'<ul>' + palette + '</ul></div>' +
					'<div class="bf-b-canvas-wrap">' +
						'<div class="bf-b-canvas" id="bf-b-canvas" aria-label="' + esc( __( 'Form fields', 'borsflow-forms' ) ) + '"></div>' +
						'<p class="bf-b-empty">' + esc( __( 'Drag fields here to start building your form.', 'borsflow-forms' ) ) + '</p>' +
					'</div>' +
					'<div class="bf-b-panel postbox" id="bf-b-panel" aria-live="polite"></div>' +
				'</div>' +
			'</div>' +
			'<div class="bf-b-pane" id="bf-b-pane-settings" role="tabpanel" aria-labelledby="bf-b-tab-settings" hidden></div>' +
			'<div class="bf-b-pane" id="bf-b-pane-notifications" role="tabpanel" aria-labelledby="bf-b-tab-notifications" hidden></div>' +
			'<div class="bf-b-pane" id="bf-b-pane-crm" role="tabpanel" aria-labelledby="bf-b-tab-crm" hidden></div>' +
			'<div class="bf-b-preview postbox">' +
				'<h2 class="hndle">' + esc( __( 'Live preview', 'borsflow-forms' ) ) + ' <span class="description">' + esc( __( '(validation and conditional logic work here; nothing is submitted)', 'borsflow-forms' ) ) + '</span></h2>' +
				'<div class="inside"><iframe id="bf-b-preview-frame" title="' + esc( __( 'Form preview', 'borsflow-forms' ) ) + '"></iframe></div>' +
			'</div>';

		$root.html( html );
		$canvas = $( '#bf-b-canvas' );
		$panel = $( '#bf-b-panel' );
		$frame = $( '#bf-b-preview-frame' );
		$status = $root.find( '.bf-b-status' );
	}

	function switchTab( tab ) {
		activeTab = tab;
		$root.find( '.bf-b-tabs .nav-tab' ).each( function () {
			var on = $( this ).data( 'tab' ) === tab;
			$( this ).toggleClass( 'nav-tab-active', on ).attr( 'aria-selected', on ? 'true' : 'false' );
		} );
		$root.find( '.bf-b-pane' ).each( function () {
			this.hidden = this.id !== 'bf-b-pane-' + tab;
		} );
		if ( tab === 'settings' ) {
			renderSettingsTab();
		} else if ( tab === 'notifications' ) {
			renderNotificationsTab();
		} else if ( tab === 'crm' ) {
			renderCrmTab();
		}
	}

	/* ---------------------------------------------------------------------
	 * Canvas
	 * ------------------------------------------------------------------- */

	function cardHtml( f ) {
		var t = data.types[ f.type ] || { label: f.type, icon: 'admin-generic' };
		var widthLabel = { full: __( 'Full', 'borsflow-forms' ), half: '1/2', third: '1/3' }[ f.width ] || '';
		var title;
		if ( f.type === 'heading' ) {
			title = f.content || t.label;
		} else if ( f.type === 'html' ) {
			title = $( '<div>' ).html( f.content ).text().slice( 0, 60 ) || t.label;
		} else {
			title = f.label || f.key;
		}
		var meta = [ t.label ];
		if ( f.key ) {
			meta.push( f.key );
		}
		meta.push( widthLabel );

		return '<div class="bf-b-card bf-w-' + esc( f.width ) + ( f.id === selectedId ? ' is-selected' : '' ) + '" data-id="' + esc( f.id ) + '">' +
			'<div class="bf-b-card-inner" tabindex="0" role="button" aria-pressed="' + ( f.id === selectedId ? 'true' : 'false' ) + '" aria-label="' + esc( sprintf( __( 'Edit %s', 'borsflow-forms' ), title ) ) + '">' +
				'<span class="dashicons dashicons-' + esc( t.icon ) + ' bf-b-card-icon" aria-hidden="true"></span>' +
				'<span class="bf-b-card-text">' +
					'<span class="bf-b-card-label">' + esc( title ) + ( f.required ? ' <span class="bf-b-req" aria-label="' + esc( __( 'required', 'borsflow-forms' ) ) + '">*</span>' : '' ) + '</span>' +
					'<span class="bf-b-card-meta">' + esc( meta.join( ' · ' ) ) +
						( f.conditions && f.conditions.enabled && f.conditions.rules.length ? ' <span class="dashicons dashicons-randomize" title="' + esc( __( 'Has conditional logic', 'borsflow-forms' ) ) + '"></span>' : '' ) +
					'</span>' +
				'</span>' +
			'</div>' +
			'<span class="bf-b-card-actions">' +
				'<button type="button" class="button-link bf-b-move" data-dir="-1" aria-label="' + esc( __( 'Move up', 'borsflow-forms' ) ) + '"><span class="dashicons dashicons-arrow-up-alt2"></span></button>' +
				'<button type="button" class="button-link bf-b-move" data-dir="1" aria-label="' + esc( __( 'Move down', 'borsflow-forms' ) ) + '"><span class="dashicons dashicons-arrow-down-alt2"></span></button>' +
				'<button type="button" class="button-link bf-b-dup" aria-label="' + esc( __( 'Duplicate field', 'borsflow-forms' ) ) + '"><span class="dashicons dashicons-admin-page"></span></button>' +
				'<button type="button" class="button-link bf-b-del" aria-label="' + esc( __( 'Delete field', 'borsflow-forms' ) ) + '"><span class="dashicons dashicons-trash"></span></button>' +
			'</span>' +
		'</div>';
	}

	function renderCanvas() {
		$canvas.html( state.fields.map( cardHtml ).join( '' ) );
		$root.find( '.bf-b-empty' ).toggle( state.fields.length === 0 );
	}

	function updateCard( f ) {
		var $old = $canvas.children( '[data-id="' + f.id + '"]' );
		if ( $old.length ) {
			$old.replaceWith( cardHtml( f ) );
		}
	}

	function initSortable() {
		$canvas.sortable( {
			items: '> .bf-b-card, > .bf-b-palette-item',
			placeholder: 'bf-b-placeholder',
			tolerance: 'pointer',
			cancel: 'button, input, textarea, select',
			forcePlaceholderSize: true,
			start: function ( e, ui ) {
				var f = findField( ui.item.data( 'id' ) );
				ui.placeholder.addClass( 'bf-w-' + ( f ? f.width : 'full' ) );
			},
			update: function () {
				setTimeout( syncFromDom, 0 );
			},
			receive: function () {
				setTimeout( syncFromDom, 0 );
			},
		} );

		$root.find( '.bf-b-palette-item' ).draggable( {
			connectToSortable: '#bf-b-canvas',
			helper: 'clone',
			revert: 'invalid',
			cancel: '',
			appendTo: $root,
			zIndex: 1000,
		} );
	}

	/**
	 * Rebuild state order from the DOM after a drag, turning dropped palette items into fields.
	 */
	function syncFromDom() {
		var fields = [];
		var created = null;
		$canvas.children().each( function () {
			var $el = $( this );
			if ( $el.hasClass( 'bf-b-palette-item' ) ) {
				created = newField( $el.data( 'type' ) );
				fields.push( created );
			} else {
				var f = findField( $el.data( 'id' ) );
				if ( f ) {
					fields.push( f );
				}
			}
		} );
		var before = state.fields.map( function ( f ) {
			return f.id;
		} ).join();
		state.fields = fields;
		renderCanvas();
		if ( created ) {
			select( created.id );
		}
		if ( created || before !== fields.map( function ( f ) {
			return f.id;
		} ).join() ) {
			changed();
		}
	}

	function addField( type ) {
		var f = newField( type );
		var idx = selectedId ? state.fields.indexOf( findField( selectedId ) ) + 1 : state.fields.length;
		state.fields.splice( idx || state.fields.length, 0, f );
		renderCanvas();
		select( f.id );
		changed();
	}

	function select( id ) {
		selectedId = id;
		$canvas.children().each( function () {
			var on = $( this ).data( 'id' ) === id;
			$( this ).toggleClass( 'is-selected', on ).find( '.bf-b-card-inner' ).attr( 'aria-pressed', on ? 'true' : 'false' );
		} );
		renderPanel();
	}

	/* ---------------------------------------------------------------------
	 * Field settings panel
	 * ------------------------------------------------------------------- */

	function row( label, control, help, id ) {
		return '<div class="bf-b-row">' +
			( label ? '<label' + ( id ? ' for="' + esc( id ) + '"' : '' ) + '>' + esc( label ) + '</label>' : '' ) +
			control +
			( help ? '<p class="description">' + esc( help ) + '</p>' : '' ) +
		'</div>';
	}

	function input( prop, value, attrs ) {
		attrs = attrs || {};
		var type = attrs.type || 'text';
		var id = 'bf-b-p-' + prop;
		var extra = Object.keys( attrs ).filter( function ( k ) {
			return k !== 'type';
		} ).map( function ( k ) {
			return ' ' + k + '="' + esc( attrs[ k ] ) + '"';
		} ).join( '' );
		return '<input type="' + type + '" id="' + id + '" class="widefat" data-prop="' + esc( prop ) + '" value="' + esc( value ) + '"' + extra + '>';
	}

	function renderPanel() {
		var f = findField( selectedId );
		if ( ! f ) {
			$panel.html( '<h2 class="hndle">' + esc( __( 'Field settings', 'borsflow-forms' ) ) + '</h2><div class="inside"><p class="description">' + esc( __( 'Select a field on the canvas to edit it.', 'borsflow-forms' ) ) + '</p></div>' );
			return;
		}
		var t = data.types[ f.type ];
		var h = '';
		var layout = isLayout( f.type );

		if ( f.type === 'heading' ) {
			h += row( __( 'Heading text', 'borsflow-forms' ), input( 'content', f.content ), '', 'bf-b-p-content' );
			h += row( __( 'Level', 'borsflow-forms' ), '<select id="bf-b-p-level" class="widefat" data-prop="level">' +
				[ 'h2', 'h3', 'h4' ].map( function ( l ) {
					return '<option value="' + l + '"' + ( f.level === l ? ' selected' : '' ) + '>' + l.toUpperCase() + '</option>';
				} ).join( '' ) + '</select>', '', 'bf-b-p-level' );
		} else if ( f.type === 'html' ) {
			h += row( __( 'Content (HTML allowed)', 'borsflow-forms' ), '<textarea id="bf-b-p-content" class="widefat code" rows="6" data-prop="content">' + esc( f.content ) + '</textarea>', __( 'Scripts and unsafe markup are removed on save.', 'borsflow-forms' ), 'bf-b-p-content' );
		} else {
			h += row( __( 'Label', 'borsflow-forms' ), input( 'label', f.label ), '', 'bf-b-p-label' );
			h += row( __( 'Field key', 'borsflow-forms' ), input( 'key', f.key, { 'class': 'widefat code', pattern: '[a-z0-9_]+' } ),
				sprintf( __( 'Used in merge tags ({%s}), CSV columns and CRM mapping. Lowercase letters, numbers and underscores.', 'borsflow-forms' ), f.key ), 'bf-b-p-key' );
		}

		if ( f.type === 'consent' ) {
			h += row( __( 'Checkbox text (links allowed)', 'borsflow-forms' ), '<textarea id="bf-b-p-content" class="widefat" rows="3" data-prop="content">' + esc( f.content ) + '</textarea>', '', 'bf-b-p-content' );
		}
		if ( supports( f, 'placeholder' ) ) {
			h += row( f.type === 'select' ? __( 'Empty option text', 'borsflow-forms' ) : __( 'Placeholder', 'borsflow-forms' ), input( 'placeholder', f.placeholder ), '', 'bf-b-p-placeholder' );
		}
		if ( ! layout && f.type !== 'hidden' ) {
			h += row( __( 'Help text', 'borsflow-forms' ), input( 'help', f.help ), '', 'bf-b-p-help' );
		}
		if ( supports( f, 'default' ) ) {
			var defHelp = f.type === 'hidden' ? __( 'Tokens: {query:utm_source}, {page_url}, {page_title}, {user_email}.', 'borsflow-forms' ) : '';
			var defType = { date: 'date', time: 'time', number: 'number' }[ f.type ] || 'text';
			h += row( __( 'Default value', 'borsflow-forms' ), f.type === 'textarea'
				? '<textarea id="bf-b-p-default" class="widefat" rows="3" data-prop="default">' + esc( f.default ) + '</textarea>'
				: input( 'default', f.default, { type: defType } ), defHelp, 'bf-b-p-default' );
		}
		if ( supports( f, 'options' ) ) {
			h += '<div class="bf-b-row"><span class="bf-b-row-label">' + esc( __( 'Options', 'borsflow-forms' ) ) + '</span><div id="bf-b-options"></div></div>';
		}

		if ( f.type !== 'hidden' ) {
			h += row( __( 'Width', 'borsflow-forms' ), '<select id="bf-b-p-width" class="widefat" data-prop="width">' +
				[ [ 'full', __( 'Full width', 'borsflow-forms' ) ], [ 'half', __( 'Half (1/2)', 'borsflow-forms' ) ], [ 'third', __( 'Third (1/3)', 'borsflow-forms' ) ] ].map( function ( o ) {
					return '<option value="' + o[ 0 ] + '"' + ( f.width === o[ 0 ] ? ' selected' : '' ) + '>' + esc( o[ 1 ] ) + '</option>';
				} ).join( '' ) + '</select>', __( 'Consecutive half or third width fields share a row.', 'borsflow-forms' ), 'bf-b-p-width' );
		}

		if ( ! layout && f.type !== 'hidden' ) {
			h += '<div class="bf-b-row"><label><input type="checkbox" data-prop="required"' + ( f.required ? ' checked' : '' ) + '> ' + esc( __( 'Required', 'borsflow-forms' ) ) + '</label></div>';
		}

		// Validation.
		var v = '';
		if ( supports( f, 'min_length' ) ) {
			v += row( __( 'Min length', 'borsflow-forms' ), input( 'min_length', f.min_length, { type: 'number', min: 0 } ), '', 'bf-b-p-min_length' );
		}
		if ( supports( f, 'max_length' ) ) {
			v += row( __( 'Max length', 'borsflow-forms' ), input( 'max_length', f.max_length, { type: 'number', min: 1 } ), '', 'bf-b-p-max_length' );
		}
		if ( supports( f, 'min' ) ) {
			var mt = { date: 'date', time: 'time' }[ f.type ] || 'number';
			v += row( __( 'Minimum', 'borsflow-forms' ), input( 'min', f.min, { type: mt, step: 'any' } ), '', 'bf-b-p-min' );
			v += row( __( 'Maximum', 'borsflow-forms' ), input( 'max', f.max, { type: mt, step: 'any' } ), '', 'bf-b-p-max' );
		}
		if ( supports( f, 'step' ) ) {
			v += row( __( 'Step', 'borsflow-forms' ), input( 'step', f.step, { placeholder: 'any' } ), '', 'bf-b-p-step' );
		}
		if ( supports( f, 'rows' ) ) {
			v += row( __( 'Rows', 'borsflow-forms' ), input( 'rows', f.rows, { type: 'number', min: 2, max: 20 } ), '', 'bf-b-p-rows' );
		}
		if ( supports( f, 'pattern' ) ) {
			v += row( __( 'Pattern (regular expression)', 'borsflow-forms' ), input( 'pattern', f.pattern, { 'class': 'widefat code', placeholder: '[A-Z]{2}[0-9]{4}' } ), __( 'Must match the whole value, like the HTML pattern attribute.', 'borsflow-forms' ), 'bf-b-p-pattern' );
			v += row( __( 'Pattern error message', 'borsflow-forms' ), input( 'pattern_message', f.pattern_message ), '', 'bf-b-p-pattern_message' );
		}
		if ( supports( f, 'accept' ) ) {
			v += row( __( 'Allowed extensions', 'borsflow-forms' ), input( 'accept', f.accept, { 'class': 'widefat code', placeholder: 'pdf,jpg,png' } ), __( 'Leave empty to use the global allowlist. Extensions outside it are ignored.', 'borsflow-forms' ), 'bf-b-p-accept' );
			v += row( __( 'Max size (MB)', 'borsflow-forms' ), input( 'max_size_mb', f.max_size_mb, { type: 'number', min: 1 } ), __( 'Capped by the global limit.', 'borsflow-forms' ), 'bf-b-p-max_size_mb' );
		}
		if ( v ) {
			h += '<details class="bf-b-section" open><summary>' + esc( __( 'Validation', 'borsflow-forms' ) ) + '</summary>' + v + '</details>';
		}

		h += '<details class="bf-b-section"' + ( f.conditions.enabled ? ' open' : '' ) + '><summary>' + esc( __( 'Conditional logic', 'borsflow-forms' ) ) + '</summary><div id="bf-b-conditions"></div></details>';
		h += '<details class="bf-b-section"><summary>' + esc( __( 'Advanced', 'borsflow-forms' ) ) + '</summary>' +
			row( __( 'CSS class', 'borsflow-forms' ), input( 'css_class', f.css_class, { 'class': 'widefat code' } ), '', 'bf-b-p-css_class' ) +
		'</details>';

		$panel.html(
			'<h2 class="hndle"><span class="dashicons dashicons-' + esc( t.icon ) + '" aria-hidden="true"></span> ' + esc( t.label ) + '</h2>' +
			'<div class="inside">' + h + '</div>'
		);

		if ( supports( f, 'options' ) ) {
			renderOptions( f );
		}
		renderConditions( f );
	}

	function renderOptions( f ) {
		var multi = data.types[ f.type ].multiple;
		var html = '<table class="bf-b-options widefat"><thead><tr>' +
			'<th class="bf-b-opt-handle"><span class="screen-reader-text">' + esc( __( 'Reorder', 'borsflow-forms' ) ) + '</span></th>' +
			'<th title="' + esc( __( 'Selected by default', 'borsflow-forms' ) ) + '">' + esc( __( 'Default', 'borsflow-forms' ) ) + '</th>' +
			'<th>' + esc( __( 'Label', 'borsflow-forms' ) ) + '</th><th>' + esc( __( 'Value', 'borsflow-forms' ) ) + '</th><th></th></tr></thead><tbody>';
		f.options.forEach( function ( o, i ) {
			html += '<tr data-index="' + i + '">' +
				'<td class="bf-b-opt-handle"><span class="dashicons dashicons-menu" aria-hidden="true"></span></td>' +
				'<td><input type="' + ( multi ? 'checkbox' : 'radio' ) + '" name="bf-b-opt-default" class="bf-b-opt-selected" aria-label="' + esc( __( 'Selected by default', 'borsflow-forms' ) ) + '"' + ( o.selected ? ' checked' : '' ) + '></td>' +
				'<td><input type="text" class="bf-b-opt-label" aria-label="' + esc( __( 'Option label', 'borsflow-forms' ) ) + '" value="' + esc( o.label ) + '"></td>' +
				'<td><input type="text" class="bf-b-opt-value code" aria-label="' + esc( __( 'Option value', 'borsflow-forms' ) ) + '" value="' + esc( o.value ) + '"></td>' +
				'<td><button type="button" class="button-link bf-b-opt-del" aria-label="' + esc( __( 'Remove option', 'borsflow-forms' ) ) + '"><span class="dashicons dashicons-no-alt"></span></button></td>' +
			'</tr>';
		} );
		html += '</tbody></table><p><button type="button" class="button bf-b-opt-add">' + esc( __( 'Add option', 'borsflow-forms' ) ) + '</button> ' +
			( multi ? '' : '<button type="button" class="button-link bf-b-opt-clear">' + esc( __( 'Clear default', 'borsflow-forms' ) ) + '</button>' ) + '</p>';

		$( '#bf-b-options' ).html( html ).find( 'tbody' ).sortable( {
			handle: '.bf-b-opt-handle',
			axis: 'y',
			update: function () {
				var order = $( this ).children().map( function () {
					return f.options[ $( this ).data( 'index' ) ];
				} ).get();
				f.options = order;
				renderOptions( f );
				changed();
			},
		} );
	}

	function renderConditions( f ) {
		var c = f.conditions;
		var sources = dataFields().filter( function ( o ) {
			return o.id !== f.id;
		} );
		var ops = [
			[ 'equals', __( 'is', 'borsflow-forms' ) ],
			[ 'not_equals', __( 'is not', 'borsflow-forms' ) ],
			[ 'contains', __( 'contains', 'borsflow-forms' ) ],
			[ 'not_empty', __( 'is filled', 'borsflow-forms' ) ],
			[ 'empty', __( 'is empty', 'borsflow-forms' ) ],
		];

		var html = '<p><label><input type="checkbox" class="bf-b-cond-enabled"' + ( c.enabled ? ' checked' : '' ) + '> ' + esc( __( 'Enable conditional logic', 'borsflow-forms' ) ) + '</label></p>';
		if ( c.enabled ) {
			if ( ! sources.length ) {
				html += '<p class="description">' + esc( __( 'Add another field first; rules compare against other fields’ values.', 'borsflow-forms' ) ) + '</p>';
			}
			html += '<p class="bf-b-cond-head">' +
				'<select class="bf-b-cond-action" aria-label="' + esc( __( 'Action', 'borsflow-forms' ) ) + '"><option value="show"' + ( c.action === 'show' ? ' selected' : '' ) + '>' + esc( __( 'Show', 'borsflow-forms' ) ) + '</option><option value="hide"' + ( c.action === 'hide' ? ' selected' : '' ) + '>' + esc( __( 'Hide', 'borsflow-forms' ) ) + '</option></select> ' +
				esc( __( 'this field if', 'borsflow-forms' ) ) + ' ' +
				'<select class="bf-b-cond-logic" aria-label="' + esc( __( 'Match', 'borsflow-forms' ) ) + '"><option value="and"' + ( c.logic === 'and' ? ' selected' : '' ) + '>' + esc( __( 'all', 'borsflow-forms' ) ) + '</option><option value="or"' + ( c.logic === 'or' ? ' selected' : '' ) + '>' + esc( __( 'any', 'borsflow-forms' ) ) + '</option></select> ' +
				esc( __( 'of these rules match:', 'borsflow-forms' ) ) +
			'</p><div class="bf-b-rules">';
			c.rules.forEach( function ( r, i ) {
				var src = sources.filter( function ( s ) {
					return s.key === r.field;
				} )[ 0 ];
				var valueControl;
				if ( r.operator === 'empty' || r.operator === 'not_empty' ) {
					valueControl = '';
				} else if ( src && src.options && src.options.length && r.operator !== 'contains' ) {
					valueControl = '<select class="bf-b-rule-value" aria-label="' + esc( __( 'Value', 'borsflow-forms' ) ) + '"><option value=""></option>' + src.options.map( function ( o ) {
						return '<option value="' + esc( o.value ) + '"' + ( o.value === r.value ? ' selected' : '' ) + '>' + esc( o.label ) + '</option>';
					} ).join( '' ) + '</select>';
				} else if ( src && src.type === 'consent' ) {
					valueControl = '<select class="bf-b-rule-value" aria-label="' + esc( __( 'Value', 'borsflow-forms' ) ) + '"><option value="yes"' + ( r.value === 'yes' ? ' selected' : '' ) + '>' + esc( __( 'checked', 'borsflow-forms' ) ) + '</option></select>';
				} else {
					valueControl = '<input type="text" class="bf-b-rule-value" aria-label="' + esc( __( 'Value', 'borsflow-forms' ) ) + '" value="' + esc( r.value ) + '">';
				}
				html += '<div class="bf-b-rule" data-index="' + i + '">' +
					'<select class="bf-b-rule-field" aria-label="' + esc( __( 'Field', 'borsflow-forms' ) ) + '"><option value="">' + esc( __( '— field —', 'borsflow-forms' ) ) + '</option>' +
						sources.map( function ( s ) {
							return '<option value="' + esc( s.key ) + '"' + ( s.key === r.field ? ' selected' : '' ) + '>' + esc( s.label || s.key ) + '</option>';
						} ).join( '' ) + '</select>' +
					'<select class="bf-b-rule-op" aria-label="' + esc( __( 'Operator', 'borsflow-forms' ) ) + '">' +
						ops.map( function ( o ) {
							return '<option value="' + o[ 0 ] + '"' + ( o[ 0 ] === r.operator ? ' selected' : '' ) + '>' + esc( o[ 1 ] ) + '</option>';
						} ).join( '' ) + '</select>' +
					valueControl +
					'<button type="button" class="button-link bf-b-rule-del" aria-label="' + esc( __( 'Remove rule', 'borsflow-forms' ) ) + '"><span class="dashicons dashicons-no-alt"></span></button>' +
				'</div>';
			} );
			html += '</div><p><button type="button" class="button bf-b-rule-add"' + ( sources.length ? '' : ' disabled' ) + '>' + esc( __( 'Add rule', 'borsflow-forms' ) ) + '</button></p>';
		}
		$( '#bf-b-conditions' ).html( html );
	}

	function bindPanel() {
		// Generic property inputs.
		$panel.on( 'input change', '[data-prop]', function ( e ) {
			var f = findField( selectedId );
			if ( ! f ) {
				return;
			}
			var prop = $( this ).data( 'prop' );
			var val = this.type === 'checkbox' ? this.checked : $( this ).val();

			if ( prop === 'key' ) {
				if ( e.type !== 'change' ) {
					return;
				}
				f.key = uniqueKey( val || f.label, f.id );
				f._autoKey = false;
				$( this ).val( f.key );
			} else {
				f[ prop ] = val;
			}
			if ( prop === 'label' && f._autoKey && ! isLayout( f.type ) ) {
				f.key = uniqueKey( val, f.id );
				$panel.find( '[data-prop="key"]' ).val( f.key );
			}
			updateCard( f );
			changed();
		} );

		// Options editor.
		$panel.on( 'input change', '.bf-b-opt-label, .bf-b-opt-value', function () {
			var f = findField( selectedId );
			var $tr = $( this ).closest( 'tr' );
			var o = f.options[ $tr.data( 'index' ) ];
			if ( $( this ).hasClass( 'bf-b-opt-label' ) ) {
				var autoValue = ! o.value || o.value === slugify( o.label ) || /^option_\d+$/.test( o.value );
				o.label = this.value;
				if ( autoValue ) {
					o.value = slugify( this.value );
					$tr.find( '.bf-b-opt-value' ).val( o.value );
				}
			} else {
				o.value = this.value;
			}
			changed();
		} );
		$panel.on( 'change', '.bf-b-opt-selected', function () {
			var f = findField( selectedId );
			var idx = $( this ).closest( 'tr' ).data( 'index' );
			if ( ! data.types[ f.type ].multiple ) {
				f.options.forEach( function ( o ) {
					o.selected = false;
				} );
			}
			f.options[ idx ].selected = this.checked;
			changed();
		} );
		$panel.on( 'click', '.bf-b-opt-add', function () {
			var f = findField( selectedId );
			var n = f.options.length + 1;
			f.options.push( { label: sprintf( __( 'Option %d', 'borsflow-forms' ), n ), value: 'option_' + n, selected: false } );
			renderOptions( f );
			$panel.find( '.bf-b-opt-label' ).last().trigger( 'focus' ).trigger( 'select' );
			changed();
		} );
		$panel.on( 'click', '.bf-b-opt-del', function () {
			var f = findField( selectedId );
			f.options.splice( $( this ).closest( 'tr' ).data( 'index' ), 1 );
			renderOptions( f );
			changed();
		} );
		$panel.on( 'click', '.bf-b-opt-clear', function () {
			var f = findField( selectedId );
			f.options.forEach( function ( o ) {
				o.selected = false;
			} );
			renderOptions( f );
			changed();
		} );

		// Conditional logic.
		$panel.on( 'change', '.bf-b-cond-enabled', function () {
			var f = findField( selectedId );
			f.conditions.enabled = this.checked;
			if ( this.checked && ! f.conditions.rules.length ) {
				f.conditions.rules.push( { field: '', operator: 'equals', value: '' } );
			}
			renderConditions( f );
			updateCard( f );
			changed();
		} );
		$panel.on( 'change', '.bf-b-cond-action, .bf-b-cond-logic', function () {
			var f = findField( selectedId );
			f.conditions[ $( this ).hasClass( 'bf-b-cond-action' ) ? 'action' : 'logic' ] = this.value;
			changed();
		} );
		$panel.on( 'change', '.bf-b-rule-field, .bf-b-rule-op', function () {
			var f = findField( selectedId );
			var r = f.conditions.rules[ $( this ).closest( '.bf-b-rule' ).data( 'index' ) ];
			if ( $( this ).hasClass( 'bf-b-rule-field' ) ) {
				r.field = this.value;
				r.value = '';
			} else {
				r.operator = this.value;
			}
			renderConditions( f );
			updateCard( f );
			changed();
		} );
		$panel.on( 'input change', '.bf-b-rule-value', function () {
			var f = findField( selectedId );
			f.conditions.rules[ $( this ).closest( '.bf-b-rule' ).data( 'index' ) ].value = this.value;
			changed();
		} );
		$panel.on( 'click', '.bf-b-rule-add', function () {
			var f = findField( selectedId );
			f.conditions.rules.push( { field: '', operator: 'equals', value: '' } );
			renderConditions( f );
			changed();
		} );
		$panel.on( 'click', '.bf-b-rule-del', function () {
			var f = findField( selectedId );
			f.conditions.rules.splice( $( this ).closest( '.bf-b-rule' ).data( 'index' ), 1 );
			renderConditions( f );
			updateCard( f );
			changed();
		} );
	}

	/* ---------------------------------------------------------------------
	 * Settings tabs
	 * ------------------------------------------------------------------- */

	function sInput( path, attrs ) {
		attrs = attrs || {};
		var v = getPath( state.settings, path );
		var id = 'bf-b-s-' + path.replace( /\./g, '-' );
		var type = attrs.type || 'text';
		if ( type === 'checkbox' ) {
			return '<input type="checkbox" id="' + id + '" data-setting="' + path + '"' + ( v ? ' checked' : '' ) + '>';
		}
		if ( type === 'textarea' ) {
			return '<textarea id="' + id + '" class="large-text" rows="' + ( attrs.rows || 6 ) + '" data-setting="' + path + '">' + esc( v ) + '</textarea>';
		}
		return '<input type="' + type + '" id="' + id + '" class="' + ( attrs.cls || 'regular-text' ) + '" data-setting="' + path + '" value="' + esc( v ) + '"' +
			( attrs.min !== undefined ? ' min="' + attrs.min + '"' : '' ) + ( attrs.max !== undefined ? ' max="' + attrs.max + '"' : '' ) +
			( attrs.placeholder ? ' placeholder="' + esc( attrs.placeholder ) + '"' : '' ) + '>';
	}

	function sSelect( path, options ) {
		var v = getPath( state.settings, path );
		return '<select id="bf-b-s-' + path.replace( /\./g, '-' ) + '" data-setting="' + path + '">' + options.map( function ( o ) {
			return '<option value="' + esc( o[ 0 ] ) + '"' + ( String( v ) === String( o[ 0 ] ) ? ' selected' : '' ) + ( o[ 2 ] ? ' disabled' : '' ) + '>' + esc( o[ 1 ] ) + '</option>';
		} ).join( '' ) + '</select>';
	}

	function tr( label, path, control, help ) {
		return '<tr><th scope="row"><label for="bf-b-s-' + path.replace( /\./g, '-' ) + '">' + esc( label ) + '</label></th><td>' + control + ( help ? '<p class="description">' + help + '</p>' : '' ) + '</td></tr>';
	}

	function fieldOptions( filter ) {
		return [ [ '', __( '— none —', 'borsflow-forms' ) ] ].concat( dataFields().filter( filter || function () {
			return true;
		} ).map( function ( f ) {
			return [ f.key, ( f.label || f.key ) + ' (' + f.key + ')' ];
		} ) );
	}

	function renderSettingsTab() {
		var s = state.settings;
		var captchaNote = ( ! data.captcha.recaptcha || ! data.captcha.turnstile )
			? esc( __( 'Captcha providers need site and secret keys in', 'borsflow-forms' ) ) + ' <a href="' + esc( data.settingsUrl ) + '">' + esc( __( 'global settings', 'borsflow-forms' ) ) + '</a>.'
			: '';
		var html = '<h2>' + esc( __( 'Submit button', 'borsflow-forms' ) ) + '</h2><table class="form-table" role="presentation">' +
			tr( __( 'Button text', 'borsflow-forms' ), 'submit_text', sInput( 'submit_text' ) ) +
			tr( __( 'Loading text', 'borsflow-forms' ), 'loading_text', sInput( 'loading_text' ), esc( __( 'Shown on the button while the submission is being sent.', 'borsflow-forms' ) ) ) +
			'</table>' +
			'<h2>' + esc( __( 'After submit', 'borsflow-forms' ) ) + '</h2><table class="form-table" role="presentation">' +
			tr( __( 'Behavior', 'borsflow-forms' ), 'after_submit', sSelect( 'after_submit', [ [ 'message', __( 'Show a success message', 'borsflow-forms' ) ], [ 'redirect', __( 'Redirect to a URL', 'borsflow-forms' ) ] ] ) ) +
			( s.after_submit === 'redirect'
				? tr( __( 'Redirect URL', 'borsflow-forms' ), 'redirect_url', sInput( 'redirect_url', { type: 'url', placeholder: 'https://' } ) )
				: tr( __( 'Success message', 'borsflow-forms' ), 'success_message', sInput( 'success_message', { type: 'textarea', rows: 3 } ) ) ) +
			'</table>' +
			'<h2>' + esc( __( 'Spam protection', 'borsflow-forms' ) ) + '</h2><p class="description">' + esc( __( 'A hidden honeypot field is always active.', 'borsflow-forms' ) ) + '</p><table class="form-table" role="presentation">' +
			tr( __( 'Time trap', 'borsflow-forms' ), 'spam.time_trap', '<label>' + sInput( 'spam.time_trap', { type: 'checkbox' } ) + ' ' + esc( __( 'Reject submissions sent faster than', 'borsflow-forms' ) ) + '</label> ' +
				sInput( 'spam.time_trap_seconds', { type: 'number', cls: 'small-text', min: 1, max: 120 } ) + ' ' + esc( __( 'seconds after the page loaded', 'borsflow-forms' ) ) ) +
			tr( __( 'Captcha', 'borsflow-forms' ), 'spam.captcha', sSelect( 'spam.captcha', [
				[ 'none', __( 'None', 'borsflow-forms' ) ],
				[ 'recaptcha', __( 'Google reCAPTCHA v3', 'borsflow-forms' ), ! data.captcha.recaptcha && s.spam.captcha !== 'recaptcha' ],
				[ 'turnstile', __( 'Cloudflare Turnstile', 'borsflow-forms' ), ! data.captcha.turnstile && s.spam.captcha !== 'turnstile' ],
			] ), captchaNote ) +
			'</table>' +
			'<h2>' + esc( __( 'Style', 'borsflow-forms' ) ) + '</h2><table class="form-table" role="presentation">' +
			tr( __( 'Preset', 'borsflow-forms' ), 'style.preset', sSelect( 'style.preset', [ [ 'inherit', __( 'Inherit theme', 'borsflow-forms' ) ], [ 'card', __( 'Light card', 'borsflow-forms' ) ], [ 'minimal', __( 'Minimal', 'borsflow-forms' ) ] ] ) ) +
			tr( __( 'Accent color', 'borsflow-forms' ), 'style.accent', sInput( 'style.accent', { cls: 'bf-b-color' } ) ) +
			tr( __( 'Border radius', 'borsflow-forms' ), 'style.radius', sInput( 'style.radius', { type: 'number', cls: 'small-text', min: 0, max: 32 } ) + ' px' ) +
			tr( __( 'Field size', 'borsflow-forms' ), 'style.size', sSelect( 'style.size', [ [ 'sm', __( 'Small', 'borsflow-forms' ) ], [ 'md', __( 'Medium', 'borsflow-forms' ) ], [ 'lg', __( 'Large', 'borsflow-forms' ) ] ] ) ) +
			'</table>';

		var $pane = $( '#bf-b-pane-settings' ).html( html );
		$pane.find( '.bf-b-color' ).wpColorPicker( {
			change: function ( e, ui ) {
				state.settings.style.accent = ui.color.toString();
				changed();
			},
		} );
	}

	function mergeTagsHelp() {
		var tags = dataFields().map( function ( f ) {
			return '<code>{' + esc( f.key ) + '}</code>';
		} );
		[ 'all_fields', 'form_title', 'site_name', 'page_url', 'submission_id', 'date' ].forEach( function ( t ) {
			tags.push( '<code>{' + t + '}</code>' );
		} );
		return esc( __( 'Merge tags:', 'borsflow-forms' ) ) + ' ' + tags.join( ' ' );
	}

	function renderNotificationsTab() {
		var isEmail = function ( f ) {
			return f.type === 'email';
		};
		var html = '<h2>' + esc( __( 'Admin notification', 'borsflow-forms' ) ) + '</h2><table class="form-table" role="presentation">' +
			tr( __( 'Send notification', 'borsflow-forms' ), 'notify.enabled', '<label>' + sInput( 'notify.enabled', { type: 'checkbox' } ) + ' ' + esc( __( 'Email me when this form is submitted', 'borsflow-forms' ) ) + '</label>' ) +
			tr( __( 'Recipients', 'borsflow-forms' ), 'notify.recipients', sInput( 'notify.recipients', { cls: 'large-text' } ), esc( __( 'Comma separated. {admin_email} is the site admin address.', 'borsflow-forms' ) ) ) +
			tr( __( 'Subject', 'borsflow-forms' ), 'notify.subject', sInput( 'notify.subject', { cls: 'large-text' } ) ) +
			tr( __( 'Body', 'borsflow-forms' ), 'notify.body', sInput( 'notify.body', { type: 'textarea', rows: 8 } ), mergeTagsHelp() ) +
			tr( __( 'Reply-To', 'borsflow-forms' ), 'notify.reply_to_field', sSelect( 'notify.reply_to_field', fieldOptions( isEmail ) ), esc( __( 'Replying to the notification emails the submitter.', 'borsflow-forms' ) ) ) +
			'</table>' +
			'<h2>' + esc( __( 'Autoresponder', 'borsflow-forms' ) ) + '</h2><table class="form-table" role="presentation">' +
			tr( __( 'Send autoresponder', 'borsflow-forms' ), 'autoresponder.enabled', '<label>' + sInput( 'autoresponder.enabled', { type: 'checkbox' } ) + ' ' + esc( __( 'Email the submitter a confirmation', 'borsflow-forms' ) ) + '</label>' ) +
			tr( __( 'Send to', 'borsflow-forms' ), 'autoresponder.to_field', sSelect( 'autoresponder.to_field', fieldOptions( isEmail ) ) ) +
			tr( __( 'Subject', 'borsflow-forms' ), 'autoresponder.subject', sInput( 'autoresponder.subject', { cls: 'large-text' } ) ) +
			tr( __( 'Body', 'borsflow-forms' ), 'autoresponder.body', sInput( 'autoresponder.body', { type: 'textarea', rows: 8 } ), mergeTagsHelp() ) +
			'</table>';
		$( '#bf-b-pane-notifications' ).html( html );
	}

	function renderCrmTab() {
		var s = state.settings.crm;
		var html = '';
		if ( ! data.crmReady ) {
			html += '<div class="notice notice-warning inline"><p>' + esc( __( 'BorsFlow CRM is not connected yet. Submissions are stored locally until you add credentials in', 'borsflow-forms' ) ) + ' <a href="' + esc( data.settingsUrl ) + '">' + esc( __( 'Settings', 'borsflow-forms' ) ) + '</a>.</p></div>';
		}
		html += '<p><label>' + sInput( 'crm.enabled', { type: 'checkbox' } ) + ' ' + esc( __( 'Send submissions from this form to BorsFlow CRM', 'borsflow-forms' ) ) + '</label></p>';
		html += '<h2>' + esc( __( 'Field mapping', 'borsflow-forms' ) ) + '</h2><p class="description">' + esc( __( 'Unmapped fields are appended to the lead notes as “Label: value” lines.', 'borsflow-forms' ) ) + '</p>';
		html += '<table class="widefat striped bf-b-mapping"><thead><tr><th>' + esc( __( 'Form field', 'borsflow-forms' ) ) + '</th><th>' + esc( __( 'CRM lead field', 'borsflow-forms' ) ) + '</th></tr></thead><tbody>';
		var fields = dataFields();
		if ( ! fields.length ) {
			html += '<tr><td colspan="2">' + esc( __( 'Add fields to the form first.', 'borsflow-forms' ) ) + '</td></tr>';
		}
		fields.forEach( function ( f ) {
			var current = s.mapping[ f.key ] || '';
			html += '<tr><td><label for="bf-b-map-' + esc( f.key ) + '">' + esc( f.label || f.key ) + '</label> <code>' + esc( f.key ) + '</code></td><td>' +
				'<select id="bf-b-map-' + esc( f.key ) + '" class="bf-b-map" data-key="' + esc( f.key ) + '"><option value="">' + esc( __( '— append to notes —', 'borsflow-forms' ) ) + '</option>' +
				Object.keys( data.crmFields ).map( function ( k ) {
					return '<option value="' + esc( k ) + '"' + ( current === k ? ' selected' : '' ) + '>' + esc( data.crmFields[ k ] ) + '</option>';
				} ).join( '' ) + '</select></td></tr>';
		} );
		html += '</tbody></table>';
		html += '<h2>' + esc( __( 'Overrides', 'borsflow-forms' ) ) + '</h2><table class="form-table" role="presentation">' +
			tr( __( 'Pipeline', 'borsflow-forms' ), 'crm.pipeline', sInput( 'crm.pipeline', { placeholder: __( 'Global default', 'borsflow-forms' ) } ) ) +
			tr( __( 'Stage', 'borsflow-forms' ), 'crm.stage', sInput( 'crm.stage', { placeholder: __( 'Global default', 'borsflow-forms' ) } ) ) +
			tr( __( 'Lead source', 'borsflow-forms' ), 'crm.source', sInput( 'crm.source', { placeholder: __( 'Global default', 'borsflow-forms' ) } ) ) +
			'</table>';
		$( '#bf-b-pane-crm' ).html( html );
	}

	function bindSettings() {
		$root.on( 'input change', '[data-setting]', function () {
			var path = $( this ).data( 'setting' );
			var val;
			if ( this.type === 'checkbox' ) {
				val = this.checked;
			} else if ( this.type === 'number' ) {
				val = this.value === '' ? '' : Number( this.value );
			} else {
				val = this.value;
			}
			setPath( state.settings, path, val );
			if ( path === 'after_submit' ) {
				renderSettingsTab();
			}
			changed();
		} );
		$root.on( 'change', '.bf-b-map', function () {
			var key = $( this ).data( 'key' );
			if ( Array.isArray( state.settings.crm.mapping ) ) {
				state.settings.crm.mapping = {};
			}
			if ( this.value ) {
				state.settings.crm.mapping[ key ] = this.value;
			} else {
				delete state.settings.crm.mapping[ key ];
			}
			changed( { noPreview: true } );
		} );
	}

	/* ---------------------------------------------------------------------
	 * Preview & save
	 * ------------------------------------------------------------------- */

	function payload() {
		return {
			id: state.id,
			title: state.title,
			enabled: state.enabled,
			fields: state.fields,
			settings: state.settings,
		};
	}

	function schedulePreview() {
		clearTimeout( previewTimer );
		previewTimer = setTimeout( refreshPreview, 350 );
	}

	function refreshPreview() {
		var seq = ++previewSeq;
		wp.apiFetch( { path: data.routes.preview, method: 'POST', data: payload() } ).then( function ( res ) {
			if ( seq !== previewSeq ) {
				return;
			}
			var doc = '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">' +
				'<link rel="stylesheet" href="' + esc( data.previewCss ) + '">' +
				'<style>body{margin:0;padding:20px;background:#fff;color:#1d2327;font:15px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif}</style>' +
				'</head><body>' + res.html +
				'<script>window.borsflowForms=' + JSON.stringify( { messages: data.messages } ).replace( /</g, '\\u003c' ) + ';<\/script>' +
				'<script src="' + esc( data.previewJs ) + '"><\/script></body></html>';
			$frame.attr( 'srcdoc', doc );
		} ).catch( function () {
			/* A failed preview is not fatal; the next change retries. */
		} );
	}

	function fitFrame() {
		var frame = $frame[ 0 ];
		try {
			var body = frame.contentDocument && frame.contentDocument.body;
			if ( ! body ) {
				return;
			}
			var resize = function () {
				frame.style.height = Math.max( 120, body.scrollHeight + 4 ) + 'px';
			};
			resize();
			if ( window.ResizeObserver ) {
				new window.ResizeObserver( resize ).observe( body );
			}
		} catch ( e ) {}
	}

	function save() {
		var $btn = $( '#bf-b-save' ).prop( 'disabled', true );
		setStatus( __( 'Saving…', 'borsflow-forms' ), 'saving' );
		wp.apiFetch( { path: data.routes.save, method: 'POST', data: payload() } ).then( function ( res ) {
			// Saved keys are live (merge tags, CRM mapping, CSV): stop deriving them from labels.
			state = res;
			dirty = false;
			renderCanvas();
			renderPanel();
			switchTab( activeTab );
			setStatus( __( 'Saved', 'borsflow-forms' ), 'saved' );
			schedulePreview();
		} ).catch( function ( err ) {
			setStatus( ( err && err.message ) || __( 'Save failed', 'borsflow-forms' ), 'error' );
		} ).finally( function () {
			$btn.prop( 'disabled', false );
		} );
	}

	/* ---------------------------------------------------------------------
	 * Boot
	 * ------------------------------------------------------------------- */

	function bindCanvas() {
		$root.on( 'click', '.bf-b-add', function () {
			addField( $( this ).data( 'type' ) );
		} );
		$canvas.on( 'click keydown', '.bf-b-card-inner', function ( e ) {
			if ( e.type === 'keydown' && e.key !== 'Enter' && e.key !== ' ' ) {
				return;
			}
			e.preventDefault();
			select( $( this ).closest( '.bf-b-card' ).data( 'id' ) );
		} );
		$canvas.on( 'click', '.bf-b-del', function () {
			var id = $( this ).closest( '.bf-b-card' ).data( 'id' );
			var f = findField( id );
			if ( ! window.confirm( sprintf( __( 'Delete the field “%s”?', 'borsflow-forms' ), f.label || f.key || data.types[ f.type ].label ) ) ) {
				return;
			}
			state.fields = state.fields.filter( function ( x ) {
				return x.id !== id;
			} );
			if ( selectedId === id ) {
				selectedId = null;
			}
			renderCanvas();
			renderPanel();
			changed();
		} );
		$canvas.on( 'click', '.bf-b-dup', function () {
			var f = findField( $( this ).closest( '.bf-b-card' ).data( 'id' ) );
			var copy = clone( f );
			copy.id = uid();
			if ( copy.key ) {
				copy.key = uniqueKey( copy.key, copy.id );
			}
			copy._autoKey = false;
			state.fields.splice( state.fields.indexOf( f ) + 1, 0, copy );
			renderCanvas();
			select( copy.id );
			changed();
		} );
		$canvas.on( 'click', '.bf-b-move', function () {
			var id = $( this ).closest( '.bf-b-card' ).data( 'id' );
			var f = findField( id );
			var i = state.fields.indexOf( f );
			var j = i + Number( $( this ).data( 'dir' ) );
			if ( j < 0 || j >= state.fields.length ) {
				return;
			}
			state.fields.splice( i, 1 );
			state.fields.splice( j, 0, f );
			renderCanvas();
			$canvas.children( '[data-id="' + id + '"]' ).find( '.bf-b-move[data-dir="' + $( this ).data( 'dir' ) + '"]' ).trigger( 'focus' );
			changed();
		} );
	}

	$( function () {
		$root = $( '#borsflow-builder' );
		if ( ! $root.length ) {
			return;
		}
		renderShell();
		renderCanvas();
		renderPanel();
		initSortable();
		bindCanvas();
		bindPanel();
		bindSettings();
		switchTab( 'fields' );
		refreshPreview();

		$frame.on( 'load', fitFrame );
		$root.on( 'click', '.bf-b-tabs .nav-tab', function () {
			switchTab( $( this ).data( 'tab' ) );
		} );
		$root.on( 'input', '#bf-b-title', function () {
			state.title = this.value;
			changed( { noPreview: true } );
		} );
		$root.on( 'change', '#bf-b-enabled', function () {
			state.enabled = this.checked;
			changed( { noPreview: true } );
		} );
		$root.on( 'focus', '.bf-b-shortcode', function () {
			this.select();
		} );
		$root.on( 'click', '#bf-b-save', save );
		$( document ).on( 'keydown', function ( e ) {
			if ( ( e.ctrlKey || e.metaKey ) && e.key === 's' ) {
				e.preventDefault();
				save();
			}
		} );
		$( window ).on( 'beforeunload', function () {
			return dirty ? true : undefined;
		} );
	} );
}( jQuery, window.wp ) );

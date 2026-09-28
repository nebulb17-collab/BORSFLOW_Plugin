/**
 * BorsFlow Forms – shared admin behaviour: delete confirmations, shortcode fields, CRM connection test.
 */
( function ( $ ) {
	'use strict';

	var i18n = ( window.borsflowAdmin && window.borsflowAdmin.i18n ) || {};

	$( document ).on( 'click', '.borsflow-confirm-delete-form', function ( e ) {
		if ( ! window.confirm( i18n.confirmDelete ) ) {
			e.preventDefault();
		}
	} );

	$( document ).on( 'click', '.borsflow-confirm-delete-sub', function ( e ) {
		if ( ! window.confirm( i18n.confirmDeleteSub ) ) {
			e.preventDefault();
		}
	} );

	// Bulk "Delete permanently" on the submissions table.
	$( document ).on( 'submit', 'form', function ( e ) {
		var $form = $( this );
		var action = $form.find( 'select[name="action"]' ).val();
		var action2 = $form.find( 'select[name="action2"]' ).val();
		if ( ( action === 'delete' || action2 === 'delete' ) && $form.find( 'input[name="ids[]"]:checked' ).length ) {
			if ( ! window.confirm( i18n.confirmDeleteSubs ) ) {
				e.preventDefault();
			}
		}
	} );

	$( document ).on( 'focus', '.borsflow-shortcode', function () {
		this.select();
	} );

	$( '#borsflow-test-connection' ).on( 'click', function () {
		var $btn = $( this ).prop( 'disabled', true );
		var $out = $( '#borsflow-test-result' ).removeClass( 'is-success is-error' ).text( i18n.testing );
		window.wp.apiFetch( {
			path: '/borsflow/v1/admin/test-connection',
			method: 'POST',
			data: {
				base_url: $( '#bf-crm-base' ).val(),
				api_key: $( '#bf-crm-api-key' ).val(),
			},
		} ).then( function ( res ) {
			$out.addClass( res.success ? 'is-success' : 'is-error' ).text( res.message );
		} ).catch( function ( err ) {
			$out.addClass( 'is-error' ).text( ( err && err.message ) || i18n.requestFailed );
		} ).finally( function () {
			$btn.prop( 'disabled', false );
		} );
	} );
}( jQuery ) );

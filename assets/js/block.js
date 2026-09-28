/**
 * BorsFlow Forms – Gutenberg block `borsflow/form`.
 *
 * Written without JSX so it needs no build step. The form picker lives in the
 * block sidebar (InspectorControls); the canvas shows a server-side render.
 */
( function ( wp ) {
	'use strict';

	var el = wp.element.createElement;
	var __ = wp.i18n.__;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var useBlockProps = wp.blockEditor.useBlockProps;
	var PanelBody = wp.components.PanelBody;
	var SelectControl = wp.components.SelectControl;
	var Placeholder = wp.components.Placeholder;
	var ExternalLink = wp.components.ExternalLink;
	var ServerSideRender = wp.serverSideRender;

	var data = window.borsflowBlock || { forms: [], newFormUrl: '' };
	var options = [ { value: 0, label: __( '— Select a form —', 'borsflow-forms' ) } ].concat(
		data.forms.map( function ( f ) {
			return { value: f.id, label: f.title };
		} )
	);

	function picker( props ) {
		return el( SelectControl, {
			label: __( 'Form', 'borsflow-forms' ),
			value: props.attributes.formId,
			options: options,
			onChange: function ( v ) {
				props.setAttributes( { formId: parseInt( v, 10 ) || 0 } );
			},
			__nextHasNoMarginBottom: true,
		} );
	}

	wp.blocks.registerBlockType( 'borsflow/form', {
		apiVersion: 2,
		title: __( 'BorsFlow Form', 'borsflow-forms' ),
		description: __( 'Embed a form built with BorsFlow Forms.', 'borsflow-forms' ),
		category: 'widgets',
		icon: 'feedback',
		keywords: [ 'form', 'contact', 'crm', 'lead' ],
		attributes: {
			formId: { type: 'number', default: 0 },
		},
		supports: { html: false, align: [ 'wide', 'full' ] },

		edit: function ( props ) {
			var blockProps = useBlockProps();
			var inspector = el(
				InspectorControls,
				null,
				el(
					PanelBody,
					{ title: __( 'Form settings', 'borsflow-forms' ), initialOpen: true },
					picker( props ),
					el( 'p', null, el( ExternalLink, { href: data.newFormUrl }, __( 'Create a new form', 'borsflow-forms' ) ) )
				)
			);

			if ( ! props.attributes.formId ) {
				return el(
					'div',
					blockProps,
					inspector,
					el(
						Placeholder,
						{ icon: 'feedback', label: __( 'BorsFlow Form', 'borsflow-forms' ), instructions: data.forms.length ? __( 'Choose a form to display.', 'borsflow-forms' ) : __( 'You have not created any forms yet.', 'borsflow-forms' ) },
						data.forms.length ? picker( props ) : el( ExternalLink, { href: data.newFormUrl }, __( 'Create a form', 'borsflow-forms' ) )
					)
				);
			}

			return el(
				'div',
				blockProps,
				inspector,
				el( 'div', { style: { pointerEvents: 'none' } }, el( ServerSideRender, { block: 'borsflow/form', attributes: props.attributes } ) )
			);
		},

		save: function () {
			return null; // Rendered in PHP.
		},
	} );
}( window.wp ) );

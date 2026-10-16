/* b83 Shortcodes – Backend */
( function ( $ ) {
	'use strict';

	var cfg = window.b83Shortcodes || { i18n: {} };
	var i18n = cfg.i18n || {};
	var dirty = false;

	/* Kopieren-Knöpfe */
	$( document ).on( 'click', '.b83-sc-copy', function () {
		var $btn = $( this );
		var text = String( $btn.attr( 'data-copy' ) || '' );
		var label = $btn.data( 'label' ) || $btn.text();
		$btn.data( 'label', label );

		var done = function () {
			$btn.text( i18n.copied || 'OK' );
			setTimeout( function () { $btn.text( label ); }, 1500 );
		};

		if ( navigator.clipboard && window.isSecureContext ) {
			navigator.clipboard.writeText( text ).then( done, function () { window.alert( i18n.copyFailed ); } );
		} else {
			var $tmp = $( '<textarea readonly>' ).val( text ).css( { position: 'fixed', left: '-9999px' } ).appendTo( 'body' );
			$tmp[ 0 ].select();
			try { document.execCommand( 'copy' ); done(); } catch ( e ) { window.alert( i18n.copyFailed ); }
			$tmp.remove();
		}
	} );

	/* Rückfragen */
	$( document ).on( 'click', '.b83-sc-confirm-delete', function ( e ) {
		if ( ! window.confirm( i18n.confirmDelete ) ) { e.preventDefault(); }
	} );
	$( document ).on( 'submit', 'form.b83-sc-confirm-restore', function ( e ) {
		if ( ! window.confirm( i18n.confirmRestore ) ) { e.preventDefault(); }
	} );
	$( document ).on( 'submit', 'form.b83-sc-confirm-import', function ( e ) {
		if ( ! window.confirm( i18n.confirmImport ) ) { e.preventDefault(); }
	} );

	/* Vorschau des Shortcode-Namens (gleiche Regeln wie beim Speichern, ohne Umlaut-Umschrift) */
	function normalizeTag( value ) {
		var map = { 'ä': 'ae', 'ö': 'oe', 'ü': 'ue', 'ß': 'ss' };
		return String( value || '' )
			.toLowerCase()
			.replace( /[äöüß]/g, function ( c ) { return map[ c ]; } )
			.normalize( 'NFD' ).replace( /[̀-ͯ]/g, '' )
			.replace( /[^a-z0-9_]+/g, '_' )
			.substring( 0, 64 )
			.replace( /^_+|_+$/g, '' );
	}

	function updatePreview() {
		var tag = normalizeTag( $( '#entry_tag' ).val() ) || normalizeTag( $( '#entry_title' ).val() );
		var code = '[' + ( tag || '…' ) + ']';
		$( '#b83-sc-preview' ).text( code );
		$( '#b83-sc-preview-copy' ).attr( 'data-copy', code );
	}
	$( document ).on( 'input', '#entry_tag, #entry_title', updatePreview );

	/* Code-Editor (CodeMirror aus WordPress) */
	$( function () {
		if ( cfg.codeEditor && window.wp && wp.codeEditor ) {
			$( 'textarea.b83-sc-code' ).each( function () {
				var editor = wp.codeEditor.initialize( this, cfg.codeEditor );
				editor.codemirror.on( 'change', function ( cm ) {
					cm.save();
					dirty = true;
				} );
			} );
		}

		var $form = $( '#b83-sc-form' );
		if ( $form.length ) {
			$form.on( 'input change', ':input', function () { dirty = true; } );
			$form.on( 'submit', function () { dirty = false; } );
			$( window ).on( 'beforeunload', function () {
				if ( dirty ) { return i18n.leave; }
			} );
		}
	} );
}( jQuery ) );

/* global jQuery, wwproAdmin */
( function ( $ ) {
	'use strict';

	/* ---------- Tier repeater (product editor + category forms) ---------- */

	function nextIndex( $tiers ) {
		var max = -1;
		$tiers.find( 'tbody .wwpro-tier-row input[name$="[qty]"]' ).each( function () {
			var m = this.name.match( /\[rows\]\[(\d+)\]\[qty\]$/ );
			if ( m && parseInt( m[1], 10 ) > max ) {
				max = parseInt( m[1], 10 );
			}
		} );
		return max + 1;
	}

	$( document ).on( 'click', '.wwpro-tier-add', function ( e ) {
		e.preventDefault();
		var $tiers = $( this ).closest( '.wwpro-tiers' );
		var html   = $tiers.find( '.wwpro-tier-template' ).html().replace( /__i__/g, nextIndex( $tiers ) );
		$tiers.find( 'tbody' ).append( html );
		$tiers.find( '.wwpro-tiers-toggle input' ).prop( 'checked', true );
		$tiers.find( '.wwpro-tiers-panel' ).show();
	} );

	$( document ).on( 'click', '.wwpro-tier-remove', function ( e ) {
		e.preventDefault();
		$( this ).closest( 'tr' ).remove();
	} );

	$( document ).on( 'change', '.wwpro-tiers-toggle input', function () {
		var $tiers = $( this ).closest( '.wwpro-tiers' );
		$tiers.find( '.wwpro-tiers-panel' ).toggle( this.checked );
		if ( this.checked && 0 === $tiers.find( 'tbody .wwpro-tier-row' ).length ) {
			$tiers.find( '.wwpro-tier-add' ).trigger( 'click' );
		}
	} );

	// Reset the "add category" form after WordPress created the term via AJAX.
	$( document ).ajaxComplete( function ( event, xhr, settings ) {
		if ( settings.data && settings.data.indexOf( 'action=add-tag' ) !== -1 && settings.data.indexOf( 'taxonomy=product_cat' ) !== -1 ) {
			$( '#addtag .wwpro-tiers tbody' ).empty();
			$( '#addtag .wwpro-tiers-toggle input' ).prop( 'checked', false );
			$( '#addtag .wwpro-tiers-panel' ).hide();
		}
	} );

	/* ---------- Roles ---------- */

	$( document ).on( 'submit', '.wwpro-delete-form', function () {
		return window.confirm( wwproAdmin.i18n.confirmDelete );
	} );

	/* ---------- Import ---------- */

	var $form = $( '#wwpro-import-form' );
	if ( ! $form.length ) {
		return;
	}

	var $progress = $( '#wwpro-import-progress' ),
		$bar      = $progress.find( '.wwpro-progress-bar span' ),
		$label    = $progress.find( '.wwpro-progress-label' ),
		$log      = $progress.find( '.wwpro-import-log' ),
		$start    = $( '#wwpro-import-start' ),
		stepOrder = [ 'roles', 'products', 'discounts', 'categories', 'global', 'users', 'done' ];

	function buildConfig() {
		var roles = {};
		$form.find( 'tr[data-src]' ).each( function () {
			var $row   = $( this ),
				target = $row.find( '.wwpro-import-target' ).val();
			if ( 'skip' === target ) {
				return;
			}
			roles[ $row.data( 'src' ) ] = {
				target: target,
				name: $row.find( '.wwpro-import-name' ).val()
			};
		} );
		return {
			roles: roles,
			overwrite: $( '#wwpro-import-overwrite' ).is( ':checked' ),
			migrate_users: $( '#wwpro-import-users' ).is( ':checked' )
		};
	}

	function appendLog( lines ) {
		$.each( lines || [], function ( i, line ) {
			$log.append( document.createTextNode( line + '\n' ) );
		} );
		$log.scrollTop( $log[0].scrollHeight );
	}

	function setProgress( step ) {
		var idx = stepOrder.indexOf( step );
		idx     = idx < 0 ? 0 : idx;
		$bar.css( 'width', Math.round( ( idx / ( stepOrder.length - 1 ) ) * 100 ) + '%' );
		$label.text( wwproAdmin.i18n.working + ' (' + step + ')' );
	}

	function runStep( state, config ) {
		setProgress( state.step );

		$.post( wwproAdmin.ajaxUrl, {
			action: 'wwpro_import_step',
			nonce: wwproAdmin.importNonce,
			step: state.step,
			role_index: state.role_index,
			offset: state.offset,
			config: JSON.stringify( config )
		} ).done( function ( response ) {
			if ( ! response || ! response.success ) {
				appendLog( [ ( response && response.data && response.data.message ) ? response.data.message : wwproAdmin.i18n.importError ] );
				$label.text( wwproAdmin.i18n.importError );
				$start.prop( 'disabled', false );
				return;
			}

			appendLog( response.data.log );

			if ( response.data.done ) {
				$bar.css( 'width', '100%' );
				$label.text( wwproAdmin.i18n.importDone );
				$start.prop( 'disabled', false );
				return;
			}

			runStep( response.data.next, response.data.config || config );
		} ).fail( function () {
			appendLog( [ wwproAdmin.i18n.importError ] );
			$label.text( wwproAdmin.i18n.importError );
			$start.prop( 'disabled', false );
		} );
	}

	$start.on( 'click', function () {
		var config = buildConfig();
		if ( $.isEmptyObject( config.roles ) ) {
			return;
		}
		if ( ! window.confirm( wwproAdmin.i18n.confirmImport ) ) {
			return;
		}
		$start.prop( 'disabled', true );
		$progress.prop( 'hidden', false );
		$log.empty();
		runStep( { step: 'roles', role_index: 0, offset: 0 }, config );
	} );
} )( jQuery );

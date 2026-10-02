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

	/* ---------- Roles and partners ---------- */

	$( document ).on( 'submit', '.wwpro-delete-form', function () {
		return window.confirm( wwproAdmin.i18n.confirmDelete );
	} );

	$( document ).on( 'submit', '.wwpro-delete-partner-form', function () {
		return window.confirm( wwproAdmin.i18n.confirmDeletePartner );
	} );

	// Only show the fields that belong to the selected shop system.
	function syncPartnerType() {
		var $select = $( '.wwpro-partner-type' );
		if ( ! $select.length ) {
			return;
		}
		var type = $select.val();
		$( '.wwpro-type-woocommerce' ).toggle( 'woocommerce' === type );
		$( '.wwpro-type-shopify' ).toggle( 'shopify' === type );
	}

	$( document ).on( 'change', '.wwpro-partner-type', syncPartnerType );
	syncPartnerType();

	/* ---------- Progress panel shared by every batch runner ---------- */

	function panel( selector ) {
		var $root = $( selector );

		return {
			exists: $root.length > 0,
			show: function () {
				$root.prop( 'hidden', false );
				$root.find( '.wwpro-import-log' ).empty();
			},
			percent: function ( value ) {
				$root.find( '.wwpro-progress-bar span' ).css( 'width', Math.max( 0, Math.min( 100, value ) ) + '%' );
			},
			label: function ( text ) {
				$root.find( '.wwpro-progress-label' ).text( text );
			},
			log: function ( lines ) {
				var $log = $root.find( '.wwpro-import-log' );
				$.each( lines || [], function ( i, line ) {
					$log.append( document.createTextNode( line + '\n' ) );
				} );
				if ( $log.length ) {
					$log.scrollTop( $log[0].scrollHeight );
				}
			}
		};
	}

	function errorMessage( response ) {
		if ( response && response.data && response.data.message ) {
			return response.data.message;
		}
		return wwproAdmin.i18n.importError;
	}

	/**
	 * Drive a batched AJAX job.
	 *
	 * @param {Object}   options            Runner options.
	 * @param {Object}   options.ui         Progress panel.
	 * @param {Function} options.request    Returns the POST payload for a state.
	 * @param {Object}   options.state      Initial state.
	 * @param {string}   options.doneText   Label shown when finished.
	 * @param {Function} options.onFinish   Called when the job ends (success or not).
	 * @param {Function} [options.nextState] Builds the next state from the response.
	 */
	function runJob( options ) {
		function step( state ) {
			options.ui.label( wwproAdmin.i18n.working );

			$.post( wwproAdmin.ajaxUrl, options.request( state ) ).done( function ( response ) {
				if ( ! response || ! response.success ) {
					options.ui.log( [ errorMessage( response ) ] );
					options.ui.label( errorMessage( response ) );
					options.onFinish();
					return;
				}

				options.ui.log( response.data.log );

				if ( typeof response.data.percent !== 'undefined' ) {
					options.ui.percent( response.data.percent );
				}

				if ( response.data.done ) {
					options.ui.percent( 100 );
					options.ui.label( options.doneText );
					options.onFinish();
					return;
				}

				step( options.nextState ? options.nextState( response.data, state ) : ( response.data.next || state ) );
			} ).fail( function () {
				options.ui.log( [ wwproAdmin.i18n.importError ] );
				options.ui.label( wwproAdmin.i18n.importError );
				options.onFinish();
			} );
		}

		options.ui.show();
		step( options.state );
	}

	/* ---------- Import from Wholesale Suite ---------- */

	( function () {
		var $form = $( '#wwpro-import-form' );
		if ( ! $form.length ) {
			return;
		}

		var ui        = panel( '#wwpro-import-progress' ),
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

		$start.on( 'click', function () {
			var config = buildConfig();
			if ( $.isEmptyObject( config.roles ) ) {
				return;
			}
			if ( ! window.confirm( wwproAdmin.i18n.confirmImport ) ) {
				return;
			}

			$start.prop( 'disabled', true );

			runJob( {
				ui: ui,
				state: { step: 'roles', role_index: 0, offset: 0, config: config },
				doneText: wwproAdmin.i18n.importDone,
				onFinish: function () {
					$start.prop( 'disabled', false );
				},
				// The server hands back a config with the resolved role mapping,
				// so the next step works with the registered target roles.
				nextState: function ( data, state ) {
					var next = data.next || state;
					next.config = data.config || state.config;
					return next;
				},
				request: function ( state ) {
					ui.percent( Math.round( ( Math.max( 0, stepOrder.indexOf( state.step ) ) / ( stepOrder.length - 1 ) ) * 100 ) );

					return {
						action: 'wwpro_import_step',
						nonce: wwproAdmin.importNonce,
						step: state.step,
						role_index: state.role_index,
						offset: state.offset,
						config: JSON.stringify( state.config || config )
					};
				}
			} );
		} );
	} )();

	/* ---------- Bulk price change ---------- */

	( function () {
		var $form = $( '#wwpro-bulk-price-form' );
		if ( ! $form.length ) {
			return;
		}

		var ui     = panel( '#wwpro-bulk-progress' ),
			$start = $( '#wwpro-bulk-start' );

		function buildJob() {
			return {
				role: $( '#wwpro-bulk-role' ).val(),
				direction: $( '#wwpro-bulk-direction' ).val(),
				percent: $( '#wwpro-bulk-percent' ).val(),
				base: $( '#wwpro-bulk-base' ).val(),
				scope: $form.find( 'input[name="scope"]:checked' ).val(),
				category: $( '#wwpro-bulk-category' ).val(),
				children: $( '#wwpro-bulk-children' ).is( ':checked' ) ? 1 : 0,
				rounding: $( '#wwpro-bulk-rounding' ).val()
			};
		}

		$start.on( 'click', function () {
			var job = buildJob();

			if ( 'category' === job.scope && ( ! job.category || '0' === String( job.category ) ) ) {
				window.alert( wwproAdmin.i18n.pickCategory );
				return;
			}

			if ( ! window.confirm( wwproAdmin.i18n.confirmBulk ) ) {
				return;
			}

			$start.prop( 'disabled', true );

			runJob( {
				ui: ui,
				state: { offset: 0, changed: 0, skipped: 0 },
				doneText: wwproAdmin.i18n.bulkDone,
				onFinish: function () {
					$start.prop( 'disabled', false );
				},
				request: function ( state ) {
					return {
						action: 'wwpro_bulk_price_step',
						nonce: wwproAdmin.bulkNonce,
						job: JSON.stringify( job ),
						offset: state.offset,
						changed: state.changed,
						skipped: state.skipped
					};
				}
			} );
		} );
	} )();

	/* ---------- Shopify push ---------- */

	( function () {
		var $buttons = $( '.wwpro-shopify-sync' );
		if ( ! $buttons.length ) {
			return;
		}

		var ui = panel( '#wwpro-shopify-progress' );

		$buttons.on( 'click', function () {
			var $button = $( this ),
				partner = $button.data( 'partner' );

			if ( ! window.confirm( wwproAdmin.i18n.confirmShopify ) ) {
				return;
			}

			$buttons.prop( 'disabled', true );

			runJob( {
				ui: ui,
				state: { page: 1, created: 0, updated: 0, failed: 0 },
				doneText: wwproAdmin.i18n.syncDone,
				onFinish: function () {
					$buttons.prop( 'disabled', false );
				},
				request: function ( state ) {
					return {
						action: 'wwpro_shopify_step',
						nonce: wwproAdmin.shopifyNonce,
						partner: partner,
						page: state.page,
						created: state.created,
						updated: state.updated,
						failed: state.failed
					};
				}
			} );
		} );
	} )();
} )( jQuery );

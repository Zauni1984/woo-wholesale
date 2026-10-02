/* global jQuery, wwpartAdmin */
( function ( $ ) {
	'use strict';

	/**
	 * Progress panel used by every batch runner on these screens.
	 *
	 * @param {string} selector Root element of the panel.
	 * @return {Object} Panel helper.
	 */
	function panel( selector ) {
		var $root = $( selector );

		return {
			exists: $root.length > 0,
			show: function () {
				$root.prop( 'hidden', false );
				$root.find( '.wwpart-log' ).empty();
			},
			percent: function ( value ) {
				$root.find( '.wwpart-progress-bar span' ).css( 'width', Math.max( 0, Math.min( 100, value ) ) + '%' );
			},
			label: function ( text ) {
				$root.find( '.wwpart-progress-label' ).text( text );
			},
			log: function ( lines ) {
				var $log = $root.find( '.wwpart-log' );
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
		return wwpartAdmin.i18n.error;
	}

	/**
	 * Drive a batched AJAX job until the server reports it is done.
	 *
	 * @param {Object}   options          Runner options.
	 * @param {Object}   options.ui       Progress panel.
	 * @param {Object}   options.state    Initial state.
	 * @param {Function} options.request  Builds the POST payload from a state.
	 * @param {string}   options.doneText Label shown when finished.
	 * @param {Function} options.onFinish Called when the job ends, success or not.
	 */
	function runJob( options ) {
		function step( state ) {
			options.ui.label( wwpartAdmin.i18n.working );

			$.post( wwpartAdmin.ajaxUrl, options.request( state ) ).done( function ( response ) {
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

				step( response.data.next || state );
			} ).fail( function () {
				options.ui.log( [ wwpartAdmin.i18n.error ] );
				options.ui.label( wwpartAdmin.i18n.error );
				options.onFinish();
			} );
		}

		options.ui.show();
		step( options.state );
	}

	/* ---------- Sync ---------- */

	( function () {
		var $start = $( '#wwpart-sync-start' );
		if ( ! $start.length ) {
			return;
		}

		var ui = panel( '#wwpart-sync-progress' );

		$start.on( 'click', function () {
			if ( ! window.confirm( wwpartAdmin.i18n.confirmSync ) ) {
				return;
			}

			$start.prop( 'disabled', true );

			runJob( {
				ui: ui,
				state: { step: 'connect', page: 1, offset: 0, total: 0, created: 0, updated: 0, skipped: 0, failed: 0 },
				doneText: wwpartAdmin.i18n.syncDone,
				onFinish: function () {
					$start.prop( 'disabled', false );
				},
				request: function ( state ) {
					return {
						action: 'wwpart_sync_step',
						nonce: wwpartAdmin.syncNonce,
						step: state.step,
						page: state.page,
						offset: state.offset,
						total: state.total,
						created: state.created,
						updated: state.updated,
						skipped: state.skipped,
						failed: state.failed
					};
				}
			} );
		} );
	} )();

	/* ---------- Markup ---------- */

	( function () {
		var $form = $( '#wwpart-markup-form' );
		if ( ! $form.length ) {
			return;
		}

		var ui     = panel( '#wwpart-markup-progress' ),
			$start = $( '#wwpart-markup-start' );

		$start.on( 'click', function () {
			var job = {
				direction: $( '#wwpart-direction' ).val(),
				percent: $( '#wwpart-percent' ).val(),
				scope: $form.find( 'input[name="wwpart-scope"]:checked' ).val(),
				category: $( '#wwpart-category' ).val(),
				children: $( '#wwpart-children' ).is( ':checked' ) ? 1 : 0,
				overwrite: $( '#wwpart-overwrite' ).is( ':checked' ) ? 1 : 0
			};

			if ( 'category' === job.scope && ( ! job.category || '0' === String( job.category ) ) ) {
				window.alert( wwpartAdmin.i18n.pickCategory );
				return;
			}

			if ( ! window.confirm( wwpartAdmin.i18n.confirmMarkup ) ) {
				return;
			}

			$start.prop( 'disabled', true );

			runJob( {
				ui: ui,
				state: { offset: 0, changed: 0 },
				doneText: wwpartAdmin.i18n.markupDone,
				onFinish: function () {
					$start.prop( 'disabled', false );
				},
				request: function ( state ) {
					return {
						action: 'wwpart_markup_step',
						nonce: wwpartAdmin.markupNonce,
						job: JSON.stringify( job ),
						offset: state.offset,
						changed: state.changed
					};
				}
			} );
		} );
	} )();

	/* ---------- Image check ---------- */

	( function () {
		var $start = $( '#wwpart-image-start' ),
			$force = $( '#wwpart-image-force' );

		if ( ! $start.length && ! $force.length ) {
			return;
		}

		var ui = panel( '#wwpart-image-progress' );

		function run( force ) {
			$start.prop( 'disabled', true );
			$force.prop( 'disabled', true );

			runJob( {
				ui: ui,
				state: { step: 'verify', offset: 0, queued: 0, downloaded: 0, failed: 0, force: force ? 1 : 0 },
				doneText: wwpartAdmin.i18n.imagesDone,
				onFinish: function () {
					$start.prop( 'disabled', false );
					$force.prop( 'disabled', false );
				},
				request: function ( state ) {
					return {
						action: 'wwpart_image_step',
						nonce: wwpartAdmin.imageNonce,
						step: state.step,
						offset: state.offset,
						queued: state.queued,
						downloaded: state.downloaded,
						failed: state.failed,
						force: state.force
					};
				}
			} );
		}

		$start.on( 'click', function () {
			run( false );
		} );

		$force.on( 'click', function () {
			run( true );
		} );
	} )();
} )( jQuery );

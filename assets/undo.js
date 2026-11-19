/**
 * Axle Trash: a snackbar with Undo after the editor deletes a template, template part,
 * pattern, navigation menu or font for good. The server parks the item and adds
 * `axle_trash: { id, restore }` to the REST response.
 */
( function ( wp ) {
	'use strict';

	if ( ! wp || ! wp.apiFetch || ! wp.data ) {
		return;
	}

	var strings = window.axleTrashL10n || {};
	var ROUTE = /^\/wp\/v2\/(templates|template-parts|blocks|navigation|font-families)(\/|\?|$)|^\/wp\/v2\/font-families\/\d+\/font-faces\//;

	function routeOf( options ) {
		if ( options.path ) {
			return options.path;
		}
		if ( options.url ) {
			var match = String( options.url ).match( /rest_route=([^&]+)/ );
			if ( match ) {
				return decodeURIComponent( match[ 1 ] );
			}
			var found = String( options.url ).match( /\/wp-json(\/wp\/v2\/[^?]*)/ );
			return found ? found[ 1 ] : '';
		}
		return '';
	}

	function notices() {
		return wp.data.dispatch( 'core/notices' );
	}

	function undo( info ) {
		wp.apiFetch( { path: '/axle-trash/v1/items/' + info.id + '/restore', method: 'POST' } ).then(
			function () {
				try {
					wp.data.dispatch( 'core' ).invalidateResolutionForStore();
				} catch ( e ) {
					// The store cache is refreshed on the next load.
				}
				notices().createSuccessNotice( strings.restored || 'Restored.', { type: 'snackbar', id: 'axle-trash-' + info.id } );
				wp.a11y && wp.a11y.speak( strings.restored || 'Restored.' );
				wp.hooks.doAction( 'axleTrash.restored', info );
			},
			function ( error ) {
				notices().createErrorNotice( ( error && error.message ) || strings.failed || 'Could not restore it.', { type: 'snackbar' } );
			}
		);
	}

	wp.apiFetch.use( function ( options, next ) {
		var path = routeOf( options );

		if ( 'DELETE' !== String( options.method || '' ).toUpperCase() || ! ROUTE.test( path ) ) {
			return next( options );
		}

		return next( options ).then( function ( result ) {
			var read = result && 'function' === typeof result.clone ? result.clone().json() : Promise.resolve( result );

			read.then( function ( data ) {
				var info = data && data.axle_trash;
				if ( ! info || ! info.id ) {
					return;
				}
				var notice = wp.hooks.applyFilters(
					'axleTrash.undoNotice',
					{
						message: strings.moved || 'Moved to the Axle trash.',
						label: strings.undo || 'Undo',
					},
					info
				);
				if ( ! notice ) {
					return;
				}
				notices().createInfoNotice( notice.message, {
					type: 'snackbar',
					id: 'axle-trash-' + info.id,
					explicitDismiss: true,
					actions: [
						{
							label: notice.label,
							onClick: function () {
								undo( info );
							},
						},
					],
				} );
				wp.a11y && wp.a11y.speak( notice.message );
				wp.hooks.doAction( 'axleTrash.parked', info );
			} ).catch( function () {} );

			return result;
		} );
	} );
}( window.wp ) );

/**
 * Thin wrapper around @wordpress/api-fetch scoped to the Optrion namespace.
 */

import apiFetch from '@wordpress/api-fetch';

const config = window.optrionConfig || {};

if ( config.nonce ) {
	apiFetch.use( apiFetch.createNonceMiddleware( config.nonce ) );
}
if ( config.restRoot ) {
	apiFetch.use( apiFetch.createRootURLMiddleware( config.restRoot ) );
}

const request = ( path, options = {} ) =>
	apiFetch( { path: path.replace( /^\//, '' ), ...options } );

export const api = {
	stats: () => request( 'stats' ),
	listOptions: ( query = {} ) => {
		const usp = new URLSearchParams();
		Object.entries( query ).forEach( ( [ k, v ] ) => {
			if ( v !== undefined && v !== null && v !== '' ) {
				usp.set( k, String( v ) );
			}
		} );
		const q = usp.toString();
		return request( 'options' + ( q ? '?' + q : '' ) );
	},
	deleteOptions: ( names ) =>
		request( 'options', {
			method: 'DELETE',
			data: { names },
		} ),
	export: ( names, scoreMin ) =>
		request( 'export', {
			method: 'POST',
			data: { names, score_min: scoreMin },
		} ),
	import: ( payload, overwrite ) =>
		request( 'import', {
			method: 'POST',
			data: { payload, overwrite },
		} ),
	importPreview: ( payload ) =>
		request( 'import/preview', {
			method: 'POST',
			data: { payload },
		} ),
	listQuarantine: ( status = 'active' ) =>
		request( 'quarantine?status=' + encodeURIComponent( status ) ),
	createQuarantine: ( names, days ) =>
		request( 'quarantine', {
			method: 'POST',
			data: { names, days },
		} ),
	restoreQuarantine: ( ids ) =>
		request( 'quarantine/restore', {
			method: 'POST',
			data: { ids },
		} ),
	deleteQuarantine: ( ids ) =>
		request( 'quarantine', {
			method: 'DELETE',
			data: { ids },
		} ),
};

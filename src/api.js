/**
 * Thin wrapper around @wordpress/api-fetch scoped to the Optrion namespace.
 */

import apiFetch from '@wordpress/api-fetch';

const config = window.optrionConfig || {};
const NAMESPACE = ( config.restNamespace || 'optrion/v1' ).replace( /^\/|\/$/g, '' );

if ( config.nonce ) {
	apiFetch.use( apiFetch.createNonceMiddleware( config.nonce ) );
}

const request = ( path, options = {} ) => {
	const clean = path.replace( /^\//, '' );
	return apiFetch( { path: '/' + NAMESPACE + '/' + clean, ...options } );
};

export const api = {
	stats: () => request( 'stats' ),
	listOptions: ( query = {} ) => {
		const usp = new URLSearchParams();
		Object.entries( query ).forEach( ( [ k, v ] ) => {
			if ( v === undefined || v === null || v === '' || v === false ) {
				return;
			}
			usp.set( k, String( v ) );
		} );
		const q = usp.toString();
		return request( 'options' + ( q ? '?' + q : '' ) );
	},
	deleteOptions: ( names ) =>
		request( 'options', {
			method: 'DELETE',
			data: { names },
		} ),
	export: ( names ) =>
		request( 'export', {
			method: 'POST',
			data: { names },
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

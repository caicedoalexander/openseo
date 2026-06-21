/**
 * REST client for the OpenSEO settings option.
 *
 * Relative paths let apiFetch's automatic root-URL + X-WP-Nonce middleware
 * (active in wp-admin via the wp-api-fetch dependency) handle auth.
 */
import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';

export function getSettings() {
	return apiFetch( { path: '/openseo/v1/settings' } );
}

export function saveSettings( values ) {
	return apiFetch( {
		path: '/openseo/v1/settings',
		method: 'POST',
		data: values,
	} );
}

export function getRedirects( { page = 1, perPage = 20, search = '' } = {} ) {
	return apiFetch( {
		path: addQueryArgs( '/openseo/v1/redirects', {
			page,
			per_page: perPage,
			search,
		} ),
	} );
}

export function createRedirect( data ) {
	return apiFetch( { path: '/openseo/v1/redirects', method: 'POST', data } );
}

export function updateRedirect( id, data ) {
	return apiFetch( {
		path: `/openseo/v1/redirects/${ id }`,
		method: 'PUT',
		data,
	} );
}

export function deleteRedirect( id ) {
	return apiFetch( {
		path: `/openseo/v1/redirects/${ id }`,
		method: 'DELETE',
	} );
}

export function bulkRedirects( action, ids ) {
	return apiFetch( {
		path: '/openseo/v1/redirects/bulk',
		method: 'POST',
		data: { action, ids },
	} );
}

export function getNotfound( { page = 1, perPage = 20 } = {} ) {
	return apiFetch( {
		path: addQueryArgs( '/openseo/v1/notfound', {
			page,
			per_page: perPage,
		} ),
	} );
}

export function deleteNotfound( id ) {
	return apiFetch( {
		path: `/openseo/v1/notfound/${ id }`,
		method: 'DELETE',
	} );
}

export function clearNotfound() {
	return apiFetch( { path: '/openseo/v1/notfound', method: 'DELETE' } );
}

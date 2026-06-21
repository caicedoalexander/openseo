/**
 * REST client for the OpenSEO settings option.
 *
 * Relative paths let apiFetch's automatic root-URL + X-WP-Nonce middleware
 * (active in wp-admin via the wp-api-fetch dependency) handle auth.
 */
import apiFetch from '@wordpress/api-fetch';

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

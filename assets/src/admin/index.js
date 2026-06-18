/**
 * OpenSEO admin settings entry point.
 *
 * Loaded only on the OpenSEO settings screen. @wordpress/scripts externalizes
 * the @wordpress/* imports to the global `wp` object and records them as script
 * dependencies in the generated admin-settings.asset.php.
 */
import domReady from '@wordpress/dom-ready';

import './style.scss';

domReady( () => {
	// Progressive enhancement for the settings screen will live here
	// (e.g. an "Generate with AI" button wired to the Abilities REST route).
	// eslint-disable-next-line no-console
	console.debug( 'OpenSEO admin assets loaded.' );
} );

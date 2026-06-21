/**
 * OpenSEO admin app entry. Mounts the React view named by the server-set
 * #openseo-app[data-view] node on each OpenSEO settings screen.
 */
import domReady from '@wordpress/dom-ready';
import { createRoot } from '@wordpress/element';
import { App } from './App';

import './style.scss';

domReady( () => {
	const el = document.getElementById( 'openseo-app' );
	if ( ! el ) {
		return;
	}
	createRoot( el ).render( <App view={ el.dataset.view || 'dashboard' } /> );
} );

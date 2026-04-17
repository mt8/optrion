/**
 * Optrion admin SPA entry point.
 *
 * Mounts a minimal React tree into the container provided by
 * Optrion\Admin_Page::render(). Subsequent UI work (issue #18) replaces
 * this placeholder with the full dashboard.
 */

import { createRoot } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import App from './App';
import './index.scss';

const config = window.optrionConfig || {};
const mount = document.getElementById( config.rootId || 'optrion-admin-root' );

if ( mount ) {
	createRoot( mount ).render( <App config={ config } /> );
} else {
	// eslint-disable-next-line no-console
	console.warn( __( 'Optrion mount node not found.', 'optrion' ) );
}

/**
 * Admin-chat widget entry.
 *
 * Mounts the <ChatApp/> React root in the admin footer and wires the admin bar
 * node to open the chat through the @wordpress/data store.
 */
import { createRoot } from '@wordpress/element';
import { dispatch } from '@wordpress/data';

import { STORE_NAME } from './store';
import ChatApp from './components/ChatApp';
import './style.scss';

/**
 * Mounts the React app onto the footer root node.
 */
function mountApp() {
	const root = document.getElementById( 'agent-mod-chat-root' );

	if ( ! root ) {
		return;
	}

	createRoot( root ).render( <ChatApp /> );
}

/**
 * Binds the admin bar link to open the chat modal.
 */
function bindToolbar() {
	const link = document.querySelector(
		'#wp-admin-bar-agent-mod-chat a'
	);

	if ( ! link ) {
		return;
	}

	link.addEventListener( 'click', ( event ) => {
		event.preventDefault();
		dispatch( STORE_NAME ).openChat();
	} );
}

document.addEventListener( 'DOMContentLoaded', () => {
	mountApp();
	bindToolbar();
} );

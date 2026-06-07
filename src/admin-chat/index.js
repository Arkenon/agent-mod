/**
 * Admin-chat widget entry.
 *
 * Mounts the chat into any container, plug-and-play. The conversation UI lives
 * in the presentation-agnostic <ChatPanel/>; this entry only decides *where* and
 * *in which wrapper* it renders:
 *
 *  - The admin-footer root (`#agent-mod-chat-root`) renders the modal variant
 *    and is opened from the admin bar (current behaviour).
 *  - Any element carrying `data-agent-mod-chat="inline|modal"` is mounted with
 *    the requested variant, so the chat can be dropped into a page, a sidebar,
 *    a meta box, etc. without writing any extra code.
 */
import { createRoot } from '@wordpress/element';
import { dispatch } from '@wordpress/data';

import { STORE_NAME } from './store';
import ChatApp from './components/ChatApp';
import ChatPanel from './components/ChatPanel';
import './style.scss';

/**
 * Available presentation variants.
 *
 * - `modal`: opens in a <Modal/>, toggled through the store (admin bar).
 * - `inline`: renders the panel right where it is placed.
 */
const VARIANTS = {
	modal: ChatApp,
	inline: ChatPanel,
};

/**
 * Renders a chat variant into the given node.
 *
 * @param {HTMLElement} node    Target container.
 * @param {string}      variant Variant key from VARIANTS; falls back to inline.
 */
function mount( node, variant ) {
	const Component = VARIANTS[ variant ] || ChatPanel;

	createRoot( node ).render( <Component /> );
}

/**
 * Mounts every chat container found on the page.
 */
function mountApp() {
	// Backwards-compatible admin-footer modal root.
	const footer = document.getElementById( 'agent-mod-chat-root' );

	if ( footer && ! footer.dataset.agentModChat ) {
		mount( footer, 'modal' );
	}

	// Plug-and-play containers anywhere on the page.
	document
		.querySelectorAll( '[data-agent-mod-chat]' )
		.forEach( ( node ) => mount( node, node.dataset.agentModChat ) );
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

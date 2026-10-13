import { createRoot } from '@wordpress/element';
import Setup from './Setup';
import './style.scss';

document.addEventListener( 'DOMContentLoaded', () => {
	const root = document.getElementById( 'agent-mod-setup-root' );
	if ( root ) {
		createRoot( root ).render( <Setup /> );
	}
} );

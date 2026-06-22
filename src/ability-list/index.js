import { createRoot } from '@wordpress/element';
import AbilityList from './AbilityList';
import './style.scss';

document.addEventListener( 'DOMContentLoaded', () => {
	const root = document.getElementById( 'agent-mod-abilities-root' );
	if ( root ) {
		createRoot( root ).render( <AbilityList /> );
	}
} );

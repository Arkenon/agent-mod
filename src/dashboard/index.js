import { createRoot } from '@wordpress/element';
import Dashboard from './Dashboard';
import './style.scss';

document.addEventListener( 'DOMContentLoaded', () => {
	const root = document.getElementById( 'agent-mod-dashboard-root' );
	if ( root ) {
		createRoot( root ).render( <Dashboard /> );
	}
} );

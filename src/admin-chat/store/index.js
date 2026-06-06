/**
 * Admin-chat @wordpress/data store registration.
 */
import { createReduxStore, register } from '@wordpress/data';

import reducer from './reducer';
import * as actions from './actions';
import * as selectors from './selectors';

export const STORE_NAME = 'agent-mod/chat';

export const store = createReduxStore( STORE_NAME, {
	reducer,
	actions,
	selectors,
} );

register( store );

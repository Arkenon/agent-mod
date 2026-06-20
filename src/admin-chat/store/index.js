/**
 * Admin-chat @wordpress/data store registration.
 */
import { createReduxStore, register, subscribe, select } from '@wordpress/data';

import reducer from './reducer';
import * as actions from './actions';
import * as selectors from './selectors';
import { saveProviderModels } from './persistence';

export const STORE_NAME = 'agent-mod/chat';

export const store = createReduxStore( STORE_NAME, {
	reducer,
	actions,
	selectors,
} );

register( store );

// Persist the provider model lists to localStorage whenever they change, so the
// picker is populated instantly on the next page load. The reference check keeps
// this to one write per actual change (the reducer returns a new map object only
// on SET_PROVIDER_MODELS), even though subscribe fires on every store update.
let lastModelsMap = select( STORE_NAME ).getProviderModelsMap();
subscribe( () => {
	const modelsMap = select( STORE_NAME ).getProviderModelsMap();

	if ( modelsMap !== lastModelsMap ) {
		lastModelsMap = modelsMap;
		saveProviderModels( modelsMap );
	}
} );

/**
 * Selectors for the admin-chat store.
 */

export function getMessages( state ) {
	return state.messages;
}

export function isLoading( state ) {
	return state.isLoading;
}

export function isChatOpen( state ) {
	return state.isOpen;
}

export function getError( state ) {
	return state.error;
}

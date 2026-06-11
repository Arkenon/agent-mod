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

export function isSiteContextEnabled( state ) {
	return state.isSiteContextEnabled;
}

export function getConversationId( state ) {
	return state.conversationId;
}

export function getAgents( state ) {
	return state.agents;
}

export function getSelectedAgentId( state ) {
	return state.selectedAgentId;
}

export function getPendingConfirmation( state ) {
	return state.pendingConfirmation;
}

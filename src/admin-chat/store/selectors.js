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

export function getSelectedProvider( state ) {
	return state.selectedProvider;
}

export function getSelectedModel( state ) {
	return state.selectedModel;
}

export function getSelectedMode( state ) {
	return state.selectedMode;
}

export function getProgress( state ) {
	return state.progress;
}

export function getAbilities( state ) {
	return state.abilities;
}

export function isAbilitiesLoading( state ) {
	return state.abilitiesLoading;
}

export function getProviderModels( state, providerId ) {
	return state.providerModels[ providerId ] || null;
}

export function getProviderModelsMap( state ) {
	return state.providerModels;
}

export function getModelsLoading( state ) {
	return state.modelsLoading;
}

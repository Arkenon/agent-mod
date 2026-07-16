/**
 * Selectors for the admin-chat store.
 */
import config from './config';

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

/**
 * Sums the per-turn token usage of every assistant message in the current
 * session. Naturally resets when messages are cleared (new topic).
 *
 * @param {Object} state Store state.
 * @return {{promptTokens: number, completionTokens: number, totalTokens: number}} Session totals.
 */
export function getSessionTokenUsage( state ) {
	return state.messages.reduce(
		( totals, message ) => {
			const usage = message.tokenUsage;
			if ( ! usage ) {
				return totals;
			}
			return {
				promptTokens: totals.promptTokens + ( usage.promptTokens || 0 ),
				completionTokens: totals.completionTokens + ( usage.completionTokens || 0 ),
				totalTokens: totals.totalTokens + ( usage.totalTokens || 0 ),
			};
		},
		{ promptTokens: 0, completionTokens: 0, totalTokens: 0 }
	);
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

/**
 * Settings resolved from the AgentMod settings page, localized once at page
 * load (see AIChatWidgetController::enqueueAssets()). Components read them
 * here instead of touching window.agentModChat directly.
 */

export function getRestPath() {
	return config.restPath || '';
}

export function getRestNamespace() {
	return config.restNamespace || 'agent-mod/v1';
}

export function getAbilitySource() {
	return config.defaults?.abilitySource || 'all';
}

/**
 * Abilities allowed by the "Selected Abilities" setting, resolved server-side
 * via wp_get_ability() (not the REST abilities list, so an ability missing
 * from REST discovery — e.g. core/get-user-info — is still included here).
 * Each item matches the @wordpress/abilities `Ability` shape.
 */
export function getSelectedAbilities() {
	return Array.isArray( config.defaults?.selectedAbilities ) ? config.defaults.selectedAbilities : [];
}

export function getAttachmentLimits() {
	const limits = config.attachments || {};
	return {
		maxBytes:  limits.maxBytes || 5242880,
		maxCount:  limits.maxCount || 5,
		mimeTypes: Array.isArray( limits.mimeTypes ) ? limits.mimeTypes : [],
	};
}

export function getStrings() {
	return config.strings || {};
}

export function getProviders() {
	return Array.isArray( config.providers ) ? config.providers : [];
}

export function getConnectorsUrl() {
	return config.connectorsUrl || '';
}

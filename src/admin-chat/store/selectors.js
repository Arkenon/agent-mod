/**
 * Selectors for the admin-chat store.
 */
import { __ } from '@wordpress/i18n';

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

/**
 * The full tray record of the currently selected agent, or null for the
 * Default Agent. Agent records may carry their own ability configuration
 * (`abilitySource` / `selectedAbilities`) so the ability tray can mirror
 * what the server will actually resolve for that agent.
 *
 * @param {Object} state Store state.
 * @return {Object|null} Selected agent record, or null.
 */
export function getSelectedAgent( state ) {
	if ( ! state.selectedAgentId ) {
		return null;
	}
	return state.agents.find( ( agent ) => agent.id === state.selectedAgentId ) || null;
}

/**
 * Ability source for the current chat session. When the selected agent
 * declares its own source it overrides the settings default — the same
 * precedence the server applies when resolving the agent's config.
 *
 * @param {Object} state Store state.
 * @return {string} 'all' or 'selected'.
 */
export function getAbilitySource( state ) {
	const agent = getSelectedAgent( state );
	if ( agent && ( 'all' === agent.abilitySource || 'selected' === agent.abilitySource ) ) {
		return agent.abilitySource;
	}
	return config.defaults?.abilitySource || 'all';
}

/**
 * Abilities allowed by the "Selected Abilities" setting, resolved server-side
 * via wp_get_ability() (not the REST abilities list, so an ability missing
 * from REST discovery — e.g. core/get-user-info — is still included here).
 * Each item matches the @wordpress/abilities `Ability` shape.
 *
 * When the selected agent carries its own `selectedAbilities` list it wins
 * over the settings default; when the agent leaves it empty the settings
 * list applies — mirroring the server-side fallback in AgentConfig.
 *
 * @param {Object} state Store state.
 * @return {Array} Ability records ({ name, label, meta }).
 */
export function getSelectedAbilities( state ) {
	const agent = getSelectedAgent( state );
	if ( agent && Array.isArray( agent.selectedAbilities ) ) {
		return agent.selectedAbilities;
	}
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

/**
 * The built-in default agent shown as the first entry of the agent tray. Its
 * configuration lives on the AgentMod settings page; selecting it sends
 * `agent.id = null` so the server resolves everything from settings.
 */
export function getDefaultAgent() {
	return (
		config.defaultAgent || {
			id: null,
			name: __( 'Default Agent', 'agent-mod' ),
			description: '',
			avatar: '',
		}
	);
}

export function getConnectorsUrl() {
	return config.connectorsUrl || '';
}

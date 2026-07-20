/**
 * Pure reducer for the admin-chat store.
 */
import { loadProviderModels } from './persistence';
import config from './config';

const DEFAULT_STATE = {
	isOpen: false,
	messages: [],
	isLoading: false,
	error: null,
	isSiteContextEnabled: config.defaults?.siteContextEnabled ?? true,
	conversationId: null,
	agents: [],
	selectedAgentId: null,
	pendingConfirmation: null, // { token, actionName, args, pendingToolCalls }
	progress: null, // live tool-call progress: { status, currentTool, executedCalls }
	selectedProvider: null, // provider id chosen in the provider/model picker
	selectedModel: null, // model id chosen for the selected provider
	selectedMode: 'execute', // interaction mode: 'ask' | 'plan' | 'execute'
	// providerId -> [{ id, name }]. Hydrated from localStorage so the picker is
	// populated instantly across page loads; refreshed by the background prefetch.
	providerModels: loadProviderModels(),
	modelsLoading: null, // providerId currently being fetched, or null
	providers: [],
	providersLoaded: false,
};

export default function reducer( state = DEFAULT_STATE, action ) {
	switch ( action.type ) {
		case 'OPEN_CHAT':
			return { ...state, isOpen: true };

		case 'CLOSE_CHAT':
			return { ...state, isOpen: false };

		case 'APPEND_MESSAGE':
			return {
				...state,
				messages: [ ...state.messages, action.message ],
			};

		case 'SET_LOADING':
			return { ...state, isLoading: action.isLoading };

		case 'SET_ERROR':
			return { ...state, error: action.error, isLoading: false };

		case 'CLEAR_ERROR':
			return { ...state, error: null };

		case 'CLEAR_MESSAGES':
			return { ...state, messages: [], error: null };

		case 'SET_MESSAGES':
			return { ...state, messages: action.messages, error: null };

		case 'SET_SITE_CONTEXT':
			return { ...state, isSiteContextEnabled: action.enabled };

		case 'SET_CONVERSATION_ID':
			return { ...state, conversationId: action.conversationId };

		case 'SET_AGENTS':
			return { ...state, agents: action.agents };

		case 'SELECT_AGENT':
			return { ...state, selectedAgentId: action.agentId };

		case 'SET_PROVIDER_MODELS':
			return {
				...state,
				providerModels: {
					...state.providerModels,
					[ action.providerId ]: action.models,
				},
			};

		case 'SET_MODELS_LOADING':
			return { ...state, modelsLoading: action.providerId };

		case 'SET_PROVIDERS':
			return { ...state, providers: action.providers, providersLoaded: true };

		case 'SELECT_PROVIDER_MODEL':
			return {
				...state,
				selectedProvider: action.provider,
				selectedModel: action.model,
			};

		case 'SELECT_MODE':
			return { ...state, selectedMode: action.mode };

		case 'SET_PROGRESS':
			return { ...state, progress: action.progress };

		case 'CLEAR_PROGRESS':
			return { ...state, progress: null };

		case 'SET_PENDING_CONFIRMATION':
			return { ...state, pendingConfirmation: action.data, isLoading: false };

		case 'CLEAR_CONFIRMATION':
			return { ...state, pendingConfirmation: null };

		default:
			return state;
	}
}

/**
 * Pure reducer for the admin-chat store.
 */
const DEFAULT_STATE = {
	isOpen: false,
	messages: [],
	isLoading: false,
	error: null,
	isSiteContextEnabled: true,
	conversationId: null,
	agents: [],
	selectedAgentId: null,
	pendingConfirmation: null, // { token, actionName, args, pendingToolCalls }
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

		case 'SET_SITE_CONTEXT':
			return { ...state, isSiteContextEnabled: action.enabled };

		case 'SET_CONVERSATION_ID':
			return { ...state, conversationId: action.conversationId };

		case 'SET_AGENTS':
			return { ...state, agents: action.agents };

		case 'SELECT_AGENT':
			return { ...state, selectedAgentId: action.agentId };

		case 'SET_PENDING_CONFIRMATION':
			return { ...state, pendingConfirmation: action.data, isLoading: false };

		case 'CLEAR_CONFIRMATION':
			return { ...state, pendingConfirmation: null };

		default:
			return state;
	}
}

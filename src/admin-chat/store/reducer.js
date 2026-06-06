/**
 * Pure reducer for the admin-chat store.
 */
const DEFAULT_STATE = {
	isOpen: false,
	messages: [],
	isLoading: false,
	error: null,
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

		default:
			return state;
	}
}

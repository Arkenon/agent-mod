/**
 * Actions for the admin-chat store.
 *
 * Includes plain action creators and the `sendMessage` thunk which talks to the
 * REST chat endpoint via apiFetch. Thunks are enabled by default in
 * @wordpress/data stores.
 */
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';

export function openChat() {
	return { type: 'OPEN_CHAT' };
}

export function closeChat() {
	return { type: 'CLOSE_CHAT' };
}

export function appendMessage( message ) {
	return { type: 'APPEND_MESSAGE', message };
}

export function setLoading( isLoading ) {
	return { type: 'SET_LOADING', isLoading };
}

export function setError( error ) {
	return { type: 'SET_ERROR', error };
}

export function clearError() {
	return { type: 'CLEAR_ERROR' };
}

export function clearMessages() {
	return { type: 'CLEAR_MESSAGES' };
}

export function setSiteContext( enabled ) {
	return { type: 'SET_SITE_CONTEXT', enabled };
}

export function setConversationId( conversationId ) {
	return { type: 'SET_CONVERSATION_ID', conversationId };
}

export function setAgents( agents ) {
	return { type: 'SET_AGENTS', agents };
}

export function selectAgent( agentId ) {
	return { type: 'SELECT_AGENT', agentId };
}

export function setPendingConfirmation( data ) {
	return { type: 'SET_PENDING_CONFIRMATION', data };
}

export function setProviderModels( providerId, models ) {
	return { type: 'SET_PROVIDER_MODELS', providerId, models };
}

export function setModelsLoading( providerId ) {
	return { type: 'SET_MODELS_LOADING', providerId };
}

/**
 * Selects a provider + model for the next chat requests. Pass ( null, null ) to
 * clear the selection and let the AI Client auto-select.
 *
 * @param {string|null} provider Provider id.
 * @param {string|null} model    Model id.
 */
export function selectProviderModel( provider, model ) {
	return { type: 'SELECT_PROVIDER_MODEL', provider, model };
}

/**
 * Lazily fetches the text-generation models for a provider and caches them in
 * the store. No-op when the models are already loaded.
 *
 * @param {string} providerId Provider id.
 */
export const fetchProviderModels = ( providerId ) => async ( { dispatch, select } ) => {
	if ( ! providerId || null !== select.getProviderModels( providerId ) ) {
		return;
	}

	dispatch.setModelsLoading( providerId );

	try {
		const config = window.agentModChat || {};
		const models = await apiFetch( {
			path:
				( config.restNamespace || 'agent-mod/v1' ) +
				'/provider-models?provider=' +
				encodeURIComponent( providerId ),
		} );
		dispatch.setProviderModels( providerId, Array.isArray( models ) ? models : [] );
	} catch {
		dispatch.setProviderModels( providerId, [] );
	} finally {
		dispatch.setModelsLoading( null );
	}
};

export function clearConfirmation() {
	return { type: 'CLEAR_CONFIRMATION' };
}

/**
 * Fetches the list of agents from the REST endpoint and updates the store.
 */
export const fetchAgents = () => async ( { dispatch } ) => {
	try {
		const config = window.agentModChat || {};
		const agents = await apiFetch( { path: ( config.restNamespace || 'agent-mod/v1' ) + '/agents' } );
		if ( Array.isArray( agents ) ) {
			dispatch.setAgents( agents );
		}
	} catch {
		// Silently ignore — agents list is optional
	}
};

/**
 * Executes a confirmed write action via the confirm-action REST endpoint.
 *
 * @param {string} token          Confirmation token.
 * @param {number} conversationId Current conversation ID.
 */
export const confirmAction = ( token, conversationId ) => async ( { dispatch } ) => {
	dispatch.setLoading( true );

	try {
		const config = window.agentModChat || {};
		const data   = await apiFetch( {
			path:   ( config.restNamespace || 'agent-mod/v1' ) + '/confirm-action',
			method: 'POST',
			data:   { token, conversationId },
		} );

		dispatch.clearConfirmation();

		if ( data && data.success && ! data.pendingConfirmation ) {
			dispatch.appendMessage( { role: 'assistant', text: data.text || '' } );

			if ( data.conversationId ) {
				dispatch.setConversationId( data.conversationId );
			}
		} else if ( data && data.pendingConfirmation ) {
			dispatch.setPendingConfirmation( {
				token:            data.confirmationToken,
				actionName:       data.pendingAction?.name || '',
				args:             data.pendingAction?.args || {},
				pendingToolCalls: data.pendingToolCalls || [],
			} );
		} else {
			const message =
				( data?.error?.message ) ||
				__( 'An unexpected error occurred.', 'agent-mod' );
			dispatch.setError( message );
		}
	} catch ( err ) {
		dispatch.clearConfirmation();
		dispatch.setError(
			( err && err.message ) ||
			__( 'Request failed. Please try again.', 'agent-mod' )
		);
	} finally {
		dispatch.setLoading( false );
	}
};

/**
 * Maps a stored attachment to the minimal shape sent to the REST endpoint.
 *
 * @param {Object} attachment Stored attachment.
 * @return {Object} Wire-shape attachment.
 */
function toWireAttachment( { name, mimeType, data } ) {
	return { name, mimeType, data };
}

/**
 * Sends a user message to the chat endpoint and appends the assistant reply.
 *
 * @param {string} text          The user message.
 * @param {Array}  [attachments] Attachments for this turn (each has name, mimeType, data).
 */
export const sendMessage = ( text, attachments = [] ) => async ( {
	dispatch,
	select,
} ) => {
	const trimmed = ( text || '' ).trim();
	const files = Array.isArray( attachments ) ? attachments : [];

	if ( '' === trimmed && 0 === files.length ) {
		return;
	}

	const config = window.agentModChat || {};

	// Build history from the messages present *before* this new turn.
	const history = select
		.getMessages()
		.map( ( { role, text: messageText, attachments: turnFiles } ) => ( {
			role,
			text: messageText,
			attachments: ( turnFiles || [] ).map( toWireAttachment ),
		} ) );

	// Use the selected agent config if available; fall back to defaultAgent.
	const selectedAgentId = select.getSelectedAgentId();
	const agents          = select.getAgents();
	const selectedAgent   = selectedAgentId
		? agents.find( ( a ) => a.id === selectedAgentId )
		: null;

	const agent = {
		...( config.defaultAgent || {} ),
		...( selectedAgent || {} ),
		autoIncludeSiteContext: select.isSiteContextEnabled(),
	};

	// A provider/model picked in the picker overrides the agent defaults so the
	// WP AI Client uses exactly that provider and model for this request.
	const pickedProvider = select.getSelectedProvider();
	if ( pickedProvider ) {
		agent.provider = pickedProvider;
		agent.model    = select.getSelectedModel() || null;
	}

	dispatch.clearError();
	dispatch.appendMessage( { role: 'user', text: trimmed, attachments: files } );
	dispatch.setLoading( true );

	try {
		const data = await apiFetch( {
			path: config.restPath,
			method: 'POST',
			data: {
				message: trimmed,
				agent,
				history,
				attachments: files.map( toWireAttachment ),
				conversationId: select.getConversationId(),
			},
		} );

		if ( data && data.success ) {
			if ( data.pendingConfirmation ) {
				// A write action needs user confirmation before executing.
				dispatch.setPendingConfirmation( {
					token:            data.confirmationToken,
					actionName:       data.pendingAction?.name || '',
					args:             data.pendingAction?.args || {},
					pendingToolCalls: data.pendingToolCalls || [],
				} );
			} else {
				dispatch.appendMessage( {
					role: 'assistant',
					text: data.text || '',
				} );

				if ( data.conversationId ) {
					dispatch.setConversationId( data.conversationId );
				}
			}
		} else {
			const message =
				( data && data.error && data.error.message ) ||
				__( 'An unexpected error occurred.', 'agent-mod' );
			dispatch.setError( message );
		}
	} catch ( err ) {
		const message =
			( err && err.message ) ||
			__( 'Request failed. Please try again.', 'agent-mod' );
		dispatch.setError( message );
	} finally {
		dispatch.setLoading( false );
	}
};

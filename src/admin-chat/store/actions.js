/**
 * Actions for the admin-chat store.
 *
 * Includes plain action creators and the `sendMessage` thunk which talks to the
 * REST chat endpoint via apiFetch. Thunks are enabled by default in
 * @wordpress/data stores.
 */
import apiFetch from '@wordpress/api-fetch';
import { applyFilters, doAction } from '@wordpress/hooks';
import { __ } from '@wordpress/i18n';

import { parseAbilityMentions } from '../utils/mentions';

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
	doAction( 'agent_mod.new_conversation' );
	return { type: 'CLEAR_MESSAGES' };
}

/**
 * Replaces the whole messages array, e.g. when restoring a stored conversation.
 *
 * @param {Array} messages Messages in the store shape
 *                         ({ role, text, attachments?, toolCalls?, tokenUsage? }).
 */
export function setMessages( messages ) {
	return {
		type: 'SET_MESSAGES',
		messages: Array.isArray( messages ) ? messages : [],
	};
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
	doAction( 'agent_mod.confirmation_requested', data );
	return { type: 'SET_PENDING_CONFIRMATION', data };
}

export function setProgress( progress ) {
	return { type: 'SET_PROGRESS', progress };
}

export function clearProgress() {
	return { type: 'CLEAR_PROGRESS' };
}

export function setProviderModels( providerId, models ) {
	return { type: 'SET_PROVIDER_MODELS', providerId, models };
}

export function setProviders( providers ) {
	return { type: 'SET_PROVIDERS', providers };
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
 * Selects the interaction mode for the next chat requests.
 *
 * @param {string} mode One of 'ask', 'plan', 'execute'.
 */
export function selectMode( mode ) {
	return { type: 'SELECT_MODE', mode };
}

/**
 * Lazily fetches the text-generation models for a provider and caches them in
 * the store. No-op when the models are already loaded.
 *
 * @param {string} providerId Provider id.
 */
export const fetchProviderModels = ( providerId ) => async ( { dispatch, select } ) => {
	// Only skip when a *non-empty* list is already cached. An empty array can
	// mean a prior fetch failed (see catch below) — treating it as "loaded"
	// would permanently skip retrying, since it's persisted to localStorage
	// (see ./persistence.js) and survives page reloads for up to 6 hours.
	const cached = select.getProviderModels( providerId );
	if ( ! providerId || ( Array.isArray( cached ) && cached.length > 0 ) ) {
		return;
	}

	dispatch.setModelsLoading( providerId );

	try {
		const models = await apiFetch( {
			path:
				select.getRestNamespace() +
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

/**
 * Lazily fetches the list of connected AI providers and caches them in the store.
 */
export const fetchProviders = () => async ( { dispatch, select } ) => {
	if ( select.getProvidersLoaded() ) {
		return;
	}

	try {
		const providers = await apiFetch( {
			path: select.getRestNamespace() + '/providers',
		} );
		const list = Array.isArray( providers ) ? providers : [];
		dispatch.setProviders( list );
		list.forEach( ( provider ) => dispatch.fetchProviderModels( provider.id ) );
	} catch {
		dispatch.setProviders( [] );
	}
};

export function clearConfirmation() {
	return { type: 'CLEAR_CONFIRMATION' };
}

/**
 * Fetches the list of agents from the REST endpoint and updates the store.
 */
export const fetchAgents = () => async ( { dispatch, select } ) => {
	try {
		const agents = await apiFetch( { path: select.getRestNamespace() + '/agents' } );

		/**
		 * Filters the agent list shown in the agent tray. Lets extensions add,
		 * remove or reorder agents client-side. Each record needs at least
		 * { id, name }; { description, avatar, icon } are used when present.
		 */
		const filtered = applyFilters(
			'agent_mod.agents',
			Array.isArray( agents ) ? agents : []
		);
		dispatch.setAgents( Array.isArray( filtered ) ? filtered : [] );
	} catch {
		// Fetch failed — still run the filter so JS-only agents survive.
		const filtered = applyFilters( 'agent_mod.agents', [] );
		if ( Array.isArray( filtered ) && filtered.length ) {
			dispatch.setAgents( filtered );
		}
	}
};

/**
 * Executes a confirmed write action via the confirm-action REST endpoint.
 *
 * @param {string} token          Confirmation token.
 * @param {number} conversationId Current conversation ID.
 */
export const confirmAction = ( token, conversationId ) => async ( { dispatch, select } ) => {
	// Close the modal immediately on confirm; don't wait for the request to
	// resolve. If the resumed run hits another write action needing approval,
	// the pendingConfirmation branch below re-opens it.
	dispatch.clearConfirmation();
	dispatch.setLoading( true );

	const requestId   = generateRequestId();
	const stopPolling = startProgressPolling( requestId, dispatch, select );

	try {
		const data = await apiFetch( {
			path:   select.getRestNamespace() + '/confirm-action',
			method: 'POST',
			data:   { token, conversationId, requestId },
		} );

		dispatch.clearConfirmation();

		if ( data && data.success && ! data.pendingConfirmation ) {
			dispatch.appendMessage( {
				role: 'assistant',
				text: data.text || '',
				toolCalls: Array.isArray( data.toolCalls ) ? data.toolCalls : [],
				tokenUsage: data.tokenUsage || null,
			} );

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
		stopPolling();
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
 * Generates a UUID v4 request id for live progress polling.
 *
 * Falls back to Math.random when crypto.randomUUID is unavailable (it requires
 * a secure context, which plain-http local sites do not have).
 *
 * @return {string} A UUID v4 string.
 */
function generateRequestId() {
	if ( window.crypto?.randomUUID ) {
		return window.crypto.randomUUID();
	}

	return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace( /[xy]/g, ( c ) => {
		const r = ( Math.random() * 16 ) | 0;
		const v = 'x' === c ? r : ( r & 0x3 ) | 0x8;
		return v.toString( 16 );
	} );
}

/**
 * Starts polling the chat-progress endpoint for an in-flight request and
 * dispatches the live state into the store. Returns a stop function.
 *
 * @param {string}   requestId Client-generated UUID sent with the chat request.
 * @param {Function} dispatch  Store dispatch object.
 * @param {Object}   select    Store select object.
 * @return {Function} Stops polling and clears the progress state.
 */
function startProgressPolling( requestId, dispatch, select ) {
	const path =
		select.getRestNamespace() +
		'/chat-progress?requestId=' +
		encodeURIComponent( requestId );

	const interval = setInterval( async () => {
		try {
			const progress = await apiFetch( { path } );
			if ( progress && 'unknown' !== progress.status ) {
				dispatch.setProgress( progress );
			}
		} catch {
			// Progress is best-effort; never surface polling errors.
		}
	}, 1200 );

	return () => {
		clearInterval( interval );
		dispatch.clearProgress();
	};
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

	// Build history from the messages present *before* this new turn.
	// Spread `...rest` so Pro can persist extra fields (e.g. structured_data)
	// that survive the round-trip through the DB and the sanitizeHistory filter.
	// toolCalls is display-only metadata and is not re-sent.
	const history = select
		.getMessages()
		.map( ( { role, text: messageText, attachments: turnFiles, toolCalls, ...rest } ) => ( {
			role,
			text: messageText,
			attachments: ( turnFiles || [] ).map( toWireAttachment ),
			...rest,
		} ) );

	// The full agent configuration is resolved server-side: the AgentMod
	// settings for the default agent, or — when an agent is selected in the
	// tray — the agent post's stored config via the agent_mod_agent_config_data
	// filter. Only request-level fields travel with the request.
	const agent = {
		id: select.getSelectedAgentId() || null,
		mode: select.getSelectedMode() || 'execute',
	};

	// @-mentioned abilities (e.g. "@agent-mod/list-posts") are emphasized in the
	// system prompt server-side; the mention text itself stays in the message.
	const mentionedAbilities = parseAbilityMentions( trimmed );
	if ( 0 < mentionedAbilities.length ) {
		agent.emphasizedAbilities = mentionedAbilities;
	}

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

	const requestId    = generateRequestId();
	const stopPolling  = startProgressPolling( requestId, dispatch, select );

	try {
		const basePayload = {
			message: trimmed,
			agent,
			history,
			attachments: files.map( toWireAttachment ),
			conversationId: select.getConversationId(),
			requestId,
		};

		const payload = applyFilters( 'agent_mod.send_message_payload', basePayload );

		const rawData = await apiFetch( {
			path: select.getRestPath(),
			method: 'POST',
			data: payload,
		} );

		const data = applyFilters( 'agent_mod.receive_message_response', rawData );

		if ( data && data.success ) {
			doAction( 'agent_mod.after_message_sent', data, agent );

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
					toolCalls: Array.isArray( data.toolCalls ) ? data.toolCalls : [],
					tokenUsage: data.tokenUsage || null,
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
		stopPolling();
		dispatch.setLoading( false );
	}
};

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
	if ( ! providerId || null !== select.getProviderModels( providerId ) ) {
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

export function clearConfirmation() {
	return { type: 'CLEAR_CONFIRMATION' };
}

/**
 * Fetches the list of agents from the REST endpoint and updates the store.
 */
export const fetchAgents = () => async ( { dispatch, select } ) => {
	try {
		const agents = await apiFetch( { path: select.getRestNamespace() + '/agents' } );
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
export const confirmAction = ( token, conversationId ) => async ( { dispatch, select } ) => {
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
 * Extracts namespaced ability names mentioned as "@namespace/ability" in a
 * message. Names are deduped; validity is enforced server-side.
 *
 * @param {string} text The message text.
 * @return {string[]} Mentioned ability names.
 */
function parseAbilityMentions( text ) {
	const matches = ( text || '' ).matchAll(
		/@([a-z0-9][a-z0-9-]*\/[a-z0-9][a-z0-9-]*)/g
	);

	return [ ...new Set( [ ...matches ].map( ( match ) => match[ 1 ] ) ) ];
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

	// Everything the agent needs beyond `mode` (role, goal, personality,
	// abilitySource, allowedAbilities, site context, ...) is already resolved
	// server-side from the AgentMod settings — only a selected Pro agent record
	// (below) legitimately overrides those per request.
	const selectedAgentId = select.getSelectedAgentId();
	const agents          = select.getAgents();
	const selectedAgent   = selectedAgentId
		? agents.find( ( a ) => a.id === selectedAgentId )
		: null;

	const agent = {
		...( selectedAgent || {} ),
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

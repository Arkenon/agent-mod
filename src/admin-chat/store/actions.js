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

	dispatch.clearError();
	dispatch.appendMessage( { role: 'user', text: trimmed, attachments: files } );
	dispatch.setLoading( true );

	try {
		const data = await apiFetch( {
			path: config.restPath,
			method: 'POST',
			data: {
				message: trimmed,
				agent: config.defaultAgent || {},
				history,
				attachments: files.map( toWireAttachment ),
			},
		} );

		if ( data && data.success ) {
			dispatch.appendMessage( {
				role: 'assistant',
				text: data.text || '',
			} );
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

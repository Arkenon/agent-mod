/**
 * Icon action buttons rendered below chat messages.
 *
 * Assistant messages get "Copy text" (clipboard icon) and "Create draft post"
 * (writing icon); user messages get "Copy text" only. Buttons are icon-only;
 * tooltip appears on hover via the WordPress Tooltip component. Pro can add,
 * remove, or reorder actions via the `agent_mod.message_actions` JS filter.
 *
 * Filter signature:
 *   applyFilters( 'agent_mod.message_actions', defaultActions, message )
 *   - defaultActions: Array<{ key, iconClass, label, onClick, disabled? }>
 *     - iconClass: one or more CSS classes for the icon span (e.g. 'dashicons-clipboard')
 *     - label:     tooltip text (also used as aria-label)
 *   - message: { role, text, attachments, ...rest }
 *
 * @package    AgentMod
 * @subpackage AdminChat/Components
 * @since      1.0.0
 */
import { useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { Tooltip } from '@wordpress/components';
import { applyFilters } from '@wordpress/hooks';
import { __ } from '@wordpress/i18n';

const COPY_RESET_MS = 1500;

/**
 * execCommand fallback for non-secure (HTTP) origins where navigator.clipboard
 * is unavailable (e.g. Laragon local dev domains like test-wp.test).
 *
 * @param {string}   text
 * @param {Function} onSuccess
 */
function execCommandCopy( text, onSuccess ) {
	const ta = document.createElement( 'textarea' );
	ta.value = text;
	ta.style.cssText =
		'position:fixed;opacity:0;top:0;left:0;pointer-events:none;';
	document.body.appendChild( ta );
	ta.focus();
	ta.select();
	try {
		if ( document.execCommand( 'copy' ) ) {
			onSuccess();
		}
	} finally {
		document.body.removeChild( ta );
	}
}

/**
 * Copy-to-clipboard state shared by user and assistant message actions.
 *
 * @param {string} text
 */
function useCopyAction( text ) {
	const [ copied, setCopied ] = useState( false );

	function handleCopy() {
		function onSuccess() {
			setCopied( true );
			setTimeout( () => setCopied( false ), COPY_RESET_MS );
		}
		if ( navigator.clipboard && window.isSecureContext ) {
			navigator.clipboard
				.writeText( text )
				.then( onSuccess )
				.catch( () => execCommandCopy( text, onSuccess ) );
		} else {
			execCommandCopy( text, onSuccess );
		}
	}

	return { copied, handleCopy };
}

/**
 * Renders a row of icon buttons from an actions array.
 */
function ActionButtons( { actions } ) {
	return (
		<div className="agent-mod-message-actions__buttons">
			{ actions.map( ( action ) => (
				<Tooltip key={ action.key } text={ action.label }>
					<button
						type="button"
						className="agent-mod-message-actions__btn"
						onClick={ action.onClick }
						disabled={ !! action.disabled }
						aria-label={ action.label }
					>
						<span
							className={ `dashicons ${ action.iconClass }` }
							aria-hidden="true"
						/>
					</button>
				</Tooltip>
			) ) }
		</div>
	);
}

/**
 * Copy-only actions rendered below user messages.
 */
function UserActions( { message } ) {
	const { copied, handleCopy } = useCopyAction( message.text || '' );

	const defaultActions = [
		{
			key: 'copy',
			iconClass: copied ? 'dashicons-yes' : 'dashicons-clipboard',
			label: copied
				? __( 'Copied!', 'agent-mod' )
				: __( 'Copy text', 'agent-mod' ),
			onClick: handleCopy,
		},
	];

	const actions = applyFilters(
		'agent_mod.message_actions',
		defaultActions,
		message
	);

	return (
		<div className="agent-mod-message-actions">
			<ActionButtons actions={ actions } />
		</div>
	);
}

/**
 * Inner component — hooks always called at top level.
 */
function AssistantActions( { message } ) {
	const { copied, handleCopy } = useCopyAction( message.text || '' );
	// null | 'loading' | { editUrl: string } | { error: string }
	const [ draftState, setDraftState ] = useState( null );

	async function handleCreateDraft() {
		if ( 'loading' === draftState ) {
			return;
		}
		setDraftState( 'loading' );
		try {
			const headingMatch = ( message.text || '' ).match( /^#{1,6}\s+(.+)/m );
			const title = headingMatch
				? headingMatch[ 1 ].trim()
				: __( 'AI Draft', 'agent-mod' );

			const post = await apiFetch( {
				path: '/wp/v2/posts',
				method: 'POST',
				data: { title, content: message.text, status: 'draft' },
			} );

			setDraftState( {
				editUrl: `/wp-admin/post.php?post=${ post.id }&action=edit`,
			} );
		} catch ( err ) {
			setDraftState( {
				error:
					( err && err.message ) ||
					__( 'Could not create draft.', 'agent-mod' ),
			} );
		}
	}

	const defaultActions = [
		{
			key: 'copy',
			iconClass: copied ? 'dashicons-yes' : 'dashicons-clipboard',
			label: copied
				? __( 'Copied!', 'agent-mod' )
				: __( 'Copy text', 'agent-mod' ),
			onClick: handleCopy,
		},
		{
			key: 'create-draft',
			iconClass:
				'loading' === draftState
					? 'dashicons-update agent-mod-spin'
					: 'dashicons-welcome-write-blog',
			label:
				'loading' === draftState
					? __( 'Creating draft…', 'agent-mod' )
					: __( 'Create draft post', 'agent-mod' ),
			onClick: handleCreateDraft,
			disabled: 'loading' === draftState,
		},
	];

	const actions = applyFilters(
		'agent_mod.message_actions',
		defaultActions,
		message
	);

	return (
		<div className="agent-mod-message-actions">
			<ActionButtons actions={ actions } />

			{ draftState && 'loading' !== draftState && (
				<div
					className={ `agent-mod-message-actions__notice ${ draftState.error ? 'is-error' : 'is-success' }` }
				>
					{ draftState.error ? (
						draftState.error
					) : (
						<>
							{ __( 'Draft created.', 'agent-mod' ) }{ ' ' }
							<a
								href={ draftState.editUrl }
								target="_blank"
								rel="noreferrer"
							>
								{ __( 'Edit post →', 'agent-mod' ) }
							</a>
						</>
					) }
				</div>
			) }
		</div>
	);
}

/**
 * Wrapper that skips rendering for empty messages and picks the action set
 * for the message's role.
 */
export default function MessageActions( { message } ) {
	if ( ! ( message.text || '' ).trim() ) {
		return null;
	}
	return 'assistant' === message.role ? (
		<AssistantActions message={ message } />
	) : (
		<UserActions message={ message } />
	);
}

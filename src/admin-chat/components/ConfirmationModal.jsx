/**
 * Write-action confirmation modal.
 *
 * Renders a @wordpress/components Modal when the store has a pending write
 * action. Confirm resumes the paused run (executing exactly the calls shown);
 * Cancel declines them, so the model can acknowledge instead of the request
 * being dropped. A checkbox lets the user session-approve the shown ability
 * types so later calls to them run without prompting again.
 */
import { Button, CheckboxControl, Modal } from '@wordpress/components';
import { useDispatch, useSelect } from '@wordpress/data';
import { useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

import { STORE_NAME } from '../store';

export default function ConfirmationModal() {
	const { confirmAction, declineAction, addSessionApprovedAbilities } =
		useDispatch( STORE_NAME );

	const [ rememberSession, setRememberSession ] = useState( false );

	const { pendingConfirmation, conversationId } = useSelect( ( select ) => {
		const storeSelect = select( STORE_NAME );
		return {
			pendingConfirmation: storeSelect.getPendingConfirmation(),
			conversationId:      storeSelect.getConversationId(),
		};
	}, [] );

	if ( ! pendingConfirmation ) {
		return null;
	}

	const { token, actionName, args, pendingToolCalls, executedCalls } =
		pendingConfirmation;

	// A provider may batch several write calls into one turn; confirming approves
	// all of them, so all of them have to be on screen. Older payloads carry only
	// the single pendingAction shape.
	const actions =
		Array.isArray( pendingToolCalls ) && pendingToolCalls.length
			? pendingToolCalls
			: [ { name: actionName, args } ];

	const abilityNames = [
		...new Set( actions.map( ( action ) => action.name ).filter( Boolean ) ),
	];

	const executedCount = Array.isArray( executedCalls )
		? executedCalls.length
		: 0;

	const onConfirm = () => {
		if ( rememberSession && abilityNames.length ) {
			// The thunk reads the fresh list from state, so the resumed run
			// already skips these ability types.
			addSessionApprovedAbilities( abilityNames );
		}
		confirmAction( token, conversationId );
	};

	const onCancel = () => {
		declineAction( token, conversationId );
	};

	return (
		<Modal
			title={ __( 'Confirm Action', 'agent-mod' ) }
			onRequestClose={ onCancel }
			className="agent-mod-chat__confirm-modal"
			isDismissible={ false }
		>
			<p>
				{ _n(
					'The agent wants to perform the following action. Do you confirm?',
					'The agent wants to perform the following actions. Do you confirm?',
					actions.length,
					'agent-mod'
				) }
			</p>

			{ 0 < executedCount && (
				<p className="agent-mod-chat__confirm-executed">
					{ sprintf(
						/* translators: %d: number of already executed steps. */
						_n(
							'%d earlier step of this task has already run.',
							'%d earlier steps of this task have already run.',
							executedCount,
							'agent-mod'
						),
						executedCount
					) }
				</p>
			) }

			{ actions.map( ( action, index ) => (
				<div
					className="agent-mod-chat__confirm-action"
					key={ `${ action.name }-${ index }` }
				>
					<strong>{ action.name }</strong>
					{ 0 < Object.keys( action.args || {} ).length && (
						<pre className="agent-mod-chat__confirm-args">
							{ JSON.stringify( action.args, null, 2 ) }
						</pre>
					) }
				</div>
			) ) }

			<div className="agent-mod-chat__confirm-remember">
				<CheckboxControl
					__nextHasNoMarginBottom
					label={ __(
						"Don't ask again for these action types in this session",
						'agent-mod'
					) }
					help={ abilityNames.join( ', ' ) }
					checked={ rememberSession }
					onChange={ setRememberSession }
				/>
			</div>

			<div className="agent-mod-chat__confirm-buttons">
				<Button variant="primary" onClick={ onConfirm }>
					{ __( 'Confirm', 'agent-mod' ) }
				</Button>
				<Button variant="secondary" onClick={ onCancel }>
					{ __( 'Cancel', 'agent-mod' ) }
				</Button>
			</div>
		</Modal>
	);
}

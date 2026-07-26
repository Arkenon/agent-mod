/**
 * Write-action confirmation modal.
 *
 * Renders a @wordpress/components Modal when the store has a pending
 * write action. The user can approve or cancel the operation.
 * Approval dispatches confirmAction(); cancellation dispatches clearConfirmation().
 */
import { Button, Modal } from '@wordpress/components';
import { useDispatch, useSelect } from '@wordpress/data';
import { __, _n } from '@wordpress/i18n';

import { STORE_NAME } from '../store';

export default function ConfirmationModal() {
	const { confirmAction, clearConfirmation, setError } = useDispatch( STORE_NAME );

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

	const { token, actionName, args, pendingToolCalls } = pendingConfirmation;

	// A provider may batch several write calls into one turn; confirming approves
	// all of them, so all of them have to be on screen. Older payloads carry only
	// the single pendingAction shape.
	const actions =
		Array.isArray( pendingToolCalls ) && pendingToolCalls.length
			? pendingToolCalls
			: [ { name: actionName, args } ];

	const onConfirm = () => {
		confirmAction( token, conversationId );
	};

	const onCancel = () => {
		clearConfirmation();
		setError( __( 'Action cancelled.', 'agent-mod' ) );
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

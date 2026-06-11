/**
 * Write-action confirmation modal.
 *
 * Renders a @wordpress/components Modal when the store has a pending
 * write action. The user can approve or cancel the operation.
 * Approval dispatches confirmAction(); cancellation dispatches clearConfirmation().
 */
import { Button, Modal } from '@wordpress/components';
import { useDispatch, useSelect } from '@wordpress/data';
import { __ } from '@wordpress/i18n';

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

	const { token, actionName, args } = pendingConfirmation;

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
				{ __( 'The agent wants to perform the following action. Do you confirm?', 'agent-mod' ) }
			</p>

			<div className="agent-mod-chat__confirm-action">
				<strong>{ actionName }</strong>
				{ 0 < Object.keys( args ).length && (
					<pre className="agent-mod-chat__confirm-args">
						{ JSON.stringify( args, null, 2 ) }
					</pre>
				) }
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

/**
 * The chat modal: message list, error notice, and composer.
 */
import { Modal, Notice } from '@wordpress/components';
import { useDispatch, useSelect } from '@wordpress/data';
import { __ } from '@wordpress/i18n';

import { STORE_NAME } from '../store';
import MessageList from './MessageList';
import Composer from './Composer';

export default function ChatModal() {
	const { closeChat, clearError } = useDispatch( STORE_NAME );
	const error = useSelect(
		( select ) => select( STORE_NAME ).getError(),
		[]
	);

	const strings = ( window.agentModChat || {} ).strings || {};

	return (
		<Modal
			title={ strings.title || __( 'AgentMod Assistant', 'agent-mod' ) }
			onRequestClose={ closeChat }
			className="agent-mod-chat__modal"
		>
			<div className="agent-mod-chat__body">
				{ error && (
					<Notice
						status="error"
						isDismissible
						onRemove={ clearError }
					>
						{ error }
					</Notice>
				) }

				<MessageList />
				<Composer />
			</div>
		</Modal>
	);
}

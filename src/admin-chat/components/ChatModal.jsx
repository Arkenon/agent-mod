/**
 * Modal presentation wrapper around the reusable <ChatPanel/>.
 *
 * This component is responsible only for the modal chrome; the conversation
 * lives in <ChatPanel/> so the exact same UI can be reused outside a modal.
 */
import { Modal } from '@wordpress/components';
import { useDispatch } from '@wordpress/data';
import { __ } from '@wordpress/i18n';

import { STORE_NAME } from '../store';
import ChatPanel from './ChatPanel';

export default function ChatModal() {
	const { closeChat } = useDispatch( STORE_NAME );

	const strings = ( window.agentModChat || {} ).strings || {};

	return (
		<Modal
			title={ strings.title || __( 'AgentMod Assistant', 'agent-mod' ) }
			onRequestClose={ closeChat }
			className="agent-mod-chat__modal"
		>
			<ChatPanel />
		</Modal>
	);
}

/**
 * Presentation-agnostic chat panel.
 *
 * Contains only the conversation itself: error notice, message list, and
 * composer. It carries no chrome of its own (no modal, no sidebar frame), so it
 * can be dropped into a modal, a full admin page, a sidebar, or any other
 * container as-is — plug-and-play, no extra wiring required.
 */
import { Notice, Slot, SlotFillProvider } from '@wordpress/components';
import { useDispatch, useSelect } from '@wordpress/data';

import { STORE_NAME } from '../store';
import ConfirmationModal from './ConfirmationModal';
import MessageList from './MessageList';
import Composer from './Composer';

export default function ChatPanel( { className = '' } ) {
	const { clearError } = useDispatch( STORE_NAME );
	const { error } = useSelect( ( select ) => {
		const storeSelect = select( STORE_NAME );
		return {
			error: storeSelect.getError(),
		};
	}, [] );

	return (
		<SlotFillProvider>
			<div className={ `agent-mod-chat__body ${ className }`.trim() }>
				<Slot name="AgentModChatHeader" />

				{ error && (
					<Notice status="error" isDismissible onRemove={ clearError }>
						{ error }
					</Notice>
				) }

				<ConfirmationModal />
				<MessageList />
				<Composer />

				<Slot name="AgentModComposerToolbar" />
			</div>
		</SlotFillProvider>
	);
}

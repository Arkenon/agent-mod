/**
 * Presentation-agnostic chat panel.
 *
 * Contains only the conversation itself: error notice, message list, and
 * composer. It carries no chrome of its own (no modal, no sidebar frame), so it
 * can be dropped into a modal, a full admin page, a sidebar, or any other
 * container as-is — plug-and-play, no extra wiring required.
 */
import { Button, Notice } from '@wordpress/components';
import { useDispatch, useSelect } from '@wordpress/data';
import { __ } from '@wordpress/i18n';

import { STORE_NAME } from '../store';
import AgentSelector from './AgentSelector';
import ConfirmationModal from './ConfirmationModal';
import MessageList from './MessageList';
import Composer from './Composer';

export default function ChatPanel( { className = '' } ) {
	const { clearError, clearMessages, setConversationId } = useDispatch( STORE_NAME );
	const { error, hasMessages, loading } = useSelect( ( select ) => {
		const storeSelect = select( STORE_NAME );
		return {
			error: storeSelect.getError(),
			hasMessages: storeSelect.getMessages().length > 0,
			loading: storeSelect.isLoading(),
		};
	}, [] );

	return (
		<div className={ `agent-mod-chat__body ${ className }`.trim() }>
			{ error && (
				<Notice status="error" isDismissible onRemove={ clearError }>
					{ error }
				</Notice>
			) }

				<div className="agent-mod-chat__toolbar">
				<AgentSelector />

				{ hasMessages && ! loading && (
					<Button
						variant="tertiary"
						size="small"
						onClick={ () => {
							clearMessages();
							setConversationId( null );
						} }
					>
						{ __( 'New Topic', 'agent-mod' ) }
					</Button>
				) }
			</div>

			<ConfirmationModal />
			<MessageList />
			<Composer />
		</div>
	);
}

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
import { __, sprintf } from '@wordpress/i18n';

import { STORE_NAME } from '../store';
import ConfirmationModal from './ConfirmationModal';
import MessageList from './MessageList';
import Composer from './Composer';

export default function ChatPanel({ className = '' }) {
	const { clearError } = useDispatch(STORE_NAME);
	const { error, sessionTokenUsage } = useSelect((select) => {
		const storeSelect = select(STORE_NAME);
		return {
			error: storeSelect.getError(),
			sessionTokenUsage: storeSelect.getSessionTokenUsage(),
		};
	}, []);

	return (
		<SlotFillProvider>
			<div className={`agent-mod-chat__body ${className}`.trim()}>
				<Slot name="AgentModChatHeader" />

				{error && (
					<Notice status="error" isDismissible onRemove={clearError}>
						{error}
					</Notice>
				)}

				<ConfirmationModal />
				<MessageList />
				{0 < sessionTokenUsage.totalTokens && (
					<div
						className="agent-mod-chat__session-tokens"
						title={sprintf(
							/* translators: 1: prompt tokens, 2: completion tokens. */
							__('Prompt: %1$d · Completion: %2$d', 'agent-mod'),
							sessionTokenUsage.promptTokens,
							sessionTokenUsage.completionTokens
						)}
					>
						{sprintf(
							/* translators: %d: total tokens used so far this session. */
							__('Session usage: %d tokens', 'agent-mod'),
							sessionTokenUsage.totalTokens
						)}
					</div>
				)}
				<Composer />

				

				<Slot name="AgentModComposerToolbar" />
			</div>
		</SlotFillProvider>
	);
}

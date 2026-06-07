/**
 * Presentation-agnostic chat panel.
 *
 * Contains only the conversation itself: error notice, message list, and
 * composer. It carries no chrome of its own (no modal, no sidebar frame), so it
 * can be dropped into a modal, a full admin page, a sidebar, or any other
 * container as-is — plug-and-play, no extra wiring required.
 */
import { Notice } from '@wordpress/components';
import { useDispatch, useSelect } from '@wordpress/data';

import { STORE_NAME } from '../store';
import MessageList from './MessageList';
import Composer from './Composer';

export default function ChatPanel( { className = '' } ) {
	const { clearError } = useDispatch( STORE_NAME );
	const error = useSelect(
		( select ) => select( STORE_NAME ).getError(),
		[]
	);

	return (
		<div className={ `agent-mod-chat__body ${ className }`.trim() }>
			{ error && (
				<Notice status="error" isDismissible onRemove={ clearError }>
					{ error }
				</Notice>
			) }

			<MessageList />
			<Composer />
		</div>
	);
}

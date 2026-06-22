/**
 * A single chat message bubble, styled by role (user/assistant).
 *
 * Renders any attachments above the message content. Text is rendered via
 * MessageContent (markdown-aware for assistant messages) and action buttons
 * (copy, create draft) via MessageActions.
 *
 * @package    AgentMod
 * @subpackage AdminChat/Components
 * @since      1.0.0
 */
import MessageContent from './MessageContent';
import MessageActions from './MessageActions';

export default function MessageItem( { message } ) {
	const role = 'assistant' === message.role ? 'assistant' : 'user';
	const attachments = message.attachments || [];

	return (
		<div
			className={ `agent-mod-chat__message agent-mod-chat__message--${ role }` }
		>
			<div className="agent-mod-chat__message-content">
				{ 0 < attachments.length && (
					<ul className="agent-mod-chat__message-attachments">
						{ attachments.map( ( item, index ) =>
							item.isImage || ( item.mimeType || '' ).startsWith( 'image/' ) ? (
								<li key={ index }>
									<img
										className="agent-mod-chat__message-image"
										src={ item.data }
										alt={ item.name || '' }
									/>
								</li>
							) : (
								<li
									key={ index }
									className="agent-mod-chat__message-file"
								>
									<span
										className="agent-mod-chat__message-file-icon dashicons dashicons-media-default"
										aria-hidden="true"
									/>
									<span>{ item.name || '' }</span>
								</li>
							)
						) }
					</ul>
				) }

				{ !! ( message.text || '' ) && (
					<MessageContent message={ message } />
				) }

				<MessageActions message={ message } />
			</div>
		</div>
	);
}

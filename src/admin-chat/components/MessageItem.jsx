/**
 * A single chat message bubble, styled by role (user/assistant).
 *
 * Renders any attachments above the message content. Text is rendered via
 * MessageContent (markdown-aware for assistant messages) and action buttons
 * (copy, create draft) via MessageActions. Assistant messages list the tools
 * used to produce the answer in a collapsible section.
 *
 * @package    AgentMod
 * @subpackage AdminChat/Components
 * @since      1.0.0
 */
import { __, sprintf } from '@wordpress/i18n';

import MessageContent from './MessageContent';
import MessageActions from './MessageActions';

export default function MessageItem( { message } ) {
	const role = 'assistant' === message.role ? 'assistant' : 'user';
	const attachments = message.attachments || [];
	const toolCalls = 'assistant' === role && Array.isArray( message.toolCalls )
		? message.toolCalls
		: [];

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

				{ 0 < toolCalls.length && (
					<details className="agent-mod-chat__toolcalls">
						<summary>
							{ sprintf(
								/* translators: %d: number of tool calls. */
								__( 'Tools used (%d)', 'agent-mod' ),
								toolCalls.length
							) }
						</summary>
						<ul>
							{ toolCalls.map( ( call, index ) => (
								<li key={ index }>
									<code className="agent-mod-chat__toolcall-name">
										{ call.name }
									</code>
									<code className="agent-mod-chat__toolcall-args">
										{ JSON.stringify( call.args || {} ) }
									</code>
								</li>
							) ) }
						</ul>
					</details>
				) }

				<MessageActions message={ message } />
			</div>
		</div>
	);
}

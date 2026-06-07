/**
 * A single chat message bubble, styled by role (user/assistant).
 *
 * Renders any attachments above the text: images as thumbnails, other files as
 * labelled chips. A message with attachments but no text shows no empty bubble.
 */
export default function MessageItem( { message } ) {
	const role = 'assistant' === message.role ? 'assistant' : 'user';
	const attachments = message.attachments || [];
	const hasText = '' !== ( message.text || '' );

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

				{ hasText && (
					<div className="agent-mod-chat__bubble">
						{ message.text }
					</div>
				) }
			</div>
		</div>
	);
}

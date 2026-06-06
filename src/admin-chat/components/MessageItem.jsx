/**
 * A single chat message bubble, styled by role (user/assistant).
 */
export default function MessageItem( { message } ) {
	const role = 'assistant' === message.role ? 'assistant' : 'user';

	return (
		<div className={ `agent-mod-chat__message agent-mod-chat__message--${ role }` }>
			<div className="agent-mod-chat__bubble">{ message.text }</div>
		</div>
	);
}

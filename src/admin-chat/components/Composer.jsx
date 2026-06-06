/**
 * Message composer: textarea + send button.
 *
 * Enter sends the message, Shift+Enter inserts a newline. The send button is
 * disabled while a request is in flight or the input is empty.
 */
import { useState } from '@wordpress/element';
import { useDispatch, useSelect } from '@wordpress/data';
import { TextareaControl, Button } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

import { STORE_NAME } from '../store';

export default function Composer() {
	const [ text, setText ] = useState( '' );
	const { sendMessage } = useDispatch( STORE_NAME );
	const loading = useSelect(
		( select ) => select( STORE_NAME ).isLoading(),
		[]
	);

	const strings = ( window.agentModChat || {} ).strings || {};
	const canSend = '' !== text.trim() && ! loading;

	const submit = () => {
		if ( ! canSend ) {
			return;
		}

		sendMessage( text );
		setText( '' );
	};

	const onKeyDown = ( event ) => {
		if ( 'Enter' === event.key && ! event.shiftKey ) {
			event.preventDefault();
			submit();
		}
	};

	return (
		<div className="agent-mod-chat__composer">
			<TextareaControl
				className="agent-mod-chat__input"
				value={ text }
				onChange={ setText }
				onKeyDown={ onKeyDown }
				placeholder={
					strings.placeholder ||
					__( 'Type your message…', 'agent-mod' )
				}
				rows={ 2 }
				disabled={ loading }
				__nextHasNoMarginBottom
			/>
			<Button
				variant="primary"
				onClick={ submit }
				disabled={ ! canSend }
			>
				{ strings.send || __( 'Send', 'agent-mod' ) }
			</Button>
		</div>
	);
}

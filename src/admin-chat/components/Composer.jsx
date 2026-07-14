/**
 * Message composer.
 *
 * Textarea + site-context toggle + send button. File attachment handling is
 * delegated to AttachmentUploader. Enter sends, Shift+Enter inserts a newline.
 */
import { useRef, useState } from '@wordpress/element';
import { useDispatch, useSelect } from '@wordpress/data';
import { Button, TextareaControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

import { STORE_NAME } from '../store';
import AttachmentUploader from './AttachmentUploader';
import AgentSelector from './AgentSelector';
import ProviderModelSelector from './ProviderModelSelector';
import ModeSelector from './ModeSelector';
import AbilityTray from './AbilityTray';

export default function Composer() {
	const [ text, setText ]               = useState( '' );
	const [ attachments, setAttachments ] = useState( [] );
	const uploaderRef                     = useRef( null );

	const { sendMessage, clearMessages, setConversationId } =
		useDispatch( STORE_NAME );
	const { loading, hasMessages } = useSelect( ( select ) => {
		const storeSelect = select( STORE_NAME );
		return {
			loading:     storeSelect.isLoading(),
			hasMessages: storeSelect.getMessages().length > 0,
		};
	}, [] );

	const config   = window.agentModChat || {};
	const strings  = config.strings || {};
	const maxCount = ( config.attachments || {} ).maxCount || 5;
	const canSend  = ( '' !== text.trim() || 0 < attachments.length ) && ! loading;

	const submit = () => {
		if ( ! canSend ) {
			return;
		}
		sendMessage( text, attachments );
		setText( '' );
		setAttachments( [] );
	};

	const onKeyDown = ( event ) => {
		if ( 'Enter' === event.key && ! event.shiftKey ) {
			event.preventDefault();
			submit();
		}
	};

	// Appends an "@ability-name " mention from the ability tray to the text.
	const insertMention = ( name ) => {
		setText( ( current ) =>
			( current && ! current.endsWith( ' ' ) ? current + ' ' : current ) +
			'@' + name + ' '
		);
	};

	return (
		<div className="agent-mod-chat__composer">
			<AttachmentUploader
				ref={ uploaderRef }
				attachments={ attachments }
				onChange={ setAttachments }
				disabled={ loading }
			/>

			<TextareaControl
				className="agent-mod-chat__input"
				value={ text }
				onChange={ setText }
				onKeyDown={ onKeyDown }
				placeholder={
					strings.placeholder || __( 'Type your message…', 'agent-mod' )
				}
				rows={ 2 }
				disabled={ loading }
			/>

			<div className="agent-mod-chat__composer-actions">
				<div className="agent-mod-chat__tools">
					<AgentSelector />
					<ModeSelector />
					<ProviderModelSelector />
					<AbilityTray onInsert={ insertMention } disabled={ loading } />

					<Button
						icon="paperclip"
						label={ strings.attach || __( 'Attach files', 'agent-mod' ) }
						onClick={ () => uploaderRef.current?.open() }
						disabled={ loading || attachments.length >= maxCount }
					/>

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

				<Button variant="primary" onClick={ submit } disabled={ ! canSend }>
					{ strings.send || __( 'Send', 'agent-mod' ) }
				</Button>
			</div>
		</div>
	);
}

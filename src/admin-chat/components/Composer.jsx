/**
 * Message composer.
 *
 * Textarea + site-context toggle + send button. File attachment handling is
 * delegated to AttachmentUploader. Enter sends, Shift+Enter inserts a newline.
 */
import { useRef, useState } from '@wordpress/element';
import { useDispatch, useSelect } from '@wordpress/data';
import { Button, TextareaControl, ToggleControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

import { STORE_NAME } from '../store';
import AttachmentUploader from './AttachmentUploader';

export default function Composer() {
	const [ text, setText ]               = useState( '' );
	const [ attachments, setAttachments ] = useState( [] );
	const uploaderRef                     = useRef( null );

	const { sendMessage, setSiteContext } = useDispatch( STORE_NAME );
	const { loading, isSiteContext } = useSelect( ( select ) => {
		const storeSelect = select( STORE_NAME );
		return {
			loading:       storeSelect.isLoading(),
			isSiteContext: storeSelect.isSiteContextEnabled(),
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

	return (
		<div className="agent-mod-chat__composer">
			<AttachmentUploader
				ref={ uploaderRef }
				attachments={ attachments }
				onChange={ setAttachments }
				disabled={ loading }
			/>

			<div className="agent-mod-chat__context-scope">
				<ToggleControl
					label={ __( 'Site Context (RAG)', 'agent-mod' ) }
					help={ isSiteContext
						? __( 'Agent reads your site data.', 'agent-mod' )
						: __( 'General knowledge only.', 'agent-mod' )
					}
					checked={ isSiteContext }
					onChange={ setSiteContext }
					__nextHasNoMarginBottom
				/>
			</div>

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
				__nextHasNoMarginBottom
			/>

			<div className="agent-mod-chat__composer-actions">
				<Button
					icon="paperclip"
					label={ strings.attach || __( 'Attach files', 'agent-mod' ) }
					onClick={ () => uploaderRef.current?.open() }
					disabled={ loading || attachments.length >= maxCount }
				/>
				<Button variant="primary" onClick={ submit } disabled={ ! canSend }>
					{ strings.send || __( 'Send', 'agent-mod' ) }
				</Button>
			</div>
		</div>
	);
}

/**
 * Message composer: attachment picker + textarea + send button.
 *
 * Enter sends the message, Shift+Enter inserts a newline. Files are read on the
 * client into base64 data URIs and travel with the message to the REST endpoint,
 * which validates them and forwards them to the AI provider via the WP AI Client
 * file infrastructure. The send button is enabled when there is text or at least
 * one attachment and no request is in flight.
 */
import { useState, useRef } from '@wordpress/element';
import { useDispatch, useSelect } from '@wordpress/data';
import { TextareaControl, Button } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';

import { STORE_NAME } from '../store';

/**
 * Reads a File into a base64 data URI.
 *
 * @param {File} file The file to read.
 * @return {Promise<string>} Resolves with the data URI.
 */
function readAsDataUri( file ) {
	return new Promise( ( resolve, reject ) => {
		const reader = new FileReader();
		reader.onload = () => resolve( reader.result );
		reader.onerror = () => reject( reader.error );
		reader.readAsDataURL( file );
	} );
}

export default function Composer() {
	const [ text, setText ] = useState( '' );
	const [ attachments, setAttachments ] = useState( [] );
	const fileInputRef = useRef( null );
	const nextId = useRef( 0 );

	const { sendMessage, setError } = useDispatch( STORE_NAME );
	const loading = useSelect(
		( select ) => select( STORE_NAME ).isLoading(),
		[]
	);

	const config = window.agentModChat || {};
	const strings = config.strings || {};
	const limits = config.attachments || {};
	const maxBytes = limits.maxBytes || 5242880;
	const maxCount = limits.maxCount || 5;
	const mimeTypes = limits.mimeTypes || [];
	const accept = mimeTypes.join( ',' );

	const canSend =
		( '' !== text.trim() || 0 < attachments.length ) && ! loading;

	const openPicker = () => {
		if ( fileInputRef.current ) {
			fileInputRef.current.click();
		}
	};

	const onFilesSelected = async ( event ) => {
		const selected = Array.from( event.target.files || [] );
		// Allow re-selecting the same file later.
		event.target.value = '';

		if ( 0 === selected.length ) {
			return;
		}

		let room = maxCount - attachments.length;

		if ( room <= 0 ) {
			setError(
				sprintf(
					strings.tooManyFiles ||
						__( 'You can attach up to %d files.', 'agent-mod' ),
					maxCount
				)
			);
			return;
		}

		const accepted = [];

		for ( const file of selected ) {
			if ( room <= 0 ) {
				setError(
					sprintf(
						strings.tooManyFiles ||
							__( 'You can attach up to %d files.', 'agent-mod' ),
						maxCount
					)
				);
				break;
			}

			if ( mimeTypes.length && ! mimeTypes.includes( file.type ) ) {
				setError(
					sprintf(
						strings.fileTypeNotAllowed ||
							__(
								'"%s" is not an allowed file type.',
								'agent-mod'
							),
						file.name
					)
				);
				continue;
			}

			if ( file.size > maxBytes ) {
				setError(
					sprintf(
						strings.fileTooLarge ||
							__( '"%s" is too large.', 'agent-mod' ),
						file.name
					)
				);
				continue;
			}

			// eslint-disable-next-line no-await-in-loop
			const data = await readAsDataUri( file );

			accepted.push( {
				id: nextId.current++,
				name: file.name,
				mimeType: file.type,
				size: file.size,
				isImage: file.type.startsWith( 'image/' ),
				data,
			} );

			room--;
		}

		if ( accepted.length ) {
			setAttachments( ( current ) => [ ...current, ...accepted ] );
		}
	};

	const removeAttachment = ( id ) => {
		setAttachments( ( current ) =>
			current.filter( ( item ) => item.id !== id )
		);
	};

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
			{ 0 < attachments.length && (
				<ul className="agent-mod-chat__attachments">
					{ attachments.map( ( item ) => (
						<li
							key={ item.id }
							className="agent-mod-chat__attachment"
						>
							{ item.isImage ? (
								<img
									className="agent-mod-chat__attachment-thumb"
									src={ item.data }
									alt={ item.name }
								/>
							) : (
								<span
									className="agent-mod-chat__attachment-icon dashicons dashicons-media-default"
									aria-hidden="true"
								/>
							) }
							<span
								className="agent-mod-chat__attachment-name"
								title={ item.name }
							>
								{ item.name }
							</span>
							<Button
								className="agent-mod-chat__attachment-remove"
								icon="no-alt"
								label={
									strings.removeAttachment ||
									__( 'Remove attachment', 'agent-mod' )
								}
								size="small"
								onClick={ () => removeAttachment( item.id ) }
								disabled={ loading }
							/>
						</li>
					) ) }
				</ul>
			) }

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

			<div className="agent-mod-chat__composer-actions">
				<input
					ref={ fileInputRef }
					className="agent-mod-chat__file-input"
					type="file"
					multiple
					accept={ accept }
					onChange={ onFilesSelected }
				/>
				<Button
					icon="paperclip"
					label={ strings.attach || __( 'Attach files', 'agent-mod' ) }
					onClick={ openPicker }
					disabled={ loading || attachments.length >= maxCount }
				/>
				<Button variant="primary" onClick={ submit } disabled={ ! canSend }>
					{ strings.send || __( 'Send', 'agent-mod' ) }
				</Button>
			</div>
		</div>
	);
}

/**
 * Attachment uploader.
 *
 * Controlled component that handles file selection, validation, and preview.
 * Renders only the attachment preview list and the hidden file input.
 * The trigger button lives in the parent (Composer) via the exposed open() ref method.
 *
 * Props:
 *   attachments  {Array}    Current attachment list managed by the parent.
 *   onChange     {Function} Called with the new attachments array on change.
 *   disabled     {boolean}  Disables remove buttons when true.
 *
 * Ref methods:
 *   open()  Opens the native file picker.
 */
import { forwardRef, useImperativeHandle, useRef } from '@wordpress/element';
import { Button } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

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

let nextId = 0;

const AttachmentUploader = forwardRef( function AttachmentUploader(
	{ attachments = [], onChange, disabled = false },
	ref
) {
	const fileInputRef = useRef( null );

	const config    = window.agentModChat || {};
	const strings   = config.strings || {};
	const limits    = config.attachments || {};
	const maxBytes  = limits.maxBytes || 5242880;
	const maxCount  = limits.maxCount || 5;
	const mimeTypes = limits.mimeTypes || [];
	const accept    = mimeTypes.join( ',' );

	useImperativeHandle( ref, () => ( {
		open() {
			if ( fileInputRef.current ) {
				fileInputRef.current.click();
			}
		},
	} ) );

	const onFilesSelected = async ( event ) => {
		const selected = Array.from( event.target.files || [] );
		event.target.value = '';

		if ( 0 === selected.length ) {
			return;
		}

		let room       = maxCount - attachments.length;
		const accepted = [];

		for ( const file of selected ) {
			if ( room <= 0 ) {
				break;
			}

			if ( mimeTypes.length && ! mimeTypes.includes( file.type ) ) {
				continue;
			}

			if ( file.size > maxBytes ) {
				continue;
			}

			// eslint-disable-next-line no-await-in-loop
			const data = await readAsDataUri( file );

			accepted.push( {
				id:       nextId++,
				name:     file.name,
				mimeType: file.type,
				size:     file.size,
				isImage:  file.type.startsWith( 'image/' ),
				data,
			} );

			room--;
		}

		if ( accepted.length && onChange ) {
			onChange( [ ...attachments, ...accepted ] );
		}
	};

	const removeAttachment = ( id ) => {
		if ( onChange ) {
			onChange( attachments.filter( ( item ) => item.id !== id ) );
		}
	};

	return (
		<>
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
								disabled={ disabled }
							/>
						</li>
					) ) }
				</ul>
			) }

			<input
				ref={ fileInputRef }
				className="agent-mod-chat__file-input"
				type="file"
				multiple
				accept={ accept }
				onChange={ onFilesSelected }
			/>
		</>
	);
} );

export default AttachmentUploader;

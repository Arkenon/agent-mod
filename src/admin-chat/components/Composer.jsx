/**
 * Message composer.
 *
 * Textarea + site-context toggle + send button. File attachment handling is
 * delegated to AttachmentUploader. Enter sends, Shift+Enter inserts a newline.
 *
 * The textarea is paired with an aria-hidden overlay that mirrors its value
 * and highlights "@ability-name" mentions — the textarea's own text is made
 * transparent (only the caret stays visible) so the overlay's highlighted
 * text is what's actually seen. Typing "@" also opens/filters the ability
 * tray live, and picking an ability (from the tray, however it was opened)
 * inserts it at the current caret position rather than at the end of the text.
 */
import { useEffect, useRef, useState } from '@wordpress/element';
import { useDispatch, useSelect } from '@wordpress/data';
import { Button, TextareaControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

import { STORE_NAME } from '../store';
import { MENTION_PATTERN, findMentionTrigger } from '../utils/mentions';
import AttachmentUploader from './AttachmentUploader';
import AgentSelector from './AgentSelector';
import ProviderModelSelector from './ProviderModelSelector';
import ModeSelector from './ModeSelector';
import AbilityTray from './AbilityTray';

/**
 * Splits text into plain-text and "@mention" parts for the highlight overlay.
 *
 * @param {string} value Composer text.
 * @return {Array} Alternating plain-text strings and mention name strings.
 */
function splitMentions( value ) {
	// Ensures the overlay keeps a trailing blank line's height when the text
	// ends with a newline (a bare trailing "\n" collapses visually otherwise).
	return value.endsWith( '\n' ) ? value + ' ' : value;
}

export default function Composer() {
	const [ text, setText ]               = useState( '' );
	const [ attachments, setAttachments ] = useState( [] );
	const [ mentionQuery, setMentionQuery ] = useState( null ); // in-progress "@" query, or null
	const [ mentionStart, setMentionStart ] = useState( null ); // offset of "@" for the query above

	const uploaderRef  = useRef( null );
	const textareaRef  = useRef( null );
	const highlightRef = useRef( null );

	const { sendMessage, clearMessages, setConversationId } =
		useDispatch( STORE_NAME );
	const { loading, hasMessages, strings, maxCount } = useSelect( ( select ) => {
		const storeSelect = select( STORE_NAME );
		return {
			loading:     storeSelect.isLoading(),
			hasMessages: storeSelect.getMessages().length > 0,
			strings:     storeSelect.getStrings(),
			maxCount:    storeSelect.getAttachmentLimits().maxCount,
		};
	}, [] );

	const canSend = ( '' !== text.trim() || 0 < attachments.length ) && ! loading;

	const clearMentionTrigger = () => {
		setMentionQuery( null );
		setMentionStart( null );
	};

	const submit = () => {
		if ( ! canSend ) {
			return;
		}
		sendMessage( text, attachments );
		setText( '' );
		setAttachments( [] );
		clearMentionTrigger();
	};

	const onKeyDown = ( event ) => {
		if ( 'Enter' === event.key && ! event.shiftKey ) {
			event.preventDefault();
			submit();
			return;
		}
		if ( 'Escape' === event.key && null !== mentionQuery ) {
			clearMentionTrigger();
		}
	};

	// Re-detects the in-progress "@" mention (if any) at the current caret
	// position, driving the ability tray's forced-open + live filter state.
	const updateMentionTrigger = () => {
		const node = textareaRef.current;
		if ( ! node ) {
			return;
		}
		const trigger = findMentionTrigger( node.value, node.selectionStart );
		setMentionQuery( trigger ? trigger.query : null );
		setMentionStart( trigger ? trigger.start : null );
	};

	const onChangeText = ( value ) => {
		setText( value );
		// The DOM's caret position already reflects this keystroke by the time
		// React's onChange fires, so it can be read straight off the ref.
		updateMentionTrigger();
	};

	const syncHighlightScroll = () => {
		if ( highlightRef.current && textareaRef.current ) {
			highlightRef.current.scrollTop  = textareaRef.current.scrollTop;
			highlightRef.current.scrollLeft = textareaRef.current.scrollLeft;
		}
	};

	useEffect( syncHighlightScroll, [ text ] );

	// Inserts (or, when triggered via an in-progress "@" query, replaces it
	// with) an ability mention at the current caret position, then restores
	// focus and moves the caret just past the inserted mention.
	const insertMention = ( name ) => {
		const node   = textareaRef.current;
		const caret  = node ? node.selectionStart : text.length;
		const hasTrigger = null !== mentionStart && null !== mentionQuery;
		const rangeStart = hasTrigger ? mentionStart : caret;

		const before = text.slice( 0, rangeStart );
		const after  = text.slice( caret );
		const needsLeadingSpace = ! hasTrigger && before && ! /\s$/.test( before );
		const mention  = ( needsLeadingSpace ? ' ' : '' ) + '@' + name + ' ';
		const nextText = before + mention + after;
		const nextCaret = before.length + mention.length;

		setText( nextText );
		clearMentionTrigger();

		requestAnimationFrame( () => {
			const el = textareaRef.current;
			if ( el ) {
				el.focus();
				el.setSelectionRange( nextCaret, nextCaret );
			}
		} );
	};

	const highlightParts = splitMentions( text ).split( MENTION_PATTERN );

	return (
		<div className="agent-mod-chat__composer">
			<AttachmentUploader
				ref={ uploaderRef }
				attachments={ attachments }
				onChange={ setAttachments }
				disabled={ loading }
			/>

			<div className="agent-mod-chat__input-wrapper">
				<div
					className="agent-mod-chat__input-highlight"
					ref={ highlightRef }
					aria-hidden="true"
				>
					{ highlightParts.map( ( part, index ) =>
						1 === index % 2 ? (
							<mark key={ index } className="agent-mod-chat__mention">
								{ '@' + part }
							</mark>
						) : (
							part
						)
					) }
				</div>

				<TextareaControl
					ref={ textareaRef }
					className="agent-mod-chat__input"
					value={ text }
					onChange={ onChangeText }
					onKeyDown={ onKeyDown }
					onKeyUp={ updateMentionTrigger }
					onClick={ updateMentionTrigger }
					onScroll={ syncHighlightScroll }
					placeholder={
						strings.placeholder || __( 'Type your message…', 'agent-mod' )
					}
					rows={ 2 }
					disabled={ loading }
				/>
			</div>

			<div className="agent-mod-chat__composer-actions">
				<div className="agent-mod-chat__tools">
					<AgentSelector />
					<ModeSelector />
					<ProviderModelSelector />
					<AbilityTray
						onInsert={ insertMention }
						disabled={ loading }
						search={ null !== mentionQuery ? mentionQuery : undefined }
						onSearchChange={ setMentionQuery }
						forceOpen={ null !== mentionQuery }
						onForceOpenChange={ ( open ) => {
							if ( ! open ) {
								clearMentionTrigger();
							}
						} }
					/>

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

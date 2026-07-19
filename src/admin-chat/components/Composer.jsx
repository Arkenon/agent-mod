/**
 * Message composer.
 *
 * Textarea + site-context toggle + send button. File attachment handling is
 * delegated to AttachmentUploader. Enter sends, Shift+Enter inserts a newline.
 *
 * The textarea is paired with an aria-hidden overlay that mirrors its value
 * and highlights "@ability-name" and "#skill-slug" mentions — the textarea's
 * own text is made transparent (only the caret stays visible) so the
 * overlay's highlighted text is what's actually seen. Typing "@" opens and
 * filters the ability tray live; typing "#" does the same for extension
 * trays (e.g. the Pro skill tray) via the composer-tools mention props.
 * Picking an item (from a tray, however it was opened) inserts it at the
 * current caret position rather than at the end of the text.
 */
import { useEffect, useRef, useState } from '@wordpress/element';
import { useDispatch, useSelect } from '@wordpress/data';
import { Button, TextareaControl } from '@wordpress/components';
import { applyFilters } from '@wordpress/hooks';
import { __ } from '@wordpress/i18n';

import { STORE_NAME } from '../store';
import { TOKEN_PATTERN, findMentionTrigger } from '../utils/mentions';
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
function splitMentions(value) {
	// Ensures the overlay keeps a trailing blank line's height when the text
	// ends with a newline (a bare trailing "\n" collapses visually otherwise).
	return value.endsWith('\n') ? value + ' ' : value;
}

export default function Composer() {
	const [text, setText] = useState('');
	const [attachments, setAttachments] = useState([]);
	const [mentionQuery, setMentionQuery] = useState(null); // in-progress mention query, or null
	const [mentionStart, setMentionStart] = useState(null); // offset of the trigger char for the query above
	const [mentionTrigger, setMentionTrigger] = useState(null); // '@' | '#' | null

	const uploaderRef = useRef(null);
	const textareaRef = useRef(null);
	const highlightRef = useRef(null);

	const { sendMessage, clearMessages, setConversationId } =
		useDispatch(STORE_NAME);
	const { loading, hasMessages, strings, maxCount } = useSelect((select) => {
		const storeSelect = select(STORE_NAME);
		return {
			loading: storeSelect.isLoading(),
			hasMessages: storeSelect.getMessages().length > 0,
			strings: storeSelect.getStrings(),
			maxCount: storeSelect.getAttachmentLimits().maxCount,
		};
	}, []);

	const canSend = ('' !== text.trim() || 0 < attachments.length) && !loading;

	const clearMentionTrigger = () => {
		setMentionQuery(null);
		setMentionStart(null);
		setMentionTrigger(null);
	};

	const submit = () => {
		if (!canSend) {
			return;
		}
		sendMessage(text, attachments);
		setText('');
		setAttachments([]);
		clearMentionTrigger();
	};

	const onKeyDown = (event) => {
		if ('Enter' === event.key && !event.shiftKey) {
			event.preventDefault();
			submit();
			return;
		}
		if ('Escape' === event.key && null !== mentionQuery) {
			clearMentionTrigger();
		}
	};

	// Re-detects the in-progress "@" or "#" mention (if any) at the current
	// caret position, driving the matching tray's forced-open + live filter
	// state.
	const updateMentionTrigger = () => {
		const node = textareaRef.current;
		if (!node) {
			return;
		}
		const trigger = findMentionTrigger(node.value, node.selectionStart);
		setMentionQuery(trigger ? trigger.query : null);
		setMentionStart(trigger ? trigger.start : null);
		setMentionTrigger(trigger ? trigger.trigger : null);
	};

	const onChangeText = (value) => {
		setText(value);
		// The DOM's caret position already reflects this keystroke by the time
		// React's onChange fires, so it can be read straight off the ref.
		updateMentionTrigger();
	};

	const syncHighlightScroll = () => {
		if (highlightRef.current && textareaRef.current) {
			highlightRef.current.scrollTop = textareaRef.current.scrollTop;
			highlightRef.current.scrollLeft = textareaRef.current.scrollLeft;
		}
	};

	useEffect(syncHighlightScroll, [text]);

	// Inserts (or, when triggered via an in-progress "@"/"#" query, replaces
	// it with) a mention token at the current caret position, then restores
	// focus and moves the caret just past the inserted mention. `token` must
	// include its trigger character (e.g. "@core/get-posts", "#site-tone").
	const insertToken = (token) => {
		const node = textareaRef.current;
		const caret = node ? node.selectionStart : text.length;
		const hasTrigger = null !== mentionStart && null !== mentionQuery;
		const rangeStart = hasTrigger ? mentionStart : caret;

		const before = text.slice(0, rangeStart);
		const after = text.slice(caret);
		const needsLeadingSpace = !hasTrigger && before && ! /\s$/.test(before);
		const mention = (needsLeadingSpace ? ' ' : '') + token + ' ';
		const nextText = before + mention + after;
		const nextCaret = before.length + mention.length;

		setText(nextText);
		clearMentionTrigger();

		requestAnimationFrame(() => {
			const el = textareaRef.current;
			if (el) {
				el.focus();
				el.setSelectionRange(nextCaret, nextCaret);
			}
		});
	};

	// Fills the composer with a preset prompt (appends when the user already
	// typed something, so their text is never destroyed), then focuses the
	// textarea with the caret at the end.
	const applyPreset = (prompt) => {
		const nextText = text.trim()
			? text.replace(/\s*$/, '') + '\n' + prompt
			: prompt;

		setText(nextText);
		clearMentionTrigger();

		requestAnimationFrame(() => {
			const el = textareaRef.current;
			if (el) {
				el.focus();
				el.setSelectionRange(nextText.length, nextText.length);
			}
		});
	};

	const presetPrompts = window.agentModChat?.presetPrompts || [];

	const highlightParts = splitMentions(text).split(TOKEN_PATTERN);

	return (

		<>
			{0 < presetPrompts.length && (
				<div className="agent-mod-chat__presets">
					{presetPrompts.map((preset, index) => (
						<Button
							key={index}
							className="agent-mod-chat__preset"
							variant="secondary"
							size="small"
							disabled={loading}
							title={preset.prompt}
							onClick={() => applyPreset(preset.prompt)}
						>
							{preset.label}
						</Button>
					))}
				</div>
			)}

			<div className="agent-mod-chat__composer">
				<AttachmentUploader
					ref={uploaderRef}
					attachments={attachments}
					onChange={setAttachments}
					disabled={loading}
				/>



				<div className="agent-mod-chat__input-wrapper">
					<div
						className="agent-mod-chat__input-highlight"
						ref={highlightRef}
						aria-hidden="true"
					>
						{highlightParts.map((part, index) =>
							1 === index % 2 ? (
								<mark key={index} className="agent-mod-chat__mention">
									{part}
								</mark>
							) : (
								part
							)
						)}
					</div>

					<TextareaControl
						ref={textareaRef}
						className="agent-mod-chat__input"
						value={text}
						onChange={onChangeText}
						onKeyDown={onKeyDown}
						onKeyUp={updateMentionTrigger}
						onClick={updateMentionTrigger}
						onScroll={syncHighlightScroll}
						placeholder={
							strings.placeholder || __('Type your message…', 'agent-mod')
						}
						rows={2}
						disabled={loading}
					/>
				</div>

				<div className="agent-mod-chat__composer-actions">
					<div className="agent-mod-chat__tools">
						<AgentSelector />
						<ModeSelector />
						<ProviderModelSelector />
						<AbilityTray
							onInsert={(name) => insertToken('@' + name)}
							disabled={loading}
							search={
								'@' === mentionTrigger ? mentionQuery : undefined
							}
							onSearchChange={setMentionQuery}
							forceOpen={'@' === mentionTrigger}
							onForceOpenChange={(open) => {
								if (!open) {
									clearMentionTrigger();
								}
							}}
						/>

						<Button
							icon="paperclip"
							label={strings.attach || __('Attach files', 'agent-mod')}
							onClick={() => uploaderRef.current?.open()}
							disabled={loading || attachments.length >= maxCount}
						/>

						{ /**
					   * Filters the extra composer toolbar tools.
					   *
					   * Lets extensions (e.g. Pro) append their own React
					   * components (such as a conversation history tray or a
					   * skill tray) to the composer toolbar. Each entry must be
					   * a component; it reads/dispatches the chat store itself
					   * and is rendered with:
					   *
					   * - `disabled`             Loading state of the chat.
					   * - `insertMention`        (fullToken) => void — inserts a
					   *                          mention token (including its
					   *                          trigger char, e.g. "#my-skill")
					   *                          at the caret.
					   * - `mentionTrigger`       '@' | '#' | null — the trigger
					   *                          of the in-progress mention.
					   * - `mentionQuery`         The partial query typed after
					   *                          the trigger, or null.
					   * - `onMentionQueryChange` Updates the live query.
					   * - `onMentionCancel`      Clears the in-progress mention
					   *                          (e.g. when a tray closes).
					   *
					   * @param {Array} tools Extra tool components, default [].
					   */ }
						{applyFilters('agent_mod.composer_tools', []).map(
							(ToolComponent, index) => (
								<ToolComponent
									key={index}
									disabled={loading}
									insertMention={insertToken}
									mentionTrigger={mentionTrigger}
									mentionQuery={mentionQuery}
									onMentionQueryChange={setMentionQuery}
									onMentionCancel={clearMentionTrigger}
								/>
							)
						)}

						{hasMessages && !loading && (
							<Button
								variant="tertiary"
								size="small"
								onClick={() => {
									clearMessages();
									setConversationId(null);
								}}
							>
								{__('New Topic', 'agent-mod')}
							</Button>
						)}
					</div>

					<Button variant="primary" onClick={submit} disabled={!canSend}>
						{strings.send || __('Send', 'agent-mod')}
					</Button>
				</div>
			</div>
		</>

	);
}

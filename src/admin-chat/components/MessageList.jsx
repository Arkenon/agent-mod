/**
 * Renders the list of chat messages and auto-scrolls to the latest one.
 *
 * While a response is loading, live tool-call progress (polled from the
 * chat-progress endpoint) is shown next to the spinner. Auto-scroll only
 * follows new content when the user is already near the bottom, so live
 * tool-call updates never yank the view while they read earlier messages.
 */
import { useSelect } from '@wordpress/data';
import { useRef, useEffect } from '@wordpress/element';
import { Spinner } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';

import { STORE_NAME } from '../store';
import MessageItem from './MessageItem';

export default function MessageList() {
	const { messages, loading, progress } = useSelect( ( select ) => {
		const storeSelect = select( STORE_NAME );

		return {
			messages: storeSelect.getMessages(),
			loading: storeSelect.isLoading(),
			progress: storeSelect.getProgress(),
		};
	}, [] );

	const containerRef = useRef( null );
	const endRef = useRef( null );
	const nearBottomRef = useRef( true );

	// Whether the user is reading at the bottom of the transcript. Stored in a
	// ref (not state) so scrolling never triggers a re-render; it is only read
	// inside the auto-scroll effect below.
	const handleScroll = () => {
		const el = containerRef.current;
		if ( ! el ) {
			return;
		}
		nearBottomRef.current =
			el.scrollHeight - el.scrollTop - el.clientHeight < 120;
	};

	// Follow new content only when the user is already near the bottom. progress
	// changes on every ~1.2s poll during tool calls, so without this guard the
	// view would be dragged down constantly while the user scrolls up to read.
	useEffect( () => {
		if ( nearBottomRef.current && endRef.current ) {
			endRef.current.scrollIntoView( { behavior: 'smooth' } );
		}
	}, [ messages.length, loading, progress ] );

	const doneCalls = ( progress?.executedCalls || [] ).filter(
		( call ) => 'done' === call.status
	);

	// AgentMod is built around tool calls, so there's no separate "generating
	// a plain-text answer" phase worth naming — only an active tool call gets
	// a status line; every other moment (including between tool calls) just
	// shows the spinner.
	const isRunningTool = 'running_tool' === progress?.status && progress?.currentTool;

	return (
		<div
			className="agent-mod-chat__messages"
			ref={ containerRef }
			onScroll={ handleScroll }
		>
			{ 0 === messages.length && ! loading && (
				<p className="agent-mod-chat__empty">
					{ __( 'Ask the assistant anything about your site.', 'agent-mod' ) }
				</p>
			) }

			{ messages.map( ( message, index ) => (
				<MessageItem key={ index } message={ message } />
			) ) }

			{ loading && (
				<div className="agent-mod-chat__loading">
					<Spinner />

					{ isRunningTool && (
						<div className="agent-mod-chat__progress">
							<span className="agent-mod-chat__progress-status">
								{ sprintf(
									/* translators: %s: tool name(s) being executed. */
									__( 'Running tool: %s…', 'agent-mod' ),
									progress.currentTool
								) }
							</span>

							{ 0 < doneCalls.length && (
								<ul className="agent-mod-chat__progress-calls">
									{ doneCalls.map( ( call, index ) => (
										<li key={ index }>
											<span
												className="dashicons dashicons-yes"
												aria-hidden="true"
											/>
											<code>{ call.name }</code>
										</li>
									) ) }
								</ul>
							) }
						</div>
					) }
				</div>
			) }

			<div ref={ endRef } />
		</div>
	);
}

/**
 * Renders the list of chat messages and auto-scrolls to the latest one.
 *
 * While a response is loading, live tool-call progress (polled from the
 * chat-progress endpoint) is shown next to the spinner.
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

	const endRef = useRef( null );

	useEffect( () => {
		if ( endRef.current ) {
			endRef.current.scrollIntoView( { behavior: 'smooth' } );
		}
	}, [ messages.length, loading, progress ] );

	const doneCalls = ( progress?.executedCalls || [] ).filter(
		( call ) => 'done' === call.status
	);

	return (
		<div className="agent-mod-chat__messages">
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

					{ progress && (
						<div className="agent-mod-chat__progress">
							<span className="agent-mod-chat__progress-status">
								{ 'running_tool' === progress.status && progress.currentTool
									? sprintf(
										/* translators: %s: tool name(s) being executed. */
										__( 'Running tool: %s…', 'agent-mod' ),
										progress.currentTool
									)
									: __( 'Thinking…', 'agent-mod' ) }
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

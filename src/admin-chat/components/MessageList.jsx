/**
 * Renders the list of chat messages and auto-scrolls to the latest one.
 */
import { useSelect } from '@wordpress/data';
import { useRef, useEffect } from '@wordpress/element';
import { Spinner } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

import { STORE_NAME } from '../store';
import MessageItem from './MessageItem';

export default function MessageList() {
	const { messages, loading } = useSelect( ( select ) => {
		const storeSelect = select( STORE_NAME );

		return {
			messages: storeSelect.getMessages(),
			loading: storeSelect.isLoading(),
		};
	}, [] );

	const endRef = useRef( null );

	useEffect( () => {
		if ( endRef.current ) {
			endRef.current.scrollIntoView( { behavior: 'smooth' } );
		}
	}, [ messages.length, loading ] );

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
				</div>
			) }

			<div ref={ endRef } />
		</div>
	);
}

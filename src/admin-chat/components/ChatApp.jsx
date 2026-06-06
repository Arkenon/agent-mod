/**
 * Root component. Renders the modal only while the chat is open.
 */
import { useSelect } from '@wordpress/data';

import { STORE_NAME } from '../store';
import ChatModal from './ChatModal';

export default function ChatApp() {
	const isOpen = useSelect(
		( select ) => select( STORE_NAME ).isChatOpen(),
		[]
	);

	if ( ! isOpen ) {
		return null;
	}

	return <ChatModal />;
}

/**
 * Agent selector.
 *
 * Dropdown that lists published agentmod_agent posts and lets the user switch
 * which agent the chat session uses. Fetches the agent list from the REST API
 * on first render and dispatches selectAgent() on change.
 */
import { useEffect } from '@wordpress/element';
import { SelectControl, Spinner } from '@wordpress/components';
import { useDispatch, useSelect } from '@wordpress/data';
import { __ } from '@wordpress/i18n';

import { STORE_NAME } from '../store';

export default function AgentSelector() {
	const { fetchAgents, selectAgent } = useDispatch( STORE_NAME );

	const { agents, selectedAgentId, loading } = useSelect( ( select ) => {
		const storeSelect = select( STORE_NAME );
		return {
			agents:          storeSelect.getAgents(),
			selectedAgentId: storeSelect.getSelectedAgentId(),
			loading:         storeSelect.isLoading(),
		};
	}, [] );

	useEffect( () => {
		if ( 0 === agents.length ) {
			fetchAgents();
		}
	}, [] ); // eslint-disable-line react-hooks/exhaustive-deps

	if ( 0 === agents.length ) {
		return loading ? <Spinner /> : null;
	}

	const options = [
		{ label: __( '— Default Agent —', 'agent-mod' ), value: '' },
		...agents.map( ( agent ) => ( {
			label: agent.name,
			value: String( agent.id ),
		} ) ),
	];

	return (
		<div className="agent-mod-chat__agent-selector">
			<SelectControl
				label={ __( 'Agent', 'agent-mod' ) }
				hideLabelFromVision
				value={ selectedAgentId ? String( selectedAgentId ) : '' }
				options={ options }
				onChange={ ( value ) => selectAgent( value ? parseInt( value, 10 ) : null ) }
				disabled={ loading }
				__nextHasNoMarginBottom
			/>
		</div>
	);
}

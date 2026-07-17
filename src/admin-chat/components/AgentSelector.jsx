/**
 * Agent tray (drop-up).
 *
 * Toolbar selector that lists the available agents with their avatars and lets
 * the user switch which agent the chat session uses. The first entry is always
 * the built-in Default Agent (configured on the AgentMod settings page); the
 * rest of the list comes from GET /agents (populated by extensions through the
 * `agent_mod_get_agents` PHP filter) and can be adjusted client-side via the
 * `agent_mod.agents` JS filter (see fetchAgents in store/actions.js).
 *
 * Selecting an agent only stores its id — the agent's configuration is
 * resolved server-side on every chat request.
 */
import { useEffect, useState } from '@wordpress/element';
import { Dropdown, Button, Spinner, Dashicon } from '@wordpress/components';
import { useDispatch, useSelect } from '@wordpress/data';
import { __ } from '@wordpress/i18n';

import { STORE_NAME } from '../store';

/**
 * Renders an agent's avatar image, its Dashicon fallback, or the default icon.
 *
 * @param {Object} props
 * @param {Object} props.agent         Agent record ({ avatar, icon, name }).
 * @param {string} props.fallbackImage Image URL used when the agent has neither avatar nor icon.
 */
function AgentAvatar( { agent, fallbackImage } ) {
	if ( agent.avatar ) {
		return (
			<img
				className="agent-mod-chat__agent-avatar"
				src={ agent.avatar }
				alt=""
			/>
		);
	}

	if ( agent.icon ) {
		return (
			<span className="agent-mod-chat__agent-avatar agent-mod-chat__agent-avatar--icon">
				<Dashicon icon={ agent.icon } />
			</span>
		);
	}

	return (
		<img
			className="agent-mod-chat__agent-avatar"
			src={ fallbackImage }
			alt=""
		/>
	);
}

export default function AgentSelector() {
	const { fetchAgents, selectAgent } = useDispatch( STORE_NAME );

	// Local fetch state so the tray's spinner reflects *its own* agent load,
	// not the shared chat isLoading flag (which message sends also set).
	const [ fetching, setFetching ] = useState( false );

	const { agents, selectedAgentId, defaultAgent, loading } = useSelect( ( select ) => {
		const storeSelect = select( STORE_NAME );
		return {
			agents: storeSelect.getAgents(),
			selectedAgentId: storeSelect.getSelectedAgentId(),
			defaultAgent: storeSelect.getDefaultAgent(),
			loading: storeSelect.isLoading(),
		};
	}, [] );

	useEffect( () => {
		if ( 0 === agents.length ) {
			setFetching( true );
			Promise.resolve( fetchAgents() ).finally( () => setFetching( false ) );
		}
	}, [] ); // eslint-disable-line react-hooks/exhaustive-deps

	// Graceful fallback to the default agent when the selected one disappears
	// (e.g. the post was unpublished after the list was fetched).
	const selectedAgent =
		( selectedAgentId && agents.find( ( a ) => a.id === selectedAgentId ) ) ||
		defaultAgent;

	const entries = [ defaultAgent, ...agents ];

	return (
		<Dropdown
			className="agent-mod-chat__agent-selector"
			popoverProps={ { placement: 'top-start' } }
			renderToggle={ ( { isOpen, onToggle } ) => (
				<Button
					variant="tertiary"
					size="small"
					className="agent-mod-chat__agent-toggle"
					aria-expanded={ isOpen }
					disabled={ loading }
					label={ __( 'Agent', 'agent-mod' ) }
					onClick={ onToggle }
				>
					<AgentAvatar
						agent={ selectedAgent }
						fallbackImage={ defaultAgent.avatar }
					/>
					<span className="agent-mod-chat__agent-toggle-name">
						{ selectedAgent.name }
					</span>
				</Button>
			) }
			renderContent={ ( { onClose } ) => (
				<div className="agent-mod-chat__agent-tray">
					<p className="agent-mod-chat__agent-tray-title">
						{ __( 'Agents', 'agent-mod' ) }
					</p>

					{ fetching && <Spinner /> }

					{ ! fetching &&
						entries.map( ( agent ) => {
							const isSelected =
								( agent.id || null ) === ( selectedAgent.id || null );

							return (
								<Button
									key={ agent.id || 'default' }
									className={
										'agent-mod-chat__agent-item' +
										( isSelected ? ' is-selected' : '' )
									}
									aria-current={ isSelected ? 'true' : undefined }
									onClick={ () => {
										selectAgent( agent.id || null );
										onClose();
									} }
								>
									<AgentAvatar
										agent={ agent }
										fallbackImage={ defaultAgent.avatar }
									/>
									<span className="agent-mod-chat__agent-item-text">
										<span className="agent-mod-chat__agent-item-name">
											{ agent.name }
										</span>
										{ agent.description && (
											<span className="agent-mod-chat__agent-desc">
												{ agent.description }
											</span>
										) }
									</span>
									{ isSelected && <Dashicon icon="yes" /> }
								</Button>
							);
						} ) }
				</div>
			) }
		/>
	);
}

/**
 * Interaction mode picker (drop-up).
 *
 * Lets the user choose how the assistant behaves for the next messages:
 * Ask (read-only Q&A), Plan (read-only planning) or Execute (default, full
 * ability execution). The selected mode is sent as `agent.mode` and enforced
 * server-side (write abilities are removed in ask/plan modes).
 */
import { Dropdown, Button } from '@wordpress/components';
import { useDispatch, useSelect } from '@wordpress/data';
import { __ } from '@wordpress/i18n';

import { STORE_NAME } from '../store';

const MODES = [
	{
		id: 'ask',
		icon: 'editor-help',
		label: __( 'Ask', 'agent-mod' ),
		description: __( 'Answer questions using site data. No changes are made.', 'agent-mod' ),
	},
	{
		id: 'plan',
		icon: 'editor-ul',
		label: __( 'Plan', 'agent-mod' ),
		description: __( 'Produce a step-by-step plan without executing changes.', 'agent-mod' ),
	},
	{
		id: 'execute',
		icon: 'controls-play',
		label: __( 'Execute', 'agent-mod' ),
		description: __( 'Run abilities to carry out requests (default).', 'agent-mod' ),
	},
];

export default function ModeSelector() {
	const { selectMode } = useDispatch( STORE_NAME );

	const selectedMode = useSelect(
		( select ) => select( STORE_NAME ).getSelectedMode(),
		[]
	);

	const current = MODES.find( ( mode ) => mode.id === selectedMode ) || MODES[ 2 ];

	return (
		<Dropdown
			className="agent-mod-chat__mode-picker"
			popoverProps={ { placement: 'top-start' } }
			renderToggle={ ( { isOpen, onToggle } ) => (
				<Button
					variant="tertiary"
					size="small"
					icon={ current.icon }
					aria-expanded={ isOpen }
					label={ __( 'Mode', 'agent-mod' ) }
					onClick={ onToggle }
				>
					{ current.label }
				</Button>
			) }
			renderContent={ ( { onClose } ) => (
				<div className="agent-mod-chat__mode-menu">
					{ MODES.map( ( mode ) => (
						<Button
							key={ mode.id }
							className={ mode.id === selectedMode ? 'is-selected' : '' }
							onClick={ () => {
								selectMode( mode.id );
								onClose();
							} }
						>
							<span className="agent-mod-chat__mode-label">
								{ mode.label }
							</span>
							<span className="agent-mod-chat__mode-description">
								{ mode.description }
							</span>
						</Button>
					) ) }
				</div>
			) }
		/>
	);
}

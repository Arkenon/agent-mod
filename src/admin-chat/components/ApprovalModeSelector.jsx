/**
 * Write-action approval picker (drop-up), shown only in Execute mode.
 *
 * Manual (default) keeps the confirmation modal for every write action, with
 * its per-ability "don't ask again" option. Auto sends the '*' wildcard as the
 * session allowlist, so every tool call runs without asking. Site owners can
 * still revoke it server-side via the agent_mod_auto_approved_abilities filter.
 */
import { Dropdown, Button } from '@wordpress/components';
import { useDispatch, useSelect } from '@wordpress/data';
import { __ } from '@wordpress/i18n';

import { STORE_NAME } from '../store';

const APPROVAL_MODES = [
	{
		id: 'manual',
		icon: 'shield',
		label: __( 'Manual', 'agent-mod' ),
		description: __( 'Ask for confirmation before each change (default).', 'agent-mod' ),
	},
	{
		id: 'auto',
		icon: 'unlock',
		label: __( 'Auto', 'agent-mod' ),
		description: __( 'Run every action without asking. The agent has full control.', 'agent-mod' ),
	},
];

export default function ApprovalModeSelector() {
	const { selectApprovalMode } = useDispatch( STORE_NAME );

	const { selectedMode, approvalMode } = useSelect( ( select ) => {
		const storeSelect = select( STORE_NAME );

		return {
			selectedMode: storeSelect.getSelectedMode(),
			approvalMode: storeSelect.getApprovalMode(),
		};
	}, [] );

	if ( 'execute' !== ( selectedMode || 'execute' ) ) {
		return null;
	}

	const current =
		APPROVAL_MODES.find( ( mode ) => mode.id === approvalMode ) || APPROVAL_MODES[ 0 ];

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
					label={ __( 'Approval', 'agent-mod' ) }
					onClick={ onToggle }
				>
					{ current.label }
				</Button>
			) }
			renderContent={ ( { onClose } ) => (
				<div className="agent-mod-chat__mode-menu">
					{ APPROVAL_MODES.map( ( mode ) => (
						<Button
							key={ mode.id }
							className={ mode.id === current.id ? 'is-selected' : '' }
							onClick={ () => {
								selectApprovalMode( mode.id );
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

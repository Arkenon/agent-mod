/**
 * Plan review modal.
 *
 * Opens after the agent delivered a plan through its present-plan tool in
 * Plan mode. The plan is shown again inside the modal (the modal covers the
 * chat), and the next step is one click away: approve it and switch to
 * Execute mode with a chosen approval mode, have the agent review it, or send
 * requested changes from a text field — no reply through the composer needed.
 */
import { Button, Modal, RadioControl, TextareaControl } from '@wordpress/components';
import { useDispatch, useSelect } from '@wordpress/data';
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { STORE_NAME } from '../store';
import { APPROVAL_MODES } from './ApprovalModeSelector';
import MessageContent from './MessageContent';

/**
 * The review form for one delivered plan.
 *
 * @param {Object} props                     Component props.
 * @param {Object} props.review              Pending plan review ({ summary, text }).
 * @param {string} props.currentApprovalMode The approval mode currently selected.
 */
function PlanReviewForm( { review, currentApprovalMode } ) {
	const { approvePlan, requestPlanReview, requestPlanChanges, clearPlanReview } =
		useDispatch( STORE_NAME );

	const [ approvalMode, setApprovalMode ] = useState( currentApprovalMode || 'manual' );
	const [ isRequestingChanges, setRequestingChanges ] = useState( false );
	const [ changes, setChanges ] = useState( '' );

	return (
		<Modal
			title={ __( 'Plan ready', 'agent-mod' ) }
			onRequestClose={ clearPlanReview }
			className="agent-mod-chat__plan-modal"
		>
			{ '' !== review.summary.trim() && (
				<p className="agent-mod-chat__plan-summary">{ review.summary }</p>
			) }

			<div className="agent-mod-chat__plan-body">
				<MessageContent message={ { role: 'assistant', text: review.text } } />
			</div>

			{ isRequestingChanges ? (
				<div className="agent-mod-chat__plan-changes">
					<TextareaControl
						__nextHasNoMarginBottom
						label={ __( 'What should change in the plan?', 'agent-mod' ) }
						rows={ 4 }
						value={ changes }
						onChange={ setChanges }
					/>
					<div className="agent-mod-chat__confirm-buttons">
						<Button
							variant="primary"
							onClick={ () => requestPlanChanges( changes.trim() ) }
							disabled={ '' === changes.trim() }
							accessibleWhenDisabled
						>
							{ __( 'Send', 'agent-mod' ) }
						</Button>
						<Button variant="secondary" onClick={ () => setRequestingChanges( false ) }>
							{ __( 'Back', 'agent-mod' ) }
						</Button>
					</div>
				</div>
			) : (
				<>
					<div className="agent-mod-chat__plan-approval">
						<RadioControl
							label={ __( 'Run the plan with', 'agent-mod' ) }
							selected={ approvalMode }
							options={ APPROVAL_MODES.map( ( mode ) => ( {
								value: mode.id,
								label: mode.label,
								description: mode.description,
							} ) ) }
							onChange={ setApprovalMode }
						/>
					</div>

					<div className="agent-mod-chat__plan-buttons">
						<Button variant="primary" onClick={ () => approvePlan( approvalMode ) }>
							{ __( 'Approve & Execute', 'agent-mod' ) }
						</Button>
						<Button variant="secondary" onClick={ requestPlanReview }>
							{ __( 'Ask Agent to Review', 'agent-mod' ) }
						</Button>
						<Button variant="secondary" onClick={ () => setRequestingChanges( true ) }>
							{ __( 'Request Changes', 'agent-mod' ) }
						</Button>
						<Button variant="tertiary" onClick={ clearPlanReview }>
							{ __( 'Close', 'agent-mod' ) }
						</Button>
					</div>
				</>
			) }
		</Modal>
	);
}

export default function PlanReviewModal() {
	const { review, approvalMode } = useSelect( ( select ) => {
		const storeSelect = select( STORE_NAME );
		return {
			review:       storeSelect.getPendingPlanReview(),
			approvalMode: storeSelect.getApprovalMode(),
		};
	}, [] );

	if ( ! review ) {
		return null;
	}

	// Keyed by the plan text: a revised plan starts with a fresh form.
	return (
		<PlanReviewForm
			key={ review.text }
			review={ review }
			currentApprovalMode={ approvalMode }
		/>
	);
}

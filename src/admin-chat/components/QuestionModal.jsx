/**
 * Agent question modal.
 *
 * Renders when the run paused on the agent's ask-user call. Each question gets
 * the control its type calls for — radio buttons for a single choice,
 * checkboxes for several, a text area for free text — plus an "Other" field
 * when the agent allowed one. Submit resumes the run with the answers; Skip
 * resumes it too, telling the agent to continue with sensible defaults.
 *
 * A request-approval pause (kind 'approval') travels the same path but shows
 * Continue / Request changes / Cancel instead of a question form.
 */
import {
	Button,
	CheckboxControl,
	Modal,
	RadioControl,
	TextControl,
	TextareaControl,
} from '@wordpress/components';
import { useDispatch, useSelect } from '@wordpress/data';
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { STORE_NAME } from '../store';
import MessageContent from './MessageContent';

/**
 * Radio value standing for "Other". Client-side only: an "Other" answer is
 * sent as free text, never as this value.
 */
const OTHER = '__agent_mod_other__';

/**
 * Initial answer state for a question: the recommended option is preselected.
 *
 * @param {Object} question Normalized question.
 * @return {Object} { selected: string[], other: string, otherOn: boolean }.
 */
function initialAnswer( question ) {
	return {
		selected: question.recommended ? [ question.recommended ] : [],
		other: '',
		otherOn: false,
	};
}

/**
 * Whether a question has a usable answer.
 *
 * @param {Object} question Normalized question.
 * @param {Object} answer   Answer state.
 * @return {boolean} True when answered.
 */
function isAnswered( question, answer ) {
	const hasOther = answer.otherOn && '' !== answer.other.trim();

	if ( 'text' === question.type ) {
		return '' !== answer.other.trim();
	}

	if ( 'single' === question.type && answer.otherOn ) {
		return hasOther;
	}

	return 0 < answer.selected.length || hasOther;
}

/**
 * Converts the answer state into the wire shape the endpoint expects.
 *
 * @param {Object} question Normalized question.
 * @param {Object} answer   Answer state.
 * @return {Object} { selected: string[], other: string }.
 */
function toWireAnswer( question, answer ) {
	if ( 'text' === question.type ) {
		return { selected: [], other: answer.other.trim() };
	}

	if ( 'single' === question.type && answer.otherOn ) {
		return { selected: [], other: answer.other.trim() };
	}

	return {
		selected: answer.selected,
		other: answer.otherOn ? answer.other.trim() : '',
	};
}

/**
 * Option label, marking the agent's recommendation.
 *
 * @param {Object} question Normalized question.
 * @param {string} option   Option text.
 * @return {string} Label.
 */
function optionLabel( question, option ) {
	return option === question.recommended
		? sprintf(
				/* translators: %s: option text. */
				__( '%s (Recommended)', 'agent-mod' ),
				option
		  )
		: option;
}

/**
 * One question with the control its type calls for.
 *
 * @param {Object}   props          Component props.
 * @param {Object}   props.question Normalized question.
 * @param {Object}   props.answer   Answer state.
 * @param {Function} props.onChange Receives the next answer state.
 */
function QuestionField( { question, answer, onChange } ) {
	const otherField = (
		<TextControl
			__nextHasNoMarginBottom
			__next40pxDefaultSize
			className="agent-mod-chat__question-other"
			label={ __( 'Your answer', 'agent-mod' ) }
			hideLabelFromVision
			placeholder={ __( 'Type your answer…', 'agent-mod' ) }
			value={ answer.other }
			onChange={ ( other ) => onChange( { ...answer, other } ) }
		/>
	);

	if ( 'text' === question.type ) {
		return (
			<TextareaControl
				__nextHasNoMarginBottom
				label={ question.question }
				rows={ 3 }
				value={ answer.other }
				onChange={ ( other ) => onChange( { ...answer, other } ) }
			/>
		);
	}

	if ( 'single' === question.type ) {
		const options = question.options.map( ( option ) => ( {
			label: optionLabel( question, option ),
			value: option,
		} ) );

		if ( question.allowOther ) {
			options.push( { label: __( 'Other', 'agent-mod' ), value: OTHER } );
		}

		return (
			<>
				<RadioControl
					label={ question.question }
					options={ options }
					selected={ answer.otherOn ? OTHER : answer.selected[ 0 ] || '' }
					onChange={ ( value ) =>
						onChange(
							OTHER === value
								? { ...answer, selected: [], otherOn: true }
								: { ...answer, selected: [ value ], otherOn: false }
						)
					}
				/>
				{ answer.otherOn && otherField }
			</>
		);
	}

	const toggle = ( option, checked ) =>
		onChange( {
			...answer,
			selected: checked
				? [ ...answer.selected, option ]
				: answer.selected.filter( ( value ) => value !== option ),
		} );

	return (
		<fieldset>
			<legend className="agent-mod-chat__question-legend">
				{ question.question }
			</legend>
			{ question.options.map( ( option ) => (
				<CheckboxControl
					__nextHasNoMarginBottom
					key={ option }
					label={ optionLabel( question, option ) }
					checked={ answer.selected.includes( option ) }
					onChange={ ( checked ) => toggle( option, checked ) }
				/>
			) ) }
			{ question.allowOther && (
				<CheckboxControl
					__nextHasNoMarginBottom
					label={ __( 'Other', 'agent-mod' ) }
					checked={ answer.otherOn }
					onChange={ ( otherOn ) => onChange( { ...answer, otherOn } ) }
				/>
			) }
			{ answer.otherOn && otherField }
		</fieldset>
	);
}

/**
 * The question form for one pending-questions token.
 *
 * @param {Object} props                Component props.
 * @param {Object} props.pending        Pending questions from the store.
 * @param {number} props.conversationId Current conversation ID.
 */
function QuestionForm( { pending, conversationId } ) {
	const { answerQuestions } = useDispatch( STORE_NAME );
	const { token, intro, questions, text } = pending;

	const [ answers, setAnswers ] = useState( () =>
		Object.fromEntries(
			questions.map( ( question ) => [ question.id, initialAnswer( question ) ] )
		)
	);

	const allAnswered = questions.every( ( question ) =>
		isAnswered( question, answers[ question.id ] )
	);

	const onSubmit = () => {
		answerQuestions(
			token,
			conversationId,
			Object.fromEntries(
				questions.map( ( question ) => [
					question.id,
					toWireAnswer( question, answers[ question.id ] ),
				] )
			)
		);
	};

	const onSkip = () => {
		answerQuestions( token, conversationId, {}, true );
	};

	return (
		<Modal
			title={ __( 'The agent has a question', 'agent-mod' ) }
			onRequestClose={ onSkip }
			className="agent-mod-chat__question-modal"
			isDismissible={ false }
		>
			{ '' !== text.trim() && (
				<p className="agent-mod-chat__question-text">{ text }</p>
			) }
			{ '' !== intro.trim() && (
				<p className="agent-mod-chat__question-intro">{ intro }</p>
			) }

			{ questions.map( ( question ) => (
				<div className="agent-mod-chat__question" key={ question.id }>
					<QuestionField
						question={ question }
						answer={ answers[ question.id ] }
						onChange={ ( next ) =>
							setAnswers( ( current ) => ( {
								...current,
								[ question.id ]: next,
							} ) )
						}
					/>
				</div>
			) ) }

			<div className="agent-mod-chat__confirm-buttons">
				<Button
					variant="primary"
					onClick={ onSubmit }
					disabled={ ! allAnswered }
					accessibleWhenDisabled
				>
					{ __( 'Submit', 'agent-mod' ) }
				</Button>
				<Button variant="secondary" onClick={ onSkip }>
					{ __( 'Skip', 'agent-mod' ) }
				</Button>
			</div>
		</Modal>
	);
}

/**
 * The go-ahead dialog for one pending request-approval token.
 *
 * Continue approves, Cancel declines (so does closing the dialog), and
 * "Request changes" sends the typed text back so the agent reworks its
 * approach instead of acting.
 *
 * @param {Object} props                Component props.
 * @param {Object} props.pending        Pending approval from the store.
 * @param {number} props.conversationId Current conversation ID.
 */
function ApprovalForm( { pending, conversationId } ) {
	const { answerQuestions } = useDispatch( STORE_NAME );
	const { token, intro, details, reason, text } = pending;

	const [ changesOn, setChangesOn ] = useState( false );
	const [ changes, setChanges ] = useState( '' );

	const answer = ( selected, other = '' ) =>
		answerQuestions( token, conversationId, {
			approval: { selected, other },
		} );

	return (
		<Modal
			title={ __( 'The agent is waiting for your approval', 'agent-mod' ) }
			onRequestClose={ () => answer( [ 'cancel' ] ) }
			className="agent-mod-chat__question-modal agent-mod-chat__approval-modal"
			isDismissible={ false }
		>
			{ '' !== text.trim() && (
				<p className="agent-mod-chat__question-text">{ text }</p>
			) }
			{ '' !== reason.trim() && (
				<p className="agent-mod-chat__question-intro">{ reason }</p>
			) }

			<div className="agent-mod-chat__question">
				<p className="agent-mod-chat__approval-summary">{ intro }</p>
				{ '' !== details.trim() && (
					<MessageContent message={ { role: 'assistant', text: details } } />
				) }
			</div>

			{ changesOn && (
				<TextareaControl
					__nextHasNoMarginBottom
					label={ __( 'What should the agent do differently?', 'agent-mod' ) }
					rows={ 3 }
					value={ changes }
					onChange={ setChanges }
				/>
			) }

			<div className="agent-mod-chat__confirm-buttons">
				{ changesOn ? (
					<>
						<Button
							variant="primary"
							onClick={ () => answer( [], changes.trim() ) }
							disabled={ '' === changes.trim() }
							accessibleWhenDisabled
						>
							{ __( 'Send changes', 'agent-mod' ) }
						</Button>
						<Button variant="secondary" onClick={ () => setChangesOn( false ) }>
							{ __( 'Back', 'agent-mod' ) }
						</Button>
					</>
				) : (
					<>
						<Button variant="primary" onClick={ () => answer( [ 'continue' ] ) }>
							{ __( 'Continue', 'agent-mod' ) }
						</Button>
						<Button variant="secondary" onClick={ () => setChangesOn( true ) }>
							{ __( 'Request changes', 'agent-mod' ) }
						</Button>
						<Button variant="tertiary" onClick={ () => answer( [ 'cancel' ] ) }>
							{ __( 'Cancel', 'agent-mod' ) }
						</Button>
					</>
				) }
			</div>
		</Modal>
	);
}

export default function QuestionModal() {
	const { pendingQuestions, conversationId } = useSelect( ( select ) => {
		const storeSelect = select( STORE_NAME );
		return {
			pendingQuestions: storeSelect.getPendingQuestions(),
			conversationId:   storeSelect.getConversationId(),
		};
	}, [] );

	if ( ! pendingQuestions || ! pendingQuestions.questions.length ) {
		return null;
	}

	// Keyed by token: a chained question round starts with a fresh form.
	if ( 'approval' === pendingQuestions.kind ) {
		return (
			<ApprovalForm
				key={ pendingQuestions.token }
				pending={ pendingQuestions }
				conversationId={ conversationId }
			/>
		);
	}

	return (
		<QuestionForm
			key={ pendingQuestions.token }
			pending={ pendingQuestions }
			conversationId={ conversationId }
		/>
	);
}

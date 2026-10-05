/**
 * Live tool-call progress card shown while a response is loading.
 *
 * The header carries the spinner, the tool currently running and a call
 * counter. Calls are listed as bordered rows (spinning icon while running,
 * check once done) with a one-line argument preview. Long runs can produce
 * dozens of calls, so only the most recent few are shown until the user
 * expands the list; the header toggle hides the list entirely.
 */
import { useState } from '@wordpress/element';
import { Spinner } from '@wordpress/components';
import { __, _n, sprintf } from '@wordpress/i18n';

// Rows visible before the "show earlier" toggle kicks in.
const VISIBLE_CALLS = 4;

/**
 * Compact one-line preview of a call's arguments.
 *
 * @param {*} args Tool-call arguments.
 * @return {string} Preview, or '' when there is nothing worth showing.
 */
function formatArgs( args ) {
	if ( ! args || ( 'object' === typeof args && 0 === Object.keys( args ).length ) ) {
		return '';
	}

	try {
		return JSON.stringify( args );
	} catch ( e ) {
		return '';
	}
}

export default function ToolProgress( { progress } ) {
	const [ isOpen, setIsOpen ] = useState( true );
	const [ showAll, setShowAll ] = useState( false );

	const calls = progress?.executedCalls || [];
	const doneCount = calls.filter( ( call ) => 'done' === call.status ).length;
	const isRunningTool = 'running_tool' === progress?.status && progress?.currentTool;

	// The first model pass has nothing to show yet: keep the bare spinner.
	if ( 0 === calls.length && ! isRunningTool ) {
		return <Spinner />;
	}

	const hiddenCount = showAll ? 0 : Math.max( 0, calls.length - VISIBLE_CALLS );
	const visibleCalls = calls.slice( hiddenCount );

	return (
		<div className="agent-mod-chat__progress">
			<button
				type="button"
				className="agent-mod-chat__progress-header"
				onClick={ () => setIsOpen( ! isOpen ) }
				aria-expanded={ isOpen }
			>
				<Spinner />
				<span className="agent-mod-chat__progress-status">
					{ isRunningTool
						? sprintf(
								/* translators: %s: tool name(s) being executed. */
								__( 'Running tool: %s…', 'agent-mod' ),
								progress.currentTool
						  )
						: __( 'Working…', 'agent-mod' ) }
				</span>
				<span className="agent-mod-chat__progress-count">
					{ sprintf(
						/* translators: %d: number of completed tool calls. */
						_n( '%d tool', '%d tools', doneCount, 'agent-mod' ),
						doneCount
					) }
				</span>
				<span
					className={
						'dashicons agent-mod-chat__progress-chevron ' +
						( isOpen ? 'dashicons-arrow-up-alt2' : 'dashicons-arrow-down-alt2' )
					}
					aria-hidden="true"
				/>
			</button>

			{ isOpen && 0 < calls.length && (
				<div className="agent-mod-chat__progress-body">
					{ 0 < hiddenCount && (
						<button
							type="button"
							className="agent-mod-chat__progress-more"
							onClick={ () => setShowAll( true ) }
						>
							{ sprintf(
								/* translators: %d: number of hidden earlier tool calls. */
								_n( 'Show %d earlier call', 'Show %d earlier calls', hiddenCount, 'agent-mod' ),
								hiddenCount
							) }
						</button>
					) }

					<ul className="agent-mod-chat__progress-calls">
						{ visibleCalls.map( ( call, index ) => {
							const isRunning = 'running' === call.status;
							const args = formatArgs( call.args );

							return (
								<li
									key={ hiddenCount + index }
									className={ isRunning ? 'is-running' : 'is-done' }
								>
									<span
										className={
											isRunning
												? 'dashicons dashicons-update agent-mod-spin'
												: 'dashicons dashicons-yes-alt'
										}
										aria-hidden="true"
									/>
									<span className="agent-mod-chat__progress-call">
										<code className="agent-mod-chat__progress-name">
											{ call.name }
										</code>
										{ args && (
											<span
												className="agent-mod-chat__progress-args"
												title={ args }
											>
												{ args }
											</span>
										) }
									</span>
								</li>
							);
						} ) }
					</ul>

					{ showAll && calls.length > VISIBLE_CALLS && (
						<button
							type="button"
							className="agent-mod-chat__progress-more"
							onClick={ () => setShowAll( false ) }
						>
							{ __( 'Show less', 'agent-mod' ) }
						</button>
					) }
				</div>
			) }
		</div>
	);
}

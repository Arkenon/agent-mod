/**
 * Renders a message's text through a filterable markdown pipeline.
 *
 * Assistant messages are parsed as GitHub Flavored Markdown; user messages
 * are rendered as plain pre-wrapped text (users don't type markdown).
 *
 * The rendered output can be replaced entirely via the
 * `agent_mod.render_message_content` JS filter, allowing Pro to plug in
 * DataViews tables or any custom renderer for structured responses.
 *
 * Filter signature:
 *   applyFilters( 'agent_mod.render_message_content', defaultElement, message )
 *   - defaultElement: React element
 *   - message: { role, text, attachments, ...rest }
 *
 * @package    AgentMod
 * @subpackage AdminChat/Components
 * @since      1.0.0
 */
import ReactMarkdown from 'react-markdown';
import remarkGfm from 'remark-gfm';
import { applyFilters } from '@wordpress/hooks';
import CodeBlock from './CodeBlock';

export default function MessageContent( { message } ) {
	if ( 'assistant' !== message.role ) {
		return (
			<div className="agent-mod-chat__bubble agent-mod-chat__bubble--user">
				{ message.text }
			</div>
		);
	}

	const defaultContent = (
		<div className="agent-mod-chat__bubble agent-mod-chat__bubble--markdown">
			<ReactMarkdown
				remarkPlugins={ [ remarkGfm ] }
				components={ {
					// Strip the <pre> wrapper; CodeBlock renders its own container.
					pre: ( { children } ) => <>{ children }</>,
					code: CodeBlock,
				} }
			>
				{ message.text || '' }
			</ReactMarkdown>
		</div>
	);

	return applyFilters( 'agent_mod.render_message_content', defaultContent, message );
}

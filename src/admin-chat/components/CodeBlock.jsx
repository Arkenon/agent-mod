/**
 * Custom code block renderer for react-markdown.
 *
 * Inline code renders as a plain <code> element with the `agent-mod-inline-code`
 * class. Fenced code blocks get syntax highlighting via react-syntax-highlighter
 * (PrismLight + Dracula theme) and a "Copy code" button with an execCommand
 * fallback for non-HTTPS (HTTP dev) environments.
 *
 * @package    AgentMod
 * @subpackage AdminChat/Components
 * @since      1.0.0
 */
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { PrismLight as SyntaxHighlighter } from 'react-syntax-highlighter';
// Direct file import — more reliable than the barrel export across bundlers.
import dracula from 'react-syntax-highlighter/dist/esm/styles/prism/dracula';
import php from 'react-syntax-highlighter/dist/esm/languages/prism/php';
import javascript from 'react-syntax-highlighter/dist/esm/languages/prism/javascript';
import jsx from 'react-syntax-highlighter/dist/esm/languages/prism/jsx';
import markup from 'react-syntax-highlighter/dist/esm/languages/prism/markup';
import css from 'react-syntax-highlighter/dist/esm/languages/prism/css';
import bash from 'react-syntax-highlighter/dist/esm/languages/prism/bash';
import json from 'react-syntax-highlighter/dist/esm/languages/prism/json';
import sql from 'react-syntax-highlighter/dist/esm/languages/prism/sql';
import typescript from 'react-syntax-highlighter/dist/esm/languages/prism/typescript';

SyntaxHighlighter.registerLanguage( 'php', php );
SyntaxHighlighter.registerLanguage( 'javascript', javascript );
SyntaxHighlighter.registerLanguage( 'js', javascript );
SyntaxHighlighter.registerLanguage( 'jsx', jsx );
SyntaxHighlighter.registerLanguage( 'html', markup );
SyntaxHighlighter.registerLanguage( 'xml', markup );
SyntaxHighlighter.registerLanguage( 'css', css );
SyntaxHighlighter.registerLanguage( 'bash', bash );
SyntaxHighlighter.registerLanguage( 'shell', bash );
SyntaxHighlighter.registerLanguage( 'sh', bash );
SyntaxHighlighter.registerLanguage( 'json', json );
SyntaxHighlighter.registerLanguage( 'sql', sql );
SyntaxHighlighter.registerLanguage( 'typescript', typescript );
SyntaxHighlighter.registerLanguage( 'ts', typescript );

const COPY_RESET_MS = 1500;

/**
 * Fallback copy using execCommand — works on HTTP (non-secure) origins where
 * navigator.clipboard is unavailable (e.g., Laragon local dev domains).
 *
 * @param {string}   text
 * @param {Function} onSuccess
 */
function execCommandCopy( text, onSuccess ) {
	const ta = document.createElement( 'textarea' );
	ta.value = text;
	ta.style.cssText = 'position:fixed;opacity:0;top:0;left:0;pointer-events:none;';
	document.body.appendChild( ta );
	ta.focus();
	ta.select();
	try {
		if ( document.execCommand( 'copy' ) ) {
			onSuccess();
		}
	} finally {
		document.body.removeChild( ta );
	}
}

/**
 * @param {Object}  props
 * @param {boolean} props.inline    True for backtick inline code.
 * @param {string}  props.className language-xxx class from react-markdown.
 * @param {*}       props.children  Code content.
 */
export default function CodeBlock( { inline, className, children } ) {
	const [ copied, setCopied ] = useState( false );

	// Inline code: styled via agent-mod-inline-code class in SCSS.
	if ( inline ) {
		return (
			<code className={ `agent-mod-inline-code ${ className || '' }`.trim() }>
				{ children }
			</code>
		);
	}

	// Normalise language name: "JavaScript" → "javascript".
	const language =
		( /language-(\w+)/.exec( className || '' ) ?? [] )[ 1 ]?.toLowerCase() ||
		'text';
	const code = String( children ).replace( /\n$/, '' );

	function onSuccess() {
		setCopied( true );
		setTimeout( () => setCopied( false ), COPY_RESET_MS );
	}

	function handleCopy() {
		if ( navigator.clipboard && window.isSecureContext ) {
			navigator.clipboard.writeText( code ).then( onSuccess ).catch( () => {
				execCommandCopy( code, onSuccess );
			} );
		} else {
			// HTTP (non-secure) context: clipboard API is blocked, use fallback.
			execCommandCopy( code, onSuccess );
		}
	}

	return (
		<div className="agent-mod-code-block">
			<div className="agent-mod-code-block__header">
				<span className="agent-mod-code-block__lang">{ language }</span>
				<button
					type="button"
					className="agent-mod-code-block__copy"
					onClick={ handleCopy }
				>
					{ copied
						? __( 'Copied!', 'agent-mod' )
						: __( 'Copy code', 'agent-mod' ) }
				</button>
			</div>
			<SyntaxHighlighter
				language={ language }
				style={ dracula }
				customStyle={ { margin: 0, borderRadius: '0 0 6px 6px', fontSize: '13px' } }
				PreTag="div"
			>
				{ code }
			</SyntaxHighlighter>
		</div>
	);
}

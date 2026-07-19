/**
 * Settings panel markdown editor.
 *
 * Enhances the prompt textareas on the AgentMod settings page with a
 * lightweight EasyMDE markdown editor. The NCF settings app is React-rendered
 * and mounts asynchronously (and remounts fields on tab switches), which
 * dictates the whole integration shape:
 *
 * - MIRROR PATTERN (do not change): EasyMDE must NEVER attach to the
 *   React-owned textarea — CodeMirror would inject and move DOM inside
 *   React's subtree and React's next reconciliation crashes with
 *   "Failed to execute 'removeChild' on 'Node'". Instead the React textarea
 *   is hidden and the editor lives on a mirror textarea inside a wrapper
 *   element we own; the wrapper is only ever a *sibling* of React's nodes.
 * - Target textareas carry the ids assigned in SettingsController
 *   ("agent-mod-md-*"); jQuery picks them up and a MutationObserver rescans
 *   whenever the settings app changes the DOM. Mutations occurring inside
 *   our own wrappers are ignored — reacting to CodeMirror's internal
 *   rendering would loop the observer forever and freeze the page.
 * - Editor changes are written to the hidden React textarea through the
 *   native value setter followed by a bubbling "input" event — a plain
 *   `.value` assignment is invisible to React's controlled TextareaControl
 *   (NCF state would never update and the edit would be lost on save).
 * - Toolbar icons use Dashicons via the per-button `icon` markup so EasyMDE's
 *   FontAwesome CDN download stays disabled (no external requests).
 */
import EasyMDE from 'easymde';
import { __ } from '@wordpress/i18n';

import './style.scss';

const TEXTAREA_SELECTOR = 'textarea[id^="agent-mod-md-"]';
const WRAPPER_CLASS = 'agent-mod-mde';
const SOURCE_HIDDEN_CLASS = 'agent-mod-mde-source-hidden';

// Native setter, so the write bypasses React's value tracker and the "input"
// event below is not deduplicated as a no-op.
const nativeValueSetter = Object.getOwnPropertyDescriptor(
	window.HTMLTextAreaElement.prototype,
	'value'
).set;

// Live registry of { source, wrapper, editor } triples; pruned (and the
// orphaned wrapper removed) when React unmounts or replaces a source
// textarea, e.g. on settings tab switches.
const instances = [];

function syncToReact( textarea, value ) {
	nativeValueSetter.call( textarea, value );
	textarea.dispatchEvent( new Event( 'input', { bubbles: true } ) );
}

const dashicon = ( name ) =>
	`<span class="dashicons dashicons-${ name }" aria-hidden="true"></span>`;

const toolbar = () => [
	{
		name: 'bold',
		action: EasyMDE.toggleBold,
		icon: dashicon( 'editor-bold' ),
		title: __( 'Bold', 'agent-mod' ),
	},
	{
		name: 'italic',
		action: EasyMDE.toggleItalic,
		icon: dashicon( 'editor-italic' ),
		title: __( 'Italic', 'agent-mod' ),
	},
	{
		name: 'heading',
		action: EasyMDE.toggleHeadingSmaller,
		icon: dashicon( 'heading' ),
		title: __( 'Heading', 'agent-mod' ),
	},
	'|',
	{
		name: 'quote',
		action: EasyMDE.toggleBlockquote,
		icon: dashicon( 'editor-quote' ),
		title: __( 'Quote', 'agent-mod' ),
	},
	{
		name: 'unordered-list',
		action: EasyMDE.toggleUnorderedList,
		icon: dashicon( 'editor-ul' ),
		title: __( 'Bulleted list', 'agent-mod' ),
	},
	{
		name: 'ordered-list',
		action: EasyMDE.toggleOrderedList,
		icon: dashicon( 'editor-ol' ),
		title: __( 'Numbered list', 'agent-mod' ),
	},
	'|',
	{
		name: 'link',
		action: EasyMDE.drawLink,
		icon: dashicon( 'admin-links' ),
		title: __( 'Link', 'agent-mod' ),
	},
	{
		name: 'code',
		action: EasyMDE.toggleCodeBlock,
		icon: dashicon( 'editor-code' ),
		title: __( 'Code', 'agent-mod' ),
	},
	'|',
	{
		name: 'preview',
		action: EasyMDE.togglePreview,
		icon: dashicon( 'visibility' ),
		title: __( 'Preview', 'agent-mod' ),
		// Preview mode disables every toolbar button except the ones flagged
		// no-disable — without this the preview button locks itself out and
		// edit mode becomes unreachable.
		noDisable: true,
	},
];

/**
 * Builds the editor next to (never inside) the React-owned source textarea.
 *
 * @param {HTMLTextAreaElement} source The React-rendered settings textarea.
 */
function enhance( source ) {
	const wrapper = document.createElement( 'div' );
	wrapper.className = WRAPPER_CLASS;

	const mirror = document.createElement( 'textarea' );
	mirror.value = source.value;
	wrapper.appendChild( mirror );

	// Sibling insertion only: React tolerates unknown siblings, but never
	// unknown children mixed into nodes it reconciles.
	source.insertAdjacentElement( 'afterend', wrapper );
	source.classList.add( SOURCE_HIDDEN_CLASS );

	const editor = new EasyMDE( {
		element: mirror,
		autoDownloadFontAwesome: false,
		spellChecker: false,
		nativeSpellcheck: true,
		status: false,
		minHeight: '120px',
		toolbar: toolbar(),
		initialValue: source.value,
	} );

	editor.codemirror.on( 'change', () =>
		syncToReact( source, editor.value() )
	);

	window.requestAnimationFrame( () => editor.codemirror.refresh() );

	instances.push( { source, wrapper, editor } );
}

/**
 * Drops registry entries whose source textarea left the DOM (React unmounted
 * or replaced it) and removes their orphaned wrappers — React only cleans up
 * its own nodes, so a replaced-in-place textarea would otherwise leave a dead
 * editor behind.
 */
function prune() {
	for ( let i = instances.length - 1; i >= 0; i-- ) {
		if ( ! document.body.contains( instances[ i ].source ) ) {
			instances[ i ].wrapper.remove();
			instances.splice( i, 1 );
		}
	}
}

/**
 * True when every mutation in the batch happened inside one of our editor
 * wrappers (CodeMirror's own rendering). Reacting to those would re-trigger
 * the observer endlessly.
 *
 * @param {MutationRecord[]} records Observed mutation batch.
 * @return {boolean} Whether the batch can be ignored.
 */
function isOwnEditorNoise( records ) {
	return records.every(
		( record ) =>
			record.target instanceof Element &&
			record.target.closest( '.' + WRAPPER_CLASS )
	);
}

( function ( $ ) {
	if ( ! $ ) {
		return;
	}

	const scan = () => {
		$( TEXTAREA_SELECTOR ).each( ( _, node ) => {
			if ( ! node.dataset.agentModMde ) {
				node.dataset.agentModMde = '1';
				enhance( node );
			}
		} );
	};

	$( () => {
		scan();

		const root =
			document.getElementById( 'wpbody-content' ) || document.body;

		// NCF mounts fields after its REST config fetch and remounts them on
		// tab switches; rescan on relevant subtree changes only.
		const observer = new window.MutationObserver( ( records ) => {
			if ( isOwnEditorNoise( records ) ) {
				return;
			}
			prune();
			scan();
		} );

		observer.observe( root, { childList: true, subtree: true } );

		// If NCF hides/shows panels via CSS instead of remounting, CodeMirror
		// may have rendered while hidden and shows blank until repainted.
		// Click-driven (cannot loop), one frame after the UI settled.
		document.addEventListener( 'click', () => {
			window.requestAnimationFrame( () => {
				instances.forEach( ( { editor } ) =>
					editor.codemirror.refresh()
				);
			} );
		} );
	} );
} )( window.jQuery );

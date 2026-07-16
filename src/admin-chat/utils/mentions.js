/**
 * Shared "@namespace/ability-name" mention helpers.
 *
 * Used both when parsing a sent message for abilities to emphasize
 * (store/actions.js) and when detecting/highlighting an in-progress mention
 * while typing (components/Composer.jsx).
 */

/**
 * Matches a complete "@namespace/ability-name" mention, capturing the name
 * (without the leading "@").
 */
export const MENTION_PATTERN = /@([a-z0-9][a-z0-9-]*\/[a-z0-9][a-z0-9-]*)/g;

/**
 * Matches an in-progress "@" mention immediately before the caret (e.g.
 * typing "@core/get-s" captures "core/get-s"), so it can be turned into a
 * live ability-tray filter.
 */
const MENTION_TRIGGER_PATTERN = /(?:^|\s)@([a-z0-9-]*(?:\/[a-z0-9-]*)?)$/i;

/**
 * Extracts namespaced ability names mentioned as "@namespace/ability" in a
 * message. Names are deduped; validity is enforced server-side.
 *
 * @param {string} text The message text.
 * @return {string[]} Mentioned ability names.
 */
export function parseAbilityMentions( text ) {
	const matches = ( text || '' ).matchAll( MENTION_PATTERN );

	return [ ...new Set( [ ...matches ].map( ( match ) => match[ 1 ] ) ) ];
}

/**
 * Finds an in-progress "@mention" ending exactly at `caretPosition` in
 * `text`, if any — used to open/filter the ability tray as the user types.
 *
 * @param {string} text          Full textarea value.
 * @param {number} caretPosition Current caret offset into `text`.
 * @return {{start: number, query: string}|null} The offset of the "@" and
 *   the partial query typed after it, or null if the caret isn't inside an
 *   in-progress mention.
 */
export function findMentionTrigger( text, caretPosition ) {
	const upToCaret = ( text || '' ).slice( 0, caretPosition );
	const match = MENTION_TRIGGER_PATTERN.exec( upToCaret );

	if ( ! match ) {
		return null;
	}

	// match[0] is either "@…" (start of string) or " @…" (preceded by
	// whitespace) — offset by 1 in the latter case to land exactly on "@".
	const atIndex = match.index + ( '@' === match[ 0 ][ 0 ] ? 0 : 1 );

	return { start: atIndex, query: match[ 1 ] };
}

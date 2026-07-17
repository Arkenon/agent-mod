/**
 * Shared mention helpers for "@namespace/ability-name" and "#skill-slug"
 * tokens.
 *
 * Used both when parsing a sent message for abilities/skills to emphasize
 * (store/actions.js, extensions via the send-message payload filter) and when
 * detecting/highlighting an in-progress mention while typing
 * (components/Composer.jsx).
 */

/**
 * Matches a complete "@namespace/ability-name" mention, capturing the name
 * (without the leading "@").
 */
export const MENTION_PATTERN = /@([a-z0-9][a-z0-9-]*\/[a-z0-9][a-z0-9-]*)/g;

/**
 * Matches a complete "#skill-slug" mention, capturing the slug (without the
 * leading "#"). Skills are provided by extensions (e.g. Pro); the free plugin
 * only supplies the shared grammar.
 */
export const SKILL_MENTION_PATTERN = /#([a-z0-9][a-z0-9-]*)/g;

/**
 * Matches any complete mention token — ability or skill — capturing the token
 * INCLUDING its trigger character, so `String.split()` keeps alternating
 * plain-text/mention parts for the highlight overlay.
 */
export const TOKEN_PATTERN =
	/(@[a-z0-9][a-z0-9-]*\/[a-z0-9][a-z0-9-]*|#[a-z0-9][a-z0-9-]*)/g;

/**
 * Matches an in-progress "@" mention immediately before the caret (e.g.
 * typing "@core/get-s" captures "core/get-s"), so it can be turned into a
 * live ability-tray filter.
 */
const MENTION_TRIGGER_PATTERN = /(?:^|\s)@([a-z0-9-]*(?:\/[a-z0-9-]*)?)$/i;

/**
 * Matches an in-progress "#" mention immediately before the caret, driving a
 * live skill-tray filter the same way MENTION_TRIGGER_PATTERN does for "@".
 */
const SKILL_TRIGGER_PATTERN = /(?:^|\s)#([a-z0-9-]*)$/i;

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
 * Extracts skill slugs mentioned as "#skill-slug" in a message. Slugs are
 * deduped; validity is enforced server-side against the selected agent's
 * attached skills.
 *
 * @param {string} text The message text.
 * @return {string[]} Mentioned skill slugs.
 */
export function parseSkillMentions( text ) {
	const matches = ( text || '' ).matchAll( SKILL_MENTION_PATTERN );

	return [ ...new Set( [ ...matches ].map( ( match ) => match[ 1 ] ) ) ];
}

/**
 * Finds an in-progress "@" or "#" mention ending exactly at `caretPosition`
 * in `text`, if any — used to open/filter the matching tray as the user
 * types.
 *
 * @param {string} text          Full textarea value.
 * @param {number} caretPosition Current caret offset into `text`.
 * @return {{start: number, query: string, trigger: string}|null} The offset
 *   of the trigger character, the partial query typed after it, and the
 *   trigger itself ('@' or '#'), or null if the caret isn't inside an
 *   in-progress mention.
 */
export function findMentionTrigger( text, caretPosition ) {
	const upToCaret = ( text || '' ).slice( 0, caretPosition );

	for ( const [ trigger, pattern ] of [
		[ '@', MENTION_TRIGGER_PATTERN ],
		[ '#', SKILL_TRIGGER_PATTERN ],
	] ) {
		const match = pattern.exec( upToCaret );

		if ( ! match ) {
			continue;
		}

		// match[0] starts with the trigger char (start of string) or a
		// whitespace character — offset by 1 in the latter case to land
		// exactly on the trigger.
		const start = match.index + ( trigger === match[ 0 ][ 0 ] ? 0 : 1 );

		return { start, query: match[ 1 ], trigger };
	}

	return null;
}

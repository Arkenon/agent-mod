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
 * True when the character before offset `index` allows a mention to start
 * there — i.e. the trigger char is at the start of the text or right after
 * whitespace. Mirrors the boundary the live trigger patterns already require.
 */
const hasLeadingBoundary = ( text, index ) =>
	0 === index || /\s/.test( text[ index - 1 ] );

/**
 * True when the character at offset `index` (the one right after a token)
 * doesn't extend the token — anything outside the slug/namespace alphabet
 * counts as a boundary, so trailing punctuation ("#my-skill.") is fine but
 * partial matches ("#my-skillX", "@ns/name/extra") are not.
 */
const hasTrailingBoundary = ( text, index ) =>
	index >= text.length || ! /[A-Za-z0-9_/-]/.test( text[ index ] );

/**
 * Core mention tokenizer. A grammar-shaped token only counts as a mention
 * when it is properly delimited (whitespace/start before, non-slug char or
 * end after) AND — when the matching known list is provided — its value is a
 * member of that list. This is what keeps ordinary prose like a "#000000"
 * color code from being treated as a skill mention.
 *
 * @param {string} text                   The text to scan.
 * @param {Object} [options]              Known values per mention type.
 * @param {?string[]} options.abilityNames Known "@namespace/name" ability
 *   names, [] for "none valid", or null to skip the membership check.
 * @param {?string[]} options.skillSlugs   Known "#slug" skill slugs, [] for
 *   "none valid", or null to skip the membership check.
 * @return {Array<{start: number, end: number, token: string, type: string, value: string}>}
 *   Mention tokens in order of appearance; `token` includes the trigger char,
 *   `value` doesn't, `type` is 'ability' or 'skill'.
 */
export function extractMentionTokens(
	text,
	{ abilityNames = null, skillSlugs = null } = {}
) {
	const source = text || '';
	const known = {
		ability: null === abilityNames ? null : new Set( abilityNames ),
		skill: null === skillSlugs ? null : new Set( skillSlugs ),
	};
	const tokens = [];

	for ( const match of source.matchAll( TOKEN_PATTERN ) ) {
		const token = match[ 0 ];
		const start = match.index;
		const end = start + token.length;

		if (
			! hasLeadingBoundary( source, start ) ||
			! hasTrailingBoundary( source, end )
		) {
			continue;
		}

		const type = '@' === token[ 0 ] ? 'ability' : 'skill';
		const value = token.slice( 1 );

		if ( null !== known[ type ] && ! known[ type ].has( value ) ) {
			continue;
		}

		tokens.push( { start, end, token, type, value } );
	}

	return tokens;
}

/**
 * Splits text into ordered parts for the highlight overlay, marking which
 * parts are validated mentions.
 *
 * @param {string} text      The text to split.
 * @param {Object} [options] Same options as extractMentionTokens().
 * @return {Array<{text: string, isMention: boolean}>} Ordered parts covering
 *   the whole text.
 */
export function splitMentionParts( text, options ) {
	const source = text || '';
	const parts = [];
	let cursor = 0;

	for ( const { start, end, token } of extractMentionTokens( source, options ) ) {
		if ( start > cursor ) {
			parts.push( { text: source.slice( cursor, start ), isMention: false } );
		}
		parts.push( { text: token, isMention: true } );
		cursor = end;
	}

	if ( cursor < source.length ) {
		parts.push( { text: source.slice( cursor ), isMention: false } );
	}

	return parts;
}

/**
 * Resolves the known ability-name list for mention validation, mirroring the
 * AbilityTray's data sourcing: with "Selected Abilities" the list is fully
 * known from settings; with "All Abilities" the site-wide list is only known
 * once it has been lazily loaded (the tray bootstraps it on first open) — if
 * it hasn't loaded yet, return null so validation falls back to grammar +
 * boundaries only (the server re-validates against the registry anyway).
 *
 * @param {string} abilitySource     'all' or 'selected'.
 * @param {Array}  selectedAbilities Ability records from the settings/agent.
 * @param {Array}  allAbilities      Site-wide ability records (may be empty
 *   until lazily loaded).
 * @return {?string[]} Known ability names, or null when unknown.
 */
export function resolveKnownAbilityNames(
	abilitySource,
	selectedAbilities,
	allAbilities
) {
	if ( 'selected' === abilitySource ) {
		return ( selectedAbilities || [] ).map( ( ability ) => ability.name );
	}

	if ( Array.isArray( allAbilities ) && 0 < allAbilities.length ) {
		return allAbilities.map( ( ability ) => ability.name );
	}

	return null;
}

/**
 * Extracts namespaced ability names mentioned as "@namespace/ability" in a
 * message. Names are deduped; when `knownNames` is given only members of it
 * count as mentions (validity is additionally enforced server-side).
 *
 * @param {string}    text         The message text.
 * @param {?string[]} [knownNames] Known ability names, or null to skip the
 *   membership check.
 * @return {string[]} Mentioned ability names.
 */
export function parseAbilityMentions( text, knownNames = null ) {
	const tokens = extractMentionTokens( text, { abilityNames: knownNames } );

	return [
		...new Set(
			tokens
				.filter( ( { type } ) => 'ability' === type )
				.map( ( { value } ) => value )
		),
	];
}

/**
 * Extracts skill slugs mentioned as "#skill-slug" in a message. Slugs are
 * deduped; when `knownSlugs` is given only members of it count as mentions
 * (validity is additionally enforced server-side against the selected
 * agent's attached skills).
 *
 * @param {string}    text         The message text.
 * @param {?string[]} [knownSlugs] Known skill slugs, or null to skip the
 *   membership check.
 * @return {string[]} Mentioned skill slugs.
 */
export function parseSkillMentions( text, knownSlugs = null ) {
	const tokens = extractMentionTokens( text, { skillSlugs: knownSlugs } );

	return [
		...new Set(
			tokens
				.filter( ( { type } ) => 'skill' === type )
				.map( ( { value } ) => value )
		),
	];
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

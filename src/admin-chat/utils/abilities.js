/**
 * Client-side mirror of AbilityResolver's selection rules.
 *
 * Under the "All Abilities" source the tray lists the site-wide abilities from
 * the core abilities REST endpoint, which the server-side
 * AbilityResolver::isSelectable() / isReadonly() never see. These helpers
 * apply the same rules so the tray only offers what the agent can actually
 * call (the server still enforces them on every request).
 */

/**
 * Ability category of AgentMod's system abilities (Constants::SYSTEM_ABILITY_CATEGORY).
 *
 * @type {string}
 */
const SYSTEM_CATEGORY = 'agent-mod-system';

/**
 * Name prefixes of proxy / runtime abilities that are never selectable:
 * the MCP Adapter's and the Agents API's.
 *
 * @type {string[]}
 */
const EXCLUDED_PREFIXES = [ 'mcp-adapter/', 'agents/' ];

/**
 * Whether an ability can be offered for selection / mentions.
 *
 * @param {Object} ability Ability record ({ name, category, meta }).
 * @return {boolean} True when selectable.
 */
export function isSelectableAbility( ability ) {
	const name = ability?.name || '';

	if ( '' === name || SYSTEM_CATEGORY === ability?.category ) {
		return false;
	}

	if ( EXCLUDED_PREFIXES.some( ( prefix ) => name.startsWith( prefix ) ) ) {
		return false;
	}

	// A declared `forbidden` action policy never runs.
	return 'forbidden' !== actionPolicy( ability );
}

/**
 * Whether an ability counts as read-only (Ask/Plan modes).
 *
 * Only an explicit `readonly: true` annotation counts, and a declared
 * `preview` / `forbidden` action policy overrides it.
 *
 * @param {Object} ability Ability record.
 * @return {boolean} True when read-only.
 */
export function isReadonlyAbility( ability ) {
	if ( true !== ability?.meta?.annotations?.readonly ) {
		return false;
	}

	return ! [ 'preview', 'forbidden' ].includes( actionPolicy( ability ) );
}

/**
 * The action policy an ability declares, or '' when none.
 *
 * @param {Object} ability Ability record.
 * @return {string} 'direct', 'preview', 'forbidden' or ''.
 */
function actionPolicy( ability ) {
	const raw = ability?.meta?.action_policy ?? ability?.meta?.annotations?.action_policy;
	const policy = 'string' === typeof raw ? raw.trim().toLowerCase() : '';

	return [ 'direct', 'preview', 'forbidden' ].includes( policy ) ? policy : '';
}

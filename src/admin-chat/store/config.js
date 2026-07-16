/**
 * Localized bootstrap config from PHP.
 *
 * Written once via wp_localize_script( 'agent-mod-chat', 'agentModChat', ... )
 * in AIChatWidgetController::enqueueAssets(). This is the only module allowed
 * to touch `window.agentModChat` directly; everything else (reducer, selectors,
 * actions, components) reads settings through the store instead.
 */
const config = window.agentModChat || {};

export default config;

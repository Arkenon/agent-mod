<?php

/**
 * View for the AgentMod admin page (page=agent-mod).
 *
 * Two-pane layout: a left sidebar (reserved for chat history) and a main pane
 * that hosts the conversation. The chat itself is mounted by the admin-chat
 * React bundle into the `data-agent-mod-chat="inline"` container, so no extra
 * JS wiring is needed here — it is plug-and-play.
 *
 * @since 1.0.0
 * @package AgentMod
 */

defined('ABSPATH') || exit;
?>
<div class="wrap agent-mod-agent-page">
	<div class="agent-mod-agent-page__layout">
		<aside class="agent-mod-agent-page__sidebar">
			<h2 class="agent-mod-agent-page__sidebar-title">
				<?php esc_html_e('Conversations', 'agent-mod'); ?>
			</h2>
			<p class="agent-mod-agent-page__sidebar-note">
				<?php esc_html_e('TODO: chat history will be listed here.', 'agent-mod'); ?>
			</p>
		</aside>

		<main class="agent-mod-agent-page__main">
			<div
				class="agent-mod-agent-page__chat"
				data-agent-mod-chat="inline"
			></div>
		</main>
	</div>
</div>

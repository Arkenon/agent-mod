<?php

/**
 * Knowledge resolver.
 *
 * Gathers contextual information about the current WordPress site that can be
 * injected into the system instruction. For now this is basic site metadata;
 * RAG / vector knowledge is left to a later step.
 *
 * @package AgentMod
 * @subpackage Services\AI
 * @since 1.0.0
 */

namespace AgentMod\Services\AI;

defined('ABSPATH') || exit;

class KnowledgeResolver
{
	/**
	 * Returns basic context about the current site.
	 *
	 * @return array<string, string>
	 * @since 1.0.0
	 */
	public function getSiteContext(): array
	{
		return [
			'site_name'        => (string) get_bloginfo('name'),
			'site_description' => (string) get_bloginfo('description'),
			'site_url'         => (string) home_url(),
			'admin_email'      => (string) get_bloginfo('admin_email'),
			'wp_version'       => (string) get_bloginfo('version'),
			'language'         => (string) get_bloginfo('language'),
		];
	}
}

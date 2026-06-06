<?php

/**
 * Additional Plugin Constants
 * (Base constant defined in the main plugin php file)
 * @package AgentMod
 * @subpackage Common
 * @since 1.0.0
 */

namespace AgentMod\Common;

defined('ABSPATH') || exit;

class Constants
{
	public const NAME = 'agent_mod';
	public const INCLUDES_PATH = AGENT_MOD_PATH . 'includes/';
	public const INCLUDES_URL = AGENT_MOD_URL . '/includes/';
	public const AUTHOR = 'Kadim Gültekin';
	public const AUTHOR_URL = 'https://kadimgultekin.com/';
	public const PLUGIN_URL = 'https://kadimgultekin.com/';
	public const EMAIL = 'info@kadimgultekin.com';
	public const PHP_VERSION = '7.4';
	public const WP_VERSION = '6.1';

	/**
	 * Default AI provider id.
	 *
	 * @since 1.0.0
	 */
	public const AI_PROVIDER_DEFAULT = 'gemini';

	/**
	 * REST namespace for the plugin.
	 *
	 * @since 1.0.0
	 */
	public const REST_NAMESPACE = 'agent-mod/v1';

	/**
	 * Default maximum number of tool-calling iterations.
	 *
	 * @since 1.0.0
	 */
	public const AI_MAX_TOOL_CALLS = 10;
}

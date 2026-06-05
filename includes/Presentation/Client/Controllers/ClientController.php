<?php

/**
 * Controller for frontend actions, scripts and styles
 * @package AgentMod
 * @subpackage Presentation\Client\Controllers
 * @since 1.0.0
 */

namespace AgentMod\Presentation\Client\Controllers;

use AgentMod\Common\Constants;

defined('ABSPATH') || exit;

final class ClientController
{
	public function __construct()
	{
		add_action('wp_enqueue_scripts', [$this, 'enqueueScripts']);
		add_action('wp_enqueue_scripts', [$this, 'enqueueStyles']);
	}

	/**
	 * Enqueue scripts for the admin area
	 * @return void
	 * @since 1.0.0
	 */
	public function enqueueScripts(): void
	{
		//Public scripts
		wp_enqueue_script('agent-mod-client', Constants::INCLUDES_URL . '/Presentation/Client/Assets/Js/agent-mod-client.js', array('jquery'), AGENT_MOD_VERSION, true);

		//Localize the script
		wp_localize_script('agent-mod-client', Constants::NAME, [
			'ajax_url' => admin_url('admin-ajax.php'),
			'nonce'    => wp_create_nonce('agent-mod-nonce'),
		]);
	}

	/**
	 * Enqueue styles for the admin area
	 * @return void
	 * @since 1.0.0
	 */
	public function enqueueStyles(): void
	{
		//Public styles
		wp_enqueue_style('agent-mod-client', Constants::INCLUDES_URL . '/Presentation/Client/Assets/Css/agent-mod-client.css', array(), AGENT_MOD_VERSION);
	}
}

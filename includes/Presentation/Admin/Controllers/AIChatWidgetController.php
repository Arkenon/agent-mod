<?php

/**
 * Admin chat widget controller.
 *
 * Adds an admin bar icon that opens the AgentMod assistant chat modal and
 * enqueues the React-based widget assets (built from src/admin-chat). Assets
 * load only in wp-admin for users with the manage_options capability.
 *
 * @package AgentMod
 * @subpackage Presentation\Admin\Controllers
 * @since 1.0.0
 */

namespace AgentMod\Presentation\Admin\Controllers;

use WP_Admin_Bar;
use AgentMod\Common\Constants;
use AgentMod\Services\AI\ProviderInfoService;
use AgentMod\Services\SettingsService;

defined('ABSPATH') || exit;

final class AIChatWidgetController
{
	/**
	 * Script and style handle.
	 *
	 * @var string
	 * @since 1.0.0
	 */
	private const HANDLE = 'agent-mod-chat';

	/**
	 * Admin bar node id.
	 *
	 * @var string
	 * @since 1.0.0
	 */
	private const NODE_ID = 'agent-mod-chat';

	/**
	 * Connected-provider info service.
	 *
	 * @var ProviderInfoService
	 * @since 1.0.0
	 */
	private ProviderInfoService $providerInfo;

	/**
	 * Constructor (PHP-DI autowired). Binds the admin hooks.
	 *
	 * @param ProviderInfoService $providerInfo Connected-provider info service.
	 *
	 * @since 1.0.0
	 */

	/**
	 * Inject SettingsService
	 *
	 * @var SettingsService
	 * @since 1.0.0
	 */
	private SettingsService $settingsService;

	public function __construct(ProviderInfoService $providerInfo, SettingsService $settingsService)
	{
		$this->providerInfo = $providerInfo;
		$this->settingsService = $settingsService;

		add_action('admin_bar_menu', [$this, 'addToolbarNode'], 100);
		add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
		add_action('admin_footer', [$this, 'renderRoot']);
	}

	/**
	 * Adds the chat icon to the admin bar.
	 *
	 * @param WP_Admin_Bar $wp_admin_bar The admin bar instance.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function addToolbarNode(WP_Admin_Bar $wp_admin_bar): void
	{
		if (! is_admin() || ! current_user_can('manage_options')) {
			return;
		}

		// Hide the icon on the AgentMod admin page itself.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only URL parameter check to determine current admin page; no form data is processed.
		$current_page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';

		if ('agent-mod' === $current_page) {
			return;
		}

		$iconUrl = esc_url(AGENT_MOD_URL . 'includes/Presentation/Admin/Assets/img/agent_mod_icon.png');
		$title = '<img src="' . $iconUrl . '" alt="" aria-hidden="true" style="width:20px;height:20px;vertical-align:middle;margin-right:4px;margin-top:-1px;" />'
			. '<span class="ab-label">' . esc_html__('Assistant', 'agent-mod') . '</span>';

		$wp_admin_bar->add_node(
			[
				'id'    => self::NODE_ID,
				'title' => $title,
				'href'  => '#',
				'meta'  => [
					'title' => esc_attr__('Open AgentMod Assistant', 'agent-mod'),
				],
			]
		);
	}

	/**
	 * Enqueues the widget script and styles, plus localized config.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function enqueueAssets(): void
	{
		if (! current_user_can('manage_options')) {
			return;
		}

		$asset_path = AGENT_MOD_PATH . 'build/admin-chat/index.asset.php';

		// Silently bail if the widget has not been built yet.
		if (! is_readable($asset_path)) {
			return;
		}

		$asset = require $asset_path;

		wp_enqueue_script(
			self::HANDLE,
			AGENT_MOD_URL . 'build/admin-chat/index.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		wp_enqueue_style('wp-components');

		wp_enqueue_style(
			self::HANDLE,
			AGENT_MOD_URL . 'build/admin-chat/style-index.css',
			['wp-components'],
			$asset['version']
		);

		wp_localize_script(
			self::HANDLE,
			'agentModChat',
			[
				'restPath'      => Constants::REST_NAMESPACE . '/chat',
				'defaultAgent'  => [
					'provider'      => Constants::AI_PROVIDER_DEFAULT,
					'abilitySource' => 'all',
					'role'          => $this->settingsService->getRole(),
					'goal'          => $this->settingsService->getGoal(),
				],
				'providers'     => $this->providerInfo->getConnectedProviders(),
				'connectorsUrl' => admin_url('options-connectors.php'),
				'attachments'  => [
					'maxBytes'  => $this->settingsService->getAttachmentMaxBytes(),
					'maxCount'  => $this->settingsService->getAttachmentMaxCount(),
					'mimeTypes' => $this->settingsService->getAttachmentMimeTypes(),
				],
				'defaults'     => [
					'siteContextEnabled' => $this->settingsService->isSiteContextEnabled(),
				],
				'strings'      => [
					'title'             => __('AgentMod Assistant', 'agent-mod'),
					'placeholder'       => __('Type your message…', 'agent-mod'),
					'send'              => __('Send', 'agent-mod'),
					'error'             => __('An unexpected error occurred.', 'agent-mod'),
					'attach'            => __('Attach files', 'agent-mod'),
					'removeAttachment'  => __('Remove attachment', 'agent-mod'),
					/* translators: %s: file name. */
					'fileTooLarge'      => __('"%s" is too large.', 'agent-mod'),
					/* translators: %s: file name. */
					'fileTypeNotAllowed' => __('"%s" is not an allowed file type.', 'agent-mod'),
					/* translators: %d: maximum number of files allowed. */
					'tooManyFiles'      => __('You can attach up to %d files.', 'agent-mod'),
				],
			]
		);
	}

	/**
	 * Renders the React root container in the admin footer.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function renderRoot(): void
	{
		if (! current_user_can('manage_options')) {
			return;
		}

		echo '<div id="agent-mod-chat-root"></div>';
	}
}

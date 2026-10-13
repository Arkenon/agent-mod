<?php

/**
 * Admin controller for the first-run setup wizard.
 *
 * Registers a hidden admin page, enqueues the wizard bundle only on it,
 * redirects once after activation and nudges the user from the dashboard
 * until the wizard has been completed or dismissed.
 *
 * @package    AgentMod
 * @subpackage Presentation\Admin\Controllers
 * @since      1.3.0
 */

namespace AgentMod\Presentation\Admin\Controllers;

use Exception;
use AgentMod\Common\Constants;
use AgentMod\Common\Helper;
use AgentMod\Services\SettingsService;

defined('ABSPATH') || exit;

final class SetupController
{
	public const PAGE_SLUG          = 'agent-mod-setup';
	public const COMPLETED_OPTION   = 'agent_mod_setup_completed';
	public const REDIRECT_TRANSIENT = 'agent_mod_do_setup_redirect';
	public const PRO_URL            = 'https://agentmodwp.com/pro-features/';

	private SettingsService $settingsService;

	/**
	 * @since 1.3.0
	 */
	public function __construct(SettingsService $settingsService)
	{
		$this->settingsService = $settingsService;

		add_action('agent_mod_activation', [$this, 'flagRedirect']);
		add_action('admin_init', [$this, 'maybeRedirect']);
		add_action('admin_init', [$this, 'maybeDismiss']);
		add_action('admin_menu', [$this, 'addPage']);
		add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
		add_action('admin_notices', [$this, 'renderNotice']);
	}

	/**
	 * URL of the wizard, optionally opened on a given step.
	 *
	 * @since 1.3.0
	 */
	public static function pageUrl(string $step = ''): string
	{
		$args = ['page' => self::PAGE_SLUG];
		if ('' !== $step) {
			$args['step'] = $step;
		}
		return add_query_arg($args, admin_url('admin.php'));
	}

	/**
	 * Flags a one-shot redirect to the wizard for the next admin request.
	 *
	 * @since 1.3.0
	 */
	public function flagRedirect(): void
	{
		if (! get_option(self::COMPLETED_OPTION)) {
			set_transient(self::REDIRECT_TRANSIENT, 1, 60);
		}
	}

	/**
	 * Redirects to the wizard once after activation.
	 *
	 * @since 1.3.0
	 */
	public function maybeRedirect(): void
	{
		if (! get_transient(self::REDIRECT_TRANSIENT)) {
			return;
		}

		delete_transient(self::REDIRECT_TRANSIENT);

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Core sets activate-multi on bulk activation.
		if (wp_doing_ajax() || is_network_admin() || isset($_GET['activate-multi']) || ! current_user_can('manage_options')) {
			return;
		}

		wp_safe_redirect(self::pageUrl());
		exit;
	}

	/**
	 * Handles the "Dismiss" link of the dashboard notice.
	 *
	 * @since 1.3.0
	 */
	public function maybeDismiss(): void
	{
		if (empty($_GET['agent_mod_dismiss_setup']) || ! current_user_can('manage_options')) {
			return;
		}

		check_admin_referer('agent_mod_dismiss_setup');
		update_option(self::COMPLETED_OPTION, 'skipped');
		wp_safe_redirect(remove_query_arg(['agent_mod_dismiss_setup', '_wpnonce']));
		exit;
	}

	/**
	 * Registers the hidden wizard page.
	 *
	 * @since 1.3.0
	 */
	public function addPage(): void
	{
		$hook = add_submenu_page(
			'',
			esc_html__('AgentMod Setup', 'agent-mod'),
			esc_html__('Setup', 'agent-mod'),
			'manage_options',
			self::PAGE_SLUG,
			[$this, 'renderPage']
		);

		// A page without a parent menu gets no $title from core, which makes
		// admin-header.php call strip_tags(null) (deprecated since PHP 8.1).
		if ($hook) {
			add_action('load-' . $hook, static function (): void {
				global $title;
				$title = __('AgentMod Setup', 'agent-mod');
			});
		}
	}

	/**
	 * Enqueues the wizard bundle. Runs only on the wizard page.
	 *
	 * @since 1.3.0
	 */
	public function enqueueAssets(string $hookSuffix): void
	{
		if ('admin_page_' . self::PAGE_SLUG !== $hookSuffix) {
			return;
		}

		$assetFile = AGENT_MOD_PATH . 'build/setup/index.asset.php';

		if (! is_readable($assetFile)) {
			return;
		}

		$asset = require $assetFile;

		wp_enqueue_script(
			'agent-mod-setup',
			AGENT_MOD_URL . 'build/setup/index.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		wp_enqueue_style('wp-components');

		wp_enqueue_style(
			'agent-mod-setup',
			AGENT_MOD_URL . 'build/setup/style-index.css',
			['wp-components'],
			$asset['version']
		);

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only step hint.
		$step = isset($_GET['step']) ? sanitize_key(wp_unslash($_GET['step'])) : '';

		wp_localize_script(
			'agent-mod-setup',
			'agentModSetup',
			[
				'restNamespace'    => Constants::REST_NAMESPACE,
				'connectorsUrl'    => admin_url('options-connectors.php'),
				'dashboardUrl'     => admin_url('admin.php?page=agent-mod'),
				'settingsUrl'      => admin_url('admin.php?page=' . $this->settingsService->optionKey),
				'proUrl'           => self::PRO_URL,
				'logoUrl'          => AGENT_MOD_URL . 'includes/Presentation/Admin/Assets/img/agent_mod_logo.jpg',
				'initialStep'      => $step,
				'completed'        => (bool) get_option(self::COMPLETED_OPTION),
				'values'           => [
					'role'               => $this->settingsService->getRole(),
					'goal'               => $this->settingsService->getGoal(),
					'systemPrompt'       => $this->settingsService->getSystemPrompt(),
					'personalityTraits'  => $this->settingsService->getPersonalityTraits(),
					'siteContextEnabled' => $this->settingsService->isSiteContextEnabled(),
					'webSearchEnabled'   => $this->settingsService->isWebSearchEnabled(),
					'presetPrompts'      => $this->settingsService->getPresetPrompts(),
					'defaultModel'       => $this->currentDefaultModel(),
				],
				'traitSuggestions' => array_map('trim', explode(',', Helper::personalityTraitOptions())),
			]
		);
	}

	/**
	 * The saved "<provider>:<model>" value ('' = Auto).
	 *
	 * @since 1.3.0
	 */
	private function currentDefaultModel(): string
	{
		$pair = $this->settingsService->getDefaultModel();
		if ('' === $pair['provider']) {
			return '';
		}
		return $pair['provider'] . ':' . (string) $pair['model'];
	}

	/**
	 * Dashboard reminder until the wizard has been completed or dismissed.
	 *
	 * @since 1.3.0
	 */
	public function renderNotice(): void
	{
		$screen = function_exists('get_current_screen') ? get_current_screen() : null;

		if (! $screen || 'toplevel_page_agent-mod' !== $screen->id || get_option(self::COMPLETED_OPTION) || ! current_user_can('manage_options')) {
			return;
		}

		$dismiss = wp_nonce_url(add_query_arg('agent_mod_dismiss_setup', 1), 'agent_mod_dismiss_setup');
?>
		<div class="notice notice-info">
			<p>
				<strong><?php esc_html_e('Finish setting up AgentMod', 'agent-mod'); ?></strong> &mdash;
				<?php esc_html_e('connect an AI provider and pick your basic settings in about two minutes.', 'agent-mod'); ?>
				<a class="button button-primary" style="margin-left:8px" href="<?php echo esc_url(self::pageUrl()); ?>"><?php esc_html_e('Start setup', 'agent-mod'); ?></a>
				<a href="<?php echo esc_url($dismiss); ?>" style="margin-left:8px"><?php esc_html_e('Dismiss', 'agent-mod'); ?></a>
			</p>
		</div>
<?php
	}

	/**
	 * Renders the PHP wrapper template.
	 *
	 * @since 1.3.0
	 */
	public function renderPage(): void
	{
		ob_start();
		try {
			include Constants::INCLUDES_PATH . 'Presentation/Admin/Views/setup-content.php';
			echo ob_get_clean(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Trusted internal admin view template.
		} catch (Exception $e) {
			ob_end_clean();
		}
	}
}

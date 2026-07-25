<?php

/**
 * Upgrader context.
 *
 * Prepares the WordPress admin environment required by the plugin/theme
 * installer classes. Ability execute callbacks run inside REST requests where
 * `wp-admin/includes/` is not loaded, so every file-modifying operation must
 * bootstrap those includes and the filesystem abstraction first.
 *
 * This is the same code path WP-CLI's `plugin`/`theme` commands take: they are
 * thin wrappers over WP_Upgrader and the wp-admin plugin/theme functions.
 *
 * @package AgentMod
 * @subpackage Services\SiteManagement
 * @since 1.1.0
 */

namespace AgentMod\Services\SiteManagement;

use WP_Ajax_Upgrader_Skin;
use WP_Error;

defined('ABSPATH') || exit;

class UpgraderContext
{
	/**
	 * Whether the wp-admin includes have already been loaded this request.
	 *
	 * @var bool
	 * @since 1.1.0
	 */
	private bool $booted = false;

	/**
	 * Loads the wp-admin includes needed by the upgrader classes.
	 *
	 * `class-wp-upgrader.php` is the aggregate loader: it pulls in every skin
	 * plus Plugin_Upgrader, Theme_Upgrader, Core_Upgrader and
	 * Language_Pack_Upgrader, so it is the only upgrader file to require.
	 *
	 * Note: themes_api() lives in `theme.php`, not `theme-install.php`.
	 *
	 * @return void
	 * @since 1.1.0
	 */
	public function boot(): void
	{
		if ($this->booted) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
		require_once ABSPATH . 'wp-admin/includes/theme.php';
		require_once ABSPATH . 'wp-admin/includes/update.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

		$this->booted = true;
	}

	/**
	 * Loads the wp-admin user management functions.
	 *
	 * wp_delete_user() and get_editable_roles() live in wp-admin/includes/user.php,
	 * which is not loaded during REST requests either.
	 *
	 * @return void
	 * @since 1.1.0
	 */
	public function bootUserAdmin(): void
	{
		require_once ABSPATH . 'wp-admin/includes/user.php';
	}

	/**
	 * Boots the includes and initialises WP_Filesystem.
	 *
	 * Only the 'direct' filesystem method is supported: any other method needs
	 * FTP/SSH credentials, which cannot be collected from inside an ability
	 * call. Failing loudly here is better than silently writing nothing.
	 *
	 * @return true|WP_Error True when the filesystem is usable.
	 * @since 1.1.0
	 */
	public function prepareFilesystem()
	{
		$this->boot();

		$method = get_filesystem_method();

		if ('direct' !== $method) {
			return new WP_Error(
				'agent_mod_filesystem_unavailable',
				sprintf(
					/* translators: %s: detected filesystem method, e.g. "ftpext". */
					__('WordPress cannot write to the filesystem directly (detected method: %s). This site requires FTP or SSH credentials for installs and updates, which cannot be supplied through the assistant. Please perform this operation from the WordPress admin screens.', 'agent-mod'),
					$method ?: 'unknown'
				)
			);
		}

		if (! WP_Filesystem()) {
			return new WP_Error(
				'agent_mod_filesystem_init_failed',
				__('WordPress could not initialise the filesystem API. The operation was not performed.', 'agent-mod')
			);
		}

		return true;
	}

	/**
	 * Returns a quiet upgrader skin.
	 *
	 * WP_Ajax_Upgrader_Skin suppresses all screen output and collects failures
	 * into a WP_Error retrievable via get_errors() — the skin core itself uses
	 * for ajax-context installs.
	 *
	 * @return WP_Ajax_Upgrader_Skin
	 * @since 1.1.0
	 */
	public function skin(): WP_Ajax_Upgrader_Skin
	{
		$this->boot();

		return new WP_Ajax_Upgrader_Skin(['api' => 0]);
	}

	/**
	 * Runs a callback with output buffering enabled.
	 *
	 * Some upgrader code paths echo directly regardless of the skin, which
	 * would corrupt the REST JSON response.
	 *
	 * @param callable $callback Operation to run.
	 *
	 * @return mixed Whatever the callback returns.
	 * @since 1.1.0
	 */
	public function silently(callable $callback)
	{
		ob_start();

		try {
			return $callback();
		} finally {
			ob_end_clean();
		}
	}

	/**
	 * Converts an upgrader outcome into a WP_Error when it failed.
	 *
	 * The upgrader family reports failure inconsistently: a WP_Error return, a
	 * false/null return, or a clean return with errors recorded on the skin.
	 * This normalises all three.
	 *
	 * @param mixed                 $result  Upgrader return value.
	 * @param WP_Ajax_Upgrader_Skin $skin    Skin used for the operation.
	 * @param string                $code    Error code to use for generic failures.
	 * @param string                $message Fallback message for generic failures.
	 *
	 * @return WP_Error|null WP_Error on failure, null on success.
	 * @since 1.1.0
	 */
	public function errorFrom($result, WP_Ajax_Upgrader_Skin $skin, string $code, string $message): ?WP_Error
	{
		if (is_wp_error($result)) {
			return $result;
		}

		$skinErrors = $skin->get_errors();

		if (is_wp_error($skinErrors) && $skinErrors->has_errors()) {
			return $skinErrors;
		}

		if (false === $result || null === $result) {
			return new WP_Error($code, $message);
		}

		return null;
	}
}

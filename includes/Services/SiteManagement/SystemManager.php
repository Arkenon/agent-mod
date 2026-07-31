<?php

/**
 * System manager.
 *
 * Native equivalents of the diagnostic and maintenance parts of WP-CLI:
 * `wp core version`, `wp core check-update`, `wp cron event list`,
 * `wp cache flush` and `wp rewrite flush`.
 *
 * Core updates themselves are deliberately out of scope: a core upgrade that is
 * interrupted by an HTTP timeout can leave the site unbootable.
 *
 * @package AgentMod
 * @subpackage Services\SiteManagement
 * @since 1.1.0
 */

namespace AgentMod\Services\SiteManagement;

use AgentMod\Services\SettingsService;
use WP_Error;

defined('ABSPATH') || exit;

class SystemManager
{
	/**
	 * Upgrader context.
	 *
	 * @var UpgraderContext
	 * @since 1.1.0
	 */
	private UpgraderContext $context;

	/**
	 * Pre-flight guard.
	 *
	 * @var Guard
	 * @since 1.1.0
	 */
	private Guard $guard;

	/**
	 * Settings service, used for result limits.
	 *
	 * @var SettingsService
	 * @since 1.1.0
	 */
	private SettingsService $settings;

	/**
	 * Constructor.
	 *
	 * @param UpgraderContext $context  Upgrader context.
	 * @param Guard           $guard    Pre-flight guard.
	 * @param SettingsService $settings Settings service.
	 *
	 * @since 1.1.0
	 */
	public function __construct(UpgraderContext $context, Guard $guard, SettingsService $settings)
	{
		$this->context  = $context;
		$this->guard    = $guard;
		$this->settings = $settings;
	}

	/**
	 * Reports WordPress, environment and update status.
	 *
	 * Equivalent of `wp core version` plus `wp core check-update`, with the
	 * environment facts that explain why an install or update might be refused.
	 *
	 * @return array<string, mixed>|WP_Error
	 * @since 1.1.0
	 */
	public function getCoreStatus()
	{
		global $wp_version, $wpdb;

		$denied = $this->guard->assertCapability('manage_options');

		if ($denied instanceof WP_Error) {
			return $denied;
		}

		$this->context->boot();

		$theme       = wp_get_theme();
		$coreUpdate  = $this->coreUpdate();
		$fileMethod  = get_filesystem_method();
		$fileModsOff = ! wp_is_file_mod_allowed('capability_update_core');

		return [
			'wordpress'   => [
				'version'          => (string) $wp_version,
				'update_available' => null !== $coreUpdate,
				'new_version'      => $coreUpdate['version'] ?? '',
				'is_multisite'     => is_multisite(),
				'site_url'         => home_url(),
				'locale'           => get_locale(),
			],
			'environment' => [
				'php_version'      => PHP_VERSION,
				'mysql_version'    => $wpdb->db_version(),
				'server_software'  => sanitize_text_field(wp_unslash((string) ($_SERVER['SERVER_SOFTWARE'] ?? ''))),
				'memory_limit'     => (string) ini_get('memory_limit'),
				'max_execution_time' => (string) ini_get('max_execution_time'),
				'debug_mode'       => defined('WP_DEBUG') && WP_DEBUG,
			],
			'active_theme' => [
				'name'           => wp_strip_all_tags((string) $theme->get('Name')),
				'slug'           => $theme->get_stylesheet(),
				'version'        => (string) $theme->get('Version'),
				'is_block_theme' => $theme->is_block_theme(),
			],
			'updates'     => [
				'plugins' => count($this->pending('update_plugins')),
				'themes'  => count($this->pending('update_themes')),
			],
			'file_modifications' => [
				'allowed'           => ! $fileModsOff,
				'filesystem_method' => $fileMethod,
				'note'              => $fileModsOff
					? __('DISALLOW_FILE_MODS is set: plugins, themes and core cannot be installed, updated or deleted on this site.', 'agent-mod')
					: ('direct' === $fileMethod
						? __('WordPress can write to the filesystem directly, so installs and updates will work.', 'agent-mod')
						: __('WordPress needs FTP or SSH credentials to write files, which the assistant cannot supply. Installs and updates must be done from the WordPress admin screens.', 'agent-mod')),
			],
		];
	}

	/**
	 * Lists scheduled cron events. Equivalent of `wp cron event list`.
	 *
	 * @param array<string, mixed> $input Optional 'search' filter.
	 *
	 * @return array<string, mixed>|WP_Error
	 * @since 1.1.0
	 */
	public function getCronEvents(array $input)
	{
		$denied = $this->guard->assertCapability('manage_options');

		if ($denied instanceof WP_Error) {
			return $denied;
		}

		$search    = isset($input['search']) ? strtolower(sanitize_text_field((string) $input['search'])) : '';
		$limit     = $this->settings->getMaxSearchResults();
		$schedules = wp_get_schedules();
		$events    = [];

		foreach ((array) _get_cron_array() as $timestamp => $hooks) {
			foreach ((array) $hooks as $hook => $instances) {
				if ('' !== $search && false === strpos(strtolower((string) $hook), $search)) {
					continue;
				}

				foreach ((array) $instances as $instance) {
					$schedule = (string) ($instance['schedule'] ?? '');

					$events[] = [
						'hook'          => (string) $hook,
						'next_run'      => gmdate('c', (int) $timestamp),
						'next_run_in'   => human_time_diff(time(), (int) $timestamp),
						'is_overdue'    => (int) $timestamp < time(),
						'schedule'      => '' === $schedule ? 'one-time' : $schedule,
						'interval_label' => $schedules[$schedule]['display'] ?? '',
					];
				}
			}
		}

		usort($events, static function (array $a, array $b): int {
			return strcmp($a['next_run'], $b['next_run']);
		});

		$total = count($events);

		return [
			'events'         => array_slice($events, 0, $limit),
			'total'          => $total,
			'truncated'      => $total > $limit,
			'cron_disabled'  => defined('DISABLE_WP_CRON') ? true : false,
			'note'           => defined('DISABLE_WP_CRON')
				? __('DISABLE_WP_CRON is set: these events only run if a real system cron job triggers wp-cron.php.', 'agent-mod')
				: '',
		];
	}

	/**
	 * Clears the object cache and regenerates the rewrite rules.
	 *
	 * Equivalent of `wp cache flush` plus `wp rewrite flush`. Both operations
	 * are reversible and safe to repeat, so this ability does not prompt for
	 * confirmation.
	 *
	 * @param array<string, mixed> $input Optional 'object_cache' and 'rewrite_rules' toggles.
	 *
	 * @return array<string, mixed>|WP_Error
	 * @since 1.1.0
	 */
	public function flushCaches(array $input)
	{
		$denied = $this->guard->assertCapability('manage_options');

		if ($denied instanceof WP_Error) {
			return $denied;
		}

		// Default to doing both when the agent passes no explicit toggles.
		$flushCache   = ! array_key_exists('object_cache', $input) || ! empty($input['object_cache']);
		$flushRewrite = ! array_key_exists('rewrite_rules', $input) || ! empty($input['rewrite_rules']);

		$done = [];

		if ($flushCache) {
			wp_cache_flush();
			$done[] = __('object cache', 'agent-mod');
		}

		if ($flushRewrite) {
			flush_rewrite_rules(true);
			$done[] = __('rewrite rules', 'agent-mod');
		}

		if (empty($done)) {
			return new WP_Error(
				'agent_mod_nothing_to_flush',
				__('Nothing was selected to flush.', 'agent-mod')
			);
		}

		return [
			'success'       => true,
			'object_cache'  => $flushCache,
			'rewrite_rules' => $flushRewrite,
			'message'       => sprintf(
				/* translators: %s: comma-separated list of flushed caches. */
				__('Flushed: %s.', 'agent-mod'),
				implode(', ', $done)
			),
		];
	}

	// =========================================================================
	// Internal helpers
	// =========================================================================

	/**
	 * Returns the pending core update, if any.
	 *
	 * @return array<string, mixed>|null
	 * @since 1.1.0
	 */
	private function coreUpdate(): ?array
	{
		$transient = get_site_transient('update_core');

		if (! isset($transient->updates) || ! is_array($transient->updates)) {
			return null;
		}

		foreach ($transient->updates as $update) {
			if ('upgrade' === ($update->response ?? '')) {
				return ['version' => (string) ($update->current ?? '')];
			}
		}

		return null;
	}

	/**
	 * Returns pending updates from an update transient.
	 *
	 * @param string $transient Transient name.
	 *
	 * @return array<string, mixed>
	 * @since 1.1.0
	 */
	private function pending(string $transient): array
	{
		$value = get_site_transient($transient);

		return isset($value->response) && is_array($value->response) ? $value->response : [];
	}
}

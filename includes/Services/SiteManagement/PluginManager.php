<?php

/**
 * Plugin manager.
 *
 * Native equivalents of the `wp plugin` WP-CLI command family, implemented on
 * top of the same core APIs WP-CLI itself delegates to (plugins_api(),
 * Plugin_Upgrader, activate_plugin(), delete_plugins()).
 *
 * @package AgentMod
 * @subpackage Services\SiteManagement
 * @since 1.1.0
 */

namespace AgentMod\Services\SiteManagement;

use AgentMod\Services\SettingsService;
use Plugin_Upgrader;
use WP_Error;

defined('ABSPATH') || exit;

class PluginManager
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

	// =========================================================================
	// Read operations
	// =========================================================================

	/**
	 * Lists installed plugins. Equivalent of `wp plugin list`.
	 *
	 * @param array<string, mixed> $input Optional 'search' and 'status' filters.
	 *
	 * @return array<string, mixed>|WP_Error
	 * @since 1.1.0
	 */
	public function listPlugins(array $input)
	{
		$denied = $this->guard->assertCapability('activate_plugins');

		if ($denied instanceof WP_Error) {
			return $denied;
		}

		$this->context->boot();

		$search  = isset($input['search']) ? strtolower(sanitize_text_field((string) $input['search'])) : '';
		$status  = isset($input['status']) ? sanitize_key((string) $input['status']) : 'all';
		$limit   = $this->settings->getMaxSearchResults();
		$updates = $this->pluginUpdates();

		$plugins = [];

		foreach (get_plugins() as $file => $data) {
			$entry = $this->summarise($file, $data, $updates);

			if ('' !== $search && ! $this->matches($entry, $search)) {
				continue;
			}

			if ('all' !== $status && ! $this->hasStatus($entry, $status)) {
				continue;
			}

			$plugins[] = $entry;
		}

		$total = count($plugins);

		return [
			'plugins'   => array_slice($plugins, 0, $limit),
			'total'     => $total,
			'truncated' => $total > $limit,
		];
	}

	/**
	 * Returns detail for a single plugin. Equivalent of `wp plugin get` + `status`.
	 *
	 * Works for plugins that are not installed too, by falling back to the
	 * WordPress.org directory.
	 *
	 * @param string $slug Plugin slug or plugin file.
	 *
	 * @return array<string, mixed>|WP_Error
	 * @since 1.1.0
	 */
	public function getPlugin(string $slug)
	{
		$denied = $this->guard->assertCapability('activate_plugins');

		if ($denied instanceof WP_Error) {
			return $denied;
		}

		$this->context->boot();

		$slug   = $this->normaliseSlug($slug);
		$file   = $this->findPluginFile($slug);
		$local  = null;

		if (null !== $file) {
			$installed = get_plugins();
			$local     = $this->summarise($file, $installed[$file], $this->pluginUpdates());

			$local['author']       = wp_strip_all_tags((string) ($installed[$file]['Author'] ?? ''));
			$local['plugin_uri']   = (string) ($installed[$file]['PluginURI'] ?? '');
			$local['description']  = wp_strip_all_tags((string) ($installed[$file]['Description'] ?? ''));
			$local['requires_wp']  = (string) ($installed[$file]['RequiresWP'] ?? '');
			$local['requires_php'] = (string) ($installed[$file]['RequiresPHP'] ?? '');
		}

		return [
			'slug'      => $slug,
			'installed' => null !== $local,
			'local'     => $local,
			'directory' => $this->directoryInfo($slug),
		];
	}

	/**
	 * Searches the WordPress.org plugin directory. Equivalent of `wp plugin search`.
	 *
	 * @param array<string, mixed> $input Requires 'search'.
	 *
	 * @return array<string, mixed>|WP_Error
	 * @since 1.1.0
	 */
	public function searchDirectory(array $input)
	{
		$denied = $this->guard->assertCapability('install_plugins');

		if ($denied instanceof WP_Error) {
			return $denied;
		}

		$query = isset($input['search']) ? sanitize_text_field((string) $input['search']) : '';

		if ('' === $query) {
			return new WP_Error('agent_mod_missing_search', __('A search term is required.', 'agent-mod'));
		}

		$this->context->boot();

		$limit = min($this->settings->getMaxSearchResults(), 20);

		$api = plugins_api(
			'query_plugins',
			[
				'search'   => $query,
				'per_page' => $limit,
				'fields'   => [
					'short_description' => true,
					'icons'             => false,
					'banners'           => false,
					'sections'          => false,
					'reviews'           => false,
					'contributors'      => false,
					'compatibility'     => false,
					'ratings'           => false,
					'downloadlink'      => false,
				],
			]
		);

		if (is_wp_error($api)) {
			return $api;
		}

		$installed = array_keys(get_plugins());
		$results   = [];

		foreach ((array) ($api->plugins ?? []) as $plugin) {
			$plugin = (array) $plugin;
			$slug   = (string) ($plugin['slug'] ?? '');

			$results[] = [
				'slug'              => $slug,
				'name'              => wp_strip_all_tags((string) ($plugin['name'] ?? '')),
				'version'           => (string) ($plugin['version'] ?? ''),
				'author'            => wp_strip_all_tags((string) ($plugin['author'] ?? '')),
				'rating'            => (float) ($plugin['rating'] ?? 0),
				'active_installs'   => (int) ($plugin['active_installs'] ?? 0),
				'requires_php'      => (string) ($plugin['requires_php'] ?? ''),
				'tested'            => (string) ($plugin['tested'] ?? ''),
				'short_description' => wp_strip_all_tags((string) ($plugin['short_description'] ?? '')),
				'installed'         => $this->slugIsInstalled($slug, $installed),
			];
		}

		return [
			'query'   => $query,
			'results' => $results,
			'total'   => (int) ($api->info['results'] ?? count($results)),
		];
	}

	// =========================================================================
	// Write operations
	// =========================================================================

	/**
	 * Installs a plugin from the WordPress.org directory.
	 *
	 * Equivalent of `wp plugin install <slug> [--activate]`.
	 *
	 * @param string $slug     Plugin slug.
	 * @param bool   $activate Whether to activate after install.
	 *
	 * @return array<string, mixed>|WP_Error
	 * @since 1.1.0
	 */
	public function install(string $slug, bool $activate)
	{
		$denied = $this->guard->assertCapability('install_plugins');

		if ($denied instanceof WP_Error) {
			return $denied;
		}

		$slug = $this->normaliseSlug($slug);

		if ('' === $slug) {
			return new WP_Error('agent_mod_missing_slug', __('A plugin slug is required.', 'agent-mod'));
		}

		$ready = $this->context->prepareFilesystem();

		if ($ready instanceof WP_Error) {
			return $ready;
		}

		if (null !== $this->findPluginFile($slug)) {
			return new WP_Error(
				'agent_mod_plugin_already_installed',
				sprintf(
					/* translators: %s: plugin slug. */
					__('The "%s" plugin is already installed. Use the manage-plugin ability to activate or update it instead.', 'agent-mod'),
					$slug
				)
			);
		}

		$api = plugins_api('plugin_information', ['slug' => $slug, 'fields' => ['sections' => false]]);

		if (is_wp_error($api)) {
			return $api;
		}

		return $this->installPackage((string) $api->download_link, $slug, $activate);
	}

	/**
	 * Installs a plugin from a ZIP URL or an uploaded media attachment.
	 *
	 * Equivalent of `wp plugin install <zip|url>`.
	 *
	 * @param array<string, mixed> $input Accepts 'url' or 'attachment_id', plus 'activate'.
	 *
	 * @return array<string, mixed>|WP_Error
	 * @since 1.1.0
	 */
	public function upload(array $input)
	{
		$denied = $this->guard->assertCapability('upload_plugins');

		if ($denied instanceof WP_Error) {
			return $denied;
		}

		$package = $this->resolvePackage($input);

		if ($package instanceof WP_Error) {
			return $package;
		}

		$ready = $this->context->prepareFilesystem();

		if ($ready instanceof WP_Error) {
			return $ready;
		}

		return $this->installPackage($package, '', ! empty($input['activate']));
	}

	/**
	 * Activates, deactivates or updates an installed plugin.
	 *
	 * @param string $slug   Plugin slug or plugin file.
	 * @param string $action One of 'activate', 'deactivate', 'update'.
	 *
	 * @return array<string, mixed>|WP_Error
	 * @since 1.1.0
	 */
	public function manage(string $slug, string $action)
	{
		$this->context->boot();

		$file = $this->findPluginFile($this->normaliseSlug($slug));

		if (null === $file) {
			return $this->notInstalled($slug);
		}

		switch ($action) {
			case 'activate':
				return $this->activate($file);

			case 'deactivate':
				return $this->deactivate($file);

			case 'update':
				return $this->update($file);
		}

		return new WP_Error(
			'agent_mod_invalid_action',
			__('Unknown action. Use one of: activate, deactivate, update.', 'agent-mod')
		);
	}

	/**
	 * Deactivates (if needed) and deletes a plugin.
	 *
	 * Equivalent of `wp plugin delete`. The plugin's uninstall routine runs,
	 * which usually removes its database options too.
	 *
	 * @param string $slug Plugin slug or plugin file.
	 *
	 * @return array<string, mixed>|WP_Error
	 * @since 1.1.0
	 */
	public function remove(string $slug)
	{
		$denied = $this->guard->assertCapability('delete_plugins');

		if ($denied instanceof WP_Error) {
			return $denied;
		}

		$this->context->boot();

		$file = $this->findPluginFile($this->normaliseSlug($slug));

		if (null === $file) {
			return $this->notInstalled($slug);
		}

		$protected = $this->guard->assertPluginNotProtected($file);

		if ($protected instanceof WP_Error) {
			return $protected;
		}

		$ready = $this->context->prepareFilesystem();

		if ($ready instanceof WP_Error) {
			return $ready;
		}

		$name = $this->pluginName($file);

		// delete_plugins() runs the uninstall hook but does not deactivate, so
		// an active plugin must be switched off first — the same order the
		// Plugins screen uses.
		if (is_plugin_active($file)) {
			$this->context->silently(static function () use ($file): void {
				deactivate_plugins([$file], true);
			});
		}

		$result = $this->context->silently(static function () use ($file) {
			return delete_plugins([$file]);
		});

		if (is_wp_error($result)) {
			return $result;
		}

		if (true !== $result) {
			return new WP_Error(
				'agent_mod_plugin_delete_failed',
				sprintf(
					/* translators: %s: plugin name. */
					__('WordPress could not delete the "%s" plugin files.', 'agent-mod'),
					$name
				)
			);
		}

		wp_clean_plugins_cache();

		return [
			'success'     => true,
			'plugin_file' => $file,
			'name'        => $name,
			'message'     => sprintf(
				/* translators: %s: plugin name. */
				__('The "%s" plugin was deactivated and deleted.', 'agent-mod'),
				$name
			),
		];
	}

	// =========================================================================
	// Internal write helpers
	// =========================================================================

	/**
	 * Runs Plugin_Upgrader against a package and optionally activates the result.
	 *
	 * @param string $package  Download URL or local ZIP path.
	 * @param string $slug     Expected slug, when known.
	 * @param bool   $activate Whether to activate after install.
	 *
	 * @return array<string, mixed>|WP_Error
	 * @since 1.1.0
	 */
	private function installPackage(string $package, string $slug, bool $activate)
	{
		$skin     = $this->context->skin();
		$upgrader = new Plugin_Upgrader($skin);

		$result = $this->context->silently(static function () use ($upgrader, $package) {
			return $upgrader->install($package);
		});

		$error = $this->context->errorFrom(
			$result,
			$skin,
			'agent_mod_plugin_install_failed',
			__('WordPress could not install the plugin package.', 'agent-mod')
		);

		if ($error instanceof WP_Error) {
			return $error;
		}

		wp_clean_plugins_cache();

		$file = $upgrader->plugin_info();

		if (! is_string($file) || '' === $file) {
			$file = '' !== $slug ? $this->findPluginFile($slug) : null;
		}

		if (! is_string($file) || '' === $file) {
			return new WP_Error(
				'agent_mod_plugin_file_unknown',
				__('The plugin was installed but WordPress could not determine its main file, so it was not activated.', 'agent-mod')
			);
		}

		$response = [
			'success'     => true,
			'plugin_file' => $file,
			'name'        => $this->pluginName($file),
			'version'     => $this->pluginVersion($file),
			'activated'   => false,
			'message'     => sprintf(
				/* translators: %s: plugin name. */
				__('The "%s" plugin was installed.', 'agent-mod'),
				$this->pluginName($file)
			),
		];

		if (! $activate) {
			return $response;
		}

		$activation = $this->activate($file);

		if ($activation instanceof WP_Error) {
			$response['activated']        = false;
			$response['activation_error'] = $activation->get_error_message();

			return $response;
		}

		$response['activated'] = true;
		$response['message']   = sprintf(
			/* translators: %s: plugin name. */
			__('The "%s" plugin was installed and activated.', 'agent-mod'),
			$response['name']
		);

		return $response;
	}

	/**
	 * Activates an installed plugin.
	 *
	 * Note: activate_plugin() loads the plugin file to test it. A plugin that
	 * fatals on load will terminate this request; WordPress fatal error
	 * protection is the safety net, exactly as on the Plugins screen.
	 *
	 * @param string $file Plugin file.
	 *
	 * @return array<string, mixed>|WP_Error
	 * @since 1.1.0
	 */
	private function activate(string $file)
	{
		$denied = $this->guard->assertCapability('activate_plugins');

		if ($denied instanceof WP_Error) {
			return $denied;
		}

		$name = $this->pluginName($file);

		if (is_plugin_active($file)) {
			return [
				'success'     => true,
				'plugin_file' => $file,
				'name'        => $name,
				'message'     => sprintf(
					/* translators: %s: plugin name. */
					__('The "%s" plugin was already active; nothing changed.', 'agent-mod'),
					$name
				),
			];
		}

		$result = $this->context->silently(static function () use ($file) {
			return activate_plugin($file, '', false, false);
		});

		if (is_wp_error($result)) {
			return $result;
		}

		return [
			'success'     => true,
			'plugin_file' => $file,
			'name'        => $name,
			'message'     => sprintf(
				/* translators: %s: plugin name. */
				__('The "%s" plugin was activated.', 'agent-mod'),
				$name
			),
		];
	}

	/**
	 * Deactivates an active plugin.
	 *
	 * @param string $file Plugin file.
	 *
	 * @return array<string, mixed>|WP_Error
	 * @since 1.1.0
	 */
	private function deactivate(string $file)
	{
		$denied = $this->guard->assertCapability('activate_plugins');

		if ($denied instanceof WP_Error) {
			return $denied;
		}

		$protected = $this->guard->assertPluginNotProtected($file);

		if ($protected instanceof WP_Error) {
			return $protected;
		}

		$name = $this->pluginName($file);

		if (! is_plugin_active($file)) {
			return [
				'success'     => true,
				'plugin_file' => $file,
				'name'        => $name,
				'message'     => sprintf(
					/* translators: %s: plugin name. */
					__('The "%s" plugin was already inactive; nothing changed.', 'agent-mod'),
					$name
				),
			];
		}

		$this->context->silently(static function () use ($file): void {
			deactivate_plugins([$file], false);
		});

		return [
			'success'     => true,
			'plugin_file' => $file,
			'name'        => $name,
			'message'     => sprintf(
				/* translators: %s: plugin name. */
				__('The "%s" plugin was deactivated.', 'agent-mod'),
				$name
			),
		];
	}

	/**
	 * Updates a plugin to its latest available version.
	 *
	 * @param string $file Plugin file.
	 *
	 * @return array<string, mixed>|WP_Error
	 * @since 1.1.0
	 */
	private function update(string $file)
	{
		$denied = $this->guard->assertCapability('update_plugins');

		if ($denied instanceof WP_Error) {
			return $denied;
		}

		$ready = $this->context->prepareFilesystem();

		if ($ready instanceof WP_Error) {
			return $ready;
		}

		$name = $this->pluginName($file);
		$from = $this->pluginVersion($file);

		// Refresh the update transient so a stale cache does not report
		// "up to date" for a plugin that has a pending release.
		wp_update_plugins();

		$updates = $this->pluginUpdates();

		if (! isset($updates[$file])) {
			return [
				'success'     => true,
				'plugin_file' => $file,
				'name'        => $name,
				'version'     => $from,
				'updated'     => false,
				'message'     => sprintf(
					/* translators: 1: plugin name, 2: version number. */
					__('The "%1$s" plugin is already up to date (version %2$s).', 'agent-mod'),
					$name,
					$from
				),
			];
		}

		$skin     = $this->context->skin();
		$upgrader = new Plugin_Upgrader($skin);

		$result = $this->context->silently(static function () use ($upgrader, $file) {
			return $upgrader->bulk_upgrade([$file]);
		});

		$error = $this->context->errorFrom(
			$result,
			$skin,
			'agent_mod_plugin_update_failed',
			sprintf(
				/* translators: %s: plugin name. */
				__('WordPress could not update the "%s" plugin.', 'agent-mod'),
				$name
			)
		);

		if ($error instanceof WP_Error) {
			return $error;
		}

		wp_clean_plugins_cache();

		return [
			'success'      => true,
			'plugin_file'  => $file,
			'name'         => $name,
			'updated'      => true,
			'from_version' => $from,
			'version'      => $this->pluginVersion($file),
			'message'      => sprintf(
				/* translators: 1: plugin name, 2: old version, 3: new version. */
				__('The "%1$s" plugin was updated from version %2$s to %3$s.', 'agent-mod'),
				$name,
				$from,
				$this->pluginVersion($file)
			),
		];
	}

	// =========================================================================
	// Internal helpers
	// =========================================================================

	/**
	 * Resolves an install package from a URL or media attachment.
	 *
	 * @param array<string, mixed> $input Ability input.
	 *
	 * @return string|WP_Error Package URL or local path.
	 * @since 1.1.0
	 */
	private function resolvePackage(array $input)
	{
		$attachmentId = isset($input['attachment_id']) ? (int) $input['attachment_id'] : 0;

		if ($attachmentId > 0) {
			$path = get_attached_file($attachmentId);

			if (! $path || ! file_exists($path)) {
				return new WP_Error(
					'agent_mod_attachment_missing',
					__('That media attachment could not be found on disk.', 'agent-mod')
				);
			}

			if ('zip' !== strtolower((string) pathinfo($path, PATHINFO_EXTENSION))) {
				return new WP_Error(
					'agent_mod_attachment_not_zip',
					__('The attachment is not a .zip archive.', 'agent-mod')
				);
			}

			return $path;
		}

		$url = isset($input['url']) ? trim((string) $input['url']) : '';

		if ('' === $url) {
			return new WP_Error(
				'agent_mod_missing_package',
				__('Provide either a https:// URL to a .zip archive or the ID of a .zip file already in the media library.', 'agent-mod')
			);
		}

		if (0 !== stripos($url, 'https://')) {
			return new WP_Error(
				'agent_mod_insecure_package_url',
				__('Only https:// package URLs are accepted.', 'agent-mod')
			);
		}

		if (! wp_http_validate_url($url)) {
			return new WP_Error(
				'agent_mod_invalid_package_url',
				__('That package URL is not valid or points at a blocked host.', 'agent-mod')
			);
		}

		return $url;
	}

	/**
	 * Builds the compact per-plugin summary used in list responses.
	 *
	 * @param string               $file    Plugin file.
	 * @param array<string, mixed> $data    Plugin header data.
	 * @param array<string, mixed> $updates Pending updates keyed by plugin file.
	 *
	 * @return array<string, mixed>
	 * @since 1.1.0
	 */
	private function summarise(string $file, array $data, array $updates): array
	{
		$update = $updates[$file] ?? null;

		return [
			'slug'             => $this->guard->pluginDirectory($file) ?: basename($file, '.php'),
			'plugin_file'      => $file,
			'name'             => wp_strip_all_tags((string) ($data['Name'] ?? $file)),
			'version'          => (string) ($data['Version'] ?? ''),
			'status'           => $this->statusOf($file),
			'update_available' => null !== $update,
			'new_version'      => null !== $update ? (string) ($update->new_version ?? '') : '',
		];
	}

	/**
	 * Returns the activation status of a plugin.
	 *
	 * @param string $file Plugin file.
	 *
	 * @return string One of 'network-active', 'active', 'inactive'.
	 * @since 1.1.0
	 */
	private function statusOf(string $file): string
	{
		if (is_multisite() && is_plugin_active_for_network($file)) {
			return 'network-active';
		}

		return is_plugin_active($file) ? 'active' : 'inactive';
	}

	/**
	 * Whether a summary entry matches a search term.
	 *
	 * @param array<string, mixed> $entry  Plugin summary.
	 * @param string               $search Lowercased search term.
	 *
	 * @return bool
	 * @since 1.1.0
	 */
	private function matches(array $entry, string $search): bool
	{
		return false !== strpos(strtolower((string) $entry['name']), $search)
			|| false !== strpos(strtolower((string) $entry['slug']), $search);
	}

	/**
	 * Whether a summary entry satisfies a status filter.
	 *
	 * @param array<string, mixed> $entry  Plugin summary.
	 * @param string               $status Requested status.
	 *
	 * @return bool
	 * @since 1.1.0
	 */
	private function hasStatus(array $entry, string $status): bool
	{
		if ('update-available' === $status) {
			return (bool) $entry['update_available'];
		}

		if ('active' === $status) {
			return in_array($entry['status'], ['active', 'network-active'], true);
		}

		return $entry['status'] === $status;
	}

	/**
	 * Returns pending plugin updates keyed by plugin file.
	 *
	 * @return array<string, object>
	 * @since 1.1.0
	 */
	private function pluginUpdates(): array
	{
		$transient = get_site_transient('update_plugins');

		return isset($transient->response) && is_array($transient->response) ? $transient->response : [];
	}

	/**
	 * Fetches WordPress.org directory information for a slug.
	 *
	 * @param string $slug Plugin slug.
	 *
	 * @return array<string, mixed>|null Null when the plugin is not in the directory.
	 * @since 1.1.0
	 */
	private function directoryInfo(string $slug): ?array
	{
		if ('' === $slug) {
			return null;
		}

		$api = plugins_api('plugin_information', ['slug' => $slug, 'fields' => ['sections' => false]]);

		if (is_wp_error($api)) {
			return null;
		}

		return [
			'name'              => wp_strip_all_tags((string) ($api->name ?? '')),
			'version'           => (string) ($api->version ?? ''),
			'author'            => wp_strip_all_tags((string) ($api->author ?? '')),
			'requires'          => (string) ($api->requires ?? ''),
			'requires_php'      => (string) ($api->requires_php ?? ''),
			'tested'            => (string) ($api->tested ?? ''),
			'rating'            => (float) ($api->rating ?? 0),
			'active_installs'   => (int) ($api->active_installs ?? 0),
			'last_updated'      => (string) ($api->last_updated ?? ''),
			'homepage'          => (string) ($api->homepage ?? ''),
			'short_description' => wp_strip_all_tags((string) ($api->short_description ?? '')),
		];
	}

	/**
	 * Normalises user/agent supplied plugin identifiers to a slug.
	 *
	 * Accepts "hello-dolly", "hello-dolly/hello.php" and "Hello Dolly".
	 *
	 * @param string $slug Raw identifier.
	 *
	 * @return string
	 * @since 1.1.0
	 */
	private function normaliseSlug(string $slug): string
	{
		$slug = trim(str_replace('\\', '/', $slug));

		if (false !== strpos($slug, '/')) {
			$slug = strtok($slug, '/');
		}

		if ('.php' === substr($slug, -4)) {
			$slug = substr($slug, 0, -4);
		}

		return sanitize_key($slug);
	}

	/**
	 * Finds the plugin file for a slug among installed plugins.
	 *
	 * @param string $slug Plugin slug.
	 *
	 * @return string|null Plugin file, or null when not installed.
	 * @since 1.1.0
	 */
	private function findPluginFile(string $slug): ?string
	{
		if ('' === $slug) {
			return null;
		}

		$this->context->boot();

		foreach (array_keys(get_plugins()) as $file) {
			$directory = $this->guard->pluginDirectory($file);

			if ('' !== $directory && $directory === $slug) {
				return $file;
			}

			if ('' === $directory && basename($file, '.php') === $slug) {
				return $file;
			}
		}

		return null;
	}

	/**
	 * Whether a directory slug matches any installed plugin file.
	 *
	 * @param string   $slug      Plugin slug.
	 * @param string[] $installed Installed plugin files.
	 *
	 * @return bool
	 * @since 1.1.0
	 */
	private function slugIsInstalled(string $slug, array $installed): bool
	{
		if ('' === $slug) {
			return false;
		}

		foreach ($installed as $file) {
			if ($this->guard->pluginDirectory($file) === $slug || basename($file, '.php') === $slug) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Returns a plugin's display name.
	 *
	 * @param string $file Plugin file.
	 *
	 * @return string
	 * @since 1.1.0
	 */
	private function pluginName(string $file): string
	{
		$plugins = get_plugins();

		return wp_strip_all_tags((string) ($plugins[$file]['Name'] ?? $file));
	}

	/**
	 * Returns a plugin's installed version.
	 *
	 * @param string $file Plugin file.
	 *
	 * @return string
	 * @since 1.1.0
	 */
	private function pluginVersion(string $file): string
	{
		$plugins = get_plugins();

		return (string) ($plugins[$file]['Version'] ?? '');
	}

	/**
	 * Builds the "not installed" error.
	 *
	 * @param string $slug Requested slug.
	 *
	 * @return WP_Error
	 * @since 1.1.0
	 */
	private function notInstalled(string $slug): WP_Error
	{
		return new WP_Error(
			'agent_mod_plugin_not_installed',
			sprintf(
				/* translators: %s: plugin slug. */
				__('No installed plugin matches "%s". Use the get-plugins ability to see what is installed.', 'agent-mod'),
				$slug
			)
		);
	}
}

<?php

/**
 * Theme manager.
 *
 * Native equivalents of the `wp theme` WP-CLI command family, implemented on
 * top of the same core APIs WP-CLI itself delegates to (themes_api(),
 * Theme_Upgrader, switch_theme(), delete_theme()).
 *
 * Themes have no "deactivate" concept: a site always has exactly one active
 * theme, so activation is a switch and the manage action set is smaller than
 * the plugin equivalent.
 *
 * @package AgentMod
 * @subpackage Services\SiteManagement
 * @since 1.1.0
 */

namespace AgentMod\Services\SiteManagement;

use AgentMod\Services\SettingsService;
use Theme_Upgrader;
use WP_Error;
use WP_Theme;

defined('ABSPATH') || exit;

class ThemeManager
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
	 * Lists installed themes. Equivalent of `wp theme list`.
	 *
	 * @param array<string, mixed> $input Optional 'search' and 'status' filters.
	 *
	 * @return array<string, mixed>|WP_Error
	 * @since 1.1.0
	 */
	public function listThemes(array $input)
	{
		$denied = $this->guard->assertCapability('switch_themes');

		if ($denied instanceof WP_Error) {
			return $denied;
		}

		$search  = isset($input['search']) ? strtolower(sanitize_text_field((string) $input['search'])) : '';
		$status  = isset($input['status']) ? sanitize_key((string) $input['status']) : 'all';
		$limit   = $this->settings->getMaxSearchResults();
		$updates = $this->themeUpdates();

		$themes = [];

		foreach (wp_get_themes() as $stylesheet => $theme) {
			$entry = $this->summarise((string) $stylesheet, $theme, $updates);

			if ('' !== $search && ! $this->matches($entry, $search)) {
				continue;
			}

			if ('all' !== $status && ! $this->hasStatus($entry, $status)) {
				continue;
			}

			$themes[] = $entry;
		}

		$total = count($themes);

		return [
			'themes'    => array_slice($themes, 0, $limit),
			'total'     => $total,
			'truncated' => $total > $limit,
		];
	}

	/**
	 * Returns detail for a single theme. Equivalent of `wp theme get` + `status`.
	 *
	 * @param string $slug Theme directory slug.
	 *
	 * @return array<string, mixed>|WP_Error
	 * @since 1.1.0
	 */
	public function getTheme(string $slug)
	{
		$denied = $this->guard->assertCapability('switch_themes');

		if ($denied instanceof WP_Error) {
			return $denied;
		}

		$slug  = $this->normaliseSlug($slug);
		$theme = $this->findTheme($slug);
		$local = null;

		if ($theme instanceof WP_Theme) {
			$local = $this->summarise($slug, $theme, $this->themeUpdates());

			$local['author']       = wp_strip_all_tags((string) $theme->get('Author'));
			$local['theme_uri']    = (string) $theme->get('ThemeURI');
			$local['description']  = wp_strip_all_tags((string) $theme->get('Description'));
			$local['requires_wp']  = (string) $theme->get('RequiresWP');
			$local['requires_php'] = (string) $theme->get('RequiresPHP');
			$local['is_block_theme'] = $theme->is_block_theme();
		}

		return [
			'slug'      => $slug,
			'installed' => null !== $local,
			'local'     => $local,
			'directory' => $this->directoryInfo($slug),
		];
	}

	/**
	 * Searches the WordPress.org theme directory. Equivalent of `wp theme search`.
	 *
	 * @param array<string, mixed> $input Requires 'search'.
	 *
	 * @return array<string, mixed>|WP_Error
	 * @since 1.1.0
	 */
	public function searchDirectory(array $input)
	{
		$denied = $this->guard->assertCapability('install_themes');

		if ($denied instanceof WP_Error) {
			return $denied;
		}

		$query = isset($input['search']) ? sanitize_text_field((string) $input['search']) : '';

		if ('' === $query) {
			return new WP_Error('agent_mod_missing_search', __('A search term is required.', 'agent-mod'));
		}

		$this->context->boot();

		$limit = min($this->settings->getMaxSearchResults(), 20);

		$api = themes_api(
			'query_themes',
			[
				'search'   => $query,
				'per_page' => $limit,
				'fields'   => [
					'description'  => false,
					'sections'     => false,
					'screenshot'   => false,
					'ratings'      => false,
					'downloadlink' => false,
					'tags'         => false,
				],
			]
		);

		if (is_wp_error($api)) {
			return $api;
		}

		$installed = wp_get_themes();
		$results   = [];

		foreach ((array) ($api->themes ?? []) as $theme) {
			$theme = (array) $theme;
			$slug  = (string) ($theme['slug'] ?? '');

			$results[] = [
				'slug'            => $slug,
				'name'            => wp_strip_all_tags((string) ($theme['name'] ?? '')),
				'version'         => (string) ($theme['version'] ?? ''),
				'author'          => wp_strip_all_tags((string) (is_array($theme['author'] ?? null) ? ($theme['author']['display_name'] ?? '') : ($theme['author'] ?? ''))),
				'rating'          => (float) ($theme['rating'] ?? 0),
				'requires_php'    => (string) ($theme['requires_php'] ?? ''),
				'preview_url'     => (string) ($theme['preview_url'] ?? ''),
				'installed'       => isset($installed[$slug]),
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
	 * Installs a theme from the WordPress.org directory.
	 *
	 * Equivalent of `wp theme install <slug> [--activate]`.
	 *
	 * @param string $slug     Theme slug.
	 * @param bool   $activate Whether to activate after install.
	 *
	 * @return array<string, mixed>|WP_Error
	 * @since 1.1.0
	 */
	public function install(string $slug, bool $activate)
	{
		$denied = $this->guard->assertCapability('install_themes');

		if ($denied instanceof WP_Error) {
			return $denied;
		}

		$slug = $this->normaliseSlug($slug);

		if ('' === $slug) {
			return new WP_Error('agent_mod_missing_slug', __('A theme slug is required.', 'agent-mod'));
		}

		$ready = $this->context->prepareFilesystem();

		if ($ready instanceof WP_Error) {
			return $ready;
		}

		if ($this->findTheme($slug) instanceof WP_Theme) {
			return new WP_Error(
				'agent_mod_theme_already_installed',
				sprintf(
					/* translators: %s: theme slug. */
					__('The "%s" theme is already installed. Use the manage-theme ability to activate or update it instead.', 'agent-mod'),
					$slug
				)
			);
		}

		$api = themes_api('theme_information', ['slug' => $slug, 'fields' => ['sections' => false]]);

		if (is_wp_error($api)) {
			return $api;
		}

		return $this->installPackage((string) $api->download_link, $slug, $activate);
	}

	/**
	 * Installs a theme from a ZIP URL or an uploaded media attachment.
	 *
	 * Equivalent of `wp theme install <zip|url>`.
	 *
	 * @param array<string, mixed> $input Accepts 'url' or 'attachment_id', plus 'activate'.
	 *
	 * @return array<string, mixed>|WP_Error
	 * @since 1.1.0
	 */
	public function upload(array $input)
	{
		$denied = $this->guard->assertCapability('upload_themes');

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
	 * Activates or updates an installed theme.
	 *
	 * @param string $slug   Theme directory slug.
	 * @param string $action One of 'activate', 'update'.
	 *
	 * @return array<string, mixed>|WP_Error
	 * @since 1.1.0
	 */
	public function manage(string $slug, string $action)
	{
		$slug  = $this->normaliseSlug($slug);
		$theme = $this->findTheme($slug);

		if (! $theme instanceof WP_Theme) {
			return $this->notInstalled($slug);
		}

		switch ($action) {
			case 'activate':
				return $this->activate($slug, $theme);

			case 'update':
				return $this->update($slug, $theme);
		}

		return new WP_Error(
			'agent_mod_invalid_action',
			__('Unknown action. Use one of: activate, update.', 'agent-mod')
		);
	}

	/**
	 * Permanently deletes an installed theme.
	 *
	 * Equivalent of `wp theme delete`.
	 *
	 * @param string $slug Theme directory slug.
	 *
	 * @return array<string, mixed>|WP_Error
	 * @since 1.1.0
	 */
	public function remove(string $slug)
	{
		$denied = $this->guard->assertCapability('delete_themes');

		if ($denied instanceof WP_Error) {
			return $denied;
		}

		$slug  = $this->normaliseSlug($slug);
		$theme = $this->findTheme($slug);

		if (! $theme instanceof WP_Theme) {
			return $this->notInstalled($slug);
		}

		$protected = $this->guard->assertThemeRemovable($slug);

		if ($protected instanceof WP_Error) {
			return $protected;
		}

		$ready = $this->context->prepareFilesystem();

		if ($ready instanceof WP_Error) {
			return $ready;
		}

		$name = wp_strip_all_tags((string) $theme->get('Name'));

		$result = $this->context->silently(static function () use ($slug) {
			return delete_theme($slug);
		});

		if (is_wp_error($result)) {
			return $result;
		}

		if (true !== $result) {
			return new WP_Error(
				'agent_mod_theme_delete_failed',
				sprintf(
					/* translators: %s: theme name. */
					__('WordPress could not delete the "%s" theme files.', 'agent-mod'),
					$name
				)
			);
		}

		return [
			'success' => true,
			'slug'    => $slug,
			'name'    => $name,
			'message' => sprintf(
				/* translators: %s: theme name. */
				__('The "%s" theme was deleted.', 'agent-mod'),
				$name
			),
		];
	}

	// =========================================================================
	// Internal write helpers
	// =========================================================================

	/**
	 * Runs Theme_Upgrader against a package and optionally activates the result.
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
		$upgrader = new Theme_Upgrader($skin);

		$result = $this->context->silently(static function () use ($upgrader, $package) {
			return $upgrader->install($package);
		});

		$error = $this->context->errorFrom(
			$result,
			$skin,
			'agent_mod_theme_install_failed',
			__('WordPress could not install the theme package.', 'agent-mod')
		);

		if ($error instanceof WP_Error) {
			return $error;
		}

		wp_clean_themes_cache();

		$installedSlug = $upgrader->theme_info();

		if ($installedSlug instanceof WP_Theme) {
			$installedSlug = $installedSlug->get_stylesheet();
		}

		if (! is_string($installedSlug) || '' === $installedSlug) {
			$installedSlug = $slug;
		}

		$theme = $this->findTheme((string) $installedSlug);

		if (! $theme instanceof WP_Theme) {
			return new WP_Error(
				'agent_mod_theme_unknown',
				__('The theme package was installed but WordPress could not identify the resulting theme, so it was not activated.', 'agent-mod')
			);
		}

		$name = wp_strip_all_tags((string) $theme->get('Name'));

		$response = [
			'success'   => true,
			'slug'      => (string) $installedSlug,
			'name'      => $name,
			'version'   => (string) $theme->get('Version'),
			'activated' => false,
			'message'   => sprintf(
				/* translators: %s: theme name. */
				__('The "%s" theme was installed.', 'agent-mod'),
				$name
			),
		];

		if (! $activate) {
			return $response;
		}

		$activation = $this->activate((string) $installedSlug, $theme);

		if ($activation instanceof WP_Error) {
			$response['activation_error'] = $activation->get_error_message();

			return $response;
		}

		$response['activated'] = true;
		$response['message']   = sprintf(
			/* translators: %s: theme name. */
			__('The "%s" theme was installed and activated.', 'agent-mod'),
			$name
		);

		return $response;
	}

	/**
	 * Switches the site to a theme.
	 *
	 * @param string   $slug  Theme directory slug.
	 * @param WP_Theme $theme Theme object.
	 *
	 * @return array<string, mixed>|WP_Error
	 * @since 1.1.0
	 */
	private function activate(string $slug, WP_Theme $theme)
	{
		$denied = $this->guard->assertCapability('switch_themes');

		if ($denied instanceof WP_Error) {
			return $denied;
		}

		$name = wp_strip_all_tags((string) $theme->get('Name'));

		if (! $theme->exists() || $theme->errors()) {
			return new WP_Error(
				'agent_mod_theme_broken',
				sprintf(
					/* translators: 1: theme name, 2: error message. */
					__('The "%1$s" theme cannot be activated: %2$s', 'agent-mod'),
					$name,
					$theme->errors() ? $theme->errors()->get_error_message() : __('the theme files are incomplete.', 'agent-mod')
				)
			);
		}

		$allowed = $this->guard->assertThemeActivatable($slug);

		if ($allowed instanceof WP_Error) {
			return $allowed;
		}

		$previous = wp_get_theme();

		if ($previous->get_stylesheet() === $slug) {
			return [
				'success' => true,
				'slug'    => $slug,
				'name'    => $name,
				'message' => sprintf(
					/* translators: %s: theme name. */
					__('The "%s" theme was already active; nothing changed.', 'agent-mod'),
					$name
				),
			];
		}

		$this->context->silently(static function () use ($slug): void {
			switch_theme($slug);
		});

		return [
			'success'       => true,
			'slug'          => $slug,
			'name'          => $name,
			'previous_theme' => wp_strip_all_tags((string) $previous->get('Name')),
			'message'       => sprintf(
				/* translators: 1: new theme name, 2: previous theme name. */
				__('The site now uses the "%1$s" theme (previously "%2$s").', 'agent-mod'),
				$name,
				wp_strip_all_tags((string) $previous->get('Name'))
			),
		];
	}

	/**
	 * Updates a theme to its latest available version.
	 *
	 * @param string   $slug  Theme directory slug.
	 * @param WP_Theme $theme Theme object.
	 *
	 * @return array<string, mixed>|WP_Error
	 * @since 1.1.0
	 */
	private function update(string $slug, WP_Theme $theme)
	{
		$denied = $this->guard->assertCapability('update_themes');

		if ($denied instanceof WP_Error) {
			return $denied;
		}

		$ready = $this->context->prepareFilesystem();

		if ($ready instanceof WP_Error) {
			return $ready;
		}

		$name = wp_strip_all_tags((string) $theme->get('Name'));
		$from = (string) $theme->get('Version');

		// Refresh the update transient so a stale cache does not report
		// "up to date" for a theme that has a pending release.
		wp_update_themes();

		if (! isset($this->themeUpdates()[$slug])) {
			return [
				'success' => true,
				'slug'    => $slug,
				'name'    => $name,
				'version' => $from,
				'updated' => false,
				'message' => sprintf(
					/* translators: 1: theme name, 2: version number. */
					__('The "%1$s" theme is already up to date (version %2$s).', 'agent-mod'),
					$name,
					$from
				),
			];
		}

		$skin     = $this->context->skin();
		$upgrader = new Theme_Upgrader($skin);

		$result = $this->context->silently(static function () use ($upgrader, $slug) {
			return $upgrader->bulk_upgrade([$slug]);
		});

		$error = $this->context->errorFrom(
			$result,
			$skin,
			'agent_mod_theme_update_failed',
			sprintf(
				/* translators: %s: theme name. */
				__('WordPress could not update the "%s" theme.', 'agent-mod'),
				$name
			)
		);

		if ($error instanceof WP_Error) {
			return $error;
		}

		wp_clean_themes_cache();

		$updated = $this->findTheme($slug);
		$to      = $updated instanceof WP_Theme ? (string) $updated->get('Version') : $from;

		return [
			'success'      => true,
			'slug'         => $slug,
			'name'         => $name,
			'updated'      => true,
			'from_version' => $from,
			'version'      => $to,
			'message'      => sprintf(
				/* translators: 1: theme name, 2: old version, 3: new version. */
				__('The "%1$s" theme was updated from version %2$s to %3$s.', 'agent-mod'),
				$name,
				$from,
				$to
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
	 * Builds the compact per-theme summary used in list responses.
	 *
	 * @param string                            $stylesheet Theme directory slug.
	 * @param WP_Theme                          $theme      Theme object.
	 * @param array<string, array<string, mixed>> $updates  Pending updates keyed by stylesheet.
	 *
	 * @return array<string, mixed>
	 * @since 1.1.0
	 */
	private function summarise(string $stylesheet, WP_Theme $theme, array $updates): array
	{
		$update  = $updates[$stylesheet] ?? null;
		$current = get_stylesheet();
		$parent  = $theme->parent();

		return [
			'slug'             => $stylesheet,
			'name'             => wp_strip_all_tags((string) $theme->get('Name')),
			'version'          => (string) $theme->get('Version'),
			'status'           => $stylesheet === $current ? 'active' : 'inactive',
			'parent'           => $parent instanceof WP_Theme ? $parent->get_stylesheet() : '',
			'update_available' => null !== $update,
			'new_version'      => null !== $update ? (string) ($update['new_version'] ?? '') : '',
		];
	}

	/**
	 * Whether a summary entry matches a search term.
	 *
	 * @param array<string, mixed> $entry  Theme summary.
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
	 * @param array<string, mixed> $entry  Theme summary.
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

		return $entry['status'] === $status;
	}

	/**
	 * Returns pending theme updates keyed by stylesheet.
	 *
	 * Unlike the plugin transient, theme update entries are arrays, not objects.
	 *
	 * @return array<string, array<string, mixed>>
	 * @since 1.1.0
	 */
	private function themeUpdates(): array
	{
		$transient = get_site_transient('update_themes');

		return isset($transient->response) && is_array($transient->response) ? $transient->response : [];
	}

	/**
	 * Fetches WordPress.org directory information for a slug.
	 *
	 * @param string $slug Theme slug.
	 *
	 * @return array<string, mixed>|null Null when the theme is not in the directory.
	 * @since 1.1.0
	 */
	private function directoryInfo(string $slug): ?array
	{
		if ('' === $slug) {
			return null;
		}

		$this->context->boot();

		$api = themes_api('theme_information', ['slug' => $slug, 'fields' => ['sections' => false]]);

		if (is_wp_error($api)) {
			return null;
		}

		$author = $api->author ?? '';

		return [
			'name'         => wp_strip_all_tags((string) ($api->name ?? '')),
			'version'      => (string) ($api->version ?? ''),
			'author'       => wp_strip_all_tags((string) (is_array($author) ? ($author['display_name'] ?? '') : $author)),
			'requires'     => (string) ($api->requires ?? ''),
			'requires_php' => (string) ($api->requires_php ?? ''),
			'rating'       => (float) ($api->rating ?? 0),
			'last_updated' => (string) ($api->last_updated ?? ''),
			'homepage'     => (string) ($api->homepage ?? ''),
			'preview_url'  => (string) ($api->preview_url ?? ''),
		];
	}

	/**
	 * Normalises user/agent supplied theme identifiers to a directory slug.
	 *
	 * Theme directories may contain characters sanitize_key() would strip, so
	 * this only trims and removes path separators.
	 *
	 * @param string $slug Raw identifier.
	 *
	 * @return string
	 * @since 1.1.0
	 */
	private function normaliseSlug(string $slug): string
	{
		$slug = trim(str_replace('\\', '/', $slug));
		$slug = basename($slug);

		return preg_replace('/[^A-Za-z0-9_.\-]/', '', $slug) ?? '';
	}

	/**
	 * Finds an installed theme by directory slug.
	 *
	 * @param string $slug Theme directory slug.
	 *
	 * @return WP_Theme|null Null when not installed.
	 * @since 1.1.0
	 */
	private function findTheme(string $slug): ?WP_Theme
	{
		if ('' === $slug) {
			return null;
		}

		$themes = wp_get_themes();

		if (isset($themes[$slug])) {
			return $themes[$slug];
		}

		// Fall back to a case-insensitive name match so "Twenty Twenty-Four"
		// resolves as readily as "twentytwentyfour".
		foreach ($themes as $stylesheet => $theme) {
			if (0 === strcasecmp((string) $stylesheet, $slug)) {
				return $theme;
			}

			if (0 === strcasecmp(wp_strip_all_tags((string) $theme->get('Name')), $slug)) {
				return $theme;
			}
		}

		return null;
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
			'agent_mod_theme_not_installed',
			sprintf(
				/* translators: %s: theme slug. */
				__('No installed theme matches "%s". Use the get-themes ability to see what is installed.', 'agent-mod'),
				$slug
			)
		);
	}
}

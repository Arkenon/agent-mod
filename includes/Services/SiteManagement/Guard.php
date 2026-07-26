<?php

/**
 * Site management guard.
 *
 * Central pre-flight checks for every site management ability: capabilities,
 * file-modification constraints, self-protection and option denylists.
 *
 * Guards return a WP_Error describing *why* an operation is refused rather than
 * a bare boolean, because that message is handed to the agent verbatim and lets
 * it explain the situation to the user instead of retrying blindly.
 *
 * @package AgentMod
 * @subpackage Services\SiteManagement
 * @since x.x.x
 */

namespace AgentMod\Services\SiteManagement;

use WP_Error;

defined('ABSPATH') || exit;

class Guard
{
	/**
	 * Capabilities routed through core's file-modification mapping.
	 *
	 * For these, map_meta_cap() already denies when DISALLOW_FILE_MODS is set
	 * or when a non-super-admin acts on multisite (wp-includes/capabilities.php).
	 * Knowing that lets us turn a failed check into a precise explanation.
	 *
	 * @var string[]
	 * @since x.x.x
	 */
	private const FILE_MOD_CAPS = [
		'install_plugins',
		'upload_plugins',
		'update_plugins',
		'delete_plugins',
		'install_themes',
		'upload_themes',
		'update_themes',
		'delete_themes',
		'update_core',
	];

	/**
	 * Options that may not be written through an ability.
	 *
	 * These control site addressing, which plugins and themes load, the role
	 * definitions and the scheduler. A wrong value here can make the site
	 * unreachable or unbootable, and none of them has a legitimate reason to be
	 * changed conversationally.
	 *
	 * @var string[]
	 * @since x.x.x
	 */
	private const WRITE_PROTECTED_OPTIONS = [
		'siteurl',
		'home',
		'active_plugins',
		'active_sitewide_plugins',
		'template',
		'stylesheet',
		'current_theme',
		'cron',
		'db_version',
		'initial_db_version',
		'rewrite_rules',
		'recently_activated',
		'admin_email',
		'new_admin_email',
		'users_can_register',
		'default_role',
		'fresh_site',
	];

	/**
	 * Options that may not even be read through an ability.
	 *
	 * Reading is only blocked where the value is a credential or is AgentMod's
	 * own configuration; harmless structural options stay readable so the agent
	 * can answer questions about the site.
	 *
	 * @var string[]
	 * @since x.x.x
	 */
	private const READ_PROTECTED_OPTIONS = [
		// AgentMod's own configuration: without this the agent could rewrite its
		// system prompt or ability allow-list and defeat every other guard.
		'agent_mod_settings',
	];

	/**
	 * Patterns matching options that may not be read or written.
	 *
	 * Targets credentials and transient caches.
	 *
	 * @var string
	 * @since x.x.x
	 */
	private const SECRET_OPTION_PATTERN = '/^_transient_|^_site_transient_|^auth_|^wp_user_roles$|password|secret|_key$|api_key|token|salt|nonce|license/i';

	/**
	 * Checks a capability and explains the denial when it fails.
	 *
	 * @param string $capability Capability to check.
	 *
	 * @return WP_Error|null Null when allowed.
	 * @since x.x.x
	 */
	public function assertCapability(string $capability): ?WP_Error
	{
		if (current_user_can($capability)) {
			return null;
		}

		if (in_array($capability, self::FILE_MOD_CAPS, true)) {
			if (! wp_is_file_mod_allowed('capability_update_core')) {
				return new WP_Error(
					'agent_mod_file_mods_disabled',
					__('This site has file modifications disabled (the DISALLOW_FILE_MODS constant is set), so plugins, themes and core cannot be installed, updated or deleted. This is a server configuration choice and cannot be overridden from here.', 'agent-mod')
				);
			}

			if (is_multisite() && ! is_super_admin()) {
				return new WP_Error(
					'agent_mod_network_admin_required',
					__('On a multisite network, installing, updating and deleting plugins or themes is restricted to network super administrators.', 'agent-mod')
				);
			}
		}

		return new WP_Error(
			'agent_mod_insufficient_permissions',
			sprintf(
				/* translators: %s: WordPress capability name. */
				__('Your user account does not have the "%s" capability required for this operation.', 'agent-mod'),
				$capability
			)
		);
	}

	/**
	 * Refuses operations that would disable or delete AgentMod itself.
	 *
	 * Deactivating or deleting the running plugin mid-request tears down the
	 * code currently executing, leaving the chat session unrecoverable.
	 *
	 * @param string $pluginFile Plugin file, e.g. "agent-mod/agent-mod.php".
	 *
	 * @return WP_Error|null Null when allowed.
	 * @since x.x.x
	 */
	public function assertPluginNotProtected(string $pluginFile): ?WP_Error
	{
		$directory = $this->pluginDirectory($pluginFile);

		if ('' === $directory) {
			return null;
		}

		if (! in_array($directory, $this->protectedPluginDirectories(), true)) {
			return null;
		}

		return new WP_Error(
			'agent_mod_protected_plugin',
			sprintf(
				/* translators: %s: plugin directory slug. */
				__('The "%s" plugin cannot be deactivated or deleted by the assistant, because doing so would shut down the assistant itself mid-request. Please perform this from the WordPress Plugins screen if you really intend it.', 'agent-mod'),
				$directory
			)
		);
	}

	/**
	 * Refuses deletion of themes the site depends on.
	 *
	 * @param string $stylesheet Theme directory slug.
	 *
	 * @return WP_Error|null Null when allowed.
	 * @since x.x.x
	 */
	public function assertThemeRemovable(string $stylesheet): ?WP_Error
	{
		$current = wp_get_theme();

		if ($stylesheet === $current->get_stylesheet()) {
			return new WP_Error(
				'agent_mod_active_theme',
				__('The currently active theme cannot be deleted. Activate a different theme first.', 'agent-mod')
			);
		}

		if ($stylesheet === $current->get_template()) {
			return new WP_Error(
				'agent_mod_parent_theme',
				sprintf(
					/* translators: %s: theme directory slug. */
					__('"%s" is the parent theme of the active child theme and cannot be deleted.', 'agent-mod'),
					$stylesheet
				)
			);
		}

		if (defined('WP_DEFAULT_THEME') && $stylesheet === WP_DEFAULT_THEME) {
			return new WP_Error(
				'agent_mod_default_theme',
				sprintf(
					/* translators: %s: theme directory slug. */
					__('"%s" is the default fallback theme WordPress uses when the active theme breaks, so it cannot be deleted.', 'agent-mod'),
					$stylesheet
				)
			);
		}

		return null;
	}

	/**
	 * Refuses activation of themes not enabled for the current network site.
	 *
	 * @param string $stylesheet Theme directory slug.
	 *
	 * @return WP_Error|null Null when allowed.
	 * @since x.x.x
	 */
	public function assertThemeActivatable(string $stylesheet): ?WP_Error
	{
		if (! is_multisite()) {
			return null;
		}

		$allowed = wp_get_themes(['allowed' => true]);

		if (isset($allowed[$stylesheet])) {
			return null;
		}

		return new WP_Error(
			'agent_mod_theme_not_allowed',
			sprintf(
				/* translators: %s: theme directory slug. */
				__('The "%s" theme is not enabled for this site on the network and cannot be activated.', 'agent-mod'),
				$stylesheet
			)
		);
	}

	/**
	 * Whether an option's value must never be exposed to the agent.
	 *
	 * @param string $option Option name.
	 *
	 * @return bool
	 * @since x.x.x
	 */
	public function isReadProtectedOption(string $option): bool
	{
		$option = strtolower(trim($option));

		if ('' === $option) {
			return true;
		}

		/**
		 * Filters the options whose values the assistant may never read.
		 *
		 * @param string[] $protected Option names.
		 *
		 * @since x.x.x
		 */
		$protected = (array) apply_filters('agent_mod_read_protected_options', self::READ_PROTECTED_OPTIONS);

		if (in_array($option, array_map('strtolower', $protected), true)) {
			return true;
		}

		return 1 === preg_match(self::SECRET_OPTION_PATTERN, $option);
	}

	/**
	 * Whether an option may not be written through an ability.
	 *
	 * Everything that is read-protected is write-protected too.
	 *
	 * @param string $option Option name.
	 *
	 * @return bool
	 * @since x.x.x
	 */
	public function isWriteProtectedOption(string $option): bool
	{
		global $wpdb;

		$option = strtolower(trim($option));

		if ($this->isReadProtectedOption($option)) {
			return true;
		}

		$protected = self::WRITE_PROTECTED_OPTIONS;

		if (isset($wpdb->prefix)) {
			$protected[] = $wpdb->prefix . 'user_roles';
		}

		/**
		 * Filters the options the assistant may never write.
		 *
		 * @param string[] $protected Option names.
		 *
		 * @since x.x.x
		 */
		$protected = (array) apply_filters('agent_mod_write_protected_options', $protected);

		return in_array($option, array_map('strtolower', $protected), true);
	}

	/**
	 * Returns a WP_Error when an option's value may not be read.
	 *
	 * @param string $option Option name.
	 *
	 * @return WP_Error|null Null when allowed.
	 * @since x.x.x
	 */
	public function assertOptionReadable(string $option): ?WP_Error
	{
		if (! $this->isReadProtectedOption($option)) {
			return null;
		}

		return new WP_Error(
			'agent_mod_unreadable_option',
			sprintf(
				/* translators: %s: option name. */
				__('The "%s" option is not readable through the assistant: it holds credentials, a cached transient or the assistant\'s own configuration.', 'agent-mod'),
				$option
			)
		);
	}

	/**
	 * Returns a WP_Error when an option may not be written.
	 *
	 * @param string $option Option name.
	 *
	 * @return WP_Error|null Null when allowed.
	 * @since x.x.x
	 */
	public function assertOptionWritable(string $option): ?WP_Error
	{
		if (! $this->isWriteProtectedOption($option)) {
			return null;
		}

		return new WP_Error(
			'agent_mod_protected_option',
			sprintf(
				/* translators: %s: option name. */
				__('The "%s" option is protected: it controls site addressing, which plugins and themes load, role definitions, credentials or the assistant\'s own configuration, and changing it here could make the site unreachable. Please edit it from the WordPress admin screens if you really intend to.', 'agent-mod'),
				$option
			)
		);
	}

	// =========================================================================
	// User guards
	// =========================================================================

	/**
	 * Checks whether the current user may act on a target user account.
	 *
	 * Beyond the per-user capability check, this refuses self-targeting (an
	 * agent must never be able to lock the operator out or escalate them) and
	 * protects super admins from non-super-admins.
	 *
	 * @param int    $userId    Target user ID.
	 * @param string $operation One of 'edit', 'promote', 'delete'.
	 *
	 * @return WP_Error|null Null when allowed.
	 * @since x.x.x
	 */
	public function assertUserTarget(int $userId, string $operation): ?WP_Error
	{
		if ($userId <= 0 || ! get_userdata($userId)) {
			return new WP_Error(
				'agent_mod_user_not_found',
				__('No user account matches that ID.', 'agent-mod')
			);
		}

		if (get_current_user_id() === $userId) {
			return new WP_Error(
				'agent_mod_self_target',
				__('For safety, the assistant will not change the role of or delete the account you are signed in as. Please do that from the WordPress Users screen.', 'agent-mod')
			);
		}

		$capability = [
			'edit'    => 'edit_user',
			'promote' => 'promote_user',
			'delete'  => 'delete_user',
		][$operation] ?? 'edit_user';

		if (! current_user_can($capability, $userId)) {
			return new WP_Error(
				'agent_mod_insufficient_permissions',
				__('Your user account is not allowed to perform that operation on this user.', 'agent-mod')
			);
		}

		if (is_multisite() && is_super_admin($userId) && ! is_super_admin()) {
			return new WP_Error(
				'agent_mod_super_admin_target',
				__('Only a network super administrator can modify another super administrator.', 'agent-mod')
			);
		}

		return null;
	}

	/**
	 * Checks whether the current user may hand out a given role.
	 *
	 * Restricts assignment to roles WordPress considers editable for the current
	 * user, and gates the administrator role behind actually being one.
	 *
	 * @param string $role Role slug.
	 *
	 * @return WP_Error|null Null when allowed.
	 * @since x.x.x
	 */
	public function assertRoleAssignable(string $role): ?WP_Error
	{
		$role = strtolower(trim($role));

		if ('' === $role) {
			return new WP_Error('agent_mod_missing_role', __('A role is required.', 'agent-mod'));
		}

		if (! current_user_can('promote_users')) {
			return new WP_Error(
				'agent_mod_insufficient_permissions',
				__('Your user account is not allowed to change user roles.', 'agent-mod')
			);
		}

		$editable = function_exists('get_editable_roles') ? get_editable_roles() : wp_roles()->roles;

		if (! isset($editable[$role])) {
			return new WP_Error(
				'agent_mod_role_not_assignable',
				sprintf(
					/* translators: 1: requested role slug, 2: comma-separated list of role slugs. */
					__('The "%1$s" role cannot be assigned. Available roles: %2$s.', 'agent-mod'),
					$role,
					implode(', ', array_keys($editable))
				)
			);
		}

		if ('administrator' === $role && ! current_user_can('administrator') && ! current_user_can('manage_options')) {
			return new WP_Error(
				'agent_mod_admin_role_denied',
				__('Only an administrator can grant the administrator role.', 'agent-mod')
			);
		}

		if ('administrator' === $role && is_multisite() && ! is_super_admin()) {
			return new WP_Error(
				'agent_mod_admin_role_denied',
				__('On a multisite network, only a super administrator can grant the administrator role.', 'agent-mod')
			);
		}

		return null;
	}

	/**
	 * Refuses operations that would leave the site without an administrator.
	 *
	 * WordPress itself does not guard this; a site with no administrator can
	 * only be recovered from the database or WP-CLI.
	 *
	 * @param int $userId Target user ID.
	 *
	 * @return WP_Error|null Null when allowed.
	 * @since x.x.x
	 */
	public function assertNotLastAdministrator(int $userId): ?WP_Error
	{
		$user = get_userdata($userId);

		if (! $user || ! in_array('administrator', (array) $user->roles, true)) {
			return null;
		}

		$administrators = get_users(
			[
				'role'   => 'administrator',
				'fields' => 'ID',
				'number' => 2,
			]
		);

		if (count($administrators) > 1) {
			return null;
		}

		return new WP_Error(
			'agent_mod_last_administrator',
			__('This is the only administrator account on the site. Removing it or changing its role would leave the site with no one able to administer it.', 'agent-mod')
		);
	}

	/**
	 * Returns the plugin directory slug for a plugin file.
	 *
	 * @param string $pluginFile Plugin file, e.g. "agent-mod/agent-mod.php".
	 *
	 * @return string Directory slug, or an empty string for single-file plugins.
	 * @since x.x.x
	 */
	public function pluginDirectory(string $pluginFile): string
	{
		$pluginFile = ltrim(str_replace('\\', '/', $pluginFile), '/');

		if (false === strpos($pluginFile, '/')) {
			return '';
		}

		return strtok($pluginFile, '/');
	}

	/**
	 * Plugin directories that may not be deactivated or deleted.
	 *
	 * @return string[]
	 * @since x.x.x
	 */
	private function protectedPluginDirectories(): array
	{
		$directories = ['agent-mod', 'agent-mod-pro'];

		if (defined('AGENT_MOD_PATH')) {
			$own = basename(rtrim(str_replace('\\', '/', AGENT_MOD_PATH), '/'));

			if ('' !== $own) {
				$directories[] = $own;
			}
		}

		/**
		 * Filters the plugin directories the assistant refuses to deactivate or delete.
		 *
		 * @param string[] $directories Plugin directory slugs.
		 *
		 * @since x.x.x
		 */
		return array_unique((array) apply_filters('agent_mod_protected_plugins', $directories));
	}
}

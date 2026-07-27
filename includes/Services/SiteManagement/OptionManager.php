<?php

/**
 * Option manager.
 *
 * Native equivalent of the `wp option` WP-CLI command family, plus a
 * schema-aware view over WordPress' registered settings.
 *
 * Registered settings (the ones behind Settings → General/Writing/Reading/
 * Discussion, and anything a plugin registers with register_setting()) carry a
 * declared type, description and sanitize callback. Working through that
 * registry rather than raw get_option()/update_option() means the agent can
 * discover what is configurable and values are validated before they land.
 *
 * @package AgentMod
 * @subpackage Services\SiteManagement
 * @since 1.1.0
 */

namespace AgentMod\Services\SiteManagement;

use WP_Error;

defined('ABSPATH') || exit;

class OptionManager
{
	/**
	 * Maximum serialized length of an option value returned to the agent.
	 *
	 * Some plugins store megabytes in a single option; returning that verbatim
	 * would blow the model's context window.
	 *
	 * @var int
	 * @since 1.1.0
	 */
	private const MAX_VALUE_LENGTH = 20000;

	/**
	 * Pre-flight guard.
	 *
	 * @var Guard
	 * @since 1.1.0
	 */
	private Guard $guard;

	/**
	 * Constructor.
	 *
	 * @param Guard $guard Pre-flight guard.
	 *
	 * @since 1.1.0
	 */
	public function __construct(Guard $guard)
	{
		$this->guard = $guard;
	}

	// =========================================================================
	// Registered settings
	// =========================================================================

	/**
	 * Lists WordPress' registered settings with their current values.
	 *
	 * @param array<string, mixed> $input Optional 'group' and 'search' filters.
	 *
	 * @return array<string, mixed>|WP_Error
	 * @since 1.1.0
	 */
	public function getSettings(array $input)
	{
		$denied = $this->guard->assertCapability('manage_options');

		if ($denied instanceof WP_Error) {
			return $denied;
		}

		$group  = isset($input['group']) ? sanitize_key((string) $input['group']) : '';
		$search = isset($input['search']) ? strtolower(sanitize_text_field((string) $input['search'])) : '';

		$settings = [];
		$groups   = [];

		foreach ($this->registeredSettings() as $name => $args) {
			$name         = (string) $name;
			$settingGroup = (string) ($args['group'] ?? '');
			$groups[]     = $settingGroup;

			if ('' !== $group && $settingGroup !== $group) {
				continue;
			}

			if ('' !== $search && false === strpos(strtolower($name . ' ' . (string) ($args['label'] ?? '')), $search)) {
				continue;
			}

			if ($this->guard->isReadProtectedOption($name)) {
				continue;
			}

			$settings[] = [
				'name'        => $name,
				'group'       => $settingGroup,
				'label'       => (string) ($args['label'] ?? ''),
				'description' => (string) ($args['description'] ?? ''),
				'type'        => (string) ($args['type'] ?? 'string'),
				'value'       => $this->truncate(get_option($name, $args['default'] ?? false)),
				'writable'    => ! $this->guard->isWriteProtectedOption($name),
			];
		}

		return [
			'settings' => $settings,
			'groups'   => array_values(array_unique(array_filter($groups))),
			'total'    => count($settings),
			'note'     => __('Settings a plugin registers only on the admin_init hook are not visible here, because abilities run during REST requests. Use get-option for those.', 'agent-mod'),
		];
	}

	/**
	 * Updates a registered setting after validating it against its schema.
	 *
	 * @param string $name  Setting/option name.
	 * @param mixed  $value New value.
	 *
	 * @return array<string, mixed>|WP_Error
	 * @since 1.1.0
	 */
	public function updateSetting(string $name, $value)
	{
		$denied = $this->guard->assertCapability('manage_options');

		if ($denied instanceof WP_Error) {
			return $denied;
		}

		$name = trim($name);

		if ('' === $name) {
			return new WP_Error('agent_mod_missing_setting', __('A setting name is required.', 'agent-mod'));
		}

		$registered = $this->registeredSettings();

		if (! isset($registered[$name])) {
			return new WP_Error(
				'agent_mod_setting_not_registered',
				sprintf(
					/* translators: %s: setting name. */
					__('"%s" is not a registered WordPress setting. Use get-settings to see what is available, or the update-option ability for unregistered options.', 'agent-mod'),
					$name
				)
			);
		}

		$blocked = $this->guard->assertOptionWritable($name);

		if ($blocked instanceof WP_Error) {
			return $blocked;
		}

		$args   = $registered[$name];
		$schema = $this->schemaFor($args);

		$valid = rest_validate_value_from_schema($value, $schema, $name);

		if (is_wp_error($valid)) {
			return $valid;
		}

		$value = rest_sanitize_value_from_schema($value, $schema, $name);

		if (is_wp_error($value)) {
			return $value;
		}

		$previous = get_option($name, $args['default'] ?? false);

		// update_option() fires sanitize_option_{$name}, which is where
		// register_setting() attached the setting's own sanitize callback.
		$changed = update_option($name, $value);

		if (! $changed && $previous === $value) {
			return [
				'success'  => true,
				'name'     => $name,
				'value'    => $this->truncate($value),
				'changed'  => false,
				'message'  => sprintf(
					/* translators: %s: setting name. */
					__('The "%s" setting already had that value; nothing changed.', 'agent-mod'),
					$name
				),
			];
		}

		return [
			'success'        => true,
			'name'           => $name,
			'value'          => $this->truncate(get_option($name)),
			'previous_value' => $this->truncate($previous),
			'changed'        => true,
			'message'        => sprintf(
				/* translators: %s: setting name. */
				__('The "%s" setting was updated.', 'agent-mod'),
				$name
			),
		];
	}

	// =========================================================================
	// Generic options
	// =========================================================================

	/**
	 * Reads a single option. Equivalent of `wp option get`.
	 *
	 * @param string $name Option name.
	 *
	 * @return array<string, mixed>|WP_Error
	 * @since 1.1.0
	 */
	public function getOption(string $name)
	{
		$denied = $this->guard->assertCapability('manage_options');

		if ($denied instanceof WP_Error) {
			return $denied;
		}

		$name = trim($name);

		if ('' === $name) {
			return new WP_Error('agent_mod_missing_option', __('An option name is required.', 'agent-mod'));
		}

		$blocked = $this->guard->assertOptionReadable($name);

		if ($blocked instanceof WP_Error) {
			return $blocked;
		}

		$sentinel = '__agent_mod_missing__';
		$value    = get_option($name, $sentinel);

		if ($sentinel === $value) {
			return [
				'name'   => $name,
				'exists' => false,
				'value'  => null,
			];
		}

		$registered = $this->registeredSettings();

		return [
			'name'       => $name,
			'exists'     => true,
			'value'      => $this->truncate($value),
			'value_type' => gettype($value),
			'registered' => isset($registered[$name]),
			'writable'   => ! $this->guard->isWriteProtectedOption($name),
		];
	}

	/**
	 * Creates or updates an option. Equivalent of `wp option update`.
	 *
	 * @param array<string, mixed> $input Requires 'name' and 'value'; optional 'create'.
	 *
	 * @return array<string, mixed>|WP_Error
	 * @since 1.1.0
	 */
	public function updateOption(array $input)
	{
		$denied = $this->guard->assertCapability('manage_options');

		if ($denied instanceof WP_Error) {
			return $denied;
		}

		$name = isset($input['name']) ? trim((string) $input['name']) : '';

		if ('' === $name) {
			return new WP_Error('agent_mod_missing_option', __('An option name is required.', 'agent-mod'));
		}

		$blocked = $this->guard->assertOptionWritable($name);

		if ($blocked instanceof WP_Error) {
			return $blocked;
		}

		if (! array_key_exists('value', $input)) {
			return new WP_Error('agent_mod_missing_value', __('A value is required.', 'agent-mod'));
		}

		$value    = $input['value'];
		$sentinel = '__agent_mod_missing__';
		$previous = get_option($name, $sentinel);
		$exists   = $sentinel !== $previous;

		if (! $exists && empty($input['create'])) {
			return new WP_Error(
				'agent_mod_option_not_found',
				sprintf(
					/* translators: %s: option name. */
					__('The "%s" option does not exist. Check the spelling, or pass create=true to create it.', 'agent-mod'),
					$name
				)
			);
		}

		if (is_object($value)) {
			return new WP_Error(
				'agent_mod_unsupported_value',
				__('Option values must be a string, number, boolean or array.', 'agent-mod')
			);
		}

		// permalink_structure needs the rewrite API rather than a bare write, so
		// that the rewrite rules are regenerated to match.
		if ('permalink_structure' === $name) {
			return $this->updatePermalinkStructure((string) $value, $exists ? $previous : '');
		}

		update_option($name, $value);

		return [
			'success'        => true,
			'name'           => $name,
			'value'          => $this->truncate(get_option($name)),
			'previous_value' => $exists ? $this->truncate($previous) : null,
			'created'        => ! $exists,
			'message'        => $exists
				? sprintf(
					/* translators: %s: option name. */
					__('The "%s" option was updated.', 'agent-mod'),
					$name
				)
				: sprintf(
					/* translators: %s: option name. */
					__('The "%s" option was created.', 'agent-mod'),
					$name
				),
		];
	}

	// =========================================================================
	// Internal helpers
	// =========================================================================

	/**
	 * Applies a new permalink structure and rebuilds the rewrite rules.
	 *
	 * @param string $structure New permalink structure.
	 * @param mixed  $previous  Previous structure.
	 *
	 * @return array<string, mixed>
	 * @since 1.1.0
	 */
	private function updatePermalinkStructure(string $structure, $previous): array
	{
		global $wp_rewrite;

		$wp_rewrite->set_permalink_structure($structure);
		flush_rewrite_rules(true);

		return [
			'success'        => true,
			'name'           => 'permalink_structure',
			'value'          => get_option('permalink_structure'),
			'previous_value' => $previous,
			'created'        => false,
			'message'        => __('The permalink structure was updated and the rewrite rules were regenerated.', 'agent-mod'),
		];
	}

	/**
	 * Returns the registered settings, registering core's own if needed.
	 *
	 * Core registers its initial settings on rest_api_init only. Abilities
	 * normally run inside a REST request so they are already there, but a
	 * plugin registering on init can leave the registry non-empty while core's
	 * own settings are still missing — so the presence of a known core setting
	 * is the condition to test, not emptiness.
	 *
	 * @return array<string, array<string, mixed>>
	 * @since 1.1.0
	 */
	private function registeredSettings(): array
	{
		$settings = get_registered_settings();

		if (! isset($settings['blogname']) && function_exists('register_initial_settings')) {
			register_initial_settings();
			$settings = get_registered_settings();
		}

		return is_array($settings) ? $settings : [];
	}

	/**
	 * Builds a validation schema for a registered setting.
	 *
	 * @param array<string, mixed> $args register_setting() arguments.
	 *
	 * @return array<string, mixed>
	 * @since 1.1.0
	 */
	private function schemaFor(array $args): array
	{
		$schema = ['type' => (string) ($args['type'] ?? 'string')];

		$showInRest = $args['show_in_rest'] ?? false;

		if (is_array($showInRest) && isset($showInRest['schema']) && is_array($showInRest['schema'])) {
			$schema = array_merge($schema, $showInRest['schema']);
		}

		return $schema;
	}

	/**
	 * Caps the size of a value handed back to the agent.
	 *
	 * @param mixed $value Option value.
	 *
	 * @return mixed Original value, or a truncation notice for oversized values.
	 * @since 1.1.0
	 */
	private function truncate($value)
	{
		if (is_scalar($value) || null === $value) {
			if (is_string($value) && strlen($value) > self::MAX_VALUE_LENGTH) {
				return substr($value, 0, self::MAX_VALUE_LENGTH) . '… [truncated]';
			}

			return $value;
		}

		$encoded = wp_json_encode($value);

		if (is_string($encoded) && strlen($encoded) > self::MAX_VALUE_LENGTH) {
			return sprintf(
				/* translators: %s: size in kilobytes. */
				__('[value omitted: %s KB of structured data, too large to display]', 'agent-mod'),
				number_format_i18n(strlen($encoded) / 1024, 1)
			);
		}

		return $value;
	}
}

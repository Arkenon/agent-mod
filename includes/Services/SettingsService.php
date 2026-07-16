<?php

/**
 * Settings service.
 *
 * Registers the AgentMod settings page via Native Custom Fields and bridges
 * saved values to the plugin's filterable constants.
 *
 * @package AgentMod
 * @subpackage Services
 * @since 1.0.0
 */

namespace AgentMod\Services;

use AgentMod\Common\Constants;

defined('ABSPATH') || exit;

final class SettingsService
{
	/**
	 * WordPress option key where NCF stores all field values for this page.
	 *
	 * @var string
	 * @since 1.0.0
	 */
	public string $optionKey;

	/**
	 * Constructor (PHP-DI autowired). Registers all hooks immediately.
	 *
	 * @since 1.0.0
	 */
	public function __construct(string $optionKey = 'agent_mod_settings')
	{
		$this->optionKey = $optionKey;
	}

	// -------------------------------------------------------------------------
	// Settings Getters — each reads the saved setting and returns it when set,
	// applying backwards-compatible filters.
	// -------------------------------------------------------------------------

	/**
	 * @return int
	 * @since 1.0.0
	 */
	public function getMaxToolCalls(): int
	{
		$saved = (int) ($this->getSettings()['agent_mod_ai_limits']['max_tool_calls'] ?? 0);
		$value = $saved > 0 ? $saved : Constants::AI_MAX_TOOL_CALLS;
		return (int) $value;
	}

	/**
	 * @return int
	 * @since 1.0.0
	 */
	public function getMaxSearchResults(): int
	{
		$saved = (int) ($this->getSettings()['agent_mod_ai_limits']['max_search_results'] ?? 0);
		$value = $saved > 0 ? $saved : Constants::AI_MAX_SEARCH_RESULTS;
		return (int) $value;
	}

	/**
	 * @return int
	 * @since 1.0.0
	 */
	public function getMaxFullContentPosts(): int
	{
		$saved = (int) ($this->getSettings()['agent_mod_ai_limits']['max_full_content_posts'] ?? 0);
		$value = $saved > 0 ? $saved : Constants::AI_MAX_FULL_CONTENT_POSTS;
		return (int) $value;
	}

	/**
	 * @return int
	 * @since 1.0.0
	 */
	public function getAttachmentMaxBytes(): int
	{
		$saved = (int) ($this->getSettings()['agent_mod_attachments']['attachment_max_bytes'] ?? 0);
		$value = $saved > 0 ? $saved : Constants::AI_ATTACHMENT_MAX_BYTES;
		return (int) $value;
	}

	/**
	 * @return int
	 * @since 1.0.0
	 */
	public function getAttachmentMaxCount(): int
	{
		$saved = (int) ($this->getSettings()['agent_mod_attachments']['attachment_max_count'] ?? 0);
		$value = $saved > 0 ? $saved : Constants::AI_ATTACHMENT_MAX_COUNT;
		return (int) $value;
	}

	/**
	 * @return string[]
	 * @since 1.0.0
	 */
	public function getAttachmentMimeTypes(): array
	{
		$raw = trim((string) ($this->getSettings()['agent_mod_attachments']['attachment_mime_types'] ?? ''));
		if ('' === $raw) {
			$value = Constants::AI_ATTACHMENT_MIME_TYPES;
		} else {
			$value = array_values(array_filter(array_map('trim', explode("\n", $raw))));
			if (empty($value)) {
				$value = Constants::AI_ATTACHMENT_MIME_TYPES;
			}
		}
		return (array) $value;
	}

	/**
	 * Returns the user-managed base system prompt when saved, else the default.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	public function getSystemPrompt(): string
	{
		$saved = trim((string) ($this->getSettings()['agent_mod_chat_behaviour']['global_system_prompt'] ?? ''));
		$value = '' !== $saved ? $saved : Constants::aiDefaultSystemPrompt();
		return (string) $value;
	}

	/**
	 * @return bool
	 * @since 1.0.6
	 */
	public function isSiteContextEnabled(): bool
	{
		$saved = $this->getSettings()['agent_mod_chat_behaviour']['site_context_enabled'] ?? null;
		$value = $saved !== null ? (bool) $saved : Constants::AI_CONTEXT_ENABLED;
		return (bool) $value;
	}

	/**
	 * @return string
	 * @since 1.0.5
	 */
	public function getRole(): string
	{
		$saved = trim((string) ($this->getSettings()['agent_mod_chat_behaviour']['role'] ?? ''));
		$value = '' !== $saved ? $saved : Constants::AI_AGENT_DEFAULT_ROLE;
		return (string) $value;
	}

	/**
	 * @return string
	 * @since 1.0.5
	 */
	public function getGoal(): string
	{
		$saved = trim((string) ($this->getSettings()['agent_mod_chat_behaviour']['goal'] ?? ''));
		$value = '' !== $saved ? $saved : Constants::AI_AGENT_DEFAULT_GOAL;
		return (string) $value;
	}

	/**
	 * @return string
	 * @since 1.1.0
	 */
	public function getAbilitySource(): string
	{
		$saved = trim((string) ($this->getSettings()['agent_mod_abilities']['ability_source'] ?? ''));
		$value = '' !== $saved ? $saved : 'all';
		return (string) $value;
	}

	/**
	 * @return string[]
	 * @since 1.1.0
	 */
	public function getAllowedAbilities(): array
	{
		$saved = $this->getSettings()['agent_mod_abilities']['allowed_abilities'] ?? [];
		return is_array($saved) ? $saved : [];
	}

	/**
	 * Resolves the allowed abilities to their display details.
	 *
	 * Uses wp_get_ability() directly (not the REST abilities list), so an
	 * allowed ability is always included here even when it is excluded from
	 * REST discovery (e.g. core/get-user-info, which core registers with
	 * show_in_rest => false for privacy reasons).
	 *
	 * @return array<int, array{name: string, label: string, meta: array{annotations: array{readonly: bool}}}>
	 * @since 1.2.0
	 */
	public function getAllowedAbilitiesDetailed(): array
	{
		if (! function_exists('wp_get_ability')) {
			return [];
		}

		$details = [];

		foreach ($this->getAllowedAbilities() as $name) {
			$ability = wp_get_ability((string) $name);

			if (null === $ability) {
				continue;
			}

			$meta = (array) $ability->get_meta();

			$details[] = [
				'name'  => $ability->get_name(),
				'label' => $ability->get_label(),
				'meta'  => [
					'annotations' => [
						'readonly' => true === ($meta['annotations']['readonly'] ?? false),
					],
				],
			];
		}

		return $details;
	}

	/**
	 * @return string[]
	 * @since 1.1.0
	 */
	public function getPersonalityTraits(): array
	{
		$saved = $this->getSettings()['agent_mod_chat_behaviour']['personality_traits'] ?? [];
		
		// Token field values can sometimes come as a comma separated string if not handled natively as an array,
		// but NCF's token_field normally saves as an array or comma string. We'll handle both.
		if (is_string($saved)) {
			$saved = array_filter(array_map('trim', explode(',', $saved)));
		}
		
		return is_array($saved) ? $saved : [];
	}

	/**
	 * Returns the saved settings array, or an empty array when no settings are saved yet.
	 *
	 * @return array<string, mixed>
	 * @since 1.0.0
	 */
	private function getSettings(): array
	{
		return (array) get_option($this->optionKey, []);
	}
}

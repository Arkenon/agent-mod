<?php

/**
 * Provider info service.
 *
 * Reads which AI provider(s) the site is currently connected to through the
 * native WordPress Connectors API. AgentMod stays provider-agnostic and lets the
 * AI Client auto-select at request time; this service is purely informational,
 * so the chat UI can show the connected provider to the user.
 *
 * It only consumes the public Connectors / WP AI Client functions — it never
 * touches third-party plugin internals.
 *
 * @package AgentMod
 * @subpackage Services\AI
 * @since 1.0.0
 */

namespace AgentMod\Services\AI;

use Throwable;
use WordPress\AiClient\AiClient;
use WordPress\AiClient\Providers\Models\DTO\ModelRequirements;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;

defined('ABSPATH') || exit;

class ProviderInfoService
{
	/**
	 * Transient key prefix for cached model lists.
	 *
	 * @var string
	 * @since 1.0.0
	 */
	private const MODELS_CACHE_PREFIX = 'agent_mod_models_';

	/**
	 * Returns the list of connected (configured) AI providers.
	 *
	 * Each entry is ['id' => ..., 'name' => ..., 'logoUrl' => ...]. Only
	 * providers whose connector has credentials in place are included.
	 *
	 * @return array<int, array<string, string>>
	 * @since 1.0.0
	 */
	public function getConnectedProviders(): array
	{
		if (! function_exists('wp_get_connectors')) {
			return [];
		}

		$providers = [];

		foreach (wp_get_connectors() as $id => $data) {
			if (! is_string($id) || ! is_array($data)) {
				continue;
			}

			if ('ai_provider' !== ($data['type'] ?? '')) {
				continue;
			}

			if (! $this->isConfigured($id)) {
				continue;
			}

			$name = isset($data['name']) && '' !== (string) $data['name']
				? (string) $data['name']
				: $id;

			$providers[] = [
				'id'      => $id,
				'name'    => $name,
				'logoUrl' => isset($data['logo_url']) ? (string) $data['logo_url'] : '',
			];
		}

		return $providers;
	}

	/**
	 * Returns the text-generation models offered by a connected provider.
	 *
	 * The list is fetched from the provider via the AI Client (a network call)
	 * and cached in a transient, since the chat UI requests it lazily whenever the
	 * user opens the provider/model picker.
	 *
	 * @param string $providerId Provider id (e.g. 'google', 'openai').
	 *
	 * @return array<int, array<string, string>> Each entry is ['id' => ..., 'name' => ...].
	 * @since 1.0.0
	 */
	public function getTextModels(string $providerId): array
	{
		$providerId = sanitize_key($providerId);

		if ('' === $providerId) {
			return [];
		}

		$cacheKey = self::MODELS_CACHE_PREFIX . $providerId;
		$cached   = get_transient($cacheKey);

		if (is_array($cached)) {
			return $cached;
		}

		$models = $this->fetchTextModels($providerId);

		// Cache an empty result only briefly so a transient failure recovers fast.
		set_transient(
			$cacheKey,
			$models,
			empty($models) ? 5 * MINUTE_IN_SECONDS : 6 * HOUR_IN_SECONDS
		);

		return $models;
	}

	/**
	 * Fetches the text-generation models for a provider from the AI Client.
	 *
	 * @param string $providerId Provider id.
	 *
	 * @return array<int, array<string, string>>
	 * @since 1.0.0
	 */
	private function fetchTextModels(string $providerId): array
	{
		if (! class_exists(AiClient::class)) {
			return [];
		}

		try {
			$registry = AiClient::defaultRegistry();

			if (! $registry->hasProvider($providerId) || ! $registry->isProviderConfigured($providerId)) {
				return [];
			}

			$requirements = new ModelRequirements([CapabilityEnum::textGeneration()], []);
			$metadata     = $registry->findProviderModelsMetadataForSupport($providerId, $requirements);

			$models = [];

			foreach ($metadata as $model) {
				$id = (string) $model->getId();

				if ('' === $id) {
					continue;
				}

				$models[] = [
					'id'   => $id,
					'name' => (string) $model->getName(),
				];
			}

			return $models;
		} catch (Throwable $e) {
			return [];
		}
	}

	/**
	 * Whether the given connector is configured with usable credentials.
	 *
	 * Prefers the AI Client registry check (is_connector_configured); falls back
	 * to the API-key presence check when only that is available.
	 *
	 * @param string $id Connector id.
	 *
	 * @return bool
	 * @since 1.0.0
	 */
	private function isConfigured(string $id): bool
	{
		if (function_exists('WordPress\\AI\\is_connector_configured')) {
			return (bool) \WordPress\AI\is_connector_configured($id);
		}

		if (function_exists('WordPress\\AI\\has_connector_authentication')) {
			return (bool) \WordPress\AI\has_connector_authentication($id);
		}

		return false;
	}
}
